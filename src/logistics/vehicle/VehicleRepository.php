<?php

declare(strict_types=1);

namespace MiniErp\logistics\vehicle;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;

/** Oracle persistence; the service owns transactions on this same connection. */
final class VehicleRepository
{
    public function __construct(private Connection $connection) {}

    /** Return the paginated company vehicle list with current availability. */
    public function findAll(int $companyId, array $pagination): array
    {
        $parameters = [
            'company_id' => $companyId,
            'offset' => ($pagination['page'] - 1) * $pagination['page_size'],
            'page_size' => $pagination['page_size'],
        ];
        $sql = "SELECT V.ID, V.VEHICLE_CODE, V.REGISTRATION_NUMBER, V.DESCRIPTION, V.CAPACITY, V.ISACTIVE,
                       CASE WHEN EXISTS (
                           SELECT 1 FROM ME_LOG_TRIPS T
                           WHERE T.COMPANY_ID = V.COMPANY_ID AND T.VEHICLE_ID = V.ID AND T.STATUS IN ('D', 'S')
                       ) THEN 'ASSIGNED' ELSE 'AVAILABLE' END AS ASSIGNMENT_STATUS
                FROM ME_LOG_VEHICLES V WHERE V.COMPANY_ID = :company_id
                ORDER BY V.VEHICLE_CODE, V.ID OFFSET :offset ROWS FETCH NEXT :page_size ROWS ONLY";
        return $this->connection->query($sql, $parameters);
    }

    public function findEligible(int $companyId, int $offset, int $pageSize, ?int $excludeTripId = null): array
    {
        $parameters = ['company_id' => $companyId, 'offset' => $offset, 'page_size' => $pageSize];
        $excludeTrip = '';
        if ($excludeTripId !== null) {
            $excludeTrip = ' AND T.ID <> :exclude_trip_id';
            $parameters['exclude_trip_id'] = $excludeTripId;
        }
        return $this->connection->query(
            "SELECT V.ID, V.VEHICLE_CODE, V.REGISTRATION_NUMBER, V.DESCRIPTION, V.CAPACITY, V.ISACTIVE,
                    'AVAILABLE' AS ASSIGNMENT_STATUS
             FROM ME_LOG_VEHICLES V
             WHERE V.COMPANY_ID = :company_id AND V.ISACTIVE = 'T'
               AND NOT EXISTS (
                   SELECT 1 FROM ME_LOG_TRIPS T
                   WHERE T.COMPANY_ID = V.COMPANY_ID AND T.VEHICLE_ID = V.ID
                     AND T.STATUS IN ('D', 'S')" . $excludeTrip . "
               )
             ORDER BY V.VEHICLE_CODE, V.ID OFFSET :offset ROWS FETCH NEXT :page_size ROWS ONLY",
            $parameters
        );
    }

    public function findById(int $companyId, int $id, bool $lock = false): array
    {
        $rows = $this->connection->query(
            'SELECT ID, VEHICLE_CODE, REGISTRATION_NUMBER, DESCRIPTION, CAPACITY, ISACTIVE
             FROM ME_LOG_VEHICLES WHERE COMPANY_ID = :company_id AND ID = :id' . ($lock ? ' FOR UPDATE' : ''),
            ['company_id' => $companyId, 'id' => $id]
        );
        if ($rows === []) {
            throw new AppError('NOT_FOUND', 'Vehicle not found.', 404);
        }
        return $rows[0];
    }

    public function duplicateExists(int $companyId, mixed $code, string $registration, ?int $excludeId = null): bool
    {
        $parameters = ['company_id' => $companyId, 'vehicle_code' => $code, 'registration' => $registration];
        $exclude = '';
        if ($excludeId !== null) {
            $exclude = ' AND ID <> :id';
            $parameters['id'] = $excludeId;
        }
        return $this->connection->query(
            'SELECT ID FROM ME_LOG_VEHICLES WHERE COMPANY_ID = :company_id' . $exclude . '
             AND (VEHICLE_CODE = :vehicle_code OR UPPER(TRIM(REGISTRATION_NUMBER)) = :registration)',
            $parameters
        ) !== [];
    }

    public function create(int $companyId, int $auditUserId, array $data): array
    {
        $this->connection->execute(
            'INSERT INTO ME_LOG_VEHICLES
             (COMPANY_ID, VEHICLE_CODE, REGISTRATION_NUMBER, DESCRIPTION, CAPACITY, ISACTIVE,
              CREATED_AT, UPDATED_AT, CREATED_BY, UPDATED_BY)
             VALUES (:company_id, :vehicle_code, :registration, :description, :capacity, :active,
                     SYSTIMESTAMP, SYSTIMESTAMP, :created_by, :updated_by)',
            [
                'company_id' => $companyId,
                'vehicle_code' => $data['vehicle_code'],
                'registration' => $data['registration_number'],
                'description' => $data['description'],
                'capacity' => $data['capacity'],
                'active' => $data['is_active'],
                'created_by' => $auditUserId,
                'updated_by' => $auditUserId,
            ]
        );
        $rows = $this->connection->query(
            'SELECT ID, VEHICLE_CODE, REGISTRATION_NUMBER, DESCRIPTION, CAPACITY, ISACTIVE
             FROM ME_LOG_VEHICLES WHERE COMPANY_ID = :company_id AND VEHICLE_CODE = :vehicle_code',
            ['company_id' => $companyId, 'vehicle_code' => $data['vehicle_code']]
        );
        if ($rows === []) {
            throw new \LogicException('Created vehicle could not be retrieved.');
        }
        return $rows[0];
    }

    /** Column names must come from the validator's fixed allowlist. */
    public function update(int $companyId, int $id, int $auditUserId, array $changes): void
    {
        $set = [];
        $parameters = ['company_id' => $companyId, 'id' => $id, 'updated_by' => $auditUserId];
        foreach ($changes as $column => $value) {
            $parameter = 'set_' . strtolower($column);
            $set[] = $column . ' = :' . $parameter;
            $parameters[$parameter] = $value;
        }
        $set[] = 'UPDATED_AT = SYSTIMESTAMP';
        $set[] = 'UPDATED_BY = :updated_by';
        $count = $this->connection->execute(
            'UPDATE ME_LOG_VEHICLES SET ' . implode(', ', $set) . ' WHERE COMPANY_ID = :company_id AND ID = :id',
            $parameters
        );
        if ($count !== 1) {
            throw new \LogicException('Unexpected vehicle update count.');
        }
    }

    public function tripStatuses(int $companyId, int $id): array
    {
        return $this->connection->query(
            'SELECT STATUS FROM ME_LOG_TRIPS WHERE COMPANY_ID = :company_id AND VEHICLE_ID = :vehicle_id',
            ['company_id' => $companyId, 'vehicle_id' => $id]
        );
    }

    /** The caller holds the Vehicle row lock shared by Trip resource assignment. */
    public function hasActiveTrip(int $companyId, int $id): bool
    {
        return $this->connection->query(
            "SELECT ID FROM ME_LOG_TRIPS
             WHERE COMPANY_ID = :company_id AND VEHICLE_ID = :vehicle_id
               AND STATUS IN ('D', 'S')
             FETCH FIRST 1 ROWS ONLY",
            ['company_id' => $companyId, 'vehicle_id' => $id]
        ) !== [];
    }

    public function hasTripHistory(int $companyId, int $id): bool
    {
        return $this->connection->query(
            'SELECT ID FROM ME_LOG_TRIPS WHERE COMPANY_ID = :company_id
             AND VEHICLE_ID = :vehicle_id FETCH FIRST 1 ROWS ONLY',
            ['company_id' => $companyId, 'vehicle_id' => $id]
        ) !== [];
    }

    public function delete(int $companyId, int $id): void
    {
        $count = $this->connection->execute(
            'DELETE FROM ME_LOG_VEHICLES WHERE COMPANY_ID = :company_id AND ID = :id',
            ['company_id' => $companyId, 'id' => $id]
        );
        if ($count !== 1) {
            throw new \LogicException('Unexpected vehicle delete count.');
        }
    }

    public function auditUserId(int $companyId, string $userCode): int
    {
        $rows = $this->connection->query(
            'SELECT ID FROM ME_USERS WHERE COMPANY_ID = :company_id AND USER_ID = :user_code AND ACTIVE = 1',
            ['company_id' => $companyId, 'user_code' => $userCode]
        );
        if (count($rows) !== 1) {
            throw new AppError('FORBIDDEN', 'An active user is required to modify vehicles.', 403);
        }
        $id = filter_var($rows[0]['ID'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new AppError('CONFIGURATION_ERROR', 'Vehicle audit fields require a numeric ME_USERS.ID.', 503);
        }
        return $id;
    }
}

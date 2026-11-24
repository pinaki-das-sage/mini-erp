<?php

declare(strict_types=1);

namespace MiniErp\logistics\driver;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;

class DriverRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function findAll(int $companyId, int $offset, int $pageSize): array
    {
        $sql = 'SELECT ID, DRIVER_CODE, USER_ID, FIRST_NAME, LAST_NAME, PHONE,
        LICENCE_NUMBER, TO_CHAR(LICENCE_EXPIRY, \'YYYY-MM-DD\') AS LICENCE_EXPIRY, ISACTIVE 
        FROM ME_LOG_DRIVERS 
        WHERE COMPANY_ID = :companyId 
        ORDER BY ID
        OFFSET :offset ROWS
        FETCH NEXT :page_size ROWS ONLY';

        return array_map($this->mapRow(...), $this->connection->query($sql, [
            'companyId' => $companyId,
            'offset' => $offset,
            'page_size' => $pageSize
        ]));
    }

    public function findById(int $companyId, int $id): array
    {
        $sql = 'SELECT ID, DRIVER_CODE, USER_ID, FIRST_NAME, LAST_NAME, PHONE,
        LICENCE_NUMBER, TO_CHAR(LICENCE_EXPIRY, \'YYYY-MM-DD\') AS LICENCE_EXPIRY, ISACTIVE 
        FROM ME_LOG_DRIVERS 
        WHERE COMPANY_ID = :companyId AND ID = :id';

        $result = $this->connection->query($sql, [
            'companyId' => $companyId,
            'id' => $id
        ]);

        return $result ? $this->mapRow($result[0]) : [];
    }

    public function hasActiveTrip(int $companyId, int $id): bool
    {
        $sql = 'SELECT COUNT(*) as count FROM ME_LOG_TRIPS WHERE COMPANY_ID = :companyId AND DRIVER_ID = :id AND STATUS IN (\'D\', \'S\')';
        $result = $this->connection->query($sql, [
            'companyId' => $companyId,
            'id' => $id
        ]);

        return $result && $result[0]['COUNT'] > 0;
    }

    public function hasTripHistory(int $companyId, int $id): bool
    {
        $sql = 'SELECT COUNT(*) as count FROM ME_LOG_TRIPS WHERE COMPANY_ID = :companyId AND DRIVER_ID = :id';
        $result = $this->connection->query($sql, [
            'companyId' => $companyId,
            'id' => $id
        ]);

        return $result && $result[0]['COUNT'] > 0;
    }

    private function mapRow(array $row): array
    {
        return [
            'id' => (int) $row['ID'],
            'driver_code' => $row['DRIVER_CODE'],
            'user_id' => $row['USER_ID'] === null ? null : (int) $row['USER_ID'],
            'first_name' => $row['FIRST_NAME'],
            'last_name' => $row['LAST_NAME'],
            'phone' => $row['PHONE'],
            'licence_number' => $row['LICENCE_NUMBER'],
            'licence_expiry' => $row['LICENCE_EXPIRY'],
            'isactive' => $row['ISACTIVE']
        ];
    }

    public function driverCodeExists(int $companyId, string $driverCode,?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) as count FROM ME_LOG_DRIVERS WHERE COMPANY_ID = :companyId AND DRIVER_CODE = :driverCode';
        if ($excludeId !== null) {
            $sql .= ' AND ID != :excludeId';
        }
        $params = [
            'companyId' => $companyId,
            'driverCode' => $driverCode
        ];
        if ($excludeId !== null) {
            $params['excludeId'] = $excludeId;
        }

        $result = $this->connection->query($sql, $params);

        return $result && $result[0]['COUNT'] > 0;
    }

    public function licenceNumberExists(int $companyId, string $licenceNumber, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) as count FROM ME_LOG_DRIVERS WHERE COMPANY_ID = :companyId AND LICENCE_NUMBER = :licenceNumber';
        $params = [
            'companyId' => $companyId,
            'licenceNumber' => $licenceNumber
        ];
        if ($excludeId !== null) {
            $sql .= ' AND ID != :excludeId';
            $params['excludeId'] = $excludeId;
        }

        $result = $this->connection->query($sql, $params);

        return $result && $result[0]['COUNT'] > 0;
    }

    public function userExists(int $companyId, int $userId): bool
    {
        $sql = 'SELECT COUNT(*) as count FROM ME_USERS WHERE COMPANY_ID = :companyId AND ID = :userId AND ACTIVE = 1';
        $result = $this->connection->query($sql, [
            'companyId' => $companyId,
            'userId' => $userId
        ]);

        return $result && $result[0]['COUNT'] > 0;
    }

    public function driverUserExists(int $companyId, int $userId, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) as count FROM ME_LOG_DRIVERS WHERE COMPANY_ID = :companyId AND USER_ID = :userId AND ISACTIVE = \'T\'';
        $params = [
            'companyId' => $companyId,
            'userId' => $userId
        ];
        if ($excludeId !== null) {
            $sql .= ' AND ID != :excludeId';
            $params['excludeId'] = $excludeId;
        }

        $result = $this->connection->query($sql, $params);

        return $result && $result[0]['COUNT'] > 0;
    }

    public function findUserIdByCode(int $companyId, string $userCode): ?int
    {
        $result = $this->connection->query(
            'SELECT ID FROM ME_USERS WHERE COMPANY_ID = :companyId AND USER_ID = :userCode AND ACTIVE = 1',
            ['companyId' => $companyId, 'userCode' => $userCode]
        );

        return $result ? (int) $result[0]['ID'] : null;
    }

    public function create(int $companyId, int $actorId, array $data): array
    {
        $sql = 'INSERT INTO ME_LOG_DRIVERS (COMPANY_ID, DRIVER_CODE, USER_ID, FIRST_NAME, LAST_NAME, PHONE, LICENCE_NUMBER, LICENCE_EXPIRY, ISACTIVE, CREATED_BY, UPDATED_BY)
                VALUES (:companyId, :driverCode, :userId, :firstName, :lastName, :phone, :licenceNumber, TO_DATE(:licenceExpiry, \'YYYY-MM-DD\'), :isActive, :actorId, :actorId)';

        $this->connection->execute($sql, [
            'companyId' => $companyId,
            'driverCode' => $data['driver_code'],
            'userId' => $data['user_id'],
            'firstName' => $data['first_name'],
            'lastName' => $data['last_name'],
            'phone' => $data['phone'],
            'licenceNumber' => $data['licence_number'],
            'licenceExpiry' => $data['licence_expiry'],
            'isActive' => $data['isactive'],
            'actorId' => $actorId
        ]);

        $result = $this->connection->query(
            'SELECT ID FROM ME_LOG_DRIVERS WHERE COMPANY_ID = :companyId AND DRIVER_CODE = :driverCode',
            ['companyId' => $companyId, 'driverCode' => $data['driver_code']]
        );

        return $this->findById($companyId, (int) $result[0]['ID']);
    }

    public function update(int $companyId, int $id, int $actorId, array $data): array
    {
        $sql = 'UPDATE ME_LOG_DRIVERS 
                SET DRIVER_CODE = :driverCode, USER_ID = :userId, FIRST_NAME = :firstName, LAST_NAME = :lastName, PHONE = :phone, LICENCE_NUMBER = :licenceNumber, LICENCE_EXPIRY = TO_DATE(:licenceExpiry, \'YYYY-MM-DD\'), ISACTIVE = :isActive, UPDATED_BY = :actorId, UPDATED_AT = SYSTIMESTAMP
                WHERE COMPANY_ID = :companyId AND ID = :id';

        $affected = $this->connection->execute($sql, [
            'companyId' => $companyId,
            'id' => $id,
            'driverCode' => $data['driver_code'],
            'userId' => $data['user_id'],
            'firstName' => $data['first_name'],
            'lastName' => $data['last_name'],
            'phone' => $data['phone'],
            'licenceNumber' => $data['licence_number'],
            'licenceExpiry' => $data['licence_expiry'],
            'isActive' => $data['isactive'],
            'actorId' => $actorId
        ]);

        if ($affected < 1) {
            throw new AppError('NOT_FOUND', 'Driver not found.', 404);
        }

        return $this->findById($companyId, $id);
    }

    public function deactivate(int $companyId, int $id, int $actorId): void
    {
        $sql = 'UPDATE ME_LOG_DRIVERS SET ISACTIVE = \'F\', UPDATED_BY = :actorId, UPDATED_AT = SYSTIMESTAMP WHERE COMPANY_ID = :companyId AND ID = :id';
        $affected = $this->connection->execute($sql, [
            'companyId' => $companyId,
            'id' => $id,
            'actorId' => $actorId
        ]);

        if ($affected < 1) {
            throw new AppError('NOT_FOUND', 'Driver not found.', 404);
        }
    }

    public function delete(int $companyId, int $id): void
    {
        $sql = 'DELETE FROM ME_LOG_DRIVERS WHERE COMPANY_ID = :companyId AND ID = :id';
        $affected = $this->connection->execute($sql, [
            'companyId' => $companyId,
            'id' => $id
        ]);

        if ($affected < 1) {
            throw new AppError('NOT_FOUND', 'Driver not found.', 404);
        }
    }
}
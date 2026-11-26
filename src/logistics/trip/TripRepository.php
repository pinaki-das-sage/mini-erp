<?php

declare(strict_types=1);

namespace MiniErp\logistics\trip;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;

class TripRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function findAll(int $companyId, int $offset, int $pageSize): array
    {
        $sql = 'SELECT T.ID, T.TRIP_CODE, T.VEHICLE_ID, T.DRIVER_ID,
        TO_CHAR(T.PLANNED_DEPARTURE_AT, \'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM\') AS PLANNED_DEPARTURE_AT,
        T.STATUS, T.SALES_ORDER_ID,
        TO_CHAR(T.ASSIGNED_AT, \'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM\') AS ASSIGNED_AT, T.ASSIGNED_BY,
        TO_CHAR(T.CANCELLED_AT, \'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM\') AS CANCELLED_AT, T.CANCELLED_BY,
        T.CANCELLATION_REASON,
        TO_CHAR(T.DISPATCHED_AT, \'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM\') AS DISPATCHED_AT, T.DISPATCHED_BY,
        TO_CHAR(T.DELIVERED_AT, \'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM\') AS DELIVERED_AT, T.DELIVERED_BY,
        NVL(L.PLANNED_LOAD_KG, 0) AS PLANNED_LOAD_KG, V.CAPACITY AS CAPACITY_KG,
        V.CAPACITY - NVL(L.PLANNED_LOAD_KG, 0) AS REMAINING_CAPACITY_KG
        FROM ME_LOG_TRIPS T
        JOIN ME_LOG_VEHICLES V ON V.COMPANY_ID = T.COMPANY_ID AND V.ID = T.VEHICLE_ID
        LEFT JOIN (
            SELECT COMPANY_ID, TRIP_ID, SUM(LINE_LOAD) AS PLANNED_LOAD_KG
            FROM ME_LOG_TRIP_LINES
            WHERE COMPANY_ID = :companyId
            GROUP BY COMPANY_ID, TRIP_ID
        ) L ON L.COMPANY_ID = T.COMPANY_ID AND L.TRIP_ID = T.ID
        WHERE T.COMPANY_ID = :companyId
        ORDER BY T.ID
        OFFSET :offset ROWS
        FETCH NEXT :page_size ROWS ONLY';

        return array_map($this->mapRow(...), $this->connection->query($sql, [
            'companyId' => $companyId,
            'offset' => $offset,
            'page_size' => $pageSize
        ]));
    }

    public function findById(int $companyId, int $id, ?TransactionContext $tx = null): array
    {
        $sql = 'SELECT T.ID, T.TRIP_CODE, T.VEHICLE_ID, T.DRIVER_ID,
        TO_CHAR(T.PLANNED_DEPARTURE_AT, \'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM\') AS PLANNED_DEPARTURE_AT,
        T.STATUS, T.SALES_ORDER_ID,
        TO_CHAR(T.ASSIGNED_AT, \'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM\') AS ASSIGNED_AT, T.ASSIGNED_BY,
        TO_CHAR(T.CANCELLED_AT, \'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM\') AS CANCELLED_AT, T.CANCELLED_BY,
        T.CANCELLATION_REASON,
        TO_CHAR(T.DISPATCHED_AT, \'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM\') AS DISPATCHED_AT, T.DISPATCHED_BY,
        TO_CHAR(T.DELIVERED_AT, \'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM\') AS DELIVERED_AT, T.DELIVERED_BY,
        NVL(L.PLANNED_LOAD_KG, 0) AS PLANNED_LOAD_KG, V.CAPACITY AS CAPACITY_KG,
        V.CAPACITY - NVL(L.PLANNED_LOAD_KG, 0) AS REMAINING_CAPACITY_KG
        FROM ME_LOG_TRIPS T
        JOIN ME_LOG_VEHICLES V ON V.COMPANY_ID = T.COMPANY_ID AND V.ID = T.VEHICLE_ID
        LEFT JOIN (
            SELECT COMPANY_ID, TRIP_ID, SUM(LINE_LOAD) AS PLANNED_LOAD_KG
            FROM ME_LOG_TRIP_LINES
            WHERE COMPANY_ID = :companyId
            GROUP BY COMPANY_ID, TRIP_ID
        ) L ON L.COMPANY_ID = T.COMPANY_ID AND L.TRIP_ID = T.ID
        WHERE T.COMPANY_ID = :companyId AND T.ID = :id';
        $result = $this->query($sql, [
            'companyId' => $companyId,
            'id' => $id
        ], $tx);

        return $result ? $this->mapRow($result[0]) : [];
    }

    public function tripCodeExists(int $companyId, int $tripCode, ?TransactionContext $tx = null): bool
    {
        $result = $this->query(
            'SELECT COUNT(*) AS COUNT FROM ME_LOG_TRIPS WHERE COMPANY_ID = :companyId AND TRIP_CODE = :tripCode',
            ['companyId' => $companyId, 'tripCode' => $tripCode],
            $tx
        );

        return $result && $result[0]['COUNT'] > 0;
    }

    public function findTripIdByCode(
        TransactionContext $tx,
        int $companyId,
        int $tripCode
    ): ?int {
        $rows = $tx->query(
            'SELECT ID FROM ME_LOG_TRIPS WHERE COMPANY_ID = :companyId AND TRIP_CODE = :tripCode',
            ['companyId' => $companyId, 'tripCode' => $tripCode]
        );

        if (count($rows) > 1) {
            throw new AppError('INVALID_STATE', 'Trip code is not unique within the company.', 409);
        }

        return $rows === [] ? null : (int) $rows[0]['ID'];
    }

    public function vehicleExistsAndIsActive(int $companyId, int $vehicleId, ?TransactionContext $tx = null): bool
    {
        $result = $this->query(
            'SELECT COUNT(*) AS COUNT FROM ME_LOG_VEHICLES WHERE COMPANY_ID = :companyId AND ID = :vehicleId AND ISACTIVE = \'T\'',
            ['companyId' => $companyId, 'vehicleId' => $vehicleId],
            $tx
        );

        return $result && $result[0]['COUNT'] > 0;
    }

    public function driverExistsAndIsActive(int $companyId, int $driverId, ?TransactionContext $tx = null): bool
    {
        $sql = 'SELECT COUNT(*) AS COUNT FROM ME_LOG_DRIVERS D
                WHERE D.COMPANY_ID = :companyId AND D.ID = :driverId AND D.ISACTIVE = \'T\'
                AND (D.USER_ID IS NULL OR EXISTS (
                    SELECT 1 FROM ME_USERS U
                    WHERE U.COMPANY_ID = D.COMPANY_ID AND U.ID = D.USER_ID AND U.ACTIVE = 1
                ))';
        $result = $this->query($sql, [
            'companyId' => $companyId,
            'driverId' => $driverId
        ], $tx);

        return $result && $result[0]['COUNT'] > 0;
    }

    public function driverLicenceValidForDate(
        int $companyId,
        int $driverId,
        string $departureDate,
        ?TransactionContext $tx = null
    ): bool {
        $rows = $this->query(
            "SELECT ID FROM ME_LOG_DRIVERS
             WHERE COMPANY_ID = :companyId AND ID = :driverId AND ISACTIVE = 'T'
               AND TRUNC(LICENCE_EXPIRY) >= TO_DATE(:departureDate, 'YYYY-MM-DD')",
            [
                'companyId' => $companyId,
                'driverId' => $driverId,
                'departureDate' => $departureDate,
            ],
            $tx
        );

        return count($rows) === 1;
    }

    public function lockVehicle(TransactionContext $tx, int $companyId, int $vehicleId): bool
    {
        $rows = $tx->query(
            'SELECT ID FROM ME_LOG_VEHICLES WHERE COMPANY_ID = :companyId AND ID = :vehicleId AND ISACTIVE = \'T\' FOR UPDATE',
            ['companyId' => $companyId, 'vehicleId' => $vehicleId]
        );

        return $rows !== [];
    }

    public function lockDriver(TransactionContext $tx, int $companyId, int $driverId): bool
    {
        $rows = $tx->query(
            'SELECT ID FROM ME_LOG_DRIVERS WHERE COMPANY_ID = :companyId AND ID = :driverId AND ISACTIVE = \'T\' FOR UPDATE',
            ['companyId' => $companyId, 'driverId' => $driverId]
        );

        return $rows !== [] && $this->driverExistsAndIsActive($companyId, $driverId, $tx);
    }

    public function vehicleHasActiveTrip(
        int $companyId,
        int $vehicleId,
        ?int $excludeTripId = null,
        ?TransactionContext $tx = null
    ): bool {
        $sql = 'SELECT COUNT(*) AS COUNT FROM ME_LOG_TRIPS WHERE COMPANY_ID = :companyId AND VEHICLE_ID = :vehicleId AND STATUS IN (\'D\', \'S\')';
        $params = [
            'companyId' => $companyId,
            'vehicleId' => $vehicleId
        ];
        if ($excludeTripId !== null) {
            $sql .= ' AND ID != :excludeTripId';
            $params['excludeTripId'] = $excludeTripId;
        }

        $result = $this->query($sql, $params, $tx);

        return $result && $result[0]['COUNT'] > 0;
    }

    public function driverHasActiveTrip(
        int $companyId,
        int $driverId,
        ?int $excludeTripId = null,
        ?TransactionContext $tx = null
    ): bool {
        $sql = 'SELECT COUNT(*) AS COUNT FROM ME_LOG_TRIPS WHERE COMPANY_ID = :companyId AND DRIVER_ID = :driverId AND STATUS IN (\'D\', \'S\')';
        $params = [
            'companyId' => $companyId,
            'driverId' => $driverId
        ];
        if ($excludeTripId !== null) {
            $sql .= ' AND ID != :excludeTripId';
            $params['excludeTripId'] = $excludeTripId;
        }

        $result = $this->query($sql, $params, $tx);

        return $result && $result[0]['COUNT'] > 0;
    }

    public function findUserIdByCode(int $companyId, string $userCode): ?string
    {
        $result = $this->connection->query(
            'SELECT ID FROM ME_USERS WHERE COMPANY_ID = :companyId AND USER_ID = :userCode AND ACTIVE = 1',
            ['companyId' => $companyId, 'userCode' => $userCode]
        );

        return $result ? (string) $result[0]['ID'] : null;
    }

    public function create(
        TransactionContext $tx,
        int $companyId,
        int $salesOrderId,
        string $actorId,
        array $data
    ): int {
        $sql = 'INSERT INTO ME_LOG_TRIPS (
                    COMPANY_ID, TRIP_CODE, VEHICLE_ID, DRIVER_ID, PLANNED_DEPARTURE_AT, STATUS,
                    SALES_ORDER_ID, ASSIGNED_AT, ASSIGNED_BY, CREATED_BY, UPDATED_BY
                ) VALUES (
                    :companyId, :tripCode, :vehicleId, :driverId,
                    TO_TIMESTAMP_TZ(:plannedDepartureAt, \'YYYY-MM-DD HH24:MI:SS.FF6 TZH:TZM\'),
                    \'D\', :salesOrderId, SYSTIMESTAMP, :actorId, :actorId, :actorId
                )';

        try {
            $tx->execute($sql, [
                'companyId' => $companyId,
                'tripCode' => $data['trip_code'],
                'vehicleId' => $data['vehicle_id'],
                'driverId' => $data['driver_id'],
                'plannedDepartureAt' => $data['planned_departure_at'],
                'salesOrderId' => $salesOrderId,
                'actorId' => $actorId
            ]);
        } catch (DatabaseError $error) {
            if ($error->oracleCode === 1) {
                throw new AppError('CONFLICT', 'Trip code already exists.', 409);
            }

            throw $error;
        }

        $result = $tx->query(
            'SELECT ID FROM ME_LOG_TRIPS WHERE COMPANY_ID = :companyId AND TRIP_CODE = :tripCode',
            ['companyId' => $companyId, 'tripCode' => $data['trip_code']]
        );

        if (count($result) !== 1) {
            throw new AppError('INVALID_STATE', 'Created Trip could not be resolved.', 409);
        }

        return (int) $result[0]['ID'];
    }

    public function createTripLines(
        TransactionContext $tx,
        int $companyId,
        int $tripId,
        string $actorId,
        array $salesLines
    ): array {
        $mapping = [];
        $seen = [];

        foreach ($salesLines as $line) {
            $lineId = $this->positiveDecimal($line['line_id'] ?? null, 'Sales line ID', 15);
            $productId = $this->positiveDecimal($line['product_id'] ?? null, 'Product ID', 10);
            $quantity = $this->positiveDecimal($line['quantity'] ?? null, 'Quantity', 10);

            if (isset($seen[$lineId])) {
                throw new AppError('INVALID_STATE', 'Sales returned duplicate Order lines.', 409);
            }

            $seen[$lineId] = true;
            $totalWeight = $this->lineWeightKg($tx, $companyId, $productId, $quantity);

            $tx->execute(
                'INSERT INTO ME_LOG_TRIP_LINES (
                    COMPANY_ID, TRIP_ID, SALES_LINE_ID, PRODUCT_ID, QUANTITY, TOTAL_WEIGHT, LINE_LOAD,
                    CREATED_BY, UPDATED_BY
                ) VALUES (
                    :companyId, :tripId, :salesLineId, :productId, :quantity, :totalWeight, :lineLoad,
                    :actorId, :actorId
                )',
                [
                    'companyId' => $companyId,
                    'tripId' => $tripId,
                    'salesLineId' => $lineId,
                    'productId' => $productId,
                    'quantity' => $quantity,
                    'totalWeight' => $totalWeight,
                    'lineLoad' => $totalWeight,
                    'actorId' => $actorId
                ]
            );

            $saved = $tx->query(
                'SELECT ID FROM ME_LOG_TRIP_LINES
                 WHERE COMPANY_ID = :companyId AND TRIP_ID = :tripId AND SALES_LINE_ID = :salesLineId',
                ['companyId' => $companyId, 'tripId' => $tripId, 'salesLineId' => $lineId]
            );
            if (count($saved) !== 1) {
                throw new AppError('INVALID_STATE', 'Created Trip line could not be resolved.', 409);
            }

            $mapping[] = [
                'line_id' => $lineId,
                'trip_line_id' => (string) $saved[0]['ID']
            ];
        }

        return $mapping;
    }

    public function lockTrip(TransactionContext $tx, int $companyId, int $id): array
    {
        $rows = $tx->query(
            'SELECT ID, TRIP_CODE, VEHICLE_ID, DRIVER_ID, SALES_ORDER_ID, STATUS
             FROM ME_LOG_TRIPS WHERE COMPANY_ID = :companyId AND ID = :id FOR UPDATE',
            ['companyId' => $companyId, 'id' => $id]
        );

        if ($rows === []) {
            return [];
        }

        return [
            'id' => (int) $rows[0]['ID'],
            'trip_code' => (int) $rows[0]['TRIP_CODE'],
            'vehicle_id' => (int) $rows[0]['VEHICLE_ID'],
            'driver_id' => (int) $rows[0]['DRIVER_ID'],
            'sales_order_id' => (int) $rows[0]['SALES_ORDER_ID'],
            'status' => $this->apiStatus($rows[0]['STATUS'])
        ];
    }

    public function lockTripLines(TransactionContext $tx, int $companyId, int $tripId): array
    {
        return $tx->query(
            'SELECT ID, SALES_LINE_ID, PRODUCT_ID, QUANTITY, TOTAL_WEIGHT, LINE_LOAD
             FROM ME_LOG_TRIP_LINES
             WHERE COMPANY_ID = :companyId AND TRIP_ID = :tripId
             ORDER BY ID FOR UPDATE',
            ['companyId' => $companyId, 'tripId' => $tripId]
        );
    }

    public function assertTripWithinCapacity(
        TransactionContext $tx,
        int $companyId,
        int $tripId,
        int $vehicleId
    ): void {
        $rows = $tx->query(
            "SELECT V.CAPACITY,
                    NVL(SUM(L.LINE_LOAD), 0) AS PLANNED_LOAD,
                    CASE WHEN NVL(SUM(L.LINE_LOAD), 0) <= V.CAPACITY THEN 'T' ELSE 'F' END AS WITHIN_CAPACITY
             FROM ME_LOG_VEHICLES V
             LEFT JOIN ME_LOG_TRIP_LINES L
               ON L.COMPANY_ID = V.COMPANY_ID AND L.TRIP_ID = :tripId
             WHERE V.COMPANY_ID = :companyId AND V.ID = :vehicleId
             GROUP BY V.CAPACITY",
            ['companyId' => $companyId, 'tripId' => $tripId, 'vehicleId' => $vehicleId]
        );

        if (count($rows) !== 1) {
            throw new AppError('INVALID_STATE', 'Trip vehicle could not be resolved for capacity validation.', 409);
        }
        if ($rows[0]['WITHIN_CAPACITY'] !== 'T') {
            throw new AppError('CAPACITY_EXCEEDED', 'Trip load exceeds vehicle capacity.', 409);
        }
    }

    public function findOrderNumber(TransactionContext $tx, int $companyId, int $orderId): string
    {
        $rows = $tx->query(
            'SELECT DOCUMENT_NUMBER FROM ME_OE_DOCUMENTS
             WHERE CNY# = :companyId AND RECORD# = :orderId AND DOCUMENT_TYPE = :documentType',
            ['companyId' => $companyId, 'orderId' => $orderId, 'documentType' => 'SALES_ORDER']
        );

        if (count($rows) !== 1 || !is_string($rows[0]['DOCUMENT_NUMBER'])) {
            throw new AppError('NOT_FOUND', 'Sales Order not found for this Trip.', 404);
        }

        return $rows[0]['DOCUMENT_NUMBER'];
    }

    public function update(
        TransactionContext $tx,
        int $companyId,
        int $id,
        string $actorId,
        array $data
    ): array {
        $sql = 'UPDATE ME_LOG_TRIPS
                SET VEHICLE_ID = :vehicleId, DRIVER_ID = :driverId,
                    PLANNED_DEPARTURE_AT = TO_TIMESTAMP_TZ(:plannedDepartureAt, \'YYYY-MM-DD HH24:MI:SS.FF6 TZH:TZM\'),
                    ASSIGNED_AT = SYSTIMESTAMP, ASSIGNED_BY = :actorId,
                    UPDATED_BY = :actorId, UPDATED_AT = SYSTIMESTAMP
                WHERE COMPANY_ID = :companyId AND ID = :id AND STATUS = \'D\'';

        $affected = $tx->execute($sql, [
            'companyId' => $companyId,
            'id' => $id,
            'vehicleId' => $data['vehicle_id'],
            'driverId' => $data['driver_id'],
            'plannedDepartureAt' => $data['planned_departure_at'],
            'actorId' => $actorId
        ]);

        if ($affected < 1) {
            throw new AppError('CONFLICT', 'Only draft trips can be updated.', 409);
        }

        return $this->findById($companyId, $id, $tx);
    }

    public function cancel(
        TransactionContext $tx,
        int $companyId,
        int $id,
        string $actorId,
        string $reason
    ): array {
        $sql = 'UPDATE ME_LOG_TRIPS
                SET STATUS = \'X\', CANCELLED_AT = SYSTIMESTAMP, CANCELLED_BY = :actorId,
                    CANCELLATION_REASON = :reason, UPDATED_BY = :actorId, UPDATED_AT = SYSTIMESTAMP
                WHERE COMPANY_ID = :companyId AND ID = :id AND STATUS = \'D\'';

        $affected = $tx->execute($sql, [
            'companyId' => $companyId,
            'id' => $id,
            'actorId' => $actorId,
            'reason' => $reason
        ]);

        if ($affected < 1) {
            throw new AppError('CONFLICT', 'Only draft trips can be cancelled.', 409);
        }

        return $this->findById($companyId, $id, $tx);
    }

    public function dispatch(TransactionContext $tx, int $companyId, int $id, string $actorId): array
    {
        $affected = $tx->execute(
            'UPDATE ME_LOG_TRIPS
             SET STATUS = \'S\', DISPATCHED_AT = SYSTIMESTAMP, DISPATCHED_BY = :actorId,
                 UPDATED_BY = :actorId, UPDATED_AT = SYSTIMESTAMP
             WHERE COMPANY_ID = :companyId AND ID = :id AND STATUS = \'D\'',
            ['companyId' => $companyId, 'id' => $id, 'actorId' => $actorId]
        );

        if ($affected < 1) {
            throw new AppError('CONFLICT', 'Only draft trips can be dispatched.', 409);
        }

        return $this->findById($companyId, $id, $tx);
    }

    public function deliver(TransactionContext $tx, int $companyId, int $id, string $actorId): array
    {
        $affected = $tx->execute(
            'UPDATE ME_LOG_TRIPS
             SET STATUS = \'C\', DELIVERED_AT = SYSTIMESTAMP, DELIVERED_BY = :actorId,
                 UPDATED_BY = :actorId, UPDATED_AT = SYSTIMESTAMP
             WHERE COMPANY_ID = :companyId AND ID = :id AND STATUS = \'S\'',
            ['companyId' => $companyId, 'id' => $id, 'actorId' => $actorId]
        );

        if ($affected < 1) {
            throw new AppError('CONFLICT', 'Only dispatched trips can be delivered.', 409);
        }

        return $this->findById($companyId, $id, $tx);
    }

    private function query(string $sql, array $parameters, ?TransactionContext $tx): array
    {
        return $tx === null
            ? $this->connection->query($sql, $parameters)
            : $tx->query($sql, $parameters);
    }

    private function apiStatus(string $status): string
    {
        return match ($status) {
            'D' => 'DRAFT',
            'S' => 'DISPATCHED',
            'C' => 'DELIVERED',
            'X' => 'CANCELLED',
            default => throw new AppError('INVALID_STATE', 'Trip has an unknown persisted status.', 409),
        };
    }

    private function positiveDecimal(mixed $value, string $label, int $maxDigits): string
    {
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,' . ($maxDigits - 1) . '}\z/D', $value)) {
            throw new AppError('DEPENDENCY_UNAVAILABLE', 'Sales returned an invalid ' . $label . '.', 503);
        }

        return $value;
    }

    private function decimal(mixed $value, string $label): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new AppError('DEPENDENCY_UNAVAILABLE', 'Inventory returned an invalid ' . $label . '.', 503);
        }

        $decimal = (string) $value;
        if (
            !preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,3})?\z/D', $decimal)
            || !preg_match('/[1-9]/', $decimal)
        ) {
            throw new AppError('DEPENDENCY_UNAVAILABLE', 'Inventory returned an invalid ' . $label . '.', 503);
        }

        return $decimal;
    }

    private function lineWeightKg(
        TransactionContext $tx,
        int $companyId,
        string $productId,
        string $quantity
    ): string {
        $rows = $tx->query(
            "SELECT CASE WHEN UNIT_WEIGHT_GRAMS > 0 THEN 'T' ELSE 'F' END AS HAS_VALID_WEIGHT,
                    TO_CHAR(
                        UNIT_WEIGHT_GRAMS * :quantity / 1000,
                        'FM999999990D000',
                        'NLS_NUMERIC_CHARACTERS=''.,'''
                    ) AS TOTAL_WEIGHT_KG
             FROM ME_INV_PRODUCTS
             WHERE COMPANY_ID = :companyId AND PRODUCT_ID = :productId",
            [
                'quantity' => $quantity,
                'companyId' => $companyId,
                'productId' => $productId
            ]
        );

        if (count($rows) !== 1) {
            throw new AppError('INVALID_STATE', 'Product does not exist for this company.', 409);
        }
        if ($rows[0]['HAS_VALID_WEIGHT'] !== 'T') {
            throw new AppError('INVALID_STATE', 'Product must have a positive UNIT_WEIGHT_GRAMS value.', 409);
        }

        return $this->decimal($rows[0]['TOTAL_WEIGHT_KG'] ?? null, 'Product-derived total weight');
    }

    private function mapRow(array $row): array
    {
        return [
            'id' => (int) $row['ID'],
            'trip_code' => (int) $row['TRIP_CODE'],
            'vehicle_id' => (int) $row['VEHICLE_ID'],
            'driver_id' => (int) $row['DRIVER_ID'],
            'planned_departure_at' => $row['PLANNED_DEPARTURE_AT'],
            'status' => $this->apiStatus($row['STATUS']),
            'sales_order_id' => (int) $row['SALES_ORDER_ID'],
            'assigned_at' => $row['ASSIGNED_AT'],
            'assigned_by' => $row['ASSIGNED_BY'] === null ? null : (int) $row['ASSIGNED_BY'],
            'cancelled_at' => $row['CANCELLED_AT'],
            'cancelled_by' => $row['CANCELLED_BY'] === null ? null : (int) $row['CANCELLED_BY'],
            'cancellation_reason' => $row['CANCELLATION_REASON'],
            'dispatched_at' => $row['DISPATCHED_AT'],
            'dispatched_by' => $row['DISPATCHED_BY'] === null ? null : (int) $row['DISPATCHED_BY'],
            'delivered_at' => $row['DELIVERED_AT'],
            'delivered_by' => $row['DELIVERED_BY'] === null ? null : (int) $row['DELIVERED_BY'],
            'planned_load_kg' => round((float) $row['PLANNED_LOAD_KG'], 3),
            'capacity_kg' => round((float) $row['CAPACITY_KG'], 3),
            'remaining_capacity_kg' => round((float) $row['REMAINING_CAPACITY_KG'], 3)
        ];
    }
}

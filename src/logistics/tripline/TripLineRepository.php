<?php

declare(strict_types=1);

namespace MiniErp\logistics\tripline;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;

/** Trip-line SQL and read-only lookups into teammate-owned tables. */
final class TripLineRepository
{
    private const COLUMNS = 'ID, TRIP_ID, SALES_LINE_ID, PRODUCT_ID, QUANTITY, TOTAL_WEIGHT, LINE_LOAD';

    public function __construct(private Connection $connection) {}

    public function findAll(int $companyId, array $page): array
    {
        $where = 'COMPANY_ID = :company_id';
        $parameters = ['company_id' => $companyId, 'offset' => $page['offset'], 'page_size' => $page['page_size']];
        if ($page['trip_id'] !== null) {
            $where .= ' AND TRIP_ID = :trip_id';
            $parameters['trip_id'] = $page['trip_id'];
        }
        return $this->connection->query('SELECT ' . self::COLUMNS . ' FROM ME_LOG_TRIP_LINES WHERE ' . $where . '
            ORDER BY TRIP_ID, ID OFFSET :offset ROWS FETCH NEXT :page_size ROWS ONLY', $parameters);
    }

    public function find(int $companyId, int $id, bool $lock = false): array
    {
        $rows = $this->connection->query(
            'SELECT ' . self::COLUMNS . '
            FROM ME_LOG_TRIP_LINES WHERE COMPANY_ID = :company_id AND ID = :id' . ($lock ? ' FOR UPDATE' : ''),
            ['company_id' => $companyId, 'id' => $id]
        );
        if ($rows === []) {
            throw new AppError('NOT_FOUND', 'Trip line not found.', 404);
        }
        return $rows[0];
    }

    public function trip(int $companyId, int $tripId, bool $lock = false): array
    {
        $rows = $this->connection->query(
            'SELECT ID, VEHICLE_ID, SALES_ORDER_ID, STATUS FROM ME_LOG_TRIPS
            WHERE COMPANY_ID = :company_id AND ID = :trip_id' . ($lock ? ' FOR UPDATE' : ''),
            ['company_id' => $companyId, 'trip_id' => $tripId]
        );
        if ($rows === []) {
            throw new AppError('NOT_FOUND', 'Trip not found.', 404);
        }
        return $rows[0];
    }

    public function lockDraftTrip(int $companyId, int $tripId): array
    {
        $snapshot = $this->trip($companyId, $tripId);
        // Lock the resource before its trip, matching the group's resource-first lock order.
        $vehicles = $this->connection->query(
            'SELECT ID FROM ME_LOG_VEHICLES
            WHERE COMPANY_ID = :company_id AND ID = :vehicle_id FOR UPDATE',
            ['company_id' => $companyId, 'vehicle_id' => $snapshot['VEHICLE_ID']]
        );
        $trip = $this->trip($companyId, $tripId, true);
        if ($vehicles === [] || (string) $trip['VEHICLE_ID'] !== (string) $snapshot['VEHICLE_ID']) {
            throw new AppError('CONFLICT', 'Trip vehicle changed. Retry the request.', 409);
        }
        if ($trip['STATUS'] !== 'D') {
            throw new AppError('INVALID_STATE', 'Only Draft trip lines can be changed.', 409);
        }
        return $trip;
    }

    public function lockSource(int $companyId, mixed $orderId, mixed $lineId): array
    {
        // Lock Sales' parent before its line. No Sales data is changed by this module.
        $orders = $this->connection->query(
            "SELECT STATUS FROM ME_OE_DOCUMENTS
            WHERE CNY# = :company_id AND RECORD# = :order_id AND DOCUMENT_TYPE = 'SALES_ORDER' FOR UPDATE",
            ['company_id' => $companyId, 'order_id' => $orderId]
        );
        $lines = $this->connection->query(
            'SELECT RECORD#, PRODUCT_ID, QUANTITY, TRIP_ID, LOGISTICS_TRIP_LINE_ID
            FROM ME_OE_DOCUMENT_LINES WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :order_id
            AND RECORD# = :sales_line_id FOR UPDATE',
            ['company_id' => $companyId, 'order_id' => $orderId, 'sales_line_id' => $lineId]
        );
        if ($orders === [] || $lines === []) {
            throw new AppError('NOT_FOUND', 'Sales line does not belong to this trip order and company.', 404);
        }
        if ($lines[0]['TRIP_ID'] !== null || $lines[0]['LOGISTICS_TRIP_LINE_ID'] !== null) {
            throw new AppError('REFERENCED_RECORD', 'Sales already references this line assignment. Use the team assignment workflow.', 409);
        }
        if ($orders[0]['STATUS'] !== 'SUBMITTED') {
            throw new AppError('INVALID_STATE', 'The Sales order must be Submitted before editing planning lines.', 409);
        }
        return $lines[0];
    }

    public function weight(int $companyId, mixed $productId, string $quantity): string
    {
        // Oracle NUMBER arithmetic avoids rounding KG through a PHP float.
        $rows = $this->connection->query(
            "SELECT TO_CHAR(UNIT_WEIGHT_GRAMS * :quantity / 1000,
            'FM999999990D000', 'NLS_NUMERIC_CHARACTERS=''.,''') AS TOTAL_WEIGHT
            FROM ME_INV_PRODUCTS WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id
            AND IS_ACTIVE = 'Y' AND UNIT_WEIGHT_GRAMS > 0 FOR UPDATE",
            ['company_id' => $companyId, 'product_id' => $productId, 'quantity' => $quantity]
        );
        $weight = $rows[0]['TOTAL_WEIGHT'] ?? null;
        if (!is_string($weight) || !preg_match('/\A[0-9]{1,9}\.[0-9]{3}\z/D', $weight) || !preg_match('/[1-9]/', $weight)) {
            throw new AppError('INVALID_STATE', 'Product is inactive, missing, or has an invalid total weight.', 409);
        }
        return $weight;
    }

    public function duplicateExists(int $companyId, mixed $sourceId, ?int $excludeId): bool
    {
        $parameters = ['company_id' => $companyId, 'sales_line_id' => $sourceId];
        $exclude = '';
        if ($excludeId !== null) {
            $exclude = ' AND L.ID <> :exclude_id';
            $parameters['exclude_id'] = $excludeId;
        }
        return $this->connection->query(
            "SELECT L.ID FROM ME_LOG_TRIP_LINES L
            JOIN ME_LOG_TRIPS T ON T.COMPANY_ID = L.COMPANY_ID AND T.ID = L.TRIP_ID
            WHERE L.COMPANY_ID = :company_id AND L.SALES_LINE_ID = :sales_line_id AND T.STATUS <> 'X'" . $exclude,
            $parameters
        ) !== [];
    }

    public function auditUserId(int $companyId, string $userCode): string
    {
        $rows = $this->connection->query(
            'SELECT ID FROM ME_USERS
            WHERE COMPANY_ID = :company_id AND USER_ID = :user_code AND ACTIVE = 1',
            ['company_id' => $companyId, 'user_code' => $userCode]
        );
        if (count($rows) !== 1 || !preg_match('/\A[1-9][0-9]{0,29}\z/D', (string) $rows[0]['ID'])) {
            throw new AppError('FORBIDDEN', 'An active numeric audit user is required.', 403);
        }
        return (string) $rows[0]['ID'];
    }

    public function create(int $companyId, int $tripId, array $data, string $auditId): array
    {
        $this->connection->execute(
            'INSERT INTO ME_LOG_TRIP_LINES
            (COMPANY_ID, TRIP_ID, SALES_LINE_ID, PRODUCT_ID, QUANTITY, TOTAL_WEIGHT, LINE_LOAD, CREATED_BY, UPDATED_BY)
            VALUES (:company_id, :trip_id, :sales_line_id, :product_id, :quantity, :total_weight, :line_load, :created_by, :updated_by)',
            ['company_id' => $companyId, 'trip_id' => $tripId, ...$data, 'created_by' => $auditId, 'updated_by' => $auditId]
        );
        $rows = $this->connection->query(
            'SELECT ID FROM ME_LOG_TRIP_LINES
            WHERE COMPANY_ID = :company_id AND TRIP_ID = :trip_id AND SALES_LINE_ID = :sales_line_id',
            ['company_id' => $companyId, 'trip_id' => $tripId, 'sales_line_id' => $data['sales_line_id']]
        );
        if (count($rows) !== 1) {
            throw new \LogicException('Created trip line could not be uniquely resolved.');
        }
        return $this->find($companyId, (int) $rows[0]['ID']);
    }

    public function update(int $companyId, int $id, array $data, string $auditId): array
    {
        $count = $this->connection->execute(
            'UPDATE ME_LOG_TRIP_LINES SET SALES_LINE_ID = :sales_line_id,
            PRODUCT_ID = :product_id, QUANTITY = :quantity, TOTAL_WEIGHT = :total_weight, LINE_LOAD = :line_load,
            UPDATED_AT = SYSTIMESTAMP, UPDATED_BY = :updated_by WHERE COMPANY_ID = :company_id AND ID = :id',
            ['company_id' => $companyId, 'id' => $id, ...$data, 'updated_by' => $auditId]
        );
        if ($count !== 1) {
            throw new \LogicException('Unexpected trip-line update count.');
        }
        return $this->find($companyId, $id);
    }

    public function delete(int $companyId, int $id): void
    {
        if ($this->connection->execute(
            'DELETE FROM ME_LOG_TRIP_LINES WHERE COMPANY_ID = :company_id AND ID = :id',
            ['company_id' => $companyId, 'id' => $id]
        ) !== 1) {
            throw new \LogicException('Unexpected trip-line delete count.');
        }
    }

    public function assertCapacity(int $companyId, array $trip): void
    {
        $rows = $this->connection->query(
            "SELECT CASE WHEN NVL(SUM(L.LINE_LOAD), 0) <= V.CAPACITY
            THEN 'T' ELSE 'F' END AS WITHIN_CAPACITY FROM ME_LOG_VEHICLES V
            LEFT JOIN ME_LOG_TRIP_LINES L ON L.COMPANY_ID = V.COMPANY_ID AND L.TRIP_ID = :trip_id
            WHERE V.COMPANY_ID = :company_id AND V.ID = :vehicle_id GROUP BY V.CAPACITY",
            ['company_id' => $companyId, 'trip_id' => $trip['ID'], 'vehicle_id' => $trip['VEHICLE_ID']]
        );
        if (count($rows) !== 1 || $rows[0]['WITHIN_CAPACITY'] !== 'T') {
            throw new AppError('CAPACITY_EXCEEDED', 'Trip load exceeds vehicle capacity.', 409);
        }
    }
}

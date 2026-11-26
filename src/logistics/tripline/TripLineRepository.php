<?php

declare(strict_types=1);

namespace MiniErp\logistics\tripline;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;

/** Read saved lines, following live Sales/Inventory links only for non-cancelled trips. */
final class TripLineRepository
{
    // Explicit aliases prevent similarly named columns from overwriting each other in OCI rows.
    // No company details, user records, creator or updater identities are selected.
    // Each join follows a composite primary key: one output row per saved line, with no per-item queries.
    // X stops the live chain; the order + source line predicates prevent joining other order lines.
    private const SELECT = <<<'SQL'
        SELECT L.ID, L.TRIP_ID, L.SALES_LINE_ID, L.PRODUCT_ID, L.QUANTITY, L.TOTAL_WEIGHT, L.LINE_LOAD,
            TO_CHAR(L.CREATED_AT, 'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM') AS CREATED_AT,
            TO_CHAR(L.UPDATED_AT, 'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM') AS UPDATED_AT,
            T.ID AS TRIP_IDENTITY, T.TRIP_CODE AS TRIP_TRIP_CODE,
            T.VEHICLE_ID AS TRIP_VEHICLE_ID, T.DRIVER_ID AS TRIP_DRIVER_ID,
            T.SALES_ORDER_ID AS TRIP_SALES_ORDER_ID, T.STATUS AS TRIP_STATUS,
            TO_CHAR(T.PLANNED_DEPARTURE_AT, 'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM') AS TRIP_PLANNED_DEPARTURE_AT,
            TO_CHAR(T.ASSIGNED_AT, 'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM') AS TRIP_ASSIGNED_AT,
            T.ASSIGNED_BY AS TRIP_ASSIGNED_BY,
            TO_CHAR(T.CANCELLED_AT, 'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM') AS TRIP_CANCELLED_AT,
            T.CANCELLED_BY AS TRIP_CANCELLED_BY, T.CANCELLATION_REASON AS TRIP_CANCELLATION_REASON,
            TO_CHAR(T.DISPATCHED_AT, 'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM') AS TRIP_DISPATCHED_AT,
            T.DISPATCHED_BY AS TRIP_DISPATCHED_BY,
            TO_CHAR(T.DELIVERED_AT, 'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM') AS TRIP_DELIVERED_AT,
            T.DELIVERED_BY AS TRIP_DELIVERED_BY,
            TO_CHAR(T.CREATED_AT, 'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM') AS TRIP_CREATED_AT,
            TO_CHAR(T.UPDATED_AT, 'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM') AS TRIP_UPDATED_AT,
            S.RECORD# AS SALE_IDENTITY, S.DOCUMENT_RECORD# AS SALE_DOCUMENT_RECORD_ID,
            S.LINE_NUMBER AS SALE_LINE_NUMBER, S.PRODUCT_ID AS SALE_PRODUCT_ID,
            S.DESCRIPTION AS SALE_DESCRIPTION, S.QUANTITY AS SALE_QUANTITY, S.UNIT_PRICE AS SALE_UNIT_PRICE,
            S.SOURCE_LINE_RECORD# AS SALE_SOURCE_LINE_RECORD_ID,
            S.TRIP_ID AS SALE_TRIP_ID, S.LOGISTICS_TRIP_LINE_ID AS SALE_LOGISTICS_TRIP_LINE_ID,
            S.TRIP_STATUS AS SALE_TRIP_STATUS,
            TO_CHAR(S.WHENCREATED, 'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM') AS SALE_WHENCREATED,
            TO_CHAR(S.WHENMODIFIED, 'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM') AS SALE_WHENMODIFIED,
            P.PRODUCT_ID AS PRODUCT_IDENTITY, P.PRODUCT_NAME AS PRODUCT_PRODUCT_NAME,
            P.SKU AS PRODUCT_SKU, P.WAREHOUSE_ID AS PRODUCT_WAREHOUSE_ID,
            P.UNIT_LABEL AS PRODUCT_UNIT_LABEL, P.UNIT_WEIGHT_GRAMS AS PRODUCT_UNIT_WEIGHT_GRAMS,
            P.IS_ACTIVE AS PRODUCT_IS_ACTIVE,
            TO_CHAR(P.WHEN_CREATED, 'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM') AS PRODUCT_WHEN_CREATED,
            TO_CHAR(P.WHEN_UPDATED, 'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM') AS PRODUCT_WHEN_UPDATED,
            B.PRODUCT_ID AS STOCK_PRODUCT_ID, B.ON_HAND AS STOCK_ON_HAND,
            B.RESERVED AS STOCK_RESERVED, B.AVAILABLE AS STOCK_AVAILABLE,
            TO_CHAR(B.WHEN_UPDATED, 'YYYY-MM-DD"T"HH24:MI:SS.FF6TZH:TZM') AS STOCK_WHEN_UPDATED
        FROM ME_LOG_TRIP_LINES L
        LEFT JOIN ME_LOG_TRIPS T ON T.COMPANY_ID = L.COMPANY_ID AND T.ID = L.TRIP_ID
        LEFT JOIN ME_OE_DOCUMENT_LINES S ON T.STATUS <> 'X'
            AND S.CNY# = T.COMPANY_ID AND S.DOCUMENT_RECORD# = T.SALES_ORDER_ID AND S.RECORD# = L.SALES_LINE_ID
        LEFT JOIN ME_INV_PRODUCTS P ON P.COMPANY_ID = S.CNY# AND P.PRODUCT_ID = S.PRODUCT_ID
        LEFT JOIN ME_INV_STOCK_BALANCE B ON B.COMPANY_ID = P.COMPANY_ID AND B.PRODUCT_ID = P.PRODUCT_ID
        SQL;

    public function __construct(private Connection $connection) {}

    public function findAll(int $companyId, array $page): array
    {
        $where = 'L.COMPANY_ID = :company_id';
        $parameters = ['company_id' => $companyId, 'offset' => $page['offset'], 'page_size' => $page['page_size']];
        if ($page['trip_id'] !== null) {
            $where .= ' AND L.TRIP_ID = :trip_id';
            $parameters['trip_id'] = $page['trip_id'];
        }
        return $this->connection->query(self::SELECT . ' WHERE ' . $where . '
            ORDER BY L.TRIP_ID, L.ID OFFSET :offset ROWS FETCH NEXT :page_size ROWS ONLY', $parameters);
    }

    public function find(int $companyId, int $id): array
    {
        $rows = $this->connection->query(
            self::SELECT . ' WHERE L.COMPANY_ID = :company_id AND L.ID = :id',
            ['company_id' => $companyId, 'id' => $id]
        );
        if ($rows === []) {
            throw new AppError('NOT_FOUND', 'Trip line not found.', 404);
        }
        return $rows[0];
    }

    public function trip(int $companyId, int $tripId): array
    {
        $rows = $this->connection->query(
            'SELECT ID, VEHICLE_ID, SALES_ORDER_ID, STATUS FROM ME_LOG_TRIPS
            WHERE COMPANY_ID = :company_id AND ID = :trip_id',
            ['company_id' => $companyId, 'trip_id' => $tripId]
        );
        if ($rows === []) {
            throw new AppError('NOT_FOUND', 'Trip not found.', 404);
        }
        return $rows[0];
    }
}

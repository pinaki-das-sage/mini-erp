<?php

declare(strict_types=1);

namespace MiniErp\logistics\tripline;

use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

/** Read existing trip lines. Creation and lifecycle changes belong to the trip workflow. */
final class TripLineService
{
    private TripLineRepository $repository;
    private TripLineValidator $validator;

    public function __construct(private Connection $connection)
    {
        $this->repository = new TripLineRepository($connection);
        $this->validator = new TripLineValidator();
    }

    public function getTripLines(Actor $actor, array $pagination): array
    {
        $this->authorize($actor, 'read');
        $page = $this->validator->pagination($pagination);
        if ($page['trip_id'] !== null) {
            $this->repository->trip($actor->companyId, $page['trip_id']);
        }
        return [
            'items' => array_map($this->present(...), $this->repository->findAll($actor->companyId, $page)),
            'page' => $page['page'],
            'page_size' => $page['page_size']
        ];
    }

    public function getTripLine(Actor $actor, string $id): array
    {
        $this->authorize($actor, 'read');
        return $this->present($this->repository->find($actor->companyId, $this->validator->id($id)));
    }

    private function authorize(Actor $actor, string $operation): void
    {
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.trip_lines', $operation);
    }

    private function present(array $row): array
    {
        $trip = $this->related($row, 'TRIP_', [
            'trip_code' => 'int',
            'vehicle_id' => 'int',
            'driver_id' => 'int',
            'sales_order_id' => 'int',
            'status' => 'text',
            'planned_departure_at' => 'text',
            'assigned_at' => 'text',
            'assigned_by' => 'int',
            'cancelled_at' => 'text',
            'cancelled_by' => 'int',
            'cancellation_reason' => 'text',
            'dispatched_at' => 'text',
            'dispatched_by' => 'int',
            'delivered_at' => 'text',
            'delivered_by' => 'int',
            'created_at' => 'text',
            'updated_at' => 'text',
        ]);
        $salesLine = null;
        $product = null;

        // Keep the original line snapshot, but stop live lookups after a cancelled/missing trip.
        // C is Delivered; only X is Cancelled.
        if ($trip !== null && $trip['status'] !== 'X') {
            $salesLine = $this->related($row, 'SALE_', [
                'document_record_id' => 'int',
                'line_number' => 'int',
                'product_id' => 'int',
                'description' => 'text',
                'quantity' => 'int',
                'unit_price' => 'float',
                'source_line_record_id' => 'int',
                'trip_id' => 'int',
                'logistics_trip_line_id' => 'int',
                'trip_status' => 'text',
                'whencreated' => 'text',
                'whenmodified' => 'text',
            ]);
            if ($salesLine !== null) {
                $product = $this->related($row, 'PRODUCT_', [
                    'product_name' => 'text',
                    'sku' => 'text',
                    'warehouse_id' => 'text',
                    'unit_label' => 'text',
                    'unit_weight_grams' => 'float',
                    'is_active' => 'text',
                    'when_created' => 'text',
                    'when_updated' => 'text',
                ]);
                if ($product !== null) {
                    // The balance is current Inventory state, not a dispatch/delivery snapshot.
                    $product['stock_balance'] = $row['STOCK_PRODUCT_ID'] === null ? null : [
                        'product_id' => $this->number($row['STOCK_PRODUCT_ID']),
                        'on_hand' => $this->number($row['STOCK_ON_HAND']),
                        'reserved' => $this->number($row['STOCK_RESERVED']),
                        'available' => $this->number($row['STOCK_AVAILABLE']),
                        'when_updated' => $row['STOCK_WHEN_UPDATED'],
                    ];
                }
            }
        }

        return [
            'id' => $this->number($row['ID']),
            'trip_id' => $this->number($row['TRIP_ID']),
            'sales_line_id' => $this->number($row['SALES_LINE_ID']),
            'product_id' => $this->number($row['PRODUCT_ID']),
            'quantity' => $this->number($row['QUANTITY']),
            'total_weight' => $this->number($row['TOTAL_WEIGHT'], true),
            'line_load' => $this->number($row['LINE_LOAD'], true),
            'created_at' => $row['CREATED_AT'],
            'updated_at' => $row['UPDATED_AT'],
            'trip' => $trip,
            'sales_line' => $salesLine,
            'product' => $product,
        ];
    }

    /** Build a nested relation; LEFT JOIN misses stay null instead of becoming fake zero IDs. */
    private function related(array $row, string $prefix, array $fields): ?array
    {
        if ($row[$prefix . 'IDENTITY'] === null) {
            return null;
        }
        $result = ['id' => $this->number($row[$prefix . 'IDENTITY'])];
        foreach ($fields as $field => $type) {
            $value = $row[$prefix . strtoupper($field)];
            $result[$field] = $type === 'text' ? $value : $this->number($value, $type === 'float');
        }
        return $result;
    }

    /** Cast only known NUMBER columns. VARCHAR codes and nulls retain their original meaning. */
    private function number(mixed $value, bool $decimal = false): int|float|null
    {
        if ($value === null) {
            return null;
        }
        if ($decimal) {
            return (float) $value;
        }
        $integer = filter_var((string) $value, FILTER_VALIDATE_INT);
        // Oracle NUMBER IDs can exceed PHP's integer range; avoid silently clamping them.
        return $integer === false ? (float) $value : $integer;
    }
}

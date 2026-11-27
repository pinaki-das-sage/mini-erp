<?php
declare(strict_types=1);

namespace MiniErp\inventory;

use MiniErp\shared\AppError;
use MiniErp\shared\WebPath;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;

/** Session-authenticated read-only routes. Product routes and lab mutation APIs remain separate. */
final readonly class ReservationWeb
{
    public function __construct(private Connection $connection, private WebPath $paths, private ?\Closure $plannedQuantity = null) {}

    public static function matches(string $path): bool
    {
        return (bool) preg_match('#\A/(?:modules|api)/inventory/reservations(?:/|\z)#', $path);
    }

    /** Same page/result return contract as InventoryWeb; Kernel owns authentication and rendering. */
    public function handle(string $method, string $path, array $input, Actor $actor, array $query = []): array
    {
        $isApi = str_starts_with($path, '/api/');
        try {
            if (!self::matches($path)) { throw new AppError('NOT_FOUND', 'Reservation page not found.', 404); }
            if ($method !== 'GET') { throw new AppError('METHOD_NOT_ALLOWED', 'Reservation screens are read-only.', 405); }
            $prefix = $isApi ? '/api/inventory/reservations' : '/modules/inventory/reservations';
            $transactions = new TransactionManager($this->connection);
            if ($path === $prefix) {
                $result = $transactions->run(fn (TransactionContext $tx): array => (new ReservationReadService($this->connection, $this->claimLookup()))->list($actor, $tx, $query));
                if ($isApi) { return ['status' => 200, 'result' => $result]; }
                $base = $result['filters'] + ['page_size' => (string) $result['page_size']];
                $url = $this->paths->url('/modules/inventory/reservations');
                foreach ($result['items'] as &$item) {
                    $item['detail_url'] = $url . '/' . $item['reservation_id'];
                    $item['product_url'] = $this->paths->url('/modules/inventory/products/' . $item['product_id']);
                }
                unset($item);
                $result['list_url'] = $url;
                $result['columns'] = [];
                foreach ([
                    ['reservation_id', 'Reservation ID', '', 'numeric', 10, 1],
                    ['document_number', 'Source Document ID', '', 'text', 100, null],
                    ['line_number', 'Source Document Line Number', '', 'numeric', 15, 1],
                    ['sku', 'Product SKU', '', 'text', 100, null],
                    ['active_quantity', 'Active', 'quantity', 'numeric', 10, 0],
                    ['claimed_quantity', 'Claimed', 'quantity', 'numeric', 10, 0],
                    ['dispatched_quantity', 'Dispatched', 'quantity', 'numeric', 10, 0],
                    ['status', 'Status', '', 'status', 0, null],
                ] as [$key, $label, $unit, $type, $length, $minimum]) {
                    $result['columns'][] = ['key' => $key, 'label' => $label, 'unit' => $unit, 'type' => $type,
                        'length' => $length, 'minimum' => $minimum, 'value' => $result['filters'][$key] ?? '',
                        'filtered' => ($result['filters'][$key] ?? '') !== ''];
                }
                $result['previous_url'] = $result['page'] > 1 ? $url . '?' . http_build_query($base + ['page' => $result['page'] - 1]) : null;
                $result['next_url'] = $result['page'] < $result['pages'] ? $url . '?' . http_build_query($base + ['page' => $result['page'] + 1]) : null;
                return ['view' => 'inventory/reservations/list.tpl', 'title' => 'Reservations', 'data' => $result];
            }
            if (!preg_match('#\A' . preg_quote($prefix, '#') . '/([1-9][0-9]{0,9})\z#', $path, $match)) {
                throw new AppError('NOT_FOUND', 'Reservation page not found.', 404);
            }
            if ($query !== []) { throw new AppError('VALIDATION_ERROR', 'Reservation details do not accept query overrides.'); }
            $result = $transactions->run(function (TransactionContext $tx) use ($actor, $match): array {
                $detail = (new ReservationService($this->connection, $this->claimLookup()))->getReservationById($actor, $tx, (int) $match[1]);
                return $detail + (new ReservationReadService($this->connection))->getReferences($actor, $tx,
                    $detail['sales_order_id'], $detail['sales_line_id'], $detail['product_id']);
            });
            if ($isApi) { return ['status' => 200, 'result' => $result]; }
            $result['product_url'] = $this->paths->url('/modules/inventory/products/' . $result['product_id']);
            $result['list_url'] = $this->paths->url('/modules/inventory/reservations');
            $result['same_line_url'] = $result['list_url'] . '?' . http_build_query(['sales_order_id' => $result['sales_order_id']]);
            return ['view' => 'inventory/reservations/detail.tpl', 'title' => 'Reservation #' . $result['reservation_id'], 'data' => $result];
        } catch (AppError $error) {
            if ($isApi) { throw $error; }
            return ['view' => 'inventory/reservations/error.tpl', 'title' => 'Reservations', 'status' => $error->status,
                'data' => ['message' => $error->getMessage(), 'code' => $error->errorCode, 'list_url' => $this->paths->url('/modules/inventory/reservations')]];
        }
    }

    private function claimLookup(): \Closure
    {
        return $this->plannedQuantity ?? \Closure::fromCallable([(new LogisticsClaimLookup()), 'getPlannedQuantity']);
    }
}

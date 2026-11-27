<?php
declare(strict_types=1);

namespace MiniErp\inventory;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;

/**
 * Browser pages for Inventory. Kernel hands over a route path and gets back which template to show and the
 * data for it, or the result of a save. It calls the same services as the REST API, so permissions and
 * rules are identical.
 */
final readonly class InventoryWeb
{
    private const PAGE_SIZE = 20;

    private ProductService $products;
    private StockHistoryService $history;

    public function __construct(Connection $connection)
    {
        $this->products = new ProductService($connection);
        $this->history = new StockHistoryService($connection);
    }

    /**
     * A page gives view, title and data; an API call gives status and result.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function handle(Actor $actor, string $method, string $path, array $query, array $input = []): array
    {
        if ($method === 'GET' && $path === '/modules/inventory/products') {
            return $this->list($actor, $query);
        }
        if ($method === 'GET' && $path === '/modules/inventory/products/new') {
            return ['view' => 'inventory/products/new.tpl', 'title' => 'Add product', 'data' => []];
        }
        if ($method === 'GET' && preg_match('#\A/modules/inventory/products/([1-9][0-9]{0,9})\z#', $path, $match) === 1) {
            return $this->view($actor, (int) $match[1], $query);
        }
        if ($method === 'GET' && preg_match('#\A/modules/inventory/products/([1-9][0-9]{0,9})/edit\z#', $path, $match) === 1) {
            return $this->edit($actor, (int) $match[1]);
        }
        if ($method === 'POST' && preg_match('#\A/api/inventory/products/update/([1-9][0-9]{0,9})\z#', $path, $match) === 1) {
            $productId = (int) $match[1];
            $this->products->update($actor, 'web-' . bin2hex(random_bytes(8)), $productId, self::updateInput($input));
            return ['status' => 200, 'result' => ['productId' => $productId]];
        }
        if ($method === 'POST' && preg_match('#\A/api/inventory/products/delete/([1-9][0-9]{0,9})\z#', $path, $match) === 1) {
            $productId = (int) $match[1];
            $this->products->delete($actor, 'web-' . bin2hex(random_bytes(8)), $productId);
            return ['status' => 200, 'result' => ['productId' => $productId, 'deleted' => true]];
        }
        if ($method === 'POST' && $path === '/api/inventory/products') {
            $created = $this->products->create($actor, 'web-' . bin2hex(random_bytes(8)), self::productInput($input));
            return ['status' => 201, 'result' => ['productId' => $created['product_id'], 'sku' => $created['sku']]];
        }
        throw new AppError('NOT_FOUND', 'Page or operation not found.', 404);
    }

    /**
     * Form fields (camelCase, weight in kilograms as typed) to the service's input (grams).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function productInput(array $input): array
    {
        return [
            'name' => $input['name'] ?? null,
            'sku' => $input['sku'] ?? null,
            'unit_label' => $input['unitLabel'] ?? null,
            'unit_weight_grams' => ProductWeight::grams($input['unitWeightKg'] ?? null, true),
        ];
    }

    /**
     * Edit form fields to the service's update input. The SKU is never sent: it cannot change.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function updateInput(array $input): array
    {
        return [
            'name' => $input['name'] ?? null,
            'unit_label' => $input['unitLabel'] ?? null,
            'unit_weight_grams' => ProductWeight::grams($input['unitWeightKg'] ?? null, true),
            'active' => $input['active'] ?? null,
        ];
    }

    /**
     * @return array{view: string, title: string, data: array<string, mixed>}
     */
    private function edit(Actor $actor, int $productId): array
    {
        $product = $this->products->get($actor, $productId);
        return [
            'view' => 'inventory/products/edit.tpl',
            'title' => 'Edit ' . $product['name'],
            'data' => ['product' => [
                'id' => $product['product_id'],
                'sku' => $product['sku'],
                'name' => $product['name'],
                'unit' => $product['unit_label'],
                'weightKg' => ProductWeight::kilograms($product['unit_weight_grams']),
                'active' => $product['active'],
                'reserved' => $product['reserved'],
            ]],
        ];
    }

    /** Rows per page: 10, 20 or 50, otherwise the default. @param array<string, mixed> $query */
    private static function pageSize(array $query): int
    {
        return isset($query['page_size']) && is_string($query['page_size']) && in_array($query['page_size'], ['10', '20', '50'], true)
            ? (int) $query['page_size'] : self::PAGE_SIZE;
    }

    /** @param array<string, mixed> $query */
    private static function pageNumber(array $query): int
    {
        return isset($query['page']) && is_string($query['page']) && ctype_digit($query['page'])
            && strlen($query['page']) <= 6 && (int) $query['page'] >= 1 ? (int) $query['page'] : 1;
    }

    /**
     * Product list with column filters (SKU, name, status) and a page size of 10, 20 or 50, like the reservation list.
     *
     * @param array<string, mixed> $query
     * @return array{view: string, title: string, data: array<string, mixed>}
     */
    private function list(Actor $actor, array $query): array
    {
        $filters = [];
        foreach (['sku', 'name'] as $key) {
            $filters[$key] = isset($query[$key]) && is_string($query[$key]) ? mb_substr(trim($query[$key]), 0, 100) : '';
        }
        $status = isset($query['status']) && is_string($query['status']) ? strtoupper(trim($query['status'])) : '';
        $filters['status'] = in_array($status, ['ACTIVE', 'INACTIVE'], true) ? $status : '';
        $size = self::pageSize($query);
        $applied = array_filter($filters, static fn (string $value): bool => $value !== '');

        $page = self::pageNumber($query);
        $result = $this->products->list($actor, $applied + ['page' => (string) $page, 'page_size' => (string) $size]);
        $pages = max(1, (int) ceil($result['total'] / $size));
        if ($page > $pages) {
            $page = $pages;
            $result = $this->products->list($actor, $applied + ['page' => (string) $page, 'page_size' => (string) $size]);
        }
        $link = static fn (int $target): string => http_build_query($applied + ['page_size' => $size, 'page' => $target]);

        $columns = [];
        foreach (['sku' => ['SKU', 'text'], 'name' => ['Name', 'text'], 'status' => ['Status', 'status']] as $key => [$label, $type]) {
            $columns[$key] = ['key' => $key, 'label' => $label, 'type' => $type, 'value' => $filters[$key], 'filtered' => $filters[$key] !== ''];
        }

        return [
            'view' => 'inventory/products/list.tpl',
            'title' => 'Products',
            'data' => [
                'products' => array_map(static fn (array $item): array => [
                    'id' => $item['product_id'],
                    'sku' => $item['sku'],
                    'name' => $item['name'],
                    'unit' => $item['unit_label'],
                    'weightKg' => ProductWeight::kilograms($item['unit_weight_grams']),
                    'active' => $item['active'],
                    'statusCode' => $item['active'] ? 'ACTIVE' : 'INACTIVE',
                    'canDelete' => $item['can_delete'] ?? false,
                ], $result['items']),
                'filters' => $filters,
                'columns' => $columns,
                'total' => $result['total'],
                'page' => $page,
                'pageSize' => $size,
                'pages' => $pages,
                'first' => $result['total'] === 0 ? 0 : ($page - 1) * $size + 1,
                'last' => min($page * $size, $result['total']),
                'previousQuery' => $page > 1 ? $link($page - 1) : '',
                'nextQuery' => $page < $pages ? $link($page + 1) : '',
            ],
        ];
    }

    /**
     * One product with its stock and, if the user may see it, its movement history (oldest first).
     *
     * @param array<string, mixed> $query
     * @return array{view: string, title: string, data: array<string, mixed>}
     */
    private function view(Actor $actor, int $productId, array $query): array
    {
        $product = $this->products->get($actor, $productId);
        $size = self::pageSize($query);
        $page = self::pageNumber($query);

        $movements = null;
        $total = 0;
        try {
            $history = $this->history->forProduct($actor, $productId, ['page' => (string) $page, 'page_size' => (string) $size]);
            $total = $history['total'];
            $pages = max(1, (int) ceil($total / $size));
            if ($page > $pages) {
                $page = $pages;
                $history = $this->history->forProduct($actor, $productId, ['page' => (string) $page, 'page_size' => (string) $size]);
            }
            $movements = array_map(static fn (array $row): array => [
                'when' => str_replace('T', ' ', substr($row['moved_at'], 0, 19)) . ' UTC',
                'type' => ucfirst(strtolower($row['type'])),
                'sourceType' => $row['source_type'],
                'sourceId' => $row['source_id'],
                'reservationId' => $row['reservation_id'],
                'opening' => $row['opening_balance'],
                'received' => $row['received_quantity'],
                'dispatched' => $row['dispatched_quantity'],
                'closing' => $row['closing_balance'],
            ], $history['items']);
        } catch (AppError $error) {
            if ($error->errorCode !== 'FORBIDDEN') {
                throw $error;
            }
        }

        $pages = max(1, (int) ceil($total / $size));
        $link = static fn (int $target): string => http_build_query(['page_size' => $size, 'page' => $target]);
        return [
            'view' => 'inventory/products/view.tpl',
            'title' => $product['name'],
            'data' => [
                'product' => [
                    'id' => $product['product_id'],
                    'sku' => $product['sku'],
                    'name' => $product['name'],
                    'unit' => $product['unit_label'],
                    'weightKg' => ProductWeight::kilograms($product['unit_weight_grams']),
                    'warehouse' => $product['warehouse_id'],
                    'active' => $product['active'],
                    'statusCode' => $product['active'] ? 'ACTIVE' : 'INACTIVE',
                    'onHand' => $product['on_hand'],
                    'reserved' => $product['reserved'],
                    'available' => $product['available'],
                ],
                'movements' => $movements,
                'movementsTotal' => $total,
                'page' => $page,
                'pageSize' => $size,
                'pages' => $pages,
                'first' => $total === 0 ? 0 : ($page - 1) * $size + 1,
                'last' => min($page * $size, $total),
                'previousQuery' => $page > 1 ? $link($page - 1) : '',
                'nextQuery' => $page < $pages ? $link($page + 1) : '',
            ],
        ];
    }
}
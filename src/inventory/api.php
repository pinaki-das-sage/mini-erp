<?php
declare(strict_types=1);

use MiniErp\inventory\AvailabilityService;
use MiniErp\inventory\LogisticsClaimLookup;
use MiniErp\inventory\ProductService;
use MiniErp\inventory\ProductWeight;
use MiniErp\inventory\ReservationService;
use MiniErp\inventory\StockHistoryService;
use MiniErp\inventory\testing\ConsumeReservationCaller;
use MiniErp\inventory\testing\DummyLogisticsService;
use MiniErp\inventory\testing\ReserveStockScenarios;
use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;

return static function (Api $api, Connection $connection): void {
    $products = new ProductService($connection);
    $history = new StockHistoryService($connection);
    $productId = static function (array $params): int {
        if (!isset($params['id']) || !ctype_digit($params['id']) || strlen($params['id']) > 10 || (int) $params['id'] < 1) {
            throw new AppError('VALIDATION_ERROR', 'Product id must be a positive whole number.');
        }
        return (int) $params['id'];
    };

    // The public JSON is camelCase (productId, unitLabel, pageSize). The services keep their snake_case names,
    // so other teams that call them are not affected: keys are converted here, at the HTTP edge only.
    // Snake_case request keys are still accepted, so older callers keep working.
    $toSnake = static function (mixed $value) use (&$toSnake): mixed {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[is_string($key) ? strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $key)) : $key] = $toSnake($item);
        }
        return $out;
    };
    $toCamel = static function (mixed $value) use (&$toCamel): mixed {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[is_string($key) ? (string) preg_replace_callback('/_([a-z0-9])/', static fn (array $m): string => strtoupper($m[1]), $key) : $key] = $toCamel($item);
        }
        return $out;
    };
    // Weight is kilograms in the API and whole grams in the database and services (Logistics may read grams).
    // The conversion lives in ProductWeight so the web screens use exactly the same rule.
    $weightIn = static function (array $input): array {
        if (!array_key_exists('unit_weight_kg', $input)) {
            return $input;
        }
        $kg = $input['unit_weight_kg'];
        unset($input['unit_weight_kg']);
        $input['unit_weight_grams'] = ProductWeight::grams($kg);
        return $input;
    };
    $weightOut = static function (mixed $value) use (&$weightOut): mixed {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            if ($key === 'unit_weight_grams') {
                $out['unit_weight_kg'] = $item / 1000;
            } else {
                $out[$key] = $weightOut($item);
            }
        }
        return $out;
    };
    $edge = static fn (callable $handler): callable => static fn (Actor $actor, array $input, array $params, array $query = []): array
        => $toCamel($weightOut($handler($actor, $weightIn($toSnake($input)), $params, $toSnake($query))));

    // Callers do not have to supply an action reference: when none is sent the server makes a fresh one.
    // A client that wants exact retry protection can still send its own non-empty string.
    // Same edge conversion for Dev B's routes: camelCase in and out, services keep snake_case.
    $camelEdge = static fn (callable $handler): callable => static fn (Actor $actor, array $input, array $params, array $query = []): array
        => $toCamel($handler($actor, $toSnake($input), $params, $toSnake($query)));
    $reference = static fn (array $input): string => array_key_exists('action_reference', $input)
        ? Api::string($input, 'action_reference')
        : 'auto-' . bin2hex(random_bytes(8));

    $api->register('GET', '/inventory/products', 'inventory.products', 'read',
        $edge(static fn (Actor $actor, array $input, array $params, array $query): array => $products->list($actor, $query)));

    $api->register('GET', '/inventory/products/{id}', 'inventory.products', 'read',
        $edge(static fn (Actor $actor, array $input, array $params): array => $products->get($actor, $productId($params))));

    $api->register('GET', '/inventory/products/movements/{id}', 'inventory.movements', 'read',
        $edge(static fn (Actor $actor, array $input, array $params, array $query): array => $history->forProduct($actor, $productId($params), $query)));

    $api->register('POST', '/inventory/products', 'inventory.products', 'create',
        $edge(static fn (Actor $actor, array $input): array => $products->create($actor, $reference($input), $input)),
        201);

    // POST, not PUT or PATCH: the training host's gateway answers PUT, PATCH and DELETE with an HTML 403 before the app runs.
    $api->register('POST', '/inventory/products/update/{id}', 'inventory.products', 'update',
        $edge(static fn (Actor $actor, array $input, array $params): array => $products->update(
            $actor, $reference($input), $productId($params), $input,
        )));

    // POST for the same gateway reason. Refused with 409 while the product has stock or history.
    $api->register('POST', '/inventory/products/delete/{id}', 'inventory.products', 'delete',
        $edge(static fn (Actor $actor, array $input, array $params): array => $products->delete(
            $actor, $reference($input), $productId($params),
        )));

    // ---- Reservations and availability (Dev B) ----
    $availability = new AvailabilityService($connection);
    $reservations = new ReservationService($connection);
    $transactions = new TransactionManager($connection);

    $api->register('POST', '/inventory/testing/reservations/{reservation_id}/consume', 'inventory.reservations', 'consume',
        static function (Actor $actor, array $input, array $params) use ($connection, $transactions): array {
            $setting = static fn (string $key): mixed => $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            if ($setting('APP_MODE') !== 'standalone' || $setting('INVENTORY_RESERVATION_TEST_API') !== '1') {
                throw new AppError('NOT_FOUND', 'API operation not found.', 404);
            }
            $rawId = $params['reservation_id'] ?? null;
            if (!is_string($rawId) || !ctype_digit($rawId) || strlen($rawId) > 10 || (int) $rawId < 1) {
                throw new AppError('VALIDATION_ERROR', 'reservation_id must be a positive integer of at most 10 digits.');
            }
            $ids = [];
            foreach (['sales_order_id', 'sales_line_id', 'trip_id', 'trip_line_id'] as $key) {
                $id = Api::integer($input, $key);
                if ($id > 999999999999999) {
                    throw new AppError('VALIDATION_ERROR', $key . ' must have at most 15 digits.');
                }
                $ids[] = $id;
            }
            $result = $transactions->run(static fn (TransactionContext $tx): array =>
                (new ConsumeReservationCaller($connection))->consume($actor, $tx, (int) $rawId, ...$ids));
            return $result + ['committed' => true];
        });

    // Lab caller for actual release; claim quantities always come from Logistics tables.
    $api->register('POST', '/inventory/testing/reservations/{reservation_id}/release', 'inventory.reservations', 'release',
        static function (Actor $actor, array $input, array $params) use ($connection, $transactions): array {
            $setting = static fn (string $key): mixed => $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            if ($setting('APP_MODE') !== 'standalone' || $setting('INVENTORY_RESERVATION_TEST_API') !== '1') {
                throw new AppError('NOT_FOUND', 'API operation not found.', 404);
            }
            $rawId = $params['reservation_id'] ?? null;
            if (!is_string($rawId) || !ctype_digit($rawId) || strlen($rawId) > 15 || (int) $rawId < 1) {
                throw new AppError('VALIDATION_ERROR', 'reservation_id must be a positive integer of at most 15 digits.');
            }
            $reservationId = (int) $rawId;
            $orderId = Api::integer($input, 'sales_order_id');
            $lineId = Api::integer($input, 'sales_line_id');
            if ($orderId > 999999999999999 || $lineId > 999999999999999) {
                throw new AppError('VALIDATION_ERROR', 'Sales IDs must have at most 15 digits.');
            }
            if (array_key_exists('simulated_claimed_quantity', $input) || array_key_exists('claimed_quantity', $input)) {
                throw new AppError('VALIDATION_ERROR', 'Omit claimed quantities: release always checks actual Logistics Draft-trip claims.');
            }
            $service = new ReservationService($connection);
            $result = $transactions->run(static function (TransactionContext $tx) use ($actor, $service, $reservationId, $orderId, $lineId): array {
                $source = ['company_id' => $actor->companyId, 'order_id' => $orderId];
                $orders = $tx->query('SELECT DOCUMENT_TYPE, STATUS FROM ME_OE_DOCUMENTS
                    WHERE CNY# = :company_id AND RECORD# = :order_id FOR UPDATE', $source);
                if ($orders === []) {
                    throw new AppError('NOT_FOUND', 'Sales order not found for this company.', 404);
                }
                if ($orders[0]['DOCUMENT_TYPE'] !== 'SALES_ORDER' || $orders[0]['STATUS'] !== 'DRAFT') {
                    throw new AppError('INVALID_STATE', 'The lab release caller requires a Draft SALES_ORDER.', 409);
                }
                $lines = $tx->query('SELECT RECORD# FROM ME_OE_DOCUMENT_LINES
                    WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :order_id AND RECORD# = :line_id FOR UPDATE',
                    $source + ['line_id' => $lineId]);
                if ($lines === []) {
                    throw new AppError('NOT_FOUND', 'Sales line not found for this order and company.', 404);
                }
                $rows = $tx->query('SELECT PRODUCT_ID, RESERVED_QUANTITY FROM ME_INV_RESERVATION
                    WHERE COMPANY_ID = :company_id AND RESERVATION_ID = :reservation_id
                    AND SALES_ORDER_ID = :order_id AND SALES_LINE_ID = :line_id',
                    $source + ['line_id' => $lineId, 'reservation_id' => $reservationId]);
                if ($rows === []) {
                    throw new AppError('NOT_FOUND', 'Reservation not found for this company/order/line.', 404);
                }
                return $service->releaseReservation($actor, $tx, $reservationId, $orderId, $lineId,
                    (int) $rows[0]['PRODUCT_ID'], (int) $rows[0]['RESERVED_QUANTITY']);
            });
            return $result + ['committed' => true, 'test_only' => true, 'claim_lookup_mode' => 'logistics', 'sales_order_status' => 'DRAFT'];
        });

    // Standalone lab only: commits real Inventory writes for a saved Draft Sales line.
    $api->register('POST', '/inventory/testing/reservations', 'inventory.reservations', 'reserve',
        static function (Actor $actor, array $input) use ($reservations, $transactions): array {
            $setting = static function (string $key): mixed {
                return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            };
            if ($setting('APP_MODE') !== 'standalone' || $setting('INVENTORY_RESERVATION_TEST_API') !== '1') {
                throw new AppError('NOT_FOUND', 'API operation not found.', 404);
            }
            $orderId = Api::integer($input, 'sales_order_id');
            $lineId = Api::integer($input, 'sales_line_id');
            if ($orderId > 999999999999999 || $lineId > 999999999999999) {
                throw new AppError('VALIDATION_ERROR', 'Sales IDs must have at most 15 digits.');
            }
            $scenario = $input['scenario'] ?? 'reserve';
            if (!in_array($scenario, ['reserve', 'all'], true)) {
                throw new AppError('VALIDATION_ERROR', 'scenario must be reserve or all.');
            }
            $requestedQuantity = array_key_exists('quantity', $input) ? Api::integer($input, 'quantity') : null;
            if ($requestedQuantity !== null && $requestedQuantity > 9999999999) {
                throw new AppError('VALIDATION_ERROR', 'quantity must be a whole number from 1 to 9999999999.');
            }
            $result = $transactions->run(static function (TransactionContext $tx) use ($actor, $reservations, $orderId, $lineId, $scenario, $requestedQuantity): array {
                $source = ['company_id' => $actor->companyId, 'order_id' => $orderId];
                $orders = $tx->query(
                    'SELECT DOCUMENT_TYPE, STATUS FROM ME_OE_DOCUMENTS
                     WHERE CNY# = :company_id AND RECORD# = :order_id FOR UPDATE', $source,
                );
                if ($orders === []) {
                    throw new AppError('NOT_FOUND', 'Sales order not found for this company.', 404);
                }
                if ($orders[0]['DOCUMENT_TYPE'] !== 'SALES_ORDER' || $orders[0]['STATUS'] !== 'DRAFT') {
                    throw new AppError('INVALID_STATE', 'The test endpoint requires a Draft SALES_ORDER.', 409);
                }
                $lines = $tx->query(
                    'SELECT PRODUCT_ID, QUANTITY FROM ME_OE_DOCUMENT_LINES
                     WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :order_id AND RECORD# = :line_id FOR UPDATE',
                    $source + ['line_id' => $lineId],
                );
                if ($lines === []) {
                    throw new AppError('NOT_FOUND', 'Saved Sales line not found for this order and company.', 404);
                }
                $productId = filter_var($lines[0]['PRODUCT_ID'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9999999999]]);
                $quantity = filter_var($lines[0]['QUANTITY'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9999999999]]);
                if ($productId === false || $quantity === false) {
                    throw new AppError('VALIDATION_ERROR', 'The saved line needs a product and a positive whole quantity.');
                }
                // Test override only: do not modify the saved Sales line.
                $quantity = $requestedQuantity ?? $quantity;
                return $scenario === 'all'
                    ? (new ReserveStockScenarios($reservations))->run($actor, $tx, $orderId, $lineId, $productId, $quantity)
                    : $reservations->reserveStock($actor, $tx, $orderId, $lineId, $productId, $quantity);
            });
            return $result + ['committed' => true, 'test_only' => true, 'sales_order_status' => 'DRAFT'];
        });

    $salesId = static function (array $params, string $key): int {
        $value = $params[$key] ?? null;
        if (!is_string($value) || !ctype_digit($value) || strlen($value) > 15 || (int) $value < 1) {
            throw new AppError('VALIDATION_ERROR', 'Sales order and line IDs must be positive integers of at most 15 digits.');
        }
        return (int) $value;
    };

    $api->register(
        'GET',
        '/inventory/reservations/{sales_order_id}/{sales_line_id}',
        'inventory.reservations',
        'read',
        $camelEdge(static function (Actor $actor, array $input, array $params, array $query = []) use ($connection, $transactions, $salesId): array {
            $orderId = $salesId($params, 'sales_order_id');
            $lineId = $salesId($params, 'sales_line_id');
            // Advisory read; release acquires the source lock separately before checking real claims.
            $detailService = new ReservationService($connection, \Closure::fromCallable([(new LogisticsClaimLookup()), 'getPlannedQuantity']));
            $simulated = array_key_exists('simulated_claimed_quantity', $query);
            if ($simulated) {
                $mode = $_ENV['APP_MODE'] ?? $_SERVER['APP_MODE'] ?? getenv('APP_MODE');
                $enabled = $_ENV['INVENTORY_CLAIM_SIMULATION'] ?? $_SERVER['INVENTORY_CLAIM_SIMULATION'] ?? getenv('INVENTORY_CLAIM_SIMULATION');
                if ($mode !== 'standalone' || $enabled !== '1') {
                    throw new AppError('VALIDATION_ERROR', 'Dummy claims require APP_MODE=standalone and INVENTORY_CLAIM_SIMULATION=1.');
                }
                $raw = $query['simulated_claimed_quantity'];
                $claimed = is_string($raw) && preg_match('/\A[0-9]+\z/', $raw)
                    ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 9999999999]])
                    : false;
                if ($claimed === false) {
                    throw new AppError('VALIDATION_ERROR', 'simulated_claimed_quantity must be a whole number from 0 to 9999999999.');
                }
                $dummy = new DummyLogisticsService($actor->companyId, $orderId, $lineId, $claimed);
                $detailService = new ReservationService($connection, \Closure::fromCallable([$dummy, 'getPlannedQuantity']));
            }
            $result = $transactions->run(
                static fn (TransactionContext $tx): array => $detailService->getReservationDetail($actor, $tx, $orderId, $lineId),
            );
            if ($simulated) {
                $result['claim_lookup_mode'] = 'simulated';
            }
            return $result;
        }),
    );

    $api->register(
        'GET',
        '/inventory/get-availability',
        'inventory.stock_balance',
        'read',
        $camelEdge(static function (
            Actor $actor,
            array $input,
            array $params,
            array $query
        ) use ($availability, $transactions): array {
            // Request format: ?productIds=41,42 (product_ids is still accepted)
            $rawIds = $query['product_ids'] ?? null;

            if (!is_string($rawIds) || trim($rawIds) === '') {
                throw new AppError(
                    'VALIDATION_ERROR',
                    'Provide productIds, for example: productIds=41,42.'
                );
            }

            $productIds = [];

            $values = explode(',', $rawIds);
            if (count($values) > 1000) {
                throw new AppError('VALIDATION_ERROR', 'Provide between 1 and 1000 product IDs.');
            }

            foreach ($values as $value) {
                $id = filter_var(
                    trim($value),
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                if ($id === false) {
                    throw new AppError(
                        'VALIDATION_ERROR',
                        'Each product ID must be a positive integer.'
                    );
                }

                $productIds[] = $id;
            }

            return $transactions->run(
                static fn (TransactionContext $tx): array =>
                    $availability->getAvailability($actor, $tx, $productIds)
            );
        })
    );
};
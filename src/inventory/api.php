<?php
declare(strict_types=1);

use MiniErp\inventory\ProductService;
use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;

return static function (Api $api, Connection $connection): void {
    $products = new ProductService($connection);
    $productId = static function (array $params): int {
        if (!isset($params['id']) || !ctype_digit($params['id']) || strlen($params['id']) > 10 || (int) $params['id'] < 1) {
            throw new AppError('VALIDATION_ERROR', 'Product id must be a positive whole number.');
        }
        return (int) $params['id'];
    };

    $api->register('GET', '/inventory/products', 'inventory.products', 'read',
        static fn (Actor $actor, array $input, array $params, array $query): array => $products->list($actor, $query));

    $api->register('GET', '/inventory/products/{id}', 'inventory.products', 'read',
        static fn (Actor $actor, array $input, array $params): array => $products->get($actor, $productId($params)));

    $api->register('POST', '/inventory/products', 'inventory.products', 'create',
        static fn (Actor $actor, array $input): array => $products->create($actor, Api::string($input, 'action_reference'), $input),
        201);

    $api->register('PATCH', '/inventory/products/{id}', 'inventory.products', 'update',
        static fn (Actor $actor, array $input, array $params): array => $products->update(
            $actor, Api::string($input, 'action_reference'), $productId($params), $input,
        ));
};
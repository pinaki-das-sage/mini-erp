<?php
declare(strict_types=1);

use MiniErp\sales\CustomerService;
use MiniErp\sales\SalesPermissions;
use MiniErp\shared\Api;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;

return static function (Api $api, Connection $connection): void {
    $customers = new CustomerService($connection);
    $api->register('POST', '/sales/customers', SalesPermissions::CUSTOMERS, 'create',
        static fn (Actor $actor, array $input): array => $customers->create($actor, $input), 201);
    $api->register('GET', '/sales/customers', SalesPermissions::CUSTOMERS, 'read',
        static fn (Actor $actor, array $input, array $params, array $query): array => $customers->list($actor, $query));
    $api->register('GET', '/sales/customers/{id}', SalesPermissions::CUSTOMERS, 'read',
        static fn (Actor $actor, array $input, array $params): array => $customers->get($actor, $params['id']));
    $api->register('PATCH', '/sales/customers/{id}', SalesPermissions::CUSTOMERS, 'update',
        static fn (Actor $actor, array $input, array $params): array => $customers->update($actor, $params['id'], $input));
    $api->register('POST', '/sales/customers/{id}/update', SalesPermissions::CUSTOMERS, 'update',
        static fn (Actor $actor, array $input, array $params): array => $customers->update($actor, $params['id'], $input));
};

<?php
declare(strict_types=1);

use MiniErp\sales\customers\CustomerService;
use MiniErp\inventory\QuoteInventoryValidator;
use MiniErp\sales\quotes\QuoteService;
use MiniErp\sales\SalesPermissions;
use MiniErp\shared\Api;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;

return static function (Api $api, Connection $connection): void {
    $customers = new CustomerService($connection);
    $quotes = new QuoteService($connection, new QuoteInventoryValidator());
    $api->register('POST', '/sales/quotes', SalesPermissions::DOCUMENTS['quote'], 'create',
        static fn (Actor $actor, array $input): array => $quotes->create($actor, $input), 201);
    $api->register('GET', '/sales/quotes', SalesPermissions::DOCUMENTS['quote'], 'read',
        static fn (Actor $actor, array $input, array $params, array $query): array => $quotes->list($actor, $query));
    $api->register('GET', '/sales/quotes/{quote_number}', SalesPermissions::DOCUMENTS['quote'], 'read',
        static fn (Actor $actor, array $input, array $params): array => $quotes->get($actor, $params['quote_number']));
    $api->register('POST', '/sales/quotes/{quote_number}/update', SalesPermissions::DOCUMENTS['quote'], 'update',
        static fn (Actor $actor, array $input, array $params): array => $quotes->update($actor, $params['quote_number'], $input));
    foreach (['submit' => 'submit', 'move-to-draft' => 'return_to_draft', 'delete' => 'delete'] as $path => $action) {
        $api->register('POST', '/sales/quotes/{quote_number}/' . $path, SalesPermissions::DOCUMENTS['quote'], $action === 'delete' ? 'delete' : 'update',
            static fn (Actor $actor, array $input, array $params): array => $quotes->action($actor, $params['quote_number'], $action, $input));
    }
    $api->register('POST', '/sales/customers', SalesPermissions::CUSTOMERS, 'create',
        static fn (Actor $actor, array $input): array => $customers->create($actor, $input), 201);
    $api->register('GET', '/sales/customers', SalesPermissions::CUSTOMERS, 'read',
        static fn (Actor $actor, array $input, array $params, array $query): array => $customers->list($actor, $query));
    $api->register('GET', '/sales/customers/{id}', SalesPermissions::CUSTOMERS, 'read',
        static fn (Actor $actor, array $input, array $params): array => $customers->get($actor, $params['id']));
    $api->register('POST', '/sales/customers/{id}/update', SalesPermissions::CUSTOMERS, 'update',
        static fn (Actor $actor, array $input, array $params): array => $customers->update($actor, $params['id'], $input));
};

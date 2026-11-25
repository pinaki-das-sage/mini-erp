<?php
declare(strict_types=1);

use MiniErp\sales\CustomerService;
use MiniErp\sales\SalesPermissions;
use MiniErp\shared\Api;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\sales\invoices\InvoiceService;
use MiniErp\sales\payments\PaymentService;

return static function (Api $api, Connection $connection): void {
    $customers = new CustomerService($connection);
    $invoices = new InvoiceService($connection);
    $payments = new PaymentService($connection);
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

    $api->register('GET', '/sales/invoices', SalesPermissions::DOCUMENTS['invoice'], 'read',
        static fn (Actor $actor, array $input, array $params, array $query): array => $invoices->list($actor, $query));
    $api->register('POST', '/sales/invoices', SalesPermissions::DOCUMENTS['invoice'], 'create',
        static fn (Actor $actor, array $input): array => $invoices->create($actor, $input), 201);
    $api->register('GET', '/sales/invoices/{invoice_number}', SalesPermissions::DOCUMENTS['invoice'], 'read',
        static fn (Actor $actor, array $input, array $params): array => $invoices->get($actor, $params['invoice_number']));
    $api->register('POST', '/sales/invoices/{invoice_number}/update', SalesPermissions::DOCUMENTS['invoice'], 'update',
        static fn (Actor $actor, array $input, array $params): array => $invoices->update($actor, $params['invoice_number'], $input));
    foreach (['submit' => 'submit', 'move-to-draft' => 'return_to_draft', 'delete' => 'delete'] as $path => $action) {
        $api->register('POST', '/sales/invoices/{invoice_number}/' . $path, SalesPermissions::DOCUMENTS['invoice'], $action,
            static fn (Actor $actor, array $input, array $params): array => $invoices->action($actor, $params['invoice_number'], $action, $input));
    }
    $api->register('POST', '/sales/invoices/{invoice_number}/payments', SalesPermissions::PAYMENTS, 'record',
        static fn (Actor $actor, array $input, array $params): array => $payments->record($actor, $params['invoice_number'], $input), 201);
    $api->register('GET', '/sales/invoices/{invoice_number}/payments', SalesPermissions::PAYMENTS, 'read',
        static fn (Actor $actor, array $input, array $params, array $query): array => $payments->list($actor, $params['invoice_number'], $query));
};

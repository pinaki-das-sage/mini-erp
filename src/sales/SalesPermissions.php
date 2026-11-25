<?php
declare(strict_types=1);

namespace MiniErp\sales;

use MiniErp\shared\AppError;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

final readonly class SalesPermissions
{
    public function __construct(private Authorization $authorization) {}

    // Shared permission objects used by both routes and services.
    public const CUSTOMERS = 'sales.customers';
    public const PAYMENTS = 'sales.payments';
    public const DOCUMENTS = [
        'quote' => 'sales.quotes',
        'order' => 'sales.orders',
        'shipper' => 'sales.shippers',
        'invoice' => 'sales.invoices',
    ];

    public function requireCustomer(Actor $actor, string $action): void
    {
        if (!in_array($action, ['create', 'read', 'edit', 'deactivate', 'reactivate'], true)) {
            throw new AppError('FORBIDDEN', 'This Customer action is not supported.', 403);
        }
        $this->authorization->requirePermission($actor, self::CUSTOMERS,
            in_array($action, ['edit', 'deactivate', 'reactivate'], true) ? 'update' : $action);
    }

    public function requirePayment(Actor $actor, string $action): void
    {
        if (!in_array($action, ['create', 'read'], true)) {
            throw new AppError('FORBIDDEN', 'Payments support recording and reading only.', 403);
        }
        $this->authorization->requirePermission($actor, self::PAYMENTS, $action === 'create' ? 'record' : 'read');
    }

    public function requireDocument(Actor $actor, string $type, string $action): void
    {
        if (!isset(self::DOCUMENTS[$type])) {
            throw new AppError('VALIDATION_ERROR', 'Select a valid document type.');
        }
        if ($action === 'record_payment') {
            if ($type !== 'invoice') {
                throw new AppError('INVALID_STATE', 'Payments can only be recorded against Invoices.', 409);
            }
            $this->requirePayment($actor, 'create');
            return;
        }
        if (!in_array($action, ['create', 'read', 'delete', 'edit', 'submit', 'return_to_draft'], true)) {
            throw new AppError('FORBIDDEN', 'This document action is not supported.', 403);
        }
        $operation = $action === 'edit' || ($type === 'quote' && in_array($action, ['submit', 'return_to_draft'], true)) ? 'update' : $action;
        $this->authorization->requirePermission($actor, self::DOCUMENTS[$type], $operation);
    }
}

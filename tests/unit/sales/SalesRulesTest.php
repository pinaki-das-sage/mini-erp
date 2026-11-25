<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\sales\DocumentRules;
use MiniErp\sales\SalesPermissions;
use MiniErp\sales\Validation;
use MiniErp\shared\AppError;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;
use MiniErp\shared\database\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SalesRulesTest extends TestCase
{
    #[DataProvider('blockedActions')]
    public function testInvalidDocumentActionsAreRejected(string $type, string $action, ?string $status, bool $downstream, bool $payments): void
    {
        $this->expectException(AppError::class);
        DocumentRules::requireAction($type, $action, $status, $downstream, $payments);
    }

    public static function blockedActions(): array
    {
        return [
            ['order', 'submit', null, false, false],
            ['order', 'edit', 'Submitted', false, false],
            ['quote', 'return_to_draft', 'Submitted', true, false],
            ['order', 'delete', 'Draft', true, false],
            ['shipper', 'delete', 'Submitted', false, false],
            ['invoice', 'return_to_draft', 'Submitted', false, true],
            ['invoice', 'delete', 'Draft', false, true],
            ['invoice', 'record_payment', 'Draft', false, false],
            ['quote', 'record_payment', 'Submitted', false, false],
            ['order', 'create', 'Draft', false, false],
            ['order', 'submit', 'Unknown', false, false],
        ];
    }

    public function testLifecycleAllowsEachDocumentTypeAndInvoicePayments(): void
    {
        foreach (['quote', 'order', 'shipper', 'invoice'] as $type) {
            DocumentRules::requireAction($type, 'create', null);
            DocumentRules::requireAction($type, 'read', 'Draft');
            DocumentRules::requireAction($type, 'read', 'Submitted');
            DocumentRules::requireAction($type, 'edit', 'Draft');
            DocumentRules::requireAction($type, 'submit', 'Draft');
            DocumentRules::requireAction($type, 'return_to_draft', 'Submitted');
            DocumentRules::requireAction($type, 'delete', 'Draft');
        }
        DocumentRules::requireAction('invoice', 'record_payment', 'Submitted', false, true);
        $this->addToAssertionCount(29);
    }

    public function testCrossCompanyAccessIsHiddenEvenForAdmin(): void
    {
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('Record not found.');
        DocumentRules::requireCompany(new Actor('a', 'admin', [], 1, true), 2);
    }

    public function testStaleVersionIsRejected(): void
    {
        $this->expectException(AppError::class);
        DocumentRules::requireVersion(2, 1);
    }

    public function testSourceLinkedFieldsStayProtectedAfterReturnToDraft(): void
    {
        $this->expectException(AppError::class);
        DocumentRules::requireEditableFields(true, ['notes', 'lines'], ['customer_id', 'warehouse_id', 'lines']);
    }

    public function testStandaloneAndNonInheritedFieldsCanBeEdited(): void
    {
        DocumentRules::requireEditableFields(false, ['lines'], ['lines']);
        DocumentRules::requireEditableFields(true, ['notes'], ['lines']);
        $this->addToAssertionCount(2);
    }

    #[DataProvider('invalidDecimals')]
    public function testInvalidDecimalsAreRejectedWithoutCoercion(mixed $value): void
    {
        $this->expectException(AppError::class);
        Validation::decimal($value, 'Quantity', 9, 3, false);
    }

    public static function invalidDecimals(): array
    {
        return [[1.25], ['1e3'], ['-1'], ['0.000'], ['1.2345'], ['1000000000'], [' 1.2 '], ['1,000'], [null]];
    }

    public function testDecimalsRetainExactValuesAndAcceptCapacityBoundary(): void
    {
        self::assertSame('999999999.999', Validation::decimal('999999999.999', 'Quantity', 9, 3, false));
        self::assertSame('0.00', Validation::decimal('0.00', 'Unit Price', 10, 2, true));
        self::assertSame('9999999999999999.99', Validation::decimal('9999999999999999.99', 'Amount', 16, 2, false));
    }

    #[DataProvider('invalidDates')]
    public function testInvalidDatesAreRejected(string $date): void
    {
        $this->expectException(AppError::class);
        Validation::date($date, 'Document Date');
    }

    public static function invalidDates(): array
    {
        return [['2026-02-29'], ['2026-2-01'], ['2026-04-31'], ['0000-01-01'], ['2026-11-18T00:00:00']];
    }

    public function testTextIsTrimmedBeforeCheckingLengthAndRequiredValues(): void
    {
        self::assertSame('abc', Validation::text('  abc  ', 'Notes', 3));
        self::assertSame('', Validation::text(null, 'Notes', 500, false));
        $this->expectException(AppError::class);
        Validation::text('   ', 'Customer Name', 100);
    }

    public function testDateRangesUseCallerProvidedBusinessDate(): void
    {
        self::assertSame('2024-02-29', Validation::date('2024-02-29', 'Date'));
        Validation::dateRange('2026-11-18', '2026-11-18', 'Payment Date', 'Invoice Date');
        $this->expectException(AppError::class);
        Validation::notFuture('2026-11-19', '2026-11-18', 'Payment Date');
    }

    #[DataProvider('salesActions')]
    public function testRolesWithoutGrantsCannotAccessAnySalesAction(string $resource, string $action): void
    {
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('You do not have permission for this operation.');
        $this->authorizeSalesAction(new Actor('u', 'user', ['admin', 'trainee'], 1), $resource, $action);
    }

    #[DataProvider('salesActions')]
    public function testAdminCanAccessSupportedSalesActionsWithoutGrants(string $resource, string $action): void
    {
        $this->authorizeSalesAction(new Actor('a', 'admin', [], 1, true), $resource, $action);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('salesActions')]
    public function testAdminMustChangeTemporaryPasswordBeforeSalesAccess(string $resource, string $action): void
    {
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('Change your temporary password first.');
        $this->authorizeSalesAction(new Actor('a', 'admin', [], 1, true, 1, true), $resource, $action);
    }

    private function authorizeSalesAction(Actor $actor, string $resource, string $action): void
    {
        $permissions = $this->permissions();
        if ($resource === 'customer') {
            $permissions->requireCustomer($actor, $action);
        } elseif ($resource === 'payment') {
            $permissions->requirePayment($actor, $action);
        } else {
            $permissions->requireDocument($actor, $resource, $action);
        }
    }

    private function permissions(): SalesPermissions
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static function (string $sql, array $parameters): array {
            if (str_contains($sql, 'FROM ME_OBJECTS')) {
                return [['ACTIVE' => 1, 'ALLOW_CREATE' => 1, 'ALLOW_READ' => 1, 'ALLOW_UPDATE' => 1, 'ALLOW_DELETE' => 1]];
            }
            return str_contains($sql, 'FROM ME_OBJECT_ACTIONS') ? [['ACTION_CODE' => $parameters['operation']]] : [];
        });
        return new SalesPermissions(new Authorization($db));
    }

    #[DataProvider('salesActions')]
    public function testMatchingGrantAllowsNonAdminWithNoRoles(string $resource, string $action): void
    {
        $object = match ($resource) {
            'customer' => 'sales.customers', 'payment' => 'sales.payments',
            default => SalesPermissions::DOCUMENTS[$resource],
        };
        $operation = match ($action) {
            'edit', 'deactivate', 'reactivate' => 'update', 'record_payment' => 'record',
            default => $resource === 'payment' && $action === 'create' ? 'record' : $action,
        };
        if ($resource === 'quote' && in_array($action, ['submit', 'return_to_draft'], true)) {
            $operation = 'update';
        }
        if ($action === 'record_payment') {
            $object = 'sales.payments';
        }
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static function (string $sql, array $parameters) use ($object, $operation): array {
            self::assertSame($object, $parameters['object']);
            if (str_contains($sql, 'FROM ME_OBJECTS')) {
                return [['ACTIVE' => 1, 'ALLOW_CREATE' => 1, 'ALLOW_READ' => 1, 'ALLOW_UPDATE' => 1, 'ALLOW_DELETE' => 1]];
            }
            self::assertSame($operation, $parameters['operation']);
            if (str_contains($sql, 'FROM ME_OBJECT_ACTIONS')) {
                return [['ACTION_CODE' => $operation]];
            }
            self::assertSame('u', $parameters['user_id']);
            self::assertSame(7, $parameters['company_id']);
            return [['USER_ID' => 'u']];
        });
        $permissions = new SalesPermissions(new Authorization($db));
        $actor = new Actor('u', 'user', [], 7);
        if ($resource === 'customer') {
            $permissions->requireCustomer($actor, $action);
        } elseif ($resource === 'payment') {
            $permissions->requirePayment($actor, $action);
        } else {
            $permissions->requireDocument($actor, $resource, $action);
        }
    }

    public function testLifecycleMigrationRegistersActionsWithoutUserGrants(): void
    {
        $statements = require dirname(__DIR__, 3) . '/database/migrations/sales/003_oe_document_actions.php';
        self::assertCount(6, $statements);
        foreach ($statements as $sql) {
            self::assertStringStartsWith('INSERT INTO ME_OBJECT_ACTIONS', $sql);
            self::assertStringNotContainsString('ME_USER_PERMISSIONS', $sql);
            self::assertStringNotContainsString('sales.shippers', $sql);
        }
    }

    public static function salesActions(): array
    {
        $cases = [];
        foreach (['create', 'read', 'edit', 'deactivate', 'reactivate'] as $action) {
            $cases[] = ['customer', $action];
        }
        foreach (['create', 'read'] as $action) {
            $cases[] = ['payment', $action];
        }
        foreach (['quote', 'order', 'shipper', 'invoice'] as $type) {
            foreach (['create', 'read', 'edit', 'submit', 'return_to_draft', 'delete'] as $action) {
                $cases[] = [$type, $action];
            }
        }
        $cases[] = ['invoice', 'record_payment'];
        return $cases;
    }

    public function testAdminCannotRecordPaymentAgainstAnotherDocumentType(): void
    {
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('Payments can only be recorded against Invoices.');
        ($this->permissions())->requireDocument(new Actor('a', 'admin', [], 1, true), 'quote', 'record_payment');
    }

    public function testAdminCannotUseUnknownDocumentType(): void
    {
        $this->expectException(AppError::class);
        ($this->permissions())->requireDocument(new Actor('a', 'admin', [], 1, true), 'unknown', 'read');
    }

    public function testAdminCannotUseUnknownDocumentAction(): void
    {
        $this->expectException(AppError::class);
        ($this->permissions())->requireDocument(new Actor('a', 'admin', [], 1, true), 'order', 'approve');
    }

    public function testPermissionMigrationIsIndependentAndDoesNotGrantUsersAccess(): void
    {
        $statements = require dirname(__DIR__, 3) . '/database/migrations/sales/001_oe_permissions.php';
        self::assertCount(7, $statements);
        foreach ($statements as $sql) {
            self::assertStringStartsWith('INSERT INTO ME_OBJECT', $sql);
            self::assertStringNotContainsString('ME_USER_PERMISSIONS', $sql);
        }
    }

    #[DataProvider('unsupportedMasterActions')]
    public function testCustomersAndPaymentsRejectUnsupportedActions(string $resource, string $action): void
    {
        $permissions = $this->permissions();
        $actor = new Actor('a', 'admin', [], 1, true);
        $this->expectException(AppError::class);
        if ($resource === 'customer') {
            $permissions->requireCustomer($actor, $action);
        } else {
            $permissions->requirePayment($actor, $action);
        }
    }

    public static function unsupportedMasterActions(): array
    {
        return [['customer', 'delete'], ['payment', 'edit'], ['payment', 'delete']];
    }

    #[DataProvider('readOnlyFields')]
    public function testSystemAndCalculatedFieldsAreRejectedByWriteAllowlist(string $field): void
    {
        $this->expectException(AppError::class);
        Validation::allowedFields([$field => 'client-value'], ['notes', 'document_date']);
    }

    public static function readOnlyFields(): array
    {
        return [['company_id'], ['actor_id'], ['status'], ['document_number'], ['total'], ['payment_status'], ['amount_due']];
    }

    public function testOverlengthMultibyteTextIsRejected(): void
    {
        self::assertSame('éé', Validation::text(' éé ', 'Description', 2));
        $this->expectException(AppError::class);
        Validation::text('ééé', 'Description', 2);
    }

    public function testDateBeforePredecessorIsRejected(): void
    {
        $this->expectException(AppError::class);
        Validation::dateRange('2026-11-17', '2026-11-18', 'Order Date', 'Quote Date');
    }

    public function testMatchingCompanyVersionAndAllowedWriteFieldsPass(): void
    {
        DocumentRules::requireCompany(new Actor('u', 'user', [], 2), 2);
        DocumentRules::requireVersion(3, 3);
        Validation::allowedFields(['notes' => 'Updated'], ['notes']);
        $this->addToAssertionCount(3);
    }

}

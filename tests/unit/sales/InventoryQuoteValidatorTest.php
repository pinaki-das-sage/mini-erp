<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\inventory\QuoteInventoryValidator;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InventoryQuoteValidatorTest extends TestCase
{
    private function readOnlyDb(): Connection
    {
        $db = $this->createMock(Connection::class);
        foreach (['execute', 'executeSchema', 'begin', 'commit', 'rollback'] as $method) {
            $db->expects(self::never())->method($method);
        }
        return $db;
    }

    public function testValidatorUsesTrustedCompanyAndDeduplicatesProducts(): void
    {
        $db = $this->readOnlyDb();
        $db->expects(self::once())->method('query')->willReturnCallback(static function (string $sql, array $binds): array {
            self::assertStringContainsString('FROM ME_INV_PRODUCTS', $sql);
            self::assertStringContainsString('COMPANY_ID = :company_id', $sql);
            self::assertSame(['company_id' => 7, 'p0' => 163, 'p1' => 165], $binds);
            return [
                ['PRODUCT_ID' => '163', 'WAREHOUSE_ID' => 'W01', 'IS_ACTIVE' => 'Y'],
                ['PRODUCT_ID' => '165', 'WAREHOUSE_ID' => 'W01', 'IS_ACTIVE' => 'Y'],
            ];
        });
        (new QuoteInventoryValidator())
            ->validate(new TransactionContext($db), new Actor('a', 'admin', [], 7, true), 'W01', ['163', '165', '163']);
    }

    #[DataProvider('invalidReferences')]
    public function testInvalidInputsDoNotQueryInventory(string $warehouse, array $ids): void
    {
        $db = $this->readOnlyDb();
        $db->expects(self::never())->method('query');
        $this->expectException(AppError::class);
        (new QuoteInventoryValidator())->validate(new TransactionContext($db), new Actor('a', 'admin', [], 7, true), $warehouse, $ids);
    }

    public static function invalidReferences(): array
    {
        return [['W02', ['163']], ['W01', []], ['W01', [163]], ['W01', ['0']], ['W01', ['ITEM-001']], ['W01', ['10000000000']]];
    }

    #[DataProvider('referenceFailures')]
    public function testLookupFailuresPreserveProviderErrorCodes(array $rows, string $code, int $status): void
    {
        $db = $this->readOnlyDb();
        $db->method('query')->willReturn($rows);
        try {
            (new QuoteInventoryValidator())->validate(new TransactionContext($db), new Actor('a', 'admin', [], 7, true), 'W01', ['163']);
            self::fail('Expected Inventory rejection');
        } catch (AppError $error) {
            self::assertSame($code, $error->errorCode);
            self::assertSame($status, $error->status);
        }
    }

    public static function referenceFailures(): array
    {
        return [
            [[], 'VALIDATION_ERROR', 400],
            [[['PRODUCT_ID' => '163', 'WAREHOUSE_ID' => 'W02', 'IS_ACTIVE' => 'Y']], 'VALIDATION_ERROR', 400],
            [[['PRODUCT_ID' => '163', 'WAREHOUSE_ID' => 'W01', 'IS_ACTIVE' => 'N']], 'INVALID_STATE', 409],
        ];
    }

    public function testDatabaseFailureReturnsUnavailable(): void
    {
        $db = $this->readOnlyDb();
        $db->expects(self::once())->method('query')->willThrowException(new DatabaseError(942));
        try {
            (new QuoteInventoryValidator())
                ->validate(new TransactionContext($db), new Actor('a', 'admin', [], 1, true), 'W01', ['163']);
            self::fail('Expected Inventory unavailability');
        } catch (AppError $error) {
            self::assertSame('INVENTORY_UNAVAILABLE', $error->errorCode);
            self::assertSame(503, $error->status);
        }
    }

    public function testLargeProductListIsQueriedInBoundChunks(): void
    {
        $db = $this->readOnlyDb();
        $calls = 0;
        $db->expects(self::exactly(2))->method('query')->willReturnCallback(static function (string $sql, array $binds) use (&$calls): array {
            self::assertSame(7, $binds['company_id']);
            unset($binds['company_id']);
            self::assertCount($calls++ === 0 ? 500 : 1, $binds);
            return array_values(array_map(static fn (int $id): array => ['PRODUCT_ID' => (string) $id, 'WAREHOUSE_ID' => 'W01', 'IS_ACTIVE' => 'Y'], $binds));
        });
        (new QuoteInventoryValidator())->validate(new TransactionContext($db), new Actor('a', 'admin', [], 7, true), 'W01', array_map('strval', range(1, 501)));
    }
}

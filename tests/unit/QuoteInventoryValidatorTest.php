<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\inventory\QuoteInventoryValidator;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\TestCase;

final class QuoteInventoryValidatorTest extends TestCase
{
    /** @var list<array{string, array<string, mixed>}> */
    private array $queries = [];

    /**
     * @param array<int, array{WAREHOUSE_ID: string, IS_ACTIVE: string}> $products products that exist for the company, by id
     */
    private function tx(array $products, bool $down = false): TransactionContext
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('begin');
        $db->expects(self::never())->method('commit');
        $db->expects(self::never())->method('rollback');
        $db->expects(self::never())->method('execute');
        $db->method('query')->willReturnCallback(function (string $sql, array $parameters = []) use ($products, $down): array {
            $this->queries[] = [$sql, $parameters];
            if ($down) {
                throw new \RuntimeException('ORA-12541: no listener');
            }
            $rows = [];
            foreach ($parameters as $name => $value) {
                if ($name !== 'company_id' && isset($products[$value])) {
                    $rows[] = ['PRODUCT_ID' => $value] + $products[$value];
                }
            }
            return $rows;
        });
        return new TransactionContext($db);
    }

    private static function actor(): Actor
    {
        return new Actor('sales', 'user', [], 7, false);
    }

    private static function active(): array
    {
        return ['WAREHOUSE_ID' => 'W01', 'IS_ACTIVE' => 'Y'];
    }

    /** @return array{string, int} code and status */
    private function failure(TransactionContext $tx, string $warehouse, array $ids): array
    {
        try {
            (new QuoteInventoryValidator())->validate($tx, self::actor(), $warehouse, $ids);
        } catch (AppError $error) {
            return [$error->errorCode, $error->status];
        }
        self::fail('Expected AppError.');
    }

    public function testValidProductsPassAndOnlyTheCompanysProductsAreLookedUp(): void
    {
        (new QuoteInventoryValidator())->validate($this->tx([163 => self::active(), 165 => self::active()]), self::actor(), 'W01', ['163', '165', '163']);
        self::assertCount(1, $this->queries);
        self::assertSame(7, $this->queries[0][1]['company_id']);
        self::assertStringNotContainsString('ME_INV_STOCK', $this->queries[0][0]);
    }

    public function testUnknownWarehouseIsAValidationError(): void
    {
        self::assertSame(['VALIDATION_ERROR', 400], $this->failure($this->tx([163 => self::active()]), 'W99', ['163']));
        self::assertSame(['VALIDATION_ERROR', 400], $this->failure($this->tx([163 => self::active()]), '', ['163']));
        self::assertSame([], $this->queries);
    }

    public function testMalformedProductIdsAreRejectedBeforeAnyQuery(): void
    {
        foreach (['abc', '0', '-1', '007', '1.5', ' 163', '12345678901', ''] as $bad) {
            self::assertSame(['VALIDATION_ERROR', 400], $this->failure($this->tx([]), 'W01', ['163', $bad]), $bad);
        }
        self::assertSame(['VALIDATION_ERROR', 400], $this->failure($this->tx([]), 'W01', [163]));
        self::assertSame([], $this->queries);
    }

    public function testAQuoteNeedsAtLeastOneProduct(): void
    {
        self::assertSame(['VALIDATION_ERROR', 400], $this->failure($this->tx([]), 'W01', []));
    }

    public function testUnknownProductIsAValidationErrorThatNamesIt(): void
    {
        try {
            (new QuoteInventoryValidator())->validate($this->tx([163 => self::active()]), self::actor(), 'W01', ['163', '999']);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame('VALIDATION_ERROR', $error->errorCode);
            self::assertStringContainsString('999', $error->getMessage());
            self::assertStringNotContainsString('163', $error->getMessage());
        }
    }

    public function testProductFromAnotherCompanyLooksUnknown(): void
    {
        // The query is filtered by the actor's company, so another company's product simply is not returned.
        self::assertSame(['VALIDATION_ERROR', 400], $this->failure($this->tx([]), 'W01', ['163']));
    }

    public function testProductInAnotherWarehouseIsAValidationError(): void
    {
        self::assertSame(['VALIDATION_ERROR', 400], $this->failure($this->tx([163 => ['WAREHOUSE_ID' => 'W02', 'IS_ACTIVE' => 'Y']]), 'W01', ['163']));
    }

    public function testInactiveProductIsInvalidState(): void
    {
        self::assertSame(['INVALID_STATE', 409], $this->failure($this->tx([163 => ['WAREHOUSE_ID' => 'W01', 'IS_ACTIVE' => 'N']]), 'W01', ['163']));
    }

    public function testDatabaseFailureIsReportedAsUnavailableNotAsInvalid(): void
    {
        self::assertSame(['INVENTORY_UNAVAILABLE', 503], $this->failure($this->tx([], true), 'W01', ['163']));
    }

    public function testManyProductsAreCheckedInChunks(): void
    {
        $products = [];
        $ids = [];
        for ($i = 1; $i <= 1200; $i++) {
            $products[$i] = self::active();
            $ids[] = (string) $i;
        }
        (new QuoteInventoryValidator())->validate($this->tx($products), self::actor(), 'W01', $ids);
        self::assertCount(3, $this->queries);
    }
}
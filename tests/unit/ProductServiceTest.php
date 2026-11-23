<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\inventory\ProductService;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductServiceTest extends TestCase
{
    private const VALID = ['name' => 'Steel Bolt', 'sku' => 'BOLT-01', 'unit_label' => 'box', 'unit_weight_grams' => 500];

    private static function admin(): Actor
    {
        return new Actor('a', 'admin', [], 1, true);
    }

    /** Connection that reports inventory.products as registered, so only validation can fail. */
    private function registered(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['ACTIVE' => 1, 'ALLOW_CREATE' => 1, 'ALLOW_READ' => 1, 'ALLOW_UPDATE' => 1, 'ALLOW_DELETE' => 0]]);
        return $db;
    }

    public function testCreateIsDeniedWhenObjectIsNotRegistered(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $db->expects(self::never())->method('begin');
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('not registered');
        (new ProductService($db))->create(self::admin(), 'ref-1', self::VALID);
    }

    public function testNonAdminWithoutGrantIsDenied(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['ACTIVE' => 1, 'ALLOW_CREATE' => 1]], [],
        );
        $db->expects(self::never())->method('begin');
        $this->expectException(AppError::class);
        (new ProductService($db))->create(new Actor('u', 'clerk', [], 1), 'ref-1', self::VALID);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidInput(): array
    {
        return [
            'empty name' => [['name' => '   '] + self::VALID],
            'name too long' => [['name' => str_repeat('x', 256)] + self::VALID],
            'empty sku' => [['sku' => ''] + self::VALID],
            'sku too long' => [['sku' => str_repeat('A', 51)] + self::VALID],
            'empty unit label' => [['unit_label' => ''] + self::VALID],
            'zero weight' => [['unit_weight_grams' => 0] + self::VALID],
            'negative weight' => [['unit_weight_grams' => -5] + self::VALID],
            'fractional weight' => [['unit_weight_grams' => 1.5] + self::VALID],
            'weight as string' => [['unit_weight_grams' => '500'] + self::VALID],
        ];
    }

    #[DataProvider('invalidInput')]
    public function testInvalidInputIsRejectedBeforeAnyDatabaseWrite(array $input): void
    {
        $db = $this->registered();
        $db->expects(self::never())->method('begin');
        $db->expects(self::never())->method('execute');
        try {
            (new ProductService($db))->create(self::admin(), 'ref-1', $input);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame('VALIDATION_ERROR', $error->errorCode);
            self::assertSame(400, $error->status);
        }
    }

    public function testMissingFieldIsRejected(): void
    {
        $db = $this->registered();
        $db->expects(self::never())->method('begin');
        $input = self::VALID;
        unset($input['sku']);
        $this->expectException(AppError::class);
        (new ProductService($db))->create(self::admin(), 'ref-1', $input);
    }

    public function testSkuIsNormalisedToTrimmedUpperCase(): void
    {
        self::assertSame('BOLT-01', ProductService::normaliseSku('  bolt-01 '));
    }

    private const ROW = ['PRODUCT_ID' => 7, 'PRODUCT_NAME' => 'Steel Bolt', 'SKU' => 'BOLT-01', 'WAREHOUSE_ID' => 'W01',
        'UNIT_LABEL' => 'box', 'UNIT_WEIGHT_GRAMS' => 500, 'IS_ACTIVE' => 'Y'];

    public function testListRequiresReadPermission(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $this->expectException(AppError::class);
        (new ProductService($db))->list(self::admin());
    }

    public function testListMapsRowsAndPagesWithCompanyScope(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(2))->method('query')->willReturnCallback(
            function (string $sql, array $params = []): array {
                if (str_contains($sql, 'ME_OBJECTS')) {
                    return [['ACTIVE' => 1, 'ALLOW_READ' => 1]];
                }
                self::assertSame(['company_id' => 1, 'offset' => 25, 'page_size' => 25], $params);
                return [self::ROW];
            },
        );
        $result = (new ProductService($db))->list(self::admin(), ['page' => '2']);
        self::assertSame(2, $result['page']);
        self::assertSame([['product_id' => 7, 'name' => 'Steel Bolt', 'sku' => 'BOLT-01', 'warehouse_id' => 'W01',
            'unit_label' => 'box', 'unit_weight_grams' => 500, 'active' => true]], $result['items']);
    }

    public function testListRejectsUnsupportedFilter(): void
    {
        $this->expectException(AppError::class);
        (new ProductService($this->registered()))->list(self::admin(), ['colour' => 'red']);
    }

    public function testGetReturnsOneProductScopedToCompany(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []): array {
                if (str_contains($sql, 'ME_OBJECTS')) {
                    return [['ACTIVE' => 1, 'ALLOW_READ' => 1]];
                }
                self::assertSame(['company_id' => 1, 'product_id' => 7], $params);
                return [['IS_ACTIVE' => 'N'] + self::ROW];
            },
        );
        $product = (new ProductService($db))->get(self::admin(), 7);
        self::assertSame(7, $product['product_id']);
        self::assertFalse($product['active']);
    }

    public function testGetUnknownProductIsNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'ME_OBJECTS') ? [['ACTIVE' => 1, 'ALLOW_READ' => 1]] : [],
        );
        try {
            (new ProductService($db))->get(self::admin(), 99);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame('NOT_FOUND', $error->errorCode);
            self::assertSame(404, $error->status);
        }
    }

    private const CHANGE = ['name' => 'Steel Bolt M8', 'unit_label' => 'pack', 'unit_weight_grams' => 450, 'active' => true];

    /** @return array{Connection, \ArrayObject<int, array{string, array<string, mixed>}>} connection that records every execute() */
    private function updatable(?array $product, int $reserved): array
    {
        $writes = new \ArrayObject();
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static function (string $sql) use ($product, $reserved): array {
            return match (true) {
                str_contains($sql, 'ME_OBJECTS') => [['ACTIVE' => 1, 'ALLOW_UPDATE' => 1]],
                str_contains($sql, 'ME_INV_STOCK_BALANCE') => [['RESERVED' => $reserved]],
                str_contains($sql, 'ME_INV_PRODUCTS') => $product === null ? [] : [['PRODUCT_ID' => 7]],
                default => [],
            };
        });
        $db->method('execute')->willReturnCallback(static function (string $sql, array $params = []) use ($writes): int {
            $writes[] = [$sql, $params];
            return 1;
        });
        return [$db, $writes];
    }

    public function testUpdateIsDeniedWhenObjectIsNotRegistered(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $db->expects(self::never())->method('begin');
        $this->expectException(AppError::class);
        (new ProductService($db))->update(self::admin(), 'ref-u1', 7, self::CHANGE);
    }

    public function testUpdateRefusesToChangeSku(): void
    {
        [$db] = $this->updatable(['x'], 0);
        $db->expects(self::never())->method('begin');
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('sku cannot be changed');
        (new ProductService($db))->update(self::admin(), 'ref-u1', 7, ['sku' => 'NEW-01'] + self::CHANGE);
    }

    public function testUpdateRejectsInvalidInputAndNonBooleanActive(): void
    {
        foreach ([['unit_weight_grams' => 0], ['name' => ''], ['active' => 'yes'], ['active' => null]] as $bad) {
            [$db] = $this->updatable(['x'], 0);
            $db->expects(self::never())->method('begin');
            try {
                (new ProductService($db))->update(self::admin(), 'ref-u1', 7, $bad + self::CHANGE);
                self::fail('Expected AppError.');
            } catch (AppError $error) {
                self::assertSame('VALIDATION_ERROR', $error->errorCode);
            }
        }
    }

    public function testUpdateUnknownProductIsNotFoundAndNothingIsChanged(): void
    {
        [$db, $writes] = $this->updatable(null, 0);
        try {
            (new ProductService($db))->update(self::admin(), 'ref-u1', 99, self::CHANGE);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame('NOT_FOUND', $error->errorCode);
        }
        self::assertSame([], array_filter((array) $writes, static fn (array $w): bool => str_contains($w[0], 'UPDATE ME_INV_PRODUCTS')));
    }

    public function testCannotDeactivateWhileStockIsReserved(): void
    {
        [$db, $writes] = $this->updatable(['x'], 3);
        try {
            (new ProductService($db))->update(self::admin(), 'ref-u1', 7, ['active' => false] + self::CHANGE);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame('INVALID_STATE', $error->errorCode);
            self::assertSame(409, $error->status);
        }
        self::assertSame([], array_filter((array) $writes, static fn (array $w): bool => str_contains($w[0], 'UPDATE ME_INV_PRODUCTS')));
    }

    public function testUpdateWritesCompanyScopedChange(): void
    {
        [$db, $writes] = $this->updatable(['x'], 0);
        $result = (new ProductService($db))->update(self::admin(), 'ref-u1', 7, ['active' => false] + self::CHANGE);
        self::assertSame(['product_id' => 7, 'name' => 'Steel Bolt M8', 'unit_label' => 'pack', 'unit_weight_grams' => 450, 'active' => false], $result);
        $update = array_values(array_filter((array) $writes, static fn (array $w): bool => str_contains($w[0], 'UPDATE ME_INV_PRODUCTS')));
        self::assertCount(1, $update);
        self::assertSame(['company_id' => 1, 'product_id' => 7, 'name' => 'Steel Bolt M8', 'unit_label' => 'pack', 'weight' => 450, 'is_active' => 'N'], $update[0][1]);
    }
}

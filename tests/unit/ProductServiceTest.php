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

    /** Fake that answers permission, count and list queries and records the parameters of the last two. */
    private function listing(array $rows, int $total, \ArrayObject $seen): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params = []) use ($rows, $total, $seen): array {
                if (str_contains($sql, 'ME_OBJECTS')) {
                    return [['ACTIVE' => 1, 'ALLOW_READ' => 1]];
                }
                $seen[] = [$sql, $params];
                return str_contains($sql, 'COUNT(*)') ? [['TOTAL' => $total]] : $rows;
            },
        );
        return $db;
    }

    public function testListMapsRowsAndPagesWithCompanyScopeAndTotal(): void
    {
        $seen = new \ArrayObject();
        $db = $this->listing([self::ROW + ['CAN_DELETE' => 1]], 9, $seen);
        $result = (new ProductService($db))->list(self::admin(), ['page' => '2']);
        self::assertSame(2, $result['page']);
        self::assertSame(9, $result['total']);
        self::assertSame([['product_id' => 7, 'name' => 'Steel Bolt', 'sku' => 'BOLT-01', 'warehouse_id' => 'W01',
            'unit_label' => 'box', 'unit_weight_grams' => 500, 'active' => true, 'can_delete' => true]], $result['items']);
        self::assertCount(2, $seen);
        self::assertSame(['company_id' => 1], $seen[0][1]);
        self::assertSame(['company_id' => 1, 'offset' => 25, 'page_size' => 25], $seen[1][1]);
    }

    public function testListFlagsProductsThatCannotBeDeleted(): void
    {
        $db = $this->listing([self::ROW + ['CAN_DELETE' => 0]], 1, new \ArrayObject());
        self::assertFalse((new ProductService($db))->list(self::admin())['items'][0]['can_delete']);
    }

    public function testListSearchMatchesSkuOrNameCaseInsensitivelyAndEscapesWildcards(): void
    {
        $seen = new \ArrayObject();
        $db = $this->listing([], 0, $seen);
        (new ProductService($db))->list(self::admin(), ['q' => ' bo_lt% ']);
        self::assertCount(2, $seen);
        foreach ($seen as [$sql, $params]) {
            self::assertStringContainsString('LIKE', $sql);
            self::assertStringContainsString('ESCAPE', $sql);
            self::assertSame('%BO\_LT\%%', $params['q']);
        }
    }

    public function testBlankSearchIsIgnored(): void
    {
        $seen = new \ArrayObject();
        $db = $this->listing([], 0, $seen);
        (new ProductService($db))->list(self::admin(), ['q' => '   ']);
        foreach ($seen as [$sql, $params]) {
            self::assertArrayNotHasKey('q', $params);
            self::assertStringNotContainsString('LIKE', $sql);
        }
    }

    public function testSearchLongerThanOneHundredCharactersIsRejected(): void
    {
        $this->expectException(AppError::class);
        (new ProductService($this->listing([], 0, new \ArrayObject())))->list(self::admin(), ['q' => str_repeat('a', 101)]);
    }

    public function testSkuAndNameFiltersAreColumnSpecificContainsMatches(): void
    {
        $seen = new \ArrayObject();
        (new ProductService($this->listing([], 0, $seen)))->list(self::admin(), ['sku' => ' bo_1 ', 'name' => 'Steel%']);
        foreach ($seen as [$sql, $params]) {
            self::assertStringContainsString("UPPER(p.SKU) LIKE :sku ESCAPE", $sql);
            self::assertStringContainsString("UPPER(p.PRODUCT_NAME) LIKE :name ESCAPE", $sql);
            self::assertSame('%BO\_1%', $params['sku']);
            self::assertSame('%STEEL\%%', $params['name']);
        }
    }

    public function testStatusFilterMapsActiveAndInactiveAndIgnoresBlank(): void
    {
        foreach (['active' => 'Y', 'INACTIVE' => 'N', ' Active ' => 'Y'] as $input => $flag) {
            $seen = new \ArrayObject();
            (new ProductService($this->listing([], 0, $seen)))->list(self::admin(), ['status' => (string) $input]);
            foreach ($seen as [$sql, $params]) {
                self::assertStringContainsString('p.IS_ACTIVE = :status', $sql);
                self::assertSame($flag, $params['status']);
            }
        }
        $seen = new \ArrayObject();
        (new ProductService($this->listing([], 0, $seen)))->list(self::admin(), ['status' => '', 'sku' => '  ', 'name' => '']);
        foreach ($seen as [$sql, $params]) {
            self::assertStringNotContainsString('IS_ACTIVE = :status', $sql);
            self::assertStringNotContainsString('LIKE', $sql);
            self::assertSame(['company_id' => 1], array_slice($params, 0, 1));
        }
    }

    public function testFiltersCombineWithAnd(): void
    {
        $seen = new \ArrayObject();
        (new ProductService($this->listing([], 0, $seen)))->list(self::admin(), ['sku' => 'a', 'name' => 'b', 'status' => 'active']);
        self::assertSame(1, substr_count($seen[0][0], 'p.IS_ACTIVE = :status'));
        self::assertSame(['company_id' => 1, 'sku' => '%A%', 'name' => '%B%', 'status' => 'Y'], $seen[0][1]);
    }

    public function testBadStatusOrOverlongColumnFilterIsRejected(): void
    {
        foreach ([['status' => 'maybe'], ['status' => ['x']], ['sku' => str_repeat('a', 101)], ['name' => ['x']]] as $bad) {
            try {
                (new ProductService($this->listing([], 0, new \ArrayObject())))->list(self::admin(), $bad);
                self::fail('Expected AppError.');
            } catch (AppError $error) {
                self::assertSame('VALIDATION_ERROR', $error->errorCode);
            }
        }
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
                return [['IS_ACTIVE' => 'N', 'ON_HAND' => 0, 'RESERVED' => 0] + self::ROW];
            },
        );
        $product = (new ProductService($db))->get(self::admin(), 7);
        self::assertSame(7, $product['product_id']);
        self::assertFalse($product['active']);
    }

    public function testGetReturnsStockQuantitiesWithAvailableAsOnHandMinusReserved(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params = []): array {
                if (str_contains($sql, 'ME_OBJECTS')) {
                    return [['ACTIVE' => 1, 'ALLOW_READ' => 1]];
                }
                self::assertStringContainsString('ME_INV_STOCK_BALANCE', $sql);
                self::assertSame(['company_id' => 1, 'product_id' => 7], $params);
                return [['ON_HAND' => '20', 'RESERVED' => '5'] + self::ROW];
            },
        );
        $product = (new ProductService($db))->get(self::admin(), 7);
        self::assertSame(20, $product['on_hand']);
        self::assertSame(5, $product['reserved']);
        self::assertSame(15, $product['available']);
    }

    public function testGetShowsZeroStockForAProductWithNothingReceived(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'ME_OBJECTS')
                ? [['ACTIVE' => 1, 'ALLOW_READ' => 1]]
                : [['ON_HAND' => 0, 'RESERVED' => 0] + self::ROW],
        );
        $product = (new ProductService($db))->get(self::admin(), 7);
        self::assertSame([0, 0, 0], [$product['on_hand'], $product['reserved'], $product['available']]);
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
    private function updatable(?array $product, int $reserved, bool $used = false): array
    {
        $writes = new \ArrayObject();
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static function (string $sql) use ($product, $reserved, $used): array {
            return match (true) {
                str_contains($sql, 'ME_OBJECTS') => [['ACTIVE' => 1, 'ALLOW_UPDATE' => 1]],
                str_contains($sql, 'EXISTS') => $used ? [['USED' => 1]] : [],
                str_contains($sql, 'ME_INV_STOCK_BALANCE') => [['RESERVED' => $reserved]],
                str_contains($sql, 'ME_INV_PRODUCTS') => $product === null ? [] : [['PRODUCT_ID' => 7, 'UNIT_LABEL' => 'box', 'UNIT_WEIGHT_GRAMS' => 500]],
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

    public function testWeightCannotChangeOnceProductHasMovementsOrReservations(): void
    {
        [$db, $writes] = $this->updatable(['x'], 0, true);
        try {
            (new ProductService($db))->update(self::admin(), 'ref-u1', 7, self::CHANGE);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame('PRODUCT_IN_USE', $error->errorCode);
            self::assertSame(409, $error->status);
        }
        self::assertSame([], array_filter((array) $writes, static fn (array $w): bool => str_contains($w[0], 'UPDATE ME_INV_PRODUCTS')));
    }

    public function testUnitCannotChangeOnceProductHasMovementsOrReservations(): void
    {
        [$db, $writes] = $this->updatable(['x'], 0, true);
        try {
            (new ProductService($db))->update(self::admin(), 'ref-u1', 7, ['unit_weight_grams' => 500, 'unit_label' => 'pack'] + self::CHANGE);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame('PRODUCT_IN_USE', $error->errorCode);
            self::assertSame(409, $error->status);
        }
        self::assertSame([], array_filter((array) $writes, static fn (array $w): bool => str_contains($w[0], 'UPDATE ME_INV_PRODUCTS')));
    }

    public function testNameAndStatusStillChangeWhenUnitAndWeightAreUnchangedOnAUsedProduct(): void
    {
        [$db, $writes] = $this->updatable(['x'], 0, true);
        $result = (new ProductService($db))->update(self::admin(), 'ref-u1', 7, ['unit_weight_grams' => 500, 'unit_label' => 'box'] + self::CHANGE);
        self::assertSame(['box', 500, 'Steel Bolt M8'], [$result['unit_label'], $result['unit_weight_grams'], $result['name']]);
        self::assertCount(1, array_filter((array) $writes, static fn (array $w): bool => str_contains($w[0], 'UPDATE ME_INV_PRODUCTS')));
    }

    /** @return array{Connection, \ArrayObject<int, array{string, array<string, mixed>}>} connection that records every execute() */
    private function deletable(?array $product, int $onHand = 0, int $reserved = 0, bool $hasHistory = false): array
    {
        $writes = new \ArrayObject();
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static function (string $sql) use ($product, $onHand, $reserved, $hasHistory): array {
            return match (true) {
                str_contains($sql, 'ME_OBJECTS') => [['ACTIVE' => 1, 'ALLOW_DELETE' => 1]],
                str_contains($sql, 'EXISTS') => $hasHistory ? [['USED' => 1]] : [],
                str_contains($sql, 'ME_INV_STOCK_BALANCE') => [['ON_HAND' => $onHand, 'RESERVED' => $reserved]],
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

    /** @param \ArrayObject<int, array{string, array<string, mixed>}> $writes */
    private static function deletes(\ArrayObject $writes): array
    {
        return array_values(array_filter((array) $writes, static fn (array $w): bool => str_starts_with(ltrim($w[0]), 'DELETE')));
    }

    public function testDeleteIsDeniedWhenObjectIsNotRegistered(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $db->expects(self::never())->method('begin');
        $this->expectException(AppError::class);
        (new ProductService($db))->delete(self::admin(), 'ref-d1', 7);
    }

    public function testDeleteIsDeniedWithoutTheDeleteGrantOnTheObject(): void
    {
        $db = $this->registered();
        $db->expects(self::never())->method('begin');
        $this->expectException(AppError::class);
        (new ProductService($db))->delete(self::admin(), 'ref-d1', 7);
    }

    public function testDeleteUnknownProductIsNotFoundAndNothingIsDeleted(): void
    {
        [$db, $writes] = $this->deletable(null);
        try {
            (new ProductService($db))->delete(self::admin(), 'ref-d1', 99);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame('NOT_FOUND', $error->errorCode);
            self::assertSame(404, $error->status);
        }
        self::assertSame([], self::deletes($writes));
    }

    public function testCannotDeleteWhileStockIsOnHand(): void
    {
        [$db, $writes] = $this->deletable(['x'], 5);
        try {
            (new ProductService($db))->delete(self::admin(), 'ref-d1', 7);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame('PRODUCT_IN_USE', $error->errorCode);
            self::assertSame(409, $error->status);
        }
        self::assertSame([], self::deletes($writes));
    }

    public function testCannotDeleteWhileStockIsReserved(): void
    {
        [$db, $writes] = $this->deletable(['x'], 5, 2);
        try {
            (new ProductService($db))->delete(self::admin(), 'ref-d1', 7);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame('PRODUCT_IN_USE', $error->errorCode);
        }
        self::assertSame([], self::deletes($writes));
    }

    public function testCannotDeleteAProductWithMovementOrReservationHistoryEvenAtZeroStock(): void
    {
        [$db, $writes] = $this->deletable(['x'], 0, 0, true);
        try {
            (new ProductService($db))->delete(self::admin(), 'ref-d1', 7);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame('PRODUCT_IN_USE', $error->errorCode);
            self::assertSame(409, $error->status);
        }
        self::assertSame([], self::deletes($writes));
    }

    public function testDeleteRemovesTheBalanceRowThenTheProductScopedToCompany(): void
    {
        [$db, $writes] = $this->deletable(['x']);
        $result = (new ProductService($db))->delete(self::admin(), 'ref-d1', 7);
        self::assertSame(['product_id' => 7, 'deleted' => true], $result);
        $deletes = self::deletes($writes);
        self::assertCount(2, $deletes);
        self::assertStringContainsString('ME_INV_STOCK_BALANCE', $deletes[0][0]);
        self::assertStringContainsString('ME_INV_PRODUCTS', $deletes[1][0]);
        foreach ($deletes as $delete) {
            self::assertSame(['company_id' => 1, 'product_id' => 7], $delete[1]);
        }
    }

    public function testLookupReturnsTheRequestedProductsInRequestedOrderScopedToCompany(): void
    {
        $seen = new \ArrayObject();
        $db = $this->listing([['PRODUCT_ID' => 9, 'SKU' => 'NUT-09'] + self::ROW, self::ROW], 0, $seen);
        $products = (new ProductService($db))->lookupProducts(self::admin(), [7, 9]);
        self::assertSame([7, 9], array_column($products, 'product_id'));
        self::assertSame(['BOLT-01', 'NUT-09'], array_column($products, 'sku'));
        self::assertCount(1, $seen);
        self::assertSame(['company_id' => 1, 'id0' => 7, 'id1' => 9], $seen[0][1]);
        self::assertStringContainsString('PRODUCT_ID IN (:id0, :id1)', $seen[0][0]);
    }

    public function testLookupGivesIdSkuNameUnitWeightAndActiveFlag(): void
    {
        $db = $this->listing([['IS_ACTIVE' => 'N'] + self::ROW], 0, new \ArrayObject());
        $product = (new ProductService($db))->lookupProducts(self::admin(), [7])[0];
        foreach (['product_id', 'sku', 'name', 'unit_label', 'unit_weight_grams', 'active'] as $field) {
            self::assertArrayHasKey($field, $product);
        }
        self::assertFalse($product['active']);
    }

    public function testLookupCollapsesDuplicateIds(): void
    {
        $seen = new \ArrayObject();
        $db = $this->listing([self::ROW], 0, $seen);
        $products = (new ProductService($db))->lookupProducts(self::admin(), [7, 7, 7]);
        self::assertCount(1, $products);
        self::assertSame(['company_id' => 1, 'id0' => 7], $seen[0][1]);
    }

    public function testLookupOfAMissingIdFailsWholeCallAndNamesTheMissingIds(): void
    {
        $db = $this->listing([self::ROW], 0, new \ArrayObject());
        try {
            (new ProductService($db))->lookupProducts(self::admin(), [7, 8, 12]);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame('NOT_FOUND', $error->errorCode);
            self::assertSame(404, $error->status);
            self::assertStringContainsString('8', $error->getMessage());
            self::assertStringContainsString('12', $error->getMessage());
            self::assertDoesNotMatchRegularExpression('/\\b7\\b/', $error->getMessage());
        }
    }

    public function testLookupRejectsAnEmptyOrTooLongOrMalformedIdList(): void
    {
        $service = new ProductService($this->listing([], 0, new \ArrayObject()));
        $bad = [[], range(1, 101), [0], [-3], ['7'], [1.5], [true], [null], [[7]]];
        foreach ($bad as $ids) {
            try {
                $service->lookupProducts(self::admin(), $ids);
                self::fail('Expected AppError for ' . json_encode($ids));
            } catch (AppError $error) {
                self::assertSame('VALIDATION_ERROR', $error->errorCode, json_encode($ids));
                self::assertSame(400, $error->status);
            }
        }
    }

    public function testLookupAcceptsExactlyOneHundredIds(): void
    {
        $rows = array_map(static fn (int $id): array => ['PRODUCT_ID' => $id] + self::ROW, range(1, 100));
        $products = (new ProductService($this->listing($rows, 0, new \ArrayObject())))->lookupProducts(self::admin(), range(1, 100));
        self::assertCount(100, $products);
    }

    public function testLookupIsDeniedWithoutReadPermissionAndNeverQueriesProducts(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static function (string $sql): array {
            self::assertStringNotContainsString('ME_INV_PRODUCTS', $sql, 'products must not be read without permission');
            return [];
        });
        $this->expectException(AppError::class);
        (new ProductService($db))->lookupProducts(self::admin(), [7]);
    }
}
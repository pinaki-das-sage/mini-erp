<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\inventory\InventoryWeb;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\TestCase;

final class InventoryWebTest extends TestCase
{
    private const ROW = ['PRODUCT_ID' => 7, 'PRODUCT_NAME' => 'Steel Bolt', 'SKU' => 'BOLT-01', 'WAREHOUSE_ID' => 'W01',
        'UNIT_LABEL' => 'box', 'UNIT_WEIGHT_GRAMS' => 500, 'IS_ACTIVE' => 'Y', 'CAN_DELETE' => 1];

    private static function admin(): Actor
    {
        return new Actor('a', 'admin', [], 1, true);
    }

    /** @param \ArrayObject<int, array{string, array<string, mixed>}> $seen */
    private function web(array $rows, int $total, \ArrayObject $seen): InventoryWeb
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static function (string $sql, array $params = []) use ($rows, $total, $seen): array {
            if (str_contains($sql, 'ME_OBJECTS')) {
                return [['ACTIVE' => 1, 'ALLOW_READ' => 1]];
            }
            $seen[] = [$sql, $params];
            return str_contains($sql, 'COUNT(*)') ? [['TOTAL' => $total]] : $rows;
        });
        return new InventoryWeb($db);
    }

    private function page(array $query = [], array $rows = [self::ROW], int $total = 1, ?\ArrayObject $seen = null): array
    {
        return $this->web($rows, $total, $seen ?? new \ArrayObject())->handle(self::admin(), 'GET', '/modules/inventory/products', $query);
    }

    public function testListPageUsesTheListTemplateAndShowsWeightInKilograms(): void
    {
        $page = $this->page();
        self::assertSame('inventory/products/list.tpl', $page['view']);
        self::assertSame('Products', $page['title']);
        self::assertSame(
            ['id' => 7, 'sku' => 'BOLT-01', 'name' => 'Steel Bolt', 'unit' => 'box', 'weightKg' => '0.5', 'active' => true, 'statusCode' => 'ACTIVE', 'canDelete' => true],
            $page['data']['products'][0],
        );
        self::assertSame(1, $page['data']['total']);
    }

    public function testColumnFiltersAndPageReachTheServiceAndPagingLinksKeepThem(): void
    {
        $seen = new \ArrayObject();
        $page = $this->page(['sku' => '  bo ', 'name' => 'steel', 'status' => 'inactive', 'page' => '2'], [self::ROW], 80, $seen);
        self::assertSame('%BO%', $seen[0][1]['sku']);
        self::assertSame('%STEEL%', $seen[0][1]['name']);
        self::assertSame('N', $seen[0][1]['status']);
        self::assertSame(20, $seen[1][1]['page_size']);
        self::assertSame(20, $seen[1][1]['offset']);
        self::assertSame(['sku' => 'bo', 'name' => 'steel', 'status' => 'INACTIVE'], $page['data']['filters']);
        self::assertTrue($page['data']['columns']['sku']['filtered']);
        self::assertSame(4, $page['data']['pages']);
        self::assertSame('sku=bo&name=steel&status=INACTIVE&page_size=20&page=1', $page['data']['previousQuery']);
        self::assertSame('sku=bo&name=steel&status=INACTIVE&page_size=20&page=3', $page['data']['nextQuery']);
        self::assertSame(21, $page['data']['first']);
        self::assertSame(40, $page['data']['last']);
    }

    public function testGlobalSearchIsGoneFromTheListPage(): void
    {
        $seen = new \ArrayObject();
        $this->page(['q' => 'bolt'], [self::ROW], 1, $seen);
        self::assertArrayNotHasKey('q', $seen[0][1]);
        self::assertStringNotContainsString('name="q"', (string) file_get_contents(dirname(__DIR__, 2) . '/views/inventory/products/list.tpl'));
    }

    public function testPageSizeIsTenTwentyOrFiftyAndDefaultsToTwenty(): void
    {
        foreach (['10' => 10, '20' => 20, '50' => 50, '25' => 20, 'x' => 20, '' => 20] as $input => $expected) {
            self::assertSame($expected, $this->page(['page_size' => (string) $input])['data']['pageSize'], 'page_size=' . $input);
        }
    }

    public function testFirstAndLastPagesHaveNoPreviousOrNextLink(): void
    {
        $first = $this->page(['page' => '1'], [self::ROW], 80)['data'];
        self::assertSame('', $first['previousQuery']);
        self::assertSame('page_size=20&page=2', $first['nextQuery']);
        $last = $this->page(['page' => '4'], [self::ROW], 80)['data'];
        self::assertSame('page_size=20&page=3', $last['previousQuery']);
        self::assertSame('', $last['nextQuery']);
    }

    public function testBadInputsAreCleanedInsteadOfFailing(): void
    {
        foreach (['abc', '0', '-1', '1.5', '12345678', ['x']] as $bad) {
            self::assertSame(1, $this->page(['page' => $bad])['data']['page']);
        }
        self::assertSame(100, mb_strlen($this->page(['sku' => str_repeat('a', 300)])['data']['filters']['sku']));
        $data = $this->page(['sku' => ['x'], 'name' => ['y'], 'status' => 'bogus'])['data'];
        self::assertSame(['sku' => '', 'name' => '', 'status' => ''], $data['filters']);
    }

    public function testAPageBeyondTheEndShowsTheLastPage(): void
    {
        $data = $this->page(['page' => '9'], [self::ROW], 30)['data'];
        self::assertSame(2, $data['page']);
        self::assertSame(2, $data['pages']);
    }

    public function testAnEmptyListStillGivesOnePage(): void
    {
        $data = $this->page([], [], 0)['data'];
        self::assertSame([], $data['products']);
        self::assertSame(1, $data['pages']);
        self::assertSame(0, $data['first']);
        self::assertSame('', $data['nextQuery']);
    }

    public function testStatusCodeDrivesTheColouredPill(): void
    {
        $data = $this->page([], [self::ROW, ['IS_ACTIVE' => 'N'] + self::ROW], 2)['data'];
        self::assertSame(['ACTIVE', 'INACTIVE'], array_column($data['products'], 'statusCode'));
    }

    public function testUnknownPagesAndOtherMethodsAreNotFound(): void
    {
        $web = $this->web([], 0, new \ArrayObject());
        foreach ([['GET', '/modules/inventory/other'], ['POST', '/modules/inventory/products'], ['GET', '/modules/inventory/products/7']] as [$method, $path]) {
            try {
                $web->handle(self::admin(), $method, $path, []);
                self::fail('Expected AppError.');
            } catch (AppError $error) {
                self::assertSame(404, $error->status, $method . ' ' . $path);
            }
        }
    }

    public function testAddFormPageUsesItsOwnTemplate(): void
    {
        $page = $this->web([], 0, new \ArrayObject())->handle(self::admin(), 'GET', '/modules/inventory/products/new', []);
        self::assertSame('inventory/products/new.tpl', $page['view']);
        self::assertSame('Add product', $page['title']);
    }

    public function testFormFieldsBecomeServiceInputWithWeightInGrams(): void
    {
        self::assertSame(
            ['name' => 'Steel Bolt', 'sku' => 'bolt-01', 'unit_label' => 'box', 'unit_weight_grams' => 500],
            InventoryWeb::productInput(['name' => 'Steel Bolt', 'sku' => 'bolt-01', 'unitLabel' => 'box', 'unitWeightKg' => '0.5']),
        );
        self::assertSame(12345, InventoryWeb::productInput(['unitWeightKg' => '12.345'])['unit_weight_grams']);
    }

    public function testMissingTextFieldsPassAsNullSoTheServiceRejectsThem(): void
    {
        $input = InventoryWeb::productInput(['unitWeightKg' => '1']);
        self::assertNull($input['name']);
        self::assertNull($input['sku']);
        self::assertNull($input['unit_label']);
    }

    public function testBadWeightInTheFormIsAValidationError(): void
    {
        foreach ([null, '', '0', 'abc', '1.2345', ['1']] as $bad) {
            try {
                InventoryWeb::productInput(['unitWeightKg' => $bad]);
                self::fail('Expected AppError.');
            } catch (AppError $error) {
                self::assertSame('VALIDATION_ERROR', $error->errorCode);
            }
        }
    }

    public function testTheApiPathIsNotAPage(): void
    {
        try {
            $this->web([], 0, new \ArrayObject())->handle(self::admin(), 'GET', '/api/inventory/products', []);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame(404, $error->status);
        }
    }

    /** Fake database for the product page: one product, one movement, and optionally no right to see movements. */
    private function viewWeb(bool $movementsAllowed = true, bool $productExists = true, int $total = 1): InventoryWeb
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static function (string $sql, array $params = []) use ($movementsAllowed, $productExists, $total): array {
            return match (true) {
                str_contains($sql, 'ME_OBJECTS') => !$movementsAllowed && in_array('inventory.movements', $params, true) ? [] : [['ACTIVE' => 1, 'ALLOW_READ' => 1]],
                str_contains($sql, 'COUNT(*)') => [['TOTAL' => $total]],
                str_contains($sql, 'MOVEMENT_AT') && str_contains($sql, 'OFFSET') => [['MOVEMENT_ID' => 12, 'MOVEMENT_AT' => '2026-11-23T19:18:47Z',
                    'MOVEMENT_TYPE' => 'RECEIVED', 'SOURCE_TYPE' => 'PURCHASE_RECEIVER', 'SOURCE_ID' => 'RCPT-1', 'RESERVATION_ID' => null,
                    'OPENING_BALANCE' => 0, 'RECEIVED_QTY' => 20, 'DISPATCHED_QTY' => 0, 'CLOSING_BALANCE' => 20]],
                str_contains($sql, 'LEFT JOIN ME_INV_STOCK_BALANCE') => $productExists ? [['PRODUCT_ID' => 7, 'PRODUCT_NAME' => 'Steel Bolt', 'SKU' => 'BOLT-01',
                    'WAREHOUSE_ID' => 'W01', 'UNIT_LABEL' => 'box', 'UNIT_WEIGHT_GRAMS' => 500, 'IS_ACTIVE' => 'Y', 'ON_HAND' => 20, 'RESERVED' => 5]] : [],
                str_contains($sql, 'FROM ME_INV_PRODUCTS') => $productExists ? [['PRODUCT_ID' => 7]] : [],
                default => throw new \LogicException('Unexpected test query: ' . $sql),
            };
        });
        return new InventoryWeb($db);
    }

    public function testProductPageShowsDetailsStockAndMovements(): void
    {
        $page = $this->viewWeb()->handle(self::admin(), 'GET', '/modules/inventory/products/7', []);
        self::assertSame('inventory/products/view.tpl', $page['view']);
        self::assertSame('Steel Bolt', $page['title']);
        self::assertSame(
            ['id' => 7, 'sku' => 'BOLT-01', 'name' => 'Steel Bolt', 'unit' => 'box', 'weightKg' => '0.5', 'warehouse' => 'W01',
                'active' => true, 'statusCode' => 'ACTIVE', 'onHand' => 20, 'reserved' => 5, 'available' => 15],
            $page['data']['product'],
        );
        self::assertSame(
            [['when' => '2026-11-23 19:18:47 UTC', 'type' => 'Received', 'sourceType' => 'PURCHASE_RECEIVER', 'sourceId' => 'RCPT-1',
                'reservationId' => null, 'opening' => 0, 'received' => 20, 'dispatched' => 0, 'closing' => 20]],
            $page['data']['movements'],
        );
        self::assertSame(1, $page['data']['movementsTotal']);
        self::assertSame(20, $page['data']['pageSize']);
        self::assertSame([1, 1], [$page['data']['first'], $page['data']['last']]);
        self::assertSame('ACTIVE', $page['data']['product']['statusCode']);
    }

    public function testProductPageStillShowsTheProductWhenMovementsAreNotAllowed(): void
    {
        $page = $this->viewWeb(false)->handle(self::admin(), 'GET', '/modules/inventory/products/7', []);
        self::assertNull($page['data']['movements']);
        self::assertSame('BOLT-01', $page['data']['product']['sku']);
    }

    public function testUnknownProductIsNotFoundAndBadIdsAreNoRoute(): void
    {
        foreach (['/modules/inventory/products/7'] as $path) {
            try {
                $this->viewWeb(true, false)->handle(self::admin(), 'GET', $path, []);
                self::fail('Expected AppError.');
            } catch (AppError $error) {
                self::assertSame(404, $error->status);
            }
        }
        foreach (['abc', '0', '07', '12345678901', '7/extra', '-1'] as $id) {
            try {
                $this->viewWeb()->handle(self::admin(), 'GET', '/modules/inventory/products/' . $id, []);
                self::fail('Expected AppError for ' . $id);
            } catch (AppError $error) {
                self::assertSame(404, $error->status, $id);
            }
        }
    }

    public function testMovementPagingKeepsTheProductPage(): void
    {
        $data = $this->viewWeb(true, true, 80)->handle(self::admin(), 'GET', '/modules/inventory/products/7', ['page' => '2'])['data'];
        self::assertSame(4, $data['pages']);
        self::assertSame('page_size=20&page=1', $data['previousQuery']);
        self::assertSame('page_size=20&page=3', $data['nextQuery']);
        self::assertSame([21, 40], [$data['first'], $data['last']]);
    }

    public function testMovementPageSizeIsTenTwentyOrFiftyAndAPageBeyondTheEndShowsTheLast(): void
    {
        self::assertSame(50, $this->viewWeb()->handle(self::admin(), 'GET', '/modules/inventory/products/7', ['page_size' => '50'])['data']['pageSize']);
        self::assertSame(20, $this->viewWeb()->handle(self::admin(), 'GET', '/modules/inventory/products/7', ['page_size' => '30'])['data']['pageSize']);
        self::assertSame(4, $this->viewWeb(true, true, 80)->handle(self::admin(), 'GET', '/modules/inventory/products/7', ['page' => '99'])['data']['page']);
    }

    public function testEditPageShowsCurrentValues(): void
    {
        $page = $this->viewWeb()->handle(self::admin(), 'GET', '/modules/inventory/products/7/edit', []);
        self::assertSame('inventory/products/edit.tpl', $page['view']);
        self::assertSame('BOLT-01', $page['data']['product']['sku']);
        self::assertSame('0.5', $page['data']['product']['weightKg']);
        self::assertTrue($page['data']['product']['active']);
    }

    public function testUpdateInputConvertsKilogramsAndNeverCarriesTheSku(): void
    {
        $input = InventoryWeb::updateInput(['name' => 'Bolt', 'sku' => 'HACK', 'unitLabel' => 'box', 'unitWeightKg' => '0.25', 'active' => false]);
        self::assertSame(['name' => 'Bolt', 'unit_label' => 'box', 'unit_weight_grams' => 250, 'active' => false], $input);
        $this->expectException(AppError::class);
        InventoryWeb::updateInput(['name' => 'Bolt', 'unitLabel' => 'box', 'unitWeightKg' => '0']);
    }

    public function testDeleteAndUpdateRoutesRejectBadIdsAndWrongMethods(): void
    {
        foreach ([['POST', '/api/inventory/products/delete/abc'], ['POST', '/api/inventory/products/delete/0'], ['GET', '/api/inventory/products/delete/7'],
            ['POST', '/api/inventory/products/update/07']] as [$method, $path]) {
            try {
                $this->viewWeb()->handle(self::admin(), $method, $path, []);
                self::fail('Expected AppError for ' . $path);
            } catch (AppError $error) {
                self::assertSame(404, $error->status, $path);
            }
        }
    }
}
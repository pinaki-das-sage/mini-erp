<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\shared\Api;
use MiniErp\shared\Config;
use MiniErp\shared\database\Connection;
use PHPUnit\Framework\TestCase;

final class InventoryApiTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const JSON = ['authorization' => 'Bearer ' . self::TOKEN, 'content-type' => 'application/json'];

    /** @var list<array{string, array<string, mixed>}> */
    private array $writes = [];
    private bool $registered = true;
    private bool $productExists = true;
    private bool $deleteAllowed = true;
    private int $onHand = 0;

    private function api(bool $admin = true): Api
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, array $parameters = []) use ($admin): array {
            return match (true) {
                str_contains($sql, 'ME_API_TOKENS') => [['USER_ID' => 'u', 'COMPANY_ID' => 7, 'SECURITY_VERSION' => 1, 'EXPIRES_AT' => time() + 3600, 'REVOKED_AT' => null]],
                str_contains($sql, 'JOIN ME_COMPANY') => [['ID' => 'u', 'USERNAME' => 'user', 'COMPANY_ID' => 7, 'COMPANY_NAME' => 'Lab',
                    'IS_ADMIN' => $admin ? 1 : 0, 'SECURITY_VERSION' => 1, 'MUST_CHANGE_PASSWORD' => 0]],
                str_contains($sql, 'ME_USER_ROLES') => [],
                str_contains($sql, 'ME_OBJECT_ACTIONS') => [],
                str_contains($sql, 'ME_OBJECTS') => $this->registered
                    ? [['ACTIVE' => 1, 'ALLOW_CREATE' => 1, 'ALLOW_READ' => 1, 'ALLOW_UPDATE' => 1, 'ALLOW_DELETE' => $this->deleteAllowed ? 1 : 0]] : [],
                str_contains($sql, 'ME_USER_PERMISSIONS') => [],
                str_contains($sql, 'NEXTVAL') => [['ID' => 5]],
                str_contains($sql, 'COUNT(*)') => [['TOTAL' => 1]],
                str_contains($sql, 'MOVEMENT_AT') && str_contains($sql, 'OFFSET') => [['MOVEMENT_ID' => 12, 'MOVEMENT_AT' => '2026-11-23T19:18:47Z',
                    'MOVEMENT_TYPE' => 'RECEIVED', 'SOURCE_TYPE' => 'PURCHASE_RECEIVER', 'SOURCE_ID' => 'RCPT-1', 'RESERVATION_ID' => null,
                    'OPENING_BALANCE' => 0, 'RECEIVED_QTY' => 20, 'DISPATCHED_QTY' => 0, 'CLOSING_BALANCE' => 20]],
                str_contains($sql, 'OFFSET') => [['PRODUCT_ID' => 7, 'PRODUCT_NAME' => 'Steel Bolt', 'SKU' => 'BOLT-01', 'WAREHOUSE_ID' => 'W01',
                    'UNIT_LABEL' => 'box', 'UNIT_WEIGHT_GRAMS' => 500, 'IS_ACTIVE' => 'Y', 'CAN_DELETE' => 1]],
                str_contains($sql, 'LEFT JOIN ME_INV_STOCK_BALANCE') => $this->productExists ? [['PRODUCT_ID' => $parameters['product_id'] ?? 7,
                    'PRODUCT_NAME' => 'Steel Bolt', 'SKU' => 'BOLT-01', 'WAREHOUSE_ID' => 'W01', 'UNIT_LABEL' => 'box',
                    'UNIT_WEIGHT_GRAMS' => 500, 'IS_ACTIVE' => 'Y', 'ON_HAND' => 20, 'RESERVED' => 5]] : [],
                str_contains($sql, 'ME_INV_STOCK_BALANCE') => [['ON_HAND' => $this->onHand, 'RESERVED' => 0]],
                str_contains($sql, 'EXISTS') => [],
                str_contains($sql, 'FOR UPDATE') => $this->productExists ? [['PRODUCT_ID' => 7, 'UNIT_LABEL' => 'box', 'UNIT_WEIGHT_GRAMS' => 500]] : [],
                str_contains($sql, 'FROM ME_INV_PRODUCTS') => $this->productExists ? [['PRODUCT_ID' => $parameters['product_id'] ?? 7,
                    'PRODUCT_NAME' => 'Steel Bolt', 'SKU' => 'BOLT-01', 'WAREHOUSE_ID' => 'W01', 'UNIT_LABEL' => 'box',
                    'UNIT_WEIGHT_GRAMS' => 500, 'IS_ACTIVE' => 'Y']] : [],
                default => throw new \LogicException('Unexpected test query: ' . $sql),
            };
        });
        $db->method('execute')->willReturnCallback(function (string $sql, array $parameters = []): int {
            $this->writes[] = [$sql, $parameters];
            return 1;
        });
        return new Api($db, Config::fromArray([]));
    }

    /** @return array<string, mixed> */
    private function wasWritten(string $value): bool
    {
        foreach ($this->writes as [, $parameters]) {
            if (in_array($value, $parameters, true)) {
                return true;
            }
        }
        return false;
    }

    private function decode(array $response): array
    {
        return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testRequestsWithoutTokenAreRejected(): void
    {
        self::assertSame(401, $this->api()->handle('GET', '/api/v1/inventory/products', [])['status']);
    }

    public function testUnregisteredObjectIsForbiddenEvenForAdmin(): void
    {
        $this->registered = false;
        self::assertSame(403, $this->api()->handle('GET', '/api/v1/inventory/products', [], self::JSON)['status']);
    }

    public function testNonAdminWithoutGrantIsForbidden(): void
    {
        self::assertSame(403, $this->api(false)->handle('GET', '/api/v1/inventory/products', [], self::JSON)['status']);
    }

    public function testListReturnsPagedItemsForTheTokenCompany(): void
    {
        $response = $this->api()->handle('GET', '/api/v1/inventory/products', [], self::JSON, ['page' => '1', 'pageSize' => '10']);
        self::assertSame(200, $response['status']);
        $result = $this->decode($response)['result'];
        self::assertSame('BOLT-01', $result['items'][0]['sku']);
        self::assertSame(10, $result['pageSize']);
        self::assertSame(1, $result['total']);
        self::assertTrue($result['items'][0]['canDelete']);
    }

    public function testGetByIdReturnsProductAndRejectsBadIds(): void
    {
        $api = $this->api();
        self::assertSame(7, $this->decode($api->handle('GET', '/api/v1/inventory/products/7', [], self::JSON))['result']['productId']);
        foreach (['abc', '0', '12345678901'] as $bad) {
            self::assertSame(400, $api->handle('GET', '/api/v1/inventory/products/' . $bad, [], self::JSON)['status'], $bad);
        }
    }

    public function testGetUnknownProductIs404(): void
    {
        $this->productExists = false;
        self::assertSame(404, $this->api()->handle('GET', '/api/v1/inventory/products/99', [], self::JSON)['status']);
    }

    public function testCreateReturns201AndEchoesTheActionReference(): void
    {
        $response = $this->api()->handle('POST', '/api/v1/inventory/products', [
            'actionReference' => 'prod-create-1', 'name' => 'Steel Bolt', 'sku' => 'bolt-01', 'unitLabel' => 'box', 'unitWeightKg' => 0.5,
        ], self::JSON);
        self::assertSame(201, $response['status']);
        $body = $this->decode($response);
        self::assertTrue($this->wasWritten('prod-create-1'));
        self::assertSame(['productId' => 5, 'sku' => 'BOLT-01'], $body['result']);
        $product = array_values(array_filter($this->writes, static fn (array $w): bool => str_contains($w[0], 'INSERT INTO ME_INV_PRODUCTS')));
        self::assertSame(7, $product[0][1]['company_id']);
    }

    public function testCreateWorksWithoutAnActionReferenceButStillNeedsJson(): void
    {
        $api = $this->api();
        $input = ['name' => 'Steel Bolt', 'sku' => 'BOLT-01', 'unitLabel' => 'box', 'unitWeightKg' => 0.5];
        self::assertSame(201, $api->handle('POST', '/api/v1/inventory/products', $input, self::JSON)['status']);
        self::assertSame(415, $api->handle('POST', '/api/v1/inventory/products', $input, ['authorization' => 'Bearer ' . self::TOKEN])['status']);
    }

    public function testWritesWithoutAReferenceUseAGeneratedOneThatDiffersEachTime(): void
    {
        $api = $this->api();
        $input = ['name' => 'Steel Bolt', 'sku' => 'BOLT-01', 'unitLabel' => 'box', 'unitWeightKg' => 0.5];
        self::assertSame(201, $api->handle('POST', '/api/v1/inventory/products', $input, self::JSON)['status']);
        self::assertSame(201, $api->handle('POST', '/api/v1/inventory/products', $input, self::JSON)['status']);
        $used = [];
        foreach ($this->writes as [, $parameters]) {
            foreach ($parameters as $value) {
                if (is_string($value) && preg_match('/\Aauto-[0-9a-f]{16}\z/', $value) === 1) {
                    $used[$value] = true;
                }
            }
        }
        self::assertCount(2, $used, 'each request should have been recorded under its own generated reference');
    }

    public function testUpdateAndDeleteAlsoWorkWithoutAReference(): void
    {
        $api = $this->api();
        $update = ['name' => 'Steel Bolt M8', 'unitLabel' => 'pack', 'unitWeightKg' => 0.45, 'active' => true];
        self::assertSame(200, $api->handle('POST', '/api/v1/inventory/products/update/7', $update, self::JSON)['status']);
        self::assertSame(200, $api->handle('POST', '/api/v1/inventory/products/delete/7', [], self::JSON)['status']);
    }

    public function testAReferenceThatIsSentMustStillBeANonEmptyString(): void
    {
        $api = $this->api();
        $input = ['name' => 'Steel Bolt', 'sku' => 'BOLT-01', 'unitLabel' => 'box', 'unitWeightKg' => 0.5];
        foreach ([['actionReference' => ''], ['actionReference' => 123], ['actionReference' => ['x']]] as $bad) {
            self::assertSame(400, $api->handle('POST', '/api/v1/inventory/products', $bad + $input, self::JSON)['status']);
        }
    }

    public function testUpdateRouteUpdatesTheProduct(): void
    {
        $response = $this->api()->handle('POST', '/api/v1/inventory/products/update/7', [
            'actionReference' => 'prod-upd-1', 'name' => 'Steel Bolt M8', 'unitLabel' => 'pack', 'unitWeightKg' => 0.45, 'active' => true,
        ], self::JSON);
        self::assertSame(200, $response['status']);
        self::assertSame('Steel Bolt M8', $this->decode($response)['result']['name']);
    }

    public function testUpdateRouteCannotChangeSkuAndPutPatchDeleteAreNotRoutes(): void
    {
        $api = $this->api();
        $change = ['actionReference' => 'prod-upd-2', 'sku' => 'NEW', 'name' => 'X', 'unitLabel' => 'box', 'unitWeightKg' => 0.005, 'active' => true];
        self::assertSame(400, $api->handle('POST', '/api/v1/inventory/products/update/7', $change, self::JSON)['status']);
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            self::assertSame(404, $api->handle($method, '/api/v1/inventory/products/7', $change, self::JSON)['status'], $method);
        }
    }

    public function testDeleteRouteDeletesAnUnusedProductForTheTokenCompany(): void
    {
        $response = $this->api()->handle('POST', '/api/v1/inventory/products/delete/7', ['actionReference' => 'prod-del-1'], self::JSON);
        self::assertSame(200, $response['status']);
        $body = $this->decode($response);
        self::assertTrue($this->wasWritten('prod-del-1'));
        self::assertSame(['productId' => 7, 'deleted' => true], $body['result']);
        $deletes = array_values(array_filter($this->writes, static fn (array $w): bool => str_starts_with(ltrim($w[0]), 'DELETE FROM ME_INV_')));
        self::assertCount(2, $deletes);
        self::assertSame(7, $deletes[1][1]['company_id']);
    }

    public function testDeleteRouteNeedsAValidId(): void
    {
        $api = $this->api();
        self::assertSame(400, $api->handle('POST', '/api/v1/inventory/products/delete/abc', ['actionReference' => 'r1'], self::JSON)['status']);
        self::assertSame(400, $api->handle('POST', '/api/v1/inventory/products/delete/0', ['actionReference' => 'r2'], self::JSON)['status']);
    }

    public function testDeleteIsForbiddenWhenTheObjectHasNoDeleteGrant(): void
    {
        $this->deleteAllowed = false;
        self::assertSame(403, $this->api()->handle('POST', '/api/v1/inventory/products/delete/7', ['actionReference' => 'r1'], self::JSON)['status']);
        self::assertSame([], array_filter($this->writes, static fn (array $w): bool => str_starts_with(ltrim($w[0]), 'DELETE')));
    }

    public function testDeleteUnknownProductIs404(): void
    {
        $this->productExists = false;
        self::assertSame(404, $this->api()->handle('POST', '/api/v1/inventory/products/delete/99', ['actionReference' => 'r1'], self::JSON)['status']);
    }

    public function testDeleteIsRefusedWith409WhenStockIsOnHand(): void
    {
        $this->onHand = 4;
        $response = $this->api()->handle('POST', '/api/v1/inventory/products/delete/7', ['actionReference' => 'r1'], self::JSON);
        self::assertSame(409, $response['status']);
        self::assertSame('PRODUCT_IN_USE', $this->decode($response)['error']['code']);
        self::assertSame([], array_filter($this->writes, static fn (array $w): bool => str_starts_with(ltrim($w[0]), 'DELETE')));
    }

    public function testListSearchIsPassedThroughAndOtherFiltersAreStillRejected(): void
    {
        $api = $this->api();
        self::assertSame(200, $api->handle('GET', '/api/v1/inventory/products', [], self::JSON, ['q' => 'bolt'])['status']);
        self::assertSame(400, $api->handle('GET', '/api/v1/inventory/products', [], self::JSON, ['colour' => 'red'])['status']);
    }

    public function testGetReturnsStockQuantities(): void
    {
        $product = $this->decode($this->api()->handle('GET', '/api/v1/inventory/products/7', [], self::JSON))['result'];
        self::assertSame([20, 5, 15], [$product['onHand'], $product['reserved'], $product['available']]);
    }

    public function testMovementHistoryRouteReturnsPagedMovementsForTheProduct(): void
    {
        $response = $this->api()->handle('GET', '/api/v1/inventory/products/movements/7', [], self::JSON);
        self::assertSame(200, $response['status']);
        $result = $this->decode($response)['result'];
        self::assertSame(1, $result['total']);
        self::assertSame(12, $result['items'][0]['movementId']);
        self::assertSame(20, $result['items'][0]['receivedQuantity']);
    }

    public function testMovementHistoryRouteRejectsABadIdAndUnknownProductAndOtherFilters(): void
    {
        $api = $this->api();
        self::assertSame(400, $api->handle('GET', '/api/v1/inventory/products/movements/abc', [], self::JSON)['status']);
        self::assertSame(400, $api->handle('GET', '/api/v1/inventory/products/movements/7', [], self::JSON, ['colour' => 'red'])['status']);
        $this->productExists = false;
        self::assertSame(404, $this->api()->handle('GET', '/api/v1/inventory/products/movements/99', [], self::JSON)['status']);
    }

    public function testMovementHistoryNeedsTheObjectToBeRegistered(): void
    {
        $this->registered = false;
        self::assertSame(403, $this->api()->handle('GET', '/api/v1/inventory/products/movements/7', [], self::JSON)['status']);
    }

    public function testRequestsStillAcceptSnakeCaseKeysAndResponsesAreCamelCaseOnly(): void
    {
        $api = $this->api();
        $old = ['action_reference' => 'old-1', 'name' => 'Steel Bolt', 'sku' => 'BOLT-01', 'unit_label' => 'box', 'unit_weight_grams' => 500];
        self::assertSame(201, $api->handle('POST', '/api/v1/inventory/products', $old, self::JSON)['status']);
        $result = $this->decode($api->handle('GET', '/api/v1/inventory/products/7', [], self::JSON))['result'];
        self::assertArrayHasKey('unitLabel', $result);
        self::assertArrayHasKey('unitWeightKg', $result);
        self::assertSame(0.5, $result['unitWeightKg']);
        self::assertArrayNotHasKey('unitWeightGrams', $result);
        self::assertArrayNotHasKey('unit_label', $result);
    }

    public function testQueryParametersAreCamelCaseAndTheOldIdFirstPathsAreGone(): void
    {
        $api = $this->api();
        self::assertSame(200, $api->handle('GET', '/api/v1/inventory/products', [], self::JSON, ['page' => '1', 'pageSize' => '5'])['status']);
        foreach (['/products/7/update', '/products/7/delete'] as $old) {
            self::assertSame(404, $api->handle('POST', '/api/v1/inventory' . $old, [], self::JSON)['status'], $old);
        }
        self::assertSame(404, $api->handle('GET', '/api/v1/inventory/products/7/movements', [], self::JSON)['status']);
    }

    public function testWeightIsAcceptedInKilogramsAndStoredAsWholeGrams(): void
    {
        $api = $this->api();
        $input = ['name' => 'Steel Bolt', 'sku' => 'BOLT-01', 'unitLabel' => 'box'];
        foreach ([[0.5, 500], [1, 1000], [0.001, 1], [12.345, 12345], [0.07, 70]] as [$kg, $grams]) {
            $this->writes = [];
            self::assertSame(201, $api->handle('POST', '/api/v1/inventory/products', $input + ['unitWeightKg' => $kg], self::JSON)['status'], (string) $kg);
            $insert = array_values(array_filter($this->writes, static fn (array $w): bool => str_contains($w[0], 'INSERT INTO ME_INV_PRODUCTS')));
            self::assertSame($grams, $insert[0][1]['weight'], (string) $kg);
        }
    }

    public function testWeightInKilogramsMustBePositiveWithAtMostThreeDecimals(): void
    {
        $api = $this->api();
        $input = ['name' => 'Steel Bolt', 'sku' => 'BOLT-01', 'unitLabel' => 'box'];
        foreach ([0, -1, 0.0004, 1.2345, '0.5', 'abc', null, true, [1], 10000000] as $bad) {
            self::assertSame(400, $api->handle('POST', '/api/v1/inventory/products', $input + ['unitWeightKg' => $bad], self::JSON)['status'], var_export($bad, true));
        }
        self::assertSame(400, $api->handle('POST', '/api/v1/inventory/products', $input, self::JSON)['status'], 'weight is required');
    }
}
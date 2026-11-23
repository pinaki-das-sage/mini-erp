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
                    ? [['ACTIVE' => 1, 'ALLOW_CREATE' => 1, 'ALLOW_READ' => 1, 'ALLOW_UPDATE' => 1, 'ALLOW_DELETE' => 0]] : [],
                str_contains($sql, 'ME_USER_PERMISSIONS') => [],
                str_contains($sql, 'NEXTVAL') => [['ID' => 5]],
                str_contains($sql, 'ME_INV_STOCK_BALANCE') => [['RESERVED' => 0]],
                str_contains($sql, 'FOR UPDATE') => $this->productExists ? [['PRODUCT_ID' => 7]] : [],
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
        $response = $this->api()->handle('GET', '/api/v1/inventory/products', [], self::JSON, ['page' => '1', 'page_size' => '10']);
        self::assertSame(200, $response['status']);
        $result = $this->decode($response)['result'];
        self::assertSame('BOLT-01', $result['items'][0]['sku']);
        self::assertSame(10, $result['page_size']);
    }

    public function testGetByIdReturnsProductAndRejectsBadIds(): void
    {
        $api = $this->api();
        self::assertSame(7, $this->decode($api->handle('GET', '/api/v1/inventory/products/7', [], self::JSON))['result']['product_id']);
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
            'action_reference' => 'prod-create-1', 'name' => 'Steel Bolt', 'sku' => 'bolt-01', 'unit_label' => 'box', 'unit_weight_grams' => 500,
        ], self::JSON);
        self::assertSame(201, $response['status']);
        $body = $this->decode($response);
        self::assertSame('prod-create-1', $body['operation_id']);
        self::assertSame(['product_id' => 5, 'sku' => 'BOLT-01'], $body['result']);
        $product = array_values(array_filter($this->writes, static fn (array $w): bool => str_contains($w[0], 'INSERT INTO ME_INV_PRODUCTS')));
        self::assertSame(7, $product[0][1]['company_id']);
    }

    public function testCreateWithoutActionReferenceOrJsonIsRejected(): void
    {
        $api = $this->api();
        $input = ['name' => 'Steel Bolt', 'sku' => 'BOLT-01', 'unit_label' => 'box', 'unit_weight_grams' => 500];
        self::assertSame(400, $api->handle('POST', '/api/v1/inventory/products', $input, self::JSON)['status']);
        self::assertSame(415, $api->handle('POST', '/api/v1/inventory/products', $input + ['action_reference' => 'r1'],
            ['authorization' => 'Bearer ' . self::TOKEN])['status']);
    }

    public function testUpdateRouteUpdatesTheProduct(): void
    {
        $response = $this->api()->handle('POST', '/api/v1/inventory/products/7/update', [
            'action_reference' => 'prod-upd-1', 'name' => 'Steel Bolt M8', 'unit_label' => 'pack', 'unit_weight_grams' => 450, 'active' => true,
        ], self::JSON);
        self::assertSame(200, $response['status']);
        self::assertSame('Steel Bolt M8', $this->decode($response)['result']['name']);
    }

    public function testUpdateRouteCannotChangeSkuAndThereIsNoDeleteOrPatchRoute(): void
    {
        $api = $this->api();
        $change = ['action_reference' => 'prod-upd-2', 'sku' => 'NEW', 'name' => 'X', 'unit_label' => 'box', 'unit_weight_grams' => 5, 'active' => true];
        self::assertSame(400, $api->handle('POST', '/api/v1/inventory/products/7/update', $change, self::JSON)['status']);
        self::assertSame(404, $api->handle('DELETE', '/api/v1/inventory/products/7', [], self::JSON)['status']);
        self::assertSame(404, $api->handle('PATCH', '/api/v1/inventory/products/7', $change, self::JSON)['status']);
    }
}
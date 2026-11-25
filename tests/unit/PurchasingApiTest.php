<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\Config;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\TestCase;

final class PurchasingApiTest extends TestCase
{
    private function handler(Connection $db): \Closure
    {
        $api = new Api($db, Config::fromArray([]));
        $routes = (new \ReflectionProperty(Api::class, 'routes'))->getValue($api);
        foreach ($routes as $route) {
            if ($route['path'] === '/purchasing/vendors') {
                self::assertSame('GET', $route['method']);
                self::assertSame('purchasing.vendor', $route['object']);
                self::assertSame('read', $route['operation']);
                return $route['handler'];
            }
        }
        self::fail('Purchasing vendor route was not loaded.');
    }

    public function testListBindsAuthenticatedCompanyFiltersAndPagination(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('query')->willReturnCallback(
            static function (string $sql, array $bindings): array {
                self::assertStringContainsString('CNYID = :company_id', $sql);
                self::assertStringContainsString('ORDER BY ID OFFSET :offset', $sql);
                self::assertStringNotContainsString('supplier-search', $sql);
                self::assertSame([
                    'company_id' => 7, 'status' => 'active', 'search' => '%supplier-search%',
                    'offset' => 10, 'limit' => 10,
                ], $bindings);
                return [[
                    'CNYID' => '7', 'ID' => '0001', 'VENDOR_ID' => 'V-1', 'NAME' => 'Supplier',
                    'STATUS' => 'active', 'CONTACT' => null, 'EMAIL' => null, 'PHONE' => null,
                    'ADDR_1' => '1 Main Street', 'ADDR_2' => null, 'CITY' => 'London',
                    'STATE' => null, 'POSTAL_CODE' => '00123', 'COUNTRY' => 'United Kingdom',
                    'PAYMENT_TERMS' => 'net_30', 'CREATEDBY' => 'user', 'MODIFIEDBY' => 'user',
                    'WHENCREATED' => '2026-11-24T10:00:00.000Z',
                    'WHENMODIFIED' => '2026-11-24T10:00:00.000Z',
                ]];
            },
        );
        $result = ($this->handler($db))(new Actor('u', 'user', [], 7), [], [], [
            'page' => '2', 'page_size' => '10', 'status' => 'active', 'search' => 'supplier-search',
        ]);
        self::assertSame([[
            'cnyid' => 7, 'id' => '0001', 'vendor_id' => 'V-1', 'name' => 'Supplier',
            'status' => 'active', 'contact' => null, 'email' => null, 'phone' => null,
            'addr_1' => '1 Main Street', 'addr_2' => null, 'city' => 'London', 'state' => null,
            'postal_code' => '00123', 'country' => 'United Kingdom', 'payment_terms' => 'net_30',
            'createdby' => 'user', 'modifiedby' => 'user',
            'whencreated' => '2026-11-24T10:00:00.000Z',
            'whenmodified' => '2026-11-24T10:00:00.000Z',
        ]], $result);
    }

    public function testEmptyDatabaseReturnsEmptyList(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('query')->with(
            self::stringContains('CNYID = :company_id'),
            ['company_id' => 9, 'offset' => 0, 'limit' => 25],
        )->willReturn([]);
        self::assertSame([], ($this->handler($db))(new Actor('u', 'user', [], 9), [], [], []));
    }

    public function testInvalidOrCompanyOverrideFiltersNeverReachDatabase(): void
    {
        foreach ([
            ['company_id' => '99'], ['cnyid' => '99'], ['status' => 'unknown'],
            ['page' => '0'], ['page_size' => '101'], ['search' => ['nested']],
            ['search' => str_repeat('a', 101)],
        ] as $query) {
            $db = $this->createMock(Connection::class);
            $db->expects(self::never())->method('query');
            $handler = $this->handler($db);
            try {
                $handler(new Actor('u', 'user', [], 7), [], [], $query);
                self::fail('Invalid filter must fail before any database access.');
            } catch (AppError $error) {
                self::assertSame('VALIDATION_ERROR', $error->errorCode);
            }
        }
    }
}

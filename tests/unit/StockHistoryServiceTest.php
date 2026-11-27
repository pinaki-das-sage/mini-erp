<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\inventory\StockHistoryService;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\TestCase;

final class StockHistoryServiceTest extends TestCase
{
    private const ROW = [
        'MOVEMENT_ID' => '12', 'MOVEMENT_AT' => '2026-11-23T19:18:47Z', 'MOVEMENT_TYPE' => 'RECEIVED',
        'SOURCE_TYPE' => 'PURCHASE_RECEIVER', 'SOURCE_ID' => 'RCPT-1', 'RESERVATION_ID' => null,
        'OPENING_BALANCE' => '0', 'RECEIVED_QTY' => '20', 'DISPATCHED_QTY' => '0', 'CLOSING_BALANCE' => '20',
    ];

    private static function admin(int $company = 1): Actor
    {
        return new Actor('a', 'admin', [], $company, true);
    }

    /** Answers permission, product, count and list queries; records the last three with their parameters. */
    private function history(array $rows, int $total, bool $productExists, \ArrayObject $seen): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params = []) use ($rows, $total, $productExists, $seen): array {
                if (str_contains($sql, 'ME_OBJECTS')) {
                    return [['ACTIVE' => 1, 'ALLOW_READ' => 1]];
                }
                $seen[] = [$sql, $params];
                return match (true) {
                    str_contains($sql, 'COUNT(*)') => [['TOTAL' => $total]],
                    str_contains($sql, 'ME_INV_STOCK_MOVEMENT') => $rows,
                    default => $productExists ? [['PRODUCT_ID' => 7]] : [],
                };
            },
        );
        return $db;
    }

    public function testHistoryMapsMovementsOldestFirstWithCompanyAndProductScope(): void
    {
        $seen = new \ArrayObject();
        $db = $this->history([self::ROW], 1, true, $seen);
        $result = (new StockHistoryService($db))->forProduct(self::admin(), 7);
        self::assertSame(1, $result['page']);
        self::assertSame(25, $result['page_size']);
        self::assertSame(1, $result['total']);
        self::assertSame([[
            'movement_id' => 12, 'moved_at' => '2026-11-23T19:18:47Z', 'type' => 'RECEIVED',
            'source_type' => 'PURCHASE_RECEIVER', 'source_id' => 'RCPT-1', 'reservation_id' => null,
            'opening_balance' => 0, 'received_quantity' => 20, 'dispatched_quantity' => 0, 'closing_balance' => 20,
        ]], $result['items']);
        self::assertCount(3, $seen);
        self::assertSame(['company_id' => 1, 'product_id' => 7], $seen[0][1]);
        self::assertSame(['company_id' => 1, 'product_id' => 7], $seen[1][1]);
        self::assertSame(['company_id' => 1, 'product_id' => 7, 'offset' => 0, 'page_size' => 25], $seen[2][1]);
        self::assertStringContainsString('ORDER BY MOVEMENT_AT ASC, MOVEMENT_ID ASC', $seen[2][0]);
        self::assertStringContainsString('MOVEMENT_AT', $seen[2][0]);
    }

    public function testHistoryUsesTheActorsCompanyAndPages(): void
    {
        $seen = new \ArrayObject();
        $db = $this->history([], 0, true, $seen);
        (new StockHistoryService($db))->forProduct(self::admin(5), 7, ['page' => '3']);
        self::assertSame(['company_id' => 5, 'product_id' => 7, 'offset' => 50, 'page_size' => 25], $seen[2][1]);
    }

    public function testHistoryKeepsAReservationIdAsAnInteger(): void
    {
        $db = $this->history([['RESERVATION_ID' => '31', 'MOVEMENT_TYPE' => 'RESERVED'] + self::ROW], 1, true, new \ArrayObject());
        $item = (new StockHistoryService($db))->forProduct(self::admin(), 7)['items'][0];
        self::assertSame(31, $item['reservation_id']);
        self::assertSame('RESERVED', $item['type']);
    }

    public function testHistoryOfAnUnknownProductIsNotFoundAndMovementsAreNotQueried(): void
    {
        $seen = new \ArrayObject();
        $db = $this->history([self::ROW], 1, false, $seen);
        try {
            (new StockHistoryService($db))->forProduct(self::admin(), 99);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame('NOT_FOUND', $error->errorCode);
            self::assertSame(404, $error->status);
        }
        self::assertCount(1, $seen);
        self::assertStringNotContainsString('ME_INV_STOCK_MOVEMENT', $seen[0][0]);
    }

    public function testHistoryIsDeniedWithoutReadPermissionAndNothingIsQueried(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static function (string $sql): array {
            self::assertStringNotContainsString('ME_INV_', $sql, 'no inventory table may be read without permission');
            return [];
        });
        $this->expectException(AppError::class);
        (new StockHistoryService($db))->forProduct(self::admin(), 7);
    }

    public function testHistoryRejectsUnsupportedFilters(): void
    {
        $db = $this->history([], 0, true, new \ArrayObject());
        $this->expectException(AppError::class);
        (new StockHistoryService($db))->forProduct(self::admin(), 7, ['colour' => 'red']);
    }
}
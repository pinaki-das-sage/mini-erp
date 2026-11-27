<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\inventory\StockReceiptService;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StockReceiptServiceTest extends TestCase
{
    private static function admin(): Actor
    {
        return new Actor('a', 'admin', [], 1, true);
    }

    /**
     * @param list<array<string, mixed>>|null $balance rows for the locked balance (null = no balance row)
     * @param list<array<string, mixed>> $existing earlier movement rows for the same source
     * @return array{Connection, \ArrayObject<int, array{string, array<string, mixed>}>, TransactionContext}
     */
    private function scenario(?int $onHand, array $existing = [], bool $actionRegistered = true): array
    {
        $writes = new \ArrayObject();
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('begin');
        $db->expects(self::never())->method('commit');
        $db->expects(self::never())->method('rollback');
        $db->method('query')->willReturnCallback(static function (string $sql) use ($onHand, $existing, $actionRegistered): array {
            return match (true) {
                str_contains($sql, 'ME_OBJECT_ACTIONS') => $actionRegistered ? [['ACTION_CODE' => 'receive']] : [],
                str_contains($sql, 'ME_OBJECTS') => [['ACTIVE' => 1, 'ALLOW_CREATE' => 0, 'ALLOW_READ' => 1, 'ALLOW_UPDATE' => 0, 'ALLOW_DELETE' => 0]],
                str_contains($sql, 'FOR UPDATE') => $onHand === null ? [] : [['ON_HAND' => $onHand]],
                str_contains($sql, 'NEXTVAL') => [['ID' => 55]],
                str_contains($sql, 'FROM ME_INV_STOCK_MOVEMENT') => $existing,
                default => throw new \LogicException('Unexpected test query: ' . $sql),
            };
        });
        $db->method('execute')->willReturnCallback(static function (string $sql, array $parameters = []) use ($writes): int {
            $writes[] = [$sql, $parameters];
            return 1;
        });
        return [$db, $writes, new TransactionContext($db)];
    }

    public function testReceiveIsDeniedWhenActionIsNotRegistered(): void
    {
        [$db, $writes, $tx] = $this->scenario(0, [], false);
        try {
            (new StockReceiptService($db))->receivePurchase($tx, self::admin(), 'RL-1', 7, 20);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame('FORBIDDEN', $error->errorCode);
        }
        self::assertCount(0, $writes);
    }

    /** @return array<string, array{string, int, int}> */
    public static function invalidInput(): array
    {
        return [
            'empty source' => ['', 7, 20],
            'source too long' => [str_repeat('S', 41), 7, 20],
            'source with spaces' => ['RL 1', 7, 20],
            'zero product' => ['RL-1', 0, 20],
            'zero quantity' => ['RL-1', 7, 0],
            'negative quantity' => ['RL-1', 7, -3],
            'quantity too large' => ['RL-1', 7, 10000000000],
        ];
    }

    #[DataProvider('invalidInput')]
    public function testInvalidInputIsRejectedWithoutAnyWrite(string $sourceId, int $productId, int $quantity): void
    {
        [$db, $writes, $tx] = $this->scenario(0);
        try {
            (new StockReceiptService($db))->receivePurchase($tx, self::admin(), $sourceId, $productId, $quantity);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame('VALIDATION_ERROR', $error->errorCode);
        }
        self::assertCount(0, $writes);
    }

    public function testUnknownProductIsNotFoundAndNothingIsWritten(): void
    {
        [$db, $writes, $tx] = $this->scenario(null);
        try {
            (new StockReceiptService($db))->receivePurchase($tx, self::admin(), 'RL-1', 99, 20);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame('NOT_FOUND', $error->errorCode);
            self::assertSame(404, $error->status);
        }
        self::assertCount(0, $writes);
    }

    public function testReceivingTwentyFromZeroWritesOneMovementAndRaisesOnHand(): void
    {
        [$db, $writes, $tx] = $this->scenario(0);
        $result = (new StockReceiptService($db))->receivePurchase($tx, self::admin(), 'RL-1', 7, 20);
        self::assertSame(['movement_id' => 55, 'product_id' => 7, 'received_quantity' => 20, 'opening_balance' => 0, 'closing_balance' => 20], $result);
        self::assertCount(2, $writes);
        self::assertStringContainsString('INSERT INTO ME_INV_STOCK_MOVEMENT', $writes[0][0]);
        self::assertSame(['company_id' => 1, 'product_id' => 7, 'movement_id' => 55, 'opening' => 0, 'quantity' => 20,
            'closing' => 20, 'source_type' => 'PURCHASE_RECEIVER', 'source_id' => 'RL-1'], $writes[0][1]);
        self::assertStringContainsString('UPDATE ME_INV_STOCK_BALANCE SET ON_HAND = ON_HAND + :quantity', $writes[1][0]);
        self::assertSame(['company_id' => 1, 'product_id' => 7, 'quantity' => 20], $writes[1][1]);
    }

    public function testSecondReceiptBuildsOnTheCurrentBalance(): void
    {
        [$db, $writes, $tx] = $this->scenario(20);
        $result = (new StockReceiptService($db))->receivePurchase($tx, self::admin(), 'RL-2', 7, 5);
        self::assertSame(20, $result['opening_balance']);
        self::assertSame(25, $result['closing_balance']);
        self::assertSame(20, $writes[0][1]['opening']);
        self::assertSame(25, $writes[0][1]['closing']);
    }

    public function testReplayOfTheSameSourceAndQuantityReturnsTheOriginalWithoutNewEffects(): void
    {
        $original = [['MOVEMENT_ID' => 41, 'OPENING_BALANCE' => 0, 'RECEIVED_QTY' => 20, 'CLOSING_BALANCE' => 20]];
        [$db, $writes, $tx] = $this->scenario(20, $original);
        $result = (new StockReceiptService($db))->receivePurchase($tx, self::admin(), 'RL-1', 7, 20);
        self::assertSame(['movement_id' => 41, 'product_id' => 7, 'received_quantity' => 20, 'opening_balance' => 0, 'closing_balance' => 20], $result);
        self::assertCount(0, $writes);
    }

    public function testSameSourceWithAChangedQuantityIsAConflictWithNoEffect(): void
    {
        $original = [['MOVEMENT_ID' => 41, 'OPENING_BALANCE' => 0, 'RECEIVED_QTY' => 20, 'CLOSING_BALANCE' => 20]];
        [$db, $writes, $tx] = $this->scenario(20, $original);
        try {
            (new StockReceiptService($db))->receivePurchase($tx, self::admin(), 'RL-1', 7, 25);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame('DUPLICATE_SOURCE_CONFLICT', $error->errorCode);
            self::assertSame(409, $error->status);
        }
        self::assertCount(0, $writes);
    }

    public function testReceiptThatWouldOverflowTheBalanceIsRejected(): void
    {
        [$db, $writes, $tx] = $this->scenario(9999999990);
        try {
            (new StockReceiptService($db))->receivePurchase($tx, self::admin(), 'RL-1', 7, 20);
            self::fail('Expected AppError.');
        } catch (AppError $error) {
            self::assertSame('VALIDATION_ERROR', $error->errorCode);
        }
        self::assertCount(0, $writes);
    }
}
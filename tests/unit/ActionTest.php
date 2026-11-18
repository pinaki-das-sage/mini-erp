<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\shared\ActionExecutor;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\NeutralNote;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\TestCase;

final class ActionTest extends TestCase
{
    public function testCanonicalPayloadIgnoresObjectKeyOrderButNotListOrder(): void
    {
        self::assertSame(
            ActionExecutor::fingerprint(['b' => ['y' => 2, 'x' => 1], 'a' => [1, 2]]),
            ActionExecutor::fingerprint(['a' => [1, 2], 'b' => ['x' => 1, 'y' => 2]])
        );
        self::assertNotSame(ActionExecutor::fingerprint([1, 2]), ActionExecutor::fingerprint([2, 1]));
    }

    public function testUnauthorizedReplayDoesNotTouchDatabase(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static fn (string $sql): array =>
            str_contains($sql, 'ME_OBJECTS') ? [['ACTIVE' => 1, 'ALLOW_UPDATE' => 1]] : [['USER_ID' => 'u']]);
        $db->expects(self::never())->method('begin');
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('Your role cannot perform this operation.');
        (new NeutralNote($db))->update(new Actor('u', 'buyer', ['buyer'], 1), 'action-1', 'new', 1);
    }

    public function testCommittedReplayDoesNotReexecuteMutation(): void
    {
        $payload = ['note_id' => 'welcome', 'text' => 'new', 'expected_version' => 1];
        $db = $this->createMock(Connection::class);
        $db->method('execute')->willThrowException(new DatabaseError(1));
        $db->method('query')->willReturn([[
            'ACTOR_ID' => 'u', 'PAYLOAD_HASH' => ActionExecutor::fingerprint($payload),
            'RESULT_JSON' => '{"current_version":2,"text":"new"}',
        ]]);
        $db->expects(self::once())->method('commit');
        $executor = new ActionExecutor(new TransactionManager($db));
        $result = $executor->run(new Actor('u', 'buyer', ['trainee'], 1), 'action-1', $payload,
            function (): never { self::fail('A replay must not validate current version or mutate.'); }
        );
        self::assertSame(2, $result['current_version']);
    }

    public function testChangedPayloadReplayConflicts(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('execute')->willThrowException(new DatabaseError(1));
        $db->method('query')->willReturn([[
            'ACTOR_ID' => 'u', 'PAYLOAD_HASH' => 'different', 'RESULT_JSON' => '{}',
        ]]);
        $db->expects(self::once())->method('rollback');
        $executor = new ActionExecutor(new TransactionManager($db));
        $this->expectException(AppError::class);
        $executor->run(new Actor('u', 'buyer', ['trainee'], 1), 'action-1', ['value' => 'new'], fn () => []);
    }

    public function testNewActionPersistsResultAndAuditInSameTransaction(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(3))->method('execute')->willReturn(1);
        $db->expects(self::once())->method('commit');
        $executor = new ActionExecutor(new TransactionManager($db));
        self::assertSame(['current_version' => 2], $executor->run(
            new Actor('u', 'buyer', ['trainee'], 1), 'action-1', ['value' => 'new'],
            fn (TransactionContext $tx) => ['current_version' => 2]
        ));
    }

    public function testNoteUpdateIsVersionedAndReturnsBusinessResult(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql): array {
            if (str_contains($sql, 'FROM ME_OBJECTS')) {
                return [['ACTIVE' => 1, 'ALLOW_UPDATE' => 1, 'ALLOW_READ' => 1]];
            }
            if (str_contains($sql, 'FROM ME_USER_PERMISSIONS')) {
                return [['USER_ID' => 'u']];
            }
            return [['ID' => 'welcome', 'BODY' => 'old', 'VERSION' => 1]];
        });
        $db->expects(self::exactly(4))->method('execute')->willReturn(1);
        $db->expects(self::once())->method('commit');
        $note = new NeutralNote($db);
        self::assertSame('old', $note->read(new Actor('u', 'buyer', ['trainee'], 1))['text']);
        $result = $note->update(new Actor('u', 'buyer', ['trainee'], 1), 'action-1', 'new', 1);
        self::assertSame(2, $result['current_version']);
        self::assertSame('new', $result['text']);
    }

    public function testStaleNoteUpdateRollsBackClaimedAction(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql): array {
            if (str_contains($sql, 'FROM ME_OBJECTS')) {
                return [['ACTIVE' => 1, 'ALLOW_UPDATE' => 1]];
            }
            if (str_contains($sql, 'FROM ME_USER_PERMISSIONS')) {
                return [['USER_ID' => 'u']];
            }
            return [['ID' => 'welcome', 'BODY' => 'old', 'VERSION' => 2]];
        });
        $db->expects(self::once())->method('execute')->willReturn(1);
        $db->expects(self::once())->method('rollback');
        $this->expectException(AppError::class);
        (new NeutralNote($db))->update(new Actor('u', 'buyer', ['trainee'], 1), 'action-1', 'new', 1);
    }

    public function testNoteRejectsOversizedInputBeforeDatabaseWrite(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('begin');
        $this->expectException(AppError::class);
        (new NeutralNote($db))->update(new Actor('u', 'buyer', ['trainee'], 1), 'action-1', str_repeat('a', 501), 1);
    }
}

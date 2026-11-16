<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;
use MiniErp\shared\security\UserService;
use PHPUnit\Framework\TestCase;

final class PermissionsTest extends TestCase
{
    private const CURRENT_ACTOR = ['IS_ADMIN' => 1, 'ACTIVE' => 1, 'SECURITY_VERSION' => 1, 'MUST_CHANGE_PASSWORD' => 0];

    public function testAdminRevokedWhileWaitingForCompanyLockCannotMutate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['ID' => 1]], [['IS_ADMIN' => 0, 'ACTIVE' => 0, 'SECURITY_VERSION' => 2, 'MUST_CHANGE_PASSWORD' => 0]],
        );
        $db->expects(self::never())->method('execute');
        $db->expects(self::once())->method('rollback');
        $this->expectException(AppError::class);
        (new UserService($db))->create(new Actor('a', 'admin', [], 1, true), 'newuser', 'New User', 'fixture-password', false);
    }

    public function testAdminGetsRegisteredPermissionWithoutGrant(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('query')->willReturn([['ALLOW_READ' => 1, 'ACTIVE' => 1]]);
        (new Authorization($db))->requirePermission(new Actor('a', 'admin', [], 1, true), 'inventory.products', 'read');
    }

    public function testUnknownObjectIsDeniedEvenForAdmin(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $this->expectException(AppError::class);
        (new Authorization($db))->requirePermission(new Actor('a', 'admin', [], 1, true), 'missing', 'read');
    }

    public function testNonAdminRequiresExplicitNamedActionGrant(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(3))->method('query')->willReturnOnConsecutiveCalls(
            [['ACTIVE' => 1]], [['ACTION_CODE' => 'approve']], [['USER_ID' => 'u']],
        );
        (new Authorization($db))->requirePermission(new Actor('u', 'buyer', [], 1), 'purchasing.orders', 'approve');
    }

    public function testAbsentGrantAndUnsupportedCrudFailClosed(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['ACTIVE' => 1, 'ALLOW_DELETE' => 0]]);
        $this->expectException(AppError::class);
        (new Authorization($db))->requirePermission(new Actor('a', 'admin', [], 1, true), 'inventory.products', 'delete');
    }

    public function testApiAndUiUseIdenticalGrants(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['ACTIVE' => 1, 'ALLOW_READ' => 1]], [],
        );
        $this->expectException(AppError::class);
        (new Authorization($db))->requirePermission(new Actor('u', 'user', [], 1), 'inventory.products', 'read');
    }

    public function testSettingsCannotBeGrantedToNonAdmin(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        $this->expectException(AppError::class);
        (new UserService($db))->listUsers(new Actor('u', 'user', [], 1));
    }

    public function testUserCreationStartsWithoutGrantsAndHashesPassword(): void
    {
        $db = $this->createMock(Connection::class);
        $writes = [];
        $db->method('execute')->willReturnCallback(function (string $sql, array $params) use (&$writes): int {
            $writes[] = [$sql, $params];
            return 1;
        });
        $db->method('query')->willReturnOnConsecutiveCalls([['ID' => 1]], [self::CURRENT_ACTOR]);
        $db->expects(self::once())->method('commit');
        $id = (new UserService($db))->create(new Actor('a', 'admin', [], 1, true), 'newuser', 'New User', 'fixture-password', false);
        self::assertNotEmpty($id);
        $insert = array_values(array_filter($writes, fn ($w) => str_contains($w[0], 'INSERT INTO ME_USERS')))[0];
        self::assertTrue(password_verify('fixture-password', $insert[1]['hash']));
        self::assertSame(0, $insert[1]['admin']);
        self::assertFalse((bool) array_filter($writes, fn ($w) => str_contains($w[0], 'INSERT INTO ME_USER_PERMISSIONS')));
    }

    public function testLastActiveAdminCannotBeDisabledOrDemoted(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['ID' => 1]], [self::CURRENT_ACTOR], [['ID' => 'a', 'IS_ADMIN' => 1, 'ACTIVE' => 1, 'VERSION' => 1]], [['TOTAL' => 1]],
        );
        $db->expects(self::never())->method('execute');
        $db->expects(self::once())->method('rollback');
        $this->expectException(AppError::class);
        (new UserService($db))->update(new Actor('a', 'admin', [], 1, true), 'a', 'Admin', false, false, 1);
    }

    public function testStaleUserEditIsRejected(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['ID' => 1]], [self::CURRENT_ACTOR], [['ID' => 'u', 'IS_ADMIN' => 0, 'ACTIVE' => 1, 'VERSION' => 2]],
        );
        $db->expects(self::never())->method('execute');
        $this->expectException(AppError::class);
        (new UserService($db))->update(new Actor('a', 'admin', [], 1, true), 'u', 'User', false, true, 1);
    }

    public function testInvalidPermissionIsRejectedWithoutDeletingOldGrants(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['ID' => 1]], [self::CURRENT_ACTOR], [['ID' => 'u', 'IS_ADMIN' => 0, 'VERSION' => 1]], [],
        );
        $db->expects(self::never())->method('execute');
        $this->expectException(AppError::class);
        (new UserService($db))->savePermissions(new Actor('a', 'admin', [], 1, true), 'u', ['missing:read'], 1);
    }

    public function testOwnPasswordChangeRequiresOldPasswordAndInvalidatesSessions(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['ID' => 1]], [self::CURRENT_ACTOR], [['ID' => 'u', 'PASSWORD_HASH' => password_hash('old-password', PASSWORD_DEFAULT)]],
        );
        $db->expects(self::once())->method('commit');
        $db->expects(self::exactly(2))->method('execute')->willReturnCallback(function (string $sql, array $params): int {
            if (str_contains($sql, 'UPDATE ME_USERS')) {
                self::assertStringContainsString('SECURITY_VERSION = SECURITY_VERSION + 1', $sql);
                self::assertTrue(password_verify('new-password-long', $params['hash']));
            }
            return 1;
        });
        (new UserService($db))->changePassword(new Actor('u', 'user', [], 1), 'old-password', 'new-password-long');
    }

    public function testActorSettingsGuardAndAdminMetadata(): void
    {
        $admin = new Actor('a', 'admin', [], 1, true, 3, true);
        self::assertTrue($admin->isAdmin);
        self::assertSame(3, $admin->securityVersion);
        self::assertTrue($admin->mustChangePassword);
        (new Actor('a', 'admin', [], 1, true))->requireAdmin();
        $this->expectException(AppError::class);
        (new Actor('u', 'user', [], 1))->requireAdmin();
    }

    public function testTemporaryPasswordCannotBypassServiceAuthorization(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        $this->expectException(AppError::class);
        (new Authorization($db))->requirePermission(new Actor('a', 'admin', [], 1, true, 1, true), 'shared.note', 'update');
    }

    public function testPasswordResetRequiresAdminAndNeverLogsPassword(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['ID' => 1]], [self::CURRENT_ACTOR], [['ID' => 'u', 'IS_ADMIN' => 0, 'ACTIVE' => 1, 'VERSION' => 1]],
        );
        $db->expects(self::exactly(2))->method('execute')->willReturnCallback(function (string $sql, array $params): int {
            if (str_contains($sql, 'ME_SECURITY_AUDIT')) {
                self::assertSame('PASSWORD_RESET', $params['event']);
                self::assertSame('[]', $params['details']);
            } else {
                self::assertStringContainsString('MUST_CHANGE_PASSWORD = 1', $sql);
                self::assertTrue(password_verify('reset-password-long', $params['hash']));
            }
            return 1;
        });
        (new UserService($db))->resetPassword(new Actor('a', 'admin', [], 1, true), 'u', 'reset-password-long', 1);
    }

    public function testWrongCurrentPasswordRollsBackWithoutUpdate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['ID' => 1]], [self::CURRENT_ACTOR], [['ID' => 'u', 'PASSWORD_HASH' => password_hash('current-password', PASSWORD_DEFAULT)]],
        );
        $db->expects(self::never())->method('execute');
        $db->expects(self::once())->method('rollback');
        $this->expectException(AppError::class);
        (new UserService($db))->changePassword(new Actor('u', 'user', [], 1), 'wrong', 'new-password-long');
    }

    public function testPermissionReplacementValidatesDeduplicatesAndInvalidatesSessions(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['ID' => 1]], [self::CURRENT_ACTOR], [['ID' => 'u', 'IS_ADMIN' => 0, 'VERSION' => 1]],
            [['ACTIVE' => 1, 'ALLOW_READ' => 1]], [['ACTIVE' => 1, 'ALLOW_READ' => 1]],
            [['OBJECT_CODE' => 'shared.note', 'OPERATION' => 'update']],
        );
        $writes = [];
        $db->method('execute')->willReturnCallback(function (string $sql, array $params) use (&$writes): int {
            $writes[] = [$sql, $params];
            return 1;
        });
        $db->expects(self::once())->method('commit');
        (new UserService($db))->savePermissions(new Actor('a', 'admin', [], 1, true), 'u', ['shared.note:read', 'shared.note:read'], 1);
        self::assertCount(4, $writes);
        self::assertStringStartsWith('DELETE FROM ME_USER_PERMISSIONS', $writes[0][0]);
        self::assertSame(['id' => 'u', 'company_id' => 1, 'object' => 'shared.note', 'operation' => 'read'], $writes[1][1]);
        self::assertStringContainsString('SECURITY_VERSION = SECURITY_VERSION + 1', $writes[2][0]);
        $audit = json_decode($writes[3][1]['details'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['shared.note:read'], $audit['grants']);
        self::assertSame([['OBJECT_CODE' => 'shared.note', 'OPERATION' => 'update']], $audit['before']);
    }
}

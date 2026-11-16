<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\AuthService;
use MiniErp\shared\security\CompanyBootstrap;
use PHPUnit\Framework\TestCase;

final class CompanyAuthTest extends TestCase
{
    public function testWrongCompanyCannotAuthenticate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('query')->with(
            self::stringContains('ME_COMPANY'), self::callback(fn ($p) => $p['company'] === 'WRONG'),
        )->willReturn([]);
        $this->expectException(AppError::class);
        (new AuthService($db))->authenticate('buyer', 'fixture-password', 'WRONG');
    }

    public function testRevokedSessionVersionCannotLoadActor(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'ID' => 'u', 'USERNAME' => 'user', 'IS_ADMIN' => 0, 'SECURITY_VERSION' => 2,
            'MUST_CHANGE_PASSWORD' => 0, 'COMPANY_NAME' => 'SIA-MINI-ERP',
        ]]);
        $this->expectException(AppError::class);
        (new AuthService($db))->actor('u', 1, 1);
    }

    public function testBootstrapIsIdempotentAndDoesNotReplacePassword(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls([['ID' => 1]], [['ID' => 'admin', 'IS_ADMIN' => 1, 'ACTIVE' => 1]]);
        $db->expects(self::never())->method('execute');
        self::assertFalse((new CompanyBootstrap($db))->run('admin', 'fixture-password'));
    }

    public function testBootstrapPasswordIsRequiredBeforeAnyDatabaseWrite(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('begin');
        $this->expectException(AppError::class);
        (new CompanyBootstrap($db))->run('admin', '');
    }

    public function testBootstrapCreatesHashedTemporaryAdminAndNote(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls([['ID' => 1]], [], [], [], []);
        $writes = [];
        $db->method('execute')->willReturnCallback(function (string $sql, array $params) use (&$writes): int {
            $writes[] = [$sql, $params];
            return 1;
        });
        $db->expects(self::once())->method('commit');
        self::assertTrue((new CompanyBootstrap($db))->run('admin', 'fixture-password'));
        self::assertCount(3, $writes);
        self::assertTrue(password_verify('fixture-password', $writes[0][1]['hash']));
        self::assertStringContainsString('IS_ADMIN, MUST_CHANGE_PASSWORD', $writes[0][0]);
        self::assertSame(1, $writes[0][1]['must_change']);
        self::assertStringContainsString('INSERT INTO ME_NOTES', $writes[2][0]);
    }

    public function testDefaultBootstrapCreatesAdminWithoutForcedPasswordChange(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls([['ID' => 1]], [], [], [], []);
        $writes = [];
        $db->method('execute')->willReturnCallback(function (string $sql, array $params) use (&$writes): int {
            $writes[] = [$sql, $params];
            return 1;
        });
        self::assertTrue((new CompanyBootstrap($db))->run());
        self::assertSame('admin', $writes[0][1]['username']);
        self::assertTrue(password_verify('admin', $writes[0][1]['hash']));
        self::assertSame(0, $writes[0][1]['must_change']);
    }
}

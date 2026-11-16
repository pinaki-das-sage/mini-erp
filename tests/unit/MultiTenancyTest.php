<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\shared\ActionExecutor;
use MiniErp\shared\AppError;
use MiniErp\shared\Config;
use MiniErp\shared\Kernel;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\AuthService;
use MiniErp\shared\security\CompanyBootstrap;
use MiniErp\shared\security\UserService;
use PHPUnit\Framework\TestCase;

final class MultiTenancyTest extends TestCase
{
    public function testCompanyCreationCommitsCompanyAndItsAdministratorTogether(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static function (string $sql): array {
            return str_contains($sql, 'NEXTVAL') ? [['ID' => 2]] : [];
        });
        $writes = [];
        $db->method('execute')->willReturnCallback(static function (string $sql, array $parameters) use (&$writes): int {
            $writes[] = [$sql, $parameters];
            return 1;
        });
        $db->expects(self::once())->method('commit');
        self::assertSame(2, (new CompanyBootstrap($db))->create('second', 'Second company'));
        self::assertSame('SECOND', $writes[0][1]['code']);
        $user = array_values(array_filter($writes, static fn ($w) =>
            str_contains($w[0], 'INSERT INTO ME_USERS') && $w[1]['username'] === 'admin'))[0];
        self::assertSame(2, $user[1]['company_id']);
        self::assertSame('admin', $user[1]['username']);
        self::assertTrue(password_verify('admin', $user[1]['hash']));
        self::assertStringContainsString('IS_ADMIN', $user[0]);
        $users = array_values(array_filter($writes, static fn ($w) => str_starts_with($w[0], 'INSERT INTO ME_USERS')));
        self::assertCount(11, $users);
        foreach ($users as [, $parameters]) {
            self::assertTrue(password_verify($parameters['username'], $parameters['hash']));
        }
        foreach (array_slice($writes, 1) as [, $parameters]) {
            self::assertSame(2, $parameters['company_id']);
        }
    }

    public function testFailedAdministratorCreationRollsBackTheNewCompany(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static fn (string $sql): array => str_contains($sql, 'NEXTVAL') ? [['ID' => 2]] : []);
        $db->method('execute')->willReturnCallback(static function (string $sql, array $parameters): int {
            if (str_contains($sql, 'INSERT INTO ME_USERS') && $parameters['username'] === 'admin') {
                throw new DatabaseError(12541);
            }
            return 1;
        });
        $db->expects(self::once())->method('rollback');
        $db->expects(self::never())->method('commit');
        $this->expectException(DatabaseError::class);
        (new CompanyBootstrap($db))->create('SECOND', 'Second company');
    }

    public function testFailedTrainingGrantRollsBackCompanyAndAllUsers(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static fn (string $sql): array => str_contains($sql, 'NEXTVAL') ? [['ID' => 2]] : []);
        $db->method('execute')->willReturnCallback(static function (string $sql): int {
            if (str_contains($sql, 'INSERT INTO ME_USER_PERMISSIONS')) {
                throw new DatabaseError(2291);
            }
            return 1;
        });
        $db->expects(self::once())->method('rollback');
        $db->expects(self::never())->method('commit');
        $this->expectException(DatabaseError::class);
        (new CompanyBootstrap($db))->create('SECOND', 'Second company');
    }

    public function testAdministratorNameCannotReplaceATrainingAccount(): void
    {
        $db = $this->createMock(Connection::class);
        $users = [];
        $db->method('query')->willReturnCallback(static function (string $sql, array $parameters) use (&$users): array {
            if (str_contains($sql, 'NEXTVAL')) {
                return [['ID' => 2]];
            }
            if (str_contains($sql, 'ME_USERS WHERE USERNAME')) {
                return isset($users[$parameters['username']]) ? [$users[$parameters['username']]] : [];
            }
            return [];
        });
        $db->method('execute')->willReturnCallback(static function (string $sql, array $parameters) use (&$users): int {
            if (str_starts_with($sql, 'INSERT INTO ME_USERS')) {
                $users[$parameters['username']] = ['ID' => $parameters['id'], 'ACTIVE' => 1,
                    'IS_ADMIN' => str_contains($sql, 'IS_ADMIN') ? 1 : 0];
            }
            return 1;
        });
        $db->expects(self::once())->method('rollback');
        $db->expects(self::never())->method('commit');
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('non-admin account');
        (new CompanyBootstrap($db))->create('SECOND', 'Second company', 'buyer', 'custom-admin-password');
    }

    public function testLoginAssociatesTheUserWithTheEnteredCompany(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static function (string $sql, array $parameters): array {
            if (str_contains($sql, 'ME_USER_ROLES')) {
                self::assertSame(2, $parameters['company_id']);
                return [['ROLE_CODE' => 'trainee']];
            }
            self::assertStringContainsString('U.COMPANY_ID = C.ID', $sql);
            self::assertSame('SECOND', $parameters['company']);
            return [['ID' => 'second-admin', 'USERNAME' => 'admin', 'PASSWORD_HASH' => password_hash('admin', PASSWORD_DEFAULT),
                'IS_ADMIN' => 1, 'SECURITY_VERSION' => 1, 'MUST_CHANGE_PASSWORD' => 0,
                'COMPANY_ID' => 2, 'COMPANY_NAME' => 'Second company']];
        });
        $actor = (new AuthService($db))->authenticate('admin', 'admin', 'second');
        self::assertSame(2, $actor->companyId);
        self::assertSame('Second company', $actor->companyName);
    }

    public function testAdministratorCannotReadAnotherCompanyUser(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static function (string $sql, array $parameters): array {
            if (str_contains($sql, 'ME_USERS') && str_contains($sql, 'COMPANY_ID = :company_id') && ($parameters['company_id'] ?? null) === 1) {
                return [];
            }
            return [['ID' => 'foreign-user', 'USERNAME' => 'other', 'DISPLAY_NAME' => 'Other company', 'IS_ADMIN' => 0, 'ACTIVE' => 1, 'VERSION' => 1]];
        });
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('User not found.');
        (new UserService($db))->detail(new Actor('a', 'admin', [], 1, true), 'foreign-user');
    }

    public function testActionReferencesHaveIndependentCompanyNamespaces(): void
    {
        $db = $this->createMock(Connection::class);
        $claimed = [];
        $db->method('execute')->willReturnCallback(static function (string $sql, array $parameters) use (&$claimed): int {
            self::assertArrayHasKey('company_id', $parameters);
            if (str_starts_with($sql, 'INSERT INTO ME_ACTIONS')) {
                $key = $parameters['company_id'] . ':' . $parameters['reference'];
                if (isset($claimed[$key])) {
                    throw new DatabaseError(1);
                }
                $claimed[$key] = true;
            }
            return 1;
        });
        $executor = new ActionExecutor(new TransactionManager($db));
        foreach ([1, 2] as $companyId) {
            $actor = new Actor('user-' . $companyId, 'buyer', ['trainee'], companyId: $companyId);
            self::assertSame(['company' => $companyId], $executor->run($actor, 'same-reference', [],
                static fn () => ['company' => $companyId]));
        }
        self::assertCount(2, $claimed);
    }

    public function testLegacySessionWithoutCompanyIsExpiredBeforeAccess(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        $session = ['actor_id' => 'a', 'security_version' => 1];
        $response = (new Kernel(Config::fromArray([]), $session, $db))->handle('GET', '/settings/users', []);
        self::assertSame(403, $response['status']);
        self::assertStringContainsString('SESSION_EXPIRED', $response['body']);
        self::assertSame([], $session);
    }

    public function testUserCannotBeReloadedUnderAnotherCompany(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('query')->with(
            self::stringContains('U.COMPANY_ID = :company_id'), ['id' => 'first-company-user', 'company_id' => 2],
        )->willReturn([]);
        $this->expectException(AppError::class);
        (new AuthService($db))->actor('first-company-user', 2, 1);
    }

    public function testLastAdministratorCheckIsScopedToItsCompany(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static function (string $sql, array $parameters): array {
            self::assertSame(2, $parameters['company_id']);
            if (str_contains($sql, 'COUNT(*)')) {
                self::assertStringContainsString('COMPANY_ID = :company_id', $sql);
                return [['TOTAL' => 1]];
            }
            return [['ID' => 'second-admin', 'ACTIVE' => 1, 'IS_ADMIN' => 1, 'SECURITY_VERSION' => 1,
                'MUST_CHANGE_PASSWORD' => 0, 'VERSION' => 1]];
        });
        $db->expects(self::never())->method('execute');
        $db->expects(self::once())->method('rollback');
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('last active administrator');
        (new UserService($db))->update(new Actor('second-admin', 'admin', [], 2, true),
            'second-admin', 'Administrator', false, false, 1);
    }

    public function testInvalidCompanyCodeFailsBeforeStartingATransaction(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('begin');
        $this->expectException(AppError::class);
        (new CompanyBootstrap($db))->create('../OTHER', 'Other company');
    }

    public function testDuplicateCompanyCreationDoesNotOverwriteItsAdministrator(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static fn (string $sql): array =>
            str_contains($sql, 'NEXTVAL') ? [['ID' => 3]] : [['ID' => 2]]);
        $db->expects(self::once())->method('execute')->willThrowException(new DatabaseError(1));
        $db->expects(self::once())->method('rollback');
        $db->expects(self::never())->method('commit');
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('Existing accounts were not changed');
        (new CompanyBootstrap($db))->create('SECOND', 'Second company');
    }
}

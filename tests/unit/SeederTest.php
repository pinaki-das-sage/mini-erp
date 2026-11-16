<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\shared\Seeder;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SeederTest extends TestCase
{
    #[DataProvider('passwordModes')]
    public function testCreatesRoleAccountsWithExpectedPasswords(?string $password): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static function (string $sql): array {
            return str_contains($sql, 'ME_COMPANY') ? [['ID' => 1]] : [];
        });
        $users = [];
        $grants = [];
        $db->method('execute')->willReturnCallback(function (string $sql, array $params) use (&$users, &$grants): int {
            if (str_starts_with($sql, 'INSERT INTO ME_USERS')) {
                $users[$params['username']] = $params['hash'];
            }
            if (str_contains($sql, 'ME_USER_PERMISSIONS')) {
                $grants[$params['id']][] = $params['operation'];
            }
            return 1;
        });
        $db->expects(self::once())->method('commit');
        $seeder = new Seeder($db, new TransactionManager($db));
        $created = $password === null ? $seeder->bootstrap() : $seeder->seed($password);
        self::assertSame(['buyer', 'approver', 'receiver', 'inventory', 'sales', 'dispatcher',
            'driver1', 'driver2', 'warehouse', 'supervisor'], $created);
        foreach ($created as $username) {
            self::assertTrue(password_verify($password ?? $username, $users[$username]));
            self::assertSame(['read', 'update'], $grants['lab-' . $username]);
        }
    }

    public static function passwordModes(): array
    {
        return [[null], ['shared-fixture-password']];
    }

    public function testCompanyUsersShareTheCallerTransactionAndHaveDistinctIdsAcrossTenants(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $users = [];
        $roles = [];
        $grants = [];
        $db->method('execute')->willReturnCallback(static function (string $sql, array $params) use (&$users, &$roles, &$grants): int {
            if (str_starts_with($sql, 'INSERT INTO ME_USERS')) {
                $users[$params['company_id']][$params['username']] = $params;
            } elseif (str_starts_with($sql, 'INSERT INTO ME_USER_ROLES')) {
                $roles[$params['id']][] = $params['role'];
            } elseif (str_starts_with($sql, 'INSERT INTO ME_USER_PERMISSIONS')) {
                $grants[$params['id']][] = $params['operation'];
            }
            return 1;
        });
        $db->expects(self::exactly(2))->method('begin');
        $db->expects(self::exactly(2))->method('commit');
        $manager = new TransactionManager($db);
        $seeder = new Seeder($db, $manager);
        foreach ([2, 3] as $companyId) {
            $created = $manager->run(fn ($tx) => $seeder->seedCompanyUsers($tx, $companyId));
            self::assertCount(10, $created);
            self::assertSame(['trainee', 'purchase_approver'], $roles[$users[$companyId]['approver']['id']]);
            foreach ($users[$companyId] as $username => $user) {
                self::assertTrue(password_verify($username, $user['hash']));
                self::assertSame(['read', 'update'], $grants[$user['id']]);
                self::assertNotSame('lab-' . $username, $user['id']);
            }
        }
        self::assertCount(20, array_unique(array_merge(
            array_column($users[2], 'id'), array_column($users[3], 'id'),
        )));
    }

    public function testBootstrapWithoutCompanyRollsBackBeforeCreatingUsers(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $db->expects(self::never())->method('execute');
        $db->expects(self::never())->method('commit');
        $db->expects(self::once())->method('rollback');
        $this->expectException(AppError::class);
        (new Seeder($db, new TransactionManager($db)))->bootstrap();
    }

    public function testBootstrapAddsMissingUsersWithoutReplacingExistingPasswordsOrGrants(): void
    {
        $db = $this->createMock(Connection::class);
        $users = ['buyer' => password_hash('existing-private-password', PASSWORD_DEFAULT)];
        $before = $users['buyer'];
        $writes = [];
        $db->method('query')->willReturnCallback(function (string $sql, array $params) use (&$users): array {
            if (str_contains($sql, 'ME_COMPANY')) {
                return [['ID' => 1]];
            }
            if (str_contains($sql, 'ME_USERS')) {
                return isset($users[$params['username']]) ? [['ID' => 'lab-' . $params['username']]] : [];
            }
            return [['ID' => 'existing']];
        });
        $db->method('execute')->willReturnCallback(function (string $sql, array $params) use (&$users, &$writes): int {
            $writes[] = [$sql, $params];
            if (str_starts_with($sql, 'INSERT INTO ME_USERS')) {
                $users[$params['username']] = $params['hash'];
            }
            return 1;
        });
        $seeder = new Seeder($db, new TransactionManager($db));
        self::assertCount(9, $seeder->bootstrap());
        $writeCount = count($writes);
        self::assertSame([], $seeder->bootstrap());
        self::assertCount($writeCount, $writes);
        self::assertSame($before, $users['buyer']);
        self::assertFalse((bool) array_filter($writes, static fn (array $write): bool => ($write[1]['id'] ?? null) === 'lab-buyer'));
    }
}

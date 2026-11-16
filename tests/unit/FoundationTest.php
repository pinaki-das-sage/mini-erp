<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\shared\AppError;
use MiniErp\shared\Config;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\OciConnection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\AuthService;
use MiniErp\shared\security\Security;
use PHPUnit\Framework\TestCase;

final class FoundationTest extends TestCase
{
    public function testConfigurationDoesNotPretendOracleIsConfigured(): void
    {
        $config = Config::fromArray([]);
        self::assertSame('standalone', $config->mode);
        $this->expectException(AppError::class);
        $config->oracle();
    }

    public function testConfigurationRejectsUnknownMode(): void
    {
        $this->expectException(AppError::class);
        Config::fromArray(['APP_MODE' => 'silent-fallback']);
    }

    public function testOciConnectionFailsExplicitlyWithoutConfiguration(): void
    {
        $this->expectException(AppError::class);
        OciConnection::connect(Config::fromArray([]));
    }

    public function testDatabaseErrorDoesNotExposeSqlOrCredentials(): void
    {
        $error = new DatabaseError(1);
        self::assertSame(1, $error->oracleCode);
        self::assertSame('Oracle operation failed.', $error->getMessage());
    }

    public function testTransactionCommitsOnceAndExpiresContext(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('begin');
        $db->expects(self::once())->method('commit');
        $db->expects(self::never())->method('rollback');
        $db->expects(self::once())->method('execute')->willReturn(1);
        $manager = new TransactionManager($db);
        $saved = null;
        $result = $manager->run(function (TransactionContext $tx) use (&$saved): int {
            $saved = $tx;
            return $tx->execute('UPDATE neutral SET value = :value', ['value' => 'new']);
        });
        self::assertSame(1, $result);
        self::assertInstanceOf(TransactionContext::class, $saved);
        $this->expectException(\LogicException::class);
        $saved->query('SELECT 1 FROM DUAL');
    }

    public function testBusinessFailureRollsBack(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('begin');
        $db->expects(self::never())->method('commit');
        $db->expects(self::once())->method('rollback');
        $manager = new TransactionManager($db);
        $this->expectException(AppError::class);
        $manager->run(function (TransactionContext $tx): never {
            throw new AppError('INVALID_STATE', 'Operation rejected.', 409);
        });
    }

    public function testNestedTransactionCannotCommitOuterWork(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('begin');
        $db->expects(self::never())->method('commit');
        $db->expects(self::once())->method('rollback');
        $manager = new TransactionManager($db);
        $this->expectException(\LogicException::class);
        $manager->run(fn () => $manager->run(fn () => 1));
    }

    public function testContextQueryUsesSameConnection(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('query')->with('SELECT :value FROM DUAL', ['value' => 1])
            ->willReturn([['VALUE' => 1]]);
        $manager = new TransactionManager($db);
        self::assertSame([['VALUE' => 1]], $manager->run(
            fn (TransactionContext $tx) => $tx->query('SELECT :value FROM DUAL', ['value' => 1])
        ));
    }

    public function testActorRequiresExplicitPermission(): void
    {
        $actor = new Actor('user-1', 'buyer', ['buyer'], 1);
        self::assertSame('user-1', $actor->id);
        $this->expectException(AppError::class);
        $actor->requireRole('purchase_approver');
    }

    public function testAuthenticationVerifiesStoredHashAndRoles(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['ID' => 'user-1', 'USERNAME' => 'buyer', 'PASSWORD_HASH' => password_hash('test-password', PASSWORD_DEFAULT),
                'IS_ADMIN' => 0, 'SECURITY_VERSION' => 1, 'MUST_CHANGE_PASSWORD' => 0, 'COMPANY_NAME' => 'SIA-MINI-ERP', 'COMPANY_ID' => 1]],
            [['ROLE_CODE' => 'buyer'], ['ROLE_CODE' => 'trainee']],
            [['ID' => 'user-1', 'USERNAME' => 'buyer', 'IS_ADMIN' => 0, 'SECURITY_VERSION' => 1, 'MUST_CHANGE_PASSWORD' => 0, 'COMPANY_NAME' => 'SIA-MINI-ERP', 'COMPANY_ID' => 1]],
            [['ROLE_CODE' => 'buyer'], ['ROLE_CODE' => 'trainee']]
        );
        $auth = new AuthService($db);
        $actor = $auth->authenticate('buyer', 'test-password', 'SIA-MINI-ERP');
        self::assertSame('user-1', $actor->id);
        $actor->requireRole('trainee');
        self::assertSame('buyer', $auth->actor('user-1', 1)->username);
    }

    public function testWrongPasswordDoesNotAuthenticate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['ID' => 'u', 'USERNAME' => 'buyer', 'PASSWORD_HASH' => password_hash('correct', PASSWORD_DEFAULT)]
        ]);
        $this->expectException(AppError::class);
        (new AuthService($db))->authenticate('buyer', 'wrong', 'SIA-MINI-ERP');
    }

    public function testCsrfAndEscapingProtectWritesAndOutput(): void
    {
        $session = [];
        $token = Security::csrfToken($session);
        self::assertSame($token, Security::csrfToken($session));
        Security::verifyCsrf($session, $token);
        self::assertSame('&lt;script&gt;&quot;&#039;&amp;', Security::escape('<script>"\'&'));
        $this->expectException(AppError::class);
        Security::verifyCsrf($session, 'incorrect');
    }

    public function testIniTemplateShapeAndEnvironmentOverridesAreSupported(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mini-erp-config-');
        file_put_contents($file, "[oracle]\nhost = localhost\nport = 1521\nservice_name = LAB\nusername = test_user\npassword = fixture-only\n");
        try {
            $config = Config::fromIni($file, ['ORACLE_DB_USER' => 'override_user']);
            self::assertSame([
                'username' => 'override_user', 'password' => 'fixture-only', 'connection' => '//localhost:1521/LAB',
            ], $config->oracle());
        } finally {
            unlink($file);
        }
    }

    public function testIniInvalidPortFailsBeforeConnecting(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mini-erp-config-');
        file_put_contents($file, "[oracle]\nhost = localhost\nport = 65536\nservice_name = LAB\nusername = test_user\npassword = fixture-only\n");
        try {
            $this->expectException(AppError::class);
            Config::fromIni($file)->oracle();
        } finally {
            unlink($file);
        }
    }

    public function testCommitFailureTriggersRollback(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('commit')->willThrowException(new DatabaseError(3113));
        $db->expects(self::once())->method('rollback');
        $this->expectException(DatabaseError::class);
        (new TransactionManager($db))->run(fn () => 'result');
    }

    public function testLintRejectsUppercaseProjectFolders(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['/src/UppercaseProbe', '/UppercaseProbe'] as $relative) {
            $probe = $root . $relative;
            self::assertFalse(file_exists($probe), 'The temporary folder probe must not already exist.');
            mkdir($probe);
            try {
                $process = proc_open([PHP_BINARY, $root . '/bin/lint.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(1, proc_close($process), $output);
                self::assertStringContainsString('lowercase', $output);
            } finally {
                rmdir($probe);
            }
        }
    }
}

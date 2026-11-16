<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsoleTest extends TestCase
{
    public function testMigrateUsesIniAndRunsWithoutSchemaArgument(): void
    {
        [$status, $output, $error, $writes] = $this->runConsole('success');
        self::assertSame(0, $status, $error);
        self::assertStringContainsString('Oracle connection OK. Schema: FIXTURE_SCHEMA', $output);
        self::assertStringContainsString('Applied: 001_foundation.php, 002_company_permissions.php', $output);
        self::assertStringContainsString('CREATE TABLE ME_COMPANY', $writes);
        self::assertStringContainsString("SET STATUS = 'APPLIED'", $writes);
    }

    #[DataProvider('connectionFailures')]
    public function testFailedPreflightErrorsWithoutMigrationWrites(string $mode, string $message): void
    {
        [$status, $output, $error, $writes] = $this->runConsole($mode);
        self::assertSame(1, $status);
        self::assertStringContainsString($message, $error);
        self::assertStringNotContainsString('Applied:', $output);
        self::assertSame('', $writes);
    }

    public static function connectionFailures(): array
    {
        return [
            ['connect_failure', 'Oracle operation failed (code 12541)'],
            ['query_failure', 'Oracle operation failed (code 3113)'],
            ['schema_mismatch', 'FORBIDDEN'],
            ['missing_schema', 'Could not verify the connected Oracle schema.'],
        ];
    }

    public function testOptionalSchemaArgumentStillRejectsWrongTarget(): void
    {
        [$status, , $error, $writes] = $this->runConsole('success', 'migrate', '--confirm-schema=WRONG');
        self::assertSame(1, $status);
        self::assertStringContainsString('FORBIDDEN', $error);
        self::assertSame('', $writes);
    }

    public function testBootstrapUsesDefaultCredentialsWithoutConfigurationOrSchemaArgument(): void
    {
        [$status, $output, $error, $writes] = $this->runConsole('success', 'bootstrap');
        self::assertSame(0, $status, $error);
        self::assertStringContainsString('Administrator created.', $output);
        self::assertStringContainsString('INSERT INTO ME_USERS', $writes);
        self::assertStringContainsString('Created lab accounts: buyer, approver, receiver, inventory, sales, dispatcher, driver1, driver2, warehouse, supervisor', $output);
    }

    public function testBootstrapAddsLabAccountsWhenAdministratorAlreadyExists(): void
    {
        [$status, $output, $error] = $this->runConsole('existing_admin', 'bootstrap');
        self::assertSame(0, $status, $error);
        self::assertStringContainsString('Administrator exists; password unchanged.', $output);
        self::assertStringContainsString('Created lab accounts: buyer, approver', $output);
    }

    public function testCompanyCreateUsesConfiguredSchemaAndAutomaticallyCreatesAdministrator(): void
    {
        [$status, $output, $error, $writes] = $this->runConsole('success', 'company:create', 'SECOND', 'Second company');
        self::assertSame(0, $status, $error);
        self::assertStringContainsString('Company SECOND created', $output);
        self::assertStringContainsString('INSERT INTO ME_COMPANY', $writes);
        self::assertStringContainsString('INSERT INTO ME_USERS', $writes);
        self::assertStringNotContainsString('Created lab accounts:', $output);
        self::assertSame(11, substr_count($writes, 'INSERT INTO ME_USERS'));
    }

    public function testAddCompanyAcceptsOneNameAndCreatesAllAccounts(): void
    {
        [$status, $output, $error, $writes] = $this->runConsole('success', 'addcompany', 'New Training Company');
        self::assertSame(0, $status, $error);
        self::assertStringContainsString('Company NEW-TRAINING-COMPANY created', $output);
        self::assertSame(11, substr_count($writes, 'INSERT INTO ME_USERS'));
        self::assertSame(20, substr_count($writes, 'INSERT INTO ME_USER_PERMISSIONS'));
    }

    public function testAddCompanyWithoutNameFailsWithoutDatabaseWrites(): void
    {
        [$status, , $error, $writes] = $this->runConsole('success', 'addcompany');
        self::assertSame(1, $status);
        self::assertStringContainsString('Usage:', $error);
        self::assertSame('', $writes);
    }

    public function testAddCompanyRetainsSchemaPreflight(): void
    {
        [$status, , $error, $writes] = $this->runConsole('schema_mismatch', 'addcompany', 'SECOND');
        self::assertSame(1, $status);
        self::assertStringContainsString('FORBIDDEN', $error);
        self::assertSame('', $writes);
    }

    private function runConsole(string $mode, string $command = 'migrate', string ...$arguments): array
    {
        $ini = tempnam(sys_get_temp_dir(), 'mini-erp-console-');
        self::assertNotFalse($ini);
        file_put_contents($ini, "[oracle]\nhost=fixture-host\nservice_name=fixture-service\nusername=fixture_schema\npassword=fixture-password\n");
        try {
            $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/ConsoleConnection.php', $mode, $ini, $command, ...$arguments],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($process), $output, $error, is_file($ini . '.writes') ? file_get_contents($ini . '.writes') : ''];
        } finally {
            unlink($ini);
            if (is_file($ini . '.writes')) {
                unlink($ini . '.writes');
            }
        }
    }
}

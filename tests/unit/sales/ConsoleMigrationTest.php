<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsoleMigrationTest extends TestCase
{
    public function testSalesAppliesSharedBeforePermissionsAndCustomers(): void
    {
        [$status, $output, $error, $writes] = $this->runConsole('success', '--module=sales', '--confirm-schema=FIXTURE_SCHEMA');
        self::assertSame(0, $status, $error);
        self::assertStringContainsString('Applied sales migrations: 001_oe_permissions.php, 002_oe_customers.php, 003_oe_document_actions.php', $output);
        $foundation = strpos($writes, 'CREATE TABLE ME_USERS');
        $permissions = strpos($writes, "VALUES ('sales.customers'");
        $customers = strpos($writes, 'CREATE TABLE ME_OE_CUSTOMERS');
        self::assertNotFalse($foundation);
        self::assertNotFalse($permissions);
        self::assertNotFalse($customers);
        self::assertLessThan($permissions, $foundation);
        self::assertLessThan($customers, $permissions);
        $lifecycle = strpos($writes, "VALUES ('sales.quotes', 'submit'");
        self::assertNotFalse($lifecycle);
        self::assertLessThan($lifecycle, $customers);
        $upgrade = strpos($writes, 'ALTER TABLE ME_OE_CUSTOMERS RENAME COLUMN ID TO RECORD#');
        $documents = strpos($writes, 'CREATE TABLE ME_OE_DOCUMENTS');
        $lines = strpos($writes, 'CREATE TABLE ME_OE_DOCUMENT_LINES');
        self::assertNotFalse($upgrade);
        self::assertNotFalse($documents);
        self::assertNotFalse($lines);
        self::assertLessThan($upgrade, $lifecycle);
        self::assertLessThan($documents, $upgrade);
        self::assertLessThan($lines, $documents);
        self::assertSame(1, substr_count($writes, 'CREATE TABLE ME_MIGRATIONS'));
    }

    public function testExistingCustomerInstallAppliesOnlyPendingSalesMigrations(): void
    {
        [$status, $output, $error, $writes] = $this->runConsole('sales_base_applied', '--module=sales');
        self::assertSame(0, $status, $error);
        self::assertStringContainsString('No pending shared migrations.', $output);
        self::assertStringContainsString('Applied sales migrations: 003_oe_document_actions.php, 004_oe_customer_schema_update.php, 005_oe_documents.php, 006_oe_document_lines.php', $output);
        self::assertStringNotContainsString('CREATE TABLE ME_OE_CUSTOMERS', $writes);
        self::assertStringNotContainsString('DROP TABLE', $writes);
        self::assertStringContainsString('ALTER TABLE ME_OE_CUSTOMERS RENAME COLUMN ID TO RECORD#', $writes);
        self::assertStringContainsString('CREATE TABLE ME_OE_DOCUMENTS', $writes);
        self::assertStringContainsString('CREATE TABLE ME_OE_DOCUMENT_LINES', $writes);
        self::assertStringContainsString('CREATE SEQUENCE ME_OE_QUOTE_NO_SEQ', $writes);
        self::assertStringContainsString('CREATE TABLE ME_OE_SOURCE_LINKS', $writes);
        self::assertStringContainsString('CREATE TABLE ME_OE_PAYMENTS', $writes);
        self::assertStringContainsString('CREATE SEQUENCE ME_OE_ORDER_NO_SEQ', $writes);
        self::assertStringContainsString('CREATE SEQUENCE ME_OE_INVOICE_NO_SEQ', $writes);
        self::assertStringContainsString('CREATE SEQUENCE ME_OE_PAYMENT_NO_SEQ', $writes);
        self::assertStringContainsString('011_oe_customer_code_sequence.php', $output);
        self::assertStringContainsString('CREATE SEQUENCE ME_OE_CUST_CODE_SEQ START WITH ', $writes);
    }

    public function testCustomerUpgradeFailureStopsDependentTables(): void
    {
        [$status, $output, $error, $writes] = $this->runConsole('sales_upgrade_ddl_failure', '--module=sales');
        self::assertSame(1, $status);
        self::assertStringContainsString('Oracle operation failed (code 2266)', $error);
        self::assertStringNotContainsString('Applied sales migrations:', $output);
        self::assertStringNotContainsString('CREATE TABLE ME_OE_DOCUMENTS', $writes);
        self::assertStringNotContainsString('CREATE TABLE ME_OE_DOCUMENT_LINES', $writes);
    }

    public function testDefaultAndExplicitSharedDoNotApplySales(): void
    {
        foreach ([[], ['--module=shared']] as $arguments) {
            [$status, $output, $error, $writes] = $this->runConsole('success', ...$arguments);
            self::assertSame(0, $status, $error);
            self::assertStringContainsString('Applied: 001_foundation.php', $output);
            self::assertStringNotContainsString('ME_OE_CUSTOMERS', $writes);
            self::assertStringNotContainsString("VALUES ('sales.customers'", $writes);
        }
    }

    public function testAlreadyAppliedComponentsDoNotWriteAgain(): void
    {
        [$status, $output, $error, $writes] = $this->runConsole('migrations_applied', '--module=sales');
        self::assertSame(0, $status, $error);
        self::assertStringContainsString('No pending shared migrations.', $output);
        self::assertStringContainsString('No pending sales migrations.', $output);
        self::assertSame('', $writes);
    }

    #[DataProvider('blockedMigrations')]
    public function testRejectedTargetOrSharedFailureCannotStartSales(string $mode, array $arguments, string $message): void
    {
        [$status, $output, $error, $writes] = $this->runConsole($mode, ...$arguments);
        self::assertSame(1, $status);
        self::assertStringContainsString($message, $error);
        self::assertStringNotContainsString('Applied sales migrations:', $output);
        self::assertStringNotContainsString("VALUES ('sales.customers'", $writes);
        self::assertStringNotContainsString('ME_OE_CUSTOMERS', $writes);
        if ($mode !== 'shared_ddl_failure' && $mode !== 'shared_incomplete') {
            self::assertSame('', $writes);
        }
    }

    public static function blockedMigrations(): array
    {
        return [
            ['success', ['--module=inventory'], 'Migration module must be shared or sales.'],
            ['success', ['--module=../sales'], 'Migration module must be shared or sales.'],
            ['success', ['--module='], 'Migration module must be shared or sales.'],
            ['success', ['--module=sales', '--module=shared'], 'Usage: migrate'],
            ['success', ['--modul=sales'], 'Usage: migrate'],
            ['success', ['--module=sales', '--confirm-schema=WRONG'], 'FORBIDDEN'],
            ['connect_failure', ['--module=sales'], 'Oracle operation failed (code 12541)'],
            ['query_failure', ['--module=sales'], 'Oracle operation failed (code 3113)'],
            ['shared_ddl_failure', ['--module=sales'], 'Oracle operation failed (code 955)'],
            ['shared_incomplete', ['--module=sales'], 'MIGRATION_ERROR'],
        ];
    }

    private function runConsole(string $mode, string ...$arguments): array
    {
        $ini = tempnam(sys_get_temp_dir(), 'mini-erp-sales-migrate-');
        self::assertNotFalse($ini);
        file_put_contents($ini, "[oracle]\nhost=fixture-host\nservice_name=fixture-service\nusername=fixture_schema\npassword=fixture-password\n");
        try {
            $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/ConsoleConnection.php', $mode, $ini, 'migrate', ...$arguments],
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

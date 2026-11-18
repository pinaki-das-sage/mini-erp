<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\shared\Config;
use MiniErp\shared\Kernel;
use MiniErp\shared\database\Connection;
use PHPUnit\Framework\TestCase;

final class LeadUiTest extends TestCase
{
    private array $grants = ['read', 'create', 'update', 'archive', 'restore'];
    private bool $archived = false;

    private function connection(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, array $params = []): array {
            if (str_contains($sql, 'JOIN ME_COMPANY')) {
                return [['ID' => 'u', 'USERNAME' => 'graduate', 'IS_ADMIN' => 0, 'SECURITY_VERSION' => 1,
                    'MUST_CHANGE_PASSWORD' => 0, 'COMPANY_NAME' => 'Lab', 'COMPANY_ID' => 7]];
            }
            if (str_contains($sql, 'ME_USER_ROLES')) {
                return [];
            }
            if (str_contains($sql, 'ME_OBJECT_ACTIONS')) {
                return array_map(static fn (string $action): array => ['OBJECT_CODE' => 'sales.leads', 'ACTION_CODE' => $action],
                    isset($params['operation']) ? [$params['operation']] : ['archive', 'restore']);
            }
            if (str_contains($sql, 'ME_OBJECTS')) {
                return [['CODE' => 'sales.leads', 'ACTIVE' => 1, 'ALLOW_CREATE' => 1, 'ALLOW_READ' => 1, 'ALLOW_UPDATE' => 1, 'ALLOW_DELETE' => 0]];
            }
            if (str_contains($sql, 'ME_USER_PERMISSIONS')) {
                if (isset($params['operation'])) {
                    return in_array($params['operation'], $this->grants, true) ? [['USER_ID' => 'u']] : [];
                }
                return array_map(static fn (string $operation): array => ['OBJECT_CODE' => 'sales.leads', 'OPERATION' => $operation], $this->grants);
            }
            if (str_contains($sql, 'ME_SAL_LEADS')) {
                self::assertSame(7, $params['company_id']);
                return [['ID' => str_repeat('a', 32), 'LEAD_REFERENCE' => 'LD-TEST', 'CONTACT_NAME' => '<Alex>',
                    'BUSINESS_NAME' => 'Example', 'EMAIL' => 'alex@example.test', 'PHONE' => null,
                    'SOURCE' => 'website', 'STATUS' => 'new', 'NOTES' => '<script>unsafe</script>',
                    'ARCHIVED' => $this->archived ? 1 : 0, 'VERSION' => 2, 'CREATED_BY' => 'u', 'UPDATED_BY' => 'u',
                    'CREATED_AT' => '2026-11-17T00:00:00Z', 'UPDATED_AT' => '2026-11-17T00:00:00Z']];
            }
            throw new \LogicException('Unexpected UI query: ' . $sql);
        });
        $db->method('execute')->willReturn(1);
        return $db;
    }

    public function testMountedListerDetailEditAndSalesNavigationRespectPermissions(): void
    {
        $session = ['actor_id' => 'u', 'security_version' => 1, 'company_id' => 7];
        $kernel = new Kernel(Config::fromArray(['APP_BASE_PATH' => '/training/public']), $session, $this->connection());
        $list = $kernel->handle('GET', '/training/public/sales/leads', []);
        self::assertSame(200, $list['status']);
        self::assertStringContainsString('href="/training/public/sales/leads/' . str_repeat('a', 32) . '"', $list['body']);
        self::assertStringContainsString('&lt;Alex&gt;', $list['body']);
        $detail = $kernel->handle('GET', '/training/public/sales/leads/' . str_repeat('a', 32), []);
        self::assertSame(200, $detail['status']);
        self::assertStringContainsString('&lt;script&gt;unsafe&lt;/script&gt;', $detail['body']);
        self::assertStringContainsString('Archive lead', $detail['body']);
        self::assertSame(200, $kernel->handle('GET', '/training/public/sales/leads/' . str_repeat('a', 32) . '/edit', [])['status']);
        self::assertSame(200, $kernel->handle('GET', '/training/public/sales/leads/new', [])['status']);
        self::assertStringContainsString('href="/training/public/sales/leads"', $kernel->handle('GET', '/training/public/modules/sales', [])['body']);
        $this->grants = ['read'];
        $detail = $kernel->handle('GET', '/training/public/sales/leads/' . str_repeat('a', 32), []);
        self::assertStringNotContainsString('Archive lead', $detail['body']);
        self::assertStringNotContainsString('Edit lead', $detail['body']);
        self::assertSame(403, $kernel->handle('GET', '/training/public/sales/leads/new', [])['status']);
        self::assertSame(403, $kernel->handle('GET', '/training/public/sales/leads/' . str_repeat('a', 32) . '/edit', [])['status']);
    }

    public function testArchivedDetailOffersRestoreButNotEditOrArchive(): void
    {
        $this->archived = true;
        $session = ['actor_id' => 'u', 'security_version' => 1, 'company_id' => 7];
        $kernel = new Kernel(Config::fromArray([]), $session, $this->connection());
        $detail = $kernel->handle('GET', '/sales/leads/' . str_repeat('a', 32), []);
        self::assertSame(200, $detail['status']);
        self::assertStringContainsString('Restore lead', $detail['body']);
        self::assertStringNotContainsString('Archive lead', $detail['body']);
        self::assertStringNotContainsString('Edit lead', $detail['body']);
    }

    public function testBrowserWritesRequireSessionCsrfAndSameBusinessPermissions(): void
    {
        $session = ['actor_id' => 'u', 'security_version' => 1, 'company_id' => 7, 'csrf_token' => 'fixture-csrf'];
        $db = $this->connection();
        $kernel = new Kernel(Config::fromArray([]), $session, $db);
        $input = ['operation' => 'create', 'contact_name' => 'Alex', 'email' => 'alex@example.test', 'action_reference' => 'ui-create'];
        self::assertSame(403, $kernel->handle('POST', '/api/sales/leads', $input)['status']);
        $headers = ['content-type' => 'application/json', 'x-csrf-token' => 'fixture-csrf'];
        self::assertSame(201, $kernel->handle('POST', '/api/sales/leads', $input, $headers)['status']);
        $this->grants = [];
        self::assertSame(403, $kernel->handle('POST', '/api/sales/leads', $input, $headers)['status']);
        $this->grants = ['create'];
        self::assertSame(200, $kernel->handle('GET', '/sales/leads/new', [])['status']);
        self::assertSame(403, $kernel->handle('GET', '/sales/leads', [])['status']);
        self::assertSame(415, $kernel->handle('POST', '/api/sales/leads', $input, ['x-csrf-token' => 'fixture-csrf'])['status']);
    }
}

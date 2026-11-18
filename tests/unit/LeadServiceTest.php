<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\sales\LeadService;
use MiniErp\shared\ActionExecutor;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LeadServiceTest extends TestCase
{
    private array $row;
    private array $writes = [];
    private array $grants = ['read', 'create', 'update', 'archive', 'restore'];
    private ?array $replay = null;
    private bool $failAudit = false;

    protected function setUp(): void
    {
        $this->row = [
            'ID' => str_repeat('a', 32), 'LEAD_REFERENCE' => 'LD-' . str_repeat('A', 32),
            'CONTACT_NAME' => 'Alex Smith', 'BUSINESS_NAME' => 'Example', 'EMAIL' => 'alex@example.test',
            'PHONE' => null, 'SOURCE' => 'website', 'STATUS' => 'new', 'NOTES' => null,
            'ARCHIVED' => 0, 'VERSION' => 2, 'CREATED_BY' => 'u', 'UPDATED_BY' => 'u',
            'CREATED_AT' => '2026-11-17T00:00:00Z', 'UPDATED_AT' => '2026-11-17T00:00:00Z',
        ];
    }

    private function actor(int $company = 7): Actor
    {
        return new Actor('u', 'graduate', [], $company);
    }

    private function connection(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, array $params = []): array {
            if (str_contains($sql, 'FROM ME_OBJECTS')) {
                return [['ACTIVE' => 1, 'ALLOW_CREATE' => 1, 'ALLOW_READ' => 1, 'ALLOW_UPDATE' => 1, 'ALLOW_DELETE' => 0]];
            }
            if (str_contains($sql, 'FROM ME_OBJECT_ACTIONS')) {
                return [['ACTION_CODE' => $params['operation']]];
            }
            if (str_contains($sql, 'FROM ME_USER_PERMISSIONS')) {
                return in_array($params['operation'], $this->grants, true) ? [['USER_ID' => 'u']] : [];
            }
            if (str_contains($sql, 'FROM ME_ACTIONS')) {
                return $this->replay === null ? [] : [$this->replay];
            }
            if (str_contains($sql, 'FROM ME_SAL_LEADS')) {
                self::assertStringContainsString('COMPANY_ID = :company_id', $sql);
                if ($params['company_id'] !== 7) {
                    return [];
                }
                if (isset($params['offset'])) {
                    self::assertSame(10, $params['offset']);
                    self::assertSame(10, $params['limit']);
                    self::assertSame('contacted', $params['status']);
                    self::assertSame('website', $params['source']);
                    self::assertSame(0, $params['archived']);
                    self::assertSame('%alex!%!_%', $params['search']);
                    self::assertStringContainsString('ORDER BY', $sql);
                }
                return $this->row === [] ? [] : [$this->row];
            }
            throw new \LogicException('Unexpected lead query: ' . $sql);
        });
        $db->method('execute')->willReturnCallback(function (string $sql, array $params): int {
            $this->writes[] = [$sql, $params];
            if ($params['company_id'] === 8) {
                self::assertStringStartsWith('INSERT INTO ME_ACTIONS', $sql);
            } else {
                self::assertSame(7, $params['company_id']);
            }
            if (str_starts_with($sql, 'INSERT INTO ME_ACTIONS') && $this->replay !== null) {
                throw new DatabaseError(1);
            }
            if (str_starts_with($sql, 'INSERT INTO ME_AUDIT') && $this->failAudit) {
                throw new DatabaseError(20001);
            }
            return 1;
        });
        return $db;
    }

    public function testCreateNormalizesOptionalFieldsAndAuditsWithoutTraineeRole(): void
    {
        $db = $this->connection();
        $db->expects(self::once())->method('commit');
        $lead = (new LeadService($db))->create($this->actor(), [
            'contact_name' => ' Alex Smith ', 'phone' => ' +44 00123 ', 'action_reference' => 'lead-create-1',
        ]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $lead['id']);
        self::assertSame('LD-' . strtoupper($lead['id']), $lead['lead_reference']);
        self::assertSame(1, $lead['current_version']);
        $insert = array_values(array_filter($this->writes, static fn (array $write): bool => str_starts_with($write[0], 'INSERT INTO ME_SAL_LEADS')))[0];
        self::assertSame('Alex Smith', $insert[1]['contact_name']);
        self::assertSame('+44 00123', $insert[1]['phone']);
        self::assertSame('new', $insert[1]['status']);
        self::assertSame('other', $insert[1]['source']);
        $audit = $this->writes[array_key_last($this->writes)];
        self::assertSame('LEAD_CREATE', $audit[1]['type']);
    }

    public function testDetailMapsNullsVersionsAndTenantBoundary(): void
    {
        $service = new LeadService($this->connection());
        $lead = $service->detail($this->actor(), str_repeat('a', 32));
        self::assertSame('', $lead['phone']);
        self::assertFalse($lead['archived']);
        self::assertSame(2, $lead['current_version']);
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('Lead not found.');
        $service->detail($this->actor(8), str_repeat('a', 32));
    }

    public function testListUsesBoundFiltersEscapesWildcardsAndPaginates(): void
    {
        $result = (new LeadService($this->connection()))->list($this->actor(), [
            'page' => '2', 'page_size' => '10', 'status' => 'contacted', 'source' => 'website', 'search' => 'alex%_',
        ]);
        self::assertSame(2, $result['page']);
        self::assertSame(10, $result['page_size']);
        self::assertCount(1, $result['items']);
    }

    public function testPatchPreservesOmittedFieldsAndIncrementsVersion(): void
    {
        $db = $this->connection();
        $db->expects(self::once())->method('commit');
        $result = (new LeadService($db))->update($this->actor(), str_repeat('a', 32), [
            'status' => 'qualified', 'notes' => 'Follow up', 'expected_version' => 2, 'action_reference' => 'lead-edit',
        ]);
        self::assertSame(3, $result['current_version']);
        $write = array_values(array_filter($this->writes, static fn (array $write): bool => str_starts_with($write[0], 'UPDATE ME_SAL_LEADS')))[0];
        self::assertSame('alex@example.test', $write[1]['email']);
        self::assertSame('qualified', $write[1]['status']);
        self::assertStringContainsString('VERSION = :version', $write[0]);
    }

    public function testArchiveAndRestoreAreSeparateVersionedActions(): void
    {
        $service = new LeadService($this->connection());
        self::assertSame(3, $service->archive($this->actor(), str_repeat('a', 32), [
            'expected_version' => 2, 'action_reference' => 'lead-archive',
        ])['current_version']);
        $this->row['ARCHIVED'] = 1;
        self::assertSame(3, $service->restore($this->actor(), str_repeat('a', 32), [
            'expected_version' => 2, 'action_reference' => 'lead-restore',
        ])['current_version']);
    }

    #[DataProvider('invalidCreates')]
    public function testInvalidCreateDoesNotStartTransaction(array $fields): void
    {
        $db = $this->connection();
        $db->expects(self::never())->method('begin');
        $this->expectException(AppError::class);
        (new LeadService($db))->create($this->actor(), $fields + ['action_reference' => 'invalid-create']);
    }

    public static function invalidCreates(): array
    {
        $valid = ['contact_name' => 'Alex', 'email' => 'alex@example.test'];
        return [
            [['email' => 'alex@example.test']],
            [['contact_name' => 'Alex']],
            [['email' => 'bad'] + $valid],
            [['source' => 'unknown'] + $valid],
            [['status' => 'unknown'] + $valid],
            [['contact_name' => str_repeat('a', 151)] + $valid],
            [['phone' => 123] + $valid],
            [['email' => null] + $valid],
            [['business_name' => null] + $valid],
            [['notes' => str_repeat('a', 1001)] + $valid],
            [['company_id' => 99] + $valid],
            [['contact_name' => str_repeat("\u{1F642}", 151)] + $valid],
        ];
    }

    public function testMaximumLengthsAndUnicodeAreAccepted(): void
    {
        $lead = (new LeadService($this->connection()))->create($this->actor(), [
            'contact_name' => str_repeat("\u{1F642}", 150), 'business_name' => str_repeat('b', 150),
            'phone' => str_repeat('0', 50), 'notes' => str_repeat("\u{1F642}", 1000), 'action_reference' => 'max-length',
        ]);
        self::assertSame(1, $lead['current_version']);
    }

    public function testPartialUpdateCannotClearTheOnlyContactMethod(): void
    {
        $db = $this->connection();
        $db->expects(self::once())->method('rollback');
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('at least one of email or phone');
        (new LeadService($db))->update($this->actor(), str_repeat('a', 32), [
            'email' => '', 'action_reference' => 'clear-contact', 'expected_version' => 2,
        ]);
    }

    public function testCompanyCannotMutateAnotherCompanyLead(): void
    {
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('Lead not found.');
        $db = $this->connection();
        $db->expects(self::once())->method('rollback');
        (new LeadService($db))->archive($this->actor(8), str_repeat('a', 32), [
            'expected_version' => 2, 'action_reference' => 'foreign-lead',
        ]);
    }

    #[DataProvider('rejectedMutations')]
    public function testRejectedMutationRollsBack(int $version, int $archived, string $operation, string $code): void
    {
        $this->row['ARCHIVED'] = $archived;
        $db = $this->connection();
        $db->expects(self::once())->method('rollback');
        $db->expects(self::never())->method('commit');
        try {
            (new LeadService($db))->$operation($this->actor(), str_repeat('a', 32), [
                'expected_version' => $version, 'action_reference' => 'lead-rejected',
                ...($operation === 'update' ? ['status' => 'contacted'] : []),
            ]);
            self::fail('Mutation must fail.');
        } catch (AppError $error) {
            self::assertSame($code, $error->errorCode);
        }
    }

    public static function rejectedMutations(): array
    {
        return [[1, 0, 'update', 'STALE_VERSION'], [2, 1, 'update', 'INVALID_STATE'],
            [2, 1, 'archive', 'INVALID_STATE'], [2, 0, 'restore', 'INVALID_STATE']];
    }

    public function testReplayReturnsSavedResultBeforeReadingCurrentStateButChecksPermission(): void
    {
        $payload = ['entity' => 'sales.leads', 'operation' => 'archive', 'id' => str_repeat('a', 32), 'expected_version' => 2];
        $this->replay = ['ACTOR_ID' => 'u', 'PAYLOAD_HASH' => ActionExecutor::fingerprint($payload),
            'RESULT_JSON' => '{"id":"' . str_repeat('a', 32) . '","current_version":3}'];
        $this->row = [];
        $service = new LeadService($this->connection());
        self::assertSame(3, $service->archive($this->actor(), str_repeat('a', 32), [
            'expected_version' => 2, 'action_reference' => 'lead-replay',
        ])['current_version']);
        $this->grants = [];
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('You do not have permission');
        $service->archive($this->actor(), str_repeat('a', 32), [
            'expected_version' => 2, 'action_reference' => 'lead-replay',
        ]);
    }

    public function testAuditFailureRollsBackBusinessWrite(): void
    {
        $this->failAudit = true;
        $db = $this->connection();
        $db->expects(self::once())->method('rollback');
        $db->expects(self::never())->method('commit');
        $this->expectException(DatabaseError::class);
        (new LeadService($db))->create($this->actor(), [
            'contact_name' => 'Alex', 'email' => 'alex@example.test', 'action_reference' => 'audit-failure',
        ]);
    }

    public function testChangedCreateReplayConflictsWithoutCreatingAnotherLead(): void
    {
        $this->replay = ['ACTOR_ID' => 'u', 'PAYLOAD_HASH' => 'different', 'RESULT_JSON' => '{}'];
        $db = $this->connection();
        $db->expects(self::once())->method('rollback');
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('different request');
        (new LeadService($db))->create($this->actor(), [
            'contact_name' => 'Changed', 'phone' => '123', 'action_reference' => 'duplicate',
        ]);
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidListQueriesAreRejected(array $query): void
    {
        $this->expectException(AppError::class);
        (new LeadService($this->connection()))->list($this->actor(), $query);
    }

    public static function invalidQueries(): array
    {
        return [[['page_size' => '101']], [['status' => 'invalid']], [['source' => 'invalid']],
            [['archived' => 'yes']], [['search' => str_repeat('a', 151)]], [['company_id' => '8']]];
    }
}

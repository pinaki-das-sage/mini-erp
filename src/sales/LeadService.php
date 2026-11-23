<?php
declare(strict_types=1);

namespace MiniErp\sales;

use MiniErp\shared\ActionExecutor;
use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

final readonly class LeadService
{
    public const SOURCES = ['website', 'referral', 'event', 'other'];
    public const STATUSES = ['new', 'contacted', 'qualified', 'lost'];
    private const FIELDS = ['contact_name' => 150, 'business_name' => 150, 'email' => 254,
        'phone' => 50, 'source' => 20, 'status' => 20, 'notes' => 1000];
    private const COLUMNS = "ID, LEAD_REFERENCE, CONTACT_NAME, BUSINESS_NAME, EMAIL, PHONE,
        SOURCE, STATUS, NOTES, ARCHIVED, VERSION, CREATED_BY, UPDATED_BY,
        TO_CHAR(CREATED_AT AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS CREATED_AT,
        TO_CHAR(UPDATED_AT AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS UPDATED_AT";

    public function __construct(private Connection $connection) {}

    public function list(Actor $actor, array $query = []): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'sales.leads', 'read');
        $page = Api::pagination($query, ['search', 'source', 'status', 'archived']);
        $filters = $page['filters'];
        $where = ['COMPANY_ID = :company_id'];
        $params = ['company_id' => $actor->companyId];
        foreach (['source' => self::SOURCES, 'status' => self::STATUSES] as $field => $values) {
            if (($filters[$field] ?? '') !== '') {
                if (!in_array($filters[$field], $values, true)) {
                    throw new AppError('VALIDATION_ERROR', 'Invalid ' . $field . ' filter.');
                }
                $where[] = strtoupper($field) . ' = :' . $field;
                $params[$field] = $filters[$field];
            }
        }
        $archived = $filters['archived'] ?? 'false';
        if (!in_array($archived, ['false', 'true', 'all'], true)) {
            throw new AppError('VALIDATION_ERROR', 'archived must be false, true or all.');
        }
        if ($archived !== 'all') {
            $where[] = 'ARCHIVED = :archived';
            $params['archived'] = $archived === 'true' ? 1 : 0;
        }
        $search = trim($filters['search'] ?? '');
        if (mb_strlen($search) > 150) {
            throw new AppError('VALIDATION_ERROR', 'Search must not exceed 150 characters.');
        }
        if ($search !== '') {
            $where[] = "(LOWER(CONTACT_NAME) LIKE :search ESCAPE '!'
                OR LOWER(BUSINESS_NAME) LIKE :search ESCAPE '!'
                OR LOWER(EMAIL) LIKE :search ESCAPE '!'
                OR LOWER(PHONE) LIKE :search ESCAPE '!'
                OR LOWER(LEAD_REFERENCE) LIKE :search ESCAPE '!')";
            $params['search'] = '%' . strtr(mb_strtolower($search), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        $rows = $this->connection->query('SELECT ' . self::COLUMNS . ' FROM ME_SAL_LEADS WHERE '
            . implode(' AND ', $where) . ' ORDER BY ME_SAL_LEADS.CREATED_AT DESC, ID DESC OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY',
            $params + ['offset' => $page['offset'], 'limit' => $page['page_size']]);
        return ['items' => array_map($this->present(...), $rows), 'page' => $page['page'], 'page_size' => $page['page_size']];
    }

    public function detail(Actor $actor, string $id): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'sales.leads', 'read');
        $this->validateId($id);
        $rows = $this->connection->query('SELECT ' . self::COLUMNS . ' FROM ME_SAL_LEADS WHERE COMPANY_ID = :company_id AND ID = :id',
            ['company_id' => $actor->companyId, 'id' => $id]);
        if ($rows === []) {
            throw new AppError('NOT_FOUND', 'Lead not found.', 404);
        }
        return $this->present($rows[0]);
    }

    public function create(Actor $actor, array $input): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'sales.leads', 'create');
        $this->checkKeys($input, ['action_reference', ...array_keys(self::FIELDS)]);
        $fields = $this->validate($input);
        $executor = new ActionExecutor(new TransactionManager($this->connection));
        return $executor->run($actor, Api::string($input, 'action_reference'),
            ['entity' => 'sales.leads', 'operation' => 'create', ...$fields],
            function (TransactionContext $tx) use ($actor, $fields): array {
                $id = bin2hex(random_bytes(16));
                $reference = 'LD-' . strtoupper($id);
                $tx->execute('INSERT INTO ME_SAL_LEADS
                    (COMPANY_ID, ID, LEAD_REFERENCE, CONTACT_NAME, BUSINESS_NAME, EMAIL, PHONE, SOURCE, STATUS, NOTES, CREATED_BY, UPDATED_BY)
                    VALUES (:company_id, :id, :lead_reference, :contact_name, :business_name, :email, :phone, :source, :status, :notes, :actor, :actor)',
                    $fields + ['company_id' => $actor->companyId, 'id' => $id, 'lead_reference' => $reference, 'actor' => $actor->id]);
                return ['id' => $id, 'lead_reference' => $reference, 'current_version' => 1];
            }, 'LEAD_CREATE');
    }

    public function update(Actor $actor, string $id, array $input): array
    {
        return $this->mutate($actor, $id, $input, 'update');
    }

    public function archive(Actor $actor, string $id, array $input): array
    {
        return $this->mutate($actor, $id, $input, 'archive');
    }

    public function restore(Actor $actor, string $id, array $input): array
    {
        return $this->mutate($actor, $id, $input, 'restore');
    }

    private function mutate(Actor $actor, string $id, array $input, string $operation): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'sales.leads', $operation);
        $this->validateId($id);
        $this->checkKeys($input, ['action_reference', 'expected_version', ...($operation === 'update' ? array_keys(self::FIELDS) : [])]);
        $version = Api::integer($input, 'expected_version');
        $changes = array_intersect_key($input, self::FIELDS);
        if ($operation === 'update' && $changes === []) {
            throw new AppError('VALIDATION_ERROR', 'Supply at least one lead field to update.');
        }
        $executor = new ActionExecutor(new TransactionManager($this->connection));
        return $executor->run($actor, Api::string($input, 'action_reference'),
            ['entity' => 'sales.leads', 'operation' => $operation, 'id' => $id, 'expected_version' => $version, ...$changes],
            function (TransactionContext $tx) use ($actor, $id, $operation, $version, $changes): array {
                $rows = $tx->query('SELECT ' . self::COLUMNS . ' FROM ME_SAL_LEADS WHERE COMPANY_ID = :company_id AND ID = :id FOR UPDATE',
                    ['company_id' => $actor->companyId, 'id' => $id]);
                if ($rows === []) {
                    throw new AppError('NOT_FOUND', 'Lead not found.', 404);
                }
                $current = $this->present($rows[0]);
                if ($current['current_version'] !== $version) {
                    throw new AppError('STALE_VERSION', 'Lead changed. Refresh before starting a new action.', 409);
                }
                if (($operation === 'restore') !== $current['archived']) {
                    throw new AppError('INVALID_STATE', 'Archived leads must be restored before editing; archive/restore must change the current state.', 409);
                }
                $params = ['company_id' => $actor->companyId, 'id' => $id, 'version' => $version, 'actor' => $actor->id];
                if ($operation === 'update') {
                    $params += $this->validate($changes + array_intersect_key($current, self::FIELDS));
                    $set = 'CONTACT_NAME = :contact_name, BUSINESS_NAME = :business_name, EMAIL = :email, PHONE = :phone,
                        SOURCE = :source, STATUS = :status, NOTES = :notes';
                } else {
                    $params['archived'] = $operation === 'archive' ? 1 : 0;
                    $set = 'ARCHIVED = :archived';
                }
                $changed = $tx->execute('UPDATE ME_SAL_LEADS SET ' . $set . ', UPDATED_BY = :actor,
                    UPDATED_AT = SYSTIMESTAMP, VERSION = VERSION + 1 WHERE COMPANY_ID = :company_id AND ID = :id AND VERSION = :version', $params);
                if ($changed !== 1) {
                    throw new AppError('STALE_VERSION', 'Lead changed. Refresh before starting a new action.', 409);
                }
                return ['id' => $id, 'current_version' => $version + 1];
            }, 'LEAD_' . strtoupper($operation));
    }

    private function validate(array $input): array
    {
        $fields = [];
        foreach (self::FIELDS as $field => $max) {
            $value = array_key_exists($field, $input) ? $input[$field] : match ($field) { 'source' => 'other', 'status' => 'new', default => '' };
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max) {
                throw new AppError('VALIDATION_ERROR', $field . ' must be text of at most ' . $max . ' characters.');
            }
            $fields[$field] = trim($value);
        }
        if ($fields['contact_name'] === '' || ($fields['email'] === '' && $fields['phone'] === '')) {
            throw new AppError('VALIDATION_ERROR', 'Contact name and at least one of email or phone are required.');
        }
        if ($fields['email'] !== '' && filter_var($fields['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new AppError('VALIDATION_ERROR', 'Supply a valid email address.');
        }
        if (!in_array($fields['source'], self::SOURCES, true) || !in_array($fields['status'], self::STATUSES, true)) {
            throw new AppError('VALIDATION_ERROR', 'Select a supported lead source and status.');
        }
        return $fields;
    }

    private function validateId(string $id): void
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/', $id)) {
            throw new AppError('VALIDATION_ERROR', 'Invalid lead ID.');
        }
    }

    private function checkKeys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new AppError('VALIDATION_ERROR', 'Unsupported lead input field.');
        }
    }

    private function present(array $row): array
    {
        $lead = [];
        foreach (['id', 'lead_reference', ...array_keys(self::FIELDS), 'created_by', 'updated_by', 'created_at', 'updated_at'] as $field) {
            $lead[$field] = $row[strtoupper($field)] ?? '';
        }
        return $lead + ['archived' => (int) $row['ARCHIVED'] === 1, 'current_version' => (int) $row['VERSION']];
    }
}

<?php
declare(strict_types=1);

namespace MiniErp\sales;

use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

final readonly class CustomerService
{
    private const TEXT = [
        'customer_name' => ['Customer Name', 100, true],
        'contact_name' => ['Contact Name', 100, false],
        'email_address' => ['Email Address', 254, false],
        'phone_number' => ['Phone Number', 25, false],
        'billing_address_line_1' => ['Billing Address Line 1', 150, true],
        'billing_address_line_2' => ['Billing Address Line 2', 150, false],
        'city' => ['City', 80, true],
        'state_province' => ['State / Province', 80, false],
        'postal_code' => ['Postal Code', 20, false],
        'country' => ['Country', 80, true],
        'notes' => ['Notes', 500, false],
    ];

    public function __construct(private Connection $connection) {}

    public function create(Actor $actor, array $input): array
    {
        $this->authorize($actor, 'create');
        $this->fields($input, true);
        $reference = $this->actionReference($input);
        return $this->actions()->run($actor, $reference, 'OE_CUSTOMER_CREATE', $input, function (TransactionContext $tx) use ($actor, $input): array {
            $code = Validation::text($input['customer_code'] ?? '', 'Customer Code', 20);
            if (!preg_match('/\A[A-Za-z0-9-]+\z/D', $code)) {
                throw new AppError('VALIDATION_ERROR', 'Customer Code may contain only letters, numbers and hyphens.');
            }
            $values = ['customer_code' => $code, ...$this->values($input, true)];
            $columns = implode(', ', array_map('strtoupper', array_keys($values)));
            $binds = ':' . implode(', :', array_keys($values));
            try {
                $tx->execute("INSERT INTO ME_OE_CUSTOMERS (CNY#, $columns) VALUES (:company_id, $binds)", ['company_id' => $actor->companyId, ...$values]);
            } catch (DatabaseError $error) {
                if ($error->oracleCode === 1) {
                    throw new AppError('DUPLICATE_CUSTOMER', 'Customer Code already exists. Enter a unique code.', 409);
                }
                throw $error;
            }
            $rows = $tx->query('SELECT * FROM ME_OE_CUSTOMERS WHERE CNY# = :company_id AND CUSTOMER_CODE = :customer_code',
                ['company_id' => $actor->companyId, 'customer_code' => $code]);
            return $this->present($this->found($rows));
        });
    }

    public function get(Actor $actor, string $id): array
    {
        $this->authorize($actor, 'read');
        $rows = $this->connection->query('SELECT * FROM ME_OE_CUSTOMERS WHERE CNY# = :company_id AND RECORD# = :id',
            ['company_id' => $actor->companyId, 'id' => $this->id($id)]);
        return $this->present($this->found($rows));
    }

    public function list(Actor $actor, array $query = []): array
    {
        $this->authorize($actor, 'read');
        $page = Api::pagination($query, ['search', 'is_active']);
        $where = 'CNY# = :company_id';
        $binds = ['company_id' => $actor->companyId, 'offset_rows' => $page['offset'], 'limit_rows' => $page['page_size']];
        if (isset($page['filters']['is_active'])) {
            $this->active($page['filters']['is_active']);
            $where .= ' AND IS_ACTIVE = :is_active';
            $binds['is_active'] = $page['filters']['is_active'];
        }
        if (isset($page['filters']['search'])) {
            $q = Validation::text($page['filters']['search'], 'Search', 100, false);
            $where .= " AND (UPPER(CUSTOMER_CODE) LIKE UPPER(:search_code) ESCAPE '!' OR UPPER(CUSTOMER_NAME) LIKE UPPER(:search_name) ESCAPE '!')";
            $pattern = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $binds['search_code'] = $pattern;
            $binds['search_name'] = $pattern;
        }
        $rows = $this->connection->query("SELECT * FROM ME_OE_CUSTOMERS WHERE $where ORDER BY CUSTOMER_CODE, RECORD# OFFSET :offset_rows ROWS FETCH NEXT :limit_rows ROWS ONLY", $binds);
        return ['items' => array_map(fn (array $row): array => $this->present($row), $rows), 'page' => $page['page'], 'page_size' => $page['page_size']];
    }

    public function update(Actor $actor, string $id, array $input): array
    {
        $this->authorize($actor, 'edit');
        $this->fields($input, false);
        $id = $this->id($id);
        $reference = $this->actionReference($input);
        return $this->actions()->run($actor, $reference, 'OE_CUSTOMER_UPDATE', ['id' => $id, 'input' => $input], function (TransactionContext $tx) use ($actor, $id, $input): array {
            $binds = ['company_id' => $actor->companyId, 'id' => $id];
            $this->found($tx->query('SELECT * FROM ME_OE_CUSTOMERS WHERE CNY# = :company_id AND RECORD# = :id FOR UPDATE', $binds));
            $values = $this->values($input, false);
            if ($values === []) {
                throw new AppError('VALIDATION_ERROR', 'Supply at least one editable Customer field.');
            }
            $assignments = implode(', ', array_map(static fn (string $key): string => strtoupper($key) . ' = :' . $key, array_keys($values)));
            $changed = $tx->execute("UPDATE ME_OE_CUSTOMERS SET $assignments, WHENMODIFIED = CURRENT_TIMESTAMP WHERE CNY# = :company_id AND RECORD# = :id",
                [...$binds, ...$values]);
            if ($changed !== 1) {
                throw new AppError('NOT_FOUND', 'Customer not found.', 404);
            }
            return $this->present($this->found($tx->query(
                'SELECT * FROM ME_OE_CUSTOMERS WHERE CNY# = :company_id AND RECORD# = :id', $binds,
            )));
        });
    }

    private function actionReference(array $input): string
    {
        return array_key_exists('action_reference', $input)
            ? Api::string($input, 'action_reference')
            : bin2hex(random_bytes(16));
    }

    private function fields(array $input, bool $create): void
    {
        if (array_key_exists('amount_customer_owes_us', $input)) {
            throw new AppError('VALIDATION_ERROR', 'Amount Customer Owes Us is calculated and cannot be edited.');
        }
        if (!$create && array_key_exists('customer_code', $input)) {
            throw new AppError('VALIDATION_ERROR', 'Customer Code cannot be changed after creation.');
        }
        Validation::allowedFields($input, [...array_keys(self::TEXT), 'is_active', 'payment_terms', 'action_reference', ...($create ? ['customer_code'] : [])]);
    }

    private function values(array $input, bool $create): array
    {
        $values = [];
        foreach (self::TEXT as $key => [$label, $limit, $required]) {
            if ($create || array_key_exists($key, $input)) {
                if (array_key_exists($key, $input) && $input[$key] === null) {
                    throw new AppError('VALIDATION_ERROR', $label . ' must be text.');
                }
                $values[$key] = Validation::text($input[$key] ?? ($required ? '' : null), $label, $limit, $required);
            }
        }
        if (($values['email_address'] ?? '') !== '' && !filter_var($values['email_address'], FILTER_VALIDATE_EMAIL)) {
            throw new AppError('VALIDATION_ERROR', 'Enter a valid Email Address.');
        }
        if (isset($values['phone_number']) && !preg_match('/\A[0-9 +()\-]*\z/D', $values['phone_number'])) {
            throw new AppError('VALIDATION_ERROR', 'Enter a valid Phone Number.');
        }
        if ($create || array_key_exists('is_active', $input)) {
            $values['is_active'] = $this->active(array_key_exists('is_active', $input) ? $input['is_active'] : 'T');
        }
        if ($create || array_key_exists('payment_terms', $input)) {
            $terms = array_key_exists('payment_terms', $input) ? $input['payment_terms'] : 'NET_30';
            if (!in_array($terms, ['DUE_ON_RECEIPT', 'NET_15', 'NET_30'], true)) {
                throw new AppError('VALIDATION_ERROR', 'Select valid Payment Terms.');
            }
            $values['payment_terms'] = $terms;
        }
        return $values;
    }

    private function active(mixed $value): string
    {
        if (!in_array($value, ['T', 'F'], true)) {
            throw new AppError('VALIDATION_ERROR', 'Select a valid Is Active value (T or F).');
        }
        return $value;
    }

    private function id(string $id): int
    {
        if (!preg_match('/\A[1-9][0-9]{0,14}\z/D', $id)) {
            throw new AppError('NOT_FOUND', 'Customer not found.', 404);
        }
        return (int) $id;
    }

    private function found(array $rows): array
    {
        if ($rows === []) {
            throw new AppError('NOT_FOUND', 'Customer not found.', 404);
        }
        return $rows[0];
    }

    private function present(array $row): array
    {
        $result = ['id' => (int) $row['RECORD#'], 'customer_code' => $row['CUSTOMER_CODE'], 'is_active' => $row['IS_ACTIVE'],
            'payment_terms' => $row['PAYMENT_TERMS']];
        foreach (self::TEXT as $key => $_) {
            $result[$key] = $row[strtoupper($key)] ?? '';
        }
        $balance = $row['AMOUNT_CUSTOMER_OWES_US'] ?? null;
        if (!is_string($balance) || !preg_match('/\A(?:[0-9]{1,16}(?:\.[0-9]{1,2})?|\.[0-9]{1,2})\z/D', $balance)) {
            throw new AppError('BALANCE_UNAVAILABLE', 'Customer balance is unavailable.', 503);
        }
        [$whole, $fraction] = array_pad(explode('.', $balance, 2), 2, '');
        $balance = (ltrim($whole, '0') ?: '0') . '.' . str_pad($fraction, 2, '0');
        return [...$result, 'amount_customer_owes_us' => $balance];
    }

    private function authorize(Actor $actor, string $action): void
    {
        (new SalesPermissions(new Authorization($this->connection)))->requireCustomer($actor, $action);
    }

    private function actions(): CustomerActions
    {
        return new CustomerActions(new TransactionManager($this->connection), new SalesPermissions(new Authorization($this->connection)));
    }
}

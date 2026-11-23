<?php
declare(strict_types=1);

namespace MiniErp\shared\security;

use MiniErp\shared\AppError;
use MiniErp\shared\Seeder;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;

final readonly class CompanyBootstrap
{
    public function __construct(private Connection $connection) {}

    public function run(string $username = 'admin', #[\SensitiveParameter] string $password = 'admin', string $company = 'SIA-MINI-ERP'): bool
    {
        $this->validateCredentials($username, $password);
        return (new TransactionManager($this->connection))->run(function (TransactionContext $tx) use ($username, $password, $company): bool {
            $rows = $tx->query('SELECT ID FROM ME_COMPANY WHERE CODE = :company AND ACTIVE = 1 FOR UPDATE', ['company' => strtoupper(trim($company))]);
            if ($rows === []) {
                throw new AppError('INVALID_STATE', 'Apply the company migration or create the company first.', 409);
            }
            return $this->bootstrapAdmin($tx, (int) $rows[0]['ID'], $username, $password);
        });
    }

    public function create(string $code, string $name, string $username = 'admin', #[\SensitiveParameter] string $password = 'admin'): int
    {
        $code = strtoupper(trim($code));
        $name = trim($name);
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,49}$/', $code) || $name === '' || strlen($name) > 150) {
            throw new AppError('VALIDATION_ERROR', 'Use a company code of 1-50 letters, digits, underscores or hyphens and a name of 1-150 bytes.');
        }
        $this->validateCredentials($username, $password);
        try {
            $transactions = new TransactionManager($this->connection);
            return $transactions->run(function (TransactionContext $tx) use ($code, $name, $username, $password, $transactions): int {
                $rows = $tx->query('SELECT ME_COMPANY_SEQ.NEXTVAL AS ID FROM DUAL');
                $id = filter_var($rows[0]['ID'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) {
                    throw new AppError('DATABASE_ERROR', 'Could not allocate a company identity.', 503);
                }
                $tx->execute('INSERT INTO ME_COMPANY (ID, CODE, NAME) VALUES (:id, :code, :name)', ['id' => $id, 'code' => $code, 'name' => $name]);
                (new Seeder($this->connection, $transactions))->seedCompanyUsers($tx, $id);
                $this->bootstrapAdmin($tx, $id, $username, $password);
                return $id;
            });
        } catch (DatabaseError $error) {
            if ($error->oracleCode === 1 && $this->connection->query('SELECT ID FROM ME_COMPANY WHERE CODE = :company', ['company' => $code]) !== []) {
                throw new AppError('INVALID_STATE', 'Company code already exists. Existing accounts were not changed.', 409);
            }
            throw $error;
        }
    }

    private function validateCredentials(string $username, #[\SensitiveParameter] string $password): void
    {
        $defaultCredentials = $username === 'admin' && $password === 'admin';
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,99}$/', $username)
            || (!$defaultCredentials && strlen($password) < 12) || strlen($password) > 72) {
            throw new AppError('VALIDATION_ERROR', 'Use the admin/admin bootstrap default, or a lowercase username (3-100 characters) and password of 12-72 bytes.');
        }
    }

    private function bootstrapAdmin(TransactionContext $tx, int $companyId, string $username, #[\SensitiveParameter] string $password): bool
    {
        $defaultCredentials = $username === 'admin' && $password === 'admin';
        $existing = $tx->query('SELECT USER_ID AS ID, IS_ADMIN, ACTIVE FROM ME_USERS WHERE USERNAME = :username AND COMPANY_ID = :company_id',
            ['username' => $username, 'company_id' => $companyId]);
        if ($existing !== []) {
            if ((int) $existing[0]['IS_ADMIN'] !== 1 || (int) $existing[0]['ACTIVE'] !== 1) {
                throw new AppError('INVALID_STATE', 'Bootstrap username already belongs to an inactive or non-admin account.', 409);
            }
            return false;
        }
        if ($tx->query('SELECT USER_ID FROM ME_USERS WHERE IS_ADMIN = 1 AND ACTIVE = 1 AND COMPANY_ID = :company_id', ['company_id' => $companyId]) !== []) {
            throw new AppError('INVALID_STATE', 'An administrator already exists. Create further users through Settings.', 409);
        }
        $id = $companyId === 1 ? 'bootstrap-admin' : 'admin-' . bin2hex(random_bytes(16));
        $tx->execute(
            'INSERT INTO ME_USERS (USER_ID, USERNAME, DISPLAY_NAME, PASSWORD_HASH, IS_ADMIN, MUST_CHANGE_PASSWORD, COMPANY_ID)
             VALUES (:id, :username, :name, :hash, 1, :must_change, :company_id)',
            ['id' => $id, 'company_id' => $companyId, 'username' => $username, 'name' => 'Administrator',
                'hash' => password_hash($password, PASSWORD_DEFAULT), 'must_change' => $defaultCredentials ? 0 : 1],
        );
        $tx->execute("INSERT INTO ME_USER_ROLES (USER_ID, ROLE_CODE, COMPANY_ID) VALUES (:id, 'trainee', :company_id)", ['id' => $id, 'company_id' => $companyId]);
        if ($tx->query("SELECT ID FROM ME_NOTES WHERE ID = 'welcome' AND COMPANY_ID = :company_id", ['company_id' => $companyId]) === []) {
            $tx->execute("INSERT INTO ME_NOTES (ID, BODY, COMPANY_ID) VALUES ('welcome', :body, :company_id)",
                ['body' => 'Training foundation is ready.', 'company_id' => $companyId]);
        }
        return true;
    }
}

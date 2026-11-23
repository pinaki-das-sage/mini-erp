<?php
declare(strict_types=1);

namespace MiniErp\shared;

use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;

final readonly class Seeder
{
    public function __construct(private Connection $connection, private TransactionManager $transactions) {}

    public function seed(#[\SensitiveParameter] string $password): array
    {
        $this->validateSeedPassword($password);
        return $this->seedAccounts($password);
    }

    public function bootstrap(): array
    {
        return $this->seedAccounts(null);
    }

    public function seedCompanyUsers(TransactionContext $tx, int $companyId, #[\SensitiveParameter] ?string $password = null): array
    {
        if ($companyId < 1) {
            throw new AppError('VALIDATION_ERROR', 'Provide a valid company identity.');
        }
        if ($password !== null) {
            $this->validateSeedPassword($password);
        }
        $accounts = [
            'buyer' => 'buyer', 'approver' => 'purchase_approver', 'receiver' => 'receiving_clerk',
            'inventory' => 'inventory_admin', 'sales' => 'sales_clerk', 'dispatcher' => 'dispatcher',
            'driver1' => 'driver', 'driver2' => 'driver', 'warehouse' => 'warehouse_receiver', 'supervisor' => 'supervisor',
        ];
        $created = [];
        foreach ($accounts as $username => $role) {
            if ($tx->query('SELECT USER_ID FROM ME_USERS WHERE USERNAME = :username AND COMPANY_ID = :company_id',
                ['username' => $username, 'company_id' => $companyId]) !== []) {
                continue;
            }
            $id = $companyId === 1 ? 'lab-' . $username : 'lab-' . bin2hex(random_bytes(16));
            $tx->execute('INSERT INTO ME_USERS (USER_ID, USERNAME, PASSWORD_HASH, COMPANY_ID) VALUES (:id, :username, :hash, :company_id)',
                ['id' => $id, 'company_id' => $companyId, 'username' => $username, 'hash' => password_hash($password ?? $username, PASSWORD_DEFAULT)]);
            foreach (['trainee', $role] as $assignedRole) {
                $tx->execute('INSERT INTO ME_USER_ROLES (USER_ID, ROLE_CODE, COMPANY_ID) VALUES (:id, :role, :company_id)',
                    ['id' => $id, 'role' => $assignedRole, 'company_id' => $companyId]);
            }
            foreach (['read', 'update'] as $operation) {
                $tx->execute("INSERT INTO ME_USER_PERMISSIONS (USER_ID, OBJECT_CODE, OPERATION, COMPANY_ID) VALUES (:id, 'shared.note', :operation, :company_id)",
                    ['id' => $id, 'operation' => $operation, 'company_id' => $companyId]);
            }
            $created[] = $username;
        }
        return $created;
    }

    private function seedAccounts(#[\SensitiveParameter] ?string $password): array
    {
        return $this->transactions->run(function (TransactionContext $tx) use ($password): array {
            $companies = $tx->query('SELECT ID FROM ME_COMPANY WHERE CODE = :company AND ACTIVE = 1 FOR UPDATE', ['company' => 'SIA-MINI-ERP']);
            if ($companies === []) {
                throw new AppError('INVALID_STATE', 'Apply the company migration first.', 409);
            }
            $companyId = (int) $companies[0]['ID'];
            $created = $this->seedCompanyUsers($tx, $companyId, $password);
            if ($tx->query("SELECT ID FROM ME_NOTES WHERE ID = 'welcome' AND COMPANY_ID = :company_id", ['company_id' => $companyId]) === []) {
                $tx->execute("INSERT INTO ME_NOTES (ID, BODY, COMPANY_ID) VALUES ('welcome', :body, :company_id)",
                    ['body' => 'Training foundation is ready.', 'company_id' => $companyId]);
            }
            return $created;
        });
    }

    private function validateSeedPassword(#[\SensitiveParameter] string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 72) {
            throw new \InvalidArgumentException('Set a lab-only seed password of 12-72 bytes. Never use a production password.');
        }
    }
}

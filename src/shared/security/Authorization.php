<?php
declare(strict_types=1);

namespace MiniErp\shared\security;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;

final readonly class Authorization
{
    public function __construct(private Connection $connection) {}

    public function requirePermission(Actor $actor, string $object, string $operation): void
    {
        if ($actor->mustChangePassword) {
            throw new AppError('PASSWORD_CHANGE_REQUIRED', 'Change your temporary password first.', 403);
        }
        $rows = $this->connection->query('SELECT ACTIVE, ALLOW_CREATE, ALLOW_READ, ALLOW_UPDATE, ALLOW_DELETE FROM ME_OBJECTS WHERE CODE = :object', ['object' => $object]);
        $supported = $rows !== [] && (int) $rows[0]['ACTIVE'] === 1;
        if ($supported && in_array($operation, ['create', 'read', 'update', 'delete'], true)) {
            $supported = (int) $rows[0]['ALLOW_' . strtoupper($operation)] === 1;
        } elseif ($supported) {
            $supported = $this->connection->query(
                'SELECT ACTION_CODE FROM ME_OBJECT_ACTIONS WHERE OBJECT_CODE = :object AND ACTION_CODE = :operation',
                ['object' => $object, 'operation' => $operation],
            ) !== [];
        }
        if (!$supported) {
            throw new AppError('FORBIDDEN', 'This object or operation is not registered and active.', 403);
        }
        if (!$actor->isAdmin && $this->connection->query(
            'SELECT USER_ID FROM ME_USER_PERMISSIONS WHERE USER_ID = :user_id AND COMPANY_ID = :company_id AND OBJECT_CODE = :object AND OPERATION = :operation',
            ['user_id' => $actor->id, 'company_id' => $actor->companyId, 'object' => $object, 'operation' => $operation],
        ) === []) {
            throw new AppError('FORBIDDEN', 'You do not have permission for this operation.', 403);
        }
    }

    /** @return list<string> */
    public function permissions(Actor $actor): array
    {
        if ($actor->mustChangePassword) {
            return [];
        }
        $supported = [];
        foreach ($this->connection->query('SELECT CODE, ALLOW_CREATE, ALLOW_READ, ALLOW_UPDATE, ALLOW_DELETE FROM ME_OBJECTS WHERE ACTIVE = 1') as $object) {
            foreach (['create', 'read', 'update', 'delete'] as $operation) {
                if ((int) $object['ALLOW_' . strtoupper($operation)] === 1) {
                    $supported[$object['CODE'] . ':' . $operation] = true;
                }
            }
        }
        foreach ($this->connection->query('SELECT A.OBJECT_CODE, A.ACTION_CODE FROM ME_OBJECT_ACTIONS A JOIN ME_OBJECTS O ON O.CODE = A.OBJECT_CODE WHERE O.ACTIVE = 1') as $action) {
            $supported[$action['OBJECT_CODE'] . ':' . $action['ACTION_CODE']] = true;
        }
        if (!$actor->isAdmin) {
            $granted = [];
            foreach ($this->connection->query('SELECT OBJECT_CODE, OPERATION FROM ME_USER_PERMISSIONS WHERE USER_ID = :user_id AND COMPANY_ID = :company_id',
                ['user_id' => $actor->id, 'company_id' => $actor->companyId]) as $grant) {
                $granted[$grant['OBJECT_CODE'] . ':' . $grant['OPERATION']] = true;
            }
            $supported = array_intersect_key($supported, $granted);
        }
        $permissions = array_keys($supported);
        sort($permissions);
        return $permissions;
    }
}

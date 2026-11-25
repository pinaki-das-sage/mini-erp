<?php
declare(strict_types=1);

namespace MiniErp\sales\customers;

use MiniErp\sales\SalesPermissions;

use MiniErp\shared\ActionExecutor;
use MiniErp\shared\AppError;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;

/** Customer adaptation of the shared note executor, without its role or payload logging. */
final readonly class CustomerActions
{
    public function __construct(private TransactionManager $transactions, private SalesPermissions $permissions) {}

    public function run(Actor $actor, string $reference, string $event, array $payload, callable $operation): array
    {
        $action = match ($event) {
            'OE_CUSTOMER_CREATE' => 'create',
            'OE_CUSTOMER_UPDATE' => 'edit',
            default => throw new AppError('FORBIDDEN', 'This Customer action is not supported.', 403),
        };
        $this->permissions->requireCustomer($actor, $action);
        if (!preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $reference)) {
            throw new AppError('VALIDATION_ERROR', 'Provide an action reference of 1-100 letters, digits, underscores or hyphens.');
        }
        $hash = ActionExecutor::fingerprint(['event' => $event, 'input' => $payload]);
        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $reference, $event, $hash, $operation): array {
            $identity = ['company_id' => $actor->companyId, 'reference' => $reference];
            try {
                $tx->execute('INSERT INTO ME_ACTIONS (COMPANY_ID, ACTION_REFERENCE, ACTOR_ID, PAYLOAD_HASH) VALUES (:company_id, :reference, :actor, :hash)',
                    [...$identity, 'actor' => $actor->id, 'hash' => $hash]);
            } catch (DatabaseError $error) {
                if ($error->oracleCode !== 1) {
                    throw $error;
                }
                $rows = $tx->query('SELECT ACTOR_ID, PAYLOAD_HASH, RESULT_JSON FROM ME_ACTIONS WHERE COMPANY_ID = :company_id AND ACTION_REFERENCE = :reference', $identity);
                if ($rows === [] || $rows[0]['ACTOR_ID'] !== $actor->id || $rows[0]['PAYLOAD_HASH'] !== $hash || $rows[0]['RESULT_JSON'] === null) {
                    throw new AppError('DUPLICATE_ACTION_CONFLICT', 'This action reference belongs to a different request.', 409);
                }
                return json_decode($rows[0]['RESULT_JSON'], true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $operation($tx);
            $tx->execute('UPDATE ME_ACTIONS SET RESULT_JSON = :result WHERE COMPANY_ID = :company_id AND ACTION_REFERENCE = :reference',
                [...$identity, 'result' => json_encode($result, JSON_THROW_ON_ERROR)]);
            $tx->execute('INSERT INTO ME_AUDIT (ID, COMPANY_ID, ACTION_REFERENCE, ACTOR_ID, EVENT_TYPE, DETAILS_JSON) VALUES (:id, :company_id, :reference, :actor, :event, :details)',
                [...$identity,   'id' => bin2hex(random_bytes(16)), 'actor' => $actor->id, 'event' => $event,
                    'details' => json_encode(['customer_record_id' => $result['id']], JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
}

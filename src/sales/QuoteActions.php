<?php
declare(strict_types=1);

namespace MiniErp\sales;

use MiniErp\shared\ActionExecutor;
use MiniErp\shared\AppError;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;

final readonly class QuoteActions
{
    public function __construct(private TransactionManager $transactions, private SalesPermissions $permissions) {}

    public function run(Actor $actor, string $action, ?string $number, array $input, callable $operation): array
    {
        $this->permissions->requireDocument($actor, 'quote', $action);
        $reference = array_key_exists('action_reference', $input) ? $input['action_reference'] : bin2hex(random_bytes(16));
        if (!is_string($reference) || !preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $reference)) {
            throw new AppError('VALIDATION_ERROR', 'Provide an action reference of 1-100 letters, digits, underscores or hyphens.');
        }
        $event = 'OE_QUOTE_' . strtoupper($action);
        $hash = ActionExecutor::fingerprint(['event' => $event, 'quote_number' => $number, 'input' => $input]);
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
                [...$identity, 'id' => bin2hex(random_bytes(16)), 'actor' => $actor->id, 'event' => $event,
                    'details' => json_encode(['quote_number' => $result['quote_number']], JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
}

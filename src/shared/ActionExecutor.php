<?php
declare(strict_types=1);

namespace MiniErp\shared;

use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;

final readonly class ActionExecutor
{
    public function __construct(private TransactionManager $transactions) {}

    public static function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode(self::canonical($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** The caller must authorize the actor before claiming or replaying an action. */
    public function run(Actor $actor, string $reference, array $payload, callable $operation, string $eventType = 'NEUTRAL_NOTE_UPDATE'): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $reference)) {
            throw new AppError('VALIDATION_ERROR', 'Provide an action reference of 1-100 letters, digits, underscores or hyphens.');
        }
        $hash = self::fingerprint($payload);
        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $reference, $payload, $operation, $hash, $eventType): array {
            try {
                $tx->execute(
                    'INSERT INTO ME_ACTIONS (ACTION_REFERENCE, ACTOR_ID, PAYLOAD_HASH, COMPANY_ID) VALUES (:reference, :actor, :hash, :company_id)',
                    ['reference' => $reference, 'company_id' => $actor->companyId, 'actor' => $actor->id, 'hash' => $hash],
                );
            } catch (DatabaseError $error) {
                if ($error->oracleCode !== 1) {
                    throw $error;
                }
                $rows = $tx->query('SELECT ACTOR_ID, PAYLOAD_HASH, RESULT_JSON FROM ME_ACTIONS WHERE ACTION_REFERENCE = :reference AND COMPANY_ID = :company_id',
                    ['reference' => $reference, 'company_id' => $actor->companyId]);
                if ($rows === [] || $rows[0]['ACTOR_ID'] !== $actor->id || $rows[0]['PAYLOAD_HASH'] !== $hash || $rows[0]['RESULT_JSON'] === null) {
                    throw new AppError('DUPLICATE_ACTION_CONFLICT', 'This action reference belongs to a different request.', 409);
                }
                return json_decode($rows[0]['RESULT_JSON'], true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $operation($tx);
            $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $tx->execute(
                'UPDATE ME_ACTIONS SET RESULT_JSON = :result WHERE ACTION_REFERENCE = :reference AND COMPANY_ID = :company_id',
                ['result' => $json, 'company_id' => $actor->companyId, 'reference' => $reference],
            );
            $tx->execute(
                'INSERT INTO ME_AUDIT (ID, ACTION_REFERENCE, ACTOR_ID, EVENT_TYPE, DETAILS_JSON, COMPANY_ID) VALUES (:id, :reference, :actor, :type, :details, :company_id)',
                ['id' => bin2hex(random_bytes(16)), 'company_id' => $actor->companyId, 'reference' => $reference, 'actor' => $actor->id, 'type' => $eventType, 'details' => json_encode(['input' => $payload, 'result' => $result], JSON_THROW_ON_ERROR)],
            );
            return $result;
        });
    }

    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }
        unset($item);
        return $value;
    }
}

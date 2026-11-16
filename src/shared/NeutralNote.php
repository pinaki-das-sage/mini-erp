<?php
declare(strict_types=1);

namespace MiniErp\shared;

use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

final readonly class NeutralNote
{
    public function __construct(private Connection $connection) {}

    public function read(Actor $actor): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'shared.note', 'read');
        $rows = $this->connection->query("SELECT ID, BODY, VERSION FROM ME_NOTES WHERE ID = 'welcome' AND COMPANY_ID = :company_id",
            ['company_id' => $actor->companyId]);
        if ($rows === []) {
            throw new AppError('INVALID_STATE', 'Seed the foundation before opening the neutral example.', 409);
        }
        return ['text' => $rows[0]['BODY'], 'current_version' => (int) $rows[0]['VERSION']];
    }

    public function update(Actor $actor, string $reference, string $text, int $version): array
    {
        if (trim($text) === '' || mb_strlen($text) > 500 || $version < 1) {
            throw new AppError('VALIDATION_ERROR', 'Text must contain 1-500 characters and version must be positive.');
        }
        (new Authorization($this->connection))->requirePermission($actor, 'shared.note', 'update');
        $executor = new ActionExecutor(new TransactionManager($this->connection));
        return $executor->run($actor, $reference, ['note_id' => 'welcome', 'text' => $text, 'expected_version' => $version],
            function (TransactionContext $tx) use ($actor, $text, $version): array {
                $rows = $tx->query("SELECT ID, BODY, VERSION FROM ME_NOTES WHERE ID = 'welcome' AND COMPANY_ID = :company_id FOR UPDATE",
                    ['company_id' => $actor->companyId]);
                if ($rows === []) {
                    throw new AppError('INVALID_STATE', 'Seed the foundation first.', 409);
                }
                if ((int) $rows[0]['VERSION'] !== $version) {
                    throw new AppError('STALE_VERSION', 'The note changed. Refresh before starting a new action.', 409);
                }
                $changed = $tx->execute(
                    "UPDATE ME_NOTES SET BODY = :body, VERSION = VERSION + 1 WHERE ID = 'welcome' AND VERSION = :version AND COMPANY_ID = :company_id",
                    ['body' => $text, 'version' => $version, 'company_id' => $actor->companyId],
                );
                if ($changed !== 1) {
                    throw new AppError('STALE_VERSION', 'The note changed. Refresh before starting a new action.', 409);
                }
                return ['text' => $text, 'previous_text' => $rows[0]['BODY'], 'current_version' => $version + 1];
            }
        );
    }
}

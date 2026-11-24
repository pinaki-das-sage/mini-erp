<?php
declare(strict_types=1);

namespace MiniErp\purchasing\Controller;

use MiniErp\purchasing\Model\PurchasingDocumentModel;
use MiniErp\shared\AppError;
use MiniErp\shared\security\Actor;

final readonly class PurchasingEntryController
{
    public function __construct(private PurchasingDocumentModel $documents) {}

    /** @return list<array<string, mixed>> */
    public function list(Actor $actor, array $input, array $params, array $query): array
    {
        foreach ($query as $key => $value) {
            if ($key !== 'doc_id' || !is_string($value) || $value === '' || strlen($value) > 40) {
                throw new AppError('VALIDATION_ERROR', 'doc_id must be a non-empty string of at most 40 characters.');
            }
        }
        return $this->documents->listEntries($actor, $query['doc_id'] ?? null);
    }

    /** @return array<string, mixed> */
    public function get(Actor $actor, array $input, array $params, array $query): array
    {
        if ($params['record'] === '' || strlen($params['record']) > 40 || $query !== []) {
            throw new AppError('VALIDATION_ERROR', 'record must be at most 40 characters and no query parameters are supported.');
        }
        $entry = $this->documents->findEntry($actor, $params['record']);
        if ($entry === null) {
            throw new AppError('NOT_FOUND', 'Document entry not found.', 404);
        }
        return $entry;
    }
}

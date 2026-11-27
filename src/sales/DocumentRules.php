<?php
declare(strict_types=1);

namespace MiniErp\sales;

use MiniErp\shared\AppError;
use MiniErp\shared\security\Actor;

/** Pure guards; services must supply authoritative facts re-read under their transaction locks. */
final class DocumentRules
{
    public static function requireCompany(Actor $actor, int $recordCompanyId): void
    {
        if ($actor->companyId !== $recordCompanyId) {
            throw new AppError('NOT_FOUND', 'Record not found.', 404);
        }
    }

    public static function requireVersion(int $currentVersion, int $expectedVersion): void
    {
        if ($expectedVersion < 1) {
            throw new AppError('VALIDATION_ERROR', 'expected_version must be a positive integer.');
        }
        if ($currentVersion !== $expectedVersion) {
            throw new AppError('STALE_VERSION', 'This document has changed. Refresh and try again.', 409);
        }
    }

    public static function requireAction(
        string $type,
        string $action,
        ?string $status,
        bool $hasDownstream = false,
        bool $hasPayments = false,
    ): void {
        if (!isset(SalesPermissions::DOCUMENTS[$type])) {
            throw new AppError('VALIDATION_ERROR', 'Select a valid document type.');
        }
        $allowed = match ($action) {
            'create' => $status === null,
            'read' => in_array($status, ['Draft', 'Submitted', ...($type === 'order' ? ['In Progress', 'Closed'] : [])], true),
            'edit', 'submit' => $status === 'Draft',
            'delete' => $status === 'Draft' && !$hasDownstream && !$hasPayments,
            'return_to_draft' => $status === 'Submitted' && !$hasDownstream && !$hasPayments,
            'record_payment' => $type === 'invoice' && $status === 'Submitted',
            default => false,
        };
        if (!$allowed) {
            $message = match ($action) {
                'submit' => 'Only a saved Draft can be submitted.',
                'edit' => 'Only Draft documents can be edited.',
                'delete' => 'Only Draft documents with no downstream documents or payments can be deleted.',
                'return_to_draft' => $hasDownstream
                    ? 'Cannot move to Draft: this document is used as a source by another document.'
                    : ($hasPayments ? 'Cannot move to Draft: a payment has been recorded for this Invoice.' : 'Only Submitted documents can move to Draft.'),
                'record_payment' => 'Submit the Invoice before recording a payment.',
                default => 'This document action is not available.',
            };
            throw new AppError('INVALID_STATE', $message, 409);
        }
    }

    /** @param list<string> $changedFields @param list<string> $inheritedFields Type-specific inherited field names. */
    public static function requireEditableFields(bool $hasSource, array $changedFields, array $inheritedFields): void
    {
        if ($hasSource && array_intersect($changedFields, $inheritedFields) !== []) {
            throw new AppError('VALIDATION_ERROR', 'Source-linked business values cannot be changed.');
        }
    }
}

<?php
declare(strict_types=1);

namespace MiniErp\purchasing\Controller;

use MiniErp\purchasing\Model\PurchasingDocumentModel;
use MiniErp\shared\AppError;
use MiniErp\shared\security\Actor;
use DateTimeImmutable;

final readonly class PurchasingDocumentController
{
    public function __construct(private PurchasingDocumentModel $documents) {}

    /** @return list<array<string, mixed>> */
    public function list(Actor $actor, array $input, array $params, array $query): array
    {
        if ($query !== []) {
            throw new AppError('VALIDATION_ERROR', 'Purchasing document listing does not accept query parameters.');
        }
        return $this->documents->list($actor);
    }

    /** @return array<string, mixed> */
    public function get(Actor $actor, array $input, array $params, array $query): array
    {
        if ($params['id'] === '' || strlen($params['id']) > 40 || $query !== []) {
            throw new AppError('VALIDATION_ERROR', 'id must be at most 40 characters and no query parameters are supported.');
        }
        $document = $this->documents->find($actor, $params['id']);
        if ($document === null) {
            throw new AppError('NOT_FOUND', 'Purchasing document not found.', 404);
        }
        return $document;
    }

    /** @return array{id: string, cnyid: int, name: string, message: string} */
    public function create(Actor $actor, array $input, array $params, array $query): array
    {
        if ($query !== []) {
            throw new AppError('VALIDATION_ERROR', 'Document creation does not accept query parameters.');
        }
        self::allowFields($input, [
            'docno', 'vendor_id', 'recordtype', 'order_id', 'receiver_id', 'invoice_id',
            'doc_date', 'vendor_ref', 'delivery_addr', 'payment_terms', 'status', 'duedate', 'entries',
        ]);
        $docno = self::requiredString($input, 'docno', 20);
        if (array_key_exists('recordtype', $input) && !is_string($input['recordtype'])) {
            throw new AppError('VALIDATION_ERROR', 'recordtype must be a string.');
        }
        $type = $input['recordtype'] ?? match (true) {
            str_starts_with($docno, 'PO-') => 'order',
            str_starts_with($docno, 'RCV-') => 'receiver',
            str_starts_with($docno, 'INV-') => 'invoice',
            default => '',
        };
        if (!is_string($type)) {
            throw new AppError('VALIDATION_ERROR', 'recordtype must be order, receiver or invoice.');
        }
        $prefix = match ($type) {
            'order' => 'PO-',
            'receiver' => 'RCV-',
            'invoice' => 'INV-',
            default => null,
        };
        if ($prefix === null || !str_starts_with($docno, $prefix)) {
            throw new AppError('VALIDATION_ERROR', 'docno prefix must match recordtype.');
        }
        $typeField = match ($type) {
            'order' => 'order_id',
            'receiver' => 'receiver_id',
            'invoice' => 'invoice_id',
        };
        foreach (['order_id', 'receiver_id', 'invoice_id'] as $field) {
            if ($field !== $typeField && array_key_exists($field, $input)) {
                throw new AppError('VALIDATION_ERROR', $field . ' is not valid for this recordtype.');
            }
        }
        $document = [
            'docno' => $docno,
            'vendor_id' => self::requiredString($input, 'vendor_id', 40),
            'recordtype' => $type,
            'doc_date' => self::date(self::requiredString($input, 'doc_date', 10), 'doc_date'),
            'vendor_ref' => self::optionalString($input, 'vendor_ref', 40),
            'delivery_addr' => self::optionalString($input, 'delivery_addr', 400),
            'payment_terms' => self::enum($input, 'payment_terms', ['due_on_receipt', 'net_15', 'net_30'], 'net_30'),
            'status' => self::enum($input, 'status', ['draft', 'pending', 'received', 'submitted', 'completed'], 'draft'),
            'duedate' => array_key_exists('duedate', $input)
                ? self::date(self::requiredString($input, 'duedate', 10), 'duedate')
                : null,
        ];
        if (array_key_exists($typeField, $input)) {
            $document[$typeField] = self::requiredString($input, $typeField, 40);
        }
        if ($type === 'invoice') {
            $expectedDueDate = self::dueDate($document['doc_date'], $document['payment_terms']);
            if ($document['duedate'] !== null && $document['duedate'] !== $expectedDueDate) {
                throw new AppError('VALIDATION_ERROR', 'duedate must match doc_date and payment_terms.');
            }
            $document['duedate'] = $expectedDueDate;
        }
        if (!isset($input['entries']) || !is_array($input['entries']) || !array_is_list($input['entries']) || $input['entries'] === []) {
            throw new AppError('VALIDATION_ERROR', 'entries must be a non-empty JSON array.');
        }
        $entries = [];
        $lineNumbers = [];
        foreach ($input['entries'] as $entry) {
            if (!is_array($entry)) {
                throw new AppError('VALIDATION_ERROR', 'Each entry must be a JSON object.');
            }
            $entries[] = self::createEntry($entry, $lineNumbers);
        }
        return $this->documents->create($actor, $document, $entries);
    }

    /** @return array<string, mixed> */
    public function update(Actor $actor, array $input, array $params, array $query): array
    {
        if ($query !== []) {
            throw new AppError('VALIDATION_ERROR', 'Document update does not accept query parameters.');
        }
        if ($params['id'] === '' || strlen($params['id']) > 40) {
            throw new AppError('VALIDATION_ERROR', 'id must be a non-empty string of at most 40 characters.');
        }
        self::allowFields($input, ['payment_terms', 'status', 'entries']);
        if ($input === []) {
            throw new AppError('VALIDATION_ERROR', 'Provide payment_terms, status or entries to update.');
        }
        $changes = [];
        if (array_key_exists('payment_terms', $input)) {
            $changes['payment_terms'] = self::enum($input, 'payment_terms', ['due_on_receipt', 'net_15', 'net_30']);
        }
        if (array_key_exists('status', $input)) {
            $changes['status'] = self::enum($input, 'status', ['draft', 'pending', 'received', 'submitted', 'completed']);
        }
        if (array_key_exists('entries', $input)) {
            if (!is_array($input['entries']) || !array_is_list($input['entries']) || $input['entries'] === []) {
                throw new AppError('VALIDATION_ERROR', 'entries must be a non-empty JSON array.');
            }
            $entries = [];
            $lineNumbers = [];
            foreach ($input['entries'] as $entry) {
                if (!is_array($entry)) {
                    throw new AppError('VALIDATION_ERROR', 'Each entry must be a JSON object.');
                }
                $entries[] = self::updateEntry($entry, $lineNumbers);
            }
            $changes['entries'] = $entries;
        }
        return $this->documents->update($actor, $params['id'], $changes);
    }

    private static function allowFields(array $input, array $allowed): void
    {
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown !== []) {
            throw new AppError('VALIDATION_ERROR', 'Unsupported field: ' . (string) reset($unknown) . '.');
        }
    }

    private static function requiredString(array $input, string $field, int $maxLength): string
    {
        if (!isset($input[$field]) || !is_string($input[$field])) {
            throw new AppError('VALIDATION_ERROR', $field . ' must be a string.');
        }
        $value = trim($input[$field]);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new AppError('VALIDATION_ERROR', $field . ' must contain 1-' . $maxLength . ' characters.');
        }
        return $value;
    }

    private static function optionalString(array $input, string $field, int $maxLength): ?string
    {
        if (!array_key_exists($field, $input)) {
            return null;
        }
        if (!is_string($input[$field]) || mb_strlen($input[$field]) > $maxLength) {
            throw new AppError('VALIDATION_ERROR', $field . ' must be a string of at most ' . $maxLength . ' characters.');
        }
        return $input[$field];
    }

    private static function enum(array $input, string $field, array $allowed, ?string $default = null): string
    {
        if (!array_key_exists($field, $input)) {
            if ($default !== null) {
                return $default;
            }
            throw new AppError('VALIDATION_ERROR', $field . ' is required.');
        }
        if (!is_string($input[$field]) || !in_array($input[$field], $allowed, true)) {
            throw new AppError('VALIDATION_ERROR', $field . ' has an unsupported value.');
        }
        return $input[$field];
    }

    private static function date(string $value, string $field): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new AppError('VALIDATION_ERROR', $field . ' must use YYYY-MM-DD.');
        }
        return $value;
    }

    private static function dueDate(string $date, string $terms): string
    {
        $days = match ($terms) {
            'due_on_receipt' => 0,
            'net_15' => 15,
            'net_30' => 30,
        };
        return (new DateTimeImmutable($date))->modify('+' . $days . ' days')->format('Y-m-d');
    }

    /** @param array<int, true> $lineNumbers
     *  @return array<string, mixed>
     */
    private static function createEntry(array $entry, array &$lineNumbers): array
    {
        self::allowFields($entry, ['doc_id', 'lineno', 'product_id', 'description', 'quantity', 'unitcost', 'notes', 'lineamount']);
        $line = self::positiveInteger($entry, 'lineno');
        if (isset($lineNumbers[$line])) {
            throw new AppError('VALIDATION_ERROR', 'lineno values must be unique within a document.');
        }
        $lineNumbers[$line] = true;
        $quantity = self::numeric($entry, 'quantity', 3, true);
        $unitCost = self::numeric($entry, 'unitcost', 2, false, 1e36);
        $lineAmount = self::calculatedAmount($quantity, $unitCost);
        if (array_key_exists('lineamount', $entry)
            && abs((float) self::numeric($entry, 'lineamount', 2, false) - (float) $lineAmount) > 0.000001) {
            throw new AppError('VALIDATION_ERROR', 'lineamount must equal quantity multiplied by unitcost, rounded to two decimals.');
        }
        $result = [
            'lineno' => $line,
            'product_id' => self::requiredString($entry, 'product_id', 20),
            'description' => self::requiredString($entry, 'description', 200),
            'quantity' => $quantity,
            'unitcost' => $unitCost,
            'lineamount' => $lineAmount,
            'notes' => self::optionalString($entry, 'notes', 500),
        ];
        if (array_key_exists('doc_id', $entry)) {
            $result['doc_id'] = self::requiredString($entry, 'doc_id', 40);
        }
        return $result;
    }

    /** @param array<int, true> $lineNumbers
     *  @return array<string, mixed>
     */
    private static function updateEntry(array $entry, array &$lineNumbers): array
    {
        if (array_key_exists('id', $entry)) {
            self::allowFields($entry, ['id', 'lineno', 'description', 'notes']);
            $result = ['id' => self::requiredString($entry, 'id', 40)];
            if (array_key_exists('lineno', $entry)) {
                $result['lineno'] = self::positiveInteger($entry, 'lineno');
            }
            foreach (['description' => 200, 'notes' => 500] as $field => $maxLength) {
                if (array_key_exists($field, $entry)) {
                    $result[$field] = self::requiredString($entry, $field, $maxLength);
                }
            }
            if (!isset($result['description']) && !isset($result['notes'])) {
                throw new AppError('VALIDATION_ERROR', 'An existing entry requires description or notes.');
            }
            return $result;
        }
        return self::createEntry($entry, $lineNumbers);
    }

    private static function positiveInteger(array $input, string $field): int
    {
        if (!isset($input[$field]) || !is_int($input[$field]) || $input[$field] < 1) {
            throw new AppError('VALIDATION_ERROR', $field . ' must be a positive JSON integer.');
        }
        return $input[$field];
    }

    private static function numeric(
        array $input,
        string $field,
        int $scale,
        bool $positive,
        float $maximum = 1e35,
    ): string
    {
        $value = $input[$field] ?? null;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)
            || ($positive && $value <= 0) || abs($value) >= $maximum
            || abs($value - round($value, $scale)) > 0.0000001) {
            throw new AppError('VALIDATION_ERROR', $field . ' must be a valid number with at most ' . $scale . ' decimal places.');
        }
        return number_format((float) $value, $scale, '.', '');
    }

    private static function calculatedAmount(string $quantity, string $unitCost): string
    {
        $amount = round((float) $quantity * (float) $unitCost, 2, PHP_ROUND_HALF_UP);
        if (!is_finite($amount) || abs($amount) >= 1e36) {
            throw new AppError('VALIDATION_ERROR', 'lineamount exceeds the supported range.');
        }
        return number_format($amount, 2, '.', '');
    }
}

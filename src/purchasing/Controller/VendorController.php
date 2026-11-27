<?php
declare(strict_types=1);

namespace MiniErp\purchasing\Controller;

use MiniErp\purchasing\Model\VendorModel;
use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\security\Actor;

final readonly class VendorController
{
    private const PAYMENT_TERMS = ['due_on_receipt', 'net_15', 'net_30'];

    public function __construct(private VendorModel $vendors) {}

    /** @return list<array<string, mixed>> */
    public function list(Actor $actor, array $input, array $params, array $query): array
    {
        $page = Api::pagination($query, ['search', 'status']);
        $filters = $page['filters'];
        if (isset($filters['search']) && mb_strlen($filters['search']) > 100) {
            throw new AppError('VALIDATION_ERROR', 'search must be at most 100 characters.');
        }
        if (isset($filters['status']) && !in_array($filters['status'], ['active', 'inactive'], true)) {
            throw new AppError('VALIDATION_ERROR', 'status must be active or inactive.');
        }
        return $this->vendors->list($actor, $page, $filters);
    }

    /** @return array{id: string, cnyid: int, name: string, message: string} */
    public function create(Actor $actor, array $input, array $params, array $query): array
    {
        self::allowFields($input, [
            'vendor_id', 'name', 'status', 'contact', 'email', 'phone', 'addr_1', 'addr_2',
            'city', 'state', 'postal_code', 'country', 'payment_terms',
        ]);
        $values = [
            'vendor_id' => self::requiredString($input, 'vendor_id', 20),
            'name' => trim(self::requiredString($input, 'name', 100)),
            'status' => self::enum($input, 'status', ['active', 'inactive'], 'active'),
            'contact' => self::optionalString($input, 'contact', 100),
            'email' => self::optionalString($input, 'email', 100),
            'phone' => self::optionalString($input, 'phone', 20),
            'addr_1' => trim(self::requiredString($input, 'addr_1', 150)),
            'addr_2' => self::optionalString($input, 'addr_2', 250),
            'city' => trim(self::requiredString($input, 'city', 80)),
            'state' => self::optionalString($input, 'state', 80),
            'postal_code' => self::optionalString($input, 'postal_code', 10),
            'country' => trim(self::requiredString($input, 'country', 80)),
            'payment_terms' => self::enum($input, 'payment_terms', self::PAYMENT_TERMS, 'net_30'),
        ];
        if (!preg_match('/\A[A-Za-z0-9-]+\z/', $values['vendor_id'])) {
            throw new AppError('VALIDATION_ERROR', 'vendor_id must contain only letters, numbers or hyphens.');
        }
        foreach (['name', 'addr_1', 'city', 'country'] as $field) {
            if ($values[$field] === '') {
                throw new AppError('VALIDATION_ERROR', $field . ' must not be blank.');
            }
        }
        if ($values['email'] !== null && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new AppError('VALIDATION_ERROR', 'email must be a valid email address.');
        }
        if ($values['phone'] !== null && !preg_match('/\A[6-9][0-9]{9}\z/', $values['phone'])) {
            throw new AppError('VALIDATION_ERROR', 'phone must be a 10-digit Indian mobile number.');
        }
        if ($values['postal_code'] !== null && !preg_match('/\A[A-Za-z0-9]+\z/', $values['postal_code'])) {
            throw new AppError('VALIDATION_ERROR', 'postal_code must contain only letters and numbers.');
        }
        if ($this->vendors->codeExists($actor, $values['vendor_id'])) {
            throw new AppError('CONFLICT', 'A vendor with this vendor_id already exists in this company.', 409);
        }
        $saved = $this->vendors->create($actor, $values);
        return $saved + ['message' => 'Vendor created successfully.'];
    }

    /** @return array<string, mixed> */
    public function get(Actor $actor, array $input, array $params, array $query): array
    {
        self::validateId($params['id']);
        $vendor = $this->vendors->find($actor, $params['id']);
        if ($vendor === null) {
            throw new AppError('NOT_FOUND', 'Vendor not found.', 404);
        }
        return $vendor;
    }

    /** @return array{id: string, cnyid: int, name: string, message: string} */
    public function update(Actor $actor, array $input, array $params, array $query): array
    {
        self::validateId($params['id']);
        self::allowFields($input, ['status', 'payment_terms']);
        if ($input === []) {
            throw new AppError('VALIDATION_ERROR', 'Provide status or payment_terms to update.');
        }
        $changes = [];
        if (array_key_exists('status', $input)) {
            $changes['status'] = self::enum($input, 'status', ['active', 'inactive']);
        }
        if (array_key_exists('payment_terms', $input)) {
            $changes['payment_terms'] = self::enum($input, 'payment_terms', self::PAYMENT_TERMS);
        }
        return $this->vendors->update($actor, $params['id'], $changes)
            + ['message' => 'Vendor updated successfully.'];
    }

    private static function allowFields(array $input, array $allowed): void
    {
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown !== []) {
            throw new AppError('VALIDATION_ERROR', 'Unsupported field: ' . (string) reset($unknown) . '.');
        }
    }

    private static function validateId(string $id): void
    {
        if ($id === '' || strlen($id) > 40) {
            throw new AppError('VALIDATION_ERROR', 'id must be a non-empty string of at most 40 characters.');
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
}

<?php

declare(strict_types=1);

namespace MiniErp\logistics\driver;

use MiniErp\shared\AppError;

class DriverValidator
{

    public function validateCreate(array $data): array
    {
        try {
            $unknown = array_diff(array_keys($data), [
                'driver_code', 'user_id', 'first_name', 'last_name', 'phone',
                'licence_number', 'licence_expiry', 'isactive', 
            ]);

            if ($unknown !== []) {
                throw new \InvalidArgumentException('Unknown field: ' . implode(', ', array_map('strval', $unknown)) . '.');
            }

            return [
                'driver_code' => $this->validateDriverCode($data['driver_code'] ?? null),
                'user_id' => $this->validateUserId($data['user_id'] ?? null),
                'first_name' => $this->validateFirstName($data['first_name'] ?? null),
                'last_name' => $this->validateLastName($data['last_name'] ?? null),
                'phone' => $this->validatePhone($data['phone'] ?? null),
                'licence_number' => $this->validateLicenceNumber($data['licence_number'] ?? null),
                'licence_expiry' => $this->validateLicenceExpiry($data['licence_expiry'] ?? null),
                'isactive' => $this->validateIsActive($data['isactive'] ?? 'T'),
            ];
        } catch (\InvalidArgumentException $error) {
            throw new AppError('VALIDATION_ERROR', $error->getMessage());
        }
    }

    public function validateUpdate(array $data): array
    {
        return $this->validateCreate($data);
    }

    public function validateId(int $id): void
    {
        if ($id <= 0) {
            throw new AppError('VALIDATION_ERROR', 'Driver ID must be a positive integer.');
        }
    }

    private function validateDriverCode(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('Driver code must be a non-empty string.');
        }

        $value = trim($value);

        if (strlen($value) > 30) {
            throw new \InvalidArgumentException('Driver code must not exceed 30 characters.');
        }

        return $value;
    }

    private function validateUserId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new \InvalidArgumentException('User ID must be a positive integer.');
        }

        return (int) $value;
    }

    private function validateFirstName(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('First name must be a non-empty string.');
        }

        $value = trim($value);

        if (strlen($value) > 100) {
            throw new \InvalidArgumentException('First name must not exceed 100 characters.');
        }

        return $value;
    }

    private function validateLastName(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('Last name must be a non-empty string.');
        }

        $value = trim($value);

        if (strlen($value) > 100) {
            throw new \InvalidArgumentException('Last name must not exceed 100 characters.');
        }

        return $value;
    }

    private function validatePhone(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException('Phone must be a string.');
        }

        $value = trim($value);

        if (strlen($value) > 30) {
            throw new \InvalidArgumentException('Phone must not exceed 30 characters.');
        }

        if (!preg_match('/^[0-9+\-()\s]+$/', $value)) {
            throw new \InvalidArgumentException('Phone contains invalid characters.');
        }

        return $value;
    }

    private function validateLicenceNumber(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('Licence number must be a non-empty string.');
        }

        $value = trim($value);

        if (strlen($value) > 50) {
            throw new \InvalidArgumentException('Licence number must not exceed 50 characters.');
        }

        return $value;
    }

    private function validateLicenceExpiry(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('Licence expiry is required.');
        }

        $value = trim($value);

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Licence expiry must be a valid date in YYYY-MM-DD format.');
        }

        return $value;
    }

    private function validateIsActive(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('ISACTIVE must be T or F.');
        }

        $value = strtoupper(trim($value));

        if (!in_array($value, ['T', 'F'], true)) {
            throw new \InvalidArgumentException('ISACTIVE must be T or F.');
        }

        return $value;
    }
}
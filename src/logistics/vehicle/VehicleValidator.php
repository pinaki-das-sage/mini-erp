<?php

declare(strict_types=1);

namespace MiniErp\logistics\vehicle;

use MiniErp\shared\AppError;

/** Validates and normalizes vehicle request fields without accessing the database. */
final class VehicleValidator
{
    public function validatePagination(array $pagination): array
    {
        $page = $pagination['page'] ?? null;
        $size = $pagination['page_size'] ?? null;
        if (!is_int($page) || $page < 1 || !is_int($size) || $size < 1 || $size > 100) {
            throw new AppError('VALIDATION_ERROR', 'page must be positive; page_size must be between 1 and 100.', 400);
        }
        if ($page - 1 > intdiv(PHP_INT_MAX, $size)) {
            throw new AppError('VALIDATION_ERROR', 'Requested page is too large.', 400);
        }
        $filters = $pagination['filters'] ?? [];
        if (!is_array($filters) || array_diff(array_keys($filters), ['active', 'search']) !== []) {
            throw new AppError('VALIDATION_ERROR', 'Unsupported vehicle filter.', 400);
        }
        foreach ($filters as $value) {
            if (!is_string($value)) {
                throw new AppError('VALIDATION_ERROR', 'Vehicle filters must be strings.', 400);
            }
        }
        if (array_key_exists('active', $filters) && !in_array($filters['active'], ['true', 'false'], true)) {
            throw new AppError('VALIDATION_ERROR', 'active must be true or false.', 400);
        }
        if (array_key_exists('search', $filters)) {
            $filters['search'] = trim($filters['search']);
            if (strlen($filters['search']) > 100) {
                throw new AppError('VALIDATION_ERROR', 'search must not exceed 100 bytes.', 400);
            }
        }
        return ['page' => $page, 'page_size' => $size, 'filters' => $filters];
    }

    public function validateCreate(array $input): array
    {
        $this->allowedFields($input, ['action_reference', 'vehicle_code', 'registration_number', 'description', 'capacity', 'is_active']);
        $this->validateReference($input);
        $code = $this->vehicleCode($input['vehicle_code'] ?? null);
        if (!is_string($input['registration_number'] ?? null)) {
            throw new AppError('VALIDATION_ERROR', 'registration_number is required and must be a string.', 400);
        }
        $registration = $this->registration($input['registration_number']);
        $capacity = $this->capacity($input['capacity'] ?? null);
        $description = $this->description($input['description'] ?? null);
        $active = $this->active(array_key_exists('is_active', $input) ? $input['is_active'] : 'T');
        return [
            'vehicle_code' => $code,
            'registration_number' => $registration,
            'capacity' => $capacity,
            'description' => $description,
            'is_active' => $active,
        ];
    }

    /** Return only submitted editable columns, preserving null as a requested change. */
    public function validateUpdate(array $input): array
    {
        $editable = ['vehicle_code', 'registration_number', 'description', 'capacity', 'is_active'];
        $this->allowedFields($input, ['action_reference', ...$editable]);
        $this->validateReference($input);
        if (array_intersect(array_keys($input), $editable) === []) {
            throw new AppError('VALIDATION_ERROR', 'Provide at least one vehicle field to update.', 400);
        }
        $values = [];
        if (array_key_exists('vehicle_code', $input)) {
            $values['VEHICLE_CODE'] = $this->vehicleCode($input['vehicle_code']);
        }
        if (array_key_exists('registration_number', $input)) {
            if (!is_string($input['registration_number'])) {
                throw new AppError('VALIDATION_ERROR', 'registration_number must be a nonempty string.', 400);
            }
            $values['REGISTRATION_NUMBER'] = $this->registration($input['registration_number']);
        }
        if (array_key_exists('description', $input)) {
            $values['DESCRIPTION'] = $this->description($input['description']);
        }
        if (array_key_exists('capacity', $input)) {
            $values['CAPACITY'] = $this->capacity($input['capacity']);
        }
        if (array_key_exists('is_active', $input)) {
            $values['ISACTIVE'] = $this->active($input['is_active']);
        }
        return $values;
    }

    public function validateDelete(array $input): void
    {
        $this->allowedFields($input, ['action_reference']);
        $this->validateReference($input);
    }

    public function validateId(int $id): void
    {
        if ($id < 1) {
            throw new AppError('VALIDATION_ERROR', 'Vehicle ID must be a positive integer.', 400);
        }
    }

    private function allowedFields(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new AppError('VALIDATION_ERROR', 'Request contains unsupported or read-only fields.', 400);
        }
    }

    private function validateReference(array $input): void
    {
        if (!is_string($input['action_reference'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $input['action_reference'])) {
            throw new AppError('VALIDATION_ERROR', 'action_reference requires 1-100 letters, digits, underscores or hyphens.', 400);
        }
        // Reference syntax is validated here; replay protection remains separate work.
    }

    private function vehicleCode(mixed $value): int
    {
        if (!is_int($value) || $value < 1) {
            throw new AppError('VALIDATION_ERROR', 'vehicle_code must be a positive JSON integer.', 400);
        }
        return $value;
    }

    private function registration(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '' || strlen($value) > 40 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new AppError('VALIDATION_ERROR', 'registration_number must contain 1-40 bytes without control characters.', 400);
        }
        return $value;
    }

    private function capacity(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,3})?$/D', $value) || !preg_match('/[1-9]/', $value)) {
            throw new AppError('VALIDATION_ERROR', 'capacity must be a positive KG decimal string with at most 9 integer digits and 3 decimal places.', 400);
        }
        return $value;
    }

    private function description(mixed $value): ?string
    {
        if ($value !== null && (!is_string($value) || strlen($value) > 255)) {
            throw new AppError('VALIDATION_ERROR', 'description must be a string of at most 255 bytes or null.', 400);
        }
        return $value;
    }

    private function active(mixed $value): string
    {
        if (!in_array($value, ['T', 'F'], true)) {
            throw new AppError('VALIDATION_ERROR', 'is_active must be T or F.', 400);
        }
        return $value;
    }
}

<?php

declare(strict_types=1);

namespace MiniErp\logistics\trip;

use MiniErp\shared\AppError;

class TripValidator
{

    public function validateCreate(array $data): array
    {
        try {
            $unknown = array_diff(array_keys($data), [
                'order_number', 'trip_code', 'vehicle_id', 'driver_id', 'planned_departure_at',
            ]);

            if ($unknown !== []) {
                throw new \InvalidArgumentException('Unknown field: ' . implode(', ', array_map('strval', $unknown)) . '.');
            }

            return [
                'order_number' => $this->validateOrderNumber($data['order_number'] ?? null),
                'trip_code' => $this->validatePositiveInteger($data['trip_code'] ?? null, 'Trip code'),
                'vehicle_id' => $this->validatePositiveInteger($data['vehicle_id'] ?? null, 'Vehicle ID'),
                'driver_id' => $this->validatePositiveInteger($data['driver_id'] ?? null, 'Driver ID'),
                'planned_departure_at' => $this->validatePlannedDepartureAt($data['planned_departure_at'] ?? null),
            ];
        } catch (\InvalidArgumentException $error) {
            throw new AppError('VALIDATION_ERROR', $error->getMessage());
        }
    }

    public function validateUpdate(array $data): array
    {
        try {
            $unknown = array_diff(array_keys($data), [
                'vehicle_id', 'driver_id', 'planned_departure_at',
            ]);

            if ($unknown !== []) {
                throw new \InvalidArgumentException('Unknown field: ' . implode(', ', array_map('strval', $unknown)) . '.');
            }

            return [
                'vehicle_id' => $this->validatePositiveInteger($data['vehicle_id'] ?? null, 'Vehicle ID'),
                'driver_id' => $this->validatePositiveInteger($data['driver_id'] ?? null, 'Driver ID'),
                'planned_departure_at' => $this->validatePlannedDepartureAt($data['planned_departure_at'] ?? null),
            ];
        } catch (\InvalidArgumentException $error) {
            throw new AppError('VALIDATION_ERROR', $error->getMessage());
        }
    }

    public function validateCancel(array $data): array
    {
        try {
            $unknown = array_diff(array_keys($data), ['reason']);

            if ($unknown !== []) {
                throw new \InvalidArgumentException('Unknown field: ' . implode(', ', array_map('strval', $unknown)) . '.');
            }

            $reason = $data['reason'] ?? null;

            if (!is_string($reason) || trim($reason) === '') {
                throw new \InvalidArgumentException('Cancellation reason must be a non-empty string.');
            }

            $reason = trim($reason);

            if (strlen($reason) > 500) {
                throw new \InvalidArgumentException('Cancellation reason must not exceed 500 characters.');
            }

            return ['reason' => $reason];
        } catch (\InvalidArgumentException $error) {
            throw new AppError('VALIDATION_ERROR', $error->getMessage());
        }
    }

    public function validateDispatch(array $data): array
    {
        if ($data !== []) {
            throw new AppError('VALIDATION_ERROR', 'Dispatch request must be an empty object.');
        }

        return [];
    }

    public function validateDeliver(array $data): array
    {
        if ($data !== []) {
            throw new AppError('VALIDATION_ERROR', 'Delivery request must be an empty object.');
        }

        return [];
    }

    public function validateId(int $id): void
    {
        if ($id <= 0) {
            throw new AppError('VALIDATION_ERROR', 'Trip ID must be a positive integer.');
        }
    }

    private function validatePositiveInteger(mixed $value, string $label): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new \InvalidArgumentException($label . ' must be a positive integer.');
        }

        return (int) $value;
    }

    private function validateOrderNumber(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[A-Za-z0-9_-]{1,20}\z/D', $value)) {
            throw new \InvalidArgumentException('Order number must contain 1 to 20 letters, digits, underscores or hyphens.');
        }

        return $value;
    }

    private function validatePlannedDepartureAt(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('Planned departure must be a non-empty timestamp with a timezone offset.');
        }

        $value = trim($value);

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/D', $value, $matches)) {
            throw new \InvalidArgumentException('Planned departure must be a valid timestamp with a timezone offset.');
        }

        if (
            !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
            || (int) $matches[4] > 23
            || (int) $matches[5] > 59
            || (int) $matches[6] > 59
            || ($matches[7] !== 'Z' && ((int) substr($matches[7], 1, 2) > 23 || (int) substr($matches[7], 4, 2) > 59))
        ) {
            throw new \InvalidArgumentException('Planned departure must be a valid timestamp with a timezone offset.');
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \InvalidArgumentException('Planned departure must be a valid timestamp with a timezone offset.');
        }

        return $date->format('Y-m-d H:i:s.u P');
    }
}

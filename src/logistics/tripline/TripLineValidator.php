<?php

declare(strict_types=1);

namespace MiniErp\logistics\tripline;

use MiniErp\shared\AppError;

/** Request validation only. Source quantities and weights are checked by the service. */
final class TripLineValidator
{
    public function id(mixed $value): int
    {
        $id = is_int($value) || is_string($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new AppError('VALIDATION_ERROR', 'ID must be a positive integer.', 400);
        }
        return $id;
    }

    public function create(array $input): array
    {
        // Product and quantity are read from the selected Sales line, never submitted on create.
        $this->command($input, ['trip_id', 'sales_line_id']);
        foreach (['trip_id', 'sales_line_id'] as $field) {
            $this->positiveInteger($input[$field] ?? null, $field);
        }
        return $input;
    }

    public function update(array $input): array
    {
        $this->command($input, ['sales_line_id', 'product_id', 'quantity']);
        if (array_intersect(array_keys($input), ['sales_line_id', 'product_id', 'quantity']) === []) {
            throw new AppError('VALIDATION_ERROR', 'Provide a source field to update or refresh.', 400);
        }
        if (array_key_exists('sales_line_id', $input)) {
            $this->positiveInteger($input['sales_line_id'], 'sales_line_id');
        }
        $this->optionalSourceFields($input);
        return $input;
    }

    public function delete(array $input): void
    {
        $this->command($input, []);
    }

    public function pagination(array $pagination): array
    {
        $page = $pagination['page'] ?? null;
        $size = $pagination['page_size'] ?? null;
        if (
            !is_int($page) || $page < 1 || $page > 1000000 || !is_int($size) || $size < 1 || $size > 100
            || $page - 1 > intdiv(PHP_INT_MAX, $size)
        ) {
            throw new AppError('VALIDATION_ERROR', 'Invalid trip-line pagination.', 400);
        }
        $filters = $pagination['filters'] ?? [];
        if (!is_array($filters) || array_diff(array_keys($filters), ['trip_id']) !== []) {
            throw new AppError('VALIDATION_ERROR', 'Unsupported trip-line filter.', 400);
        }
        return [
            'page' => $page,
            'page_size' => $size,
            'offset' => ($page - 1) * $size,
            'trip_id' => array_key_exists('trip_id', $filters) ? $this->id($filters['trip_id']) : null
        ];
    }

    private function command(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), ['action_reference', ...$allowed]) !== []) {
            throw new AppError('VALIDATION_ERROR', 'Request contains unsupported or read-only fields.', 400);
        }
        if (!is_string($input['action_reference'] ?? null) || !preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $input['action_reference'])) {
            throw new AppError('VALIDATION_ERROR', 'action_reference requires 1-100 letters, digits, underscores or hyphens.', 400);
        }
    }

    private function optionalSourceFields(array $input): void
    {
        foreach (['product_id', 'quantity'] as $field) {
            if (array_key_exists($field, $input)) {
                $this->positiveInteger($input[$field], $field);
            }
        }
    }

    private function positiveInteger(mixed $value, string $field): void
    {
        if (!is_int($value) || $value < 1) {
            throw new AppError('VALIDATION_ERROR', $field . ' must be a positive JSON integer.', 400);
        }
    }
}

<?php

declare(strict_types=1);

namespace MiniErp\logistics\tripline;

use MiniErp\shared\AppError;

/** Validate trip-line read IDs, pagination and the optional trip filter. */
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
}

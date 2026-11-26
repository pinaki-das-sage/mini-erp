<?php
declare(strict_types=1);

namespace MiniErp\sales;

use MiniErp\shared\AppError;

/** Browser lists use one date range control; services retain their existing date parameters. */
final class ListingFilters
{
    public static function query(array $query): array
    {
        $query['page_size'] = '25';
        if (array_key_exists('date_range', $query)) {
            $range = $query['date_range'];
            unset($query['date_range'], $query['from_date'], $query['to_date']);
            if (!is_string($range)) { throw new AppError('VALIDATION_ERROR', 'Enter a valid Date from/to range.'); }
            $range = trim($range);
            if ($range !== '') {
                if (!preg_match('/\A(\d{4}-\d{2}-\d{2})(?:\s*(?:to|[-–—])\s*(\d{4}-\d{2}-\d{2}))?\z/uiD', $range, $dates)) {
                    throw new AppError('VALIDATION_ERROR', 'Use Date from/to like 2026-11-24 to 2026-11-25.');
                }
                $query['from_date'] = Validation::date($dates[1], 'From Date');
                $query['to_date'] = Validation::date($dates[2] ?? $dates[1], 'To Date');
                Validation::dateRange($query['to_date'], $query['from_date'], 'To Date', 'From Date');
            }
        }
        return $query;
    }

    public static function range(array $query): string
    {
        if (is_string($query['date_range'] ?? null)) { return $query['date_range']; }
        $from = is_string($query['from_date'] ?? null) ? $query['from_date'] : '';
        $to = is_string($query['to_date'] ?? null) ? $query['to_date'] : '';
        return $from !== '' && $to !== '' ? $from . ' to ' . $to : '';
    }
}

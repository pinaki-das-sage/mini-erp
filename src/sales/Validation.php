<?php
declare(strict_types=1);

namespace MiniErp\sales;

use MiniErp\shared\AppError;

final class Validation
{
    public static function text(mixed $value, string $label, int $limit, bool $required = true): string
    {
        if ($value === null && !$required) {
            return '';
        }
        if (!is_string($value)) {
            throw new AppError('VALIDATION_ERROR', $label . ' must be text.');
        }
        $value = trim($value);
        if ($required && $value === '') {
            throw new AppError('VALIDATION_ERROR', 'Enter ' . $label . '.');
        }
        if (mb_strlen($value, 'UTF-8') > $limit) {
            throw new AppError('VALIDATION_ERROR', $label . ' must be ' . $limit . ' characters or fewer.');
        }
        return $value;
    }

    /** Validate exact decimal strings without converting to float or silently truncating. */
    public static function decimal(mixed $value, string $label, int $wholeDigits, int $scale, bool $allowZero): string
    {
        if ($wholeDigits < 1 || $scale < 0) {
            throw new \InvalidArgumentException('Invalid decimal capacity.');
        }
        $valid = is_string($value) && preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D', $value) === 1;
        if ($valid) {
            $parts = explode('.', $value);
            $valid = strlen(ltrim($parts[0], '0')) <= $wholeDigits
                && strlen($parts[1] ?? '') <= $scale
                && ($allowZero || preg_match('/[1-9]/', $value) === 1);
        }
        if (!$valid) {
            throw new AppError('VALIDATION_ERROR', $label . ' must be a decimal string ' . ($allowZero ? 'of 0 or more' : 'greater than 0')
                . ', with up to ' . $wholeDigits . ' whole digits and ' . $scale . ' decimal places.');
        }
        return $value;
    }

    public static function date(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D', $value, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new AppError('VALIDATION_ERROR', 'Enter a valid ' . $label . ' in YYYY-MM-DD format.');
        }
        return $value;
    }

    public static function dateRange(string $date, string $earlierDate, string $label, string $earlierLabel): void
    {
        self::date($date, $label);
        self::date($earlierDate, $earlierLabel);
        if (strcmp($date, $earlierDate) < 0) {
            throw new AppError('VALIDATION_ERROR', $label . ' must be on or after ' . $earlierLabel . '.');
        }
    }

    /** Business date is supplied by the application clock in its configured business timezone. */
    public static function notFuture(string $date, string $businessDate, string $label): void
    {
        self::date($date, $label);
        self::date($businessDate, 'Business Date');
        if (strcmp($date, $businessDate) > 0) {
            throw new AppError('VALIDATION_ERROR', $label . ' cannot be in the future.');
        }
    }

    /** @param array<string, mixed> $input @param list<string> $allowedFields */
    public static function allowedFields(array $input, array $allowedFields): void
    {
        foreach (array_keys($input) as $field) {
            if (!in_array($field, $allowedFields, true)) {
                throw new AppError('VALIDATION_ERROR', 'This request contains an unsupported or read-only field.');
            }
        }
    }
}

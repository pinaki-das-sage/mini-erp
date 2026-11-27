<?php
declare(strict_types=1);

namespace MiniErp\inventory;

use MiniErp\shared\AppError;

/** Products are stored in whole grams. People see and type kilograms (1 kg = 1000 g). */
final class ProductWeight
{
    private const MAX_GRAMS = 9999999999;

    /** 500 becomes "0.5", 1000 becomes "1", 12345 becomes "12.345". Exact: no floating point involved. */
    public static function kilograms(int $grams): string
    {
        $text = sprintf('%d.%03d', intdiv($grams, 1000), $grams % 1000);
        return rtrim(rtrim($text, '0'), '.');
    }

    /**
     * Kilograms to whole grams. Accepts a JSON number; with $allowText also text typed in a form such as "0.5".
     * Refuses anything below 0.001 kg, above 9999999.999 kg, or with more than 3 decimals.
     *
     * @throws AppError VALIDATION_ERROR
     */
    public static function grams(mixed $value, bool $allowText = false): int
    {
        $error = new AppError('VALIDATION_ERROR', 'Weight must be a number of kilograms, at least 0.001, with at most 3 decimals.');
        if (is_string($value) && $allowText) {
            if (preg_match('/\A([0-9]{1,7})(?:\.([0-9]{1,3}))?\z/', $value, $match) !== 1) {
                throw $error;
            }
            $grams = (int) $match[1] * 1000 + (int) str_pad($match[2] ?? '', 3, '0');
        } elseif (is_int($value) || is_float($value)) {
            $scaled = $value * 1000;
            $rounded = round($scaled);
            if (!is_finite($rounded) || abs($scaled - $rounded) > 1e-4 || $rounded < 1 || $rounded > self::MAX_GRAMS) {
                throw $error;
            }
            $grams = (int) $rounded;
        } else {
            throw $error;
        }
        if ($grams < 1 || $grams > self::MAX_GRAMS) {
            throw $error;
        }
        return $grams;
    }
}
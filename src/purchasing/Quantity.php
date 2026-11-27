<?php
declare(strict_types=1);

namespace MiniErp\purchasing;

use MiniErp\shared\AppError;

final class Quantity
{
    public const MAXIMUM = 9999999999;

    public static function fromInput(mixed $value): int
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value <= 0) {
            throw new AppError('VALIDATION_ERROR', 'quantity must be a positive JSON number.');
        }
        $rounded = round((float) $value, 0, PHP_ROUND_HALF_UP);
        if ($rounded < 1 || $rounded > self::MAXIMUM) {
            throw new AppError('VALIDATION_ERROR', 'quantity must round to between 1 and 9999999999 whole units.');
        }
        return (int) $rounded;
    }

    public static function fromStored(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new AppError('VALIDATION_ERROR', 'The stored quantity is invalid.');
        }
        return self::fromInput((float) $value);
    }
}

<?php
declare(strict_types=1);

namespace MiniErp\sales;

use MiniErp\shared\AppError;

/** Integer cents keep NUMBER(18,2) exact on the project's 64-bit PHP runtime. */
final class InvoiceMoney
{
    public const MAX_CENTS = 999999999999999999;

    public static function cents(mixed $value, string $label = 'Amount', int $wholeDigits = 16): int
    {
        Validation::decimal($value, $label, $wholeDigits, 2, true);
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return (int) ((ltrim($whole, '0') ?: '0') . str_pad($fraction, 2, '0'));
    }

    public static function format(int $cents): string
    {
        if ($cents < 0 || $cents > self::MAX_CENTS) {
            self::capacity();
        }
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function add(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $left > self::MAX_CENTS - $right) {
            self::capacity();
        }
        return $left + $right;
    }

    public static function line(mixed $quantity, mixed $price): array
    {
        if (!is_string($quantity) || !preg_match('/\A(?=[0-9]*[1-9])[0-9]{1,9}\z/D', $quantity)) {
            throw new AppError('VALIDATION_ERROR', 'Quantity must be a positive whole-number string with at most 9 digits.');
        }
        $count = (int) $quantity;
        if (!is_string($price) || !preg_match('/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/D', $price)) {
            throw new AppError('VALIDATION_ERROR', 'Unit Price must be a decimal string with up to 10 whole digits and 2 decimal places.');
        }
        $unit = self::cents($price, 'Unit Price', 10);
        if ($unit > intdiv(self::MAX_CENTS, $count)) {
            self::capacity();
        }
        return ['quantity' => (string) $count, 'unit_price' => self::format($unit), 'cents' => $unit * $count];
    }

    private static function capacity(): never
    {
        throw new AppError('VALIDATION_ERROR', 'Calculated amount exceeds 16 whole digits and 2 decimal places.');
    }
}

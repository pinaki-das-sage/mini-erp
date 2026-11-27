<?php
declare(strict_types=1);

namespace MiniErp\sales\quotes;

use MiniErp\shared\AppError;

/** Unsigned cents arithmetic; products can exceed PHP's integer range. */
final class QuoteMoney
{
    public static function multiply(string $quantity, string $price): string
    {
        [$whole, $fraction] = array_pad(explode('.', $price, 2), 2, '');
        $cents = ltrim($whole . str_pad($fraction, 2, '0'), '0') ?: '0';
        $result = '0';
        foreach (str_split($quantity) as $digit) {
            $result .= '0';
            for ($i = 0; $i < (int) $digit; ++$i) { $result = self::add($result, $cents); }
        }
        return self::capacity($result);
    }

    public static function add(string $a, string $b): string
    {
        $result = '';
        $carry = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry; --$i, --$j) {
            $sum = ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0) + $carry;
            $result = ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
        }
        return self::capacity($result);
    }

    public static function format(string $cents): string
    {
        $cents = str_pad(self::capacity($cents), 3, '0', STR_PAD_LEFT);
        return substr($cents, 0, -2) . '.' . substr($cents, -2);
    }

    private static function capacity(string $value): string
    {
        $value = ltrim($value, '0') ?: '0';
        if (strlen($value) > 18) { throw new AppError('VALIDATION_ERROR', 'Quote amount exceeds 16 whole digits and 2 decimal places.'); }
        return $value;
    }
}

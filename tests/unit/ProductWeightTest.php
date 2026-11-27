<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\inventory\ProductWeight;
use MiniErp\shared\AppError;
use PHPUnit\Framework\TestCase;

final class ProductWeightTest extends TestCase
{
    public function testGramsAreShownAsKilogramsWithoutTrailingZeros(): void
    {
        foreach ([[1, '0.001'], [70, '0.07'], [450, '0.45'], [500, '0.5'], [1000, '1'], [1050, '1.05'],
            [10000, '10'], [12345, '12.345'], [100000, '100'], [9999999999, '9999999.999']] as [$grams, $kg]) {
            self::assertSame($kg, ProductWeight::kilograms($grams), (string) $grams);
        }
    }

    public function testKilogramsAreConvertedToWholeGramsFromNumbersAndFormText(): void
    {
        foreach ([[0.5, 500], [1, 1000], [0.001, 1], [12.345, 12345], [0.07, 70], [9999999.999, 9999999999]] as [$kg, $grams]) {
            self::assertSame($grams, ProductWeight::grams($kg), (string) $kg);
        }
        foreach ([['0.5', 500], ['1', 1000], ['0.001', 1], ['12.345', 12345], ['0.07', 70], ['007.5', 7500], ['9999999.999', 9999999999], ['2.50', 2500]] as [$text, $grams]) {
            self::assertSame($grams, ProductWeight::grams($text, true), $text);
        }
    }

    public function testBadWeightsAreRefused(): void
    {
        $numbers = [0, -1, 0.0004, 1.2345, 10000000, true, null, [1], 'abc'];
        foreach ($numbers as $bad) {
            $this->assertRefused($bad, false);
            $this->assertRefused($bad, true);
        }
        foreach (['0', '0.000', '-1', '1,5', '1e3', ' 1', '1 ', '', '.5', '5.', '1.2345', '10000000', '10000000.5', '0x10'] as $text) {
            $this->assertRefused($text, true);
        }
        $this->assertRefused('0.5', false); // the REST API takes JSON numbers only; text is for the browser form
    }

    private function assertRefused(mixed $value, bool $allowText): void
    {
        try {
            ProductWeight::grams($value, $allowText);
            self::fail('Expected a refusal for ' . var_export($value, true));
        } catch (AppError $error) {
            self::assertSame('VALIDATION_ERROR', $error->errorCode);
            self::assertSame(400, $error->status);
        }
    }
}
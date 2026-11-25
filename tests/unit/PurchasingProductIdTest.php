<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\purchasing\Controller\PurchasingDocumentController;
use MiniErp\purchasing\Model\PurchasingDocumentModel;
use MiniErp\shared\AppError;
use PHPUnit\Framework\TestCase;

final class PurchasingProductIdTest extends TestCase
{
    public function testEntryAcceptsNumericProductId(): void
    {
        $method = new \ReflectionMethod(PurchasingDocumentController::class, 'createEntry');
        foreach ([1, 1001, 9999999999] as $productId) {
            $lines = [];
            $entry = $method->invokeArgs(null, [[
                'lineno' => 1, 'product_id' => $productId, 'description' => 'Product',
                'quantity' => 2, 'unitcost' => 10,
            ], &$lines]);
            self::assertSame($productId, $entry['product_id']);
        }
    }

    public function testEntryRejectsInvalidProductIds(): void
    {
        $method = new \ReflectionMethod(PurchasingDocumentController::class, 'createEntry');
        foreach (['1001', 'abc', 0, -1, 1.5, 10000000000, null, true] as $productId) {
            $lines = [];
            try {
                $method->invokeArgs(null, [[
                    'lineno' => 1, 'product_id' => $productId, 'description' => 'Product',
                    'quantity' => 2, 'unitcost' => 10,
                ], &$lines]);
                self::fail('Invalid product_id must be rejected.');
            } catch (AppError $error) {
                self::assertSame('VALIDATION_ERROR', $error->errorCode);
                self::assertStringContainsString('product_id', $error->getMessage());
            }
        }
    }

    public function testOracleProductIdIsReturnedAsJsonInteger(): void
    {
        $row = array_fill_keys([
            'CNYID', 'ID', 'DOC_ID', 'ENTRY_CNYID', 'ENTRY_ID', 'ENTRY_DOC_ID',
            'DESCRIPTION', 'QUANTITY', 'UNITCOST', 'LINEAMOUNT', 'NOTES',
            'CREATEDBY', 'MODIFIEDBY', 'WHENCREATED', 'WHENMODIFIED',
            'ENTRY_CREATEDBY', 'ENTRY_MODIFIEDBY', 'ENTRY_WHENCREATED', 'ENTRY_WHENMODIFIED',
        ], null);
        $row['LINENO'] = '1';
        $row['PRODUCT_ID'] = '1001';
        foreach (['mapEntry', 'mapJoinedEntry'] as $name) {
            $entry = (new \ReflectionMethod(PurchasingDocumentModel::class, $name))->invoke(null, $row);
            self::assertSame(1001, $entry['product_id']);
        }
    }
}

<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\purchasing\Model\PurchasingDocumentModel;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\TestCase;

final class PurchasingProductIdTest extends TestCase
{
    public function testProductIdsRemainIntegersInAllReadResponses(): void
    {
        // Simulate a database driver returning numeric columns as strings.
        $row = array_fill_keys([
            'DOC_CNYID', 'DOC_ID', 'VENDOR_ID', 'DOCNO', 'DOC_DATE', 'VENDOR_REF',
            'DELIVERY_ADDR', 'PAYMENT_TERMS', 'STATUS', 'ORDER_ID', 'RECEIVER_ID',
            'INVOICE_ID', 'DUEDATE', 'RECORDTYPE', 'AMOUNTDUE', 'TOTAL',
            'DOC_CREATEDBY', 'DOC_MODIFIEDBY', 'DOC_WHENCREATED', 'DOC_WHENMODIFIED',
            'ENTRY_CNYID', 'ENTRY_ID', 'ENTRY_DOC_ID', 'LINENO', 'PRODUCT_ID',
            'DESCRIPTION', 'QUANTITY', 'UNITCOST', 'LINEAMOUNT', 'NOTES',
            'ENTRY_CREATEDBY', 'ENTRY_MODIFIEDBY', 'ENTRY_WHENCREATED', 'ENTRY_WHENMODIFIED',
            'CNYID', 'ID', 'CREATEDBY', 'MODIFIEDBY', 'WHENCREATED', 'WHENMODIFIED',
        ], null);
        $row = array_replace($row, [
            'DOC_CNYID' => '7', 'DOC_ID' => 'document-1',
            'ENTRY_CNYID' => '7', 'ENTRY_ID' => 'entry-1', 'ENTRY_DOC_ID' => 'document-1',
            'CNYID' => '7', 'ID' => 'entry-1', 'LINENO' => '1', 'PRODUCT_ID' => '1001',
        ]);
        $actor = new Actor('user-1', 'user', [], 7);

        foreach (['order', 'receiver', 'invoice'] as $type) {
            $row['RECORDTYPE'] = $type;
            $db = $this->createMock(Connection::class);
            $db->method('query')->willReturn([$row]);
            $model = new PurchasingDocumentModel($db);

            $list = $model->list($actor);
            $document = $model->find($actor, 'document-1');
            $entries = $model->listEntries($actor, 'document-1');
            $entry = $model->findEntry($actor, 'entry-1');

            foreach ([$list[0]['entries'][0], $document['entries'][0], $entries[0], $entry] as $result) {
                self::assertSame(1001, $result['product_id']);
                // Match the API's JSON encoding, then check the client-visible type.
                $json = json_encode(['result' => $result], JSON_THROW_ON_ERROR);
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame(1001, $decoded['result']['product_id']);
            }
        }
    }
}

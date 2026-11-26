<?php
declare(strict_types=1);

use MiniErp\purchasing\Controller\VendorController;
use MiniErp\purchasing\Controller\PurchasingDocumentController;
use MiniErp\purchasing\Controller\PurchasingEntryController;
use MiniErp\purchasing\Model\VendorModel;
use MiniErp\purchasing\Model\PurchasingDocumentModel;
use MiniErp\shared\Api;
use MiniErp\shared\database\Connection;

return static function (Api $api, Connection $connection): void {
    $vendors = new VendorController(new VendorModel($connection));
    $api->register('GET', '/purchasing/vendors', 'purchasing.vendor', 'read', [$vendors, 'list']);
    $api->register('POST', '/purchasing/vendors', 'purchasing.vendor', 'create', [$vendors, 'create'], 201);
    $api->register('GET', '/purchasing/vendors/{id}', 'purchasing.vendor', 'read', [$vendors, 'get']);
    $api->register('POST', '/purchasing/vendors/{id}', 'purchasing.vendor', 'update', [$vendors, 'update']);

    $documents = new PurchasingDocumentController(new PurchasingDocumentModel($connection));
    $api->register('GET', '/purchasing/documents', 'purchasing.document', 'read', [$documents, 'list']);
    $api->register('POST', '/purchasing/documents', 'purchasing.document', 'create', [$documents, 'create'], 201);
    $api->register('GET', '/purchasing/documents/{id}', 'purchasing.document', 'read', [$documents, 'get']);
    $api->register('POST', '/purchasing/documents/{id}', 'purchasing.document', 'update', [$documents, 'update']);

    $entries = new PurchasingEntryController(new PurchasingDocumentModel($connection));
    $api->register('GET', '/purchasing/document-entries', 'purchasing.document_entry', 'read', [$entries, 'list']);
    $api->register('GET', '/purchasing/document-entries/{record}', 'purchasing.document_entry', 'read', [$entries, 'get']);
};

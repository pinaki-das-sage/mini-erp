<?php
declare(strict_types=1);

// Register capabilities used by the shared purchasing API routes.
// This migration registers available permissions; it grants no user access.
return [
    "INSERT INTO ME_OBJECTS (CODE, NAME, MODULE_CODE, ALLOW_CREATE, ALLOW_READ, ALLOW_UPDATE, ALLOW_DELETE)
     VALUES ('purchasing.vendor', 'Purchasing Vendors', 'purchasing', 1, 1, 1, 0)",
    "INSERT INTO ME_OBJECTS (CODE, NAME, MODULE_CODE, ALLOW_CREATE, ALLOW_READ, ALLOW_UPDATE, ALLOW_DELETE)
     VALUES ('purchasing.document', 'Purchasing Documents', 'purchasing', 1, 1, 1, 0)",
    "INSERT INTO ME_OBJECTS (CODE, NAME, MODULE_CODE, ALLOW_CREATE, ALLOW_READ, ALLOW_UPDATE, ALLOW_DELETE)
     VALUES ('purchasing.document_entry', 'Purchasing Document Entries', 'purchasing', 0, 1, 0, 0)",
];

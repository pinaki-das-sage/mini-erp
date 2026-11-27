<?php
declare(strict_types=1);

// Pending migration 007: vendor and document keys are unique per company.
// Preserve the recorded checksums of 001_vendor.php and 002_doc.php.
// Resolve existing duplicates before running; no business data is rewritten.
// Oracle DDL commits implicitly. Stop purchasing writes while applying.
// Conditional unique indexes omit null document keys so unrelated document
// types can leave ORDER_ID / RECEIVER_ID / INVOICE_ID empty on multiple rows.
return [
    <<<'SQL'
DECLARE
    duplicate_groups NUMBER;
BEGIN
    SELECT COUNT(*) INTO duplicate_groups
    FROM (
        SELECT 1 FROM ME_PUR_VENDOR
        GROUP BY CNYID, VENDOR_ID HAVING COUNT(*) > 1
        UNION ALL
        SELECT 1 FROM ME_PUR_DOC WHERE DOCNO IS NOT NULL
        GROUP BY CNYID, DOCNO HAVING COUNT(*) > 1
        UNION ALL
        SELECT 1 FROM ME_PUR_DOC WHERE ORDER_ID IS NOT NULL
        GROUP BY CNYID, ORDER_ID HAVING COUNT(*) > 1
        UNION ALL
        SELECT 1 FROM ME_PUR_DOC WHERE RECEIVER_ID IS NOT NULL
        GROUP BY CNYID, RECEIVER_ID HAVING COUNT(*) > 1
        UNION ALL
        SELECT 1 FROM ME_PUR_DOC WHERE INVOICE_ID IS NOT NULL
        GROUP BY CNYID, INVOICE_ID HAVING COUNT(*) > 1
    );
    IF duplicate_groups > 0 THEN
        RAISE_APPLICATION_ERROR(-20003,
            'Duplicate vendor/document keys exist within a company. Resolve them before applying migration 007.');
    END IF;
END;
SQL,
    'ALTER TABLE ME_PUR_VENDOR
        ADD CONSTRAINT ME_PUR_VENDOR_CODE_UK UNIQUE (CNYID, VENDOR_ID)
        ENABLE VALIDATE',
    'CREATE UNIQUE INDEX ME_PUR_DOC_DOCNO_UX ON ME_PUR_DOC
        (CASE WHEN DOCNO IS NOT NULL THEN CNYID END, DOCNO)',
    'CREATE UNIQUE INDEX ME_PUR_DOC_ORDER_ID_UX ON ME_PUR_DOC
        (CASE WHEN ORDER_ID IS NOT NULL THEN CNYID END, ORDER_ID)',
    'CREATE UNIQUE INDEX ME_PUR_DOC_RECEIVER_ID_UX ON ME_PUR_DOC
        (CASE WHEN RECEIVER_ID IS NOT NULL THEN CNYID END, RECEIVER_ID)',
    'CREATE UNIQUE INDEX ME_PUR_DOC_INVOICE_ID_UX ON ME_PUR_DOC
        (CASE WHEN INVOICE_ID IS NOT NULL THEN CNYID END, INVOICE_ID)',
];

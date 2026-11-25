<?php
declare(strict_types=1);

// Forward migration: keep 003_doc_entry.php unchanged for recorded checksums.
// Run with purchasing writes stopped: Oracle DDL commits implicitly.
// Existing IDs must already be numeric product IDs, not SKUs or product names.
// Add the company-scoped foreign key once the parent table name is confirmed.
return [
    <<<'SQL'
DECLARE
    invalid_count NUMBER;
BEGIN
    SELECT COUNT(*) INTO invalid_count
    FROM ME_PUR_DOC_ENTRY
    WHERE TRIM(PRODUCT_ID) IS NULL
       OR NOT REGEXP_LIKE(TRIM(PRODUCT_ID), '^[0-9]+$');

    IF invalid_count > 0 THEN
        RAISE_APPLICATION_ERROR(-20001,
            'PRODUCT_ID contains nonnumeric IDs. Map these to product IDs before migrating.');
    END IF;
END;
SQL,
    'ALTER TABLE ME_PUR_DOC_ENTRY ADD (PRODUCT_ID_NUM NUMBER)',
    'UPDATE ME_PUR_DOC_ENTRY SET PRODUCT_ID_NUM = TO_NUMBER(TRIM(PRODUCT_ID))',
    // Validate the copied values before removing the original column.
    'ALTER TABLE ME_PUR_DOC_ENTRY MODIFY (PRODUCT_ID_NUM NOT NULL)',
    'ALTER TABLE ME_PUR_DOC_ENTRY DROP COLUMN PRODUCT_ID',
    'ALTER TABLE ME_PUR_DOC_ENTRY RENAME COLUMN PRODUCT_ID_NUM TO PRODUCT_ID',
];

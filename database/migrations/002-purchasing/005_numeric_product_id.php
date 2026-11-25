<?php
declare(strict_types=1);

// Apply with purchasing writes stopped. Validate all existing values before DDL.
// Oracle commits DDL; restore a backup if a later step fails before completion.
return [
    "DECLARE
        invalid_count NUMBER;
    BEGIN
        SELECT COUNT(*) INTO invalid_count FROM ME_PUR_DOC_ENTRY
        WHERE CASE
            WHEN REGEXP_LIKE(PRODUCT_ID, '^[0-9]{1,20}$') THEN
                CASE WHEN TO_NUMBER(PRODUCT_ID) BETWEEN 1 AND 9999999999 THEN 0 ELSE 1 END
            ELSE 1
        END = 1;
        IF invalid_count > 0 THEN
            RAISE_APPLICATION_ERROR(-20001, 'PRODUCT_ID values must be positive integers at most 9999999999 before migration.');
        END IF;
    END;",
    'ALTER TABLE ME_PUR_DOC_ENTRY ADD (PRODUCT_ID_NUM NUMBER(10))',
    'UPDATE ME_PUR_DOC_ENTRY SET PRODUCT_ID_NUM = TO_NUMBER(PRODUCT_ID)',
    'ALTER TABLE ME_PUR_DOC_ENTRY MODIFY (PRODUCT_ID_NUM NOT NULL)',
    'ALTER TABLE ME_PUR_DOC_ENTRY DROP COLUMN PRODUCT_ID',
    'ALTER TABLE ME_PUR_DOC_ENTRY RENAME COLUMN PRODUCT_ID_NUM TO PRODUCT_ID',
    'ALTER TABLE ME_PUR_DOC_ENTRY ADD CONSTRAINT ME_PUR_DOC_ENTRY_PRODUCT_CK CHECK (PRODUCT_ID >= 1)',
];

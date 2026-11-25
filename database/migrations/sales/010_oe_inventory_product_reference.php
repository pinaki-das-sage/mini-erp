<?php
declare(strict_types=1);

// Requires Inventory's ME_INV_PRODUCTS and its (COMPANY_ID, PRODUCT_ID) primary key.
// Stop application writes and deploy the matching Quote service changes together.
// Approved mapping: company 1, document 1, line RECORD# 1, ITEM-001 -> Product 163.
// Preserve the saved line and its contents; correct its Draft Quote Warehouse to W01.
// An empty line table is also supported. Other existing lines need explicit mappings.
// Keep ITEM_CODE until the numeric reference is populated and the FK has validated it.
// Oracle DDL commits implicitly; follow migration recovery if a run is interrupted.
return [
    <<<'SQL'
    DECLARE
        line_count NUMBER;
        invalid_count NUMBER;
    BEGIN
        SELECT COUNT(*) INTO invalid_count
        FROM ME_OE_DOCUMENT_LINES
        WHERE NOT (CNY# = 1 AND RECORD# = 1 AND DOCUMENT_RECORD# = 1 AND ITEM_CODE = 'ITEM-001');
        IF invalid_count > 0 THEN
            RAISE_APPLICATION_ERROR(-20010,
                'Unmapped Sales lines exist. Only company 1 document 1 line 1 ITEM-001 to Product 163 is approved.');
        END IF;
        SELECT COUNT(*) INTO line_count FROM ME_OE_DOCUMENT_LINES;
        IF line_count > 0 THEN
            SELECT COUNT(*) INTO invalid_count FROM ME_INV_PRODUCTS
            WHERE COMPANY_ID = 1 AND PRODUCT_ID = 163 AND SKU = 'RF-001'
                AND WAREHOUSE_ID = 'W01' AND IS_ACTIVE = 'Y';
            IF invalid_count <> 1 THEN
                RAISE_APPLICATION_ERROR(-20011, 'Active company 1 Product 163 (RF-001) in W01 is required.');
            END IF;
            SELECT COUNT(*) INTO invalid_count FROM ME_OE_DOCUMENTS D
            WHERE D.CNY# = 1 AND D.RECORD# = 1 AND D.DOCUMENT_TYPE = 'SALES_QUOTE'
                AND D.STATUS = 'DRAFT' AND D.WAREHOUSE_ID IN ('WH-001', 'W01')
                AND NOT EXISTS (SELECT 1 FROM ME_OE_SOURCE_LINKS S
                    WHERE S.CNY# = D.CNY# AND S.SOURCE_DOCUMENT_RECORD# = D.RECORD#);
            IF invalid_count <> 1 THEN
                RAISE_APPLICATION_ERROR(-20012, 'Expected an unreferenced Draft Quote 1 in company 1.');
            END IF;
        END IF;
    END;
    SQL,
    'ALTER TABLE ME_OE_DOCUMENT_LINES ADD (PRODUCT_ID NUMBER(10))',
    <<<'SQL'
    DECLARE
        unmapped_count NUMBER;
    BEGIN
        UPDATE ME_OE_DOCUMENT_LINES
        SET PRODUCT_ID = 163, WHENMODIFIED = CURRENT_TIMESTAMP
        WHERE CNY# = 1 AND RECORD# = 1 AND DOCUMENT_RECORD# = 1 AND ITEM_CODE = 'ITEM-001';

        UPDATE ME_OE_DOCUMENTS D
        SET WAREHOUSE_ID = 'W01', VERSION = VERSION + 1, WHENMODIFIED = CURRENT_TIMESTAMP
        WHERE D.CNY# = 1 AND D.RECORD# = 1
            AND EXISTS (SELECT 1 FROM ME_OE_DOCUMENT_LINES L
                WHERE L.CNY# = D.CNY# AND L.DOCUMENT_RECORD# = D.RECORD#
                    AND L.RECORD# = 1 AND L.ITEM_CODE = 'ITEM-001' AND L.PRODUCT_ID = 163);

        SELECT COUNT(*) INTO unmapped_count FROM ME_OE_DOCUMENT_LINES WHERE PRODUCT_ID IS NULL;
        IF unmapped_count > 0 THEN
            RAISE_APPLICATION_ERROR(-20013, 'Some Sales lines have no approved Product mapping.');
        END IF;
    END;
    SQL,
    'ALTER TABLE ME_OE_DOCUMENT_LINES MODIFY (PRODUCT_ID NOT NULL)',
    'ALTER TABLE ME_OE_DOCUMENT_LINES ADD CONSTRAINT ME_OE_LINE_PRODUCT_FK
        FOREIGN KEY (CNY#, PRODUCT_ID)
        REFERENCES ME_INV_PRODUCTS (COMPANY_ID, PRODUCT_ID)',
    'ALTER TABLE ME_OE_DOCUMENT_LINES DROP COLUMN ITEM_CODE',
];

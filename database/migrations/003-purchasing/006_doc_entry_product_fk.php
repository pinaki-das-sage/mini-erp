<?php
declare(strict_types=1);

// Apply after 005_doc_entry_product_id_number.php; preserve earlier checksums.
// Prerequisites: ME_INV_PRODUCTS exists in this schema, COMPANY_ID and
// PRODUCT_ID are numeric, and (COMPANY_ID, PRODUCT_ID) has an enabled PRIMARY
// KEY or UNIQUE constraint. Inventory owns that parent key.
// Every existing entry must reference a product belonging to its own company.
// ENABLE VALIDATE checks existing rows as well as future inserts and updates.
// Oracle DDL commits implicitly; run during a purchasing maintenance window.
return [
    'ALTER TABLE ME_PUR_DOC_ENTRY
        ADD CONSTRAINT ME_PUR_DOC_ENTRY_PRODUCT_FK
        FOREIGN KEY (CNYID, PRODUCT_ID)
        REFERENCES ME_INV_PRODUCTS (COMPANY_ID, PRODUCT_ID)
        ENABLE VALIDATE',
    'CREATE INDEX ME_PUR_DOC_ENTRY_PRODUCT_IX
        ON ME_PUR_DOC_ENTRY (CNYID, PRODUCT_ID)',
];

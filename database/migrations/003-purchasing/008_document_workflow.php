<?php
declare(strict_types=1);

// Purchasing-owned workflow metadata. Apply before deploying the new UI/model.
return [
    'ALTER TABLE ME_PUR_DOC ADD (SOURCE_DOC_ID VARCHAR2(40), NOTES VARCHAR2(500))',
    'ALTER TABLE ME_PUR_DOC ADD CONSTRAINT ME_PUR_DOC_SOURCE_FK
        FOREIGN KEY (CNYID, SOURCE_DOC_ID) REFERENCES ME_PUR_DOC (CNYID, ID)',
    'CREATE UNIQUE INDEX ME_PUR_DOC_SOURCE_UX ON ME_PUR_DOC
        (CASE WHEN SOURCE_DOC_ID IS NOT NULL THEN CNYID END, SOURCE_DOC_ID)',
    "INSERT INTO ME_OBJECT_ACTIONS (OBJECT_CODE, ACTION_CODE, NAME)
     VALUES ('purchasing.document', 'submit', 'Submit purchase order')",
    "INSERT INTO ME_OBJECT_ACTIONS (OBJECT_CODE, ACTION_CODE, NAME)
     VALUES ('purchasing.document', 'create_receiver', 'Create receiver from submitted order')",
    "INSERT INTO ME_OBJECT_ACTIONS (OBJECT_CODE, ACTION_CODE, NAME)
     VALUES ('purchasing.document', 'create_invoice', 'Create invoice from received receiver')",
];

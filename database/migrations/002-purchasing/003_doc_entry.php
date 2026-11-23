<?php
declare(strict_types=1);

return ["CREATE TABLE ME_PUR_DOC_ENTRY (
        CNYID NUMBER(10) not null,
        ID VARCHAR2(40) not null,
        DOC_ID VARCHAR2(40) not null,
        LINENO NUMBER(10) ,
        PRODUCT_ID VARCHAR2(20) not null,  --foreign key reference to the inventory table
        DESCRIPTION VARCHAR2(200),
        QUANTITY NUMBER(38,3) not null,
        UNITCOST NUMBER(38,2) not null,
        LINEAMOUNT NUMBER(38,2) not null,
        NOTES VARCHAR2(500),
        CREATEDBY VARCHAR2(40),
        MODIFIEDBY VARCHAR2(40),
        WHENCREATED TIMESTAMP default systimestamp not null,
        WHENMODIFIED TIMESTAMP default systimestamp not null,
        CONSTRAINT ME_PUR_DOC_ENTRY_PK PRIMARY KEY (CNYID, ID),
        CONSTRAINT ME_PUR_DOC_ENTRY_DOC_FK FOREIGN KEY (CNYID, DOC_ID)
            REFERENCES ME_PUR_DOC (CNYID, ID)
    --constraint for product id to inventory table
    )",
    
    'CREATE INDEX ME_PUR_DOC_ENTRY_DOC_IX ON ME_PUR_DOC_ENTRY (CNYID, DOC_ID)',
];

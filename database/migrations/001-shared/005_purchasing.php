<?php
declare(strict_types=1);

return [
    "CREATE TABLE ME_PUR_VENDOR (
        CNYID NUMBER(10) not null,
        ID VARCHAR2(40) not null,
        VENDOR_ID VARCHAR2(20) not null,
        NAME VARCHAR2(100),
        STATUS VARCHAR2(10),
        CONTACT VARCHAR2(100),
        EMAIL VARCHAR2(100),
        PHONE VARCHAR2(20),
        ADDR_1 VARCHAR2(150),
        ADDR_2 VARCHAR2(250),
        CITY VARCHAR2(80),
        STATE VARCHAR2(80),
        POSTAL_CODE varchar(10),
        COUNTRY VARCHAR2(80),
        PAYMENT_TERMS VARCHAR2(20),
        CREATEDBY VARCHAR2(40),
        MODIFIEDBY VARCHAR2(40),
        WHENCREATED TIMESTAMP,
        WHENMODIFIED TIMESTAMP,
        CONSTRAINT ME_PUR_VENDOR_PK PRIMARY KEY (CNYID, ID)
    )",
    "CREATE TABLE ME_PUR_DOC (
        CNYID NUMBER(10) not null,
        ID VARCHAR2(40) not null,
        VENDOR_ID VARCHAR2(40) not null,
        DOCNO VARCHAR2(20),
        DOC_DATE DATE,
        VENDOR_REF VARCHAR2(40),
        DELIVERY_ADDR VARCHAR2(400),
        PAYMENT_TERMS VARCHAR2(20),
        STATUS VARCHAR2(10),
        ORDER_ID VARCHAR2(40) ,
        RECEIVER_ID VARCHAR2(40),
        INVOICE_ID VARCHAR2(40),
        DUEDATE DATE,
        RECORDTYPE VARCHAR2(10),
        AMOUNTDUE NUMBER(20,2),
        TOTAL NUMBER(20,2),
        CREATEDBY VARCHAR2(40),
        MODIFIEDBY VARCHAR2(40),
        WHENCREATED TIMESTAMP,
        WHENMODIFIED TIMESTAMP,
        CONSTRAINT ME_PUR_DOC_PK PRIMARY KEY (CNYID, ID),
        CONSTRAINT ME_PUR_DOC_VENDOR_FK FOREIGN KEY (CNYID, VENDOR_ID)
            REFERENCES ME_PUR_VENDOR (CNYID, ID)
    )",
    // The diagram repeats the header table name; this table holds its document lines.
    // Oracle supports a maximum NUMBER precision of 38, rather than the diagram's 40.
    "CREATE TABLE ME_PUR_DOC_ENTRY (
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
    'CREATE INDEX ME_PUR_DOC_VENDOR_IX ON ME_PUR_DOC (CNYID, VENDOR_ID)',
    'CREATE INDEX ME_PUR_DOC_ENTRY_DOC_IX ON ME_PUR_DOC_ENTRY (CNYID, DOC_ID)',
];

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
];
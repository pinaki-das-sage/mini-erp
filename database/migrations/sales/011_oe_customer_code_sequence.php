<?php
declare(strict_types=1);

// Pause Customer creation while applying this migration and deploying the new service.
// Existing codes stay unchanged. One sequence serves all companies; gaps are expected.
// CUST- plus at most 15 digits fits the existing VARCHAR2(20 CHAR) column.
return [
    "DECLARE
        next_code NUMBER;
     BEGIN
        SELECT NVL(MAX(CASE
            WHEN REGEXP_LIKE(CUSTOMER_CODE, '^CUST-[0-9]{1,15}$', 'c')
                THEN TO_NUMBER(SUBSTR(CUSTOMER_CODE, 6))
            ELSE 0
        END), 0) + 1 INTO next_code
        FROM ME_OE_CUSTOMERS;

        IF next_code > 999999999999999 THEN
            RAISE_APPLICATION_ERROR(-20001, 'Customer Code sequence capacity exhausted.');
        END IF;

        EXECUTE IMMEDIATE 'CREATE SEQUENCE ME_OE_CUST_CODE_SEQ START WITH '
            || TO_CHAR(next_code, 'FM999999999999999')
            || ' INCREMENT BY 1 MAXVALUE 999999999999999 NOCYCLE CACHE 20';
     END;",
];

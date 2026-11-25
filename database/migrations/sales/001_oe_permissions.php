<?php
declare(strict_types=1);

// Draft shared-route capability registration. Sales services additionally require Actor::isAdmin.
// Individual grants do not authorize Sales access. This migration grants no user access.
return [
    "INSERT INTO ME_OBJECTS (CODE, NAME, MODULE_CODE, ALLOW_CREATE, ALLOW_READ, ALLOW_UPDATE, ALLOW_DELETE)
     VALUES ('sales.customers', 'OE Customers', 'sales', 1, 1, 1, 0)",
    "INSERT INTO ME_OBJECTS (CODE, NAME, MODULE_CODE, ALLOW_CREATE, ALLOW_READ, ALLOW_UPDATE, ALLOW_DELETE)
     VALUES ('sales.quotes', 'OE Sales Quotes', 'sales', 1, 1, 1, 1)",
    "INSERT INTO ME_OBJECTS (CODE, NAME, MODULE_CODE, ALLOW_CREATE, ALLOW_READ, ALLOW_UPDATE, ALLOW_DELETE)
     VALUES ('sales.orders', 'OE Sales Orders', 'sales', 1, 1, 1, 1)",
    "INSERT INTO ME_OBJECTS (CODE, NAME, MODULE_CODE, ALLOW_CREATE, ALLOW_READ, ALLOW_UPDATE, ALLOW_DELETE)
     VALUES ('sales.shippers', 'OE Shippers', 'sales', 1, 1, 1, 1)",
    "INSERT INTO ME_OBJECTS (CODE, NAME, MODULE_CODE, ALLOW_CREATE, ALLOW_READ, ALLOW_UPDATE, ALLOW_DELETE)
     VALUES ('sales.invoices', 'OE Sales Invoices', 'sales', 1, 1, 1, 1)",
    "INSERT INTO ME_OBJECTS (CODE, NAME, MODULE_CODE, ALLOW_CREATE, ALLOW_READ, ALLOW_UPDATE, ALLOW_DELETE)
     VALUES ('sales.payments', 'OE Customer Payments', 'sales', 0, 1, 0, 0)",
    "INSERT INTO ME_OBJECT_ACTIONS (OBJECT_CODE, ACTION_CODE, NAME)
     VALUES ('sales.payments', 'record', 'Record Customer payment')",
];

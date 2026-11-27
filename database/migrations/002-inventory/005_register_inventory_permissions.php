<?php
declare(strict_types=1);


return [
    "INSERT INTO ME_OBJECTS (CODE, NAME, MODULE_CODE, ALLOW_CREATE, ALLOW_READ, ALLOW_UPDATE)
     VALUES ('inventory.products', 'Products', 'inventory', 1, 1, 1)",
    "INSERT INTO ME_OBJECTS (CODE, NAME, MODULE_CODE, ALLOW_READ)
     VALUES ('inventory.stock', 'Stock balances', 'inventory', 1)",
    "INSERT INTO ME_OBJECT_ACTIONS (OBJECT_CODE, ACTION_CODE, NAME)
     VALUES ('inventory.stock', 'receive', 'Receive purchase stock')",
    "INSERT INTO ME_OBJECTS (CODE, NAME, MODULE_CODE, ALLOW_READ)
     VALUES ('inventory.movements', 'Stock movements', 'inventory', 1)",
];

<?php
declare(strict_types=1);

// Register independent lifecycle permissions. No user receives grants automatically.
$statements = [];
foreach (['sales.quotes', 'sales.orders', 'sales.invoices'] as $object) {
    foreach (['submit' => 'Submit', 'return_to_draft' => 'Return to Draft'] as $action => $name) {
        $statements[] = "INSERT INTO ME_OBJECT_ACTIONS (OBJECT_CODE, ACTION_CODE, NAME)
            VALUES ('$object', '$action', '$name')";
    }
}
return $statements;

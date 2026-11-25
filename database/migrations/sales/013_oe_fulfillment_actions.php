<?php
declare(strict_types=1);

// Proposed Sales/Logistics handoff capabilities. Register definitions only; grant no user access.
// Review with Logistics before deployment. Follow normal checksum migration ordering, including 012's prerequisites.
$statements = [];
foreach (['record_trip_assignment' => 'Record Trip Assignment', 'record_trip_cancellation' => 'Record Trip Cancellation',
    'record_delivery' => 'Record Order Delivery'] as $action => $name) {
    $statements[] = "INSERT INTO ME_OBJECT_ACTIONS (OBJECT_CODE, ACTION_CODE, NAME) VALUES ('sales.orders', '$action', '$name')";
}
return $statements;

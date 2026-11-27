<?php
declare(strict_types=1);

return [
    "INSERT INTO ME_OBJECTS (CODE, NAME, MODULE_CODE, ALLOW_CREATE, ALLOW_READ, ALLOW_UPDATE, ALLOW_DELETE)
     VALUES ('logistics.vehicles', 'Vehicles', 'logistics', 1, 1, 1, 1)",

    "INSERT INTO ME_OBJECTS (CODE, NAME, MODULE_CODE,ALLOW_CREATE, ALLOW_READ, ALLOW_UPDATE, ALLOW_DELETE)
     VALUES ('logistics.drivers', 'Drivers', 'logistics', 1, 1, 1, 1)",

    "INSERT INTO ME_OBJECTS (CODE, NAME, MODULE_CODE, ALLOW_CREATE, ALLOW_READ, ALLOW_UPDATE, ALLOW_DELETE)
     VALUES ('logistics.trips', 'Trips', 'logistics', 1, 1, 1, 1)",

    "INSERT INTO ME_OBJECTS (CODE, NAME, MODULE_CODE, ALLOW_CREATE, ALLOW_READ, ALLOW_UPDATE, ALLOW_DELETE)
     VALUES ('logistics.trip_lines', 'Trip Lines', 'logistics',1, 1, 1, 1)",
];
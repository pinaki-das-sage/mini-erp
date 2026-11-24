<?php

declare(strict_types=1);

use MiniErp\shared\Api;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\logistics\vehicle\VehicleService;

return static function (Api $api, Connection $connection): void {
    $vehicles = new VehicleService($connection);

    $api->register(
        'GET',
        '/logistics/vehicles',
        'logistics.vehicles',
        'read',
        static function (
            Actor $actor,
            array $input,
            array $params,
            array $query
        ) use ($vehicles): array {
            $pagination = Api::pagination($query);
            return $vehicles->getVehicles($actor, $pagination);
        }
    );
};

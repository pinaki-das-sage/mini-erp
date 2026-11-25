<?php

declare(strict_types=1);

use MiniErp\shared\Api;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\logistics\vehicle\VehicleService;
use MiniErp\logistics\driver\DriverService;

return static function (Api $api, Connection $connection): void {
    $vehicles = new VehicleService($connection);
    $drivers = new DriverService($connection);

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

    $api->register(
        'GET',
        '/logistics/drivers',
        'logistics.drivers',
        'read',
        static function (
            Actor $actor,
            array $input,
            array $params,
            array $query
        ) use ($drivers): array {
            $pagination = Api::pagination($query);
            return $drivers->getDrivers($actor, $pagination);
        }
    );  

    $api->register(
        'GET',
        '/logistics/drivers/{id}',
        'logistics.drivers',
        'read',
        static function (
            Actor $actor,
            array $input,
            array $params,
            array $query
        ) use ($drivers): array {
            $id = (int) $params['id'];
            return $drivers->getDriver($actor, $id);
        }
    );

    $api->register(
        'POST',
        '/logistics/drivers',
        'logistics.drivers',
        'create',
        static function (
            Actor $actor,
            array $input,
            array $params,
            array $query
        ) use ($drivers): array {
            return $drivers->createDriver($actor, $input);
        },
        201
    );

    $api->register(
        'POST',
        '/logistics/drivers/{id}/update',
        'logistics.drivers',
        'update',
        static function (
            Actor $actor,
            array $input,
            array $params,
            array $query
        ) use ($drivers): array {
            $id = (int) $params['id'];
            return $drivers->updateDriver($actor, $id, $input);
        }
    );

    $api->register(
        'POST',
        '/logistics/drivers/{id}/delete',
        'logistics.drivers',
        'delete',
        static function (
            Actor $actor,
            array $input,
            array $params,
            array $query
        ) use ($drivers): array {
            $id = (int) $params['id'];
            return $drivers->deleteDriver($actor, $id);
        }
    );

    $api->register(
        'GET',
        '/logistics/driver-users',
        'logistics.drivers',
        'read',
        static function (
            Actor $actor,
            array $input,
            array $params,
            array $query
        ) use ($drivers): array {
            $pagination = Api::pagination($query, ['search']);
            return $drivers->getEligibleUsers($actor, $pagination);
        }
    );
};

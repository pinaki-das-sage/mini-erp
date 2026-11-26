<?php

declare(strict_types=1);

use MiniErp\shared\Api;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\logistics\vehicle\VehicleService;
use MiniErp\logistics\driver\DriverService;
use MiniErp\logistics\trip\TripService;

return static function (Api $api, Connection $connection): void {
    
    $drivers = new DriverService($connection);
    $trips = new TripService($connection);


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

    $api->register(
        'GET',
        '/logistics/trips',
        'logistics.trips',
        'read',
        static function (
            Actor $actor,
            array $input,
            array $params,
            array $query
        ) use ($trips): array {
            $pagination = Api::pagination($query);
            return $trips->getTrips($actor, $pagination);
        }
    );

    $api->register(
        'GET',
        '/logistics/trips/{id}',
        'logistics.trips',
        'read',
        static function (
            Actor $actor,
            array $input,
            array $params,
            array $query
        ) use ($trips): array {
            $id = (int) $params['id'];
            return $trips->getTrip($actor, $id);
        }
    );

    $api->register(
        'POST',
        '/logistics/trips',
        'logistics.trips',
        'create',
        static function (
            Actor $actor,
            array $input,
            array $params,
            array $query
        ) use ($trips): array {
            return $trips->createTrip($actor, $input);
        },
        201
    );

    $api->register(
        'POST',
        '/logistics/trips/{id}/update',
        'logistics.trips',
        'update',
        static function (
            Actor $actor,
            array $input,
            array $params,
            array $query
        ) use ($trips): array {
            $id = (int) $params['id'];
            return $trips->updateTrip($actor, $id, $input);
        }
    );

    $api->register(
        'POST',
        '/logistics/trips/{id}/cancel',
        'logistics.trips',
        'update',
        static function (
            Actor $actor,
            array $input,
            array $params,
            array $query
        ) use ($trips): array {
            $id = (int) $params['id'];
            return $trips->cancelTrip($actor, $id, $input);
        }
    );

    $api->register(
        'POST',
        '/logistics/trips/{id}/dispatch',
        'logistics.trips',
        'update',
        static function (
            Actor $actor,
            array $input,
            array $params,
            array $query
        ) use ($trips): array {
            $id = (int) $params['id'];
            return $trips->dispatchTrip($actor, $id, $input);
        }
    );

    $api->register(
        'POST',
        '/logistics/trips/{id}/deliver',
        'logistics.trips',
        'update',
        static function (
            Actor $actor,
            array $input,
            array $params,
            array $query
        ) use ($trips): array {
            $id = (int) $params['id'];
            return $trips->deliverTrip($actor, $id, $input);
        }
    );
};

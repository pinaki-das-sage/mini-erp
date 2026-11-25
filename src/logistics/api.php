<?php

// Enforce declared scalar types instead of silently coercing values in calls from this file.
declare(strict_types=1);

// Imports let us use short class names instead of fully qualified names below.
use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\logistics\vehicle\VehicleService;
use MiniErp\logistics\driver\DriverService;
use MiniErp\logistics\tripline\TripLineService;
use MiniErp\logistics\tripline\TripLineValidator;

/**
 * The application loads this file and calls the returned function to register routes.
 * Api is the router; Connection is the shared Oracle connection. void means no return value.
 * static means this callback has no $this object.
 *
 * Every route handler receives:
 * - actor: authenticated identity/company, provided by the framework.
 * - input: parsed JSON object as an array (not input[body]).
 * - params: URL placeholders, e.g. id from /vehicles/{id}.
 * - query: URL query parameters, e.g. page, page_size, active and search.
 */
return static function (Api $api, Connection $connection): void {
    // Construct the business service using the same connection as the framework.
    $vehicles = new VehicleService($connection);
    // Reusable URL-ID validator: unlike an integer cast, filter_var rejects malformed IDs.
    $vehicleId = static function (array $params): int {
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        // Strict comparison distinguishes validation failure from a successful integer result.
        if ($id === false) {
            throw new AppError('VALIDATION_ERROR', 'Vehicle ID must be a positive integer.', 400);
        }
        return $id;
    };

    // register(method, path, permission object, operation, handler). Api supplies /api/v1.
    // This route reads a paginated list; it does not modify records.
    $api->register(
        'GET',
        '/logistics/vehicles',
        'logistics.vehicles',
        'read',
        static function (Actor $actor, array $input, array $params, array $query) use ($vehicles): array {
            // use ($vehicles) captures the outer service for this anonymous function.
            // pagination() validates page/size, calculates offset and permits only these filters.
            return $vehicles->getVehicles($actor, Api::pagination($query, ['active', 'search']));
        }
    );

    // {id} is the generated database ID, not the vehicle's business code.
    $api->register(
        'GET',
        '/logistics/vehicles/{id}',
        'logistics.vehicles',
        'read',
        static function (Actor $actor, array $input, array $params, array $query) use ($vehicles, $vehicleId): array {
            // Validate the URL value before passing a typed int to the service.
            return $vehicles->getVehicleById($actor, $vehicleId($params));
        }
    );

    // Each write route requires its own permission before invoking the service.
    $api->register(
        'POST',
        '/logistics/vehicles',
        'logistics.vehicles',
        'create',
        static function (Actor $actor, array $input, array $params, array $query) use ($vehicles): array {
            return $vehicles->createVehicle($actor, $input);
        },
        201
    );

    $api->register(
        'POST',
        '/logistics/vehicles/{id}/update',
        'logistics.vehicles',
        'update',
        static function (Actor $actor, array $input, array $params, array $query) use ($vehicles, $vehicleId): array {
            return $vehicles->updateVehicle($actor, $vehicleId($params), $input);
        }
    );

    $api->register(
        'POST',
        '/logistics/vehicles/{id}/delete',
        'logistics.vehicles',
        'delete',
        static function (Actor $actor, array $input, array $params, array $query) use ($vehicles, $vehicleId): array {
            return $vehicles->deleteVehicle($actor, $vehicleId($params), $input);
        }
    );
    $drivers = new DriverService($connection);

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
        }
    );

    $api->register(
        'POST',
        '/logistics/drivers/{id}',
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
    // Trip-line planning only; trip lifecycle and peer module implementations stay separate.
    $tripLines = new TripLineService($connection);
    $tripLineValidator = new TripLineValidator();
    $api->register(
        'GET',
        '/logistics/trip-lines',
        'logistics.trip_lines',
        'read',
        static function (Actor $actor, array $input, array $params, array $query) use ($tripLines): array {
            return $tripLines->getTripLines($actor, Api::pagination($query, ['trip_id']));
        }
    );
    $api->register(
        'GET',
        '/logistics/trip-lines/{id}',
        'logistics.trip_lines',
        'read',
        static function (Actor $actor, array $input, array $params, array $query) use ($tripLines, $tripLineValidator): array {
            return $tripLines->getTripLine($actor, $tripLineValidator->id($params['id']));
        }
    );
    $api->register(
        'POST',
        '/logistics/trip-lines',
        'logistics.trip_lines',
        'create',
        static function (Actor $actor, array $input, array $params, array $query) use ($tripLines): array {
            return $tripLines->createTripLine($actor, $input);
        },
        201
    );
    $api->register(
        'POST',
        '/logistics/trip-lines/{id}/update',
        'logistics.trip_lines',
        'update',
        static function (Actor $actor, array $input, array $params, array $query) use ($tripLines, $tripLineValidator): array {
            return $tripLines->updateTripLine($actor, $tripLineValidator->id($params['id']), $input);
        }
    );
    $api->register(
        'POST',
        '/logistics/trip-lines/{id}/delete',
        'logistics.trip_lines',
        'delete',
        static function (Actor $actor, array $input, array $params, array $query) use ($tripLines, $tripLineValidator): array {
            return $tripLines->deleteTripLine($actor, $tripLineValidator->id($params['id']), $input);
        }
    );
};

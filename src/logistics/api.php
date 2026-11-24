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

    // Choose the exact permission from the validated action, before handling it.
    $vehicleAction = static function (array $input): string {
        // Missing action defaults to create for existing clients. Explicit null is still invalid.
        $action = array_key_exists('action', $input) ? $input['action'] : 'create';
        if (!is_string($action) || !in_array($action, ['create', 'update', 'delete'], true)) {
            throw new AppError('VALIDATION_ERROR', 'action must be create, update or delete.', 400);
        }
        return $action;
    };

    // One write endpoint. The action resolver selects create/update/delete permission first.
    // It does not give delete access merely because the user can create vehicles.
    $api->register(
        'POST',
        '/logistics/vehicles',
        'logistics.vehicles',
        $vehicleAction,
        static function (Actor $actor, array $input, array $params, array $query) use ($vehicles, $vehicleAction): array {
            $action = $vehicleAction($input);
            // Remove routing metadata so the service validates only its allowed command fields.
            unset($input['action']);
            if ($action === 'create') {
                return $vehicles->createVehicle($actor, $input);
            }
            // Update/delete use id in the JSON body; integer() rejects quoted numbers and floats.
            $id = Api::integer($input, 'id');
            if ($id < 1) {
                throw new AppError('VALIDATION_ERROR', 'id must be a positive JSON integer.', 400);
            }
            // Pass id separately: it identifies the row and must not become an editable field.
            unset($input['id']);
            // Ternary dispatch: update the selected record, otherwise run the validated delete action.
            return $action === 'update'
                ? $vehicles->updateVehicle($actor, $id, $input)
                : $vehicles->deleteVehicle($actor, $id, $input);
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
};

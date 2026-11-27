<?php

declare(strict_types=1);

namespace MiniErp\logistics;

use MiniErp\logistics\driver\DriverService;
use MiniErp\logistics\trip\TripService;
use MiniErp\logistics\tripline\TripLineService;
use MiniErp\logistics\vehicle\VehicleService;
use MiniErp\sales\orders\OrderService;
use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

final class LogisticsUiApi
{
    private Connection $connection;
    private VehicleService $vehicles;
    private DriverService $drivers;
    private TripService $trips;
    private TripLineService $tripLines;
    private OrderService $orders;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->vehicles = new VehicleService($connection);
        $this->drivers = new DriverService($connection);
        $this->trips = new TripService($connection);
        $this->tripLines = new TripLineService($connection);
        $this->orders = new OrderService($connection);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $query
     * @return array{status: int, result: array<string, mixed>}
     */
    public function dispatch(Actor $actor, string $method, string $path, array $input, array $query): array
    {
        if ($method === 'GET' && $path === '/capabilities') {
            $permissions = array_flip((new Authorization($this->connection))->permissions($actor));
            return $this->response([
                'trips' => [
                    'read' => isset($permissions['logistics.trips:read']),
                    'create' => isset($permissions['logistics.trips:create']),
                    'update' => isset($permissions['logistics.trips:update']),
                ],
                'drivers' => [
                    'read' => isset($permissions['logistics.drivers:read']),
                    'create' => isset($permissions['logistics.drivers:create']),
                    'update' => isset($permissions['logistics.drivers:update']),
                    'delete' => isset($permissions['logistics.drivers:delete']),
                ],
                'vehicles' => [
                    'read' => isset($permissions['logistics.vehicles:read']),
                    'create' => isset($permissions['logistics.vehicles:create']),
                    'update' => isset($permissions['logistics.vehicles:update']),
                    'delete' => isset($permissions['logistics.vehicles:delete']),
                ],
                'sales_orders_read' => isset($permissions['sales.orders:read']),
                'trip-lines' => ['read' => isset($permissions['logistics.trip_lines:read'])],
            ]);
        }
        if ($method === 'GET' && $path === '/vehicles') {
            return $this->response($this->vehicles->getVehicles(
                $actor,
                Api::pagination($query)
            ));
        }
        if ($method === 'GET' && $path === '/eligible-vehicles') {
            $pagination = Api::pagination($query, ['exclude_trip_id']);
            $excludeTripId = $this->optionalId($pagination['filters']['exclude_trip_id'] ?? null, 'exclude_trip_id');
            $pagination['filters'] = [];
            return $this->response($this->vehicles->getEligibleVehicles(
                $actor,
                $pagination,
                $excludeTripId,
            ));
        }
        if ($method === 'GET' && $path === '/drivers') {
            return $this->response($this->drivers->getDrivers($actor, Api::pagination($query)));
        }
        if ($method === 'GET' && $path === '/eligible-drivers') {
            $pagination = Api::pagination($query, ['exclude_trip_id', 'planned_departure_at']);
            $plannedDepartureAt = $pagination['filters']['planned_departure_at'] ?? null;
            if ($plannedDepartureAt === null) {
                throw new AppError('VALIDATION_ERROR', 'planned_departure_at is required.');
            }
            return $this->response($this->drivers->getEligibleDrivers(
                $actor,
                $pagination,
                $plannedDepartureAt,
                $this->optionalId($pagination['filters']['exclude_trip_id'] ?? null, 'exclude_trip_id'),
            ));
        }
        if ($method === 'GET' && $path === '/driver-users') {
            return $this->response($this->drivers->getEligibleUsers($actor, Api::pagination($query, ['search'])));
        }
        if ($method === 'GET' && $path === '/trips') {
            return $this->response($this->trips->getTrips($actor, Api::pagination($query)));
        }
        // Reuse the read-only service, including company scoping and permission checks.
        if ($method === 'GET' && $path === '/trip-lines') {
            return $this->response($this->tripLines->getTripLines($actor, Api::pagination($query, ['trip_id'])));
        }
        if ($method === 'GET' && preg_match('#\A/trip-lines/([^/]+)\z#D', $path, $match)) {
            if ($query !== []) {
                throw new AppError('VALIDATION_ERROR', 'Unsupported filter or invalid query value.');
            }
            return $this->response($this->tripLines->getTripLine($actor, rawurldecode($match[1])));
        }
        // Read the selected order for the planner; Sales still owns its rules and permissions.
        if ($method === 'GET' && preg_match('#\A/sales-orders/([^/]+)\z#D', $path, $match)) {
            if ($query !== []) {
                throw new AppError('VALIDATION_ERROR', 'Unsupported filter or invalid query value.');
            }
            return $this->response($this->orders->get($actor, rawurldecode($match[1])));
        }
        if ($method === 'GET' && $path === '/sales-orders') {
            return $this->response($this->trips->getEligibleSalesOrders($actor, $query));
        }

        if (preg_match('#\A/(vehicles|drivers|trips)/([0-9]+)(?:/(update|delete|cancel|dispatch|deliver))?\z#D', $path, $match)) {
            $id = filter_var($match[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new AppError('VALIDATION_ERROR', 'Record ID must be a positive integer.');
            }

            $resource = $match[1];
            $action = $match[3] ?? '';
            if ($method === 'GET' && $action === '') {
                if ($query !== []) {
                    throw new AppError('VALIDATION_ERROR', 'Unsupported filter or invalid query value.');
                }
                return $this->response(match ($resource) {
                    'vehicles' => $this->vehicles->getVehicleById($actor, $id),
                    'drivers' => $this->drivers->getDriver($actor, $id),
                    default => $this->trips->getTrip($actor, $id),
                });
            }
            if ($method === 'POST' && $resource === 'vehicles' && $action === 'update') {
                return $this->response($this->vehicles->updateVehicle($actor, $id, $input));
            }
            if ($method === 'POST' && $resource === 'vehicles' && $action === 'delete') {
                return $this->response($this->vehicles->deleteVehicle($actor, $id, $input));
            }
            if ($method === 'POST' && $resource === 'drivers' && $action === 'update') {
                return $this->response($this->drivers->updateDriver($actor, $id, $input));
            }
            if ($method === 'POST' && $resource === 'drivers' && $action === 'delete') {
                return $this->response($this->drivers->deleteDriver($actor, $id));
            }
            if ($method === 'POST' && $resource === 'trips' && $action === 'update') {
                return $this->response($this->trips->updateTrip($actor, $id, $input));
            }
            if ($method === 'POST' && $resource === 'trips' && $action === 'cancel') {
                return $this->response($this->trips->cancelTrip($actor, $id, $input));
            }
            if ($method === 'POST' && $resource === 'trips' && $action === 'dispatch') {
                return $this->response($this->trips->dispatchTrip($actor, $id, $input));
            }
            if ($method === 'POST' && $resource === 'trips' && $action === 'deliver') {
                return $this->response($this->trips->deliverTrip($actor, $id, $input));
            }
        }

        if ($method === 'POST' && $path === '/drivers') {
            return $this->response($this->drivers->createDriver($actor, $input), 201);
        }
        if ($method === 'POST' && $path === '/vehicles') {
            return $this->response($this->vehicles->createVehicle($actor, $input), 201);
        }
        if ($method === 'POST' && $path === '/trips') {
            return $this->response($this->trips->createTrip($actor, $input), 201);
        }

        throw new AppError('NOT_FOUND', 'Logistics UI operation not found.', 404);
    }

    /** @param array<string, mixed> $result
     *  @return array{status: int, result: array<string, mixed>}
     */
    private function response(array $result, int $status = 200): array
    {
        return ['status' => $status, 'result' => $result];
    }

    private function optionalId(mixed $value, string $name): ?int
    {
        if ($value === null) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new AppError('VALIDATION_ERROR', $name . ' must be a positive integer.');
        }
        return $id;
    }
}

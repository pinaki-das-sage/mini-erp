<?php

declare(strict_types=1);

namespace MiniErp\logistics\driver;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

class DriverService
{
    private Connection $connection;
    private DriverRepository $repository;
    private DriverValidator $validator;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->repository = new DriverRepository($connection);
        $this->validator = new DriverValidator();
    }

    // GET /logistics/driver
    public function getDrivers(Actor $actor, array $pagination): array
    {
        $authorization = new Authorization($this->connection);

        $authorization->requirePermission(
            $actor,
            'logistics.drivers',
            'read'
        );

        $drivers = $this->repository->findAll(
            $actor->companyId,
            $pagination['offset'],
            $pagination['page_size']
        );

        return [
            'items' => $drivers,
            'page' => $pagination['page'],
            'page_size' => $pagination['page_size']
        ];
    }

    public function getEligibleDrivers(Actor $actor, array $pagination, ?int $excludeTripId = null): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.drivers', 'read');
        if ($excludeTripId !== null) {
            $this->validator->validateId($excludeTripId);
        }
        return [
            'items' => $this->repository->findEligible(
                $actor->companyId, $pagination['offset'], $pagination['page_size'], $excludeTripId,
            ),
            'page' => $pagination['page'],
            'page_size' => $pagination['page_size'],
        ];
    }

    // GET /logistics/driver-users
    public function getEligibleUsers(Actor $actor, array $pagination): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.drivers', 'read');

        return [
            'items' => $this->repository->findEligibleUsers(
                $actor->companyId,
                $pagination['offset'],
                $pagination['page_size'],
                $pagination['filters']['search'] ?? null
            ),
            'page' => $pagination['page'],
            'page_size' => $pagination['page_size']
        ];
    }

    public function getDriver(Actor $actor, int $id): array
    {
        $authorization = new Authorization($this->connection);

        $authorization->requirePermission(
            $actor,
            'logistics.drivers',
            'read'
        );

        $this->validator->validateId($id);

        return $this->requireDriver($actor->companyId, $id);
    }

    // POST /logistics/driver
    public function createDriver(Actor $actor, array $input): array
    {
        $authorization = new Authorization($this->connection);

        $authorization->requirePermission(
            $actor,
            'logistics.drivers',
            'create'
        );

        $data = $this->validator->validateCreate($input);

        $this->assertReferences($actor->companyId, $data);
        $actorId = $this->actorId($actor);

        return (new TransactionManager($this->connection))->run(
            fn (): array => $this->repository->create($actor->companyId, $actorId, $data)
        );
    }

    // POST /logistics/drivers/{id}/update
    public function updateDriver(Actor $actor, int $id, array $input): array
    {   
        $authorization = new Authorization($this->connection);

        $authorization->requirePermission(
            $actor,
            'logistics.drivers',
            'update'
        );

        $this->validator->validateId($id);
        $data = $this->validator->validateUpdate($input);

        $this->requireDriver($actor->companyId, $id);
        $this->assertReferences($actor->companyId, $data, $id);
        $actorId = $this->actorId($actor);

        return (new TransactionManager($this->connection))->run(
            fn (): array => $this->repository->update($actor->companyId, $id, $actorId, $data)
        );
    }

    // POST /logistics/drivers/{id}/delete
    public function deleteDriver(Actor $actor, int $id): array
    {
        $authorization = new Authorization($this->connection);

        $authorization->requirePermission(
            $actor,
            'logistics.drivers',
            'delete'
        );

        $this->validator->validateId($id);
        $this->requireDriver($actor->companyId, $id);

        if ($this->repository->hasActiveTrip($actor->companyId, $id)) {
            throw new AppError('CONFLICT', 'Driver is assigned to an active trip.', 409);
        }

        $actorId = $this->actorId($actor);
        $referenced = $this->repository->hasTripHistory($actor->companyId, $id);

        (new TransactionManager($this->connection))->run(
            fn () => $referenced
                ? $this->repository->deactivate($actor->companyId, $id, $actorId)
                : $this->repository->delete($actor->companyId, $id)
        );

        return ['id' => $id, 'deleted' => true];
    }

    private function actorId(Actor $actor): int
    {
        $id = $this->repository->findUserIdByCode($actor->companyId, $actor->id);

        if ($id === null) {
            throw new AppError('UNAUTHENTICATED', 'Actor not found.', 401);
        }

        return $id;
    }

    private function requireDriver(int $companyId, int $id): array
    {
        $driver = $this->repository->findById($companyId, $id);

        if ($driver === []) {
            throw new AppError('NOT_FOUND', 'Driver not found.', 404);
        }

        return $driver;
    }

    private function assertReferences(int $companyId, array $data, ?int $excludeId = null): void
    {
        if ($this->repository->driverCodeExists($companyId, $data['driver_code'], $excludeId)) {
            throw new AppError('CONFLICT', 'Driver code already exists.', 409);
        }

        if ($this->repository->licenceNumberExists($companyId, $data['licence_number'], $excludeId)) {
            throw new AppError('CONFLICT', 'Licence number already exists.', 409);
        }

        if ($data['user_id'] !== null && !$this->repository->userExists($companyId, $data['user_id'])) {
            throw new AppError('VALIDATION_ERROR', 'User does not exist.');
        }

        if ($data['user_id'] !== null && $this->repository->driverUserExists($companyId, $data['user_id'], $excludeId)) {
            throw new AppError('CONFLICT', 'User already has an active driver profile.', 409);
        }
    }
}

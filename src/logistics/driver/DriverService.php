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

    public function getEligibleDrivers(
        Actor $actor,
        array $pagination,
        string $plannedDepartureAt,
        ?int $excludeTripId = null
    ): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.drivers', 'read');
        if ($excludeTripId !== null) {
            $this->validator->validateId($excludeTripId);
        }
        $departureDate = (new \MiniErp\logistics\trip\TripValidator())
            ->departureDate($plannedDepartureAt);
        return [
            'items' => $this->repository->findEligible(
                $actor->companyId,
                $pagination['offset'],
                $pagination['page_size'],
                $departureDate,
                $excludeTripId,
            ),
            'page' => $pagination['page'],
            'page_size' => $pagination['page_size'],
        ];
    }

    // GET /logistics/driver-users
    public function getEligibleUsers(Actor $actor, array $pagination): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.drivers', 'read');
        $search = $pagination['filters']['search'] ?? null;
        if ($search !== null && mb_strlen($search, 'UTF-8') > 100) {
            throw new AppError('VALIDATION_ERROR', 'Search must not exceed 100 characters.');
        }

        return [
            'items' => $this->repository->findEligibleUsers(
                $actor->companyId,
                $pagination['offset'],
                $pagination['page_size'],
                $search
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

    // POST /logistics/drivers/{id}
    public function updateDriver(Actor $actor, int $id, array $input): array
    {   
        $authorization = new Authorization($this->connection);

        $authorization->requirePermission(
            $actor,
            'logistics.drivers',
            'update'
        );

        $this->validator->validateId($id);

        return (new TransactionManager($this->connection))->run(
            function () use ($actor, $id, $input): array {
                $driver = $this->repository->findById($actor->companyId, $id, true);
                if ($driver === []) {
                    throw new AppError('NOT_FOUND', 'Driver not found.', 404);
                }

                $data = $this->validator->validateUpdate($input);
                $changed = false;
                foreach ($data as $field => $value) {
                    if ($driver[$field] !== $value) {
                        $changed = true;
                        break;
                    }
                }
                if (!$changed) {
                    throw new AppError('VALIDATION_ERROR', 'At least one field must actually change.', 400);
                }

                if ($driver['isactive'] === 'T' && $data['isactive'] === 'F'
                    && $this->repository->hasActiveTrip($actor->companyId, $id)) {
                    throw new AppError(
                        'RESOURCE_IN_USE',
                        'Driver cannot be deactivated while assigned to a Draft or Dispatched Trip.',
                        409
                    );
                }

                // Keeping this driver's current user is valid. Identity changes with history
                // must report their own restriction before unrelated duplicate-user checks.
                $codeChanged = $driver['driver_code'] !== $data['driver_code'];
                $userChanged = $driver['user_id'] !== $data['user_id'];
                if (($codeChanged || $userChanged) && $this->repository->hasTripHistory($actor->companyId, $id)) {
                    if ($codeChanged) {
                        throw new AppError('REFERENCED_RECORD', 'Driver code cannot change after first Trip use.', 409);
                    }
                    throw new AppError('REFERENCED_RECORD', 'Linked user cannot change after first Trip use.', 409);
                }

                $this->assertReferences($actor->companyId, $data, $id);
                $actorId = $this->actorId($actor);
                return $this->repository->update($actor->companyId, $id, $actorId, $data);
            }
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

        (new TransactionManager($this->connection))->run(
            function () use ($actor, $id): void {
                $driver = $this->repository->findById($actor->companyId, $id, true);
                if ($driver === []) {
                    throw new AppError('NOT_FOUND', 'Driver not found.', 404);
                }
                if ($this->repository->hasActiveTrip($actor->companyId, $id)) {
                    throw new AppError('CONFLICT', 'Driver is assigned to an active trip.', 409);
                }

                $actorId = $this->actorId($actor);
                if ($this->repository->hasTripHistory($actor->companyId, $id)) {
                    $this->repository->deactivate($actor->companyId, $id, $actorId);
                } else {
                    $this->repository->delete($actor->companyId, $id);
                }
            }
        );

        return [];
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

        if ($data['user_id'] !== null && $this->repository->userLinkedToAnotherDriver($companyId, $data['user_id'], $excludeId)) {
            throw new AppError('CONFLICT', 'User already has an active driver profile.', 409);
        }
    }
}

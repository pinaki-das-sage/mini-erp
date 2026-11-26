<?php

declare(strict_types=1);

namespace MiniErp\logistics\vehicle;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

/** Vehicle permissions, business rules and transaction boundaries. */
final class VehicleService
{
    private VehicleRepository $repository;
    private VehicleValidator $validator;

    public function __construct(private Connection $connection)
    {
        $this->repository = new VehicleRepository($connection);
        $this->validator = new VehicleValidator();
    }

    public function getVehicles(Actor $actor, array $pagination): array
    {
        $this->authorize($actor, 'read');
        $pagination = $this->validator->validatePagination($pagination);
        $rows = $this->repository->findAll($actor->companyId, $pagination);
        return [
            'items' => array_map($this->present(...), $rows),
            'page' => $pagination['page'],
            'page_size' => $pagination['page_size'],
        ];
    }

    public function getEligibleVehicles(Actor $actor, array $pagination, ?int $excludeTripId = null): array
    {
        $this->authorize($actor, 'read');
        $pagination = $this->validator->validatePagination($pagination);
        if ($excludeTripId !== null) {
            $this->validator->validateId($excludeTripId);
        }
        return [
            'items' => array_map(
                $this->present(...),
                $this->repository->findEligible(
                    $actor->companyId,
                    ($pagination['page'] - 1) * $pagination['page_size'],
                    $pagination['page_size'],
                    $excludeTripId
                )
            ),
            'page' => $pagination['page'],
            'page_size' => $pagination['page_size'],
        ];
    }

    public function getVehicleById(Actor $actor, int $id): array
    {
        $this->authorize($actor, 'read');
        $this->validator->validateId($id);
        return $this->present($this->repository->findById($actor->companyId, $id));
    }

    public function createVehicle(Actor $actor, array $input): array
    {
        $this->authorize($actor, 'create');
        $data = $this->validator->validateCreate($input);
        $this->connection->begin();
        try {
            $auditUserId = $this->repository->auditUserId($actor->companyId, $actor->id);
            if ($this->repository->duplicateExists($actor->companyId, $data['vehicle_code'], $data['registration_number'])) {
                throw new AppError('DUPLICATE_VEHICLE', 'Vehicle code or registration number already exists.', 409);
            }
            $result = $this->present($this->repository->create($actor->companyId, $auditUserId, $data));
            $this->connection->commit();
            return $result;
        } catch (\Throwable $error) {
            $this->connection->rollback();
            if ($error instanceof DatabaseError && $error->oracleCode === 1) {
                throw new AppError('DUPLICATE_VEHICLE', 'Vehicle code or registration number conflicts with an existing record.', 409);
            }
            throw $error;
        }
    }

    /** Update submitted fields only, keeping historical and assigned vehicles protected. */
    public function updateVehicle(Actor $actor, int $id, array $input): array
    {
        $this->authorize($actor, 'update');
        $this->validator->validateId($id);
        $values = $this->validator->validateUpdate($input);
        $this->connection->begin();
        try {
            $vehicle = $this->repository->findById($actor->companyId, $id, true);
            $changes = $this->changedFields($vehicle, $values);
            if ($changes === []) {
                throw new AppError('VALIDATION_ERROR', 'At least one field must actually change.', 400);
            }
            if ((isset($changes['VEHICLE_CODE']) || isset($changes['REGISTRATION_NUMBER']))
                && $this->repository->hasTripHistory($actor->companyId, $id)
            ) {
                throw new AppError('REFERENCED_RECORD', 'Vehicle code and registration cannot change after first Trip use.', 409);
            }
            // Draft already reserves the vehicle. Only Delivered/Cancelled trips release it.
            $requiresFreeVehicle = isset($changes['CAPACITY']) || (($changes['ISACTIVE'] ?? null) === 'F');
            if ($requiresFreeVehicle && $this->repository->hasActiveTrip($actor->companyId, $id)) {
                throw new AppError('RESOURCE_IN_USE', 'Vehicle is assigned to a Draft or Dispatched trip. It must be free before changing capacity or setting is_active to F.', 409);
            }
            if (isset($changes['VEHICLE_CODE']) || isset($changes['REGISTRATION_NUMBER'])) {
                if ($this->repository->duplicateExists(
                    $actor->companyId,
                    $changes['VEHICLE_CODE'] ?? $vehicle['VEHICLE_CODE'],
                    $changes['REGISTRATION_NUMBER'] ?? $vehicle['REGISTRATION_NUMBER'],
                    $id
                )) {
                    throw new AppError('DUPLICATE_VEHICLE', 'Vehicle code or registration number already exists.', 409);
                }
            }
            $auditUserId = $this->repository->auditUserId($actor->companyId, $actor->id);
            $this->repository->update($actor->companyId, $id, $auditUserId, $changes);
            $result = $this->present($this->repository->findById($actor->companyId, $id));
            $this->connection->commit();
            return $result;
        } catch (\Throwable $error) {
            $this->connection->rollback();
            if ($error instanceof DatabaseError && $error->oracleCode === 1) {
                throw new AppError('DUPLICATE_VEHICLE', 'Vehicle code or registration number conflicts with an existing record.', 409);
            }
            throw $error;
        }
    }

    public function deleteVehicle(Actor $actor, int $id, array $input): array
    {
        $this->authorize($actor, 'delete');
        $this->validator->validateId($id);
        $this->validator->validateDelete($input);
        $this->connection->begin();
        try {
            $this->repository->findById($actor->companyId, $id, true);
            if ($this->repository->hasTripHistory($actor->companyId, $id)) {
                throw new AppError('REFERENCED_RECORD', 'Vehicle has trip history and cannot be deleted. Deactivate it when available.', 409);
            }
            $this->repository->delete($actor->companyId, $id);
            $this->connection->commit();
            return ['id' => $id, 'deleted' => true];
        } catch (\Throwable $error) {
            $this->connection->rollback();
            if ($error instanceof DatabaseError && $error->oracleCode === 2292) {
                throw new AppError('REFERENCED_RECORD', 'Vehicle is referenced by another record and cannot be deleted.', 409);
            }
            throw $error;
        }
    }

    private function authorize(Actor $actor, string $action): void
    {
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.vehicles', $action);
    }

    private function changedFields(array $vehicle, array $values): array
    {
        $changes = [];
        foreach ($values as $column => $value) {
            $previous = $vehicle[$column];
            if ($column === 'CAPACITY') {
                $same = $this->capacityKey((string) $previous) === $this->capacityKey($value);
            } elseif ($column === 'VEHICLE_CODE') {
                $same = (string) $previous === (string) $value;
            } else {
                $same = $previous === ($value === '' ? null : $value);
            }
            if (!$same) {
                $changes[$column] = $value;
            }
        }
        return $changes;
    }

    /** Compare exact capacities without floating-point conversion. */
    private function capacityKey(string $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return (ltrim($whole, '0') ?: '0') . '.' . str_pad($fraction, 3, '0');
    }

    private function present(array $row): array
    {
        return [
            'id' => (int) $row['ID'],
            'vehicle_code' => (int) $row['VEHICLE_CODE'],
            'registration_number' => $row['REGISTRATION_NUMBER'],
            'description' => $row['DESCRIPTION'],
            'capacity' => (float) $row['CAPACITY'],
            'is_active' => $row['ISACTIVE'],
            ...(array_key_exists('ASSIGNMENT_STATUS', $row) ? ['assignment_status' => $row['ASSIGNMENT_STATUS']] : []),
        ];
    }
}

<?php

declare(strict_types=1);

namespace MiniErp\logistics\tripline;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

/** Draft planning-line CRUD. Trip lifecycle and Sales assignment posting belong to teammates. */
final class TripLineService
{
    private TripLineRepository $repository;
    private TripLineValidator $validator;

    public function __construct(private Connection $connection)
    {
        $this->repository = new TripLineRepository($connection);
        $this->validator = new TripLineValidator();
    }

    public function getTripLines(Actor $actor, array $pagination): array
    {
        $this->authorize($actor, 'read');
        $page = $this->validator->pagination($pagination);
        if ($page['trip_id'] !== null) {
            $this->repository->trip($actor->companyId, $page['trip_id']);
        }
        return [
            'items' => array_map($this->present(...), $this->repository->findAll($actor->companyId, $page)),
            'page' => $page['page'],
            'page_size' => $page['page_size']
        ];
    }

    public function getTripLine(Actor $actor, int $id): array
    {
        $this->authorize($actor, 'read');
        return $this->present($this->repository->find($actor->companyId, $this->validator->id($id)));
    }

    public function createTripLine(Actor $actor, array $input): array
    {
        $this->authorize($actor, 'create');
        $input = $this->validator->create($input);
        return $this->write(function () use ($actor, $input): array {
            $trip = $this->repository->lockDraftTrip($actor->companyId, $input['trip_id']);
            $data = $this->sourceData($actor, $trip, $input, null);
            $row = $this->repository->create(
                $actor->companyId,
                $input['trip_id'],
                $data,
                $this->repository->auditUserId($actor->companyId, $actor->id)
            );
            $this->repository->assertCapacity($actor->companyId, $trip);
            return $this->present($row);
        });
    }

    public function updateTripLine(Actor $actor, int $id, array $input): array
    {
        $this->authorize($actor, 'update');
        $id = $this->validator->id($id);
        $input = $this->validator->update($input);
        return $this->write(function () use ($actor, $id, $input): array {
            [$trip, $line] = $this->lockedLine($actor, $id);
            // Check the old Sales link before replacing it so published assignments cannot be orphaned.
            $this->repository->lockSource($actor->companyId, $trip['SALES_ORDER_ID'], $line['SALES_LINE_ID']);
            $input['sales_line_id'] ??= (int) $line['SALES_LINE_ID'];
            $data = $this->sourceData($actor, $trip, $input, $id);
            $row = $this->repository->update(
                $actor->companyId,
                $id,
                $data,
                $this->repository->auditUserId($actor->companyId, $actor->id)
            );
            $this->repository->assertCapacity($actor->companyId, $trip);
            return $this->present($row);
        });
    }

    public function deleteTripLine(Actor $actor, int $id, array $input): array
    {
        $this->authorize($actor, 'delete');
        $id = $this->validator->id($id);
        $this->validator->delete($input);
        return $this->write(function () use ($actor, $id): array {
            [$trip, $line] = $this->lockedLine($actor, $id);
            $this->repository->lockSource($actor->companyId, $trip['SALES_ORDER_ID'], $line['SALES_LINE_ID']);
            $this->repository->delete($actor->companyId, $id);
            return ['id' => $id, 'deleted' => true];
        });
    }

    private function lockedLine(Actor $actor, int $id): array
    {
        $snapshot = $this->repository->find($actor->companyId, $id);
        $trip = $this->repository->lockDraftTrip($actor->companyId, (int) $snapshot['TRIP_ID']);
        $line = $this->repository->find($actor->companyId, $id, true);
        if ((string) $line['TRIP_ID'] !== (string) $trip['ID']) {
            throw new AppError('CONFLICT', 'Trip line moved. Retry the request.', 409);
        }
        return [$trip, $line];
    }

    private function sourceData(Actor $actor, array $trip, array $input, ?int $excludeId): array
    {
        $source = $this->repository->lockSource($actor->companyId, $trip['SALES_ORDER_ID'], $input['sales_line_id']);
        $quantity = (string) $source['QUANTITY'];
        $product = (string) $source['PRODUCT_ID'];
        if (!preg_match('/\A[1-9][0-9]{0,9}\z/D', $quantity) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $product)) {
            throw new AppError('INVALID_STATE', 'Sales returned an invalid product or whole quantity.', 409);
        }
        // Optional client values are assertions, not authority to split an original Sales line.
        if ((isset($input['quantity']) && (string) $input['quantity'] !== $quantity)
            || (isset($input['product_id']) && (string) $input['product_id'] !== $product)
        ) {
            throw new AppError('VALIDATION_ERROR', 'product_id and quantity must match the complete Sales line.', 400);
        }
        if ($this->repository->duplicateExists($actor->companyId, $input['sales_line_id'], $excludeId)) {
            throw new AppError('DUPLICATE_TRIP_LINE', 'Sales line already belongs to a non-cancelled trip.', 409);
        }
        $weight = $this->repository->weight($actor->companyId, $product, $quantity);
        return [
            'sales_line_id' => $input['sales_line_id'],
            'product_id' => $product,
            'quantity' => $quantity,
            'total_weight' => $weight,
            'line_load' => $weight
        ];
    }

    private function write(callable $operation): array
    {
        try {
            return (new TransactionManager($this->connection))->run($operation);
        } catch (DatabaseError $error) {
            if ($error->oracleCode === 1) {
                throw new AppError('DUPLICATE_TRIP_LINE', 'Trip line conflicts with an existing record.', 409);
            }
            if ($error->oracleCode === 2292) {
                throw new AppError('REFERENCED_RECORD', 'Trip line is referenced by another record.', 409);
            }
            throw $error;
        }
    }

    private function authorize(Actor $actor, string $operation): void
    {
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.trip_lines', $operation);
    }

    private function present(array $row): array
    {
        return [
            'id' => (int) $row['ID'],
            'trip_id' => (int) $row['TRIP_ID'],
            'sales_line_id' => (string) $row['SALES_LINE_ID'],
            'product_id' => (string) $row['PRODUCT_ID'],
            'quantity' => (string) $row['QUANTITY'],
            'total_weight' => (string) $row['TOTAL_WEIGHT'],
            'line_load' => (string) $row['LINE_LOAD']
        ];
    }
}

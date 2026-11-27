<?php

declare(strict_types=1);

namespace MiniErp\logistics\trip;

use MiniErp\inventory\ReservationService;
use MiniErp\logistics\SalesOrderProvider;
use MiniErp\sales\orders\LogisticsOrderAdapter;
use MiniErp\sales\orders\SalesFulfillment;
use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

class TripService
{
    private Connection $connection;
    private TripRepository $repository;
    private TripValidator $validator;
    private ?SalesOrderProvider $salesOrderProvider = null;
    private ?SalesFulfillment $fulfillment = null;
    private ?ReservationService $reservations = null;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->repository = new TripRepository($connection);
        $this->validator = new TripValidator();
    }

    // GET /logistics/trips
    public function getTrips(Actor $actor, array $pagination): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.trips', 'read');

        return [
            'items' => $this->repository->findAll(
                $actor->companyId,
                $pagination['offset'],
                $pagination['page_size']
            ),
            'page' => $pagination['page'],
            'page_size' => $pagination['page_size']
        ];
    }

    public function getEligibleSalesOrders(Actor $actor, array $query): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'sales.orders', 'read');
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.trips', 'read');
        $page = Api::pagination($query, ['search']);
        return $this->repository->findEligibleSalesOrders($actor->companyId, $page);
    }

    // GET /logistics/trips/{id}
    public function getTrip(Actor $actor, int $id): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.trips', 'read');
        $this->validator->validateId($id);

        return $this->requireTrip($actor->companyId, $id);
    }

    // POST /logistics/trips
    public function createTrip(Actor $actor, array $input): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.trips', 'create');
        $data = $this->validator->validateCreate($input);

        $actorId = $this->actorId($actor);
        $fulfillment = $this->salesFulfillment();

        return (new TransactionManager($this->connection))->run(
            function (TransactionContext $tx) use ($actor, $actorId, $data, $fulfillment): array {
                $existingId = $this->repository->findTripIdByCode(
                    $tx,
                    $actor->companyId,
                    $data['trip_code']
                );

                if ($existingId !== null) {
                    $trip = $this->repository->lockTrip($tx, $actor->companyId, $existingId);
                    $tripLines = $this->repository->lockTripLines($tx, $actor->companyId, $existingId);
                    $savedTrip = $this->repository->findById($actor->companyId, $existingId, $tx);
                    $orderNumber = $this->repository->findOrderNumber(
                        $tx,
                        $actor->companyId,
                        $trip['sales_order_id']
                    );

                    if (
                        $trip['vehicle_id'] !== $data['vehicle_id']
                        || $trip['driver_id'] !== $data['driver_id']
                        || $orderNumber !== $data['order_number']
                        || !$this->sameTimestamp($savedTrip['planned_departure_at'], $data['planned_departure_at'])
                        || (string) $savedTrip['assigned_by'] !== $actorId
                    ) {
                        throw new AppError('CONFLICT', 'Trip code already exists for a different assignment.', 409);
                    }

                    $order = $fulfillment->lockOrderForEvent($tx, $actor, $orderNumber, 'assignment');
                    if ((string) ($order['order_id'] ?? '') !== (string) $trip['sales_order_id']) {
                        throw new AppError('DUPLICATE_ACTION_CONFLICT', 'Trip code already exists for a different Sales Order.', 409);
                    }
                    $this->assertTripMatchesOrderDemand($order, $tripLines);

                    // The create and complete assignment committed atomically, so this exact Trip request is a replay.
                    return $this->repository->findById($actor->companyId, $existingId, $tx);
                }

                $order = $fulfillment->lockOrderForEvent($tx, $actor, $data['order_number'], 'assignment');

                if (($order['status'] ?? null) !== 'Submitted') {
                    throw new AppError('INVALID_STATE', 'Only Submitted Sales Orders can be assigned to a Trip.', 409);
                }

                $orderId = $this->decimalId($order['order_id'] ?? null, 'Sales Order ID', 15);
                $salesLines = $this->validateSalesLines($order['lines'] ?? null);
                if ($salesLines === []) {
                    throw new AppError('INVALID_STATE', 'A Sales Order must contain at least one saved line before Trip assignment.', 409);
                }
                $this->assertSalesLinesUnassigned($salesLines);
                if ($this->repository->hasActiveTripForOrder($tx, $actor->companyId, $orderId)) {
                    throw new AppError('CONFLICT', 'This Sales Order already has an active Trip assignment.', 409);
                }

                $this->assertResourcesAvailable(
                    $tx,
                    $actor->companyId,
                    $data['vehicle_id'],
                    $data['driver_id'],
                    $this->validator->normalizedDepartureDate($data['planned_departure_at'])
                );

                $tripId = $this->repository->create(
                    $tx,
                    $actor->companyId,
                    (int) $orderId,
                    $actorId,
                    $data
                );
                $this->repository->createTripLines(
                    $tx,
                    $actor->companyId,
                    $tripId,
                    $actorId,
                    $salesLines
                );
                $tripLines = $this->repository->lockTripLines($tx, $actor->companyId, $tripId);
                $this->assertTripMatchesOrderDemand($order, $tripLines, $salesLines);
                $mapping = $this->tripLineMapping($tripLines);
                $this->repository->assertTripWithinCapacity(
                    $tx,
                    $actor->companyId,
                    $tripId,
                    $data['vehicle_id']
                );
                $eventInput = [
                    'expected_version' => $this->orderVersion($order),
                    'event_reference' => $this->eventReference('assignment', $actor->companyId, $data['order_number'], $data['trip_code']),
                    'trip_id' => (string) $tripId,
                    'lines' => $mapping
                ];

                $fulfillment->recordTripAssignment($tx, $actor, $data['order_number'], $eventInput);

                return $this->repository->findById($actor->companyId, $tripId, $tx);
            }
        );
    }

    // POST /logistics/trips/{id}/update
    public function updateTrip(Actor $actor, int $id, array $input): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.trips', 'update');
        $this->validator->validateId($id);
        $data = $this->validator->validateUpdate($input);
        $actorId = $this->actorId($actor);
        $fulfillment = $this->salesFulfillment();

        return (new TransactionManager($this->connection))->run(
            function (TransactionContext $tx) use ($actor, $id, $data, $actorId, $fulfillment): array {
                $trip = $this->repository->lockTrip($tx, $actor->companyId, $id);
                if ($trip === []) {
                    throw new AppError('NOT_FOUND', 'Trip not found.', 404);
                }
                if ($trip['status'] !== 'DRAFT') {
                    throw new AppError('CONFLICT', 'Only draft trips can be updated.', 409);
                }

                $tripLines = $this->repository->lockTripLines($tx, $actor->companyId, $id);
                if ($tripLines === []) {
                    throw new AppError('INVALID_STATE', 'Trip has no persisted lines.', 409);
                }
                $orderNumber = $this->repository->findOrderNumber($tx, $actor->companyId, $trip['sales_order_id']);
                $order = $fulfillment->lockOrderForEvent($tx, $actor, $orderNumber, 'assignment');
                if (($order['status'] ?? null) !== 'Submitted') {
                    throw new AppError('INVALID_STATE', 'Only a Submitted Sales Order can be updated from a Draft Trip.', 409);
                }
                $this->assertOrderMatchesTrip($order, $trip, $tripLines);

                $this->assertResourcesAvailable(
                    $tx,
                    $actor->companyId,
                    $data['vehicle_id'],
                    $data['driver_id'],
                    $this->validator->normalizedDepartureDate($data['planned_departure_at']),
                    $id
                );

                $this->repository->assertTripWithinCapacity(
                    $tx,
                    $actor->companyId,
                    $id,
                    $data['vehicle_id']
                );

                return $this->repository->update($tx, $actor->companyId, $id, $actorId, $data);
            }
        );
    }

    // POST /logistics/trips/{id}/cancel
    public function cancelTrip(Actor $actor, int $id, array $input): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.trips', 'update');
        $this->validator->validateId($id);
        $data = $this->validator->validateCancel($input);
        $actorId = $this->actorId($actor);
        $fulfillment = $this->salesFulfillment();

        return (new TransactionManager($this->connection))->run(
            function (TransactionContext $tx) use ($actor, $id, $data, $actorId, $fulfillment): array {
                $trip = $this->repository->lockTrip($tx, $actor->companyId, $id);

                if ($trip === []) {
                    throw new AppError('NOT_FOUND', 'Trip not found.', 404);
                }
                $retry = $trip['status'] === 'CANCELLED';
                if (!$retry && $trip['status'] !== 'DRAFT') {
                    throw new AppError('CONFLICT', 'Only draft trips can be cancelled.', 409);
                }

                $tripLines = $this->repository->lockTripLines($tx, $actor->companyId, $id);
                $mapping = $this->tripLineMapping($tripLines);
                $orderNumber = $this->repository->findOrderNumber(
                    $tx,
                    $actor->companyId,
                    $trip['sales_order_id']
                );
                $order = $fulfillment->lockOrderForEvent($tx, $actor, $orderNumber, 'cancellation');

                if (!$retry && ($order['status'] ?? null) !== 'Submitted') {
                    throw new AppError('INVALID_STATE', 'Only a Submitted Sales Order can be cancelled from a Draft Trip.', 409);
                }
                if ($retry) {
                    $saved = $this->repository->findById($actor->companyId, $id, $tx);
                    if ($saved['cancellation_reason'] !== $data['reason'] || (string) $saved['cancelled_by'] !== $actorId) {
                        throw new AppError('DUPLICATE_ACTION_CONFLICT', 'Trip was already cancelled by a different request.', 409);
                    }
                    $this->assertCancelledTripLines($order, $trip, $tripLines);
                    return $saved;
                }
                $this->assertOrderMatchesTrip($order, $trip, $tripLines);

                $cancelled = $this->repository->cancel(
                    $tx,
                    $actor->companyId,
                    $id,
                    $actorId,
                    $data['reason']
                );
                $fulfillment->recordTripCancellation($tx, $actor, $orderNumber, [
                    'expected_version' => $this->orderVersion($order),
                    'event_reference' => $this->eventReference('cancellation', $actor->companyId, $orderNumber, $id),
                    'trip_id' => (string) $id,
                    'lines' => $mapping
                ]);

                return $cancelled;
            }
        );
    }

    // POST /logistics/trips/{id}/dispatch
    public function dispatchTrip(Actor $actor, int $id, array $input): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.trips', 'update');
        $this->validator->validateId($id);
        $this->validator->validateDispatch($input);
        $actorId = $this->actorId($actor);
        $fulfillment = $this->salesFulfillment();
        $reservations = $this->reservationService();

        return (new TransactionManager($this->connection))->run(
            function (TransactionContext $tx) use ($actor, $id, $actorId, $fulfillment, $reservations): array {
                $trip = $this->repository->lockTrip($tx, $actor->companyId, $id);
                if ($trip === []) {
                    throw new AppError('NOT_FOUND', 'Trip not found.', 404);
                }
                $retry = in_array($trip['status'], ['DISPATCHED', 'DELIVERED'], true);
                if (!$retry && $trip['status'] !== 'DRAFT') {
                    throw new AppError('CONFLICT', 'Only draft trips can be dispatched.', 409);
                }
                $savedTrip = $this->repository->findById($actor->companyId, $id, $tx);

                $tripLines = $this->repository->lockTripLines($tx, $actor->companyId, $id);
                if ($tripLines === []) {
                    throw new AppError('INVALID_STATE', 'Trip has no persisted lines.', 409);
                }
                $mapping = $this->tripLineMapping($tripLines);
                $orderNumber = $this->repository->findOrderNumber(
                    $tx,
                    $actor->companyId,
                    $trip['sales_order_id']
                );
                $order = $fulfillment->lockOrderForEvent($tx, $actor, $orderNumber, 'assignment');
                if (!$retry && ($order['status'] ?? null) !== 'Submitted') {
                    throw new AppError('INVALID_STATE', 'Only a Submitted Sales Order can be dispatched.', 409);
                }
                if ($retry && !in_array($order['status'] ?? null, ['In Progress', 'Closed'], true)) {
                    throw new AppError('INVALID_STATE', 'A committed Dispatch retry requires an In Progress or Closed Sales Order.', 409);
                }
                $this->assertOrderMatchesTrip($order, $trip, $tripLines);

                if ($retry) {
                    if ((string) $savedTrip['dispatched_by'] !== $actorId) {
                        throw new AppError('DUPLICATE_ACTION_CONFLICT', 'Trip was already dispatched by a different request.', 409);
                    }
                    foreach ($tripLines as $line) {
                        $this->assertReservationMatches(
                            $reservations->getReservationDetail($actor, $tx, $trip['sales_order_id'], (int) $line['SALES_LINE_ID']),
                            $trip,
                            $line,
                            true
                        );
                    }

                    return $this->repository->findById($actor->companyId, $id, $tx);
                }

                $this->assertResourcesAvailable(
                    $tx,
                    $actor->companyId,
                    $trip['vehicle_id'],
                    $trip['driver_id'],
                    $this->validator->departureDate($savedTrip['planned_departure_at']),
                    $id
                );
                $this->repository->assertTripWithinCapacity(
                    $tx,
                    $actor->companyId,
                    $id,
                    $trip['vehicle_id']
                );

                foreach ($tripLines as $line) {
                    $salesLineId = (int) $line['SALES_LINE_ID'];
                    $productId = (int) $line['PRODUCT_ID'];
                    $quantity = (int) $line['QUANTITY'];
                    $tripLineId = (int) $line['ID'];
                    $reservation = $reservations->getReservationDetail(
                        $actor,
                        $tx,
                        $trip['sales_order_id'],
                        $salesLineId
                    );

                    $this->assertReservationMatches(
                        $reservation,
                        $trip,
                        $line,
                        false
                    );

                    $reservations->consumeReservation(
                        $actor,
                        $tx,
                        $reservation['reservation_id'],
                        $trip['sales_order_id'],
                        $salesLineId,
                        $productId,
                        $quantity,
                        $id,
                        $tripLineId
                    );
                }

                $updated = $this->repository->dispatch($tx, $actor->companyId, $id, $actorId);

                $fulfillment->recordTripAssignment($tx, $actor, $orderNumber, [
                    'expected_version' => $this->orderVersion($order),
                    'event_reference' => $this->eventReference('dispatch', $actor->companyId, $orderNumber, $id),
                    'trip_id' => (string) $id,
                    'lines' => $mapping
                ]);

                return $updated;
            }
        );
    }

    // POST /logistics/trips/{id}/deliver
    public function deliverTrip(Actor $actor, int $id, array $input): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.trips', 'update');
        $this->validator->validateId($id);
        $this->validator->validateDeliver($input);
        $actorId = $this->actorId($actor);
        $fulfillment = $this->salesFulfillment();
        $reservations = $this->reservationService();

        return (new TransactionManager($this->connection))->run(
            function (TransactionContext $tx) use ($actor, $id, $actorId, $fulfillment, $reservations): array {
                $trip = $this->repository->lockTrip($tx, $actor->companyId, $id);

                if ($trip === []) {
                    throw new AppError('NOT_FOUND', 'Trip not found.', 404);
                }

                $retry = $trip['status'] === 'DELIVERED';
                if (!$retry && $trip['status'] !== 'DISPATCHED') {
                    throw new AppError('CONFLICT', 'Only dispatched trips can be delivered.', 409);
                }
                if ($retry) {
                    $savedTrip = $this->repository->findById($actor->companyId, $id, $tx);
                    if ((string) $savedTrip['delivered_by'] !== $actorId) {
                        throw new AppError('DUPLICATE_ACTION_CONFLICT', 'Trip was already delivered by a different request.', 409);
                    }
                }

                $tripLines = $this->repository->lockTripLines($tx, $actor->companyId, $id);
                if ($tripLines === []) {
                    throw new AppError('INVALID_STATE', 'Trip has no persisted lines.', 409);
                }
                $mapping = $this->tripLineMapping($tripLines);

                $orderNumber = $this->repository->findOrderNumber($tx, $actor->companyId, $trip['sales_order_id']);
                $order = $fulfillment->lockOrderForEvent($tx, $actor, $orderNumber, 'delivery');
                if (!$retry && ($order['status'] ?? null) !== 'In Progress') {
                    throw new AppError('INVALID_STATE', 'Only an In Progress Sales Order can be delivered.', 409);
                }
                if ($retry && !in_array($order['status'] ?? null, ['In Progress', 'Closed'], true)) {
                    throw new AppError('INVALID_STATE', 'A committed Delivery retry requires an In Progress or Closed Sales Order.', 409);
                }
                $this->assertOrderMatchesTrip($order, $trip, $tripLines);

                foreach ($tripLines as $line) {
                    $this->assertReservationMatches(
                        $reservations->getReservationDetail($actor, $tx, $trip['sales_order_id'], (int) $line['SALES_LINE_ID']),
                        $trip,
                        $line,
                        true
                    );
                }

                if ($retry) {
                    // Delivery and the Sales update committed together, so only return the saved Trip.
                    return $this->repository->findById($actor->companyId, $id, $tx);
                }

                $delivered = $this->repository->deliver($tx, $actor->companyId, $id, $actorId);

                $fulfillment->recordDelivery($tx, $actor, $orderNumber, [
                    'expected_version' => $this->orderVersion($order),
                    'event_reference' => $this->eventReference('delivery', $actor->companyId, $orderNumber, $id),
                    'trip_id' => (string) $id,
                    'lines' => $mapping
                ]);

                return $delivered;
            }
        );
    }

    private function actorId(Actor $actor): string
    {
        $id = $this->repository->findUserIdByCode($actor->companyId, $actor->id);

        if ($id === null) {
            throw new AppError('UNAUTHENTICATED', 'Actor not found.', 401);
        }
        if (!preg_match('/\A[1-9][0-9]{0,10}\z/D', $id)) {
            throw new AppError('DEPENDENCY_UNAVAILABLE', 'The resolved ME_USERS.ID is not a valid NUMBER(11) audit identity.', 503);
        }

        return $id;
    }

    private function requireTrip(int $companyId, int $id): array
    {
        $trip = $this->repository->findById($companyId, $id);

        if ($trip === []) {
            throw new AppError('NOT_FOUND', 'Trip not found.', 404);
        }

        return $trip;
    }

    private function assertResourcesAvailable(
        TransactionContext $tx,
        int $companyId,
        int $vehicleId,
        int $driverId,
        string $departureDate,
        ?int $excludeTripId = null
    ): void {
        if (!$this->repository->lockVehicle($tx, $companyId, $vehicleId)) {
            throw new AppError('VALIDATION_ERROR', 'Vehicle does not exist or is inactive.');
        }

        if (!$this->repository->lockDriver($tx, $companyId, $driverId)) {
            throw new AppError('VALIDATION_ERROR', 'Driver does not exist or is inactive.');
        }

        if ($this->repository->vehicleHasActiveTrip($companyId, $vehicleId, $excludeTripId, $tx)) {
            throw new AppError('CONFLICT', 'Vehicle is already assigned to an active trip.', 409);
        }

        if ($this->repository->driverHasActiveTrip($companyId, $driverId, $excludeTripId, $tx)) {
            throw new AppError('CONFLICT', 'Driver is already assigned to an active trip.', 409);
        }

        if (!$this->repository->driverLicenceValidForDate(
            $companyId,
            $driverId,
            $departureDate,
            $tx
        )) {
            throw new AppError('INVALID_STATE', 'Driver licence is not valid for the planned departure.', 409);
        }
    }

    private function validateSalesLines(mixed $lines): array
    {
        if (!is_array($lines) || !array_is_list($lines)) {
            throw new AppError('DEPENDENCY_UNAVAILABLE', 'Sales returned invalid Order line data.', 503);
        }

        $validated = [];
        $seen = [];

        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new AppError('DEPENDENCY_UNAVAILABLE', 'Sales returned invalid Order line data.', 503);
            }

            $line['line_id'] = $this->decimalId($line['line_id'] ?? null, 'Sales line ID', 15);
            $line['product_id'] = $this->decimalId($line['product_id'] ?? null, 'Product ID', 10);
            $line['quantity'] = $this->decimalId($line['quantity'] ?? null, 'Quantity', 10);
            foreach (['trip_id', 'trip_line_id'] as $field) {
                if (($line[$field] ?? null) !== null) {
                    $line[$field] = $this->decimalId($line[$field], 'Sales line assignment ID', 15);
                }
            }

            if (isset($seen[$line['line_id']])) {
                throw new AppError('INVALID_STATE', 'Sales returned duplicate Order lines.', 409);
            }

            $seen[$line['line_id']] = true;
            $validated[] = $line;
        }

        return $validated;
    }

    private function assertSalesLinesUnassigned(array $salesLines): void
    {
        foreach ($salesLines as $line) {
            if (
                ($line['trip_id'] ?? null) !== null
                || ($line['trip_line_id'] ?? null) !== null
                || !in_array($line['trip_status'] ?? null, [null, '', 'Cancelled'], true)
            ) {
                throw new AppError('CONFLICT', 'The Sales Order already has a current Trip assignment.', 409);
            }
        }
    }

    private function assertOrderMatchesTrip(array $order, array $trip, array $tripLines): void
    {
        if ((string) ($order['order_id'] ?? '') !== (string) $trip['sales_order_id']) {
            throw new AppError('CONFLICT', 'Trip Sales Order does not match the locked Sales Order.', 409);
        }

        $salesLines = $this->validateSalesLines($order['lines'] ?? null);
        $this->assertTripMatchesOrderDemand($order, $tripLines, $salesLines);
        $salesById = [];
        foreach ($salesLines as $line) {
            $salesById[$line['line_id']] = $line;
        }
        $expectedStatus = match ($trip['status']) {
            'DRAFT' => 'Draft',
            'DISPATCHED' => 'Dispatched',
            'DELIVERED' => 'Delivered',
            default => throw new AppError('CONFLICT', 'Trip status cannot be matched to a current Sales assignment.', 409),
        };

        foreach ($tripLines as $tripLine) {
            $lineId = (string) $tripLine['SALES_LINE_ID'];
            $salesLine = $salesById[$lineId] ?? null;

            if (
                $salesLine === null
                || $salesLine['product_id'] !== (string) $tripLine['PRODUCT_ID']
                || $salesLine['quantity'] !== (string) $tripLine['QUANTITY']
                || (string) ($salesLine['trip_id'] ?? '') !== (string) $trip['id']
                || (string) ($salesLine['trip_line_id'] ?? '') !== (string) $tripLine['ID']
                || ($salesLine['trip_status'] ?? null) !== $expectedStatus
            ) {
                throw new AppError('CONFLICT', 'Persisted Trip line does not match its current Sales assignment.', 409);
            }
        }
    }

    private function assertTripMatchesOrderDemand(array $order, array $tripLines, ?array $salesLines = null): void
    {
        $salesLines ??= $this->validateSalesLines($order['lines'] ?? null);
        if ($salesLines === [] || count($tripLines) !== count($salesLines)) {
            throw new AppError('CONFLICT', 'Trip must contain every saved Sales Order line exactly once.', 409);
        }
        $this->tripLineMapping($tripLines);

        $salesById = [];
        foreach ($salesLines as $line) {
            $salesById[$line['line_id']] = $line;
        }
        $seen = [];
        foreach ($tripLines as $tripLine) {
            $lineId = (string) $tripLine['SALES_LINE_ID'];
            $salesLine = $salesById[$lineId] ?? null;
            if (
                isset($seen[$lineId])
                || $salesLine === null
                || $salesLine['product_id'] !== (string) $tripLine['PRODUCT_ID']
                || $salesLine['quantity'] !== (string) $tripLine['QUANTITY']
            ) {
                throw new AppError('CONFLICT', 'Trip lines must match every saved Sales Order line exactly once.', 409);
            }
            $seen[$lineId] = true;
        }
    }

    private function assertCancelledTripLines(array $order, array $trip, array $tripLines): void
    {
        if ((string) ($order['order_id'] ?? '') !== (string) $trip['sales_order_id']) {
            throw new AppError('CONFLICT', 'Trip Sales Order does not match the locked Sales Order.', 409);
        }
        $salesLines = $this->validateSalesLines($order['lines'] ?? null);
        $this->assertTripMatchesOrderDemand($order, $tripLines, $salesLines);
        $cancelledTripLineIds = [];
        foreach ($tripLines as $tripLine) {
            $cancelledTripLineIds[(string) $tripLine['ID']] = true;
        }
        $currentTrip = null;
        $currentStatus = null;
        $currentCount = 0;
        $currentTripLines = [];
        foreach ($salesLines as $line) {
            $tripId = $line['trip_id'] ?? null;
            $tripLineId = $line['trip_line_id'] ?? null;
            if (($tripId === null) !== ($tripLineId === null)) {
                throw new AppError('CONFLICT', 'Cancelled Trip history does not match the cleared Sales line assignments.', 409);
            }
            if ($tripId === null) {
                if (($line['trip_status'] ?? null) !== 'Cancelled') {
                    throw new AppError('CONFLICT', 'Cancelled Trip history does not match the cleared Sales line assignments.', 409);
                }
                continue;
            }
            if ((string) $tripId === (string) $trip['id'] || isset($cancelledTripLineIds[(string) $tripLineId])) {
                throw new AppError('CONFLICT', 'Cancelled Trip history still owns a current Sales line assignment.', 409);
            }
            $status = $line['trip_status'] ?? null;
            if (!in_array($status, ['Draft', 'Dispatched', 'Delivered'], true)
                || ($currentTrip !== null && $currentTrip !== (string) $tripId)
                || ($currentStatus !== null && $currentStatus !== $status)
                || isset($currentTripLines[(string) $tripLineId])
            ) {
                throw new AppError('CONFLICT', 'Current Sales assignments must belong to one Trip with one shared status.', 409);
            }
            $currentTripLines[(string) $tripLineId] = true;
            $currentTrip = (string) $tripId;
            $currentStatus = $status;
            $currentCount++;
        }
        if ($currentCount !== 0 && $currentCount !== count($salesLines)) {
            throw new AppError('CONFLICT', 'A Sales Order cannot have a partial current Trip assignment.', 409);
        }
    }

    private function tripLineMapping(array $tripLines): array
    {
        $mapping = [];
        $seenSalesLines = [];
        $seenTripLines = [];

        foreach ($tripLines as $line) {
            $salesLineId = (string) $line['SALES_LINE_ID'];
            $tripLineId = (string) $line['ID'];

            if (isset($seenSalesLines[$salesLineId]) || isset($seenTripLines[$tripLineId])) {
                throw new AppError('INVALID_STATE', 'Trip contains duplicate Sales or Trip line identities.', 409);
            }

            $seenSalesLines[$salesLineId] = true;
            $seenTripLines[$tripLineId] = true;
            $mapping[] = [
                'line_id' => $salesLineId,
                'trip_line_id' => $tripLineId
            ];
        }

        return $mapping;
    }

    private function assertReservationMatches(array $reservation, array $trip, array $line, bool $retry): void
    {
        $status = $retry ? 'DISPATCHED' : 'ACTIVE';

        if (
            $reservation['status'] !== $status
            || $reservation['sales_order_id'] !== $trip['sales_order_id']
            || $reservation['sales_line_id'] !== (int) $line['SALES_LINE_ID']
            || $reservation['product_id'] !== (int) $line['PRODUCT_ID']
            || $reservation['reserved_quantity'] !== (int) $line['QUANTITY']
        ) {
            throw new AppError('CONFLICT', 'Inventory reservation does not match the persisted Trip line.', 409);
        }

        if (!$retry && (
            $reservation['active_quantity'] !== (int) $line['QUANTITY']
            || $reservation['claimed_quantity'] !== (int) $line['QUANTITY']
            || $reservation['claim_check_available'] !== true
        )) {
            throw new AppError('CONFLICT', 'Inventory reservation does not confirm the complete Draft Trip claim.', 409);
        }

        if ($retry && (
            $reservation['trip_id'] !== $trip['id']
            || $reservation['trip_line_id'] !== (int) $line['ID']
        )) {
            throw new AppError('DUPLICATE_ACTION_CONFLICT', 'Inventory reservation belongs to a different Trip dispatch.', 409);
        }
    }

    private function decimalId(mixed $value, string $label, int $maxDigits): string
    {
        if (
            (!is_string($value) && !is_int($value))
            || !preg_match('/\A[1-9][0-9]{0,' . ($maxDigits - 1) . '}\z/D', (string) $value)
        ) {
            throw new AppError('DEPENDENCY_UNAVAILABLE', 'Sales returned an invalid ' . $label . '.', 503);
        }

        return (string) $value;
    }

    private function orderVersion(array $order): int
    {
        $version = $order['current_version'] ?? null;
        if (
            (!is_int($version) && !is_string($version))
            || !preg_match('/\A[1-9][0-9]{0,9}\z/D', (string) $version)
        ) {
            throw new AppError('DEPENDENCY_UNAVAILABLE', 'Sales returned an invalid Order version.', 503);
        }

        return (int) $version;
    }

    private function eventReference(string $event, int $companyId, string $orderNumber, int $tripIdentity): string
    {
        return 'logtrip_' . $event . '_' . hash('sha256', $companyId . '|' . $orderNumber . '|' . $tripIdentity);
    }

    private function sameTimestamp(string $saved, string $requested): bool
    {
        return (new \DateTimeImmutable($saved))->format('U.u')
            === (new \DateTimeImmutable($requested))->format('U.u');
    }

    private function salesFulfillment(): SalesFulfillment
    {
        if (
            !interface_exists(\MiniErp\sales\orders\LogisticsOrderProvider::class)
            || !class_exists(\MiniErp\sales\orders\LogisticsOrderAdapter::class)
            || !class_exists(SalesFulfillment::class)
            || !class_exists(ReservationService::class)
        ) {
            throw new AppError('DEPENDENCY_UNAVAILABLE', 'SalesFulfillment or Inventory ReservationService is unavailable.', 503);
        }

        $missingSalesClasses = [];
        foreach ([
            \MiniErp\sales\SalesPermissions::class,
            \MiniErp\sales\Validation::class,
            \MiniErp\sales\DocumentRules::class,
            \MiniErp\sales\orders\SalesTripAssignmentGuard::class,
        ] as $class) {
            if (!class_exists($class)) {
                $missingSalesClasses[] = $class;
            }
        }
        if ($missingSalesClasses !== []) {
            throw new AppError(
                'DEPENDENCY_UNAVAILABLE',
                'Required Sales implementations are missing: ' . implode(', ', $missingSalesClasses) . '.',
                503
            );
        }

        if ($this->fulfillment === null) {
            $provider = $this->salesOrderProvider();
            $adapter = new LogisticsOrderAdapter($provider);
            $reservations = new ReservationService(
                $this->connection,
                static function (Actor $actor, TransactionContext $tx, int $orderId, int $lineId) use ($provider): int {
                    return $provider->getPlannedQuantity($actor, $tx, $orderId, $lineId);
                }
            );
            $this->reservations = $reservations;
            $this->fulfillment = new SalesFulfillment($this->connection, $adapter, $reservations);
        }

        if ($this->fulfillment === null) {
            throw new AppError('DEPENDENCY_UNAVAILABLE', 'Sales fulfillment could not be initialized.', 503);
        }

        return $this->fulfillment;
    }

    private function reservationService(): ReservationService
    {
        if ($this->reservations === null) {
            $this->salesFulfillment();
        }

        if ($this->reservations === null) {
            throw new AppError('DEPENDENCY_UNAVAILABLE', 'Inventory ReservationService could not be initialized.', 503);
        }

        return $this->reservations;
    }

    private function salesOrderProvider(): SalesOrderProvider
    {
        if (!interface_exists(\MiniErp\sales\orders\LogisticsOrderProvider::class)) {
            throw new AppError('DEPENDENCY_UNAVAILABLE', 'Sales LogisticsOrderProvider contract is unavailable.', 503);
        }

        if ($this->salesOrderProvider === null) {
            $this->salesOrderProvider = new SalesOrderProvider($this->connection);
        }

        if ($this->salesOrderProvider === null) {
            throw new AppError('DEPENDENCY_UNAVAILABLE', 'Sales order provider could not be initialized.', 503);
        }

        return $this->salesOrderProvider;
    }
}

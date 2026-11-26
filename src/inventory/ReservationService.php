<?php
declare(strict_types=1);

namespace MiniErp\inventory;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

final readonly class ReservationService
{
    /**
     * The optional server-side adapter returns Draft-trip claimed base quantity.
     * Release defaults to the deployed Logistics table adapter when none is injected.
     * It receives (Actor, TransactionContext, salesOrderId, salesLineId).
     * The caller must already hold the agreed Sales-line lock before any write service.
     * @param (\Closure(Actor, TransactionContext, int, int): int)|null $plannedQuantity
     */
    public function __construct(private Connection $connection, private ?\Closure $plannedQuantity = null) {}

    /**
     * Latest reservation snapshot for an original Sales line, including resubmission cycles.
     * Quantities follow the whole-line status model; released records remain as history.
     * Draft claims are Logistics-owned; missing trip IDs do not prove a line is unclaimed.
     *
     * @return array{reservation_id: int, sales_order_id: int, sales_line_id: int, product_id: int,
     *     reserved_quantity: int, active_quantity: int, released_quantity: int, consumed_quantity: int,
     *     status: string, trip_id: ?int, trip_line_id: ?int, claimed_quantity: ?int,
     *     releasable_quantity: ?int, claim_check_available: bool}
     */
    public function getReservationDetail(Actor $actor, TransactionContext $tx, int $salesOrderId, int $salesLineId): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'inventory.reservations', 'read');
        if ($salesOrderId < 1 || $salesOrderId > 999999999999999
            || $salesLineId < 1 || $salesLineId > 999999999999999) {
            throw new AppError('VALIDATION_ERROR', 'Sales order and line IDs must be positive integers of at most 15 digits.');
        }
        $rows = $tx->query(
            'SELECT RESERVATION_ID, SALES_ORDER_ID, SALES_LINE_ID, PRODUCT_ID,
                    RESERVED_QUANTITY, RESERVATION_STATUS, TRIP_ID, TRIP_LINE_ID
             FROM ME_INV_RESERVATION
             WHERE COMPANY_ID = :company_id AND SALES_ORDER_ID = :sales_order_id AND SALES_LINE_ID = :sales_line_id
             ORDER BY RESERVATION_ID DESC',
            ['company_id' => $actor->companyId, 'sales_order_id' => $salesOrderId, 'sales_line_id' => $salesLineId],
        );
        if ($rows === []) {
            throw new AppError('NOT_FOUND', 'Reservation not found for this Sales order and line.', 404);
        }
        return $this->presentReservation($actor, $tx, $rows[0]);
    }

    /** Fetch the selected historical cycle rather than the latest reservation for its Sales line. */
    public function getReservationById(Actor $actor, TransactionContext $tx, int $reservationId): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'inventory.reservations', 'read');
        if ($reservationId < 1 || $reservationId > 9999999999) {
            throw new AppError('VALIDATION_ERROR', 'Reservation ID must be a positive integer of at most 10 digits.');
        }
        $rows = $tx->query(
            'SELECT RESERVATION_ID, SALES_ORDER_ID, SALES_LINE_ID, PRODUCT_ID,
                    RESERVED_QUANTITY, RESERVATION_STATUS, TRIP_ID, TRIP_LINE_ID
             FROM ME_INV_RESERVATION WHERE COMPANY_ID = :company_id AND RESERVATION_ID = :reservation_id',
            ['company_id' => $actor->companyId, 'reservation_id' => $reservationId],
        );
        if ($rows === []) {
            throw new AppError('NOT_FOUND', 'Reservation not found for this company.', 404);
        }
        return $this->presentReservation($actor, $tx, $rows[0]);
    }

    private function presentReservation(Actor $actor, TransactionContext $tx, array $row): array
    {
        $salesOrderId = (int) $row['SALES_ORDER_ID'];
        $salesLineId = (int) $row['SALES_LINE_ID'];
        $status = $row['RESERVATION_STATUS'];
        $quantity = (int) $row['RESERVED_QUANTITY'];
        if ($quantity < 1 || !in_array($status, ['ACTIVE', 'RELEASED', 'DISPATCHED'], true)) {
            throw new AppError('INVALID_STATE', 'The reservation has an invalid status or quantity.', 409);
        }
        $active = $status === 'ACTIVE' ? $quantity : 0;
        // Draft claims on the same Sales line belong to its current ACTIVE cycle, not released history.
        $claimed = $this->plannedQuantity === null ? null
            : ($status === 'ACTIVE' ? $this->claimedQuantity($actor, $tx, $salesOrderId, $salesLineId, $active) : 0);
        return [
            'reservation_id' => (int) $row['RESERVATION_ID'],
            'sales_order_id' => (int) $row['SALES_ORDER_ID'],
            'sales_line_id' => (int) $row['SALES_LINE_ID'],
            'product_id' => (int) $row['PRODUCT_ID'],
            'reserved_quantity' => $quantity,
            'active_quantity' => $active,
            'released_quantity' => $status === 'RELEASED' ? $quantity : 0,
            'consumed_quantity' => $status === 'DISPATCHED' ? $quantity : 0,
            'status' => $status,
            'trip_id' => $row['TRIP_ID'] === null ? null : (int) $row['TRIP_ID'],
            'trip_line_id' => $row['TRIP_LINE_ID'] === null ? null : (int) $row['TRIP_LINE_ID'],
            'claimed_quantity' => $claimed,
            'releasable_quantity' => $status === 'ACTIVE' ? ($claimed === null ? null : $active - $claimed) : 0,
            'claim_check_available' => $this->plannedQuantity !== null,
        ];
    }

    /**
     * Join Sales' transaction; any rejection must roll back the entire submission.
     * Sales validates/locks its original lines and owns whole-order action replay/audit.
     * Repeating an identical active source returns its reservation with current balances.
     * After release, a new submission creates a new reservation and retains released history.
     *
     * @return array{reservation_id: int, sales_order_id: int, sales_line_id: int,
     *     product_id: int, reserved_quantity: int, status: string, on_hand: int,
     *     reserved: int, available: int}
     */
    public function reserveStock(
        Actor $actor,
        TransactionContext $tx,
        int $salesOrderId,
        int $salesLineId,
        int $productId,
        int $quantity,
    ): array {
        (new Authorization($this->connection))->requirePermission($actor, 'inventory.reservations', 'reserve');

        if ($salesOrderId < 1 || $salesOrderId > 999999999999999
            || $salesLineId < 1 || $salesLineId > 999999999999999
            || $productId < 1 || $productId > 9999999999
            || $quantity < 1 || $quantity > 9999999999) {
            throw new AppError('VALIDATION_ERROR', 'Provide valid positive IDs and a whole quantity of 1-9999999999.');
        }

        $source = ['company_id' => $actor->companyId, 'sales_order_id' => $salesOrderId, 'sales_line_id' => $salesLineId];

        $productKey = ['company_id' => $actor->companyId, 'product_id' => $productId];
        $products = $tx->query(
            'SELECT IS_ACTIVE FROM ME_INV_PRODUCTS WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id',
            $productKey,
        );
        if ($products === []) {
            throw new AppError('INVALID_STATE', 'The product does not exist for this company.', 409);
        }

        $balances = $tx->query(
            'SELECT ON_HAND, RESERVED FROM ME_INV_STOCK_BALANCE
             WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id FOR UPDATE',
            $productKey,
        );

        if ($balances === []) {
            throw new AppError('INSUFFICIENT_STOCK', 'There is no stock available for this product.', 409);
        }
        $onHand = (int) $balances[0]['ON_HAND'];
        $reserved = (int) $balances[0]['RESERVED'];
        if ($onHand < 0 || $reserved < 0 || $reserved > $onHand) {
            throw new AppError('INVALID_STATE', 'The stock balance is inconsistent; reconcile it before allocation.', 409);
        }
        // Read the source after the balance lock, including a competing request's committed insert.
        $existing = $tx->query(
            'SELECT RESERVATION_ID, PRODUCT_ID, RESERVED_QUANTITY, RESERVATION_STATUS
             FROM ME_INV_RESERVATION
             WHERE COMPANY_ID = :company_id AND SALES_ORDER_ID = :sales_order_id AND SALES_LINE_ID = :sales_line_id
             FOR UPDATE',
            $source,
        );
        $current = null;
        foreach ($existing as $reservation) {
            if ($reservation['RESERVATION_STATUS'] === 'DISPATCHED') {
                throw new AppError('INVALID_STATE', 'This Sales line reservation has already been dispatched.', 409);
            }
            if ($reservation['RESERVATION_STATUS'] === 'ACTIVE') {
                if ($current !== null) {
                    throw new AppError('INVALID_STATE', 'This Sales line has multiple active reservations; reconcile it first.', 409);
                }
                $current = $reservation;
            } elseif ($reservation['RESERVATION_STATUS'] !== 'RELEASED') {
                throw new AppError('INVALID_STATE', 'This Sales line has an invalid reservation status.', 409);
            }
        }
        if ($current !== null && ((int) $current['PRODUCT_ID'] !== $productId
            || (int) $current['RESERVED_QUANTITY'] !== $quantity)) {
            throw new AppError('DUPLICATE_ACTION_CONFLICT', 'This Sales line already has a reservation with a different product or quantity.', 409);
        }
        if ($current !== null) {
            // Source-level duplicate protection returns current balances, not a saved action result.
            return ['reservation_id' => (int) $current['RESERVATION_ID'], 'sales_order_id' => $salesOrderId,
                'sales_line_id' => $salesLineId, 'product_id' => $productId, 'reserved_quantity' => $quantity,
                'status' => 'ACTIVE', 'on_hand' => $onHand, 'reserved' => $reserved, 'available' => $onHand - $reserved];
        }
        if (!in_array((string) $products[0]['IS_ACTIVE'], ['Y', 'T', '1'], true)) {
            throw new AppError('INVALID_STATE', 'The product is inactive.', 409);
        }
        if ($quantity > $onHand - $reserved) {
            throw new AppError('INSUFFICIENT_STOCK', 'There is not enough available stock to reserve the complete Sales line.', 409);
        }

        $reservationId = (int) $tx->query('SELECT ME_INV_RESERVATION_SEQ.NEXTVAL AS RESERVATION_ID FROM DUAL')[0]['RESERVATION_ID'];
        $result = [
            'reservation_id' => $reservationId, 'sales_order_id' => $salesOrderId, 'sales_line_id' => $salesLineId,
            'product_id' => $productId, 'reserved_quantity' => $quantity, 'status' => 'ACTIVE',
            'on_hand' => $onHand, 'reserved' => $reserved + $quantity, 'available' => $onHand - $reserved - $quantity,
        ];
        try {
            $tx->execute(
                "INSERT INTO ME_INV_RESERVATION
                    (COMPANY_ID, RESERVATION_ID, PRODUCT_ID, SALES_ORDER_ID, SALES_LINE_ID,
                     RESERVED_QUANTITY, RESERVATION_STATUS)
                 VALUES (:company_id, :reservation_id, :product_id, :sales_order_id, :sales_line_id,
                         :quantity, 'ACTIVE')",
                [...$source, 'reservation_id' => $reservationId, 'product_id' => $productId, 'quantity' => $quantity],
            );
        } catch (DatabaseError $error) {
            if ($error->oracleCode !== 1) {
                throw $error;
            }
            throw new AppError('DUPLICATE_ACTION_CONFLICT', 'This Sales line already has a reservation. Roll back this submission.', 409);
        }
        $changed = $tx->execute(
            'UPDATE ME_INV_STOCK_BALANCE SET RESERVED = RESERVED + :quantity
             WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id
               AND ON_HAND - RESERVED >= :quantity',
            [...$productKey, 'quantity' => $quantity],
        );
        if ($changed !== 1) {
            throw new AppError('INVALID_STATE', 'The stock balance could not be updated. Roll back this submission.', 409);
        }
        return $result;
    }
    /**
     * Release the complete original line inside Sales' cancellation transaction.
     * Sales checks cancellation eligibility, holds its line lock, and records the reason/audit.
     * Missing Logistics information is an error, never an assumed zero claim.
     */
    public function releaseReservation(
        Actor $actor,
        TransactionContext $tx,
        int $reservationId,
        int $salesOrderId,
        int $salesLineId,
        int $productId,
        int $quantity,
    ): array {
        (new Authorization($this->connection))->requirePermission($actor, 'inventory.reservations', 'release');
        $this->validateTransition($reservationId, $salesOrderId, $salesLineId, $productId, $quantity);
        $claimLookup = $this->plannedQuantity;
        if ($claimLookup === null) {
            // Acquire the shared source lock BEFORE balance/reservation locks. Logistics
            // planning must take this same Sales-line lock before creating/removing claims.
            $source = $tx->query('SELECT RECORD# FROM ME_OE_DOCUMENT_LINES
                WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :sales_order_id AND RECORD# = :sales_line_id FOR UPDATE',
                ['company_id' => $actor->companyId, 'sales_order_id' => $salesOrderId, 'sales_line_id' => $salesLineId]);
            if ($source === []) {
                throw new AppError('NOT_FOUND', 'Original Sales line not found for this company and order.', 404);
            }
            $claimLookup = \Closure::fromCallable([(new LogisticsClaimLookup()), 'getPlannedQuantity']);
        }
        [$row, $onHand, $reserved] = $this->lockReservation($actor, $tx, $reservationId, $salesOrderId, $salesLineId, $productId, $quantity);
        if ($row['RESERVATION_STATUS'] === 'RELEASED') {
            return [...$this->transitionResult($row, $onHand, $reserved), 'released_quantity' => $quantity];
        }
        if ($row['RESERVATION_STATUS'] !== 'ACTIVE') {
            throw new AppError('INVALID_STATE', 'Only an ACTIVE reservation can be released.', 409);
        }
        if ($this->claimedQuantity($actor, $tx, $salesOrderId, $salesLineId, $quantity, $claimLookup) > 0) {
            throw new AppError('QUANTITY_CONFLICT', 'Remove all Draft-trip claims before releasing this full-line reservation.', 409);
        }
        if ($reserved < $quantity) {
            throw new AppError('INVALID_STATE', 'Reserved stock is inconsistent with the reservation.', 409);
        }
        $changed = $tx->execute(
            'UPDATE ME_INV_STOCK_BALANCE SET RESERVED = RESERVED - :quantity
             WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id AND RESERVED >= :quantity',
            ['company_id' => $actor->companyId, 'product_id' => $productId, 'quantity' => $quantity],
        );
        $this->requireChanged($changed);
        $changed = $tx->execute(
            "UPDATE ME_INV_RESERVATION SET RESERVATION_STATUS = 'RELEASED'
             WHERE COMPANY_ID = :company_id AND RESERVATION_ID = :reservation_id AND RESERVATION_STATUS = 'ACTIVE'",
            ['company_id' => $actor->companyId, 'reservation_id' => $reservationId],
        );
        $this->requireChanged($changed);
        $row['RESERVATION_STATUS'] = 'RELEASED';
        return [...$this->transitionResult($row, $onHand, $reserved - $quantity), 'released_quantity' => $quantity];
    }

    /**
     * Consume the complete reservation inside Logistics' dispatch transaction.
     * Logistics validates/locks its trip, claims and original Sales lines before this call.
     * The stable dispatch source is TRIP + "tripId:tripLineId"; retries cannot repeat its stock effect.
     */
    public function consumeReservation(
        Actor $actor,
        TransactionContext $tx,
        int $reservationId,
        int $salesOrderId,
        int $salesLineId,
        int $productId,
        int $quantity,
        int $tripId,
        int $tripLineId,
    ): array {
        (new Authorization($this->connection))->requirePermission($actor, 'inventory.reservations', 'consume');
        $this->validateTransition($reservationId, $salesOrderId, $salesLineId, $productId, $quantity);
        if ($tripId < 1 || $tripId > 999999999999999 || $tripLineId < 1 || $tripLineId > 999999999999999) {
            throw new AppError('VALIDATION_ERROR', 'Trip and trip-line IDs must be positive integers of at most 15 digits.');
        }
        [$row, $onHand, $reserved] = $this->lockReservation($actor, $tx, $reservationId, $salesOrderId, $salesLineId, $productId, $quantity);
        $sourceId = $tripId . ':' . $tripLineId;
        $movements = $tx->query(
            "SELECT MOVEMENT_ID, PRODUCT_ID, RESERVATION_ID, DISPATCHED_QTY, OPENING_BALANCE,
                    CLOSING_BALANCE, MOVEMENT_TYPE, SOURCE_TYPE, SOURCE_ID
             FROM ME_INV_STOCK_MOVEMENT WHERE COMPANY_ID = :company_id
               AND (RESERVATION_ID = :reservation_id OR (SOURCE_TYPE = 'TRIP' AND SOURCE_ID = :source_id))",
            ['company_id' => $actor->companyId, 'reservation_id' => $reservationId, 'source_id' => $sourceId],
        );
        if ($row['RESERVATION_STATUS'] === 'DISPATCHED') {
            if ((int) $row['TRIP_ID'] !== $tripId || (int) $row['TRIP_LINE_ID'] !== $tripLineId
                || count($movements) !== 1 || !$this->matchingMovement($movements[0], $reservationId, $productId, $quantity, $sourceId)) {
                throw new AppError('DUPLICATE_ACTION_CONFLICT', 'This reservation was dispatched with a different source, or its movement is inconsistent.', 409);
            }
            return [...$this->transitionResult($row, $onHand, $reserved),
                'consumed_quantity' => $quantity, 'movement_id' => (int) $movements[0]['MOVEMENT_ID']];
        }
        if ($row['RESERVATION_STATUS'] !== 'ACTIVE') {
            throw new AppError('INVALID_STATE', 'Only an ACTIVE reservation can be consumed.', 409);
        }
        if ($movements !== []) {
            throw new AppError('DUPLICATE_ACTION_CONFLICT', 'The reservation or dispatch source already has a stock movement.', 409);
        }
        if ($reserved < $quantity || $onHand < $quantity) {
            throw new AppError('INVALID_STATE', 'Stock is inconsistent with the reservation; dispatch cannot proceed.', 409);
        }
        $movementId = (int) $tx->query('SELECT ME_INV_MOVEMENT_SEQ.NEXTVAL AS MOVEMENT_ID FROM DUAL')[0]['MOVEMENT_ID'];
        try {
            $tx->execute(
                "INSERT INTO ME_INV_STOCK_MOVEMENT
                    (COMPANY_ID, MOVEMENT_ID, PRODUCT_ID, OPENING_BALANCE, RECEIVED_QTY,
                     DISPATCHED_QTY, CLOSING_BALANCE, MOVEMENT_TYPE, SOURCE_TYPE, SOURCE_ID, RESERVATION_ID)
                 VALUES (:company_id, :movement_id, :product_id, :opening, 0,
                         :quantity, :closing, 'DISPATCH', 'TRIP', :source_id, :reservation_id)",
                ['company_id' => $actor->companyId, 'movement_id' => $movementId, 'product_id' => $productId,
                    'opening' => $onHand, 'quantity' => $quantity, 'closing' => $onHand - $quantity,
                    'source_id' => $sourceId, 'reservation_id' => $reservationId],
            );
            $changed = $tx->execute(
                'UPDATE ME_INV_STOCK_BALANCE SET ON_HAND = ON_HAND - :quantity, RESERVED = RESERVED - :quantity
                 WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id
                   AND ON_HAND >= :quantity AND RESERVED >= :quantity',
                ['company_id' => $actor->companyId, 'product_id' => $productId, 'quantity' => $quantity],
            );
            $this->requireChanged($changed);
            $changed = $tx->execute(
                "UPDATE ME_INV_RESERVATION SET RESERVATION_STATUS = 'DISPATCHED', TRIP_ID = :trip_id, TRIP_LINE_ID = :trip_line_id
                 WHERE COMPANY_ID = :company_id AND RESERVATION_ID = :reservation_id AND RESERVATION_STATUS = 'ACTIVE'",
                ['company_id' => $actor->companyId, 'reservation_id' => $reservationId, 'trip_id' => $tripId, 'trip_line_id' => $tripLineId],
            );
            $this->requireChanged($changed);
        } catch (DatabaseError $error) {
            if ($error->oracleCode !== 1) {
                throw $error;
            }
            throw new AppError('DUPLICATE_ACTION_CONFLICT', 'This dispatch source is already in use. Roll back the whole dispatch.', 409);
        }
        $row['RESERVATION_STATUS'] = 'DISPATCHED';
        $row['TRIP_ID'] = $tripId;
        $row['TRIP_LINE_ID'] = $tripLineId;
        return [...$this->transitionResult($row, $onHand - $quantity, $reserved - $quantity),
            'consumed_quantity' => $quantity, 'movement_id' => $movementId];
    }

    private function validateTransition(int $reservationId, int $salesOrderId, int $salesLineId, int $productId, int $quantity): void
    {
        if ($reservationId < 1 || $reservationId > 9999999999 || $productId < 1 || $productId > 9999999999
            || $salesOrderId < 1 || $salesOrderId > 999999999999999 || $salesLineId < 1 || $salesLineId > 999999999999999
            || $quantity < 1 || $quantity > 9999999999) {
            throw new AppError('VALIDATION_ERROR', 'Provide valid positive reservation, Sales and product IDs, and a whole quantity of 1-9999999999.');
        }
    }

    /** Lock balances before reservations. Sales/Logistics must already hold their earlier locks. */
    private function lockReservation(
        Actor $actor, TransactionContext $tx, int $reservationId, int $salesOrderId, int $salesLineId, int $productId, int $quantity,
    ): array {
        $balances = $tx->query(
            'SELECT ON_HAND, RESERVED FROM ME_INV_STOCK_BALANCE
             WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id FOR UPDATE',
            ['company_id' => $actor->companyId, 'product_id' => $productId],
        );
        $rows = $tx->query(
            'SELECT RESERVATION_ID, SALES_ORDER_ID, SALES_LINE_ID, PRODUCT_ID,
                    RESERVED_QUANTITY, RESERVATION_STATUS, TRIP_ID, TRIP_LINE_ID
             FROM ME_INV_RESERVATION WHERE COMPANY_ID = :company_id AND RESERVATION_ID = :reservation_id FOR UPDATE',
            ['company_id' => $actor->companyId, 'reservation_id' => $reservationId],
        );
        if ($rows === []) {
            throw new AppError('NOT_FOUND', 'Reservation not found for this company.', 404);
        }
        $row = $rows[0];
        if ((int) $row['SALES_ORDER_ID'] !== $salesOrderId || (int) $row['SALES_LINE_ID'] !== $salesLineId
            || (int) $row['PRODUCT_ID'] !== $productId) {
            throw new AppError('INVALID_STATE', 'The reservation does not match the original Sales order, line and product.', 409);
        }
        if ((int) $row['RESERVED_QUANTITY'] !== $quantity) {
            throw new AppError('QUANTITY_CONFLICT', 'This status-based reservation must be released or consumed in full.', 409);
        }
        if ($balances === [] || !in_array($row['RESERVATION_STATUS'], ['ACTIVE', 'RELEASED', 'DISPATCHED'], true)) {
            throw new AppError('INVALID_STATE', 'The reservation or stock balance is inconsistent.', 409);
        }
        $onHand = (int) $balances[0]['ON_HAND'];
        $reserved = (int) $balances[0]['RESERVED'];
        if ($onHand < 0 || $reserved < 0 || $reserved > $onHand) {
            throw new AppError('INVALID_STATE', 'The stock balance is inconsistent.', 409);
        }
        return [$row, $onHand, $reserved];
    }

    private function claimedQuantity(Actor $actor, TransactionContext $tx, int $orderId, int $lineId, int $active, ?\Closure $lookup = null): int
    {
        $lookup ??= $this->plannedQuantity;
        if ($lookup === null) {
            throw new AppError('DEPENDENCY_UNAVAILABLE', 'Logistics claim lookup is not connected; release eligibility is unknown.', 503);
        }
        $claimed = $lookup($actor, $tx, $orderId, $lineId);
        if (!is_int($claimed) || $claimed < 0 || $claimed > $active) {
            throw new AppError('QUANTITY_CONFLICT', 'Logistics returned an invalid or inconsistent claimed base quantity.', 409);
        }
        return $claimed;
    }

    private function transitionResult(array $row, int $onHand, int $reserved): array
    {
        return ['reservation_id' => (int) $row['RESERVATION_ID'], 'sales_order_id' => (int) $row['SALES_ORDER_ID'],
            'sales_line_id' => (int) $row['SALES_LINE_ID'], 'product_id' => (int) $row['PRODUCT_ID'],
            'reserved_quantity' => (int) $row['RESERVED_QUANTITY'], 'status' => $row['RESERVATION_STATUS'],
            'trip_id' => $row['TRIP_ID'] === null ? null : (int) $row['TRIP_ID'],
            'trip_line_id' => $row['TRIP_LINE_ID'] === null ? null : (int) $row['TRIP_LINE_ID'],
            'on_hand' => $onHand, 'reserved' => $reserved, 'available' => $onHand - $reserved];
    }

    private function matchingMovement(array $row, int $reservationId, int $productId, int $quantity, string $sourceId): bool
    {
        return (int) $row['RESERVATION_ID'] === $reservationId && (int) $row['PRODUCT_ID'] === $productId
            && (int) $row['DISPATCHED_QTY'] === $quantity && $row['MOVEMENT_TYPE'] === 'DISPATCH'
            && $row['SOURCE_TYPE'] === 'TRIP' && $row['SOURCE_ID'] === $sourceId
            && (int) $row['CLOSING_BALANCE'] >= 0 && (int) $row['OPENING_BALANCE'] - $quantity === (int) $row['CLOSING_BALANCE'];
    }

    private function requireChanged(int $changed): void
    {
        if ($changed !== 1) {
            throw new AppError('INVALID_STATE', 'An Inventory row could not be updated. Roll back the entire business transaction.', 409);
        }
    }
}

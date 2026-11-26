<?php
declare(strict_types=1);

namespace MiniErp\sales\orders;

use MiniErp\logistics\SalesOrderProvider;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;

/** Sales translates its integration boundary to the Logistics-owned service. */
final readonly class LogisticsOrderAdapter implements LogisticsOrderProvider
{
    public function __construct(private SalesOrderProvider $provider) {}

    public function assertEvent(TransactionContext $tx, Actor $actor, string $event, string $orderId,
        string $tripId, array $lines, string $eventReference): void
    {
        $this->provider->assertEvent($tx, $actor, $event, $orderId, $tripId, $lines, $eventReference);
    }

    public function getAssignments(TransactionContext $tx, Actor $actor, string $orderId): array
    {
        return $this->provider->getAssignments($tx, $actor, $orderId);
    }

    public function assertUnassigned(TransactionContext $tx, Actor $actor, string $orderId): void
    {
        $this->provider->assertUnassigned($tx, $actor, $orderId);
    }

    public function getPlannedQuantity(Actor $actor, TransactionContext $tx, int $orderId, int $lineId): int
    {
        return $this->provider->getPlannedQuantity($actor, $tx, $orderId, $lineId);
    }
}

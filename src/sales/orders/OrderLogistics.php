<?php
declare(strict_types=1);

namespace MiniErp\sales\orders;

use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;

/** Assignment verification boundary. The default SalesTripAssignmentGuard uses current saved Sales fields.
 * Caller holds source Quote and Order parent locks. Logistics must mirror claims/assignment changes in
 * those fields atomically and synchronize on the same Order parent/lines. Alternative owner-backed
 * providers may implement this interface, but the default return flow needs no external Logistics lookup.
 */
interface OrderLogistics
{
    public function assertUnassigned(TransactionContext $tx, Actor $actor, string $orderId): void;
}

<?php
declare(strict_types=1);

namespace MiniErp\purchasing\Controller;

use MiniErp\purchasing\Model\PurchasingDocumentModel;
use MiniErp\purchasing\Model\VendorModel;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

/** Session requests reuse the same validation and models as the versioned API. */
final readonly class PurchasingWebController
{
    public function __construct(private Connection $connection) {}

    public function handle(Actor $actor, string $method, string $path, array $input, array $query): array
    {
        if (!preg_match('#^/api/purchasing/(vendors|documents)(?:/([a-zA-Z0-9_-]{1,40}))?$#', $path, $match)
            || !in_array($method, ['GET', 'POST'], true)) {
            throw new AppError('NOT_FOUND', 'Purchasing operation not found.', 404);
        }
        $vendor = $match[1] === 'vendors';
        $params = isset($match[2]) ? ['id' => $match[2]] : [];
        $operation = $method === 'GET' ? 'read' : ($params === [] ? 'create' : 'update');
        (new Authorization($this->connection))->requirePermission(
            $actor, $vendor ? 'purchasing.vendor' : 'purchasing.document', $operation,
        );
        $controller = $vendor
            ? new VendorController(new VendorModel($this->connection))
            : new PurchasingDocumentController(new PurchasingDocumentModel($this->connection));
        $action = $method === 'GET' ? ($params === [] ? 'list' : 'get') : $operation;
        return $controller->$action($actor, $input, $params, $query);
    }
}

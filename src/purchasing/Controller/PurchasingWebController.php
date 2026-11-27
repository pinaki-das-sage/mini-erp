<?php
declare(strict_types=1);

namespace MiniErp\purchasing\Controller;

use MiniErp\purchasing\Model\PurchasingDocumentModel;
use MiniErp\purchasing\Model\VendorModel;
use MiniErp\purchasing\Model\PurchasingReferenceModel;
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
        $auth = new Authorization($this->connection);
        if ($method === 'GET' && $path === '/api/purchasing/context') {
            return ['permissions' => $auth->permissions($actor),
                'receiving_available' => PurchasingDocumentModel::receivingAvailable(),
                'warehouse_id' => PurchasingDocumentModel::WAREHOUSE];
        }
        if ($method === 'GET' && preg_match('#^/api/purchasing/references/(products|vendors)$#', $path, $reference)) {
            if (array_intersect($auth->permissions($actor), [
                'purchasing.document:read', 'purchasing.document:create', 'purchasing.document:update',
            ]) === []) {
                throw new AppError('FORBIDDEN', 'You do not have permission to view purchasing references.', 403);
            }
            $search = $query['search'] ?? '';
            if (!is_string($search) || mb_strlen($search) > 100) {
                throw new AppError('VALIDATION_ERROR', 'Search must be at most 100 characters.');
            }
            $model = new PurchasingReferenceModel($this->connection);
            return $reference[1] === 'products' ? $model->products($actor, $search) : $model->vendors($actor, $search);
        }
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

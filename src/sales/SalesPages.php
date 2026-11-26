<?php
declare(strict_types=1);

namespace MiniErp\sales;

use MiniErp\sales\customers\CustomerPages;
use MiniErp\sales\invoices\InvoicePages;
use MiniErp\shared\AppError;
use MiniErp\shared\WebPath;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;
use MiniErp\shared\view\Renderer;
use MiniErp\sales\payments\PaymentPages;

/** Sales owns browser dispatch and page data; Kernel supplies the authenticated session actor. */
final readonly class SalesPages
{
    public function __construct(private Connection $connection, private WebPath $paths, private string $csrf) {}

    public function handle(string $method, string $path, Actor $actor, array $input, array $query): array
    {
        if ($method === 'GET' && $path === '/modules/sales') {
            $urls = [];
            foreach (['customers' => SalesPermissions::CUSTOMERS, 'invoices' => SalesPermissions::DOCUMENTS['invoice']] as $section => $object) {
                try {
                    (new Authorization($this->connection))->requirePermission($actor, $object, 'read');
                    $urls[$section] = $this->paths->url('/modules/sales/' . $section);
                } catch (AppError $error) {
                    if ($error->errorCode !== 'FORBIDDEN') { throw $error; }
                }
            }
            if ((new PaymentPages(
                $this->connection,
                $this->paths,
                $this->csrf,
            ))->canOpen($actor)) {
                $urls['payments'] = $this->paths->url('/modules/sales/payments');
            }
            return ['status' => 200, 'headers' => ['Content-Type' => 'text/html; charset=UTF-8'],
                'body' => (new Renderer($this->paths))->render('sales/index.tpl', 'Sales', $this->csrf,
                    ['actor' => $actor, 'salesUrls' => $urls])];
        }
        foreach (['customers' => CustomerPages::class, 'invoices' => InvoicePages::class, 'payments' => PaymentPages::class,] as $section => $class) {
            $base = '/modules/sales/' . $section;
            if ($path === $base || str_starts_with($path, $base . '/')) {
                return (new $class($this->connection, $this->paths, $this->csrf))->handle($method, $path, $actor, $input, $query);
            }
        }
        throw new AppError('NOT_FOUND', 'Sales page not found.', 404);
    }
}

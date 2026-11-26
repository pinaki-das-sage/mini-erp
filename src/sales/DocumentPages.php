<?php
declare(strict_types=1);

namespace MiniErp\sales;

use MiniErp\inventory\QuoteInventoryValidator;
use MiniErp\inventory\ReservationService;
use MiniErp\sales\orders\InventoryOrderAdapter;
use MiniErp\sales\orders\OrderService;
use MiniErp\sales\orders\SalesTripAssignmentGuard;
use MiniErp\sales\quotes\QuoteService;
use MiniErp\shared\AppError;
use MiniErp\shared\WebPath;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;
use MiniErp\shared\security\Security;
use MiniErp\shared\view\Renderer;

/** Session pages use the same services and transaction boundaries as the JSON API. */
final readonly class DocumentPages
{
    private QuoteService|OrderService $service;
    private SalesPermissions $permissions;
    private string $base;
    private const FILTERS = ['customer_code', 'warehouse_id', 'status', 'from_date', 'to_date'];
    private const ACTIONS = [
        'submit' => ['submit', 'Submit'],
        'move-to-draft' => ['return_to_draft', 'Move to Draft'],
        'delete' => ['delete', 'Delete Draft'],
    ];

    public function __construct(
        private Connection $connection,
        private WebPath $paths,
        private string $csrf,
        private string $kind,
        ?QuoteService $quotes = null,
        ?OrderService $orders = null,
    ) {
        if (!in_array($kind, ['quote', 'order'], true)) { throw new \InvalidArgumentException('Unsupported Sales document.'); }
        $this->base = '/modules/sales/' . $kind . 's';
        $this->permissions = new SalesPermissions(new Authorization($connection));
        $guard = new SalesTripAssignmentGuard();
        $this->service = $kind === 'quote' ? ($quotes ?? new QuoteService($connection)) : ($orders ?? new OrderService(
            $connection, new InventoryOrderAdapter(new ReservationService($connection, $guard->getPlannedQuantity(...)),
                new QuoteInventoryValidator()), $guard,
        ));
    }

    public function handle(string $method, string $path, Actor $actor, array $input, array $query): array
    {
        if (!in_array($method, ['GET', 'POST'], true)) { throw new AppError('NOT_FOUND', 'Sales page not found.', 404); }
        if ($method === 'POST') { Security::verifyCsrf(['csrf_token' => $this->csrf], $input['csrf_token'] ?? null); }
        if ($path === $this->base && $method === 'GET') { return $this->listing($actor, $query); }
        if ($path === $this->base . '/new') { return $this->form($actor, null, $method, $input, $query); }
        if (!preg_match('~^' . preg_quote($this->base, '~') . '/([A-Za-z0-9_-]{1,20})(?:/(edit|submit|move-to-draft|delete))?$~D', $path, $match)) {
            throw new AppError('NOT_FOUND', 'Sales page not found.', 404);
        }
        $number = $match[1];
        $action = $match[2] ?? '';
        if ($action === 'edit') { return $this->form($actor, $number, $method, $input, []); }
        if (isset(self::ACTIONS[$action])) { return $this->confirmation($actor, $number, $action, $method, $input); }
        if ($method === 'GET' && $action === '') { return $this->detail($actor, $this->service->get($actor, $number)); }
        throw new AppError('NOT_FOUND', 'Sales page not found.', 404);
    }

    private function listing(Actor $actor, array $query): array
    {
        $this->permissions->requireDocument($actor, $this->kind, 'read');
        $fields = [...self::FILTERS, $this->kind . '_number'];
        foreach ($fields as $field) { if (($query[$field] ?? null) === '') { unset($query[$field]); } }
        unset($query['page_size']);
        $error = null;
        $status = 200;
        try { $list = $this->service->list($actor, ListingFilters::query($query)); }
        catch (AppError $exception) {
            if ($exception->status !== 400) { throw $exception; }
            $list = ['items' => [], 'page' => 1, 'page_size' => 25];
            $error = $exception->getMessage();
            $status = 400;
        }
        foreach ($list['items'] as &$document) {
            $document['number'] = $document[$this->kind . '_number'];
            $document['date'] = $document[$this->kind . '_date'];
            $document['viewUrl'] = $this->url($document['number']);
        }
        unset($document);
        $filters = array_fill_keys($fields, '');
        foreach ($fields as $field) { if (is_string($query[$field] ?? null)) { $filters[$field] = $query[$field]; } }
        $filters['date_range'] = ListingFilters::range($query);
        $pageUrl = fn (int $page): string => $this->paths->url($this->base) . '?' . http_build_query([...$query, 'page' => (string) $page]);
        return $this->page('list', ucfirst($this->kind) . 's', $actor, [
            'list' => $list, 'filters' => $filters, 'error' => $error,
            'numberFilterValue' => $filters[$this->kind . '_number'],
            'statuses' => $this->kind === 'quote' ? ['Draft', 'Submitted'] : ['Draft', 'Submitted', 'In Progress', 'Closed'],
            'createUrl' => $this->can($actor, $this->kind, 'create') &&
                ($this->kind === 'quote' || $this->can($actor, 'quote', 'read')) ? $this->paths->url($this->base . '/new') : null,
            'previousUrl' => $error === null && $list['page'] > 1 ? $pageUrl($list['page'] - 1) : null,
            'nextUrl' => $error === null && count($list['items']) === $list['page_size'] && $list['page'] < 1000000
                ? $pageUrl($list['page'] + 1) : null,
        ], $status);
    }

    private function detail(Actor $actor, array $document, ?string $error = null, int $status = 200): array
    {
        $number = $document[$this->kind . '_number'];
        $actions = [];
        foreach (self::ACTIONS as $path => [$serviceAction, $label]) {
            $available = $path === 'move-to-draft' ? 'move_to_draft' : $path;
            if (in_array($available, $document['available_actions'], true)) {
                $actions[] = ['url' => $this->url($number, '/' . $path), 'label' => $label];
            }
        }
        return $this->page('detail', $number, $actor, [
            'document' => $document, 'number' => $number, 'actions' => $actions, 'error' => $error,
            'invoiceAction' => $this->kind === 'order' ? $this->invoiceAction($actor, $number, $document['status']) : null,
            'editUrl' => in_array('edit', $document['available_actions'], true) ? $this->url($number, '/edit') : null,
            'createOrderUrl' => $this->kind === 'quote' && $document['status'] === 'Submitted' &&
                $this->can($actor, 'order', 'create') && $this->can($actor, 'quote', 'read')
                ? $this->paths->url('/modules/sales/orders/new') . '?' . http_build_query(['source_quote_number' => $number]) : null,
        ], $status);
    }

    private function form(Actor $actor, ?string $number, string $method, array $input, array $query): array
    {
        $isNew = $number === null;
        $this->permissions->requireDocument($actor, $this->kind, $isNew ? 'create' : 'edit');
        $error = null;
        $status = 200;
        // Perform writes before a read so retrying a successful request can return its saved result.
        if ($method === 'POST') {
            try {
                $payload = $this->payload($input, !$isNew);
                $saved = $isNew ? $this->service->create($actor, $payload) : $this->service->update($actor, $number, $payload);
                return $this->redirect($this->can($actor, $this->kind, 'read')
                    ? $this->url($saved[$this->kind . '_number']) : $this->paths->url('/modules/sales'));
            } catch (AppError $exception) {
                if (!in_array($exception->status, [400, 409], true)) { throw $exception; }
                $error = $exception->getMessage();
                $status = $exception->status;
            }
        }
        $document = $isNew ? $this->defaults() : $this->service->get($actor, $number);
        if (!$isNew && !in_array('edit', $document['available_actions'], true)) {
            return $this->detail($actor, $document, $error ?? 'This document is no longer editable.', 409);
        }
        if ($isNew && $this->kind === 'order' && is_string($query['source_quote_number'] ?? null)) {
            $document['source_quote_number'] = $query['source_quote_number'];
        }
        $expectedVersion = $document['current_version'] ?? '';
        if ($method === 'POST') {
            $editable = $this->kind === 'quote'
                ? ['customer_code', 'warehouse_id', 'quote_date', 'valid_until', 'requested_delivery_date', 'shipping_address', 'payment_terms', 'notes']
                : ['order_date', 'requested_delivery_date', 'customer_po_number', 'notes', ...($isNew ? ['source_quote_number'] : [])];
            foreach ($editable as $field) { if (is_string($input[$field] ?? null)) { $document[$field] = $input[$field]; } }
            if (!$isNew && is_string($input['expected_version'] ?? null)) { $expectedVersion = $input['expected_version']; }
            if (isset($input['lines']) && is_array($input['lines'])) { $document['lines'] = $this->displayLines($input['lines']); }
        }
        $sources = [];
        $customers = [];
        $products = [];
        if ($isNew && $this->kind === 'order') {
            $sources = $this->service->eligibleQuotes($actor, ['page_size' => '100'])['items'];
        } else {
            if ($this->kind === 'quote') {
                $customers = $this->connection->query("SELECT CUSTOMER_CODE, CUSTOMER_NAME FROM ME_OE_CUSTOMERS
                    WHERE CNY# = :company_id AND IS_ACTIVE = 'T' ORDER BY CUSTOMER_NAME, CUSTOMER_CODE FETCH FIRST 100 ROWS ONLY",
                    ['company_id' => $actor->companyId]);
            }
            $products = $this->connection->query("SELECT PRODUCT_ID, SKU FROM ME_INV_PRODUCTS
                WHERE COMPANY_ID = :company_id AND WAREHOUSE_ID = 'W01' AND IS_ACTIVE = 'Y'
                ORDER BY SKU, PRODUCT_ID FETCH FIRST 100 ROWS ONLY", ['company_id' => $actor->companyId]);
        }
        return $this->page('form', ($isNew ? 'New ' : 'Edit Draft ') . ucfirst($this->kind), $actor, [
            'document' => $document, 'isNew' => $isNew, 'expectedVersion' => $expectedVersion,
            'sources' => $sources, 'customers' => $customers, 'products' => $products,
            'formUrl' => $isNew ? $this->paths->url($this->base . '/new') : $this->url($number, '/edit'),
            'actionReference' => $this->reference($input), 'error' => $error,
        ], $status);
    }

    private function confirmation(Actor $actor, string $number, string $action, string $method, array $input): array
    {
        [$serviceAction, $label] = self::ACTIONS[$action];
        $this->permissions->requireDocument($actor, $this->kind, $serviceAction);
        $error = null;
        $status = 200;
        if ($method === 'POST') {
            try {
                $result = $this->service->action($actor, $number, $serviceAction, $this->payload($input, true));
                return $this->redirect(!empty($result['deleted']) ? $this->paths->url($this->base) : $this->url($number));
            } catch (AppError $exception) {
                if (!in_array($exception->status, [400, 409, 503], true)) { throw $exception; }
                $error = $exception->getMessage();
                $status = $exception->status;
            }
        }
        $document = $this->service->get($actor, $number);
        $available = $action === 'move-to-draft' ? 'move_to_draft' : $action;
        if (!in_array($available, $document['available_actions'], true)) {
            return $this->detail($actor, $document, $error ?? 'This action is no longer available.', 409);
        }
        $messages = [
            'submit' => $this->kind === 'quote' ? 'Submit this Quote? Products and warehouse will be verified.'
                : 'Submit this Order? Available stock will be checked and reserved. You can then create its Invoice from the Order page.',
            'move-to-draft' => $this->kind === 'quote' ? 'Return this Quote to Draft for editing?'
                : 'Return this Order to Draft? This requires no assigned Trip. Any linked Invoice must still be Draft. Reserved stock will be released and any Draft Invoice removed.',
            'delete' => 'Permanently delete this Draft and its item lines?',
        ];
        return $this->page('confirm', $label . ' ' . ucfirst($this->kind), $actor, [
            'document' => $document, 'number' => $number, 'viewUrl' => $this->url($number),
            'actionUrl' => $this->url($number, '/' . $action), 'label' => $label, 'message' => $messages[$action],
            'expectedVersion' => is_string($input['expected_version'] ?? null) ? $input['expected_version'] : $document['current_version'],
            'actionReference' => $this->reference($input), 'error' => $error,
        ], $status);
    }

    private function payload(array $input, bool $versionRequired): array
    {
        $payload = $input;
        unset($payload['csrf_token']);
        if (!is_string($payload['action_reference'] ?? null) || !preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $payload['action_reference'])) {
            throw new AppError('VALIDATION_ERROR', 'Invalid request reference. Reload the page and try again.');
        }
        if ($versionRequired) {
            if (!is_string($payload['expected_version'] ?? null) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $payload['expected_version'])) {
                throw new AppError('VALIDATION_ERROR', 'Invalid document version. Reload the page and try again.');
            }
            $payload['expected_version'] = (int) $payload['expected_version'];
        }
        if ($this->kind === 'order' && !$versionRequired) {
            foreach (['order_date', 'requested_delivery_date', 'notes', 'customer_po_number'] as $field) {
                if (($payload[$field] ?? null) === '') { unset($payload[$field]); }
            }
        }
        foreach (['valid_until', 'requested_delivery_date'] as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] === '') { $payload[$field] = null; }
        }
        if ($this->kind === 'quote') {
            foreach (['shipping_address', 'payment_terms'] as $field) {
                if (!$versionRequired && ($payload[$field] ?? null) === '') { unset($payload[$field]); }
            }
        }
        if (isset($payload['lines_present'])) {
            unset($payload['lines_present']);
            if (!array_key_exists('lines', $payload)) { $payload['lines'] = []; }
        }
        if (isset($payload['lines']) && is_array($payload['lines'])) {
            // PHP form indexes have gaps after a row is removed. New rows have no saved line ID.
            $payload['lines'] = array_values($payload['lines']);
            foreach ($payload['lines'] as &$line) {
                if (is_array($line) && ($line['line_id'] ?? null) === '') { unset($line['line_id']); }
            }
            unset($line);
        }
        return $payload;
    }

    private function displayLines(array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            if (!is_array($line)) { continue; }
            $display = [];
            foreach (['line_id', 'product_id', 'description', 'quantity', 'unit_price'] as $field) {
                $display[$field] = is_string($line[$field] ?? null) ? $line[$field] : '';
            }
            $result[] = $display;
        }
        return $result;
    }

    private function defaults(): array
    {
        return ['customer_code' => '', 'warehouse_id' => 'W01', 'quote_date' => date('Y-m-d'),
            'order_date' => '', 'valid_until' => '', 'requested_delivery_date' => '', 'shipping_address' => '',
            'payment_terms' => '', 'notes' => '', 'customer_po_number' => '', 'source_quote_number' => '',
            'lines' => [['line_id' => '', 'product_id' => '', 'description' => '', 'quantity' => '1', 'unit_price' => '0.00']]];
    }

    private function reference(array $input): string
    {
        $value = $input['action_reference'] ?? null;
        return is_string($value) && preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $value) ? $value : bin2hex(random_bytes(16));
    }

    private function invoiceAction(Actor $actor, string $number, string $status): ?array
    {
        if (!in_array($status, ['Submitted', 'In Progress', 'Closed'], true)) { return null; }
        $read = $this->can($actor, 'invoice', 'read');
        $create = $status === 'Submitted' && $this->can($actor, 'invoice', 'create');
        if (!$read && !$create) { return null; }
        $rows = $this->connection->query("SELECT I.DOCUMENT_NUMBER AS INVOICE_NUMBER FROM ME_OE_DOCUMENTS O
            JOIN ME_OE_SOURCE_LINKS L ON L.CNY# = O.CNY# AND L.SOURCE_DOCUMENT_RECORD# = O.RECORD#
            JOIN ME_OE_DOCUMENTS I ON I.CNY# = L.CNY# AND I.RECORD# = L.TARGET_DOCUMENT_RECORD#
            WHERE O.CNY# = :company_id AND O.DOCUMENT_TYPE = 'SALES_ORDER' AND O.DOCUMENT_NUMBER = :order_number
              AND I.DOCUMENT_TYPE = 'SALES_INVOICE'", ['company_id' => $actor->companyId, 'order_number' => $number]);
        if ($rows !== []) {
            return $read ? ['create' => false, 'url' => $this->paths->url('/modules/sales/invoices/' . rawurlencode($rows[0]['INVOICE_NUMBER']))] : null;
        }
        return $create ? ['create' => true, 'url' => $this->paths->url('/modules/sales/invoices/new'),
            'sourceNumber' => $number, 'actionReference' => bin2hex(random_bytes(16))] : null;
    }

    private function can(Actor $actor, string $kind, string $action): bool
    {
        try { $this->permissions->requireDocument($actor, $kind, $action); return true; }
        catch (AppError $error) { if ($error->errorCode !== 'FORBIDDEN') { throw $error; } return false; }
    }

    private function url(string $number, string $suffix = ''): string { return $this->paths->url($this->base . '/' . rawurlencode($number) . $suffix); }
    private function redirect(string $url): array { return ['status' => 303, 'headers' => ['Location' => $url], 'body' => '']; }

    private function page(string $view, string $title, Actor $actor, array $data, int $status = 200): array
    {
        return ['status' => $status, 'headers' => ['Content-Type' => 'text/html; charset=UTF-8'],
            'body' => (new Renderer($this->paths))->render('sales/documents/' . $view . '.tpl', $title, $this->csrf, [
                'actor' => $actor, 'kind' => $this->kind, 'documentLabel' => ucfirst($this->kind),
                'listUrl' => $this->paths->url($this->base), 'salesUrl' => $this->paths->url('/modules/sales'),
                'scriptUrl' => $this->paths->url('/assets/sales/documents.js'),
                'styleUrl' => $this->paths->url('/assets/sales/documents.css'), ...$data,
                'dateRangeScriptUrl' => $this->paths->url('/assets/sales/date-range.js'),
                'dateRangeStyleUrl' => $this->paths->url('/assets/sales/date-range.css'),
            ])];
    }
}

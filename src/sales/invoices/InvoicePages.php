<?php
declare(strict_types=1);

namespace MiniErp\sales\invoices;

use MiniErp\sales\BusinessDate;
use MiniErp\sales\ListingFilters;
use MiniErp\sales\SalesPermissions;
use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\WebPath;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;
use MiniErp\shared\security\Security;
use MiniErp\shared\view\Renderer;

final readonly class InvoicePages
{
    private const BASE = '/modules/sales/invoices';

    private const FILTERS = [
        'invoice_number',
        'customer_code',
        'warehouse_id',
        'status',
        'payment_status',
        'from_date',
        'to_date',
    ];

    private const ACTIONS = [
        'submit' => [
            'service' => 'submit',
            'available' => 'submit',
            'label' => 'Submit invoice',
            'message' => 'Submit this invoice and add its total to the Customer account balance?',
        ],
        'move-to-draft' => [
            'service' => 'return_to_draft',
            'available' => 'move_to_draft',
            'label' => 'Return to draft',
            'message' => 'Return this invoice to Draft and remove its debt from the Customer account balance?',
        ],
        'delete' => [
            'service' => 'delete',
            'available' => 'delete',
            'label' => 'Delete draft invoice',
            'message' => 'Permanently delete this draft invoice and its item lines?',
        ],
    ];

    private InvoiceService $invoices;
    private InvoiceLookups $lookups;
    private Authorization $authorization;
    private \Closure $businessDate;

    public function __construct(
        Connection $connection,
        private WebPath $paths,
        private string $csrf,
        ?\Closure $businessDate = null,
    ) {
        $this->businessDate = $businessDate ?? BusinessDate::today(...);
        $this->invoices = new InvoiceService($connection, $this->businessDate);
        $this->lookups = new InvoiceLookups($connection);
        $this->authorization = new Authorization($connection);
    }

    public function handle(
        string $method,
        string $path,
        Actor $actor,
        array $input,
        array $query,
    ): array {
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new AppError('NOT_FOUND', 'Invoice page not found.', 404);
        }

        if ($method === 'POST') {
            Security::verifyCsrf(
                ['csrf_token' => $this->csrf],
                $input['csrf_token'] ?? null,
            );
        }

        if ($path === self::BASE && $method === 'GET') {
            return $this->listing($actor, $query);
        }

        if ($path === self::BASE . '/new') {
            return $this->formPage($actor, null, $method, $input);
        }

        if (!preg_match(
            '~^/modules/sales/invoices/([A-Za-z0-9-]{1,20})(?:/(edit|submit|move-to-draft|delete))?$~D',
            $path,
            $match,
        )) {
            throw new AppError('NOT_FOUND', 'Invoice page not found.', 404);
        }

        $number = $match[1];
        $action = $match[2] ?? '';

        if ($action === 'edit') {
            return $this->formPage($actor, $number, $method, $input);
        }

        if (isset(self::ACTIONS[$action])) {
            return $this->actionPage($actor, $number, $action, $method, $input);
        }

        if ($method === 'GET' && $action === '') {
            return $this->detail($actor, $this->invoices->get($actor, $number));
        }

        throw new AppError('NOT_FOUND', 'Invoice page not found.', 404);
    }

    private function listing(Actor $actor, array $query): array
    {
        $this->authorization->requirePermission($actor, SalesPermissions::DOCUMENTS['invoice'], 'read');
        unset($query['page_size']);
        foreach (self::FILTERS as $filter) {
            if (($query[$filter] ?? null) === '') {
                unset($query[$filter]);
            }
        }

        $error = null;
        $status = 200;

        try {
            $invoices = $this->invoices->list($actor, ListingFilters::query($query));
        } catch (AppError $exception) {
            if ($exception->status !== 400) {
                throw $exception;
            }

            $error = $exception->getMessage();
            $status = 400;
            $invoices = ['items' => [], 'page' => 1, 'page_size' => 25];
        }

        foreach ($invoices['items'] as &$invoice) {
            $invoice['viewUrl'] = $this->invoiceUrl($invoice['invoice_number']);
        }
        unset($invoice);

        $filters = array_fill_keys(self::FILTERS, '');

        foreach (self::FILTERS as $filter) {
            if (isset($query[$filter]) && is_string($query[$filter])) {
                $filters[$filter] = $query[$filter];
            }
        }

        $listUrl = $this->paths->url(self::BASE);
        $filters['date_range'] = ListingFilters::range($query);
        $pageUrl = static fn (int $page): string =>
            $listUrl . '?' . http_build_query([...$query, 'page' => (string) $page]);

        return $this->page('list', 'Sales invoices', $actor, [
            'invoices' => $invoices,
            'filters' => $filters,
            'listUrl' => $listUrl,
            'salesUrl' => $this->paths->url('/modules/sales'),
            'styleUrl' => $this->paths->url('/assets/sales/documents.css'),
            'dateRangeScriptUrl' => $this->paths->url('/assets/sales/date-range.js'),
            'dateRangeStyleUrl' => $this->paths->url('/assets/sales/date-range.css'),
            'createUrl' => $this->can($actor, SalesPermissions::DOCUMENTS['invoice'], 'create')
                && $this->can($actor, SalesPermissions::DOCUMENTS['order'], 'read')
                ? $this->paths->url(self::BASE . '/new')
                : null,
            'previousUrl' => $invoices['page'] > 1
                ? $pageUrl($invoices['page'] - 1)
                : null,
            'nextUrl' => $error === null
                && count($invoices['items']) === $invoices['page_size']
                && $invoices['page'] < 1000000
                ? $pageUrl($invoices['page'] + 1)
                : null,
            'error' => $error,
        ], $status);
    }

    private function formPage(
        Actor $actor,
        ?string $number,
        string $method,
        array $input,
    ): array {
        $isNew = $number === null;

        $this->authorization->requirePermission(
            $actor,
            SalesPermissions::DOCUMENTS['invoice'],
            $isNew ? 'create' : 'update',
        );

        $error = null;
        $status = 200;

        if ($method === 'POST') {
            try {
                $payload = $this->payload($input, !$isNew);

                $saved = $isNew
                    ? $this->invoices->create($actor, $payload)
                    : $this->invoices->update($actor, $number, $payload);

                return $this->redirect($this->can($actor, SalesPermissions::DOCUMENTS['invoice'], 'read')
                    ? $this->invoiceUrl($saved['invoice_number']) : $this->paths->url('/modules/sales'));
            } catch (AppError $exception) {
                if (!in_array($exception->status, [400, 404, 409], true)) {
                    throw $exception;
                }

                $error = $exception->getMessage();
                $status = $exception->status;
            }
        }

        $invoice = $isNew
            ? ['invoice_number' => '', 'source_order_number' => '', 'current_version' => null]
            : $this->invoices->get($actor, $number);

        if (!$isNew && !in_array('edit', $invoice['available_actions'], true)) {
            return $this->detail(
                $actor,
                $invoice,
                $error ?? 'This invoice is no longer editable.',
                409,
            );
        }

        $expectedVersion = $invoice['current_version'];
        if ($method === 'POST') {
            foreach (['source_order_number', 'invoice_date', 'due_date', 'notes'] as $field) {
                if (isset($input[$field]) && is_string($input[$field])) { $invoice[$field] = $input[$field]; }
            }
            // Retain the submitted revision on an edit error; do not silently overwrite concurrent edits.
            if (!$isNew && is_string($input['expected_version'] ?? null) &&
                preg_match('/\A[1-9][0-9]{0,9}\z/D', $input['expected_version'])) {
                $expectedVersion = (int) $input['expected_version'];
            }
        }
        $orders = $isNew ? $this->invoices->eligibleOrders($actor, ['page_size' => '100']) : ['items' => []];
        if (!$isNew) { $invoice = $this->lookups->withProductLabels($actor, $invoice); }
        return $this->page('form', $isNew ? 'New invoice from Order' : 'Edit draft invoice', $actor, [
            'invoice' => $invoice, 'isNew' => $isNew, 'expectedVersion' => $expectedVersion,
            'orders' => $orders['items'],
            'listUrl' => $this->paths->url(self::BASE),
            'formUrl' => $isNew ? $this->paths->url(self::BASE . '/new') : $this->invoiceUrl($number, '/edit'),
            'scriptUrl' => $this->paths->url('/assets/sales/invoices.js'),
            'actionReference' => $this->reference($input), 'error' => $error,
        ], $status);
    }

    private function actionPage(
        Actor $actor,
        string $number,
        string $action,
        string $method,
        array $input,
    ): array {
        $definition = self::ACTIONS[$action];
        $error = null;
        $status = 200;

        if ($method === 'POST') {
            try {
                // Call the service before reading current state, preserving retries
                // of successful actions, including deletion.
                $saved = $this->invoices->action(
                    $actor,
                    $number,
                    $definition['service'],
                    $this->payload($input, true),
                );

                return $this->redirect(
                    !empty($saved['deleted'])
                        ? $this->paths->url(self::BASE)
                        : $this->invoiceUrl($number),
                );
            } catch (AppError $exception) {
                if (!in_array($exception->status, [400, 409], true)) {
                    throw $exception;
                }

                $error = $exception->getMessage();
                $status = $exception->status;
            }
        }

        $invoice = $this->invoices->get($actor, $number);

        if (!in_array($definition['available'], $invoice['available_actions'], true)) {
            return $this->detail(
                $actor,
                $invoice,
                $error ?? 'This action is no longer available. Review the current invoice.',
                409,
            );
        }

        return $this->page('confirm', $definition['label'], $actor, [
            'invoice' => $invoice,
            'viewUrl' => $this->invoiceUrl($number),
            'actionUrl' => $this->invoiceUrl($number, '/' . $action),
            'actionTitle' => $definition['label'],
            'actionMessage' => $definition['message'],
            'submitLabel' => $definition['label'],
            'actionReference' => $this->reference($input),
            'error' => $error,
        ], $status);
    }

    private function detail(
        Actor $actor,
        array $invoice,
        ?string $error = null,
        int $status = 200,
    ): array {
        $invoice = $this->lookups->withProductLabels($actor, $invoice);
        $number = $invoice['invoice_number'];
        $available = $invoice['available_actions'];
        $actions = [];

        foreach (self::ACTIONS as $path => $definition) {
            if (in_array($definition['available'], $available, true)) {
                $actions[] = [
                    'url' => $this->invoiceUrl($number, '/' . $path),
                    'label' => $definition['label'],
                ];
            }
        }

        $terms = [
            'DUE_ON_RECEIPT' => 'Due on receipt',
            'NET_15' => 'Net 15 days',
            'NET_30' => 'Net 30 days',
        ];
        $invoiceQuery = '?' . http_build_query([
            'invoice_number' => $number,
        ]);
        return $this->page('detail', $number, $actor, [
            'invoice' => $invoice,
            'paymentTermsLabel' => $terms[$invoice['payment_terms']],
            'listUrl' => $this->paths->url(self::BASE),
            'editUrl' => in_array('edit', $available, true)
                ? $this->invoiceUrl($number, '/edit')
                : null,
            'actions' => $actions,
            'recordPaymentUrl' => in_array('record_payment', $available, true)
                ? $this->paths->url('/modules/sales/payments/new') . $invoiceQuery
                : null,
            'paymentHistoryUrl' => $this->can(
                $actor,
                SalesPermissions::PAYMENTS,
                'read',
            )
                ? $this->paths->url('/modules/sales/payments/history') . $invoiceQuery
                : null,
            'error' => $error,
        ], $status);
    }

    private function payload(array $input, bool $versionRequired): array
    {
        $payload = $input;
        unset($payload['csrf_token']);

        $reference = Api::string($payload, 'action_reference');

        if (!preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $reference)) {
            throw new AppError('VALIDATION_ERROR', 'Invalid request reference. Refresh and try again.');
        }

        if ($versionRequired) {
            $version = $payload['expected_version'] ?? null;

            if (!is_string($version) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $version)) {
                throw new AppError('VALIDATION_ERROR', 'Invalid invoice version. Refresh and try again.');
            }

            $payload['expected_version'] = (int) $version;
        }

        if (($payload['due_date'] ?? null) === '') { unset($payload['due_date']); }

        return $payload;
    }

    private function reference(array $input): string
    {
        $reference = $input['action_reference'] ?? null;
        return is_string($reference) && preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $reference)
            ? $reference : bin2hex(random_bytes(16));
    }

    private function can(Actor $actor, string $object, string $operation): bool
    {
        try {
            $this->authorization->requirePermission($actor, $object, $operation);
            return true;
        } catch (AppError $error) {
            if ($error->errorCode !== 'FORBIDDEN') {
                throw $error;
            }
            return false;
        }
    }

    private function invoiceUrl(string $number, string $suffix = ''): string
    {
        return $this->paths->url(
            self::BASE . '/' . rawurlencode($number) . $suffix,
        );
    }

    private function redirect(string $url): array
    {
        return ['status' => 303, 'headers' => ['Location' => $url], 'body' => ''];
    }

    private function page(
        string $view,
        string $title,
        Actor $actor,
        array $data,
        int $status = 200,
    ): array {
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'text/html; charset=UTF-8'],
            'body' => (new Renderer($this->paths))->render(
                'sales/invoices/' . $view . '.tpl',
                $title,
                $this->csrf,
                ['actor' => $actor, ...$data],
            ),
        ];
    }
}

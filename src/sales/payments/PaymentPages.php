<?php
declare(strict_types=1);

namespace MiniErp\sales\payments;

use MiniErp\sales\BusinessDate;
use MiniErp\sales\SalesPermissions;
use MiniErp\sales\invoices\InvoiceMoney;
use MiniErp\sales\invoices\InvoiceService;
use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\WebPath;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;
use MiniErp\shared\security\Security;
use MiniErp\shared\view\Renderer;

final readonly class PaymentPages
{
    private const BASE = '/modules/sales/payments';

    private PaymentService $payments;
    private InvoiceService $invoices;
    private Authorization $authorization;
    private \Closure $businessDate;

    public function __construct(
        Connection $connection,
        private WebPath $paths,
        private string $csrf,
        ?\Closure $businessDate = null,
    ) {
        $this->businessDate = $businessDate ?? BusinessDate::today(...);
        $this->payments = new PaymentService($connection, $this->businessDate);
        $this->invoices = new InvoiceService($connection, $this->businessDate);
        $this->authorization = new Authorization($connection);
    }

    public function canOpen(Actor $actor): bool
    {
        $access = $this->access($actor);
        return $access['canRead'] || $access['canRecord'];
    }

    public function handle(
        string $method,
        string $path,
        Actor $actor,
        array $input,
        array $query,
    ): array {
        if ($method === 'GET' && in_array($path, [
            self::BASE,
            self::BASE . '/history',
        ], true)) {
            return $this->history($actor, $query);
        }

        if (
            $path !== self::BASE . '/new'
            || !in_array($method, ['GET', 'POST'], true)
        ) {
            throw new AppError('NOT_FOUND', 'Payment page not found.', 404);
        }

        if ($method === 'POST') {
            Security::verifyCsrf(
                ['csrf_token' => $this->csrf],
                $input['csrf_token'] ?? null,
            );
        }

        $this->authorization->requirePermission(
            $actor,
            SalesPermissions::PAYMENTS,
            'record',
        );

        // The entry form displays the Invoice and its outstanding balance.
        $this->authorization->requirePermission(
            $actor,
            SalesPermissions::DOCUMENTS['invoice'],
            'read',
        );

        if ($method === 'GET' && ($query['invoice_number'] ?? '') === '') {
            return $this->history($actor, []);
        }

        try {
            $number = $this->number($query['invoice_number'] ?? null);
        } catch (AppError $error) {
            return $this->history(
                $actor,
                $query,
                $error->getMessage(),
                $error->status,
            );
        }

        $error = null;
        $status = 200;

        if ($method === 'POST') {
            try {
                // Preserve successful retries, including after the Invoice is Paid.
                // Do not check current Invoice eligibility before this call.
                $saved = $this->payments->record(
                    $actor,
                    $number,
                    $this->payload($input),
                );

                return $this->page('success', 'Payment recorded', $actor, [
                    'payment' => $saved['payment'],
                    'invoice' => $saved['invoice'],
                    'homeUrl' => $this->url(),
                    'invoiceUrl' => $this->invoiceUrl($number),
                    'historyUrl' => $this->can(
                        $actor,
                        SalesPermissions::PAYMENTS,
                        'read',
                    ) ? $this->url('/history', $number) : null,
                ], 201);
            } catch (AppError $exception) {
                if (!in_array($exception->status, [400, 409], true)) {
                    throw $exception;
                }

                $error = $exception->getMessage();
                $status = $exception->status;
            }
        }

        try {
            $invoice = $this->invoices->get($actor, $number);
        } catch (AppError $exception) {
            if ($exception->status !== 404) {
                throw $exception;
            }

            return $this->history(
                $actor,
                ['invoice_number' => $number],
                $exception->getMessage(),
                404,
            );
        }

        $today = ($this->businessDate)();
        $blockReason = null;

        if ($invoice['status'] !== 'Submitted') {
            $blockReason = 'Submit the Invoice before recording a payment.';
        } elseif (InvoiceMoney::cents($invoice['amount_due']) === 0) {
            $blockReason = 'This Invoice is fully paid.';
        } elseif ($invoice['invoice_date'] > $today) {
            $blockReason = 'Payments can be recorded on or after the Invoice date.';
        }

        $payment = [
            'payment_amount' => '',
            'payment_date' => $today,
            'payment_reference' => '',
        ];

        foreach ($payment as $field => $default) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $payment[$field] = $input[$field];
            }
        }

        return $this->page('form', 'Record payment', $actor, [
            'invoice' => $invoice,
            'payment' => $payment,
            'businessDate' => $today,
            'canRecord' => $blockReason === null,
            'blockReason' => $blockReason,
            'actionReference' => bin2hex(random_bytes(16)),
            'formUrl' => $this->url('/new', $number),
            'homeUrl' => $this->url(),
            'cancelUrl' => $this->invoiceUrl($number),
            'error' => $error,
        ], $status);
    }

    private function history(
        Actor $actor,
        array $query,
        ?string $error = null,
        int $status = 200,
    ): array {
        $access = $this->access($actor);

        if (!$access['canRead'] && !$access['canRecord']) {
            throw new AppError(
                'FORBIDDEN',
                'You do not have permission to open the Payments screens.',
                403,
            );
        }

        $number = is_string($query['invoice_number'] ?? null)
            ? $query['invoice_number']
            : '';

        $historyQuery = $query;
        unset($historyQuery['invoice_number']);
        $payments = null;

        if ($error === null) {
            try {
                Api::pagination($historyQuery);

                if (($query['invoice_number'] ?? '') !== '') {
                    $this->authorization->requirePermission(
                        $actor,
                        SalesPermissions::PAYMENTS,
                        'read',
                    );

                    $number = $this->number($query['invoice_number']);
                    $payments = $this->payments->list(
                        $actor,
                        $number,
                        $historyQuery,
                    );
                }
            } catch (AppError $exception) {
                if (!in_array($exception->status, [400, 404], true)) {
                    throw $exception;
                }

                $error = $exception->getMessage();
                $status = $exception->status;
            }
        }

        $pageUrl = fn (int $page): string => $this->url(
            '/history',
            $number,
            [...$historyQuery, 'page' => (string) $page],
        );

        return $this->page('history', 'Customer payments', $actor, [
            ...$access,
            'salesUrl' => $this->paths->url('/modules/sales'),
            'lookupUrl' => $this->url('/history'),
            'newUrl' => $this->url('/new'),
            'invoiceNumber' => $number,
            'payments' => $payments,
            'invoiceUrl' => $payments !== null
                && $this->can($actor, SalesPermissions::DOCUMENTS['invoice'], 'read')
                ? $this->invoiceUrl($number)
                : null,
            'previousUrl' => $payments !== null && $payments['page'] > 1
                ? $pageUrl($payments['page'] - 1)
                : null,
            'nextUrl' => $payments !== null
                && count($payments['items']) === $payments['page_size']
                && $payments['page'] < 1000000
                ? $pageUrl($payments['page'] + 1)
                : null,
            'error' => $error,
        ], $status);
    }

    private function payload(array $input): array
    {
        $payload = $input;
        unset($payload['csrf_token']);

        $reference = Api::string($payload, 'action_reference');

        if (!preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $reference)) {
            throw new AppError(
                'VALIDATION_ERROR',
                'Invalid request reference. Refresh and try again.',
            );
        }

        $version = $payload['expected_version'] ?? null;

        if (
            !is_string($version)
            || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $version)
        ) {
            throw new AppError(
                'VALIDATION_ERROR',
                'Invalid Invoice version. Refresh and try again.',
            );
        }

        $payload['expected_version'] = (int) $version;
        return $payload;
    }

    private function access(Actor $actor): array
    {
        return [
            'canRead' => $this->can($actor, SalesPermissions::PAYMENTS, 'read'),
            'canRecord' => $this->can($actor, SalesPermissions::PAYMENTS, 'record')
                && $this->can($actor, SalesPermissions::DOCUMENTS['invoice'], 'read'),
        ];
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

    private function url(
        string $suffix = '',
        ?string $number = null,
        array $query = [],
    ): string {
        if ($number !== null) {
            $query['invoice_number'] = $number;
        }

        $url = $this->paths->url(self::BASE . $suffix);
        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    private function invoiceUrl(string $number): string
    {
        return $this->paths->url(
            '/modules/sales/invoices/' . rawurlencode($number),
        );
    }

    private function number(mixed $value): string
    {
        if (
            !is_string($value)
            || !preg_match('/\A[A-Za-z0-9_-]{1,20}\z/D', trim($value))
        ) {
            throw new AppError('VALIDATION_ERROR', 'Enter a valid Invoice number.');
        }

        return trim($value);
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
                'sales/payments/' . $view . '.tpl',
                $title,
                $this->csrf,
                ['actor' => $actor, ...$data],
            ),
        ];
    }
}
<?php
declare(strict_types=1);

namespace MiniErp\sales\customers;

use MiniErp\sales\SalesPermissions;
use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\WebPath;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;
use MiniErp\shared\security\Security;
use MiniErp\shared\view\Renderer;

final readonly class CustomerPages
{
    private const BASE = '/modules/sales/customers';

    private const FIELDS = [
        ['customer_name', 'Customer name', 'text', 100, true],
        ['contact_name', 'Contact name', 'text', 100, false],
        ['email_address', 'Email address', 'email', 254, false],
        ['phone_number', 'Phone number', 'tel', 25, false],
        ['billing_address_line_1', 'Billing address line 1', 'text', 150, true],
        ['billing_address_line_2', 'Billing address line 2', 'text', 150, false],
        ['city', 'City', 'text', 80, true],
        ['state_province', 'State / Province', 'text', 80, false],
        ['postal_code', 'Postal code', 'text', 20, false],
        ['country', 'Country', 'text', 80, true],
    ];

    private CustomerService $customers;
    private Authorization $authorization;

    public function __construct(
        Connection $connection,
        private WebPath $paths,
        private string $csrf,
    ) {
        $this->customers = new CustomerService($connection);
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
            throw new AppError('NOT_FOUND', 'Customer page not found.', 404);
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

        $isNew = $path === self::BASE . '/new';
        $id = null;

        if (!$isNew) {
            if (!preg_match(
                '~^/modules/sales/customers/([1-9][0-9]{0,14})(/edit)?$~D',
                $path,
                $match,
            )) {
                throw new AppError('NOT_FOUND', 'Customer page not found.', 404);
            }

            $id = $match[1];

            if (empty($match[2])) {
                if ($method !== 'GET') {
                    throw new AppError('NOT_FOUND', 'Customer page not found.', 404);
                }

                return $this->detail(
                    $actor,
                    $this->customers->get($actor, $id),
                );
            }
        }

        $this->authorization->requirePermission(
            $actor,
            SalesPermissions::CUSTOMERS,
            $isNew ? 'create' : 'update',
        );

        $customer = $isNew
            ? $this->defaults()
            : $this->customers->get($actor, $id);

        $reference = bin2hex(random_bytes(16));
        $error = null;
        $status = 200;

        if ($method === 'POST') {
            $payload = $input;
            unset($payload['csrf_token']);

            try {
                $reference = Api::string($payload, 'action_reference');

                if (!preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $reference)) {
                    throw new AppError(
                        'VALIDATION_ERROR',
                        'Invalid request reference. Refresh the page and try again.',
                    );
                }

                $saved = $isNew
                    ? $this->customers->create($actor, $payload)
                    : $this->customers->update($actor, $id, $payload);

                $destination = $this->can($actor, 'read')
                    ? self::BASE . '/' . rawurlencode((string) $saved['id'])
                    : '/modules/sales';

                return [
                    'status' => 303,
                    'headers' => ['Location' => $this->paths->url($destination)],
                    'body' => '',
                ];
            } catch (AppError $exception) {
                if (!in_array($exception->status, [400, 409], true)) {
                    throw $exception;
                }

                $error = $exception->getMessage();
                $status = $exception->status;

                // Preserve editable text when validation fails.
                foreach (array_keys($this->defaults()) as $field) {
                    if (isset($payload[$field]) && is_string($payload[$field])) {
                        $customer[$field] = $payload[$field];
                    }
                }

                if (!preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $reference)) {
                    $reference = bin2hex(random_bytes(16));
                }
            }
        }

        return $this->page('form', $isNew ? 'New customer' : 'Edit customer', $actor, [
            'customer' => $customer,
            'fields' => $this->fields(),
            'formUrl' => $this->paths->url(
                $isNew ? self::BASE . '/new' : self::BASE . '/' . $id . '/edit',
            ),
            'listUrl' => $this->paths->url(
                $this->can($actor, 'read') ? self::BASE : '/modules/sales',
            ),
            'actionReference' => $reference,
            'error' => $error,
        ], $status);
    }

    private function listing(Actor $actor, array $query): array
    {
        foreach (['search', 'is_active'] as $filter) {
            if (($query[$filter] ?? null) === '') {
                unset($query[$filter]);
            }
        }

        $customers = $this->customers->list($actor, $query);
        $listUrl = $this->paths->url(self::BASE);

        foreach ($customers['items'] as &$customer) {
            $customer['viewUrl'] = $this->paths->url(
                self::BASE . '/' . rawurlencode((string) $customer['id']),
            );
        }
        unset($customer);

        $pageUrl = static fn (int $page): string =>
            $listUrl . '?' . http_build_query([...$query, 'page' => (string) $page]);

        return $this->page('list', 'Customers', $actor, [
            'customers' => $customers,
            'listUrl' => $listUrl,
            'salesUrl' => $this->paths->url('/modules/sales'),
            'createUrl' => $this->can($actor, 'create')
                ? $this->paths->url(self::BASE . '/new')
                : null,
            'filters' => [
                'search' => $query['search'] ?? '',
                'is_active' => $query['is_active'] ?? '',
            ],
            'previousUrl' => $customers['page'] > 1
                ? $pageUrl($customers['page'] - 1)
                : null,
            'nextUrl' => count($customers['items']) === $customers['page_size']
                && $customers['page'] < 1000000
                ? $pageUrl($customers['page'] + 1)
                : null,
        ]);
    }

    private function detail(Actor $actor, array $customer): array
    {
        $terms = [
            'DUE_ON_RECEIPT' => 'Due on receipt',
            'NET_15' => 'Net 15 days',
            'NET_30' => 'Net 30 days',
        ];

        return $this->page('detail', $customer['customer_name'], $actor, [
            'customer' => $customer,
            'fields' => $this->fields(),
            'paymentTermsLabel' => $terms[$customer['payment_terms']],
            'listUrl' => $this->paths->url(self::BASE),
            'editUrl' => $this->can($actor, 'update')
                ? $this->paths->url(self::BASE . '/' . $customer['id'] . '/edit')
                : null,
        ]);
    }

    private function defaults(): array
    {
        $customer = array_fill_keys(array_column(self::FIELDS, 0), '');

        return [
            ...$customer,
            'payment_terms' => 'NET_30',
            'is_active' => 'T',
            'notes' => '',
        ];
    }

    private function fields(): array
    {
        return array_map(static fn (array $field): array => [
            'name' => $field[0],
            'label' => $field[1],
            'type' => $field[2],
            'maxLength' => $field[3],
            'required' => $field[4],
        ], self::FIELDS);
    }

    private function can(Actor $actor, string $operation): bool
    {
        try {
            $this->authorization->requirePermission(
                $actor,
                SalesPermissions::CUSTOMERS,
                $operation,
            );
            return true;
        } catch (AppError $error) {
            if ($error->errorCode !== 'FORBIDDEN') {
                throw $error;
            }
            return false;
        }
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
                'sales/customers/' . $view . '.tpl',
                $title,
                $this->csrf,
                ['actor' => $actor, ...$data],
            ),
        ];
    }
}
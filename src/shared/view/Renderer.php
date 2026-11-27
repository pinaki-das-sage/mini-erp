<?php
declare(strict_types=1);

namespace MiniErp\shared\view;

use MiniErp\shared\WebPath;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Security;
use Smarty\Smarty;

final class Renderer
{
    private const MODULES = [
        'inventory' => 'Products, balances and reservations',
        'purchasing' => 'Suppliers, approvals and full receipts',
        'sales' => 'Customers, orders and fulfillment',
        'logistics' => 'Trips, dispatch and delivery',
    ];

    private Smarty $smarty;

    public function __construct(private readonly WebPath $paths)
    {
        $root = dirname(__DIR__, 3);
        $this->smarty = new Smarty();
        $this->smarty->setTemplateDir($root . '/views');
        $this->smarty->setCompileDir($root . '/var/smarty/compile');
        $this->smarty->setCacheDir($root . '/var/smarty/cache');
        $this->smarty->registerPlugin('modifier', 'escape_html', static fn (mixed $value): string => Security::escape((string) $value));
        $this->smarty->setDefaultModifiers(['escape_html']);
        $this->smarty->caching = Smarty::CACHING_OFF;
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, string $title, string $csrf, array $data = []): string
    {
        if (!preg_match('~\A(?:[a-z][a-z0-9_]*\/)+[a-z][a-z0-9_]*\.tpl\z~', $template)
            || !is_file(dirname(__DIR__, 3) . '/views/' . $template)) {
            throw new \InvalidArgumentException('Unknown view template.');
        }
        $actor = $data['actor'] ?? null;
        if ($actor !== null && !$actor instanceof Actor) {
            throw new \InvalidArgumentException('View actor must be an authenticated Actor.');
        }
        unset($data['actor']);
        if (isset($data['users'])) {
            $data['users'] = array_map($this->user(...), $data['users']);
        }
        if (isset($data['user'])) {
            $data['user'] = $this->user($data['user']);
        }
        $modules = [];
        foreach (self::MODULES as $code => $description) {
            $modules[] = ['name' => ucfirst($code), 'description' => $description,
                'url' => $this->paths->url('/modules/' . $code)];
        }
        $urls = [];
        foreach ([
            'home' => '/', 'login' => '/login', 'logout' => '/logout',
            'css' => '/assets/app.css', 'script' => '/assets/app.js',
            'example' => '/example', 'password' => '/account/password',
            'company' => '/settings/company', 'users' => '/settings/users',
            'createUser' => '/settings/users/new', 'userApi' => '/api/settings/users',
            'health' => '/health', 'ready' => '/ready',
        ] as $name => $route) {
            $urls[$name] = $this->paths->url($route);
        }
        $page = $this->smarty->createTemplate('shared/layout.tpl');
        $page->assign([
            'title' => $title, 'csrf' => $csrf, 'data' => $data,
            'basePath' => $this->paths->basePath, 'urls' => $urls, 'modules' => $modules,
            'loginPage' => $template === 'shared/security/login.tpl', 'contentTemplate' => $template,
            'actor' => $actor === null ? null : ['username' => $actor->username,
                'companyName' => $actor->companyName, 'isAdmin' => $actor->isAdmin],
            'permissionGroups' => $this->permissions($data),
        ]);
        return $page->fetch();
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function user(array $user): array
    {
        return [...$user,
            'displayName' => $user['DISPLAY_NAME'] ?? $user['USERNAME'],
            'isAdmin' => (int) $user['IS_ADMIN'] === 1,
            'active' => (int) $user['ACTIVE'] === 1,
            'version' => (int) $user['VERSION'],
            'editUrl' => $this->paths->url('/settings/users/' . rawurlencode($user['ID'])),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{name: string, objects: list<array{name: string, controls: list<array{value: string, label: string, checked: bool}>}>}>
     */
    private function permissions(array $data): array
    {
        $groups = [];
        $selected = array_fill_keys($data['grants'] ?? [], true);
        foreach ($data['objects'] ?? [] as $object) {
            $controls = [];
            foreach (['create', 'read', 'update', 'delete'] as $operation) {
                if ((int) $object['ALLOW_' . strtoupper($operation)] === 1) {
                    $value = $object['CODE'] . ':' . $operation;
                    $controls[] = ['value' => $value, 'label' => ucfirst($operation), 'checked' => isset($selected[$value])];
                }
            }
            foreach ($data['actions'] ?? [] as $action) {
                if ($action['OBJECT_CODE'] === $object['CODE']) {
                    $value = $object['CODE'] . ':' . $action['ACTION_CODE'];
                    $controls[] = ['value' => $value, 'label' => $action['NAME'], 'checked' => isset($selected[$value])];
                }
            }
            $module = $object['MODULE_CODE'];
            if (!isset($groups[$module])) {
                $groups[$module] = ['name' => ucfirst($module), 'objects' => []];
            }
            $groups[$module]['objects'][] = ['name' => $object['NAME'], 'controls' => $controls];
        }
        return array_values($groups);
    }
}

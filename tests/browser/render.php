<?php
declare(strict_types=1);

use MiniErp\shared\security\Actor;
use MiniErp\shared\WebPath;
use MiniErp\shared\view\Renderer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$template = match ($argv[1] ?? '') {
    'user-new' => 'shared/settings/users/create.tpl',
    'user-edit' => 'shared/settings/users/edit.tpl',
    'users' => 'shared/settings/users/list.tpl',
    'example' => 'shared/example/note.tpl',
    'lead-new' => 'sales/leads/form.tpl',
    'lead-edit' => 'sales/leads/form.tpl',
    'lead-detail' => 'sales/leads/detail.tpl',
    default => throw new InvalidArgumentException('Unsupported browser fixture view.'),
};
$title = 'User management';
$csrf = 'browser-fixture-token';
$paths = new WebPath($argv[2] ?? '');
$user = ['ID' => 'u', 'USERNAME' => 'graduate', 'DISPLAY_NAME' => 'Graduate',
    'IS_ADMIN' => 0, 'ACTIVE' => 1, 'VERSION' => 3];
$data = [
    'actor' => new Actor('a', 'admin', [], 1, true),
    'user' => $user,
    'users' => [$user, ['ID' => 'inactive', 'USERNAME' => 'inactive', 'DISPLAY_NAME' => 'Inactive graduate',
        'IS_ADMIN' => 0, 'ACTIVE' => 0, 'VERSION' => 1]],
    'objects' => [['CODE' => 'inventory.products', 'NAME' => 'Products', 'MODULE_CODE' => 'inventory',
        'ALLOW_CREATE' => 1, 'ALLOW_READ' => 1, 'ALLOW_UPDATE' => 0, 'ALLOW_DELETE' => 0]],
    'actions' => [['OBJECT_CODE' => 'inventory.products', 'ACTION_CODE' => 'post', 'NAME' => 'Post']],
    'grants' => ['inventory.products:read'],
    'note' => ['text' => 'Initial note', 'current_version' => 1],
];
if (str_starts_with($argv[1], 'lead-')) {
    $editing = $argv[1] === 'lead-edit';
    $data += [
        'lead' => ['id' => str_repeat('a', 32), 'lead_reference' => 'LD-TEST', 'contact_name' => 'Alex Smith',
            'business_name' => 'Example', 'email' => 'alex@example.test', 'phone' => '', 'source' => 'website',
            'status' => 'new', 'notes' => '', 'archived' => false, 'current_version' => 2,
            'created_at' => '2026-11-17T00:00:00Z', 'updated_at' => '2026-11-17T00:00:00Z', 'created_by' => 'a', 'updated_by' => 'a'],
        'leadPermissions' => ['read' => true, 'create' => true, 'update' => true, 'archive' => true, 'restore' => true],
        'operation' => $editing ? 'update' : 'create', 'actionReference' => bin2hex(random_bytes(16)),
        'sources' => \MiniErp\sales\LeadService::SOURCES, 'statuses' => \MiniErp\sales\LeadService::STATUSES,
        'detailUrl' => $paths->url('/sales/leads/' . str_repeat('a', 32)),
        'editUrl' => $paths->url('/sales/leads/' . str_repeat('a', 32) . '/edit'),
    ];
}
echo (new Renderer($paths))->render($template, $title, $csrf, $data);

<?php
declare(strict_types=1);

use MiniErp\shared\security\Actor;
use MiniErp\shared\WebPath;
use MiniErp\shared\view\Renderer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$template = match ($argv[1] ?? '') {
    'purchasing' => 'purchasing/index.tpl',
    'user-new' => 'shared/settings/users/create.tpl',
    'user-edit' => 'shared/settings/users/edit.tpl',
    'users' => 'shared/settings/users/list.tpl',
    'example' => 'shared/example/note.tpl',
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
echo (new Renderer($paths))->render($template, $title, $csrf, $data);

<?php
declare(strict_types=1);

$base = getenv('BROWSER_TEST_BASE_PATH');
if ($base === false || $base === '') {
    throw new RuntimeException('Start this test router through Playwright with BROWSER_TEST_BASE_PATH configured.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
foreach (['app.css' => 'text/css', 'app.js' => 'application/javascript'] as $asset => $type) {
    if ($path === $base . '/assets/' . $asset) {
        header('Content-Type: ' . $type);
        readfile(dirname(__DIR__, 2) . '/public/assets/' . $asset);
        return;
    }
}
$_SERVER['SCRIPT_NAME'] = $base . '/index.php';
require dirname(__DIR__, 2) . '/public/index.php';

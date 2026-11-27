<?php
declare(strict_types=1);

use MiniErp\shared\AppError;
use MiniErp\shared\Kernel;

ini_set('display_errors', '0');
set_exception_handler(static function (Throwable $error): void {
    $reference = bin2hex(random_bytes(8));
    error_log('mini-erp unhandled_error request=' . $reference . ' type=' . get_class($error));
    http_response_code($error instanceof AppError ? $error->status : 500);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(['success' => false, 'operation_id' => null, 'current_version' => null, 'result' => null, 'error' => [
        'code' => $error instanceof AppError ? $error->errorCode : 'INTERNAL_ERROR',
        'message' => $error instanceof AppError ? $error->getMessage() : 'Unexpected error. Contact the lab maintainer.',
        'request_id' => $reference,
    ]], JSON_THROW_ON_ERROR);
});

$config = require dirname(__DIR__) . '/bootstrap.php';
if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4) {
    throw new AppError('CONFIGURATION_ERROR', 'Use PHP 8.4 for this training application.', 503);
}
session_name('mini_erp_session');
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$paths = $config->webPath($scriptName);
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$routePath = $paths->routePath($requestPath);
$browserSession = $routePath !== '/api/v1' && !str_starts_with($routePath, '/api/v1/');
$session = [];
if ($browserSession) {
    session_start([
        'use_strict_mode' => true,
        'cookie_httponly' => true,
        'cookie_secure' => $config->environment === 'production',
        'cookie_samesite' => 'Lax',
        'cookie_path' => $paths->url('/'),
    ]);
    $session = &$_SESSION;
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('Cache-Control: no-store');

$input = $_POST;
if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) === 'application/json') {
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if ($raw === false || strlen($raw) > 8192) {
        throw new AppError('VALIDATION_ERROR', 'Request body exceeds the allowed size.');
    }
    try {
        $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new AppError('VALIDATION_ERROR', 'Request body must be valid JSON.');
    }
    if (!$decoded instanceof stdClass) {
        throw new AppError('VALIDATION_ERROR', 'Request body must be a JSON object.');
    }
    $input = get_object_vars($decoded);
}
$headers = [];
foreach (getallheaders() as $name => $value) {
    $headers[strtolower($name)] = $value;
}
$previousActor = $session['actor_id'] ?? null;
$response = (new Kernel($config, $session, null, $scriptName, $_SERVER['REMOTE_ADDR'] ?? 'unknown'))->handle(
    $_SERVER['REQUEST_METHOD'], $requestPath, $input, $headers, $_GET,
);
if ($browserSession && $previousActor !== ($session['actor_id'] ?? null)) {
    session_regenerate_id(true);
}
http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $response['body'];

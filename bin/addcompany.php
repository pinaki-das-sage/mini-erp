<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Company creation is CLI-only.');
}

if (count($argv) !== 2 || trim($argv[1]) === '') {
    fwrite(STDERR, "Usage: php bin/addcompany.php <NEW_COMPANY_NAME>\nQuote names containing spaces.\n");
    exit(1);
}

$name = trim($argv[1]);
$code = preg_replace('/\s+/', '-', strtoupper($name));
if ($code === null) {
    fwrite(STDERR, "Could not derive a company identifier.\n");
    exit(1);
}
$argv = [__DIR__ . '/console.php', 'company:create', $code, $name];
require __DIR__ . '/console.php';

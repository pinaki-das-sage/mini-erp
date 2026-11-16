<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$entries = scandir($root);
if ($entries === false) {
    throw new RuntimeException('Cannot enumerate project files.');
}
$files = [];
foreach ($entries as $path) {
    if ($path === '.' || $path === '..') {
        continue;
    }
    $fullPath = $root . '/' . $path;
    if (is_file($fullPath)) {
        if (pathinfo($fullPath, PATHINFO_EXTENSION) === 'php') {
            $files[] = $fullPath;
        }
        continue;
    }
    if ($path !== strtolower($path)) {
        fwrite(STDERR, 'Project folders must be lowercase: ' . $fullPath . "\n");
        exit(1);
    }
    if (in_array($path, ['vendor', 'node_modules', 'var', '.git', '.phpunit.cache', 'test-results', 'playwright-report'], true)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fullPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $file) {
        if ($file->isDir() && $file->getFilename() !== strtolower($file->getFilename())) {
            fwrite(STDERR, 'Project folders must be lowercase: ' . $file->getPathname() . "\n");
            exit(1);
        }
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
foreach ($files as $file) {
    $process = proc_open([PHP_BINARY, '-l', $file], [1 => STDOUT, 2 => STDERR], $pipes);
    if ($process === false || proc_close($process) !== 0) {
        exit(1);
    }
}
echo "PHP syntax checks passed.\n";

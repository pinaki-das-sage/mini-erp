<?php
declare(strict_types=1);

namespace MiniErp\Tests\apache;

use PHPUnit\Framework\TestCase;

final class ApacheRoutingTest extends TestCase
{
    public function testPortableRoutingAndPrivateCheckoutProtection(): void
    {
        $binary = getenv('APACHE_HTTPD');
        $modules = getenv('APACHE_MODULE_DIR');
        if (!$binary || !$modules) {
            self::markTestSkipped('Set APACHE_HTTPD and APACHE_MODULE_DIR to run the isolated Apache test.');
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Run the isolated Apache test as an unprivileged user.');
        }
        self::assertFileExists($binary);
        self::assertDirectoryExists($modules);
        self::assertFileExists(dirname(__DIR__, 2) . '/.htaccess');
        self::assertFileExists(dirname(__DIR__, 2) . '/public/.htaccess');

        $directory = sys_get_temp_dir() . '/mini-erp-apache-' . bin2hex(random_bytes(8));
        $checkout = $directory . '/renamed-project';
        $public = $checkout . '/public';
        $files = [
            $checkout . '/.htaccess', $public . '/.htaccess', $public . '/index.php',
            $public . '/assets/example.css', $checkout . '/config.ini',
            $checkout . '/.env', $checkout . '/src/private.php',
            $checkout . '/.git/config', $directory . '/httpd.conf',
            $directory . '/httpd.pid', $directory . '/error.log', $directory . '/process.log',
        ];
        $directories = [$directory, $checkout, $public, $public . '/assets', $checkout . '/src', $checkout . '/.git'];
        $process = null;
        try {
            foreach ($directories as $path) {
                self::assertTrue(mkdir($path, 0700));
            }
            self::assertTrue(copy(dirname(__DIR__, 2) . '/.htaccess', $checkout . '/.htaccess'));
            self::assertTrue(copy(dirname(__DIR__, 2) . '/public/.htaccess', $public . '/.htaccess'));
            file_put_contents($public . '/index.php', <<<'CGI'
                #!/bin/sh
                body=$(cat)
                printf 'Content-Type: application/json\r\n\r\n'
                printf '{"script_name":"%s","method":"%s","query_string":"%s","body":"%s"}' "$SCRIPT_NAME" "$REQUEST_METHOD" "$QUERY_STRING" "$body"
                CGI);
            self::assertTrue(chmod($public . '/index.php', 0700));
            file_put_contents($public . '/assets/example.css', 'static-asset-marker');
            foreach ([$checkout . '/config.ini', $checkout . '/.env', $checkout . '/src/private.php', $checkout . '/.git/config'] as $path) {
                file_put_contents($path, 'private-marker');
            }

            $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
            self::assertIsResource($socket, $errorMessage);
            $address = stream_socket_get_name($socket, false);
            self::assertNotFalse($address);
            fclose($socket);
            $port = substr($address, strrpos($address, ':') + 1);
            $loads = '';
            foreach (['mpm_prefork', 'unixd', 'authz_core', 'authz_host', 'alias', 'dir', 'rewrite', 'autoindex', 'cgi'] as $module) {
                $loads .= "LoadModule {$module}_module \"$modules/mod_$module.so\"\n";
            }
            $configuration = <<<APACHE
                ServerRoot "$directory"
                PidFile "$directory/httpd.pid"
                Listen 127.0.0.1:$port
                ServerName localhost
                ErrorLog "$directory/error.log"
                $loads
                DocumentRoot "$public"
                Alias "/users/graduate/training-app/public" "$public"
                Alias "/deep/another-name" "$public"
                Alias "/checkout" "$checkout"
                <Directory "$directory">
                    Require all granted
                    AllowOverride AuthConfig FileInfo Indexes Options=Indexes
                    Options SymLinksIfOwnerMatch ExecCGI
                </Directory>
                <Files "index.php">
                    SetHandler cgi-script
                </Files>
                <FilesMatch "^\\.ht">
                    Require all denied
                </FilesMatch>
                APACHE;
            file_put_contents($directory . '/httpd.conf', $configuration);
            $process = proc_open([$binary, '-X', '-f', $directory . '/httpd.conf'],
                [1 => ['file', $directory . '/process.log', 'a'], 2 => ['file', $directory . '/process.log', 'a']], $pipes);
            self::assertIsResource($process);

            $ready = false;
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline) {
                $connection = @stream_socket_client("tcp://$address", $errorCode, $errorMessage, 0.1);
                if (is_resource($connection)) {
                    fclose($connection);
                    $ready = true;
                    break;
                }
                if (!proc_get_status($process)['running']) {
                    break;
                }
                usleep(50000);
            }
            self::assertTrue($ready, file_get_contents($directory . '/process.log'));
            self::assertFileExists($directory . '/httpd.pid');
            $status = proc_get_status($process);
            self::assertTrue($status['running']);
            self::assertSame($status['pid'], (int) trim(file_get_contents($directory . '/httpd.pid')));

            $requests = [];
            foreach (['', '/users/graduate/training-app/public', '/deep/another-name', '/checkout/public'] as $prefix) {
                foreach (['/', '/login', '/settings/users', '/api/note?value=1'] as $route) {
                    $requests[] = [$prefix . $route, 200, $prefix, 'GET'];
                }
                $requests[] = [$prefix . '/login', 200, $prefix, 'POST'];
                $requests[] = [$prefix . '/api/note?value=1', 200, $prefix, 'POST'];
                $requests[] = [$prefix . '/assets/example.css', 200, 'static-asset-marker', 'GET'];
                $requests[] = [$prefix . '/assets/', 403, null, 'GET'];
                $requests[] = [$prefix . '/.htaccess', 403, null, 'GET'];
            }
            foreach (['/config.ini', '/.env', '/src/private.php', '/.git/config', '/.htaccess'] as $path) {
                $requests[] = ['/checkout' . $path, 403, null, 'GET'];
            }
            foreach ($requests as [$path, $status, $expected, $method]) {
                $body = file_get_contents("http://$address$path", false, stream_context_create([
                    'http' => [
                        'method' => $method, 'ignore_errors' => true, 'timeout' => 2,
                        'header' => 'Content-Type: application/x-www-form-urlencoded',
                        'content' => $method === 'POST' ? 'fixture=payload' : '',
                    ],
                ]));
                self::assertNotFalse($body, $path);
                self::assertMatchesRegularExpression("/^HTTP\\/1\\.[01] $status /", $http_response_header[0], $path);
                if ($expected === 'static-asset-marker') {
                    self::assertSame($expected, $body, $path);
                } elseif ($expected !== null) {
                    $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    self::assertIsArray($response, $path);
                    self::assertSame($expected . '/index.php', $response['script_name'], $path);
                    self::assertSame($method, $response['method'], $path);
                    self::assertSame(parse_url($path, PHP_URL_QUERY) ?? '', $response['query_string'], $path);
                    self::assertSame($method === 'POST' ? 'fixture=payload' : '', $response['body'], $path);
                } else {
                    self::assertStringNotContainsString('private-marker', $body, $path);
                }
            }
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach ($files as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            foreach (array_reverse($directories) as $path) {
                if (is_dir($path)) {
                    rmdir($path);
                }
            }
        }
    }
}

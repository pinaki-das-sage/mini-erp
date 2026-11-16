<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\shared\AppError;
use MiniErp\shared\Config;
use MiniErp\shared\WebPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebPathTest extends TestCase
{
    public function testDetectsApacheScriptDirectoryAndPreservesRootDeployment(): void
    {
        $paths = WebPath::fromScriptName('/apps/training/public/index.php');
        self::assertSame('/apps/training/public/login', $paths->url('/login'));
        self::assertSame('/login', WebPath::fromScriptName('/index.php')->url('/login'));
        self::assertSame('/health', WebPath::fromScriptName('/health')->url('/health'));
    }

    public function testAutomaticPathsDoNotDependOnUsernameOrCheckoutName(): void
    {
        foreach (['', '/users/graduate/training-app/public', '/deep/another-name', '/checkout/public'] as $prefix) {
            $paths = Config::fromArray([])->webPath($prefix . '/index.php');
            self::assertSame($prefix . '/assets/app.js', $paths->url('/assets/app.js'));
            self::assertSame($prefix . '/settings/users', $paths->url('/settings/users'));
            self::assertSame('/api/note', $paths->routePath($prefix . '/api/note'));
        }
    }

    public function testNormalizesBaseAndKeepsQueryInGeneratedUrls(): void
    {
        $paths = new WebPath('/apps/training/public/');
        self::assertSame('/apps/training/public/', $paths->url('/'));
        self::assertSame('/apps/training/public/login?password_changed=1', $paths->url('/login?password_changed=1'));
        self::assertSame('/login', (new WebPath('/'))->url('/login'));
    }

    public function testStripsOnlyTheDeploymentPrefix(): void
    {
        $paths = new WebPath('/apps/training/public');
        self::assertSame('/', $paths->routePath('/apps/training/public/'));
        self::assertSame('/', $paths->routePath('/apps/training/public'));
        self::assertSame('/', $paths->routePath('/apps/training/public/index.php'));
        self::assertSame('/api/note', $paths->routePath('/apps/training/public/api/note'));
        $this->expectException(AppError::class);
        $paths->routePath('/apps/training/public-other/login');
    }

    #[DataProvider('invalidBases')]
    public function testRejectsInvalidConfiguredBase(string $base): void
    {
        $this->expectException(AppError::class);
        new WebPath($base);
    }

    public static function invalidBases(): array
    {
        return [['https://example.com'], ['//example.com'], ['/app?query=1'], ["/app\nheader"], ['/app/../other']];
    }

    public function testUrlCannotTargetAnotherOrigin(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WebPath('/app'))->url('//example.com/login');
    }

    public function testConfigSupportsAutomaticAndExplicitBasePaths(): void
    {
        self::assertSame('/auto/login', Config::fromArray([])->webPath('/auto/index.php')->url('/login'));
        self::assertSame('/configured/login', Config::fromArray(['APP_BASE_PATH' => '/configured'])->webPath('/auto/index.php')->url('/login'));
        self::assertSame('/login', Config::fromArray(['APP_BASE_PATH' => '/'])->webPath('/auto/index.php')->url('/login'));
    }

    public function testConfigRejectsNonStringBasePath(): void
    {
        $this->expectException(AppError::class);
        Config::fromArray(['APP_BASE_PATH' => 42])->webPath('/index.php');
    }
}

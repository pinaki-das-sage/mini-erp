<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\shared\WebPath;
use MiniErp\shared\security\Actor;
use MiniErp\shared\view\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    public function testLoginEscapesValuesAndUsesSharedMountedLayout(): void
    {
        $renderer = new Renderer(new WebPath('/training/public'));
        $html = $renderer->render('shared/security/login.tpl', '<script>Title</script>', 'token"&', [
            'error' => '<img src=x onerror=alert(1)>',
        ]);
        self::assertStringContainsString('&lt;script&gt;Title&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringContainsString('value="token&quot;&amp;"', $html);
        self::assertStringContainsString('action="/training/public/login"', $html);
        self::assertStringContainsString('href="/training/public/assets/app.css"', $html);
        self::assertStringContainsString('src="/training/public/assets/app.js"', $html);
        self::assertStringContainsString('<header>', $html);
        self::assertStringContainsString('<footer>', $html);
        self::assertStringNotContainsString('Main navigation', $html);
    }

    public function testListerPreparesDisplayValuesAndEncodedUserLinks(): void
    {
        $renderer = new Renderer(new WebPath('/training'));
        $html = $renderer->render('shared/settings/users/list.tpl', 'Users', 'csrf', [
            'actor' => new Actor('admin', 'admin', [], 1, true),
            'users' => [['ID' => 'a/b"', 'USERNAME' => 'graduate', 'DISPLAY_NAME' => null,
                'IS_ADMIN' => '0', 'ACTIVE' => '0', 'VERSION' => '2']],
        ]);
        self::assertStringContainsString('id="users-table"', $html);
        self::assertStringContainsString('data-active="0"', $html);
        self::assertStringContainsString('<td>graduate</td>', $html);
        self::assertStringContainsString('<td>Non-admin</td>', $html);
        self::assertStringContainsString('<td>Inactive</td>', $html);
        self::assertStringContainsString('href="/training/settings/users/a%2Fb%22"', $html);
        self::assertStringContainsString('action="/training/logout"', $html);
    }

    public function testPermissionPresentationPreservesSupportedAndSelectedControls(): void
    {
        $renderer = new Renderer(new WebPath('/training'));
        $html = $renderer->render('shared/settings/users/edit.tpl', 'Edit user', 'csrf', [
            'actor' => new Actor('admin', 'admin', [], 1, true),
            'user' => ['ID' => 'u', 'USERNAME' => 'graduate', 'DISPLAY_NAME' => '<Graduate>',
                'IS_ADMIN' => '0', 'ACTIVE' => '1', 'VERSION' => '3'],
            'objects' => [['CODE' => 'inventory.products', 'NAME' => '<Products>', 'MODULE_CODE' => 'inventory',
                'ALLOW_CREATE' => 1, 'ALLOW_READ' => 1, 'ALLOW_UPDATE' => 0, 'ALLOW_DELETE' => 0]],
            'actions' => [['OBJECT_CODE' => 'inventory.products', 'ACTION_CODE' => 'post', 'NAME' => '<Post>'],
                ['OBJECT_CODE' => 'other.items', 'ACTION_CODE' => 'close', 'NAME' => 'Close']],
            'grants' => ['inventory.products:read', 'inventory.products:post'],
        ]);
        self::assertStringContainsString('<h3>Inventory</h3>', $html);
        self::assertStringContainsString('&lt;Products&gt;', $html);
        self::assertStringContainsString('&lt;Post&gt;', $html);
        self::assertStringContainsString('value="inventory.products:read" checked', $html);
        self::assertStringContainsString('value="inventory.products:post" checked', $html);
        self::assertStringNotContainsString('value="inventory.products:delete"', $html);
        self::assertStringNotContainsString('other.items:close', $html);
        self::assertStringContainsString('data-operation="reset_password"', $html);
        self::assertStringContainsString('data-version="3"', $html);
    }

    public function testRenderingDoesNotReuseActorDataOrCsrfFromAnEarlierPage(): void
    {
        $renderer = new Renderer(new WebPath(''));
        $renderer->render('shared/home.tpl', 'Home', 'first-token', [
            'actor' => new Actor('admin', 'private-admin', [], 2, true),
        ]);
        $html = $renderer->render('shared/security/login.tpl', 'Login', 'second-token');
        self::assertStringNotContainsString('private-admin', $html);
        self::assertStringNotContainsString('first-token', $html);
        self::assertStringNotContainsString('Sign out', $html);
        self::assertStringContainsString('second-token', $html);
    }

    public function testHtmlEscapingPreservesInvalidUtf8Substitution(): void
    {
        $renderer = new Renderer(new WebPath(''));
        $html = $renderer->render('shared/security/login.tpl', "Title \xff <unsafe>", 'csrf');
        self::assertStringContainsString("<title>Title \xef\xbf\xbd &lt;unsafe&gt;</title>", $html);
    }

    public function testOtherPagesPreserveFormsAndEscapeCompanyAndNoteContent(): void
    {
        $renderer = new Renderer(new WebPath('/training'));
        $actor = new Actor('admin', 'admin', [], 1, true);
        $password = $renderer->render('shared/security/password.tpl', 'Change password', 'csrf', ['actor' => $actor]);
        self::assertStringContainsString('action="/training/account/password"', $password);
        self::assertStringContainsString('name="new_password"', $password);
        $company = $renderer->render('shared/settings/company.tpl', 'Company', 'csrf', [
            'actor' => $actor, 'company' => ['CODE' => 'COMPANY', 'NAME' => '<Company>'],
        ]);
        self::assertStringContainsString('&lt;Company&gt;', $company);
        $note = $renderer->render('shared/example/note.tpl', 'Note', 'csrf', [
            'actor' => $actor, 'note' => ['text' => '</textarea><script>unsafe</script>', 'current_version' => 2],
        ]);
        self::assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;unsafe&lt;/script&gt;', $note);
        self::assertStringContainsString('id="note-form" data-version="2"', $note);
        $admin = $renderer->render('shared/settings/users/edit.tpl', 'Edit admin', 'csrf', [
            'actor' => $actor, 'user' => ['ID' => 'a', 'USERNAME' => 'admin', 'IS_ADMIN' => 1, 'ACTIVE' => 1, 'VERSION' => 1],
        ]);
        self::assertStringContainsString('All registered permissions.', $admin);
        self::assertStringNotContainsString('data-operation="permissions"', $admin);
        foreach (['inventory', 'purchasing', 'sales', 'logistics'] as $module) {
            $html = $renderer->render($module . '/index.tpl', 'Module', 'csrf', ['actor' => $actor, 'module' => $module]);
            self::assertStringContainsString('This is the ' . $module . ' team', $html);
        }
    }

    public function testTemplateTraversalAndMissingTemplatesFailExplicitly(): void
    {
        $renderer = new Renderer(new WebPath(''));
        foreach (['../config.ini', '/shared/security/login.tpl', 'string:<script>', 'missing/page.tpl'] as $template) {
            try {
                $renderer->render($template, 'Invalid', 'csrf');
                self::fail('An invalid template must not render.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Unknown view template.', $error->getMessage());
            }
        }
    }
}

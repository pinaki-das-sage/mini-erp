<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\shared\security\Security;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Smarty\Smarty;

final class StatusBadgeTest extends TestCase
{
    private function render(?string $status, string $kind): string
    {
        $root = dirname(__DIR__, 3);
        $smarty = new Smarty();
        $smarty->setTemplateDir($root . '/views');
        $smarty->setCompileDir($root . '/var/smarty/compile');
        $smarty->registerPlugin('modifier', 'escape_html', static fn (mixed $value): string => Security::escape((string) $value));
        $smarty->setDefaultModifiers(['escape_html']);
        $smarty->assign(['badgeStatus' => $status, 'badgeKind' => $kind]);
        return $smarty->fetch('sales/partials/status_badge.tpl');
    }

    #[DataProvider('statuses')]
    public function testStatusHasTheExpectedToneAndVisibleLabel(string $kind, string $status, string $tone, string $label): void
    {
        $html = $this->render($status, $kind);
        self::assertStringContainsString('class="sales-status sales-status--' . $tone . '"', $html);
        self::assertStringContainsString('>' . $label . '</span>', $html);
    }

    public static function statuses(): array
    {
        return [
            ['customer', 'T', 'success', 'Active'],
            ['customer', 'F', 'neutral', 'Inactive'],
            ['quote', 'Draft', 'neutral', 'Draft'],
            ['quote', 'Submitted', 'success', 'Submitted'],
            ['order', 'Draft', 'neutral', 'Draft'],
            ['order', 'Submitted', 'info', 'Submitted'],
            ['order', 'In Progress', 'warning', 'In Progress'],
            ['order', 'Closed', 'success', 'Closed'],
            ['invoice', 'Draft', 'neutral', 'Draft'],
            ['invoice', 'Submitted', 'success', 'Submitted'],
            ['payment', 'Unpaid', 'danger', 'Unpaid'],
            ['payment', 'Partially Paid', 'warning', 'Partially Paid'],
            ['payment', 'Paid', 'success', 'Paid'],
        ];
    }

    public function testMissingStatusRemainsADashAndUnknownTextIsEscaped(): void
    {
        foreach ([null, '', '-'] as $status) {
            $html = $this->render($status, 'payment');
            self::assertStringContainsString('&mdash;', $html);
            self::assertStringNotContainsString('sales-status', $html);
        }
        $html = $this->render('<script>alert(1)</script>', 'payment');
        self::assertStringContainsString('sales-status--neutral', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }
}

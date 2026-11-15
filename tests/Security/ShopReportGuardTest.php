<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Admin pages the menu hides from a role must refuse that role when the address is typed in.
 * These checked only that someone was logged in: an author could open Месечен отчет and
 * download the order and donor CSVs, or print a courier label with a customer's address
 * (found on a staging copy, 2026-11-15), and a shop manager could run the article editor's
 * AI keyword suggestions.
 */
#[Group('admin')]
final class ShopReportGuardTest extends TestCase
{
    public static function refused(): array
    {
        return [
            'author opens the monthly report'        => ['admin/monthly-report.php', 'author', 'GET', []],
            'author downloads the monthly report'    => ['admin/monthly-report.php', 'author', 'POST', ['year' => '2026', 'month' => '10']],
            'author downloads a site report'         => ['admin/monthly-report.php', 'author', 'POST', ['year' => '2026', 'month' => '10', 'report' => 'x']],
            'author prints a BoxNow label'           => ['admin/boxnow-label.php', 'author', 'GET', ['order_id' => '1']],
            'author prints a Speedy label'           => ['admin/speedy-label.php', 'author', 'GET', ['order_id' => '1']],
            'shop manager asks for keywords'         => ['admin/suggest-keywords-ajax.php', 'shop_admin', 'POST', []],
        ];
    }

    #[DataProvider('refused')]
    public function test_role_the_menu_hides_the_page_from_is_refused(string $rel, string $role, string $method, array $data): void
    {
        $r = run_admin_page($rel, $data, ['role' => $role, 'method' => $method]);
        $this->assertSame(403, $r['status']);
    }

    public static function allowed(): array
    {
        return [
            'admin opens the monthly report'        => ['admin/monthly-report.php', 'admin'],
            'shop manager opens the monthly report' => ['admin/monthly-report.php', 'shop_admin'],
            'shop manager asks for a BoxNow label'  => ['admin/boxnow-label.php', 'shop_admin'],
            'shop manager asks for a Speedy label'  => ['admin/speedy-label.php', 'shop_admin'],
            'author asks for keywords'              => ['admin/suggest-keywords-ajax.php', 'author'],
        ];
    }

    #[DataProvider('allowed')]
    public function test_role_the_menu_shows_the_page_to_gets_past_the_check(string $rel, string $role): void
    {
        $r = run_admin_page($rel, [], ['role' => $role, 'method' => 'GET']);
        $this->assertNotSame(403, $r['status']);
        $this->assertStringNotContainsString('Access denied', $r['body']);
    }
}

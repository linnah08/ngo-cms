<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A site's own monthly report files (includes/site_extensions.php). */
final class SiteReportsTest extends TestCase
{
    private static array $tmp = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/site_extensions.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_site_extensions_file']);
        foreach (self::$tmp as $f) @unlink($f);
        self::$tmp = [];
    }

    private function site(string $php): void
    {
        $f = tempnam(sys_get_temp_dir(), 'ext') . '.php';
        file_put_contents($f, $php);
        self::$tmp[] = $f;
        $GLOBALS['_om_site_extensions_file'] = $f;
    }

    public function test_a_site_report_builds_its_file_and_email_line(): void
    {
        $this->site('<?php return ["reports" => [
            "partner"  => ["label" => "Отчет за партньора", "filename" => "partner-%04d-%02d.csv",
                           "csv" => fn(int $y, int $m) => "a,b\n$y,$m\n", "summary" => fn(int $y, int $m) => "Дължимо: 12,50 €"],
            "broken"   => ["label" => "Счупен", "filename" => "broken-%04d-%02d.csv", "csv" => function () { throw new Exception("boom"); }],
            "bad_name" => ["label" => "X", "filename" => "../../etc/passwd", "csv" => fn() => ""],
            "no_csv"   => ["label" => "X", "filename" => "x.csv"],
        ]];');
        $this->assertSame(['partner', 'broken'], array_keys(site_reports()));
        $this->assertSame(['name' => 'partner-2026-03.csv', 'csv' => "a,b\n2026,3\n"], site_report_build('partner', 2026, 3));
        $this->assertSame('Дължимо: 12,50 €', site_report_summary('partner', 2026, 3));
        $this->assertNull(site_report_build('broken', 2026, 3), 'a failing report is left out, never fatal');
        $this->assertSame('', site_report_summary('broken', 2026, 3));
        $this->assertNull(site_report_build('nope', 2026, 3));
    }

    public function test_the_report_page_and_email_offer_site_reports(): void
    {
        $root = dirname(__DIR__, 2);
        $page = (string) file_get_contents("$root/admin/monthly-report.php");
        $this->assertStringContainsString('site_reports()', $page);
        $this->assertStringContainsString('includes/site_extensions.php', $page);
        $cron = (string) file_get_contents("$root/cron/monthly-report-cron.php");
        $this->assertStringContainsString('site_report_build(', $cron);
        $this->assertStringContainsString('$site_files', $cron);
    }
}

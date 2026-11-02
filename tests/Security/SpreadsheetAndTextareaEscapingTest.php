<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Two ways typed-in text could turn into something live: a CSV cell a
 * spreadsheet runs as a formula, and a saved value that closes an admin
 * <textarea> early and renders as HTML on the admin page.
 */
final class SpreadsheetAndTextareaEscapingTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function formulaStarts(): array
    {
        return [
            'equals'          => ['=1+1'],
            'plus'            => ['+359 888 000 000'],
            'minus'           => ['-2+3'],
            'at'              => ['@SUM(A1)'],
            'tab'             => ["\t=1"],
            'carriage return' => ["\r=1"],
        ];
    }

    #[DataProvider('formulaStarts')]
    public function test_a_value_a_spreadsheet_would_run_becomes_plain_text(string $value): void
    {
        $this->assertSame("'" . $value, csv_text($value));
    }

    public function test_ordinary_values_are_left_alone(): void
    {
        $this->assertSame('Мария Иванова', csv_text('Мария Иванова'));
        $this->assertSame('a=b@c.bg', csv_text('a=b@c.bg'), 'only the first character matters');
        $this->assertSame('', csv_text(''));
        $this->assertSame('', csv_text(null));
    }

    public function test_the_exports_pass_typed_in_text_through_csv_text(): void
    {
        $backers = (string) file_get_contents(self::root() . '/admin/campaign-backers.php');
        foreach (["csv_text(\$r['name'])", "csv_text(\$r['email'])", 'csv_text($addr_str)'] as $call) {
            $this->assertStringContainsString($call, $backers);
        }
        $monthly = (string) file_get_contents(self::root() . '/includes/reports/monthly-orders.php');
        $this->assertStringContainsString("csv_text(\$row['customer_name'])", $monthly);
    }

    public function test_no_textarea_prints_a_value_without_escaping_it(): void
    {
        $offenders = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root(), FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen(self::root()) + 1);
            if ($f->getExtension() !== 'php' || preg_match('#^(vendor|node_modules|tests|\.git|\.claude)/#', $rel)) continue;
            $src = (string) file_get_contents($f->getPathname());
            // The value printed between "...>" and "</textarea>". $_don_val()
            // (templates/donation-form.php) is h() over the visitor's last input.
            preg_match_all('#><\?(?:=|php echo)\s*((?:(?!\?>).)+?)\s*;?\s*\?></textarea>#s', $src, $m);
            foreach ($m[1] as $expr) {
                if (!preg_match('/^(h|htmlspecialchars|\$_don_val)\s*\(/', $expr)) $offenders[] = "$rel: $expr";
            }
        }
        $this->assertSame([], $offenders, 'Wrap the value in h() so saved text cannot close the textarea.');
    }
}

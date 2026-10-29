<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/TestServer.php';
require_once dirname(__DIR__) . '/EditModeCoverageTest.php';

/**
 * A site with several donation recipients (its own includes/site-extensions.php)
 * still passes EditModeCoverageTest on the donation page: each recipient's name
 * and hint can be reworded there in Edit mode.
 *
 * The test web server reads a throwaway extensions file through
 * NGO_SITE_EXTENSIONS_FILE, so the checkout's own file is never touched.
 */
#[Group('http')]
final class DonationRecipientsEditModeTest extends TestCase
{
    private static string $base = '';
    private static string $file = '';

    public static function setUpBeforeClass(): void
    {
        self::$file = sys_get_temp_dir() . '/ngo-ext-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents(self::$file, <<<'PHP'
<?php return ['recipients' => [
    'foundation' => [
        'label_bg' => 'Фондация Тест', 'label_en' => 'Test Foundation',
        'purpose_bg' => 'За фондацията', 'default' => true,
    ],
    'partner-centre' => [
        'label_bg' => 'Партньорски център', 'label_en' => 'Partner centre',
        'hint_bg' => 'Дарението отива за центъра.', 'hint_en' => 'The donation goes to the centre.',
        'hint_url_bg' => '/novini/', 'hint_url_en' => '/en/news/',
        'purpose_bg' => 'За центъра',
        'order_label' => ['bg' => 'Център (поръчки)', 'en' => 'Centre (orders)'],
    ],
]];
PHP);
        // A server another test class left running would not see the file.
        TestServer::stop();
        putenv('NGO_SITE_EXTENSIONS_FILE=' . self::$file);
        self::$base = TestServer::start();
        if (self::$base === '') self::markTestSkipped('Could not start a local test server.');
    }

    public static function tearDownAfterClass(): void
    {
        TestServer::stop();
        putenv('NGO_SITE_EXTENSIONS_FILE');
        @unlink(self::$file);
    }

    public static function pages(): array
    {
        return [
            'bg' => ['/donation/', ['Фондация Тест', 'Партньорски център', 'Дарението отива за центъра.']],
            'en' => ['/en/donation/', ['Test Foundation', 'Partner centre', 'The donation goes to the centre.']],
        ];
    }

    #[DataProvider('pages')]
    public function test_every_recipient_text_can_be_changed_from_edit_mode(string $path, array $texts): void
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15]);
        $html = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 404) $this->markTestSkipped("$path is off on this site (its module is switched off).");
        $this->assertSame(200, $code);

        $this->assertStringContainsString('name="donation_recipient" value="partner-centre"', $html, 'donors are asked to choose');
        foreach ($texts as $t) $this->assertStringContainsString('>' . $t . '</span>', $html, "the file's text is the default: $t");
        $this->assertStringContainsString('data-cms-field="donation.recipient_label.partner_centre"', $html);
        $this->assertStringContainsString('data-cms-field="donation.recipient_hint.partner_centre"', $html);
        $this->assertStringNotContainsString('(поръчки)', $html, 'the order name is not for donors');
        $this->assertStringNotContainsString('(orders)', $html, 'the order name is not for donors');

        $missing = EditModeCoverageTest::uncovered($html, $path);
        $this->assertSame([], $missing, "$path shows text an admin can't reach from Edit mode:\n  " . implode("\n  ", $missing));
    }
}

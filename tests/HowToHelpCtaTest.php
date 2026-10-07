<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/Support/TestServer.php';

/**
 * "How to help" (BG /kak-da-pomogna/, EN /en/how-to-help/): each way to help gets
 * its button, and the contact form pre-selects the topic the button sends.
 *
 * The ways come from content/pages.json, which is site content and not in git,
 * so the class writes its own and puts back whatever was there. Which buttons
 * should show depends on this site's own settings (donations and campaign
 * switched on, social addresses), read from the same config the server uses.
 */
#[Group('http')]
final class HowToHelpCtaTest extends TestCase
{
    private static string $base = '';
    private static ?string $pagesBackup = null;

    private const WAYS = [
        ['title' => 'Стани доброволец', 'title_en' => 'Become a volunteer', 'text' => '<p>Д</p>', 'text_en' => '<p>V</p>'],
        ['title' => 'Стани партньор', 'title_en' => 'Become a partner', 'text' => '<p>П</p>', 'text_en' => '<p>P</p>'],
        ['title' => 'Стани дарител', 'title_en' => 'Become a donor', 'text' => '<p>Д</p>', 'text_en' => '<p>D</p>'],
        ['title' => 'Стани наш приятел в социалните мрежи', 'title_en' => 'Be our friend on social media', 'text' => '<p>С</p>', 'text_en' => '<p>S</p>'],
        ['title' => 'Подкрепи кампанията', 'title_en' => 'Support our campaign', 'text' => '<p>К</p>', 'text_en' => '<p>C</p>'],
    ];

    public static function setUpBeforeClass(): void
    {
        $pages = CONTENT_PATH . '/pages.json';
        self::$pagesBackup = is_file($pages) ? file_get_contents($pages) : null;
        save_json($pages, ['how_to_help' => ['title' => 'Как да помогна?', 'ways' => self::WAYS]]);

        self::$base = TestServer::start();
        if (self::$base === '') {
            self::markTestSkipped('Could not start a local test server — skipping HTTP tests.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        TestServer::stop();
        $pages = CONTENT_PATH . '/pages.json';
        if (self::$pagesBackup !== null) file_put_contents($pages, self::$pagesBackup);
        elseif (is_file($pages)) unlink($pages);
    }

    private function getBody(string $path): string
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $body = (string) curl_exec($ch);
        curl_close($ch);
        return $body;
    }

    // ── Contact form GET pre-selection ────────────────────────────────────────

    public function testBgContactFormPreSelectsVolunteer(): void
    {
        $body = $this->getBody('/kontakti/?topic=' . urlencode('Искам да стана доброволец'));
        $this->assertStringContainsString('value="Искам да стана доброволец" selected', $body);
    }

    public function testBgContactFormPreSelectsPartner(): void
    {
        $body = $this->getBody('/kontakti/?topic=' . urlencode('Искам да стана партньор'));
        $this->assertStringContainsString('value="Искам да стана партньор" selected', $body);
    }

    public function testBgContactFormIgnoresInvalidTopic(): void
    {
        $body = $this->getBody('/kontakti/?topic=InvalidTopic');
        $this->assertStringContainsString('selected>— Изберете тема —', $body);
    }

    public function testEnContactFormPreSelectsVolunteer(): void
    {
        $body = $this->getBody('/en/contacts/?topic=' . urlencode('I want to volunteer'));
        $this->assertStringContainsString('value="I want to volunteer" selected', $body);
    }

    public function testEnContactFormPreSelectsPartner(): void
    {
        $body = $this->getBody('/en/contacts/?topic=' . urlencode('I want to become a partner'));
        $this->assertStringContainsString('value="I want to become a partner" selected', $body);
    }

    public function testEnContactFormIgnoresInvalidTopic(): void
    {
        $body = $this->getBody('/en/contacts/?topic=InvalidTopic');
        $this->assertStringContainsString('selected>— Select a topic —', $body);
    }

    // ── Buttons on how-to-help (BG and EN) ────────────────────────────────────

    public function testBgHowToHelpButtons(): void
    {
        $this->assertWayButtons($this->getBody('/kak-da-pomogna/'), 'bg');
    }

    public function testEnHowToHelpButtons(): void
    {
        $this->assertWayButtons($this->getBody('/en/how-to-help/'), 'en');
    }

    private function assertWayButtons(string $body, string $lang): void
    {
        $en = $lang === 'en';
        foreach (self::WAYS as $way) {
            $this->assertStringContainsString(h($en ? $way['title_en'] : $way['title']), $body, 'every way is listed');
        }

        $contact = $en ? '/en/contacts/?topic=' : '/kontakti/?topic=';
        $this->assertStringContainsString($contact . urlencode($en ? 'I want to volunteer' : 'Искам да стана доброволец'), $body);
        $this->assertStringContainsString($contact . urlencode($en ? 'I want to become a partner' : 'Искам да стана партньор'), $body);

        $donate = 'href="' . h(donation_path('form', $lang)) . '"';
        feature_enabled('donations')
            ? $this->assertStringContainsString($donate, $body, 'the donor way links to the donation form')
            : $this->assertStringNotContainsString($donate, $body, 'donations are off, so no donate button');

        $campaign = 'href="' . ($en ? '/en/campaign/' : '/campaign/') . '"';
        feature_enabled('campaign')
            ? $this->assertStringContainsString($campaign, $body, 'the campaign way links to the campaign')
            : $this->assertStringNotContainsString($campaign, $body, 'the campaign is off, so nothing links to it');

        $this->assertStringContainsString($en ? 'Like us on Facebook' : 'Харесайте ни във Facebook', $body, 'the social way shows the follow buttons');
        if (SOCIAL_FACEBOOK !== '') $this->assertStringContainsString('href="' . h(SOCIAL_FACEBOOK) . '"', $body);
        if (SOCIAL_INSTAGRAM !== '') $this->assertStringContainsString('href="' . h(SOCIAL_INSTAGRAM) . '"', $body);
    }
}

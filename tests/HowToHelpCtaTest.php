<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('http')]
final class HowToHelpCtaTest extends TestCase
{
    private static string $base = 'http://oddminds.test';

    public static function setUpBeforeClass(): void
    {
        $ch = curl_init(self::$base . '/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0) {
            self::markTestSkipped('oddminds.test is not reachable — skipping HTTP tests.');
        }
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
        $this->assertStringContainsString(
            'value="Искам да стана доброволец" selected',
            $body,
            'BG contact form should pre-select "Искам да стана доброволец" via GET'
        );
    }

    public function testBgContactFormPreSelectsPartner(): void
    {
        $body = $this->getBody('/kontakti/?topic=' . urlencode('Искам да стана партньор'));
        $this->assertStringContainsString(
            'value="Искам да стана партньор" selected',
            $body,
            'BG contact form should pre-select "Искам да стана партньор" via GET'
        );
    }

    public function testBgContactFormIgnoresInvalidTopic(): void
    {
        $body = $this->getBody('/kontakti/?topic=InvalidTopic');
        $this->assertStringContainsString(
            'selected>— Изберете тема —',
            $body,
            'BG contact form should show placeholder when topic is invalid'
        );
    }

    public function testEnContactFormPreSelectsVolunteer(): void
    {
        $body = $this->getBody('/en/contacts/?topic=' . urlencode('I want to volunteer'));
        $this->assertStringContainsString(
            'value="I want to volunteer" selected',
            $body,
            'EN contact form should pre-select "I want to volunteer" via GET'
        );
    }

    public function testEnContactFormPreSelectsPartner(): void
    {
        $body = $this->getBody('/en/contacts/?topic=' . urlencode('I want to become a partner'));
        $this->assertStringContainsString(
            'value="I want to become a partner" selected',
            $body,
            'EN contact form should pre-select "I want to become a partner" via GET'
        );
    }

    public function testEnContactFormIgnoresInvalidTopic(): void
    {
        $body = $this->getBody('/en/contacts/?topic=InvalidTopic');
        $this->assertStringContainsString(
            'selected>— Select a topic —',
            $body,
            'EN contact form should show placeholder when topic is invalid'
        );
    }

    // ── CTA buttons on BG how-to-help ─────────────────────────────────────────

    public function testBgHowToHelpHasVolunteerCta(): void
    {
        $body = $this->getBody('/kak-da-pomogna/');
        $this->assertStringContainsString(
            '/kontakti/?topic=' . urlencode('Искам да стана доброволец'),
            $body,
            'BG page should have CTA link for volunteer way'
        );
    }

    public function testBgHowToHelpHasPartnerCta(): void
    {
        $body = $this->getBody('/kak-da-pomogna/');
        $this->assertStringContainsString(
            '/kontakti/?topic=' . urlencode('Искам да стана партньор'),
            $body,
            'BG page should have CTA link for partner way'
        );
    }

    public function testBgHowToHelpHasDonorCta(): void
    {
        $body = $this->getBody('/kak-da-pomogna/');
        $this->assertStringContainsString(
            '/donation/checkout.php',
            $body,
            'BG page should have donation checkout link for donor way'
        );
    }

    public function testBgHowToHelpHasSocialButtons(): void
    {
        $body = $this->getBody('/kak-da-pomogna/');
        $this->assertStringContainsString(
            'facebook.com/profile.php?id=61580050070685',
            $body,
            'BG page should have Facebook button in social media way'
        );
        $this->assertStringContainsString(
            'instagram.com/oddminds_foundation/',
            $body,
            'BG page should have Instagram button in social media way'
        );
    }

    public function testBgHowToHelpHasLafetkasCta(): void
    {
        $body = $this->getBody('/kak-da-pomogna/');
        $this->assertStringContainsString(
            'href="/campaign/"',
            $body,
            'BG page should have campaign link for Lafetki way'
        );
    }

    // ── CTA buttons on EN how-to-help ─────────────────────────────────────────

    public function testEnHowToHelpHasVolunteerCta(): void
    {
        $body = $this->getBody('/en/how-to-help/');
        $this->assertStringContainsString(
            '/en/contacts/?topic=' . urlencode('I want to volunteer'),
            $body,
            'EN page should have CTA link for volunteer way'
        );
    }

    public function testEnHowToHelpHasPartnerCta(): void
    {
        $body = $this->getBody('/en/how-to-help/');
        $this->assertStringContainsString(
            '/en/contacts/?topic=' . urlencode('I want to become a partner'),
            $body,
            'EN page should have CTA link for partner way'
        );
    }

    public function testEnHowToHelpHasDonorCta(): void
    {
        $body = $this->getBody('/en/how-to-help/');
        $this->assertStringContainsString(
            '/donation/checkout.php',
            $body,
            'EN page should have donation checkout link for donor way'
        );
    }

    public function testEnHowToHelpHasSocialButtons(): void
    {
        $body = $this->getBody('/en/how-to-help/');
        $this->assertStringContainsString(
            'facebook.com/profile.php?id=61580050070685',
            $body,
            'EN page should have Facebook button in social media way'
        );
        $this->assertStringContainsString(
            'instagram.com/oddminds_foundation/',
            $body,
            'EN page should have Instagram button in social media way'
        );
    }

    public function testEnHowToHelpHasLafetkasCta(): void
    {
        $body = $this->getBody('/en/how-to-help/');
        $this->assertStringContainsString(
            'href="/en/campaign/"',
            $body,
            'EN page should have EN campaign link for Lafetki way'
        );
    }
}

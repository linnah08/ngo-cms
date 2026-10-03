<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * The donation page, /donation/ and /en/donation/: the one place a donor gives.
 * One shared form template, every "Donate" link points at the page, and old
 * links to the shop's #donation anchor still reach the form.
 */
#[Group('shop')]
final class DonationPageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once self::root() . '/includes/home.php';
        require_once self::root() . '/includes/home_render.php';
        require_once self::root() . '/includes/seo.php';
    }

    private static function root(): string
    {
        return $_SERVER['DOCUMENT_ROOT'];
    }

    private static function src(string $rel): string
    {
        return (string) file_get_contents(self::root() . '/' . $rel);
    }

    // ── one form, one page ───────────────────────────────────────────────────

    public function testTheFormExistsOnlyInTheSharedTemplate(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root(), FilesystemIterator::SKIP_DOTS));
        $found = [];
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen(self::root()) + 1);
            if ($f->getExtension() !== 'php' || preg_match('#^(vendor|node_modules|tests|docs|\.claude|\.git)/#', $rel)) continue;
            if (str_contains((string) file_get_contents($f->getPathname()), 'action="/donation/checkout.php"')) {
                $found[] = $rel;
            }
        }
        $this->assertSame(['templates/donation-form.php'], $found, 'the donation form must not be copied');
        $this->assertStringContainsString("require \$_SERVER['DOCUMENT_ROOT'] . '/templates/donation-form.php'", self::src('donation/index.php'));
    }

    public function testEnglishPageIsAWrapperForTheSharedPage(): void
    {
        $this->assertStringContainsString(
            "require \$_SERVER['DOCUMENT_ROOT'] . '/donation/index.php'",
            self::src('en/donation/index.php')
        );
    }

    public function testThePageRespectsTheDonationsSwitch(): void
    {
        $this->assertMatchesRegularExpression(
            "/if \(!feature_enabled\('donations'\)\) \{\s*require [^;]*errors\/404\.php';\s*exit;/",
            self::src('donation/index.php')
        );
    }

    // ── title and intro ──────────────────────────────────────────────────────

    public function testDefaultsWhenTheSiteHasNoTextYet(): void
    {
        $bg = donation_page_content([], 'bg');
        $en = donation_page_content(['donation' => ['title' => '  '], 'shop' => []], 'en');
        $this->assertSame('Направи дарение', $bg['title']);
        $this->assertSame('Make a donation', $en['title']);
        $this->assertStringContainsString(h(SITE_NAME_BG), $bg['intro_html']);
        $this->assertStringContainsString(h(SITE_NAME_EN), $en['intro_html']);
        $this->assertDoesNotMatchRegularExpression('/[\x{0400}-\x{04FF}]/u', $en['title'] . $en['intro_html']);
    }

    public function testTheSitesOwnTextWins(): void
    {
        $pages = [
            'donation' => ['title' => 'Подкрепете ни', 'title_en' => 'Support us'],
            'shop'     => ['donation_text_bg' => '<p>Текст</p>', 'donation_text_en' => '<p>Text</p>'],
        ];
        $this->assertSame(['title' => 'Подкрепете ни', 'intro_html' => '<p>Текст</p>'], donation_page_content($pages, 'bg'));
        $this->assertSame(['title' => 'Support us', 'intro_html' => '<p>Text</p>'], donation_page_content($pages, 'en'));
    }

    // ── links ────────────────────────────────────────────────────────────────

    public function testDonateLinksPointAtTheDonationPage(): void
    {
        $this->assertSame('/donation/', donation_path('form', 'bg'));
        $this->assertSame('/en/donation/', donation_path('form', 'en'));
        $this->assertStringContainsString('href="/donation/" class="btn btn--primary"', self::src('novini/index.php'));
        $this->assertStringContainsString('href="/en/donation/" class="btn btn--primary"', self::src('en/news/index.php'));

        $seed = home_seed([], [], []);
        $cta  = array_values(array_filter($seed['sections'], fn ($s) => $s['type'] === 'cta'))[0];
        $this->assertSame('/donation/', $cta['fields']['btn1_url']['bg']);
        $this->assertSame('/en/donation/', $cta['fields']['btn1_url']['en']);

        foreach (['novini/index.php', 'en/news/index.php', 'includes/home.php', 'kak-da-pomogna/index.php', 'en/how-to-help/index.php'] as $rel) {
            $this->assertStringNotContainsString('#donation', self::src($rel), "$rel still links to the shop's old donation anchor");
        }
    }

    public function testOldFrontPageLinksToTheShopAnchorGoToTheDonationPage(): void
    {
        $this->assertSame('/donation/', home_current_link('/magazin/#donation'));
        $this->assertSame('/en/donation/', home_current_link('/en/shop/#donation'));
        $this->assertSame('/magazin/', home_current_link('/magazin/'));

        $html = home_buttons('s1', ['btn1_label' => ['bg' => 'Дари', 'en' => 'Donate'], 'btn1_url' => ['bg' => '/magazin/#donation', 'en' => '/en/shop/#donation']],
            'en', [['btn', ''], ['btn', '']]);
        $this->assertStringContainsString('href="/en/donation/"', $html);
    }

    public function testDonationPageHasALanguagePairAndIsInTheSitemap(): void
    {
        $this->assertSame('/en/donation/', seo_static_alt_map()['/donation/']);
        $this->assertStringContainsString("'/donation/', '/en/donation/'", self::src('sitemap.php'));
    }

    // ── rendered ─────────────────────────────────────────────────────────────

    #[RunInSeparateProcess]
    public function testBulgarianPageShowsTitleIntroAndForm(): void
    {
        if (!feature_enabled('donations')) $this->markTestSkipped('Donations are switched off on this site (FEATURE_DONATIONS).');
        $_SERVER['REQUEST_URI']    = '/donation/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        global $_path_map_bg_to_en;
        ob_start();
        require self::root() . '/donation/index.php';
        $html = (string) ob_get_clean();

        $dp = donation_page_content(load_json(CONTENT_PATH . '/pages.json'), 'bg');
        $this->assertMatchesRegularExpression('#<h1 data-cms-field="title"\s+data-cms-section="donation"#', $html, 'the title is editable on the page');
        $this->assertStringContainsString('>' . h($dp['title']) . '</h1>', $html);
        $this->assertStringContainsString('data-cms-field="donation_text"', $html, 'the intro is editable on the page');
        $this->assertStringContainsString('<section id="donation"', $html);
        $this->assertStringContainsString('<input type="hidden" name="_lang" value="bg">', $html);
        $this->assertStringContainsString('Дари сега', $html);
        $this->assertStringNotContainsString('name="purpose"', $html, 'one general donation — no purpose to choose');
        $this->assertStringNotContainsString('id="donationErrors"', $html, 'no error box without errors');
    }

    #[RunInSeparateProcess]
    public function testErrorsComeBackOnTheDonationPageWithTheDonorsInput(): void
    {
        if (!feature_enabled('donations')) $this->markTestSkipped('Donations are switched off on this site (FEATURE_DONATIONS).');
        $_SERVER['REQUEST_URI']    = '/donation/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        start_session();
        donation_form_fail(donation_validate(20, '', 'x@example.com', 'company', '', '', 'bg'),
            ['amount' => '20', 'donor_email' => 'x@example.com', 'donor_type' => 'company']);
        global $_path_map_bg_to_en;
        ob_start();
        require self::root() . '/donation/index.php';
        $html = (string) ob_get_clean();

        $this->assertMatchesRegularExpression('#<div id="donationErrors" role="alert" tabindex="-1"#', $html);
        $this->assertStringContainsString('<li>Моля въведете вашите имена.</li>', $html);
        $this->assertStringContainsString('value="x@example.com"', $html);
        $this->assertMatchesRegularExpression('#value="company" checked#', $html, 'the donor type stays chosen');
        $this->assertStringContainsString('<div id="donorCompanyFields" style="">', $html, 'company fields stay open');
    }

    #[RunInSeparateProcess]
    public function testShopPointsToTheDonationPageAndForwardsTheOldAnchor(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No DB configured.');
        if (!feature_enabled('donations')) $this->markTestSkipped('Donations are switched off on this site (FEATURE_DONATIONS).');
        $_SERVER['REQUEST_URI']    = '/en/shop/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        global $_path_map_bg_to_en;
        ob_start();
        require self::root() . '/magazin/index.php';
        $html = (string) ob_get_clean();

        $section = substr($html, (int) strpos($html, '<section id="donation"'));
        $section = substr($section, 0, (int) strpos($section, '</section>'));
        $this->assertNotSame('', $section, 'the #donation anchor stays for saved links');
        $this->assertStringContainsString("if (location.hash === '#donation') location.replace(\"\\/en\\/donation\\/\");", $section);
        $this->assertStringContainsString('<a href="/en/donation/" class="btn btn--primary"', $section, 'works without JavaScript too');
        $this->assertStringNotContainsString('<form', $section, 'the shop no longer carries its own copy of the form');
    }
}

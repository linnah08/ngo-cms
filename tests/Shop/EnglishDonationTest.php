<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * English donors stay in English from the donation form to the thank-you page:
 * the form on /en/donation/, its validation errors, the bank's payment
 * title, the payment returns, /en/donation/confirmation/ (a thin wrapper) and
 * the payment-failed page. Same pattern as EnglishCheckoutTest: t_or() for the
 * text, the order's stored language for everything after the bank.
 */
#[Group('shop')]
final class EnglishDonationTest extends TestCase
{
    private static function root(): string
    {
        return $_SERVER['DOCUMENT_ROOT'];
    }

    private static function src(string $rel): string
    {
        return (string) file_get_contents(self::root() . '/' . $rel);
    }

    // ── donation_path() ──────────────────────────────────────────────────────

    public function testDonationPathPerLanguage(): void
    {
        $this->assertSame('/donation/', donation_path('form', 'bg'));
        $this->assertSame('/en/donation/', donation_path('form', 'en'));
        $this->assertSame('/donation/confirmation/', donation_path('confirmation', 'bg'));
        $this->assertSame('/en/donation/confirmation/', donation_path('confirmation', 'en'));
        $this->assertSame('/donation/confirmation/', donation_path('confirmation', 'nonsense'), 'anything but en is BG');
    }

    public function testDonationPathRejectsUnknownPage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        donation_path('nope', 'en');
    }

    public function testEnglishConfirmationIsAWrapperForTheSharedPage(): void
    {
        $wrapper = self::root() . '/en/donation/confirmation/index.php';
        $this->assertFileExists($wrapper);
        $this->assertStringContainsString(
            "require \$_SERVER['DOCUMENT_ROOT'] . '/donation/confirmation/index.php'",
            (string) file_get_contents($wrapper),
            'the English page must reuse the shared page, not copy it'
        );
    }

    public function testBankPaymentTextsFollowTheDonationLanguage(): void
    {
        $this->assertSame('Donation OM-1', donation_payment_title('OM-1', 'en'));
        $this->assertSame('Дарение OM-1', donation_payment_title('OM-1', 'bg'));
        $this->assertSame(SITE_NAME_EN . ' — donation OM-1', donation_payment_description('OM-1', 'en'));
        $this->assertSame(SITE_NAME_BG . ' — дарение OM-1', donation_payment_description('OM-1', 'bg'));
    }

    // ── validation ───────────────────────────────────────────────────────────

    public function testValidDonationHasNoErrors(): void
    {
        $this->assertSame([], donation_validate(20, 'Ann', 'ann@example.com', 'individual', '', '', 'en'));
        $this->assertSame([], donation_validate(20, 'Ann', 'ann@example.com', 'company', 'Acme', '123', 'bg'));
    }

    public function testValidationErrorsAreInTheDonorsLanguage(): void
    {
        $en = donation_validate(0, '', 'not-an-email', 'company', '', '', 'en');
        $this->assertSame([
            'The amount must be at least 1 €.',
            'Please enter your name.',
            'Please enter a valid email address.',
            'Please enter the company name.',
            'Please enter the company ID (EIK / BULSTAT).',
        ], $en);

        $bg = donation_validate(0, '', 'not-an-email', 'individual', '', '', 'bg');
        $this->assertSame(['Сумата трябва да е поне 1 €.', 'Моля въведете вашите имена.', 'Невалиден имейл адрес.'], $bg);

        $this->assertSame(['The largest online donation is 50,000 €.'], donation_validate(60000, 'A', 'a@b.co', 'individual', '', '', 'en'));
    }

    public function testCompanyFieldsAreOnlyRequiredForACompany(): void
    {
        $this->assertSame([], donation_validate(5, 'A', 'a@b.co', 'individual', '', '', 'en'));
    }

    public function testFailedFormKeepsErrorsAndTypedValuesOnce(): void
    {
        start_session();
        donation_form_fail(['Please enter your name.'], [
            'amount' => ' 25 ', 'donor_email' => 'a@b.co', 'donor_type' => 'company',
            'donor_name' => ['not', 'a', 'string'], 'csrf_token' => 'secret', 'unknown' => 'x',
        ]);
        $state = donation_form_state();
        $this->assertSame(['Please enter your name.'], $state['errors']);
        $this->assertSame('25', $state['old']['amount']);
        $this->assertSame('company', $state['old']['donor_type']);
        $this->assertSame('', $state['old']['donor_name'], 'non-string input is dropped');
        $this->assertArrayNotHasKey('csrf_token', $state['old'], 'only the form fields are kept');
        $this->assertArrayNotHasKey('unknown', $state['old']);

        $this->assertSame(['errors' => [], 'old' => []], donation_form_state(), 'shown once, then cleared');
    }

    // ── the flow uses the language, not hardcoded BG paths ──────────────────

    public function testCheckoutTakesTheLanguageFromTheForm(): void
    {
        $src = self::src('donation/checkout.php');
        $this->assertStringContainsString('$order_lang = post_lang();', $src);
        $this->assertStringContainsString("donation_validate(", $src);
        $this->assertStringContainsString("shop_path('payment-failed', \$order_lang)", $src);
        $this->assertStringContainsString('donation_payment_title($order_number, $order_lang)', $src);
        $this->assertStringContainsString('<input type="hidden" name="_lang" value="<?= h($lang) ?>">', self::src('templates/donation-form.php'));
    }

    public function testPaymentReturnsSendTheDonorBackInTheirLanguage(): void
    {
        foreach (['api/payment-return.php', 'api/iris-payment-return.php'] as $rel) {
            $this->assertStringContainsString("donation_path('confirmation', \$order['lang'] ?? 'bg')", self::src($rel), $rel);
        }
        $this->assertStringContainsString("donation_payment_title(\$order_number, \$order['lang'] ?? 'bg')", self::src('api/iris-payment-return.php'),
            'an IRIS retry of a donation says "Donation", not "Order"');
    }

    public function testDonationPagesMoveToTheDonationsLanguage(): void
    {
        $this->assertStringContainsString("donation_path('confirmation', \$order_lang)", self::src('donation/confirmation/index.php'));
        $failed = self::src('checkout/payment-failed/index.php');
        $this->assertStringContainsString("donation_path('confirmation', \$order['lang'] ?? 'bg')", $failed);
        $this->assertStringContainsString("donation_path('form', \$lang)", $failed, 'a donor goes back to the donation form');
    }

    public function testDonorEmailFollowsTheDonationLanguage(): void
    {
        $src = self::src('includes/payment/process_payment.php');
        $this->assertStringContainsString("render_email_subject('donation-confirmation-customer', \$lang", $src);
        $this->assertStringContainsString("'lang'             => \$lang,", $src);
        $this->assertStringNotContainsString("'Благодарим за вашето дарение!'", $src, 'subject comes from the email template, in the donor\'s language');
    }

    public static function hardcodedPathFileProvider(): array
    {
        return array_map(fn ($f) => [$f], [
            'donation/checkout.php', 'donation/confirmation/index.php', 'checkout/payment-failed/index.php',
            'api/payment-return.php', 'api/iris-payment-return.php',
            'kak-da-pomogna/index.php', 'en/how-to-help/index.php',
        ]);
    }

    #[DataProvider('hardcodedPathFileProvider')]
    public function testNoHardcodedBulgarianDonationPaths(string $rel): void
    {
        $src = self::src($rel);
        $this->assertDoesNotMatchRegularExpression("#'/donation/confirmation/#", $src, "$rel must use donation_path('confirmation')");
        $this->assertDoesNotMatchRegularExpression("#Location: /(checkout|magazin|donation)/#", $src, "$rel must redirect via shop_path()/donation_path()");
        $this->assertDoesNotMatchRegularExpression("#'href'\s*=>\s*'/donation/checkout\.php'#", $src,
            "$rel: a GET to the donation handler cannot know the language — link to donation_path('form')");
    }

    // ── no Bulgarian-only text left in the donor's path ─────────────────────

    /** Cyrillic that may stay: text for the admin (error reports). */
    private const ALLOWED = [
        'payment_error_report(',
    ];

    /** The donor-facing files, or the donor-facing part of a file. */
    public static function donorFileProvider(): array
    {
        return [
            ['donation/checkout.php', null],
            ['donation/confirmation/index.php', null],
            ['includes/donation.php', null],
            ['templates/donation-form.php', null],
            ['donation/index.php', null],
            ['magazin/index.php', '<!-- ── SECTION B: Donations'],
        ];
    }

    #[DataProvider('donorFileProvider')]
    public function testNoUntranslatedBulgarianInDonorPages(string $rel, ?string $from): void
    {
        $src    = self::src($rel);
        $offset = 0;
        if ($from !== null) {
            $pos = strpos($src, $from);
            $this->assertNotFalse($pos, "$rel lost its '$from' marker");
            $offset = substr_count(substr($src, 0, $pos), "\n");
        }
        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= str_repeat("\n", substr_count($t[1], "\n"));
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }
        $bad = [];
        foreach (array_slice(explode("\n", $code), $offset, null, true) as $i => $line) {
            if (!preg_match('/[\x{0400}-\x{04FF}]/u', $line)) continue;
            if (str_contains($line, 't_or(')) continue;
            if (str_starts_with(ltrim($line), '//')) continue;
            if (preg_match("/\\\$lang === '(bg|en)' \\?/", $line)) continue; // an inline BG/EN pair
            foreach (self::ALLOWED as $ok) {
                if (str_contains($line, $ok)) continue 2;
            }
            $bad[] = ($i + 1) . ': ' . trim($line);
        }
        $this->assertSame([], $bad, "$rel has Bulgarian text an English donor would see");
    }

    #[RunInSeparateProcess]
    public function testLanguageSwitchOnDonationConfirmation(): void
    {
        $_SERVER['REQUEST_URI'] = '/donation/confirmation/';
        $page_title = 'x';
        global $_path_map_bg_to_en; // header.php's _switch_lang() reads it as a global
        ob_start();
        require self::root() . '/templates/header.php';
        ob_end_clean();
        $this->assertSame('/en/donation/confirmation/', _switch_lang('/donation/confirmation/', 'bg'));
        $this->assertSame('/donation/confirmation/', _switch_lang('/en/donation/confirmation/', 'en'));
    }

    // ── rendered: the English form with errors ───────────────────────────────

    #[RunInSeparateProcess]
    public function testEnglishDonationFormShowsErrorsAccessiblyInEnglish(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No DB configured.');
        if (!feature_enabled('donations')) $this->markTestSkipped('Donations are switched off on this site (FEATURE_DONATIONS).');
        $_SERVER['REQUEST_URI']    = '/en/donation/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        start_session();
        donation_form_fail(donation_validate(0, 'Ann', 'ann@example.com', 'individual', '', '', 'en'), [
            'amount' => '0', 'donor_name' => 'Ann <b>', 'donor_email' => 'ann@example.com',
        ]);
        global $_path_map_bg_to_en;
        ob_start();
        require self::root() . '/en/donation/index.php';
        $html = (string) ob_get_clean();

        $section = substr($html, (int) strpos($html, '<section id="donation"'));
        $section = substr($section, 0, (int) strpos($section, '</section>'));
        $this->assertNotSame('', $section);

        $this->assertMatchesRegularExpression('#<div id="donationErrors" role="alert" tabindex="-1"#', $section, 'errors are announced');
        $this->assertStringContainsString('Your donation was not sent', $section);
        $this->assertStringContainsString('<li>The amount must be at least 1 €.</li>', $section);
        $this->assertStringContainsString('value="Ann &lt;b&gt;"', $section, 'typed values come back, escaped');
        foreach (['donAmount', 'donName', 'donEmail', 'donMessage', 'donCompany', 'donEik', 'donVat'] as $id) {
            $this->assertMatchesRegularExpression('#<label for="' . $id . '"#', $section, "$id has a real label");
            $this->assertMatchesRegularExpression('#id="' . $id . '"#', $section);
        }
        $this->assertStringContainsString('Donate now', $section);

        $this->assertDoesNotMatchRegularExpression('/[\x{0400}-\x{04FF}]/u', strip_tags($section), 'no Bulgarian in the English donation form');
    }

    // ── rendered: the English thank-you page ─────────────────────────────────

    #[RunInSeparateProcess]
    public function testEnglishDonationConfirmationIsEnglish(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No DB configured.');
        if (!feature_enabled('donations')) $this->markTestSkipped('Donations are switched off on this site (FEATURE_DONATIONS).');
        require_once self::root() . '/admin/includes/db.php';
        $pdo = get_pdo();
        $num = generate_order_number();
        $pdo->prepare("INSERT INTO orders (order_number, type, status, lang, customer_name, customer_email, items,
                         subtotal_eur, shipping_eur, total_eur, donation_message, payment_method, payment_status)
                       VALUES (?, 'donation', 'confirmed', 'en', 'Test Donor', 'donor@example.com', '[]', 15, 0, 15, 'Go team', 'card', 'paid')")
            ->execute([$num]);
        $id = (int) $pdo->lastInsertId();
        try {
            $_SERVER['REQUEST_URI'] = '/en/donation/confirmation/?order=' . $num;
            $_GET['order'] = $num;
            global $_path_map_bg_to_en;
            ob_start();
            require self::root() . '/donation/confirmation/index.php';
            $html = (string) ob_get_clean();
        } finally {
            $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
        }

        $main = substr($html, (int) strpos($html, '<section class="section section--teal">'));
        $main = substr($main, 0, (int) strpos($main, '<!-- Follow / share -->'));
        $this->assertStringContainsString('Thank you from the heart!', $main);
        $this->assertStringContainsString('Your donation of <strong>15.00 €</strong> has been received.', $main);
        $this->assertStringContainsString('Go team', $main);
        $this->assertStringContainsString('href="/en/"', $main);
        $this->assertStringContainsString('href="/en/how-to-help/"', $main);
        $this->assertDoesNotMatchRegularExpression('/[\x{0400}-\x{04FF}]/u', $main);
        $this->assertMatchesRegularExpression('#<html[^>]*lang="en"#', $html);
    }
}

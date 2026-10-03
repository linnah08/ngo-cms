<?php
/**
 * The standalone donation flow (the donation page /donation/ → donation/checkout.php
 * → the bank → donation/confirmation/), in the donor's language.
 *
 * Same pattern as the shop (shop_path(), t_or()): one PHP file per page, the
 * /en/... path is a thin wrapper, and the order's stored language (orders.lang)
 * decides where payment returns land.
 */

/**
 * Donor-facing donation URLs. The form lives on its own page, /donation/
 * (older links to the shop's #donation anchor are forwarded there by the shop
 * page); the payment-failed page is the shared one from shop_path().
 */
function donation_path(string $page, ?string $lang = null): string {
    $lang = ($lang ?? get_lang()) === 'en' ? 'en' : 'bg';
    return match ($page) {
        'form'         => $lang === 'en' ? '/en/donation/' : '/donation/',
        'confirmation' => $lang === 'en' ? '/en/donation/confirmation/' : '/donation/confirmation/',
        default        => throw new InvalidArgumentException("Unknown donation page: $page"),
    };
}

/**
 * Title and intro of the donation page, in $lang, with generic defaults for a
 * site that has not written its own yet. Stored in pages.json where the shop's
 * donation block always kept them: donation.title(_en) and
 * shop.donation_text_bg/_en (rich text from the admin editor).
 *
 * @return array{title: string, intro_html: string}
 */
function donation_page_content(array $pages, string $lang): array {
    $en    = $lang === 'en';
    $title = trim((string) ($pages['donation'][$en ? 'title_en' : 'title'] ?? ''));
    $intro = trim((string) ($pages['shop'][$en ? 'donation_text_en' : 'donation_text_bg'] ?? ''));
    if ($title === '') {
        $title = t_or('donation.page.title', 'Направи дарение', 'Make a donation', $lang);
    }
    if ($intro === '') {
        $site  = $en ? SITE_NAME_EN : SITE_NAME_BG;
        $intro = '<p>' . h(t_or('donation.page.intro', 'Вашето дарение подкрепя дейността и програмите на {site}. Всяка сума има значение — благодарим ви!', 'Your donation supports the work and programmes of {site}. Every amount makes a difference — thank you!', $lang, ['site' => $site])) . '</p>';
    }
    return ['title' => $title, 'intro_html' => $intro];
}

/** What the donor's bank shows for a donation (IRIS Pay by Bank). */
function donation_payment_title(string $order_number, string $lang): string {
    return t_or('donation.pay.title', 'Дарение', 'Donation', $lang) . ' ' . $order_number;
}

function donation_payment_description(string $order_number, string $lang): string {
    $site = $lang === 'en' ? SITE_NAME_EN : SITE_NAME_BG;
    return $site . ' — ' . mb_strtolower(t_or('donation.pay.title', 'Дарение', 'Donation', $lang)) . ' ' . $order_number;
}

/**
 * Check a donation form submission. Returns the donor-facing error messages
 * (empty when it is fine), in $lang.
 */
function donation_validate(float $amount, string $name, string $email, string $donor_type,
                           string $company, string $eik, string $lang): array {
    $errors = [];
    if ($amount < 1) {
        $errors[] = t_or('donation.err.amount_min', 'Сумата трябва да е поне 1 €.', 'The amount must be at least 1 €.', $lang);
    }
    if ($amount > 50000) {
        $errors[] = t_or('donation.err.amount_max', 'Максималната сума за онлайн дарение е 50 000 €.', 'The largest online donation is 50,000 €.', $lang);
    }
    if ($name === '') {
        $errors[] = t_or('donation.err.name', 'Моля въведете вашите имена.', 'Please enter your name.', $lang);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = t_or('donation.err.email', 'Невалиден имейл адрес.', 'Please enter a valid email address.', $lang);
    }
    if ($donor_type === 'company') {
        if ($company === '') {
            $errors[] = t_or('donation.err.company', 'Въведете наименование на фирмата.', 'Please enter the company name.', $lang);
        }
        if ($eik === '') {
            $errors[] = t_or('donation.err.eik', 'Въведете ЕИК / Булстат.', 'Please enter the company ID (EIK / BULSTAT).', $lang);
        }
    }
    return $errors;
}

/** Form fields kept after a failed submission, so the donor doesn't type them again. */
const DONATION_FORM_FIELDS = [
    'amount', 'donor_name', 'donor_email', 'donation_message', 'donor_type',
    'invoice_company', 'invoice_eik', 'invoice_vat', 'payment_method',
];

/**
 * Remember why a donation was not accepted (shown inside the form, next to
 * the fields, not at the top of the shop page) and what the donor typed.
 */
function donation_form_fail(array $errors, array $post): void {
    start_session();
    $old = [];
    foreach (DONATION_FORM_FIELDS as $f) {
        $v = $post[$f] ?? '';
        $old[$f] = is_string($v) ? mb_substr(trim($v), 0, 2000) : '';
    }
    $_SESSION['donation_form'] = ['errors' => array_values($errors), 'old' => $old];
}

/** Returns and clears the remembered errors and values: ['errors' => [...], 'old' => [...]]. */
function donation_form_state(): array {
    start_session();
    $state = $_SESSION['donation_form'] ?? [];
    unset($_SESSION['donation_form']);
    return [
        'errors' => is_array($state['errors'] ?? null) ? $state['errors'] : [],
        'old'    => is_array($state['old'] ?? null) ? $state['old'] : [],
    ];
}

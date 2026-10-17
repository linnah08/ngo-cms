<?php
/**
 * Checks for what customers type into the checkout and donation forms: their
 * name, phone, and a company's ЕИК / ДДС номер. The ЕИК check itself is
 * org_eik_normalize() in includes/organisation.php.
 */

/**
 * A first and last name: at least two words of two or more letters each, in
 * any alphabet ("Иван Петров", "Ivan Petrov", "Мария-Елена Д'Арк"). A middle
 * initial is fine as long as two real words are there. Digits and symbols are
 * refused.
 */
function form_person_name_valid(string $name): bool
{
    $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $real  = 0;
    foreach ($words as $w) {
        if (!preg_match("/^[\p{L}\p{M}'’.\-]+$/u", $w)) return false;
        if (preg_match_all('/\p{L}/u', $w) >= 2) $real++;
    }
    return $real >= 2;
}

/**
 * A phone number the server can trust after the browser's own check. Expects
 * the international form the checkout script submits (+359888123456); a
 * number without a country code is read as Bulgarian. Bulgarian numbers must
 * have 8 or 9 digits after +359, and mobiles (087/088/089/098/099) exactly 9.
 */
function form_phone_valid(string $phone): bool
{
    require_once __DIR__ . '/couriers/courier_phone.php';
    $e164   = courier_normalize_phone($phone);
    $digits = substr($e164, 1);
    if (!preg_match('/^\d{7,15}$/', $digits)) return false;
    if (!str_starts_with($digits, '359')) return true;
    $national = substr($digits, 3);
    if (preg_match('/^(8[7-9]|9[89])/', $national)) return strlen($national) === 9;
    return (bool) preg_match('/^[2-9]\d{7,8}$/', $national);
}

/**
 * An EU VAT number: two-letter country code, then the number. Bulgarian ones
 * are BG + 9 or 10 digits. Spaces, dots and dashes are removed. Returns the
 * clean number, or null when it can't be one.
 */
function form_vat_normalize(string $vat): ?string
{
    $v = strtoupper(preg_replace('/[\s.\-]+/', '', $vat));
    if (str_starts_with($v, 'BG')) return preg_match('/^BG\d{9,10}$/', $v) ? $v : null;
    return preg_match('/^[A-Z]{2}[0-9A-Z+*]{2,13}$/', $v) && preg_match('/\d/', $v) ? $v : null;
}

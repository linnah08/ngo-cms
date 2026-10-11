<?php
/**
 * Shared phone-number normalization for courier integrations.
 *
 * Couriers (Speedy, Econt, BoxNow) reject phone numbers that aren't in a clean
 * international format. Historically the checkout stored numbers WITHOUT the
 * +359 country code (intl-tel-input's separateDialCode keeps the dial code out
 * of the submitted value), which made Speedy/Econt fail with "Невалидна
 * стойност". This normalizer is the server-side safety net for those orders.
 *
 * Strips all separators (spaces, dashes, parens, dots) and resolves to E.164.
 * Handles: 08XXXXXXXX, 8XXXXXXXX, 00359…, 359…, +3598XXXXXXXX
 * Returns '' for an empty input.
 */
function courier_normalize_phone(string $phone): string
{
    $hasPlus = str_starts_with(ltrim($phone), '+');
    $digits  = preg_replace('/\D+/', '', $phone);            // keep digits only

    if ($digits === '')                     return '';
    if ($hasPlus)                           return '+' . $digits;       // already international
    if (str_starts_with($digits, '00'))     return '+' . substr($digits, 2);
    if (str_starts_with($digits, '359'))    return '+' . $digits;       // 359… without +
    if (str_starts_with($digits, '0'))      return '+359' . substr($digits, 1);
    return '+359' . $digits;
}

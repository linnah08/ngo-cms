<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 *  SITE / ORGANISATION CONFIGURATION
 * ─────────────────────────────────────────────────────────────────────────────
 *  Copy this file to `site.config.php` (gitignored) and replace the values
 *  below with your own organisation's details. This is the ONE file every
 *  adopting NGO needs to edit to rebrand the site.
 *
 *  The values shipped here are the live Odd Minds Foundation values, kept as a
 *  worked example. If `site.config.php` is missing, this file is loaded as a
 *  fallback so a fresh clone still boots — but you should always create your own.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── Organisation identity ─────────────────────────────────────────────────────
define('SITE_NAME_BG', 'Фондация Различни умове');   // name shown on the Bulgarian site
define('SITE_NAME_EN', 'Odd Minds Foundation');      // name shown on the English site

// Public base URL — NO trailing slash. Every absolute URL (emails, payment
// callbacks, lang_url()) derives from this. For local dev use e.g.
// 'http://localhost:8000' or 'https://yoursite.test'.
define('SITE_URL',   'https://oddminds.org');

define('SITE_EMAIL', 'info@oddminds.org');           // public contact / from address
define('SITE_PHONE', '+359896670346');               // public phone (also seeds an internal token)

// ── Bank details (shown for direct donations) ─────────────────────────────────
define('SITE_IBAN',      'BG40STSA93000032062526');
define('SITE_BIC',       'STSABGSF');
define('SITE_BANK_NAME', 'ДСК Банк');

// Email of the single admin account authorised to sign donation certificates.
define('SIGNING_ADMIN_EMAIL', 'info@oddminds.org');

// ── Social profiles (leave '' to hide that icon) ──────────────────────────────
define('SOCIAL_FACEBOOK',  'https://www.facebook.com/profile.php?id=61580050070685');
define('SOCIAL_INSTAGRAM', 'https://www.instagram.com/oddminds_foundation/');
define('SOCIAL_LINKEDIN',  'https://www.linkedin.com/company/odd-minds-foundation');

// ── Analytics / ads (leave '' to disable that tag entirely) ───────────────────
define('GTM_ID',                    'GTM-K5MKH58C');        // Google Tag Manager container
define('GA4_ID',                    'G-MFR08GHCMN');        // GA4 measurement ID
define('GOOGLE_ADS_ID',             'AW-17949247786');      // Google Ads conversion ID
define('GOOGLE_ADS_PURCHASE_LABEL', 'un6iCMLrq7UcEKqS7-5C');// add-to-cart conversion label

// ── Currency ──────────────────────────────────────────────────────────────────
// Bulgaria adopted the Euro on 1 Jan 2026; dual BGN/EUR display is required
// until 1 July 2026. Non-Bulgarian adopters can set DUAL_CURRENCY_UNTIL to a
// past date to show a single currency only.
define('EUR_BGN_RATE',        1.95583);
define('DUAL_CURRENCY_UNTIL', '2026-07-01');

// ── Optional feature modules ──────────────────────────────────────────────────
// Crowdfunding campaigns (the campaign/ pages + reward pledges in checkout).
// Set to false if your organisation does not run crowdfunding campaigns.
define('FEATURE_CAMPAIGN', true);

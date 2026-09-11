<?php
/**
 * Renders the Cloudflare Turnstile widget when configured; otherwise emits
 * nothing so the including form still submits normally.
 * See includes/spam_filter.php for turnstile_is_configured()/turnstile_verify().
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/spam_filter.php';

if (turnstile_is_configured()):
?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<div class="cf-turnstile" data-sitekey="<?= h(setting_get('turnstile_site_key')) ?>"></div>
<?php endif; ?>

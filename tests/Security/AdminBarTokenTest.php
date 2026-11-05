<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The admin-bar cookie (om_admin_tok) is accepted as login by
 * admin/inline-save.php, so it must be signed with a secret key — never one
 * derived from values shown on the public site (name, phone, IBAN).
 */
final class AdminBarTokenTest extends TestCase
{
    protected function setUp(): void
    {
        if (_admin_bar_secret() === null) {
            $this->markTestSkipped('SETTINGS_ENCRYPTION_KEY not configured (no db.config.php)');
        }
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[ADMIN_BAR_COOKIE]);
    }

    /** The current format: "<exp>|<role>|<sig>", the signature over "v2|<exp>|<role>". */
    private function token(string $key, ?int $exp = null, string $role = 'admin'): string
    {
        $payload = ($exp ?? time() + 3600) . '|' . $role;
        return $payload . '|' . hash_hmac('sha256', 'v2|' . $payload, $key);
    }

    /** The format issued before the role was recorded: every user got "admin". */
    private function oldToken(string $key, string $role = 'admin'): string
    {
        $payload = (time() + 3600) . '|' . $role;
        return $payload . '|' . hash_hmac('sha256', $payload, $key);
    }

    public function test_valid_token_is_accepted(): void
    {
        $_COOKIE[ADMIN_BAR_COOKIE] = $this->token((string) _admin_bar_secret());
        $this->assertTrue(admin_bar_token_verify());
    }

    public function test_token_carries_the_role_it_was_issued_to(): void
    {
        $key = (string) _admin_bar_secret();
        $_COOKIE[ADMIN_BAR_COOKIE] = $this->token($key, null, 'author');
        $this->assertSame('author', admin_bar_token_role());
        $_COOKIE[ADMIN_BAR_COOKIE] = $this->token($key);
        $this->assertSame('admin', admin_bar_token_role());
    }

    public function test_cookie_issued_before_roles_were_recorded_is_refused(): void
    {
        // It said "admin" for an author too, so it must not open the editor any more.
        $_COOKIE[ADMIN_BAR_COOKIE] = $this->oldToken((string) _admin_bar_secret());
        $this->assertNull(admin_bar_token_role());
        $this->assertFalse(admin_bar_token_verify());
    }

    public function test_token_forged_from_public_site_details_is_rejected(): void
    {
        // The old key: everything in it is printed in the site footer.
        $public = hash('sha256', SITE_NAME_EN . SITE_PHONE . SITE_IBAN);
        $_COOKIE[ADMIN_BAR_COOKIE] = $this->token($public);
        $this->assertFalse(admin_bar_token_verify());
    }

    public function test_key_does_not_depend_on_public_details(): void
    {
        $this->assertNotSame(hash('sha256', SITE_NAME_EN . SITE_PHONE . SITE_IBAN), _admin_bar_secret());
        $this->assertNotSame((string) SETTINGS_ENCRYPTION_KEY, _admin_bar_secret(), 'derived, not the raw encryption key');
    }

    public function test_expired_tampered_and_malformed_tokens_are_rejected(): void
    {
        $key = (string) _admin_bar_secret();

        $_COOKIE[ADMIN_BAR_COOKIE] = $this->token($key, time() - 10);
        $this->assertFalse(admin_bar_token_verify(), 'expired');

        $good = $this->token($key);
        $_COOKIE[ADMIN_BAR_COOKIE] = str_replace('|admin|', '|author|', $good);
        $this->assertFalse(admin_bar_token_verify(), 'tampered role');

        $_COOKIE[ADMIN_BAR_COOKIE] = 'garbage';
        $this->assertFalse(admin_bar_token_verify(), 'malformed');
    }
}

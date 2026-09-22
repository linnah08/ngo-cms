<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * An order records what the customer agreed to and which version of the text
 * they agreed to. The checkbox in the page is marked `required`, but that is a
 * convenience for the customer — the rule that matters is enforced on the
 * server, because a `required` attribute is trivially bypassed.
 */
final class CheckoutConsentTest extends TestCase
{
    private static function checkoutSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/checkout/index.php');
    }

    public function testTheOrderIsAbandonedWhenConsentIsMissing(): void
    {
        $src = self::checkoutSource();

        $this->assertMatchesRegularExpression(
            '/if \(empty\(\$_POST\[.accept_terms.\]\)\)/',
            $src,
            'Checkout must verify the consent server-side, not only in the browser'
        );

        // The check has to come before anything is written. If the bail-out is
        // ever removed, an order would be created with consent refused.
        $checkPos = strpos($src, "empty(\$_POST['accept_terms'])");
        $insertPos = strpos($src, 'INSERT INTO orders');
        $this->assertIsInt($checkPos);
        $this->assertIsInt($insertPos);
        $this->assertLessThan(
            $insertPos,
            $checkPos,
            'The consent check must run before the order row is written'
        );

        $between = substr($src, $checkPos, $insertPos - $checkPos);
        $this->assertStringContainsString(
            'goto render',
            $between,
            'A missing consent must abandon the order, not just record an error'
        );
    }

    public function testConsentIsStoredWithItsVersionAndTimestamp(): void
    {
        $src = self::checkoutSource();
        foreach (["'terms_version' => LEGAL_VERSION", "'accepted_at'", "'newsletter'"] as $needle) {
            $this->assertStringContainsString($needle, $src, "Consent record is missing $needle");
        }
        $this->assertStringContainsString(
            'json_encode($consents',
            $src,
            'The consent record must be written to the order'
        );
    }

    public function testTheOrderInsertBindsEveryColumnItNames(): void
    {
        // Adding a column to the INSERT without adding its placeholder is an
        // easy mistake and fails only at runtime, on a real customer's order.
        $src = self::checkoutSource();
        $this->assertTrue(
            (bool) preg_match('/INSERT INTO orders\s*\((.*?)\)\s*VALUES\s*\((.*?)\)/s', $src, $m),
            'Could not find the order INSERT'
        );
        $columns      = count(array_filter(array_map('trim', explode(',', $m[1]))));
        $placeholders = substr_count($m[2], '?');
        $this->assertSame(
            $columns,
            $placeholders,
            "The order INSERT names $columns columns but binds $placeholders values"
        );
    }

    public function testLegalVersionIsSetAndDated(): void
    {
        $this->assertTrue(defined('LEGAL_VERSION'));
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            LEGAL_VERSION,
            'LEGAL_VERSION should be a date, so the accepted wording can be identified later'
        );
    }

    public function testBothLanguagesGetTheConsentCopy(): void
    {
        $src = self::checkoutSource();
        $this->assertStringContainsString('Приемам', $src, 'Missing Bulgarian consent label');
        $this->assertStringContainsString('I accept the', $src, 'Missing English consent label');
    }
}

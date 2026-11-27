<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Source-scan checks that every place the cart changes also saves it, and that
 * placing an order marks the saved cart as ordered.
 */
#[Group('shop')]
#[Group('cart')]
final class SavedCartsHooksTest extends TestCase
{
    private function src(string $rel): string
    {
        $s = file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
        $this->assertNotFalse($s, $rel);
        return $s;
    }

    public function test_every_cart_write_calls_sync(): void
    {
        foreach (['cart/add.php', 'cart/update.php', 'cart/remove.php', 'checkout/index.php'] as $f) {
            $s = $this->src($f);
            if (preg_match('/\$_SESSION\[\'cart\'\]\s*=[^=]/', $s)) {
                $this->assertStringContainsString('saved_cart_sync(', $s, "$f writes the cart but never saves it");
                $this->assertStringContainsString('includes/saved_carts.php', $s, "$f must load saved_carts.php");
            }
        }
    }

    public function test_cart_writes_are_each_followed_by_a_sync(): void
    {
        foreach (['cart/add.php', 'cart/update.php', 'cart/remove.php'] as $f) {
            $s = $this->src($f);
            $this->assertSame(1, preg_match_all('/\$_SESSION\[\'cart\'\]\s*=[^=]/', $s), $f);
            $pos = strpos($s, "\$_SESSION['cart'] =");
            $this->assertNotFalse(strpos($s, 'saved_cart_sync(', $pos), "$f: sync must come after the write");
        }
        $s = $this->src('checkout/index.php');
        $fn = substr($s, strpos($s, 'function update_cart_from_post'));
        $fn = substr($fn, 0, strpos($fn, "\n}\n"));
        $this->assertStringContainsString('saved_cart_sync(', $fn);
    }

    public function test_sync_failures_are_swallowed(): void
    {
        foreach (['cart/add.php', 'cart/update.php', 'cart/remove.php', 'checkout/index.php'] as $f) {
            $this->assertMatchesRegularExpression(
                '/try\s*\{[^}]*saved_cart_(sync|mark_ordered)\([^}]*\}\s*catch\s*\(Throwable/s',
                $this->src($f), $f
            );
        }
    }

    public function test_order_commit_marks_ordered(): void
    {
        $s = $this->src('checkout/index.php');
        $commit = strpos($s, '$pdo->commit();');
        $this->assertNotFalse($commit);
        $mark = strpos($s, 'saved_cart_mark_ordered($pdo);', $commit);
        $this->assertNotFalse($mark, 'order commit must mark the saved cart ordered');
        $this->assertLessThan(strpos($s, "unset(\$_SESSION['cart'], \$_SESSION['checkout_data']);"), $mark);
    }

    public function test_pledge_handoff_marks_pledge_row(): void
    {
        $s = $this->src('checkout/index.php');
        $mark = strpos($s, 'saved_cart_mark_ordered($pdo, $pledge_id);');
        $this->assertNotFalse($mark);
        $this->assertLessThan(strpos($s, "unset(\$_SESSION['checkout_data'], \$_SESSION['cart']);"), $mark);
        $this->assertGreaterThan(strpos($s, 'campaign_pledges SET dsk_order_id'), $mark);
    }
}

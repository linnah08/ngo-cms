<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Order, donation and pledge numbers are in the confirmation page's URL and have
 * only four random characters a day, so a script can walk through a day's numbers.
 * The buyer's details — items, address, phone, email, the donor's message — must
 * show only in the browser that placed the order; anyone else gets a thank-you.
 */
final class ConfirmationPrivacyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/order_session.php';
    }

    // ── the session rule ─────────────────────────────────────────────────────

    public function testOnlyRememberedNumbersAreOwned(): void
    {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $saved = $_SESSION[ORDER_SESSION_KEY] ?? null;
        try {
            unset($_SESSION[ORDER_SESSION_KEY]);
            $this->assertFalse(order_session_owns('OM-20260101-ABCD'));
            order_session_remember('OM-20260101-ABCD');
            $this->assertTrue(order_session_owns('om-20260101-abcd'), 'case-insensitive, like the URL');
            $this->assertFalse(order_session_owns('OM-20260101-ABCE'));
            $this->assertFalse(order_session_owns(''));
            for ($i = 0; $i < 12; $i++) order_session_remember(sprintf('CP-20260101-%04X', $i));
            $this->assertFalse(order_session_owns('OM-20260101-ABCD'), 'only the last 10 are kept');
            $this->assertTrue(order_session_owns('CP-20260101-000B'));
        } finally {
            $_SESSION[ORDER_SESSION_KEY] = $saved;
        }
    }

    public function testEveryFormThatCreatesAnOrderRemembersIt(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['checkout/index.php' => '$order_number', 'donation/checkout.php' => '$order_number', 'campaign/checkout.php' => '$pledge_number'] as $rel => $var) {
            $this->assertStringContainsString("order_session_remember($var)", (string) file_get_contents("$root/$rel"), $rel);
        }
    }

    // ── the pages themselves, against the database ───────────────────────────

    /** Render a confirmation page for $number, owned by this session or not. */
    private static function render(string $page, string $param, string $number, bool $owned): string
    {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_SESSION[ORDER_SESSION_KEY] = $owned ? [strtoupper($number)] : [];
        $_GET = [$param => $number];
        $_SERVER['REQUEST_URI'] = "/$page/?$param=" . rawurlencode($number);
        ob_start();
        require $_SERVER['DOCUMENT_ROOT'] . "/$page/index.php";
        return (string) ob_get_clean();
    }

    private static function pdoOrSkip(TestCase $t): \PDO
    {
        if (!test_db_available()) $t->markTestSkipped('No DB configured.');
        require_once dirname(__DIR__, 2) . '/admin/includes/db.php';
        return get_pdo();
    }

    private static function insertOrder(\PDO $pdo, string $type, array $extra = []): array
    {
        $number = sprintf('OM-20000101-%04X', random_int(0, 0xFFFF));
        $row = $extra + [
            'order_number' => $number, 'type' => $type, 'status' => 'new', 'lang' => 'bg',
            'customer_name' => 'Тест Купувач', 'customer_email' => 'buyer-secret@example.test',
            'customer_phone' => '+359888000111', 'items' => '[]', 'subtotal_eur' => 25, 'shipping_eur' => 0,
            'total_eur' => 25, 'payment_method' => 'cod', 'payment_status' => 'pending',
        ];
        $cols = array_keys($row);
        $pdo->prepare('INSERT INTO orders (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($row));
        return [$number, (int) $pdo->lastInsertId()];
    }

    public static function viewers(): array
    {
        return ['a stranger' => [false], 'the buyer' => [true]];
    }

    // One render per process: a page can only be required once (it declares functions).

    #[RunInSeparateProcess]
    #[DataProvider('viewers')]
    public function testShopConfirmationShowsItemsAndAddressOnlyToTheBuyer(bool $owned): void
    {
        $pdo = self::pdoOrSkip($this);
        [$number, $id] = self::insertOrder($pdo, 'physical', [
            'items' => json_encode([['name_bg' => 'Тайна книга', 'quantity' => 1, 'subtotal_eur' => 25]]),
            'delivery_type' => 'address', 'courier' => 'speedy',
            'delivery_address' => 'ул. Тайна 7', 'delivery_city' => 'Пловдив',
        ]);
        try {
            $html = self::render('checkout/confirmation', 'order', $number, $owned);
            $this->assertStringContainsString($number, $html, 'the number and thank-you always show');
            foreach (['ул. Тайна 7', 'Пловдив', 'Тайна книга', '"purchase"'] as $detail) {
                $owned ? $this->assertStringContainsString($detail, $html, "the buyer sees: $detail")
                       : $this->assertStringNotContainsString($detail, $html, "a stranger must not see: $detail");
            }
        } finally {
            $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
        }
    }

    #[RunInSeparateProcess]
    #[DataProvider('viewers')]
    public function testDonationConfirmationShowsAmountAndMessageOnlyToTheDonor(bool $owned): void
    {
        $pdo = self::pdoOrSkip($this);
        if (!feature_enabled('donations')) $this->markTestSkipped('Donations are off on this install.');
        [$number, $id] = self::insertOrder($pdo, 'donation', [
            'total_eur' => 77.5, 'donation_message' => 'Лично послание за детето', 'payment_status' => 'paid',
        ]);
        try {
            $html = self::render('donation/confirmation', 'order', $number, $owned);
            $this->assertStringContainsString($number, $html);
            foreach (['77.50', 'Лично послание', '"purchase"'] as $detail) {
                $owned ? $this->assertStringContainsString($detail, $html, "the donor sees: $detail")
                       : $this->assertStringNotContainsString($detail, $html, "a stranger must not see: $detail");
            }
        } finally {
            $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
        }
    }

    #[RunInSeparateProcess]
    #[DataProvider('viewers')]
    public function testCampaignConfirmationShowsEmailOnlyToTheBacker(bool $owned): void
    {
        $pdo = self::pdoOrSkip($this);
        if (!feature_enabled('campaign')) $this->markTestSkipped('The campaign module is off on this install.');
        $number = sprintf('CP-20000101-%04X', random_int(0, 0xFFFF));
        $pdo->prepare("INSERT INTO campaign_pledges (pledge_number, name, email, amount_eur, payment_status) VALUES (?, ?, ?, ?, 'paid')")
            ->execute([$number, 'Тест Дарител', 'backer-secret@example.test', 30]);
        $id = (int) $pdo->lastInsertId();
        try {
            $html = self::render('campaign/confirmation', 'pledge', $number, $owned);
            foreach (['backer-secret@example.test', '30.00'] as $detail) {
                $owned ? $this->assertStringContainsString($detail, $html, "the backer sees: $detail")
                       : $this->assertStringNotContainsString($detail, $html, "a stranger must not see: $detail");
            }
        } finally {
            $pdo->prepare('DELETE FROM campaign_pledges WHERE id = ?')->execute([$id]);
        }
    }
}

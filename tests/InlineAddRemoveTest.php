<?php
// tests/InlineAddRemoveTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The on-page add (+) and remove (×) buttons: admin/inline-add.php (a form POST)
 * and admin/inline-remove.php (a JSON body), each run for real via
 * run_admin_page(). Every file they touch is put back byte for byte afterwards —
 * impact, centres and partners are in git; pages.json and articles are not, so
 * the tests write their own.
 */
#[Group('admin')]
final class InlineAddRemoveTest extends TestCase
{
    private const SLUG = 'pw-inline-remove';

    /** Raw bytes of each file before the test, or null if it did not exist. */
    private array $backup = [];
    private ?int $productId = null;

    private function articlePath(string $lang): string { return ARTICLES_PATH . "/$lang/" . self::SLUG . '.json'; }

    protected function setUp(): void
    {
        $files = [CONTENT_PATH . '/pages.json', PARTNERS_FILE, IMPACT_FILE, CENTRES_FILE, $this->articlePath('bg'), $this->articlePath('en')];
        foreach ($files as $path) $this->backup[$path] = is_file($path) ? file_get_contents($path) : null;

        save_json(CONTENT_PATH . '/pages.json', ['about' => ['team' => [
            ['name' => 'Мария', 'name_en' => 'Maria', 'role' => 'Директор', 'role_en' => 'Director', 'photo' => ''],
            ['name' => 'Иван', 'name_en' => 'Ivan', 'role' => 'Доброволец', 'role_en' => 'Volunteer', 'photo' => ''],
        ]]]);
        save_json(IMPACT_FILE, [['number' => '10', 'label_bg' => 'деца', 'label_en' => 'children'],
                                ['number' => '3', 'label_bg' => 'центъра', 'label_en' => 'centres']]);
    }

    protected function tearDown(): void
    {
        foreach ($this->backup as $path => $bytes) {
            if ($bytes !== null) file_put_contents($path, $bytes);
            elseif (is_file($path)) unlink($path);
        }
        if ($this->productId !== null) {
            get_pdo()->prepare('DELETE FROM products WHERE id = ?')->execute([$this->productId]);
        }
    }

    private function add(array $post, array $opts = []): array
    {
        $r = run_admin_page('admin/inline-add.php', $post, $opts);
        $r['json'] = json_decode(trim($r['body']), true) ?? [];
        return $r;
    }

    private function remove(string $type, string|int $id, array $opts = []): array
    {
        $r = run_admin_page('admin/inline-remove.php', [], $opts + ['json' => ['type' => $type, 'id' => $id]]);
        $r['json'] = json_decode(trim($r['body']), true) ?? [];
        return $r;
    }

    // ── Who may add or remove ────────────────────────────────────────────────

    public function test_logged_out_visitor_can_neither_add_nor_remove(): void
    {
        $before = file_get_contents(IMPACT_FILE);
        $this->assertSame(302, $this->add(['type' => 'impact', 'number' => '1'], ['role' => null])['status']);
        $this->assertSame(302, $this->remove('impact', 0, ['role' => null])['status']);
        $this->assertSame($before, file_get_contents(IMPACT_FILE));
    }

    public function test_missing_csrf_token_changes_nothing(): void
    {
        $before = file_get_contents(IMPACT_FILE);
        $this->assertSame('csrf', $this->add(['type' => 'impact', 'number' => '1'], ['csrf' => false])['json']['error'] ?? null);
        $this->assertSame('csrf', $this->remove('impact', 0, ['csrf' => false])['json']['error'] ?? null);
        $this->assertSame($before, file_get_contents(IMPACT_FILE));
    }

    public function test_unknown_type_is_refused(): void
    {
        $this->assertSame('unknown type', $this->add(['type' => 'unknown_type'])['json']['error'] ?? null);
        $this->assertSame('unknown type', $this->remove('unknown_type', 0)['json']['error'] ?? null);
    }

    // ── Add ──────────────────────────────────────────────────────────────────

    public function test_add_impact_item_appends_it_without_tags(): void
    {
        $r = $this->add(['type' => 'impact', 'number' => ' 999 ', 'label_bg' => '<b>Тест</b>', 'label_en' => 'Test']);
        $this->assertSame(['ok' => true], $r['json'], $r['body']);

        $items = load_json(IMPACT_FILE);
        $this->assertCount(3, $items);
        $this->assertSame(['number' => '999', 'label_bg' => 'Тест', 'label_en' => 'Test'], end($items));
    }

    public function test_add_centre_and_team_member(): void
    {
        $centres = count(load_json(CENTRES_FILE));
        $this->add(['type' => 'centre', 'name_bg' => 'Тест център', 'name_en' => 'Test Centre',
                    'description_bg' => 'Описание', 'description_en' => 'Description']);
        $last = load_json(CENTRES_FILE);
        $this->assertCount($centres + 1, $last);
        $this->assertSame(['Тест център', 'Test Centre', true], [end($last)['name_bg'], end($last)['name_en'], end($last)['active']]);

        $this->add(['type' => 'team', 'name_bg' => 'Петя', 'name_en' => 'Petya', 'role_bg' => 'Касиер', 'role_en' => 'Treasurer']);
        $team = load_json(CONTENT_PATH . '/pages.json')['about']['team'];
        $this->assertCount(3, $team);
        $this->assertSame(['Петя', 'Treasurer'], [$team[2]['name'], $team[2]['role_en']]);
    }

    public function test_partner_link_must_be_a_web_address(): void
    {
        $this->add(['type' => 'partner', 'name' => 'Добър', 'url' => 'https://example.com']);
        $this->add(['type' => 'partner', 'name' => 'Лош', 'url' => 'javascript:alert(1)']);
        $partners = load_json(PARTNERS_FILE);
        [$good, $bad] = array_slice($partners, -2);
        $this->assertSame(['Добър', 'https://example.com'], [$good['name'], $good['url']]);
        $this->assertSame(['Лош', ''], [$bad['name'], $bad['url']]);
    }

    // ── Remove ───────────────────────────────────────────────────────────────

    public function test_remove_takes_out_exactly_the_chosen_item(): void
    {
        $this->assertSame(['ok' => true], $this->remove('team', 0)['json']);
        $team = load_json(CONTENT_PATH . '/pages.json')['about']['team'];
        $this->assertSame(['Иван'], array_column($team, 'name'));

        $this->remove('impact', 1);
        $this->assertSame(['10'], array_column(load_json(IMPACT_FILE), 'number'));
    }

    public function test_remove_with_an_index_that_does_not_exist_changes_nothing(): void
    {
        $before = file_get_contents(IMPACT_FILE);
        $this->assertSame(['ok' => true], $this->remove('impact', 99)['json']);
        $this->assertSame($before, file_get_contents(IMPACT_FILE));
    }

    public function test_remove_article_deletes_both_languages_and_cannot_leave_the_folder(): void
    {
        foreach (['bg', 'en'] as $lang) {
            if (!is_dir(dirname($this->articlePath($lang)))) mkdir(dirname($this->articlePath($lang)), 0755, true);
            save_json($this->articlePath($lang), ['title' => 'x', 'status' => 'draft']);
        }

        $this->remove('article', '../../pages');
        $this->assertFileExists(CONTENT_PATH . '/pages.json', 'a slug with ../ must not reach other content');

        $this->remove('article', self::SLUG);
        $this->assertFileDoesNotExist($this->articlePath('bg'));
        $this->assertFileDoesNotExist($this->articlePath('en'));
    }

    public function test_remove_product_hides_it_rather_than_deleting_it(): void
    {
        if (!test_db_available()) $this->markTestSkipped('DB not available.');
        $pdo = get_pdo();
        $pdo->prepare('INSERT INTO products (slug, name_bg, name_en, price_eur, stock, active) VALUES (?,?,?,?,?,?)')
            ->execute(['pw-inline-remove-' . bin2hex(random_bytes(3)), 'Тест', 'Test', 5, 1, 1]);
        $this->productId = (int) $pdo->lastInsertId();

        $this->assertSame(['ok' => true], $this->remove('product', $this->productId)['json']);
        $row = $pdo->prepare('SELECT active FROM products WHERE id = ?');
        $row->execute([$this->productId]);
        $this->assertSame(0, (int) $row->fetchColumn());
    }

    /** Authors may remove articles, but products belong to whoever manages the shop. */
    public function test_only_shop_managers_can_remove_a_product(): void
    {
        if (!test_db_available()) $this->markTestSkipped('DB not available.');
        $pdo = get_pdo();
        $pdo->prepare('INSERT INTO products (slug, name_bg, name_en, price_eur, stock, active) VALUES (?,?,?,?,?,?)')
            ->execute(['pw-inline-remove-' . bin2hex(random_bytes(3)), 'Тест', 'Test', 5, 1, 1]);
        $this->productId = (int) $pdo->lastInsertId();
        $active = function () use ($pdo): int {
            $row = $pdo->prepare('SELECT active FROM products WHERE id = ?');
            $row->execute([$this->productId]);
            return (int) $row->fetchColumn();
        };

        $r = $this->remove('product', $this->productId, ['role' => 'author']);
        $this->assertSame(403, $r['status']);
        $this->assertFalse($r['json']['ok'] ?? true);
        $this->assertSame(1, $active(), 'an author must not hide a product');

        $this->assertSame(['ok' => true], $this->remove('product', $this->productId, ['role' => 'shop_admin'])['json']);
        $this->assertSame(0, $active());
    }

    public function test_author_can_still_remove_an_article(): void
    {
        foreach (['bg', 'en'] as $lang) {
            if (!is_dir(dirname($this->articlePath($lang)))) mkdir(dirname($this->articlePath($lang)), 0755, true);
            save_json($this->articlePath($lang), ['title' => 'x', 'status' => 'draft']);
        }
        $this->assertSame(['ok' => true], $this->remove('article', self::SLUG, ['role' => 'author'])['json']);
        $this->assertFileDoesNotExist($this->articlePath('bg'));
    }
}

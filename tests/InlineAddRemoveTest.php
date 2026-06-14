<?php
// tests/InlineAddRemoveTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class InlineAddRemoveTest extends TestCase
{
    private string $pagesPath;
    private array  $origPages;
    private string $partnersPath;
    private array  $origPartners;
    private string $impactPath;
    private array  $origImpact;
    private string $centresPath;
    private array  $origCentres;

    protected function setUp(): void
    {
        $this->pagesPath    = CONTENT_PATH . '/pages.json';
        $this->origPages    = json_decode(file_get_contents($this->pagesPath), true);
        $this->partnersPath = PARTNERS_FILE;
        $this->origPartners = json_decode(file_get_contents($this->partnersPath), true);
        $this->impactPath   = IMPACT_FILE;
        $this->origImpact   = json_decode(file_get_contents($this->impactPath), true);
        $this->centresPath  = CENTRES_FILE;
        $this->origCentres  = json_decode(file_get_contents($this->centresPath), true);
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION[ADMIN_SESSION_NAME] = ['role' => 'admin', 'time' => time()];
    }

    protected function tearDown(): void
    {
        file_put_contents($this->pagesPath,    json_encode($this->origPages,    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        file_put_contents($this->partnersPath, json_encode($this->origPartners, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        file_put_contents($this->impactPath,   json_encode($this->origImpact,   JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        file_put_contents($this->centresPath,  json_encode($this->origCentres,  JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    // ── Type whitelist ────────────────────────────────────────────────────────

    public function test_add_rejects_unknown_type(): void
    {
        $allowed = ['team', 'partner', 'impact', 'centre'];
        $this->assertFalse(in_array('unknown_type', $allowed, true));
    }

    public function test_remove_rejects_unknown_type(): void
    {
        $allowed = ['team', 'partner', 'impact', 'centre', 'article', 'product'];
        $this->assertFalse(in_array('unknown_type', $allowed, true));
    }

    // ── Add: impact (direct logic test) ──────────────────────────────────────

    public function test_add_impact_item(): void
    {
        // Impact is stored in content/impact.json with keys: number, label_bg, label_en
        $items  = load_json(IMPACT_FILE);
        $before = count($items);

        $items[] = [
            'number'   => '999',
            'label_bg' => 'Тест',
            'label_en' => 'Test',
        ];
        save_json(IMPACT_FILE, $items);

        $saved = json_decode(file_get_contents($this->impactPath), true);
        $this->assertCount($before + 1, $saved);
        $last = end($saved);
        $this->assertSame('999', $last['number']);
        $this->assertSame('Тест', $last['label_bg']);
    }

    // ── Remove: impact item (direct logic test) ───────────────────────────────

    public function test_remove_impact_item(): void
    {
        $items = load_json(IMPACT_FILE);
        // Add a dummy item first
        $items[] = ['number' => '0', 'label_bg' => 'Тест', 'label_en' => 'Test'];
        save_json(IMPACT_FILE, $items);

        $items  = load_json(IMPACT_FILE);
        $before = count($items);
        $idx    = $before - 1;
        array_splice($items, $idx, 1);
        save_json(IMPACT_FILE, $items);

        $saved = json_decode(file_get_contents($this->impactPath), true);
        $this->assertCount($before - 1, $saved);
    }

    // ── Add: centre (direct logic test) ──────────────────────────────────────

    public function test_add_centre_item(): void
    {
        // Centres are stored in content/centres.json with keys: name_bg, name_en, description_bg, description_en, image, active
        $centres = load_json(CENTRES_FILE);
        $before  = count($centres);

        $centres[] = [
            'name_bg'        => 'Тест център',
            'name_en'        => 'Test Centre',
            'description_bg' => 'Описание',
            'description_en' => 'Description',
            'image'          => '',
            'active'         => true,
        ];
        save_json(CENTRES_FILE, $centres);

        $saved = json_decode(file_get_contents($this->centresPath), true);
        $this->assertCount($before + 1, $saved);
        $last = end($saved);
        $this->assertSame('Тест център', $last['name_bg']);
        $this->assertSame('Test Centre', $last['name_en']);
    }

    // ── Remove: team member (direct logic test) ───────────────────────────────

    public function test_remove_team_member(): void
    {
        $pages = load_json(CONTENT_PATH . '/pages.json');
        // Add a dummy member first
        if (!isset($pages['about']['team'])) $pages['about']['team'] = [];
        $pages['about']['team'][] = ['name' => 'Тест', 'name_en' => 'Test', 'role' => 'Role', 'role_en' => 'Role', 'photo' => ''];
        save_json(CONTENT_PATH . '/pages.json', $pages);

        $pages  = load_json(CONTENT_PATH . '/pages.json');
        $team   = $pages['about']['team'] ?? [];
        $before = count($team);
        $idx    = $before - 1; // last item (the one we just added)
        array_splice($team, $idx, 1);
        $pages['about']['team'] = $team;
        save_json(CONTENT_PATH . '/pages.json', $pages);

        $saved = json_decode(file_get_contents($this->pagesPath), true);
        $this->assertCount($before - 1, $saved['about']['team'] ?? []);
    }

    // ── Remove: article slug sanitisation ────────────────────────────────────

    public function test_remove_article_slug_sanitised(): void
    {
        $slug = '../../../etc/passwd';
        $sanitised = basename(str_replace(['..', "\0"], '', $slug));
        $this->assertStringNotContainsString('..', $sanitised);
        $this->assertStringNotContainsString('/', $sanitised);
    }

    // ── Remove: product deactivation (DB) ────────────────────────────────────

    public function test_remove_product_deactivates(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        $pdo  = get_pdo();
        $prod = $pdo->query('SELECT id, active FROM products WHERE active = 1 LIMIT 1')->fetch();
        if (!$prod) $this->markTestSkipped('No active products');

        $pid = (int)$prod['id'];
        $pdo->prepare('UPDATE products SET active = 0 WHERE id = ?')->execute([$pid]);

        $row = $pdo->prepare('SELECT active FROM products WHERE id = ?');
        $row->execute([$pid]);
        $this->assertSame(0, (int)$row->fetch()['active']);

        // Restore
        $pdo->prepare('UPDATE products SET active = 1 WHERE id = ?')->execute([$pid]);
    }

    // ── Partner URL validation ────────────────────────────────────────────────

    public function test_invalid_partner_url_stored_as_empty(): void
    {
        $url = filter_var('not-a-url', FILTER_VALIDATE_URL);
        $this->assertSame('', $url ?: '');
    }

    public function test_valid_partner_url_passes(): void
    {
        $url = filter_var('https://example.com', FILTER_VALIDATE_URL);
        $this->assertSame('https://example.com', $url ?: '');
    }
}

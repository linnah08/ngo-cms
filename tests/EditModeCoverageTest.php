<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/TestServer.php';

/**
 * Everything a visitor reads on a public page can be changed by an admin, and Edit
 * mode shows how: either the text is editable on the page (data-cms-field — te(),
 * the block editor, menus…) or it is marked with cms_admin(), which says where in
 * the admin it is changed.
 *
 * Fetches each page from a throwaway server for this checkout and lists every
 * visible piece of text that is neither. A new page or a new line of wording that
 * fails here: print it with te() (config.php), or mark data from the admin with
 * cms_admin(). Only text that really can't be either goes in EXEMPT, with the reason.
 */
#[Group('http')]
final class EditModeCoverageTest extends TestCase
{
    private static string $base = '';
    private static bool $aboutExisted = true;
    private static bool $pagesDirExisted = true;

    /**
     * [path regex, text regex, why it can't be edited or pointed to]. Keep it short.
     */
    private const EXEMPT = [
        ['#.#', '/^(BG|EN|Admin|← Admin|Edit mode (on|off))$/u', 'the admin bar itself'],
        ['#.#', '/^©$/u', 'the copyright sign before the year'],
        ['#.#', '/^:$/u', 'punctuation between a label and its value'],
    ];

    /** Content the pages only show when it exists: one of each, put back afterwards. */
    private const SLUG = 'edit-mode-coverage-test';
    private static ?string $pagesJson = null;
    private static int $productId = 0;
    private static int $eventId = 0;

    private static function aboutFile(): string { return CONTENT_PATH . '/pages/p_000about.json'; }

    public static function setUpBeforeClass(): void
    {
        self::$pagesDirExisted = is_dir(dirname(self::aboutFile()));
        self::$aboutExisted    = is_file(self::aboutFile());
        self::seed();
        self::$base = TestServer::start();
        if (self::$base === '') self::markTestSkipped('Could not start a local test server.');
    }

    private static function seed(): void
    {
        foreach (['bg', 'en'] as $l) {
            @mkdir(ARTICLES_PATH . "/$l", 0755, true);
            file_put_contents(ARTICLES_PATH . "/$l/" . self::SLUG . '.json', json_encode([
                'title' => "Статия $l", 'status' => 'published', 'date' => '2026-01-02', 'author' => 'Автор',
                'excerpt' => 'Откъс', 'content' => '<p>Текст</p>', 'sources' => ['Източник 1'],
            ], JSON_UNESCAPED_UNICODE));
        }
        $file = CONTENT_PATH . '/pages.json';
        self::$pagesJson = is_file($file) ? (string) file_get_contents($file) : '';
        $pages = self::$pagesJson !== '' ? (json_decode(self::$pagesJson, true) ?: []) : [];
        $pages['projects'] = [['title' => 'Проект', 'title_en' => 'Project', 'text' => '<p>За проекта</p>', 'text_en' => '<p>About it</p>', 'images' => []]];
        $pages['how_to_help']['ways'] = [['title' => 'Стани доброволец', 'title_en' => 'Become a volunteer', 'text' => '<p>Как</p>', 'text_en' => '<p>How</p>']];
        save_json($file, $pages);
        try {
            $pdo = get_pdo();
            $pdo->prepare('DELETE FROM products WHERE slug = ?')->execute([self::SLUG]);
            $pdo->prepare('INSERT INTO products (slug, name_bg, name_en, description_bg, description_en, price_eur, stock, active) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([self::SLUG, 'Продукт', 'Product', 'Описание', 'Description', 5, 3, 1]);
            self::$productId = (int) $pdo->lastInsertId();
            require_once ROOT_PATH . '/includes/events.php';
            $pdo->prepare('DELETE FROM events WHERE slug = ?')->execute([self::SLUG]);
            self::$eventId = event_save($pdo, event_validate([
                'title' => 'Edit mode coverage test', 'title_en' => 'Edit mode coverage test', 'event_date' => date('Y-m-d', strtotime('+30 days')),
                'event_time' => '19:00', 'place' => 'Място', 'place_en' => 'Place', 'description' => '<p>Описание</p>',
                'description_en' => '<p>Description</p>', 'fb_url' => 'https://facebook.com/events/1', 'price_eur' => '10',
                'capacity' => '50', 'published' => '1', 'sales_open' => '1',
            ])['values']);
        } catch (Throwable $e) {
            // No database here: the pages that need it are skipped by their 404.
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (['bg', 'en'] as $l) @unlink(ARTICLES_PATH . "/$l/" . self::SLUG . '.json');
        if (self::$pagesJson !== null) {
            if (self::$pagesJson === '') @unlink(CONTENT_PATH . '/pages.json');
            else file_put_contents(CONTENT_PATH . '/pages.json', self::$pagesJson);
        }
        try {
            if (self::$productId) get_pdo()->prepare('DELETE FROM products WHERE id = ?')->execute([self::$productId]);
            if (self::$eventId) get_pdo()->prepare('DELETE FROM events WHERE id = ?')->execute([self::$eventId]);
        } catch (Throwable $e) {}
        TestServer::stop();
        if (!self::$aboutExisted) {
            @unlink(self::aboutFile());
            @unlink(self::aboutFile() . '.lock');
        }
        if (!self::$pagesDirExisted) {
            @unlink(dirname(self::aboutFile()) . '/.htaccess');
            @rmdir(dirname(self::aboutFile()));
        }
    }

    /** Every public page a visitor reaches from the menus and links, BG and EN. */
    public static function pages(): array
    {
        $paths = [
            '/', '/za-nas/', '/proekti/', '/kak-da-pomogna/', '/kontakti/', '/magazin/', '/donation/',
            '/campaign/', '/novini/', '/finansovi-otcheti/', '/sabitiya/',
            '/politika-za-poveritelnost/', '/politika-za-biskvitki/', '/usloviya/', '/pravna-informaciya/',
            '/en/', '/en/about/', '/en/projects/', '/en/how-to-help/', '/en/contacts/', '/en/shop/', '/en/donation/',
            '/en/campaign/', '/en/news/', '/en/financial-reports/', '/en/events/',
            '/en/privacy-policy/', '/en/cookie-policy/', '/en/terms/', '/en/legal/',
            // One of each detail page, from the content seeded above.
            '/novini/' . self::SLUG . '/', '/en/news/' . self::SLUG . '/',
            '/magazin/' . self::SLUG . '/', '/en/shop/' . self::SLUG . '/',
            '/sabitiya/' . self::SLUG . '/', '/en/events/' . self::SLUG . '/',
        ];
        return array_combine($paths, array_map(fn($p) => [$p], $paths));
    }

    #[DataProvider('pages')]
    public function test_every_visible_text_can_be_changed_from_edit_mode(string $path): void
    {
        [$code, $html] = self::fetch($path);
        if ($code === 404) $this->markTestSkipped("$path is off on this site (its module is switched off).");
        $this->assertSame(200, $code, "$path returned HTTP $code");

        $missing = self::uncovered($html, $path);
        $this->assertSame([], $missing, "$path shows text an admin can't reach from Edit mode — print it with te() "
            . "or mark it with cms_admin():\n  " . implode("\n  ", $missing));
    }

    /** @return array{int, string} */
    private static function fetch(string $path): array
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15]);
        $html = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $html];
    }

    /** The visible texts of the page that are neither editable nor marked: "<tag> text". */
    public static function uncovered(string $html, string $path): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $skip = "ancestor-or-self::*[self::script or self::style or self::noscript or self::template or self::svg"
              . " or self::option or self::select or self::textarea or self::title or self::head"
              . " or @hidden or @aria-hidden='true'"
              . " or contains(concat(' ', normalize-space(@class), ' '), ' sr-only ') or contains(@style, 'clip:rect(0')"
              . " or @data-cms-field or @data-cms-admin or @id='om-admin-bar' or @id='om-add-modal' or @id='om-save-btn'"
              // Messages of a form's own result, shown after a visit, not standing page text.
              . " or @role='alert' or @data-flash]";
        $out = [];
        foreach ($xp->query("//body//text()[not($skip)]") as $t) {
            $text = trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $t->nodeValue)));
            if ($text === '' || !preg_match('/\p{L}/u', $text)) continue;
            foreach (self::EXEMPT as [$p, $re]) {
                if (preg_match($p, $path) && preg_match($re, $text)) continue 2;
            }
            $line = '<' . $t->parentNode->nodeName . '> ' . mb_substr($text, 0, 90);
            $out[$line] = $line;
        }
        return array_values($out);
    }
}

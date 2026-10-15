<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The optional Latin article addresses (Админ → Организация) and the record of old
 * addresses (`slug_legacy`) that lets the news pages 301 a renamed article.
 */
#[Group('admin')]
final class ArticleSlugsLatinTest extends TestCase
{
    private string $dir = '';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/articles.php';
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ngo_legacy_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) unlink($f);
        @rmdir($this->dir);
    }

    // ── article_compute_slugs with $latin ─────────────────────────────────────

    public function testLatinOffKeepsCyrillicForANewArticle(): void
    {
        $s = article_compute_slugs('', '', 'Помощ за семейства', '', '', false);
        $this->assertSame('помощ-за-семейства', $s['bg']);
    }

    public function testLatinOnTransliteratesANewArticleFromItsTitle(): void
    {
        $s = article_compute_slugs('', '', 'Помощ за семейства', '', '', true);
        $this->assertSame('pomosht-za-semeystva', $s['bg']);
    }

    public function testLatinOnTransliteratesATypedAddress(): void
    {
        $s = article_compute_slugs('Нова Година!', '', 'Заглавие', '', '', true);
        $this->assertSame('nova-godina', $s['bg']);
    }

    public function testLatinOnLeavesAnExistingCyrillicAddressAloneWhenUnchanged(): void
    {
        // Switching the setting on must never move an article by itself.
        $s = article_compute_slugs('стара-статия', '', 'Стара статия', '', '', true, 'стара-статия');
        $this->assertSame('стара-статия', $s['bg']);
    }

    public function testLatinOnTransliteratesAnExistingArticleWhoseAddressWasCleared(): void
    {
        $s = article_compute_slugs('', '', 'Стара статия', '', '', true, 'стара-статия');
        $this->assertSame('stara-statiya', $s['bg']);
    }

    public function testLatinOnAlsoGivesTheEnglishFallbackALatinAddress(): void
    {
        $s = article_compute_slugs('', '', 'Помощ', '', '', true);
        $this->assertSame('pomosht', $s['en']);
    }

    // ── the setting ───────────────────────────────────────────────────────────

    public function testSettingIsOffWhenTheConstantIsMissing(): void
    {
        if (defined('ARTICLE_SLUGS_LATIN')) $this->markTestSkipped('ARTICLE_SLUGS_LATIN is set on this checkout.');
        $this->assertFalse(article_slugs_latin());
    }

    public function testSettingIsAnOrganisationField(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/organisation.php';
        $this->assertSame('ARTICLE_SLUGS_LATIN', org_fields()['article_slugs_latin'] ?? null);
        $form = file_get_contents(dirname(__DIR__, 2) . '/admin/organisation.php');
        $this->assertStringContainsString('name="article_slugs_latin"', $form);
    }

    public function testAnUntickedBoxIsSavedAsAnExplicitOff(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/organisation.php';
        $on  = org_validate(['site_name_bg' => 'X', 'site_name_en' => 'X', 'article_slugs_latin' => '1'], array_keys(brand_themes()));
        $off = org_validate(['site_name_bg' => 'X', 'site_name_en' => 'X'], array_keys(brand_themes()));
        $this->assertSame('1', $on['values']['article_slugs_latin']);
        $this->assertSame('0', $off['values']['article_slugs_latin']);
    }

    // ── slug_legacy bookkeeping ───────────────────────────────────────────────

    public function testRenameRecordsTheOldAddress(): void
    {
        $this->assertSame('старо', article_retire_slug([], 'старо', 'staro'));
    }

    public function testUnchangedAddressKeepsWhatWasRecorded(): void
    {
        $this->assertSame('старо', article_retire_slug(['slug_legacy' => 'старо'], 'staro', 'staro'));
        $this->assertSame('', article_retire_slug([], 'staro', 'staro'));
    }

    public function testSecondRenameKeepsBothOldAddresses(): void
    {
        $this->assertSame(['старо', 'staro'], article_retire_slug(['slug_legacy' => 'старо'], 'staro', 'novo'));
    }

    public function testRenamingBackToAnOldAddressMakesItCurrentAgain(): void
    {
        $this->assertSame(['staro', 'novo'], article_retire_slug(['slug_legacy' => ['старо', 'staro']], 'novo', 'старо'));
    }

    public function testBuildersStoreTheOldAddresses(): void
    {
        $f = ['title' => 'T', 'slug' => 'novo', 'slug_en' => 'new', 'date' => '2026-01-01', 'author' => 'A',
              'status' => 'published', 'excerpt' => '', 'image' => '', 'tags' => [], 'content' => ''];
        $this->assertSame('старо', article_build_bg_data($f + ['slug_legacy' => 'старо'])['slug_legacy']);
        $this->assertSame('old', article_build_en_data($f + ['slug_legacy' => 'old'])['slug_legacy']);
        // No old address: no empty key cluttering the file.
        $this->assertArrayNotHasKey('slug_legacy', article_build_bg_data($f + ['slug_legacy' => '']));
        $this->assertArrayNotHasKey('slug_legacy', article_build_en_data($f));
        // Saved without the field (an older caller): the stored value survives.
        $this->assertSame('старо', article_build_bg_data($f, ['slug_legacy' => 'старо'])['slug_legacy']);
    }

    public function testEditorRecordsRenamesForBothLanguages(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/admin/article-edit.php');
        $this->assertStringContainsString("article_retire_slug(\$article, \$slug_param, \$new_slug)", $src);
        $this->assertStringContainsString("article_retire_slug(\$article_en,", $src);
        $this->assertStringContainsString('article_slugs_latin()', $src);
    }

    // ── article_legacy_slug_target ────────────────────────────────────────────

    public function testOldAddressFindsTheCurrentOne(): void
    {
        file_put_contents($this->dir . '/pomosht.json', json_encode(['title' => 'x', 'slug_legacy' => 'помощ']));
        $this->assertSame('pomosht', article_legacy_slug_target('помощ', 'bg', $this->dir));
    }

    public function testAnyOfSeveralOldAddressesFindsTheCurrentOne(): void
    {
        file_put_contents($this->dir . '/treto.json', json_encode(['slug_legacy' => ['parvo', 'vtoro']]));
        $this->assertSame('treto', article_legacy_slug_target('vtoro', 'bg', $this->dir));
    }

    public function testUnknownAddressFindsNothing(): void
    {
        file_put_contents($this->dir . '/a.json', json_encode(['slug_legacy' => 'b']));
        $this->assertNull(article_legacy_slug_target('never-used', 'bg', $this->dir));
        $this->assertNull(article_legacy_slug_target('', 'bg', $this->dir));
        $this->assertNull(article_legacy_slug_target('x', 'bg', $this->dir . '/missing'));
    }

    public function testAnArticleNeverRedirectsToItself(): void
    {
        file_put_contents($this->dir . '/same.json', json_encode(['slug_legacy' => 'same']));
        $this->assertNull(article_legacy_slug_target('same', 'bg', $this->dir));
    }
}

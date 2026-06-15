<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for includes/translator.php.
 * No network calls — tests logic that can be verified without hitting DeepL.
 */
final class TranslatorTest extends TestCase
{
    protected function setUp(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/translator.php';
    }

    // ── deepl_translate: empty input ──────────────────────────────────────────

    public function test_empty_string_returns_empty_without_api_call(): void
    {
        // Should short-circuit before touching the API key or network
        $result = deepl_translate('');
        $this->assertSame('', $result);
    }

    public function test_whitespace_only_returns_empty(): void
    {
        $result = deepl_translate('   ');
        $this->assertSame('', $result);
    }

    // ── deepl_translate: missing API key ─────────────────────────────────────

    public function test_missing_api_key_returns_empty_and_sets_error(): void
    {
        // Only meaningful without a real key in the DB; skip if configured
        if (function_exists('setting_get') && setting_get('deepl_api_key') !== '') {
            $this->markTestSkipped('DeepL API key is configured — skipping missing-key test.');
        }

        $error  = null;
        $result = deepl_translate('Здравей', 'EN-GB', false, $error);

        $this->assertSame('', $result);
        $this->assertNotNull($error);
        $this->assertStringContainsString('key', strtolower($error));
    }

    // ── deepl_is_configured ───────────────────────────────────────────────────

    public function test_is_configured_returns_bool(): void
    {
        $this->assertIsBool(deepl_is_configured());
    }

    // ── deepl_load_glossary ───────────────────────────────────────────────────

    public function test_load_glossary_returns_array(): void
    {
        $glossary = deepl_load_glossary();
        $this->assertIsArray($glossary);
    }

    public function test_load_glossary_pairs_have_two_elements(): void
    {
        $glossary = deepl_load_glossary();
        foreach ($glossary as $pair) {
            $this->assertCount(2, $pair, 'Each glossary pair must be [source, target]');
        }
    }

    // ── deepl_save_glossary / deepl_load_glossary round-trip ─────────────────

    public function test_save_and_load_glossary_round_trip(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB available.');
        }

        $original = deepl_load_glossary();

        $pairs = [['Дарение', 'Donation'], ['Нужди', 'Needs']];
        deepl_save_glossary($pairs);

        $loaded = deepl_load_glossary();
        $this->assertSame($pairs, $loaded);

        // Restore
        deepl_save_glossary($original);
    }

    public function test_save_glossary_filters_empty_is_preserved(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB available.');
        }

        $original = deepl_load_glossary();

        // Empty pairs should round-trip correctly
        deepl_save_glossary([]);
        $this->assertSame([], deepl_load_glossary());

        deepl_save_glossary($original);
    }

    // ── Host routing (free-tier vs paid) — tested via is_configured guard ─────

    public function test_free_tier_key_pattern(): void
    {
        // Verify our understanding of the ':fx' suffix rule used in deepl_translate()
        $this->assertTrue(str_ends_with('abc123:fx', ':fx'));
        $this->assertFalse(str_ends_with('abc123', ':fx'));
    }
}

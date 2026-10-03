<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** includes/seo.php — canonical/hreflang resolution, meta tags and JSON-LD output. */
final class SeoTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/seo.php';
    }

    private function base(): string
    {
        return rtrim(SITE_URL, '/');
    }

    public static function staticPairs(): array
    {
        $rows = [];
        foreach (seo_static_alt_map_for_provider() as $bg => $en) $rows[$bg] = [$bg, $en];
        return $rows;
    }

    #[DataProvider('staticPairs')]
    public function testStaticPagesResolveBothWays(string $bg, string $en): void
    {
        $want = ['bg' => $this->base() . $bg, 'en' => $this->base() . $en];
        $this->assertSame($want, seo_resolve_alternates($bg));
        $this->assertSame($want, seo_resolve_alternates($en));
    }

    #[DataProvider('staticPairs')]
    public function testStaticAlternatesPointAtRealPages(string $bg, string $en): void
    {
        foreach ([$bg, $en] as $path) {
            $this->assertFileExists(ROOT_PATH . rtrim($path, '/') . '/index.php', "hreflang points at $path, which has no page");
        }
    }

    public function testProductsShareTheirSlugAcrossLanguages(): void
    {
        $want = ['bg' => $this->base() . '/magazin/lafetki/', 'en' => $this->base() . '/en/shop/lafetki/'];
        $this->assertSame($want, seo_resolve_alternates('/magazin/lafetki/'));
        $this->assertSame($want, seo_resolve_alternates('/en/shop/lafetki'));
    }

    public function testExplicitAlternatesWinAndMissingSidesAreDropped(): void
    {
        $this->assertSame(
            ['bg' => $this->base() . '/novini/statia/'],
            seo_resolve_alternates('/novini/statia/', ['bg' => '/novini/statia/', 'en' => null])
        );
    }

    public function testUnknownPathsHaveNoAlternates(): void
    {
        $this->assertSame([], seo_resolve_alternates('/cart/'));
    }

    public function testAbsUrl(): void
    {
        $this->assertSame('', seo_abs_url(''));
        $this->assertSame('https://cdn.example/x.png', seo_abs_url('https://cdn.example/x.png'));
        $this->assertSame($this->base() . '/assets/x.png', seo_abs_url('/assets/x.png'));
        $this->assertSame($this->base() . '/assets/x.png', seo_abs_url('assets/x.png'));
    }

    private function meta(array $ctx): string
    {
        ob_start();
        seo_render_meta($ctx + ['url' => $this->base() . '/', 'title' => 'T']);
        return (string) ob_get_clean();
    }

    public function testMetaEmitsCanonicalHreflangAndXDefault(): void
    {
        $html = $this->meta(['alternates' => seo_resolve_alternates('/za-nas/'), 'lang' => 'en']);
        $this->assertStringContainsString('<link rel="canonical" href="' . $this->base() . '/">', $html);
        $this->assertStringContainsString('hreflang="en" href="' . $this->base() . '/en/about/"', $html);
        $this->assertStringContainsString('hreflang="x-default" href="' . $this->base() . '/za-nas/"', $html);
        $this->assertStringContainsString('og:locale" content="en_US"', $html);
        $this->assertStringContainsString('og:image" content="' . $this->base() . '/assets/images/og-default.png"', $html);
    }

    public function testMetaEscapesTitleAndDescription(): void
    {
        $html = $this->meta(['title' => '"><script>x</script>', 'description' => '<b>"d"</b>']);
        $this->assertStringNotContainsString('<script>x', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
    }

    public function testJsonLdCannotBeBrokenOutOfByReviewText(): void
    {
        ob_start();
        seo_render_jsonld([
            ['@type' => 'Review', 'reviewBody' => '</script><script>alert(1)</script>', 'author' => ['name' => 'A & B <i>']],
            null,
        ]);
        $html = (string) ob_get_clean();

        $this->assertSame(1, substr_count($html, '<script'), 'only the JSON-LD tag itself may open a script');
        $this->assertSame(1, substr_count($html, '</script>'), 'review text must not close the JSON-LD script early');
        preg_match('#<script type="application/ld\+json">(.*)</script>#s', $html, $m);
        $data = json_decode($m[1], true);
        $this->assertSame('</script><script>alert(1)</script>', $data['reviewBody'], 'the escaped JSON must still decode to the original text');
        $this->assertSame('A & B <i>', $data['author']['name']);
    }

    public function testOrgJsonLdIsValid(): void
    {
        $org = seo_org_jsonld();
        $this->assertSame('NGO', $org['@type']);
        $this->assertSame($this->base() . '/', $org['url']);
        foreach ($org['sameAs'] as $u) $this->assertMatchesRegularExpression('#^https://#', $u);
    }
}

function seo_static_alt_map_for_provider(): array
{
    // Data providers run before setUpBeforeClass().
    require_once ROOT_PATH . '/includes/seo.php';
    return seo_static_alt_map();
}

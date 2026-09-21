<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every English text field in the admin must have a way to auto-translate it
 * from its Bulgarian counterpart. A field counts as covered when it either
 *   - carries data-translate-from="<bg field>" (the shared "✦ Translate"
 *     button in admin-footer.php attaches itself), or
 *   - is wired to a page's own translate code (its id, name or a class is
 *     used in a <script> block, an onclick, or a data-tgt attribute).
 */
final class TranslateButtonCoverageTest extends TestCase
{
    /** Fields with no Bulgarian counterpart to translate from, and why. */
    private const EXEMPT = [
        // BG and EN menus are separate lists with their own rows and URLs.
        'menus.php'        => ['label_en', 'url_en'],
        // URL slug, built from the English title — not text to translate.
        'article-edit.php' => ['slug_en'],
    ];

    private const TAG = '/<(input|textarea)\b((?:<\?.*?\?>|[^>])*)>/s';

    public static function adminPages(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__, 2) . '/admin/*.php') as $path) {
            $out[basename($path)] = [$path];
        }
        return $out;
    }

    #[DataProvider('adminPages')]
    public function testEveryEnglishFieldCanBeTranslated(string $path): void
    {
        $src  = file_get_contents($path);
        $file = basename($path);

        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/s', $src, $scripts);
        preg_match_all('/\b(?:onclick|data-tgt)="([^"]*)"/', $src, $attrs);
        $hooks = implode("\n", array_merge($scripts[1], $attrs[1]));

        $missing = [];
        preg_match_all(self::TAG, $src, $tags, PREG_SET_ORDER);
        foreach ($tags as [$whole, $kind, $body]) {
            if (!preg_match('/\bname="([a-z0-9_]+_en)(?:\[[^"]*\])?"/', $body, $m)) continue;
            $name = $m[1];
            if ($kind === 'input' && preg_match('/\btype="(\w+)"/', $body, $t) && $t[1] !== 'text') continue;
            if (in_array($name, self::EXEMPT[$file] ?? [], true)) continue;

            if (preg_match('/\bdata-translate-from="([^"]+)"/', $body, $from)) {
                $this->assertMatchesRegularExpression(
                    '/\b(?:name|id)="' . preg_quote($from[1], '/') . '"/',
                    $src,
                    "$file: $name translates from \"{$from[1]}\", but no field with that name or id exists."
                );
                continue;
            }
            $keys = [$name];
            if (preg_match('/\bid="([^"]+)"/', $body, $i)) $keys[] = $i[1];
            if (preg_match('/\bclass="([^"]+)"/', $body, $c)) {
                array_push($keys, ...preg_split('/\s+/', trim($c[1])));
            }
            foreach ($keys as $key) {
                if (str_contains($hooks, $key)) continue 2;
            }

            $missing[] = $name;
        }

        $this->assertSame(
            [],
            array_values(array_unique($missing)),
            "$file: these English fields have no translate button — add data-translate-from=\"<bg field name>\"."
        );
    }
}

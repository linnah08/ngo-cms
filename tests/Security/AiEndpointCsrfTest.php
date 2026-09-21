<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Admin AJAX endpoints that spend money on an external API must verify CSRF
 * before doing that work — otherwise a forged request against a logged-in
 * admin can burn paid quota.
 */
#[Group('security')]
final class AiEndpointCsrfTest extends TestCase
{
    /** @return array<string, array{0:string,1:string}> */
    public static function aiEndpointProvider(): array
    {
        return [
            'translate-ajax (DeepL)'     => ['admin/translate-ajax.php', 'deepl_translate'],
            'extract-size-dims (Claude)' => ['admin/extract-size-dims.php', 'claude_api_key'],
        ];
    }

    #[DataProvider('aiEndpointProvider')]
    public function testAiEndpointVerifiesCsrf(string $relPath, string $workMarker): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/' . $relPath);
        $csrfPos = strpos($src, 'csrf_verify()');
        $workPos = strpos($src, $workMarker);
        $this->assertNotFalse($csrfPos, "$relPath spends money on an external API and must verify CSRF");
        $this->assertNotFalse($workPos);
        $this->assertLessThan($workPos, $csrfPos, 'CSRF check must precede the API work');
    }

    public function testEveryTranslateCallerSendsTheCsrfToken(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/admin/{,includes/}*.php', GLOB_BRACE) as $path) {
            $src = file_get_contents($path);
            $n = preg_match_all("/fetch\\('\\/admin\\/(?:translate-ajax|extract-size-dims)\\.php'.{0,400}?\\)\\s*;?/s", $src, $calls);
            foreach ($calls[0] as $call) {
                $this->assertStringContainsString('csrf_token', $call, basename($path) . ' calls an AI endpoint without sending csrf_token');
            }
        }
    }
}

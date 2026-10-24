<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * CVE-2026-115560: PHP's session upload progress can lead to code execution when
 * the session serialize handler is switched at runtime. PHP's mitigation until a
 * fixed release is session.upload_progress.enabled = Off, which is PERDIR-only, so
 * it has to live in .user.ini — and no code may switch the handler.
 */
#[Group('security')]
final class SessionUploadProgressTest extends TestCase
{
    public static function iniFiles(): array
    {
        return [
            'root'  => ['.user.ini'],
            'admin' => ['admin/.user.ini'],
            'relay' => ['relay/public/.user.ini'],
        ];
    }

    #[DataProvider('iniFiles')]
    public function testUploadProgressIsOff(string $rel): void
    {
        $ini = parse_ini_file(ROOT_PATH . '/' . $rel, false, INI_SCANNER_RAW);
        $this->assertIsArray($ini, "$rel does not parse");
        $this->assertSame(
            'off',
            strtolower((string) ($ini['session.upload_progress.enabled'] ?? '')),
            "$rel must set session.upload_progress.enabled = Off (CVE-2026-115560)"
        );
    }

    public function testNoCodeSwitchesTheSessionSerializeHandler(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(ROOT_PATH, FilesystemIterator::SKIP_DOTS),
            fn(SplFileInfo $f) => !in_array($f->getFilename(), ['vendor', 'node_modules', 'tests', '.git', '.claude'], true)
        ));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') continue;
            $this->assertDoesNotMatchRegularExpression(
                '/serialize_handler/',
                (string) file_get_contents($file->getPathname()),
                $file->getPathname() . ' touches session.serialize_handler (CVE-2026-115560)'
            );
        }
    }
}

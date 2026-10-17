<?php
declare(strict_types=1);

/**
 * Finds every t_or('key', 'bg default', 'en default', ...) call in the site's
 * PHP (not vendor/, node_modules/ or tests/), so tests can check the defaults
 * against content/{bg,en}/strings.json. te() (the same text, editable on the page)
 * counts too; te('key') with no defaults gives bg/en null — its text is strings.json's.
 *
 * @return list<array{key:string,bg:?string,en:?string,file:string,line:int}>
 */
function t_or_scan(string $root): array
{
    $skip  = ['vendor', 'node_modules', 'tests', '.git', '.claude', 'uploads', 'documents', 'logs', 'backups'];
    $calls = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $f) use ($skip, $root): bool {
                if ($f->isDir()) {
                    return !($f->getPath() === $root && in_array($f->getFilename(), $skip, true));
                }
                return $f->getExtension() === 'php';
            }
        )
    );
    foreach ($it as $file) {
        $path   = $file->getPathname();
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($path)),
            static fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_STRING || !in_array($t[1], ['t_or', 'te'], true)) continue;
            // Skip the definition itself: `function t_or(`.
            $prev = $tokens[$i - 1] ?? null;
            if (is_array($prev) && $prev[0] === T_FUNCTION) continue;
            if (($tokens[$i + 1] ?? null) !== '(') continue;
            $first = $tokens[$i + 2] ?? null;
            if ($t[1] === 'te' && is_array($first) && $first[0] === T_CONSTANT_ENCAPSED_STRING && ($tokens[$i + 3] ?? null) === ')') {
                $calls[] = ['key' => substr($first[1], 1, -1), 'bg' => null, 'en' => null, 'file' => $path, 'line' => $t[2]];
                continue;
            }
            $args = [];
            $j = $i + 2;
            for ($a = 0; $a < 3; $a++) {
                $tok = $tokens[$j] ?? null;
                if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING || $tok[1][0] !== "'") {
                    $args = null;
                    break;
                }
                $args[] = str_replace(['\\\\', "\\'"], ['\\', "'"], substr($tok[1], 1, -1));
                $j += 2; // skip the comma
            }
            $calls[] = $args === null
                ? ['key' => '', 'bg' => '', 'en' => '', 'file' => $path, 'line' => $t[2]]
                : ['key' => $args[0], 'bg' => $args[1], 'en' => $args[2], 'file' => $path, 'line' => $t[2]];
        }
    }
    return $calls;
}

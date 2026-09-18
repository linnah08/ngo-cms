<?php
declare(strict_types=1);

namespace SupportRelay;

/**
 * Append-only JSON-lines log in {data_dir}/logs/relay.log (outside the web root).
 * Never pass support codes, Trello key/token, or ticket free text in $context.
 */
final class Logger
{
    private string $file;

    public function __construct(string $dataDir)
    {
        $dir = rtrim($dataDir, '/') . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $this->file = $dir . '/relay.log';
    }

    public function info(string $message, array $context = []): void  { $this->write('info', $message, $context); }
    public function error(string $message, array $context = []): void { $this->write('error', $message, $context); }

    private function write(string $level, string $message, array $context): void
    {
        $line = json_encode([
            'ts'    => gmdate('c'),
            'level' => $level,
            'msg'   => $message,
            'ctx'   => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        @file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}

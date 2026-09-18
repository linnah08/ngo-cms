<?php
declare(strict_types=1);

namespace SupportRelay;

/**
 * Rolling-window limiter, one small JSON file of timestamps per install hash, guarded by flock.
 */
final class RateLimiter
{
    private string $dir;

    /** @param \Closure():int|null $clock */
    public function __construct(
        string $dataDir,
        private readonly int $limit,
        private readonly int $windowSeconds = 3600,
        private readonly ?\Closure $clock = null,
    ) {
        $this->dir = rtrim($dataDir, '/') . '/ratelimit';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
    }

    /** Records a hit and returns true if allowed; returns false (without recording) if over the limit. */
    public function hit(string $key): bool
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $key)) {
            throw new \InvalidArgumentException('rate-limit key must be a sha256 hex');
        }
        $now = $this->clock ? ($this->clock)() : time();
        $fh = fopen($this->dir . '/' . $key . '.json', 'c+');
        if ($fh === false) {
            throw new \RuntimeException('rate-limit state unwritable');
        }
        try {
            flock($fh, LOCK_EX);
            $hits = json_decode((string) stream_get_contents($fh), true);
            $hits = is_array($hits) ? $hits : [];
            $cutoff = $now - $this->windowSeconds;
            $hits = array_values(array_filter($hits, fn($t) => is_int($t) && $t > $cutoff));
            if (count($hits) >= $this->limit) {
                return false;
            }
            $hits[] = $now;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($hits));
            fflush($fh);
            return true;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}

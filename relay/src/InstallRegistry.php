<?php
declare(strict_types=1);

namespace SupportRelay;

/**
 * installs.json: { "<sha256 hex of support code>": { "name": ..., "trello_list_id": ..., "active": bool, "created_at": ... } }
 * Only hashes are ever stored. Plaintext codes exist only in the CLI output shown once.
 */
final class InstallRegistry
{
    public const CODE_PREFIX = 'ngo_';

    public function __construct(private readonly string $file) {}

    public static function hashCode(string $code): string
    {
        return hash('sha256', $code);
    }

    /** 32 random bytes, base64url, prefixed → e.g. "ngo_Xk3...". */
    public static function generateCode(): string
    {
        return self::CODE_PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Accepts a bare 24-hex Trello id or an ARI like
     * "ari:cloud:trello::list/workspace/<ws>/<objectId>" and returns the 24-hex id, or null.
     */
    public static function normalizeListId(string $input): ?string
    {
        $input = trim($input);
        if (str_starts_with($input, 'ari:')) {
            $parts = explode('/', $input);
            $input = (string) end($parts);
        }
        return preg_match('/^[0-9a-f]{24}$/i', $input) ? strtolower($input) : null;
    }

    /**
     * Resolve a presented support code to its active install.
     * Returns ['hash' => ..., 'name' => ..., 'trello_list_id' => ...] or null (unknown OR inactive —
     * deliberately indistinguishable to the caller).
     */
    public function authenticate(string $code): ?array
    {
        if ($code === '' || strlen($code) > 256) {
            return null;
        }
        $presented = self::hashCode($code);
        $match = null;
        // Walk every entry with hash_equals so timing doesn't depend on where/whether it matches.
        foreach ($this->load() as $hash => $entry) {
            if (hash_equals((string) $hash, $presented)) {
                $match = ['hash' => (string) $hash] + (is_array($entry) ? $entry : []);
            }
        }
        if ($match === null || ($match['active'] ?? false) !== true) {
            return null;
        }
        if (!isset($match['name'], $match['trello_list_id']) || !is_string($match['trello_list_id'])) {
            return null;
        }
        return $match;
    }

    /** @return array<string, array> */
    public function load(): array
    {
        if (!is_file($this->file)) {
            throw new \RuntimeException('installs file missing');
        }
        $fh = fopen($this->file, 'r');
        if ($fh === false) {
            throw new \RuntimeException('installs file unreadable');
        }
        try {
            flock($fh, LOCK_SH);
            $raw = stream_get_contents($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('installs file is not valid JSON');
        }
        return $data;
    }

    /** Adds a new install; returns the plaintext code (the only time it exists). */
    public function add(string $name, string $listId): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Customer name must be 1-100 characters.');
        }
        $normalized = self::normalizeListId($listId);
        if ($normalized === null) {
            throw new \InvalidArgumentException('Trello list id must be a 24-character hex id (or an ARI ending in one).');
        }
        $code = self::generateCode();
        $this->mutate(function (array $data) use ($name, $normalized, $code): array {
            $data[self::hashCode($code)] = [
                'name'           => $name,
                'trello_list_id' => $normalized,
                'active'         => true,
                'created_at'     => gmdate('c'),
            ];
            return $data;
        });
        return $code;
    }

    /**
     * Deactivate by exact (case-insensitive) customer name or by hash prefix (>= 8 hex chars).
     * Refuses if the needle matches more than one install. Returns the deactivated hashes.
     */
    public function deactivate(string $needle): array
    {
        $needle = trim($needle);
        $done = [];
        $this->mutate(function (array $data) use ($needle, &$done): array {
            $matches = [];
            foreach ($data as $hash => $entry) {
                $byName = isset($entry['name']) && mb_strtolower((string) $entry['name']) === mb_strtolower($needle);
                $byHash = strlen($needle) >= 8 && ctype_xdigit($needle) && str_starts_with((string) $hash, strtolower($needle));
                if ($byName || $byHash) {
                    $matches[] = (string) $hash;
                }
            }
            if (count($matches) === 0) {
                throw new \InvalidArgumentException('No install matches that name or hash prefix.');
            }
            if (count($matches) > 1) {
                throw new \InvalidArgumentException('Ambiguous: matches ' . count($matches)
                    . ' installs (' . implode(', ', array_map(fn($h) => substr($h, 0, 12), $matches))
                    . '). Use a longer hash prefix.');
            }
            foreach ($matches as $h) {
                $data[$h]['active'] = false;
                $data[$h]['deactivated_at'] = gmdate('c');
            }
            $done = $matches;
            return $data;
        });
        return $done;
    }

    /** Read-modify-write under an exclusive lock on a sidecar lock file; atomic replace via rename. */
    private function mutate(callable $fn): void
    {
        $lock = fopen($this->file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Could not lock installs file.');
        }
        try {
            $data = is_file($this->file) ? $this->load() : [];
            $data = $fn($data);
            $tmp = $this->file . '.tmp.' . bin2hex(random_bytes(4));
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (file_put_contents($tmp, $json . "\n") === false || !rename($tmp, $this->file)) {
                @unlink($tmp);
                throw new \RuntimeException('Could not write installs file.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

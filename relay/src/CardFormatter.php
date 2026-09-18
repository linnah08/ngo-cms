<?php
declare(strict_types=1);

namespace SupportRelay;

/**
 * Builds the Trello card name + markdown description.
 * Trello's hard limit for desc is 16384 chars; we stay under MAX_DESC with a safety margin.
 */
final class CardFormatter
{
    public const MAX_NAME        = 250;
    public const MAX_DESC        = 16000;
    public const MAX_DIAG_VALUE  = 1000;
    public const MAX_DIAG_DEPTH  = 6;

    public static function name(string $customer, string $subject): string
    {
        $name = '[' . TicketValidator::singleLine($customer) . '] ' . $subject;
        return self::truncate($name, self::MAX_NAME);
    }

    public static function description(array $ticket, string $customer, string $reference, ?int $now = null): string
    {
        $head = $ticket['description'];
        if ($ticket['page'] !== '') {
            $head .= "\n\n**Page:** " . $ticket['page'];
        }
        $meta = "\n\n---\n"
            . '**Customer:** ' . TicketValidator::singleLine($customer) . "  \n"
            . '**Reference:** ' . $reference . "  \n"
            . '**Received:** ' . gmdate('Y-m-d H:i', $now ?? time()) . ' UTC';

        $diag = '';
        if (is_array($ticket['diagnostics']) && $ticket['diagnostics'] !== []) {
            $lines = [];
            self::flatten($ticket['diagnostics'], '', 0, $lines);
            $budget = self::MAX_DESC - mb_strlen($head) - mb_strlen($meta) - 40;
            $diag = self::diagnosticsSection($lines, $budget);
        }

        $out = $head . $meta . $diag;
        // Belt and braces: never exceed the cap regardless of inputs.
        return self::truncate($out, self::MAX_DESC);
    }

    /** @param list<string> $lines */
    private static function diagnosticsSection(array $lines, int $budget): string
    {
        $header = "\n\n---\n**Diagnostics**\n";
        $body = '';
        $used = mb_strlen($header);
        $count = count($lines);
        foreach ($lines as $i => $line) {
            $add = "\n" . $line;
            $remaining = $count - $i;
            $marker = "\n- … (" . $remaining . ' more lines truncated)';
            if ($used + mb_strlen($add) + mb_strlen($marker) > $budget) {
                return $used + mb_strlen($marker) <= $budget ? $header . $body . $marker : '';
            }
            $body .= $add;
            $used += mb_strlen($add);
        }
        return $header . $body;
    }

    /** Pretty "- a.b.c: value" lines. */
    private static function flatten(array $data, string $prefix, int $depth, array &$lines): void
    {
        foreach ($data as $k => $v) {
            $key = TicketValidator::singleLine($prefix === '' ? (string) $k : $prefix . '.' . $k);
            if (is_array($v) && $v !== [] && $depth < self::MAX_DIAG_DEPTH) {
                self::flatten($v, $key, $depth + 1, $lines);
                continue;
            }
            $lines[] = '- ' . $key . ': ' . self::scalar($v);
        }
    }

    private static function scalar(mixed $v): string
    {
        $s = match (true) {
            is_bool($v)  => $v ? 'true' : 'false',
            $v === null  => 'null',
            is_array($v) => (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default      => (string) $v,
        };
        return self::truncate(TicketValidator::singleLine($s), self::MAX_DIAG_VALUE);
    }

    public static function truncate(string $s, int $max): string
    {
        return mb_strlen($s) <= $max ? $s : mb_substr($s, 0, $max - 1) . '…';
    }
}

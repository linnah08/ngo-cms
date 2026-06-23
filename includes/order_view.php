<?php
/**
 * Pure, testable read-side helpers for admin/order-view.php.
 *
 * order-view.php is mostly side-effect orchestration (refunds, courier labels,
 * emails) that is neither safe nor useful to pull into pure functions. What IS
 * pure is the presentation/derivation logic that was inlined into the view: the
 * print placement → cm measurement maths, ticket-path decoding and donation
 * detection. Those live here so they can be unit-tested; the page keeps the I/O.
 */

/**
 * Decode a pledge's stored ticket_path into a list of relative PDF paths.
 * The column holds either a JSON array of paths or a single bare path.
 */
function order_ticket_paths(?string $raw): array {
    if ($raw === null || $raw === '') return [];
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) return $decoded;
    return [$raw];
}

/** True if the order is a donation, or any line item is a donation. */
function order_has_donation(string $type, array $items): bool {
    if ($type === 'donation') return true;
    foreach ($items as $i) {
        if (($i['type'] ?? '') === 'donation') return true;
    }
    return false;
}

/**
 * Human-readable placement spec for a printed design.
 *
 * Given the design placement ($pos: scale, x, y in 0..1 of the print area), the
 * design's aspect ratio ($ar = height/width) and the ordered size's real-world
 * dimensions ($sdims: w, h in cm), return the printed size and offsets in cm —
 * e.g. "18 × 24 cm · 6 cm от ляво · 9 cm от горе · Размер: L". When the size has
 * no stored dimensions, return the prompt to add them. Mirrors the calc that was
 * inlined in the item-row view.
 *
 * @param array      $pos          ['scale'=>float,'x'=>float,'y'=>float]
 * @param float      $ar           design aspect ratio (height / width)
 * @param array|null $sdims        ['w'=>float,'h'=>float] in cm, or null
 * @param string     $ordered_size e.g. 'L' (for the trailing label)
 */
function order_print_spec(array $pos, float $ar, ?array $sdims, string $ordered_size): string {
    $size_suffix = $ordered_size !== '' ? " · Размер: {$ordered_size}" : '';

    if ($sdims && ($sdims['w'] ?? 0) > 0) {
        $dw_cm   = round($pos['scale'] * $sdims['w'], 1);
        $dh_cm   = round($pos['scale'] * $ar * $sdims['h'], 1);
        $left_cm = round(max(0, $pos['x'] - $pos['scale'] / 2) * $sdims['w'], 1);
        $top_cm  = round(max(0, $pos['y'] - ($pos['scale'] * $ar) / 2) * $sdims['h'], 1);
        return "{$dw_cm} × {$dh_cm} cm · {$left_cm} cm от ляво · {$top_cm} cm от горе" . $size_suffix;
    }

    return 'Добавете размери на тениската в продукта за да изчислим cm' . $size_suffix;
}

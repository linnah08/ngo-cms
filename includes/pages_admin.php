<?php
/**
 * Pure, testable helpers for the ordered-list editors in admin/pages.php.
 *
 * Eight POST branches (impact, team, projects, ways — each save + delete) carried
 * a byte-for-byte copy of the same ordered-list logic: assign a default `order`,
 * sort by it, then upsert or delete an entry and re-sort. That logic now lives
 * here as three array-in/array-out functions — no $_POST, no I/O — so the page is
 * a thin controller (load_json → mutate → save_json) and the list maths is tested
 * once instead of duplicated eight times.
 */

/** Give every entry an `order` (defaulting to its current index) and sort by it. */
function pages_normalize_order(array $list): array {
    foreach ($list as $i => &$p) {
        if (!isset($p['order'])) $p['order'] = $i;
    }
    unset($p);
    usort($list, fn($a, $b) => (int)$a['order'] - (int)$b['order']);
    return $list;
}

/**
 * Insert or replace an entry in an ordered list, returning the re-sorted list.
 *
 * $idx === 'new' appends with order = max+1. A numeric index replaces that entry
 * in place, preserving its order. An out-of-range numeric index is a no-op (the
 * entry is dropped) — matching the original inline behaviour. The list is
 * normalised first, so callers may pass it straight from storage.
 *
 * @param int|string $idx   'new' or the position in the normalised list.
 * @param array      $entry The entry to insert/replace (its `order` is set here).
 */
function pages_list_upsert(array $list, int|string $idx, array $entry): array {
    $list = pages_normalize_order($list);

    if ($idx === 'new') {
        $max_order      = empty($list) ? -1 : max(array_column($list, 'order'));
        $entry['order'] = $max_order + 1;
        $list[]         = $entry;
    } else {
        $idx = (int)$idx;
        if ($idx >= 0 && $idx < count($list)) {
            $entry['order'] = $list[$idx]['order'];
            $list[$idx]     = $entry;
        }
    }

    usort($list, fn($a, $b) => (int)$a['order'] - (int)$b['order']);
    return $list;
}

/**
 * Remove the entry at $idx from an ordered list and re-sequence `order` values.
 * Returns the new list, or null when $idx is out of range (nothing removed) so
 * the caller can skip the save + "deleted" flash, as the original did.
 */
function pages_list_delete(array $list, int $idx): ?array {
    $list = pages_normalize_order($list);
    if ($idx < 0 || $idx >= count($list)) return null;

    array_splice($list, $idx, 1);
    foreach ($list as $i => &$p) {
        $p['order'] = $i;
    }
    unset($p);
    return $list;
}

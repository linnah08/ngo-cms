<?php
/**
 * Pure, testable helpers for admin/product-edit.php.
 *
 * The editor inlined all of the POST-to-structure shaping (colour validation,
 * print-area clamping, per-size dimensions, variant-row gallery sanitisation)
 * between request handling, DB writes and ~600 lines of view/JS. These functions
 * take plain arrays in and return plain arrays out — no $_POST, no DB, no globals
 * — so the fiddly validation can be unit-tested and the page stays a thin
 * controller. The one filesystem dependency (checking a gallery image exists) is
 * injected as a predicate so these stay pure.
 */

require_once __DIR__ . '/../config.php'; // slug()

/** Canonical product slug: explicit slug, else EN name, else BG name. */
function product_compute_slug(string $slug_input, string $name_en, string $name_bg): string {
    return slug(trim($slug_input) ?: (trim($name_en) ?: trim($name_bg)));
}

/**
 * Build the 'print' product variant structure from POST fields.
 * Returns the array ready to json_encode (keys: colours, sizes, print_area,
 * custom, size_guide, size_dims). Caller uses ['sizes'] and ['size_guide'] for
 * validation.
 *
 * @param array $post          The submitted fields.
 * @param array $existing_vdata Decoded existing `variants` JSON (size_guide fallback).
 */
function product_build_print_variants(array $post, array $existing_vdata = []): array {
    $colour_names     = $post['colour_name']     ?? [];
    $colour_labels_bg = $post['colour_label_bg'] ?? [];
    $colour_labels_en = $post['colour_label_en'] ?? [];
    $colour_mockups   = $post['colour_mockup']   ?? [];
    $colours = [];
    foreach ($colour_names as $i => $cname) {
        $cname = trim((string)$cname);
        if ($cname === '') continue;
        if (!preg_match('/^[a-zA-Z0-9#\-]{1,32}$/', $cname)) continue;
        $colours[] = [
            'name'     => $cname,
            'label_bg' => trim((string)($colour_labels_bg[$i] ?? '')),
            'label_en' => trim((string)($colour_labels_en[$i] ?? '')),
            'mockup'   => trim((string)($colour_mockups[$i]   ?? '')),
        ];
    }

    $sizes = array_values(array_intersect($post['sizes'] ?? [], ['S', 'M', 'L', 'XL', 'XXL']));

    $print_area = [
        'x' => min(1.0, max(0.0, (float)($post['pa_x'] ?? 28) / 100)),
        'y' => min(1.0, max(0.0, (float)($post['pa_y'] ?? 18) / 100)),
        'w' => min(1.0, max(0.0, (float)($post['pa_w'] ?? 44) / 100)),
        'h' => min(1.0, max(0.0, (float)($post['pa_h'] ?? 50) / 100)),
    ];

    // Per-size dimensions (half-chest width × body length in cm); keep only positive pairs.
    $size_dims  = [];
    $raw_dims_w = $post['size_dim_w'] ?? [];
    $raw_dims_h = $post['size_dim_h'] ?? [];
    foreach (array_keys($raw_dims_w) as $sz) {
        $sz = trim((string)$sz);
        if ($sz === '') continue;
        $w = round((float)($raw_dims_w[$sz] ?? 0), 1);
        $h = round((float)($raw_dims_h[$sz] ?? 0), 1);
        if ($w > 0 && $h > 0) $size_dims[$sz] = ['w' => $w, 'h' => $h];
    }

    $size_guide = trim((string)($post['size_guide_filename'] ?? '')) ?: ($existing_vdata['size_guide'] ?? '');

    return [
        'colours'    => $colours,
        'sizes'      => $sizes,
        'print_area' => $print_area,
        'custom'     => !empty($post['custom_orders']),
        'size_guide' => $size_guide,
        'size_dims'  => $size_dims,
    ];
}

/** Clean the variant attribute-name list: trim, drop empty, cap at 64 chars. */
function product_clean_variant_attributes(array $raw): array {
    $clean = [];
    foreach ($raw as $a) {
        $a = trim((string)$a);
        if ($a !== '' && mb_strlen($a) <= 64) $clean[] = $a;
    }
    return $clean;
}

/**
 * Parse + sanitise 'variant' product rows from POST.
 *
 * Each kept row: id, label_bg, label_en, image (primary), images (gallery, max 8,
 * deduped, filename-validated), stock, attrs. Rows with an empty BG label are
 * skipped. $image_ok(string $filename): bool keeps only real gallery files and is
 * injected so this function does no filesystem I/O itself.
 */
function product_parse_variant_rows(array $post, callable $image_ok): array {
    $vr_ids       = $post['pv_id']       ?? [];
    $vr_labels    = $post['pv_label_bg'] ?? [];
    $vr_labels_en = $post['pv_label_en'] ?? [];
    $vr_images    = $post['pv_images']   ?? []; // JSON per row: {"images":[...],"primary":"..."}
    $vr_stocks    = $post['pv_stock']    ?? [];
    $vr_attrs     = $post['pv_attrs']    ?? []; // JSON strings per row

    $rows = [];
    foreach (array_keys($vr_labels) as $i) {
        $lbl = trim((string)($vr_labels[$i] ?? ''));
        if ($lbl === '') continue;

        $row_attrs = json_decode($vr_attrs[$i] ?? '{}', true);
        if (!is_array($row_attrs)) $row_attrs = [];

        // Parse + sanitise the variant gallery.
        $gal     = json_decode($vr_images[$i] ?? '', true);
        $images  = [];
        $primary = '';
        if (is_array($gal)) {
            foreach ((array)($gal['images'] ?? []) as $f) {
                $f = basename(trim((string)$f));
                if ($f !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $f)
                    && !in_array($f, $images, true) && $image_ok($f)) {
                    $images[] = $f;
                }
                if (count($images) >= 8) break;
            }
            $primary = basename(trim((string)($gal['primary'] ?? '')));
        }
        if ($primary === '' || !in_array($primary, $images, true)) {
            $primary = $images[0] ?? '';
        }

        $rows[] = [
            'id'       => (int)($vr_ids[$i] ?? 0),
            'label_bg' => $lbl,
            'label_en' => trim((string)($vr_labels_en[$i] ?? '')),
            'image'    => $primary,
            'images'   => $images,
            'stock'    => max(0, (int)($vr_stocks[$i] ?? 0)),
            'attrs'    => $row_attrs,
        ];
    }
    return $rows;
}

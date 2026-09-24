<?php
/**
 * Print product validation and file-handling helpers.
 * All functions are pure/testable — no global state, no HTTP side effects.
 */

/**
 * Validate that colour and size are in the product's variants.
 * Returns an array of error messages (empty = valid).
 *
 * @param array  $variants  Decoded variants JSON array
 * @param string $colour    Submitted colour name (e.g. "navy")
 * @param string $size      Submitted size (e.g. "L")
 * @param string $lang      'bg' | 'en' — language of the messages
 */
function print_validate_item(array $variants, string $colour, string $size, string $lang = 'bg'): array
{
    $errors        = [];
    $valid_colours = array_column($variants['colours'] ?? [], 'name');
    $valid_sizes   = $variants['sizes'] ?? [];

    if ($colour === '' || !in_array($colour, $valid_colours, true)) {
        $errors[] = t_or('cart.err.choose_colour', 'Моля изберете валиден цвят.', 'Please choose a colour.', $lang);
    }
    if ($size === '' || !in_array($size, $valid_sizes, true)) {
        $errors[] = t_or('cart.err.choose_size', 'Моля изберете валиден размер.', 'Please choose a size.', $lang);
    }
    return $errors;
}

/**
 * Validate an uploaded design file entry (as from $_FILES['design']).
 * Returns an array of error messages (empty = valid).
 *
 * @param array $file  Single entry from $_FILES — must have 'type' and 'size' keys
 * @warning  'type' is client-supplied and must NOT be trusted for security.
 *           Callers should use mime_content_type($file['tmp_name']) and pass the
 *           server-detected MIME as 'type' instead of relying on the browser value.
 */
function print_validate_design_file(array $file, string $lang = 'bg'): array
{
    $errors  = [];
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];

    if (!in_array($file['type'] ?? '', $allowed, true)) {
        $errors[] = t_or('cart.err.file_type', 'Неподдържан формат. Разрешени: JPEG, PNG, WebP.', 'This file type is not supported. Please upload a JPEG, PNG or WebP image.', $lang);
    }
    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        $errors[] = t_or('cart.err.file_size', 'Файлът е прекалено голям. Максимум 10 MB.', 'The file is too large. The maximum is 10 MB.', $lang);
    }
    return $errors;
}

/**
 * Generate a random, safe filename for a design upload.
 * Pattern: {uniqid()}_{16 random hex chars}.{ext}
 * Contains only a-f0-9, underscore, and a dot — passes print_filename_safe().
 */
function print_generate_filename(string $ext): string
{
    return uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . strtolower($ext);
}

/**
 * Returns true if the filename contains only safe characters.
 * Prevents path traversal in download endpoints.
 *
 * @param string $filename Bare filename only (no directory components — call basename() first)
 */
function print_filename_safe(string $filename): bool
{
    // Allow hex chars + underscore for the stem, dot separator, alpha for extension.
    // Rejects path separators, spaces, and any other unsafe characters.
    return (bool) preg_match('/^[a-f0-9_]+\.[a-z]{2,4}$/', $filename);
}

<?php
/**
 * PDF files admins upload to link to (a leaflet, a one-pager, a report), served from
 * /assets/files/uploads/. The editor's link dialog and the section link fields upload
 * through admin/upload-file.php, which stores them with file_upload_store().
 *
 * The folder ships with only its .htaccess (serve PDFs, run nothing), like the yearly
 * reports beside it in assets/files/reports/. The PDFs are gitignored, and a site's
 * rsync --delete deploy must exclude the PDFs in both folders, or it deletes them.
 */

const FILES_DIR       = 'assets/files/uploads';
const FILES_MAX_BYTES = 15 * 1024 * 1024;

/** The largest PDF that can be uploaded: our own limit or the server's, whichever is smaller. */
function file_upload_max_bytes(): int
{
    require_once __DIR__ . '/images.php';
    $server = upload_max_bytes();
    return $server > 0 ? min(FILES_MAX_BYTES, $server) : FILES_MAX_BYTES;
}

/** "320 KB", "1,4 MB" — the way the link text and the messages show a size. */
function file_size_label(int $bytes): string
{
    if ($bytes >= 1 << 20) {
        return str_replace('.', ',', (string) round($bytes / (1 << 20), 1)) . ' MB';
    }
    return max(1, (int) round($bytes / 1024)) . ' KB';
}

/**
 * The stored name: the original name in Latin letters plus a short random part, so the
 * downloaded file still says what it is and two uploads never overwrite each other.
 * "Фондация Различни умове - интро.pdf" → "fondatsiya-razlichni-umove-intro-3fa9c1.pdf".
 */
function file_upload_name(string $original): string
{
    $base = ascii_slug(pathinfo($original, PATHINFO_FILENAME));
    $base = (string) preg_replace('/[^a-z0-9-]+/', '', $base);
    $base = trim(substr($base, 0, 60), '-');
    if ($base === '') $base = 'document';
    return $base . '-' . bin2hex(random_bytes(3)) . '.pdf';
}

/** The text a new link gets: "Фондация Различни умове - интро (PDF, 320 KB)". */
function file_link_text(string $original, int $bytes): string
{
    $name = trim((string) preg_replace('/\s+/u', ' ', pathinfo($original, PATHINFO_FILENAME)));
    return ($name !== '' ? $name . ' ' : '') . '(PDF, ' . file_size_label($bytes) . ')';
}

/** Is this file really a PDF? Its contents decide, not its name. */
function file_is_pdf(string $path): bool
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) return false;
    $head = (string) fread($fh, 5);
    fclose($fh);
    return $head === '%PDF-' && (new finfo(FILEINFO_MIME_TYPE))->file($path) === 'application/pdf';
}

/** Plain words for each way an upload can fail. */
function file_upload_message(string $reason): string
{
    return match ($reason) {
        'size'  => 'Файлът е твърде голям — най-много ' . file_size_label(file_upload_max_bytes()) . '. Намалете размера на PDF файла и опитайте отново.',
        'type'  => 'Този файл не е PDF. Изберете файл във формат PDF.',
        'empty' => 'Не е избран файл.',
        default => 'Файлът не можа да се качи. Опитайте отново.',
    };
}

/**
 * Checks and stores one uploaded PDF under $root/assets/files/.
 * $uploaded: false only in tests, where the file did not come through an HTTP upload.
 *
 * @return array{ok: true, path: string, text: string, size: string}|array{ok: false, reason: string, message: string}
 */
function file_upload_store(string $tmp, string $original, int $error, string $root, bool $uploaded = true): array
{
    $fail = static fn(string $reason): array => ['ok' => false, 'reason' => $reason, 'message' => file_upload_message($reason)];

    if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) return $fail('size');
    if ($error === UPLOAD_ERR_NO_FILE) return $fail('empty');
    if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)) return $fail('server');
    if ($uploaded && !is_uploaded_file($tmp)) return $fail('server');

    $bytes = (int) filesize($tmp);
    if ($bytes === 0) return $fail('empty');
    if ($bytes > file_upload_max_bytes()) return $fail('size');
    if (strtolower(pathinfo($original, PATHINFO_EXTENSION)) !== 'pdf' || !file_is_pdf($tmp)) return $fail('type');

    $dir = rtrim($root, '/') . '/' . FILES_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return $fail('server');

    $name = file_upload_name($original);
    $ok   = $uploaded ? move_uploaded_file($tmp, $dir . '/' . $name) : rename($tmp, $dir . '/' . $name);
    if (!$ok) return $fail('server');
    @chmod($dir . '/' . $name, 0644);

    return ['ok' => true, 'path' => '/' . FILES_DIR . '/' . $name, 'text' => file_link_text($original, $bytes), 'size' => file_size_label($bytes)];
}

/**
 * The site's own PDFs an article's text links to, once each and in order, for the
 * „Файлове за изтегляне“ box under the article (templates/article-downloads.php).
 * A link counts when it points into /assets/files/<folder>/ — as a path or as a full
 * address on this site — and the file is there; links elsewhere are left alone.
 * The name is the link's title (the upload button sets it to the file's name), else
 * its text, else the stored file name.
 *
 * @return list<array{href: string, name: string, size: string}>
 */
function file_links_in_html(string $html, string $root = ROOT_PATH): array
{
    if (stripos($html, '.pdf') === false) return [];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NONET);
    libxml_clear_errors();

    $site  = strtolower((string) parse_url(SITE_URL, PHP_URL_HOST));
    $site  = preg_replace('/^www\./', '', $site);
    $found = [];
    foreach ($doc->getElementsByTagName('a') as $a) {
        $href = trim($a->getAttribute('href'));
        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        if ($host !== '' && preg_replace('/^www\./', '', $host) !== $site) continue;
        $path = rawurldecode((string) parse_url($href, PHP_URL_PATH));
        if (!preg_match('#^/assets/files/[a-z0-9_-]+/[^/]+\.pdf$#i', $path) || str_contains($path, '..')) continue;
        if (isset($found[$path])) continue;
        $file = rtrim($root, '/') . $path;
        if (!is_file($file)) continue;

        $name = trim((string) preg_replace('/\s*\(PDF,[^)]*\)\s*$/u', '', $a->getAttribute('title')));
        if ($name === '') $name = trim((string) preg_replace('/\s+/u', ' ', $a->textContent));
        if ($name === '') $name = (string) preg_replace('/-[0-9a-f]{6}$/', '', pathinfo($path, PATHINFO_FILENAME));

        $found[$path] = ['href' => $path, 'name' => $name, 'size' => file_size_label((int) filesize($file))];
    }
    return array_values($found);
}

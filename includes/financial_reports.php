<?php
/**
 * Yearly reports (Годишни отчети): several PDF documents per year, shared by the BG and
 * EN pages. Data in content/financial-reports.json (server is the source of truth, so it
 * is gitignored); files in assets/files/reports/, named by the server, never by the uploader.
 */
const REPORTS_FILE = CONTENT_PATH . '/financial-reports.json';
const REPORTS_DIR  = ROOT_PATH . '/assets/files/reports';
const REPORTS_URL  = '/assets/files/reports';

function reports_load(?string $file = null): array {
    $d = load_json($file ?? REPORTS_FILE);
    return ['years' => is_array($d['years'] ?? null) ? array_values($d['years']) : []];
}

function reports_save(array $d, ?string $file = null): bool {
    $file ??= REPORTS_FILE;
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
    $json = json_encode(reports_sorted($d), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return @file_put_contents($file, $json, LOCK_EX) !== false;   // the caller shows a plain message on false
}

function reports_sorted(array $d): array {
    $years = $d['years'] ?? [];
    usort($years, static fn(array $a, array $b): int => $b['year'] <=> $a['year']);
    return ['years' => array_values($years)];
}

function reports_add_year(array $d, int $year): array|string {
    $max = (int) date('Y') + 1;
    if ($year < 1990 || $year > $max) return "Годината трябва да е между 1990 и {$max}.";
    if (in_array($year, array_column($d['years'] ?? [], 'year'), true)) return "Година {$year} вече съществува.";
    $d['years'][] = ['year' => $year, 'documents' => []];
    return reports_sorted($d);
}

function reports_file_name_ok(string $name): bool {
    return (bool) preg_match('/^\d{4}-[0-9a-f]{8}\.pdf$/', $name);
}

function reports_clean_fields(array $f): array {
    $t = static fn($v, int $max): string => is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    return [
        'title_bg'       => $t($f['title_bg'] ?? '', 200),
        'title_en'       => $t($f['title_en'] ?? '', 200),
        'description_bg' => $t($f['description_bg'] ?? '', 2000),
        'description_en' => $t($f['description_en'] ?? '', 2000),
    ];
}

function reports_add_document(array $d, int $year, array $f, string $file): array {
    foreach ($d['years'] as &$y) {
        if ($y['year'] !== $year) continue;
        $y['documents'][] = ['id' => substr($file, 5, 8)] + reports_clean_fields($f) + ['file' => $file];
    }
    unset($y);
    return $d;
}

function reports_update_document(array $d, string $id, array $f, ?string $file = null): array {
    foreach ($d['years'] as &$y) {
        foreach ($y['documents'] as &$doc) {
            if ($doc['id'] !== $id) continue;
            $doc = ['id' => $id] + reports_clean_fields($f) + ['file' => $file ?? $doc['file']];
        }
        unset($doc);
    }
    unset($y);
    return $d;
}

/** @return array{data: array, file: ?string} */
function reports_delete_document(array $d, string $id): array {
    $file = null;
    foreach ($d['years'] as &$y) {
        foreach ($y['documents'] as $i => $doc) {
            if ($doc['id'] === $id) { $file = $doc['file']; unset($y['documents'][$i]); }
        }
        $y['documents'] = array_values($y['documents']);
    }
    unset($y);
    return ['data' => $d, 'file' => $file];
}

/** @return array{data: array, files: string[]} */
function reports_delete_year(array $d, int $year): array {
    $files = [];
    foreach ($d['years'] as $i => $y) {
        if ($y['year'] !== $year) continue;
        $files = array_column($y['documents'], 'file');
        unset($d['years'][$i]);
    }
    $d['years'] = array_values($d['years']);
    return ['data' => $d, 'files' => $files];
}

function reports_move_document(array $d, string $id, int $delta): array {
    foreach ($d['years'] as &$y) {
        $ids = array_column($y['documents'], 'id');
        $i = array_search($id, $ids, true);
        if ($i === false) continue;
        $j = $i + $delta;
        if ($j < 0 || $j >= count($ids)) break;
        [$y['documents'][$i], $y['documents'][$j]] = [$y['documents'][$j], $y['documents'][$i]];
    }
    unset($y);
    return $d;
}

/** Years with documents, in display language, with each file's size and whether it exists. */
function reports_public_list(array $d, string $lang, ?callable $size = null): array {
    $size ??= static function (string $f): ?int {
        $p = REPORTS_DIR . '/' . $f;
        return is_file($p) ? (int) filesize($p) : null;
    };
    $pick = static fn(array $doc, string $k): string =>
        ($lang === 'en' && ($doc[$k . '_en'] ?? '') !== '') ? $doc[$k . '_en'] : (string) ($doc[$k . '_bg'] ?? '');
    $out = [];
    foreach (reports_sorted($d)['years'] as $y) {
        if (empty($y['documents'])) continue;
        $docs = [];
        foreach ($y['documents'] as $doc) {
            $file  = (string) ($doc['file'] ?? '');
            $ok    = reports_file_name_ok($file);
            $bytes = $ok ? $size($file) : null;
            $docs[] = [
                'id'          => (string) ($doc['id'] ?? ''),
                'title'       => $pick($doc, 'title'),
                'description' => $pick($doc, 'description'),
                'url'         => $ok ? REPORTS_URL . '/' . $file : '',
                'bytes'       => $bytes,
                'exists'      => $bytes !== null,
            ];
        }
        $out[] = ['year' => (int) $y['year'], 'documents' => $docs];
    }
    return $out;
}

function reports_any_published(?array $d = null): bool {
    foreach (($d ?? reports_load())['years'] as $y) {
        if (!empty($y['documents'])) return true;
    }
    return false;
}

/** null when $entry is a real PDF upload; otherwise a plain Bulgarian message. */
function reports_check_upload(array $entry): ?string {
    $err = $entry['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return 'Изберете PDF файл.';
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return 'Файлът е твърде голям за сървъра.';
    if ($err !== UPLOAD_ERR_OK) return 'Файлът не се качи докрай. Опитайте отново.';
    $tmp = (string) ($entry['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) return 'Файлът не се качи докрай. Опитайте отново.';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $head = (string) file_get_contents($tmp, false, null, 0, 5);
    if ($mime !== 'application/pdf' || $head !== '%PDF-') return 'Позволени са само PDF файлове.';
    return null;
}

/** @return array{file: ?string, error: ?string} */
function reports_store_upload(array $entry, int $year, ?string $dir = null, ?callable $move = null): array {
    $error = reports_check_upload($entry);
    if ($error !== null) return ['file' => null, 'error' => $error];
    $dir  ??= REPORTS_DIR;
    $move ??= 'move_uploaded_file';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return ['file' => null, 'error' => 'Папката за отчети не може да се създаде.'];
    $file = sprintf('%04d-%s.pdf', $year, bin2hex(random_bytes(4)));
    if (!$move($entry['tmp_name'], $dir . '/' . $file)) return ['file' => null, 'error' => 'Файлът не можа да се запише на сървъра.'];
    return ['file' => $file, 'error' => null];
}

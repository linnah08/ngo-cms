# Annual Financial Reports Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A public page of yearly reports (several PDFs per year), readable inside the page on any device through a vendored PDF.js viewer, managed from the admin, linked from the footer once a report exists.

**Architecture:** Pure helpers in `includes/financial_reports.php` own the data (one JSON file, shared by BG and EN) and the upload checks. `admin/financial-reports.php` is a thin controller over them. One template renders both public pages. A small module script drives PDF.js, which is served from `assets/vendor/pdfjs/`.

**Tech Stack:** PHP 8.4 (no framework), JSON flat files, PDF.js 6.4.299 legacy build (Apache-2.0), vanilla JS, PHPUnit 13, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-03-financial-reports-design.md`

## Global Constraints

- **Addresses:** `/finansovi-otcheti/` (BG) and `/en/financial-reports/` (EN). The data is shared by both.
- **Data:** `content/financial-reports.json` → `{"years":[{"year":2025,"documents":[{"id","title_bg","title_en","description_bg","description_en","file"}]}]}`. Years are kept newest first.
- **IDs and files:** a document `id` is 8 lowercase hex characters. Its file is `<year>-<id>.pdf` in `assets/files/reports/`. Any name not matching `^\d{4}-[0-9a-f]{8}\.pdf$` counts as missing.
- **Years:** an integer from 1990 to next year; no duplicates.
- **Uploads:** checked by `finfo` MIME `application/pdf` and the first bytes `%PDF-`. No size limit of our own, and no change to the server's limits. PHP's upload errors become plain Bulgarian messages.
- **Footer link:** shown only while at least one document exists. The sitemap follows the same rule.
- **EN page:** shows the English title and description, falling back to the BG text. The download link says "in Bulgarian".
- **Viewer:** PDF.js from `/assets/vendor/pdfjs/`, as files renamed to `.js` (not `.mjs`, which some hosts serve with the wrong MIME type). Loaded only on the reports page; no CDN.
- **Accessibility:**
  - Real `<button>`s with labels, at least 44×44.
  - The page counter sits in `aria-live="polite"`.
  - Focus returns to "Отвори" on close.
  - No animation under `prefers-reduced-motion`.
  - Persistent messages, never auto-dismissed.
  - Every EN input has `data-translate-from`.
- **Security:**
  - Admin pages call `admin_require_editorial()`.
  - Every POST calls `csrf_verify()`.
  - Every value is output with `h()`.
  - File paths are built only from validated names.
- **Styles:** layout-critical styles inline (CLAUDE.md: stale-cached CSS).
- **Workflow:**
  - Work in the worktree `../oddminds-oss-wt/reports-spec` (branch `reports-spec`).
  - Commit after each task. Never push without the user saying "push".
  - Run the suite as `php vendor/bin/phpunit > /tmp/pu.txt 2>&1; echo rc=$?; tail -3 /tmp/pu.txt`.

## Review Focus

1. **A report uploaded, then its file deleted on the server by hand.** Expected: the page still lists the document with "Файлът не е наличен", and nothing errors. (Test in Task 1.)
2. **A file named `report.pdf` that is really a JPEG, or HTML renamed to `.pdf`.** Expected: refused with "Позволени са само PDF файлове", and nothing stored. (Test in Task 2.)
3. **Titles or descriptions containing quotes, `<script>` or `&`.** Expected: shown literally on the page and in the admin. (Tests in Tasks 3 and 4.)
4. **Saving a new document whose upload fails** (an upload error or a non-PDF). Expected: no JSON entry pointing at nothing, and the typed title and description are still in the form. (Test in Task 2.)
5. **The PDF fails to load in the viewer** (a missing worker or a corrupt file). Expected: the viewer says so in words and points to the download link, and the rest of the page keeps working. (Browser test in Task 5.)

---

## File Structure

| File | Responsibility |
|---|---|
| `includes/financial_reports.php` (create) | Data model, upload check and file storage, public list |
| `admin/financial-reports.php` (create) | Admin screen: years and documents |
| `admin/pages.php` (modify) | A "Финансови отчети" row in the Съдържание list |
| `templates/financial-reports.php` (create) | The public page body (BG and EN) |
| `finansovi-otcheti/index.php`, `en/financial-reports/index.php` (create) | Thin public pages |
| `assets/js/report-viewer.js` (create) | The PDF.js-driven viewer |
| `assets/vendor/pdfjs/pdf.min.js`, `pdf.worker.min.js`, `LICENSE` (create) | Vendored PDF.js 6.4.299 legacy build |
| `assets/files/reports/.htaccess` (create) | Serve PDFs only, no script execution |
| `templates/footer.php`, `sitemap.php`, `content/{bg,en}/strings.json`, `.gitignore` (modify) | Link, sitemap, wording, ignore the site's data |
| `tests/FinancialReportsTest.php`, `tests/FinancialReportsPageTest.php`, `tests/Browser/financial-reports.spec.js` (create) | Tests |

---

### Task 1: The data model

**Files:**
- Create: `includes/financial_reports.php`
- Test: `tests/FinancialReportsTest.php`

**Interfaces:**
- Produces (all pure unless noted; `$d` is the decoded JSON `['years' => [...]]`):
  - `const REPORTS_FILE` = `CONTENT_PATH . '/financial-reports.json'`
  - `const REPORTS_DIR` = `ROOT_PATH . '/assets/files/reports'`
  - `const REPORTS_URL` = `'/assets/files/reports'`
  - `reports_load(?string $file = null): array` (I/O). Always returns `['years' => array]`.
  - `reports_save(array $d, ?string $file = null): bool` (I/O, `LOCK_EX`).
  - `reports_add_year(array $d, int $year): array|string`. Returns the new data, or a BG error.
  - `reports_add_document(array $d, int $year, array $f, string $file): array`. `$f` has keys `title_bg`, `title_en`, `description_bg`, `description_en`. The new document's `id` comes from its file name.
  - `reports_update_document(array $d, string $id, array $f, ?string $file = null): array`
  - `reports_delete_document(array $d, string $id): array{data: array, file: ?string}`
  - `reports_delete_year(array $d, int $year): array{data: array, files: string[]}`
  - `reports_move_document(array $d, string $id, int $delta): array`
  - `reports_file_name_ok(string $name): bool`
  - `reports_public_list(array $d, string $lang, ?callable $size = null): array`. Returns a list of `['year' => int, 'documents' => [['id','title','description','url','bytes'|null,'exists'=>bool]]]`. `$size(string $file): ?int` defaults to the filesize in `REPORTS_DIR`. Years with no documents are left out.
  - `reports_any_published(?array $d = null): bool`

- [ ] **Step 1: Write the failing tests**

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Yearly reports: several documents per year, shared by the BG and EN pages. */
final class FinancialReportsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/includes/financial_reports.php';
    }

    private static function fields(string $t = 'Годишен финансов отчет'): array
    {
        return ['title_bg' => $t, 'title_en' => 'Annual statement', 'description_bg' => 'Описание', 'description_en' => ''];
    }

    private static function two(): array
    {
        $d = reports_add_year(['years' => []], 2024);
        $d = reports_add_year($d, 2025);
        $d = reports_add_document($d, 2025, self::fields('А'), '2025-aaaaaaaa.pdf');
        $d = reports_add_document($d, 2025, self::fields('Б'), '2025-bbbbbbbb.pdf');
        return reports_add_document($d, 2024, self::fields('В'), '2024-cccccccc.pdf');
    }

    public function testYearsAreKeptNewestFirst(): void
    {
        $this->assertSame([2025, 2024], array_column(self::two()['years'], 'year'));
    }

    public function testBadOrDuplicateYearsAreRefusedInPlainWords(): void
    {
        $d = self::two();
        $this->assertIsString(reports_add_year($d, 2025));
        $this->assertIsString(reports_add_year($d, 1989));
        $this->assertIsString(reports_add_year($d, (int) date('Y') + 2));
        $this->assertIsArray(reports_add_year($d, (int) date('Y') + 1));
    }

    public function testDocumentIdComesFromItsFile(): void
    {
        $doc = self::two()['years'][0]['documents'][0];
        $this->assertSame('aaaaaaaa', $doc['id']);
        $this->assertSame('2025-aaaaaaaa.pdf', $doc['file']);
    }

    public function testMoveAndUpdate(): void
    {
        $d = reports_move_document(self::two(), 'bbbbbbbb', -1);
        $this->assertSame(['Б', 'А'], array_column($d['years'][0]['documents'], 'title_bg'));
        $d = reports_update_document($d, 'aaaaaaaa', self::fields('Ново'), '2025-dddddddd.pdf');
        $doc = $d['years'][0]['documents'][1];
        $this->assertSame('Ново', $doc['title_bg']);
        $this->assertSame('2025-dddddddd.pdf', $doc['file']);
        $this->assertSame('aaaaaaaa', $doc['id'], 'a replaced file keeps the document id');
    }

    public function testDeletesReturnTheFilesToRemove(): void
    {
        $r = reports_delete_document(self::two(), 'aaaaaaaa');
        $this->assertSame('2025-aaaaaaaa.pdf', $r['file']);
        $this->assertCount(1, $r['data']['years'][0]['documents']);

        $r = reports_delete_year(self::two(), 2025);
        $this->assertSame(['2025-aaaaaaaa.pdf', '2025-bbbbbbbb.pdf'], $r['files']);
        $this->assertSame([2024], array_column($r['data']['years'], 'year'));
    }

    public function testPublicListUsesTheLanguageAndFallsBackToBulgarian(): void
    {
        $size = static fn(string $f): ?int => 1024;
        $bg = reports_public_list(self::two(), 'bg', $size);
        $en = reports_public_list(self::two(), 'en', $size);
        $this->assertSame('А', $bg[0]['documents'][0]['title']);
        $this->assertSame('Annual statement', $en[0]['documents'][0]['title']);
        $this->assertSame('Описание', $en[0]['documents'][0]['description'], 'empty EN description falls back to BG');
        $this->assertSame('/assets/files/reports/2025-aaaaaaaa.pdf', $bg[0]['documents'][0]['url']);
    }

    /** Review Focus 1 */
    public function testMissingFileIsFlaggedNotFatal(): void
    {
        $list = reports_public_list(self::two(), 'bg', static fn(string $f): ?int => $f === '2025-aaaaaaaa.pdf' ? null : 10);
        $this->assertFalse($list[0]['documents'][0]['exists']);
        $this->assertTrue($list[0]['documents'][1]['exists']);
    }

    public function testBadFileNamesInHandEditedJsonCountAsMissing(): void
    {
        $d = ['years' => [['year' => 2025, 'documents' => [
            ['id' => 'x', 'title_bg' => 'x', 'file' => '../../config.php'],
        ]]]];
        $list = reports_public_list($d, 'bg', static fn(string $f): ?int => 10);
        $this->assertFalse($list[0]['documents'][0]['exists']);
        $this->assertSame('', $list[0]['documents'][0]['url']);
        $this->assertFalse(reports_file_name_ok('2025-AAAAAAAA.pdf'));
        $this->assertTrue(reports_file_name_ok('2025-0a1b2c3d.pdf'));
    }

    public function testEmptyYearsAreNotShownAndCountAsNothingPublished(): void
    {
        $d = reports_add_year(['years' => []], 2025);
        $this->assertSame([], reports_public_list($d, 'bg'));
        $this->assertFalse(reports_any_published($d));
        $this->assertTrue(reports_any_published(self::two()));
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $file = sys_get_temp_dir() . '/fr-' . bin2hex(random_bytes(4)) . '.json';
        $this->assertTrue(reports_save(self::two(), $file));
        $this->assertSame(self::two(), reports_load($file));
        unlink($file);
        $this->assertSame(['years' => []], reports_load($file));
    }
}
```

- [ ] **Step 2: Run to see them fail**

Run: `php vendor/bin/phpunit tests/FinancialReportsTest.php`
Expected: `Failed opening required '…/includes/financial_reports.php'`.

- [ ] **Step 3: Implement `includes/financial_reports.php`**

```php
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
    return file_put_contents($file, $json, LOCK_EX) !== false;
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
```

- [ ] **Step 4: Run the tests.** `php vendor/bin/phpunit tests/FinancialReportsTest.php` should give `OK (10 tests, …)`. Then run the full suite (expect `rc=0`).

- [ ] **Step 5: Commit**

```bash
git add includes/financial_reports.php tests/FinancialReportsTest.php
git commit -m "feat(reports): data model for yearly reports with several documents each"
```

---

### Task 2: Upload check and storing the file

**Files:**
- Modify: `includes/financial_reports.php` (append)
- Create: `assets/files/reports/.htaccess`
- Modify: `.gitignore`
- Test: `tests/FinancialReportsTest.php` (extend)

**Interfaces:**
- Consumes: `REPORTS_DIR`, `reports_file_name_ok()` (Task 1)
- Produces:
  - `reports_check_upload(array $entry): ?string`. `$entry` is one `$_FILES[...]` item. Returns null when it's a real PDF, otherwise a plain BG message.
  - `reports_store_upload(array $entry, int $year, ?string $dir = null, ?callable $move = null): array{file: ?string, error: ?string}`. Checks the upload, makes a new name `<year>-<8 hex>.pdf` and moves the file there (`$move` defaults to `move_uploaded_file`, so tests can inject `rename`).

- [ ] **Step 1: Write the failing tests** (add to `FinancialReportsTest`)

```php
    private function upload(string $bytes, int $err = UPLOAD_ERR_OK): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, $bytes);
        return ['name' => 'отчет.pdf', 'tmp_name' => $tmp, 'error' => $err, 'size' => strlen($bytes)];
    }

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    public function testARealPdfPasses(): void
    {
        $this->assertNull(reports_check_upload($this->upload(self::PDF)));
    }

    /** Review Focus 2 */
    public function testAnythingElseNamedPdfIsRefused(): void
    {
        foreach (["\xFF\xD8\xFF\xE0JFIF", '<html><script>x</script></html>', ''] as $bytes) {
            $this->assertSame('Позволени са само PDF файлове.', reports_check_upload($this->upload($bytes)));
        }
    }

    public function testPhpUploadErrorsBecomePlainWords(): void
    {
        $this->assertSame('Файлът е твърде голям за сървъра.', reports_check_upload($this->upload('', UPLOAD_ERR_INI_SIZE)));
        $this->assertSame('Изберете PDF файл.', reports_check_upload($this->upload('', UPLOAD_ERR_NO_FILE)));
        $this->assertNotNull(reports_check_upload($this->upload('', UPLOAD_ERR_PARTIAL)));
        $this->assertNotNull(reports_check_upload([]));
    }

    /** Review Focus 4: a failed upload stores nothing. */
    public function testStoringNamesTheFileAndRefusesWithoutLeavingAnything(): void
    {
        $dir = sys_get_temp_dir() . '/fr-' . bin2hex(random_bytes(4));
        $move = static fn(string $from, string $to): bool => rename($from, $to);

        $r = reports_store_upload($this->upload(self::PDF), 2025, $dir, $move);
        $this->assertNull($r['error']);
        $this->assertTrue(reports_file_name_ok($r['file']));
        $this->assertStringStartsWith('2025-', $r['file']);
        $this->assertFileExists($dir . '/' . $r['file']);

        $bad = reports_store_upload($this->upload('<html>'), 2025, $dir, $move);
        $this->assertNull($bad['file']);
        $this->assertSame('Позволени са само PDF файлове.', $bad['error']);
        $this->assertCount(1, glob($dir . '/*.pdf'));

        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }

    public function testTheReportsFolderServesOnlyPdfs(): void
    {
        $ht = (string) @file_get_contents(dirname(__DIR__) . '/assets/files/reports/.htaccess');
        $this->assertStringContainsString('php_flag engine off', $ht);
        $this->assertMatchesRegularExpression('/FilesMatch/', $ht);
        $gi = (string) file_get_contents(dirname(__DIR__) . '/.gitignore');
        $this->assertStringContainsString('/content/financial-reports.json', $gi);
        $this->assertStringContainsString('/assets/files/reports/*.pdf', $gi);
    }
```

- [ ] **Step 2: Run to see them fail.** Expect `Call to undefined function reports_check_upload()`, and the `.htaccess` assertion failing.

- [ ] **Step 3: Implement** (append to `includes/financial_reports.php`)

```php
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
```

Create `assets/files/reports/.htaccess`:

```apache
# Yearly report PDFs: public on purpose. Serve only PDFs; never run anything here.
php_flag engine off
<FilesMatch "\.(?!pdf$)[^.]+$">
  Require all denied
</FilesMatch>
<FilesMatch "\.pdf$">
  ForceType application/pdf
  Header set X-Content-Type-Options "nosniff"
</FilesMatch>
```

Add to `.gitignore`, under the admin-editable content block:

```gitignore
/content/financial-reports.json
/assets/files/reports/*.pdf
```

- [ ] **Step 4: Run the tests and the full suite.** Both should pass (`rc=0`).

- [ ] **Step 5: Commit**

```bash
git add includes/financial_reports.php assets/files/reports/.htaccess .gitignore tests/FinancialReportsTest.php
git commit -m "feat(reports): accept only real PDFs, named by the server"
```

---

### Task 3: The admin screen

**Files:**
- Create: `admin/financial-reports.php`
- Modify: `admin/pages.php` (the Съдържание list)
- Test: `tests/FinancialReportsTest.php` (source checks), `tests/Admin/TranslateButtonCoverageTest.php` and `tests/Security/WebrootGuardsTest.php` (must still pass)

**Interfaces:**
- Consumes: everything from Tasks 1–2.
- Produces: POST actions `add_year` (`year`), `add_doc` (`year`, the fields, `pdf`), `edit_doc` (`id`, the fields, optional `pdf`), `delete_doc` (`id`), `delete_year` (`year`), `move_doc` (`id`, `dir` = `up`|`down`). Each action redirects to `/admin/financial-reports.php?msg=<key>` (PRG), with persistent messages. A failed add or edit re-renders the form with the typed values and the error, without redirecting.

- [ ] **Step 1: Write the failing tests** (add to `FinancialReportsTest`)

```php
    public function testAdminScreenIsGuardedAndEveryPostIsCsrfChecked(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/admin/financial-reports.php');
        $this->assertStringContainsString('admin_require_editorial()', $src);
        $this->assertStringContainsString('csrf_verify()', $src);
        $this->assertSame(substr_count($src, '<form'), substr_count($src, 'csrf_field()'), 'every form posts a token');
        $this->assertStringNotContainsString('window.confirm', $src);
        $this->assertStringContainsString('data-confirm=', $src);
        $this->assertStringContainsString('data-translate-from="title_bg"', $src);
        $this->assertStringContainsString('data-translate-from="description_bg"', $src);
        $this->assertStringContainsString('Сканираният PDF не може да се чете от екранни четци', $src);
    }

    public function testContentPageListsTheReports(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/admin/pages.php');
        $this->assertStringContainsString('/admin/financial-reports.php', $src);
        $this->assertStringContainsString("'Финансови отчети'", $src);
    }
```

- [ ] **Step 2: Run to see them fail** (the file doesn't exist yet).

- [ ] **Step 3: Create `admin/financial-reports.php`**

```php
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/financial_reports.php';

admin_require_editorial();

$messages = [
    'year_added' => 'Годината е добавена.',
    'doc_added'  => 'Документът е добавен.',
    'doc_saved'  => 'Документът е запазен.',
    'doc_moved'  => 'Редът е променен.',
    'doc_deleted'=> 'Документът е изтрит.',
    'year_deleted' => 'Годината е изтрита.',
];
$error = '';
$form  = null;   // re-shown add/edit form after a failed upload: ['mode','year','id','fields']

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $d      = reports_load();
    $action = (string) ($_POST['action'] ?? '');
    $fields = reports_clean_fields($_POST);
    $id     = preg_match('/^[0-9a-f]{8}$/', (string) ($_POST['id'] ?? '')) ? $_POST['id'] : '';
    $year   = filter_var($_POST['year'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $done   = null;

    if ($action === 'add_year') {
        $r = reports_add_year($d, $year);
        if (is_string($r)) $error = $r; else { reports_save($r); $done = 'year_added'; }
    } elseif ($action === 'add_doc' || $action === 'edit_doc') {
        $form = ['mode' => $action, 'year' => $year, 'id' => $id, 'fields' => $fields];
        $has_file = ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($fields['title_bg'] === '') {
            $error = 'Заглавието е задължително.';
        } elseif ($action === 'add_doc' || $has_file) {
            $up = reports_store_upload($_FILES['pdf'] ?? [], $year ?: (int) date('Y'));
            if ($up['error'] !== null) {
                $error = $up['error'];
            } elseif ($action === 'add_doc') {
                reports_save(reports_add_document($d, $year, $fields, $up['file'])); $done = 'doc_added';
            } else {
                $old = reports_delete_document($d, $id)['file'];
                reports_save(reports_update_document($d, $id, $fields, $up['file']));
                if ($old && reports_file_name_ok($old)) @unlink(REPORTS_DIR . '/' . $old);   // only after the new file and JSON are in place
                $done = 'doc_saved';
            }
        } else {
            reports_save(reports_update_document($d, $id, $fields)); $done = 'doc_saved';
        }
    } elseif ($action === 'move_doc') {
        reports_save(reports_move_document($d, $id, ($_POST['dir'] ?? '') === 'up' ? -1 : 1)); $done = 'doc_moved';
    } elseif ($action === 'delete_doc') {
        $r = reports_delete_document($d, $id);
        reports_save($r['data']);
        if ($r['file'] && reports_file_name_ok($r['file'])) @unlink(REPORTS_DIR . '/' . $r['file']);
        $done = 'doc_deleted';
    } elseif ($action === 'delete_year') {
        $r = reports_delete_year($d, $year);
        reports_save($r['data']);
        foreach ($r['files'] as $f) if (reports_file_name_ok($f)) @unlink(REPORTS_DIR . '/' . $f);
        $done = 'year_deleted';
    }

    if ($done !== null) { header('Location: /admin/financial-reports.php?msg=' . $done); exit; }
}

$d       = reports_load();
$msg     = $messages[$_GET['msg'] ?? ''] ?? '';
$editing = $form ?? null;
if (!$editing && isset($_GET['edit']) && preg_match('/^[0-9a-f]{8}$/', $_GET['edit'])) {
    foreach ($d['years'] as $y) foreach ($y['documents'] as $doc) {
        if ($doc['id'] === $_GET['edit']) $editing = ['mode' => 'edit_doc', 'year' => $y['year'], 'id' => $doc['id'], 'fields' => $doc];
    }
}
if (!$editing && isset($_GET['add'])) $editing = ['mode' => 'add_doc', 'year' => (int) $_GET['add'], 'id' => '', 'fields' => reports_clean_fields([])];

$page_title_admin = 'Финансови отчети';
$active_nav       = 'pages';
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
$btn = 'min-height:44px;';
?>
<h1 style="margin-bottom:.5rem;">Финансови отчети</h1>
<p style="margin:0 0 1.5rem;color:var(--text-muted);">Годишните отчети се показват на <a href="/finansovi-otcheti/" target="_blank" rel="noopener">/finansovi-otcheti/</a>. Връзката във футъра се появява, щом има поне един документ.</p>

<?php if ($msg): ?><div class="admin-alert admin-alert--success" role="status" style="margin-bottom:1rem;"><?= h($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="admin-alert admin-alert--error" role="alert" style="margin-bottom:1rem;"><?= h($error) ?></div><?php endif; ?>

<?php if ($editing): $f = $editing['fields']; ?>
<form method="POST" enctype="multipart/form-data" class="admin-form"
      style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;margin-bottom:2rem;max-width:720px;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="<?= h($editing['mode']) ?>">
  <input type="hidden" name="year" value="<?= (int) $editing['year'] ?>">
  <input type="hidden" name="id" value="<?= h($editing['id']) ?>">
  <h2 style="font-size:1.1rem;margin:0 0 1rem;"><?= $editing['mode'] === 'add_doc' ? 'Нов документ за ' . (int) $editing['year'] : 'Редактиране на документ' ?></h2>
  <div class="form-group"><label for="title_bg">Заглавие *</label>
    <input type="text" id="title_bg" name="title_bg" required maxlength="200" value="<?= h($f['title_bg'] ?? '') ?>" style="width:100%;min-height:44px;"></div>
  <div class="form-group"><label for="title_en">Title (EN)</label>
    <input type="text" id="title_en" name="title_en" maxlength="200" data-translate-from="title_bg" value="<?= h($f['title_en'] ?? '') ?>" style="width:100%;min-height:44px;"></div>
  <div class="form-group"><label for="description_bg">Описание</label>
    <p id="descHelp" style="margin:.25rem 0 .5rem;color:var(--text-muted);font-size:.9rem;">Сканираният PDF не може да се чете от екранни четци и търсачки — напишете накратко какво съдържа документът.</p>
    <textarea id="description_bg" name="description_bg" rows="3" maxlength="2000" aria-describedby="descHelp" style="width:100%;"><?= h($f['description_bg'] ?? '') ?></textarea></div>
  <div class="form-group"><label for="description_en">Description (EN)</label>
    <textarea id="description_en" name="description_en" rows="3" maxlength="2000" data-translate-from="description_bg" style="width:100%;"><?= h($f['description_en'] ?? '') ?></textarea></div>
  <div class="form-group"><label for="pdf"><?= $editing['mode'] === 'add_doc' ? 'PDF файл *' : 'Нов PDF файл (по желание — заменя сегашния)' ?></label>
    <input type="file" id="pdf" name="pdf" accept="application/pdf" <?= $editing['mode'] === 'add_doc' ? 'required' : '' ?> style="min-height:44px;"></div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
    <button type="submit" class="btn btn--primary" style="<?= $btn ?>">Запази</button>
    <a href="/admin/financial-reports.php" class="btn btn--outline" style="<?= $btn ?>">Отказ</a>
  </div>
</form>
<?php endif; ?>

<form method="POST" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:2rem;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add_year">
  <div><label for="newYear" style="display:block;font-weight:600;">Нова година</label>
    <input type="number" id="newYear" name="year" min="1990" max="<?= (int) date('Y') + 1 ?>" value="<?= (int) date('Y') - 1 ?>" required style="min-height:44px;width:8rem;"></div>
  <button type="submit" class="btn btn--primary" style="<?= $btn ?>">+ Нова година</button>
</form>

<?php if (!$d['years']): ?>
  <p>Още няма отчети. Добавете година, после документите за нея.</p>
<?php endif; ?>

<?php foreach ($d['years'] as $y): $n = count($y['documents']); ?>
<section aria-labelledby="y<?= (int) $y['year'] ?>" style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;margin-bottom:1.25rem;">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
    <h2 id="y<?= (int) $y['year'] ?>" style="font-size:1.2rem;margin:0;"><?= (int) $y['year'] ?></h2>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
      <a href="/admin/financial-reports.php?add=<?= (int) $y['year'] ?>" class="btn btn--primary" style="<?= $btn ?>">+ Добави документ</a>
      <form method="POST" data-confirm="<?= h($n ? "Да изтрия ли {$y['year']} и всичките {$n} документа? Файловете се изтриват завинаги." : "Да изтрия ли година {$y['year']}?") ?>" data-confirm-ok="Да, изтрий">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete_year"><input type="hidden" name="year" value="<?= (int) $y['year'] ?>">
        <button type="submit" class="btn btn--outline" style="<?= $btn ?>color:#b91c1c;border-color:#b91c1c;" aria-label="Изтрий година <?= (int) $y['year'] ?>">Изтрий година</button>
      </form>
    </div>
  </div>
  <?php if (!$n): ?><p style="margin:.75rem 0 0;color:var(--text-muted);">Няма документи за тази година.</p><?php endif; ?>
  <ol style="list-style:none;padding:0;margin:.75rem 0 0;">
  <?php foreach ($y['documents'] as $i => $doc): $missing = !reports_file_name_ok($doc['file']) || !is_file(REPORTS_DIR . '/' . $doc['file']); ?>
    <li style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center;padding:.75rem 0;border-top:1px solid var(--border);">
      <div><strong><?= h($doc['title_bg']) ?></strong>
        <?php if ($missing): ?><span style="color:#b91c1c;font-weight:600;"> — <span aria-hidden="true">⚠</span> файлът липсва</span><?php endif; ?></div>
      <div style="display:flex;gap:.35rem;flex-wrap:wrap;">
        <?php foreach (['up' => ['↑', 'нагоре', $i > 0], 'down' => ['↓', 'надолу', $i < $n - 1]] as $dir => [$arrow, $word, $can]): if (!$can) continue; ?>
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="move_doc"><input type="hidden" name="id" value="<?= h($doc['id']) ?>"><input type="hidden" name="dir" value="<?= $dir ?>">
          <button type="submit" class="btn btn--outline" style="<?= $btn ?>min-width:44px;" aria-label="Премести „<?= h($doc['title_bg']) ?>“ <?= $word ?>"><?= $arrow ?></button></form>
        <?php endforeach; ?>
        <a href="/admin/financial-reports.php?edit=<?= h($doc['id']) ?>" class="btn btn--outline" style="<?= $btn ?>" aria-label="Редактирай „<?= h($doc['title_bg']) ?>“">Редактирай</a>
        <form method="POST" data-confirm="<?= h('Да изтрия ли „' . $doc['title_bg'] . '“? Файлът се изтрива завинаги.') ?>" data-confirm-ok="Да, изтрий">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete_doc"><input type="hidden" name="id" value="<?= h($doc['id']) ?>">
          <button type="submit" class="btn btn--outline" style="<?= $btn ?>color:#b91c1c;border-color:#b91c1c;" aria-label="Изтрий „<?= h($doc['title_bg']) ?>“">Изтрий</button>
        </form>
      </div>
    </li>
  <?php endforeach; ?>
  </ol>
</section>
<?php endforeach; ?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
```

(Checked: `admin/includes/admin-footer.php` intercepts `<form data-confirm>` and reads `data-confirm-ok`; `admin_require_editorial()` is at `config.php:685` and lets admins and authors in.)

In `admin/pages.php`, add a row to the `$rows` list after `['shop', …]`:

```php
          ['reports',       'Финансови отчети',                '/finansovi-otcheti/'],
```

and change the row's link expression to:

```php
<?= $key === 'home' ? '/admin/home-sections.php' : ($key === 'reports' ? '/admin/financial-reports.php' : '/admin/pages.php?page=' . h($key)) ?>
```

- [ ] **Step 4: Run** `php vendor/bin/phpunit tests/FinancialReportsTest.php tests/Admin/TranslateButtonCoverageTest.php tests/Security/WebrootGuardsTest.php`, then the full suite (`rc=0`).

- [ ] **Step 5: Commit**

```bash
git add admin/financial-reports.php admin/pages.php tests/FinancialReportsTest.php
git commit -m "feat(reports): admin screen for years and their report documents"
```

---

### Task 4: The public page, footer link and sitemap

**Files:**
- Create: `templates/financial-reports.php`, `finansovi-otcheti/index.php`, `en/financial-reports/index.php`
- Modify: `templates/footer.php:128-131`, `sitemap.php:16-25`, `content/bg/strings.json`, `content/en/strings.json`
- Test: `tests/FinancialReportsPageTest.php` (create)

**Interfaces:**
- Consumes: `reports_public_list()`, `reports_any_published()` (Task 1)
- Produces: template variables `$lang`, `$reports` (the output of `reports_public_list`). The viewer markup hooks used by Task 5 are `data-report-open="<url>"` on each "Отвори" button, `data-report-title` and a `<div data-report-viewer hidden>` per document.

- [ ] **Step 1: Write the failing tests**

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The public reports page (BG and EN) and its footer link. */
final class FinancialReportsPageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/includes/financial_reports.php';
    }

    private function render(array $reports, string $lang = 'bg'): string
    {
        ob_start();
        (static function (array $reports, string $lang): void {
            require dirname(__DIR__) . '/templates/financial-reports.php';
        })($reports, $lang);
        return (string) ob_get_clean();
    }

    private function one(array $doc = []): array
    {
        return [['year' => 2025, 'documents' => [$doc + [
            'id' => 'aaaaaaaa', 'title' => 'Годишен финансов отчет', 'description' => 'Приходи и разходи',
            'url' => '/assets/files/reports/2025-aaaaaaaa.pdf', 'bytes' => 12_582_912, 'exists' => true,
        ]]]];
    }

    public function testDocumentHasOpenAndDownloadWithSize(): void
    {
        $html = $this->render($this->one());
        $this->assertStringContainsString('<h2', $html);
        $this->assertStringContainsString('2025', $html);
        $this->assertStringContainsString('data-report-open="/assets/files/reports/2025-aaaaaaaa.pdf"', $html);
        $this->assertMatchesRegularExpression('#href="/assets/files/reports/2025-aaaaaaaa\.pdf"[^>]*>\s*Изтегли \(PDF, 12 MB\)#u', $html);
    }

    public function testEnglishSaysTheDocumentIsInBulgarian(): void
    {
        $html = $this->render($this->one(), 'en');
        $this->assertStringContainsString('Download (PDF, 12 MB, in Bulgarian)', $html);
        $this->assertStringContainsString('>Open<', $html);
    }

    public function testEmptyStateIsPlainWords(): void
    {
        $this->assertStringContainsString('Още няма публикувани отчети.', $this->render([]));
        $this->assertStringContainsString('No reports have been published yet.', $this->render([], 'en'));
    }

    /** Review Focus 1 */
    public function testMissingFileShowsANoticeAndNoButtons(): void
    {
        $html = $this->render($this->one(['exists' => false, 'bytes' => null]));
        $this->assertStringContainsString('Файлът не е наличен', $html);
        $this->assertStringNotContainsString('data-report-open', $html);
        $this->assertStringNotContainsString('Изтегли', $html);
    }

    /** Review Focus 3 */
    public function testTextIsEscaped(): void
    {
        $html = $this->render($this->one(['title' => '"><script>x</script>', 'description' => 'A & B']));
        $this->assertStringNotContainsString('<script>x', $html);
        $this->assertStringContainsString('A &amp; B', $html);
    }

    public function testBothPagesUseTheTemplateAndTheHeaderAndFooter(): void
    {
        foreach (['/finansovi-otcheti/index.php', '/en/financial-reports/index.php'] as $p) {
            $src = (string) file_get_contents(dirname(__DIR__) . $p);
            $this->assertStringContainsString('/templates/financial-reports.php', $src, $p);
            $this->assertStringContainsString('/templates/header.php', $src, $p);
            $this->assertStringContainsString('/templates/footer.php', $src, $p);
        }
    }

    public function testFooterLinkOnlyWhenSomethingIsPublished(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/templates/footer.php');
        $this->assertMatchesRegularExpression("#reports_any_published\(\)[^?]*\?>\s*<a href=\"<\?= \\\$lang === 'bg' \? '/finansovi-otcheti/' : '/en/financial-reports/' \?>\"#", $src);
        foreach (['bg' => 'Финансови отчети', 'en' => 'Financial reports'] as $lang => $label) {
            $s = json_decode((string) file_get_contents(dirname(__DIR__) . "/content/$lang/strings.json"), true);
            $this->assertSame($label, $s['footer.reports'] ?? null);
        }
    }

    public function testSitemapListsThePagesOnlyWhenPublished(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/sitemap.php');
        $this->assertMatchesRegularExpression("#if \(reports_any_published\(\)\)\s*\{\s*array_push\(\\\$static, '/finansovi-otcheti/', '/en/financial-reports/'\);#", $src);
    }
}
```

- [ ] **Step 2: Run to see them fail.**

- [ ] **Step 3: Create `templates/financial-reports.php`**

```php
<?php
/**
 * The yearly reports page (BG and EN). Vars: $lang, $reports (reports_public_list()).
 * A document's "Отвори" opens it in the viewer below it (assets/js/report-viewer.js);
 * the download link always works, also without JavaScript. Inline styles only.
 */
$_en  = $lang === 'en';
$_mb  = static fn(?int $b): string => $b === null ? '' : ($b >= 1048576 ? round($b / 1048576) . ' MB' : max(1, (int) round($b / 1024)) . ' KB');
$_btn = 'min-height:44px;min-width:44px;';
?>
<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container">
    <h1><?= $_en ? 'Annual reports' : 'Годишни отчети' ?></h1>
    <p class="lead" style="max-width:640px;margin-top:1rem;"><?= $_en
      ? 'Our yearly financial statements and activity reports. The documents are in Bulgarian.'
      : 'Годишните ни финансови отчети и доклади за дейността.' ?></p>
  </div>
</section>
<section class="section">
  <div class="container container--narrow">
  <?php if (!$reports): ?>
    <p><?= $_en ? 'No reports have been published yet.' : 'Още няма публикувани отчети.' ?></p>
  <?php endif; ?>
  <?php foreach ($reports as $y): ?>
    <h2 style="margin:2rem 0 1rem;"><?= (int) $y['year'] ?></h2>
    <?php foreach ($y['documents'] as $doc): ?>
      <article style="border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;margin-bottom:1rem;">
        <h3 style="margin:0 0 .5rem;font-size:1.1rem;"><?= h($doc['title']) ?></h3>
        <?php if ($doc['description'] !== ''): ?><p style="margin:0 0 1rem;"><?= nl2br(h($doc['description'])) ?></p><?php endif; ?>
        <?php if (!$doc['exists']): ?>
          <p style="margin:0;color:#7f1d1d;font-weight:600;"><span aria-hidden="true">⚠</span> <?= $_en ? 'File not available.' : 'Файлът не е наличен.' ?></p>
        <?php else: ?>
          <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
            <button type="button" class="btn btn--primary" style="<?= $_btn ?>"
                    data-report-open="<?= h($doc['url']) ?>" data-report-title="<?= h($doc['title']) ?>"
                    aria-expanded="false" aria-controls="rv-<?= h($doc['id']) ?>"><?= $_en ? 'Open' : 'Отвори' ?></button>
            <a href="<?= h($doc['url']) ?>" class="btn btn--outline" style="<?= $_btn ?>" download>
              <?= $_en ? 'Download (PDF, ' . $_mb($doc['bytes']) . ', in Bulgarian)' : 'Изтегли (PDF, ' . $_mb($doc['bytes']) . ')' ?></a>
          </div>
          <div id="rv-<?= h($doc['id']) ?>" data-report-viewer hidden style="margin-top:1rem;"></div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </div>
</section>
<?php if ($reports): ?>
<script>window.OM_REPORT_LANG = <?= json_encode($lang) ?>;</script>
<script type="module" src="<?= h(versioned_asset('assets/js/report-viewer.js')) ?>"></script>
<?php endif; ?>
```

(`versioned_asset()` is in `config.php:544`.) Note: a 12 MB file shows as "12 MB", because `round()` is used for MB.

Create `finansovi-otcheti/index.php`:

```php
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/financial_reports.php';
$lang             = 'bg';
$page_title       = 'Годишни отчети';
$page_description = 'Годишни финансови отчети и доклади за дейността на ' . SITE_NAME_BG . '.';
$reports          = reports_public_list(reports_load(), $lang);
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/financial-reports.php';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php';
```

Create `en/financial-reports/index.php`: the same file with `$lang = 'en'`, `$page_title = 'Annual reports'`, and `$page_description = 'Annual financial statements and activity reports of ' . SITE_NAME_EN . '.'`.

Add the pair to the BG↔EN language switch in `templates/header.php` (`$_path_map_bg_to_en`, no trailing slashes, before the `'/'` entry): `'/finansovi-otcheti' => '/en/financial-reports',`.

In `templates/footer.php`, inside `<div class="footer-legal">` after the terms link (line 130), add:

```php
        <?php require_once ROOT_PATH . '/includes/financial_reports.php'; if (reports_any_published()): ?>
        <a href="<?= $lang === 'bg' ? '/finansovi-otcheti/' : '/en/financial-reports/' ?>"><?= t('footer.reports') ?></a>
        <?php endif; ?>
```

In `content/bg/strings.json` add `"footer.reports": "Финансови отчети",` after `footer.terms`. In `content/en/strings.json` add `"footer.reports": "Financial reports",`.

In `sitemap.php`, after the donations block (line 25):

```php
require_once __DIR__ . '/includes/financial_reports.php';
if (reports_any_published()) {
    array_push($static, '/finansovi-otcheti/', '/en/financial-reports/');
}
```

- [ ] **Step 4: Run** `php vendor/bin/phpunit tests/FinancialReportsPageTest.php`, then the full suite (`rc=0`).

- [ ] **Step 5: Commit**

```bash
git add templates/financial-reports.php finansovi-otcheti/index.php en/financial-reports/index.php templates/footer.php templates/header.php sitemap.php content/bg/strings.json content/en/strings.json tests/FinancialReportsPageTest.php
git commit -m "feat(reports): public reports page (BG + EN), footer link and sitemap once published"
```

---

### Task 5: The in-page viewer (PDF.js) and the end-to-end browser test

**Files:**
- Create: `assets/vendor/pdfjs/pdf.min.js`, `assets/vendor/pdfjs/pdf.worker.min.js`, `assets/vendor/pdfjs/LICENSE`, `assets/vendor/pdfjs/VERSION`
- Create: `assets/js/report-viewer.js`
- Test: `tests/Browser/financial-reports.spec.js` (create)

**Interfaces:**
- Consumes: the markup hooks from Task 4 (`data-report-open`, `data-report-title`, `[data-report-viewer]`, `aria-controls`), `window.OM_REPORT_LANG`.

- [ ] **Step 1: Vendor PDF.js 6.4.299** (the legacy build, for older phone browsers). The files are renamed `.mjs` → `.js` so hosts serve them as JavaScript:

```bash
V=6.4.299; D=assets/vendor/pdfjs; mkdir -p $D
curl -sf https://cdn.jsdelivr.net/npm/pdfjs-dist@$V/legacy/build/pdf.min.mjs        -o $D/pdf.min.js
curl -sf https://cdn.jsdelivr.net/npm/pdfjs-dist@$V/legacy/build/pdf.worker.min.mjs -o $D/pdf.worker.min.js
curl -sf https://cdn.jsdelivr.net/npm/pdfjs-dist@$V/LICENSE                         -o $D/LICENSE
echo "pdfjs-dist $V legacy build — https://github.com/mozilla/pdf.js (Apache-2.0)" > $D/VERSION
ls -la $D
```

Expected: `pdf.min.js` is about 520 KB and `pdf.worker.min.js` about 1.3 MB.

- [ ] **Step 2: Write the failing browser test** in `tests/Browser/financial-reports.spec.js`

```js
// @ts-check
// Yearly reports end to end: add a year and a document in the admin, read it in the
// in-page viewer on the BG and EN pages, then delete it.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '../..');
const AUTH_FILE = path.join(ROOT, '.playwright-auth.json');
const DATA = path.join(ROOT, 'content/financial-reports.json');
let backup = null;

// A real 2-page PDF, built by hand: enough for PDF.js to render page 1 and page 2.
function twoPagePdf() {
  const objs = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>',
  ];
  let out = '%PDF-1.4\n', offs = [];
  objs.forEach((o, i) => { offs.push(out.length); out += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  const x = out.length;
  out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n` + offs.map((o) => String(o).padStart(10, '0') + ' 00000 n \n').join('');
  out += `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${x}\n%%EOF\n`;
  return Buffer.from(out, 'latin1');
}

test.use({ storageState: AUTH_FILE });
test.describe.configure({ mode: 'serial' });
test.beforeAll(() => { backup = fs.existsSync(DATA) ? fs.readFileSync(DATA) : null; if (backup) fs.unlinkSync(DATA); });
test.afterAll(() => { if (backup) fs.writeFileSync(DATA, backup); else if (fs.existsSync(DATA)) fs.unlinkSync(DATA); });

test('no reports: no footer link, plain empty page', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('footer a[href="/finansovi-otcheti/"]')).toHaveCount(0);
  await page.goto('/finansovi-otcheti/');
  await expect(page.getByText('Още няма публикувани отчети.')).toBeVisible();
});

test('add a year and a document, read it in the viewer, delete it', async ({ page }) => {
  await page.goto('/admin/financial-reports.php');
  await page.fill('#newYear', '2025');
  await page.getByRole('button', { name: '+ Нова година' }).click();
  await expect(page.getByRole('status')).toContainText('Годината е добавена');

  await page.getByRole('link', { name: '+ Добави документ' }).click();
  await page.fill('#title_bg', 'PW отчет "2025" & <b>');
  await page.fill('#title_en', 'PW report');
  await page.setInputFiles('#pdf', { name: 'otchet.pdf', mimeType: 'application/pdf', buffer: twoPagePdf() });
  await page.getByRole('button', { name: 'Запази' }).click();
  await expect(page.getByRole('status')).toContainText('Документът е добавен');

  await page.goto('/finansovi-otcheti/');
  await expect(page.locator('footer a[href="/finansovi-otcheti/"]')).toHaveCount(1);
  await expect(page.getByRole('heading', { name: 'PW отчет "2025" & <b>' })).toBeVisible();
  const open = page.getByRole('button', { name: 'Отвори' });
  await open.click();
  await expect(open).toHaveAttribute('aria-expanded', 'true');
  await expect(page.locator('[data-report-viewer] canvas')).toBeVisible();
  await expect(page.locator('[data-report-page]')).toHaveText('1 / 2');
  await page.getByRole('button', { name: 'Следваща страница' }).click();
  await expect(page.locator('[data-report-page]')).toHaveText('2 / 2');
  await page.getByRole('button', { name: 'Затвори' }).click();
  await expect(open).toBeFocused();
  await expect(open).toHaveAttribute('aria-expanded', 'false');

  await page.goto('/en/financial-reports/');
  await expect(page.getByRole('heading', { name: 'PW report' })).toBeVisible();
  await expect(page.getByText(/in Bulgarian/)).toBeVisible();

  await page.goto('/admin/financial-reports.php');
  await page.getByRole('button', { name: /Изтрий „PW отчет/ }).click();
  await page.getByRole('button', { name: 'Да, изтрий' }).click();
  await expect(page.getByRole('status')).toContainText('Документът е изтрит');
});

/** Review Focus 5 */
test('a PDF the viewer cannot read says so and points to the download', async ({ page }) => {
  fs.mkdirSync(path.join(ROOT, 'assets/files/reports'), { recursive: true });
  fs.writeFileSync(path.join(ROOT, 'assets/files/reports/2025-0badf00d.pdf'), '%PDF-1.4 not really a pdf');
  fs.writeFileSync(DATA, JSON.stringify({ years: [{ year: 2025, documents: [
    { id: '0badf00d', title_bg: 'Счупен', title_en: '', description_bg: '', description_en: '', file: '2025-0badf00d.pdf' }] }] }));
  await page.goto('/finansovi-otcheti/');
  await page.getByRole('button', { name: 'Отвори' }).click();
  await expect(page.locator('[data-report-viewer]')).toContainText('Документът не може да се покаже тук');
  await expect(page.getByRole('link', { name: /Изтегли/ })).toBeVisible();
  fs.unlinkSync(path.join(ROOT, 'assets/files/reports/2025-0badf00d.pdf'));
});
```

- [ ] **Step 3: Run it to see it fail.** Run `npx playwright test tests/Browser/financial-reports.spec.js --workers=1`. The first test should pass; the second should fail at the viewer (`canvas` not visible, because there's no viewer script yet).

- [ ] **Step 4: Create `assets/js/report-viewer.js`**

```js
// The in-page reader for yearly report PDFs (templates/financial-reports.php). PDF.js is
// served from this site; one viewer open at a time; every control is a real button.
import * as pdfjsLib from '/assets/vendor/pdfjs/pdf.min.js';
pdfjsLib.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.min.js';

const EN = window.OM_REPORT_LANG === 'en';
const T = EN
  ? { prev: 'Previous page', next: 'Next page', zin: 'Zoom in', zout: 'Zoom out', close: 'Close', fail: 'The document cannot be shown here. Use the download link above.', page: 'Page' }
  : { prev: 'Предишна страница', next: 'Следваща страница', zin: 'Увеличи', zout: 'Намали', close: 'Затвори', fail: 'Документът не може да се покаже тук. Използвайте връзката „Изтегли“ по-горе.', page: 'Страница' };
const BTN = 'min-width:44px;min-height:44px;';
let current = null;   // { button, box }

function close() {
  if (!current) return;
  current.box.hidden = true;
  current.box.innerHTML = '';
  current.button.setAttribute('aria-expanded', 'false');
  current.button.focus();
  current = null;
}

async function open(button) {
  if (current && current.button === button) { close(); return; }
  close();
  const box = document.getElementById(button.getAttribute('aria-controls'));
  current = { button, box };
  button.setAttribute('aria-expanded', 'true');
  box.hidden = false;
  box.innerHTML =
    '<div role="group" aria-label="' + button.dataset.reportTitle.replace(/"/g, '&quot;') + '" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.5rem;">'
    + '<button type="button" class="btn btn--outline" data-act="prev" style="' + BTN + '" aria-label="' + T.prev + '">‹</button>'
    + '<span data-report-page aria-live="polite" style="min-width:4rem;text-align:center;font-weight:600;"></span>'
    + '<button type="button" class="btn btn--outline" data-act="next" style="' + BTN + '" aria-label="' + T.next + '">›</button>'
    + '<button type="button" class="btn btn--outline" data-act="zout" style="' + BTN + '" aria-label="' + T.zout + '">−</button>'
    + '<button type="button" class="btn btn--outline" data-act="zin" style="' + BTN + '" aria-label="' + T.zin + '">+</button>'
    + '<button type="button" class="btn btn--outline" data-act="close" style="' + BTN + '">' + T.close + '</button>'
    + '</div><div style="overflow:auto;border:1px solid var(--border);border-radius:8px;background:#f5f5f5;"><canvas style="display:block;margin:0 auto;max-width:none;"></canvas></div>';

  const canvas = box.querySelector('canvas');
  const label = box.querySelector('[data-report-page]');
  let pdf, num = 1, zoom = 1, busy = false;

  async function render() {
    if (busy) return; busy = true;
    const page = await pdf.getPage(num);
    const fit = (box.clientWidth - 2) / page.getViewport({ scale: 1 }).width;
    const dpr = window.devicePixelRatio || 1;
    const vp = page.getViewport({ scale: fit * zoom * dpr });
    canvas.width = vp.width; canvas.height = vp.height;
    canvas.style.width = (vp.width / dpr) + 'px';
    canvas.setAttribute('aria-label', T.page + ' ' + num + ' / ' + pdf.numPages);
    await page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
    label.textContent = num + ' / ' + pdf.numPages;
    busy = false;
  }

  box.querySelector('[role=group]').addEventListener('click', (e) => {
    const act = e.target.closest('button')?.dataset.act;
    if (act === 'close') return close();
    if (!pdf) return;
    if (act === 'prev' && num > 1) num--;
    else if (act === 'next' && num < pdf.numPages) num++;
    else if (act === 'zin') zoom = Math.min(zoom * 1.25, 4);
    else if (act === 'zout') zoom = Math.max(zoom / 1.25, 0.5);
    else return;
    render();
  });
  box.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });

  try {
    pdf = await pdfjsLib.getDocument(button.dataset.reportOpen).promise;
    await render();
  } catch (err) {
    box.innerHTML = '<p role="alert" style="margin:0;color:#7f1d1d;font-weight:600;">' + T.fail + '</p>';
    console.error('report viewer:', err);
  }
}

document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-report-open]');
  if (b) open(b);
});
```

Check against the vendored version before relying on it: PDF.js 6 renders with `page.render({ canvas, viewport })`, while older versions want `canvasContext`. Passing both works in 5.x and 6.x. Confirm by the browser test showing the canvas, and look at the console for a deprecation error.

- [ ] **Step 5: Run the browser test** with `npx playwright test tests/Browser/financial-reports.spec.js --workers=1`. Expected: 3 passed. Then run the full PHP suite (`rc=0`).

- [ ] **Step 6: Check it by hand at phone width.** Start the preview for this worktree, set 375px wide, open a real multi-page scanned PDF and check:
  - the page fits the width with no sideways page scroll;
  - zoom works and then scrolls inside the viewer box;
  - Tab reaches every control, with a visible outline;
  - with "reduce motion" emulated nothing animates.

  Then check the page in EN.

- [ ] **Step 7: Commit**

```bash
git add assets/vendor/pdfjs assets/js/report-viewer.js tests/Browser/financial-reports.spec.js
git commit -m "feat(reports): read report PDFs inside the page with a self-hosted PDF.js viewer"
```

---

### Task 6: Finish

- [ ] **Step 1: Full suites.** Run `php vendor/bin/phpunit > /tmp/pu.txt 2>&1; echo rc=$?` (expect `rc=0`), then `npx playwright test tests/Browser/financial-reports.spec.js --workers=1` (expect all passed).
- [ ] **Step 2: Stop and report.** List the commits, then wait for "push". Trello #18 gets its comment after the push.

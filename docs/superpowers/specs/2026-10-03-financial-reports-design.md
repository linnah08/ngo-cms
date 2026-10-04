# Annual financial reports — design

Trello #18: "add a link in the footer for accessing Annual Financial reports where the
reports will be pdf's that we display inside a page". Scoped 03.10.2026.

## Goal

An NGO publishes its yearly reports on its own site. Each year holds several documents
(for example the annual financial statement, the activity report, an auditor's report).
Visitors read them inside a page on any device, including phones, or download them.
A footer link leads there. Built generically in ngo-cms; oddminds takes it later as a port.

## Decisions (agreed in scoping)

| Question | Decision |
|---|---|
| What a year holds | Several documents, each one PDF with its own title and description |
| Showing a PDF "inside a page" | PDF.js, served from the site itself (no CDN), loaded only on this page |
| English | One shared PDF per document (they are scans of the Bulgarian original). The EN page shows English titles and descriptions and marks each PDF "(in Bulgarian)" |
| Address | `/finansovi-otcheti/` (BG), `/en/financial-reports/` (EN) |
| Footer link | Shown only while at least one document exists |

## What the visitor sees

`/finansovi-otcheti/` and `/en/financial-reports/`. Both pages include
`templates/header.php` and `templates/footer.php`. Layout-critical styles are inline,
because main.css may be stale-cached.

- An `<h1>` ("Годишни отчети" / "Annual reports") and a short intro line.
- Years, newest first, each an `<h2>`. Under a year, its documents in the admin's order.
  Each document shows:
  - its title (`<h3>`) and description;
  - **"Отвори" / "Open"**, which opens the document in the viewer on the page;
  - **"Изтегли (PDF, 12 MB)" / "Download (PDF, 12 MB, in Bulgarian)"**, a plain link to
    the file. The size is worked out from the file on disk.
- The **viewer** opens in place under the document. It shows the pages one after another,
  has previous/next, zoom in/out and page "3 / 12" (`aria-live="polite"`), and a
  "Затвори" / "Close" button that hands focus back to the "Отвори" button. Only one
  viewer is open at a time.
  - Every control is a real `<button>` with a label, at least 44×44.
  - No animation under `prefers-reduced-motion`.
  - If PDF.js can't load the file, the viewer area says so in words and points to the
    download link; the page itself keeps working.
- No reports yet: one sentence saying none are published yet. The footer link is hidden
  in that state anyway, so this is mostly for someone typing the address.
- A **missing file** (deleted on the server): the document still shows with its title,
  and says "Файлът не е наличен" / "File not available" instead of the buttons.

## Admin

A new screen, `admin/financial-reports.php`, linked from Съдържание next to the other
content pages. It is for admins and authors (`admin_require_editorial()`), like posts.

- **"+ Нова година"** adds a year, as a 4-digit number from 1990 up to next year.
  Duplicates are refused with a plain message.
- Inside a year, **"+ Добави документ"** opens the form:
  - Заглавие (BG, required), Title (EN, `data-translate-from`).
  - Описание (BG), Description (EN, ✦ Translate). Next to it, in plain words:
    "Сканираният PDF не може да се чете от екранни четци и търсачки — напишете накратко
    какво съдържа документът."
  - The PDF file (required for a new document; optional when editing, to replace it).
- Each document row has **↑ / ↓** to reorder, **Редактирай** and **Изтрий**. Deleting
  uses the shared `_adminConfirm` modal and removes the file from disk. An empty year can
  be deleted. A year with documents asks first, then deletes the year and its files.
- Every form posts with `csrf_field()`; every handler starts with `csrf_verify()`.
- Messages are persistent and visible (no auto-dismiss): "Документът е добавен",
  "Файлът е твърде голям за сървъра", "Позволени са само PDF файлове".

## Files and storage

- **`content/financial-reports.json`**, gitignored like `content/pages.json`, since
  the server is the source of truth. Shared by BG and EN:
  ```json
  { "years": [
      { "year": 2025, "documents": [
          { "id": "a1b2c3d4", "title_bg": "Годишен финансов отчет", "title_en": "Annual financial statement",
            "description_bg": "…", "description_en": "…", "file": "2025-a1b2c3d4.pdf" } ] } ] }
  ```
  `id` is 8 random hex characters and stays the same for the document's whole life.
  Years are kept sorted newest first on save.
- **PDFs** live in `assets/files/reports/`, which is gitignored. They are named
  `<year>-<id>.pdf` by the server, and the uploaded name is never used. They are public
  on purpose. A `.htaccess` in that folder serves only `.pdf` and turns off script
  execution, as defence in depth.
- **Upload checks** (server side): `UPLOAD_ERR_OK`, `finfo` MIME `application/pdf` and the
  file starting with `%PDF-`, and a successful move into the folder. PHP's own upload
  errors (including "file too big" from the server's existing limits) become plain
  Bulgarian messages; there is no limit of our own and no change to the server's limits.
  Any failure keeps the rest of the form's input and shows the message.
- `assets/files/reports/` and `content/financial-reports.json` are gitignored, so a
  release (built from git) never carries a site's reports.

## Code

- `includes/financial_reports.php` holds pure helpers, testable without a request:
  - `reports_load(): array` and `reports_save(array): bool` (one JSON file).
  - `reports_add_year(array, int): array|string`: returns the new data, or an error message.
  - `reports_add_document(array, int $year, array $fields, string $file): array`.
  - `reports_update_document(array, string $id, array $fields, ?string $file): array`.
  - `reports_delete_document(array, string $id): array{data: array, file: ?string}`.
  - `reports_delete_year(array, int): array{data: array, files: string[]}`.
  - `reports_move_document(array, string $id, int $delta): array`.
  - `reports_check_upload(array $fileEntry): ?string`: null when OK, else a plain BG message.
  - `reports_public_list(array, string $lang): array`: years with documents for display,
    with the language's title and description (EN falls back to BG), file size, and
    `exists`.
  - `reports_any_published(): bool`, for the footer link.
- `finansovi-otcheti/index.php` (BG) and `en/financial-reports/index.php` (EN): thin pages
  over a shared template `templates/financial-reports.php`.
- `templates/footer.php`: the "Финансови отчети" / "Financial reports" link in the legal
  links row, only when `reports_any_published()`. Copy from `content/{bg,en}/strings.json`
  (`footer.reports`).
- `assets/vendor/pdfjs/`: PDF.js (the `pdfjs-dist` legacy build, so older phone browsers
  work). The version is pinned and its licence kept beside it. It is loaded by a
  `<script type="module">` only on the reports page.
- `sitemap.php`: both addresses, only while reports exist.
- `admin/includes/admin-header.php`: the Съдържание submenu entry.

## Edge cases

- Two admins edit at once: the last save wins. Writes go through `reports_save()` with
  `LOCK_EX`, so the file is never half-written.
- An upload fails after the JSON was changed: the JSON is saved only after the file has
  been moved successfully, so no entry ever points at nothing.
- A replaced file: the old file is deleted only after the new one is in place and the
  JSON is saved.
- A file deleted by hand on the server: the document shows "Файлът не е наличен" (see
  "What the visitor sees").
- Hand-edited JSON with a bad file name: the name is checked against `^\d{4}-[0-9a-f]{8}\.pdf$`
  before it is used in a path or URL. Anything else counts as missing.

## Out of scope

- Search inside scanned PDFs (OCR).
- English PDFs.
- One list of all documents across years, and filtering.
- Porting to oddminds (later, by hand, as usual).

## Tests

- **Helpers:**
  - add/duplicate/out-of-range year;
  - add/update/move/delete document;
  - delete year returns its files;
  - the public list orders newest first, falls back to BG, flags missing files, and
    rejects bad file names;
  - `reports_check_upload` refuses non-PDF content with a `.pdf` name and turns PHP
    upload errors into plain messages.
- **Template:**
  - empty state;
  - escaping of titles and descriptions;
  - EN marks "(in Bulgarian)" and uses the English labels;
  - the download link shows the size;
  - a missing file shows the notice and no buttons.
- **Footer:** the link is absent with no reports and present with one, in BG and EN.
- **Security:**
  - the admin page requires an editorial login;
  - every POST without a valid CSRF token is refused;
  - `TranslateButtonCoverageTest` passes for the EN fields;
  - the webroot guard test covers the new public pages.
- **Browser (Playwright):**
  - add a year and a document with a small PDF, see it on `/finansovi-otcheti/` and
    `/en/financial-reports/`;
  - open the viewer, go to page 2, close it (focus returns to "Отвори");
  - delete via the confirm modal.

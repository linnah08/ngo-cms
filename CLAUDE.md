# CLAUDE.md — NGO Platform

Project-level rules for Claude Code. These override defaults.

---

## Model selection

The running model does not switch itself per task — it is fixed for the session until **you** change it with `/model`. So:

- Default to **Sonnet** (`claude-sonnet-4-6`) for day-to-day work.
- `/model opus` before complex architecture, large multi-file refactors, or security-sensitive design.
- For cheap bulk work (lookups, greps, reading files), delegate to a **subagent pinned to Haiku** via the Agent tool (`model: haiku`) — that is the only way to route a single task to a cheaper model without changing the whole session.

---

## Workflow Rules

- **Always work in your own worktree.** Several Claude sessions run in these repos at the same time, by design — assume another one is active, don't check first. Before changing any file, create a worktree off the latest main: `git fetch && git worktree add ../<repo>-wt/<task> -b <task> origin/main`, then symlink `vendor/`, `node_modules/` and the gitignored `*.config.php` files into it so tests and the dev server work there. Work, test and commit there — never in the main checkout, where sessions stash/reset each other's uncommitted work, `git add` sweeps up the other's files, and a push ships their unreviewed commits. Read-only questions can stay in the main checkout.
- The work still lands on `main`: no PRs or long-lived branches for routine work. On "push", `git fetch && git rebase origin/main` in your worktree, run the tests, then `git push origin HEAD:main`. Afterwards remove the worktree (`git worktree remove ../<repo>-wt/<task>`) and delete its branch.
- **Push only your own commits.** Before pushing, `git log --oneline origin/main..HEAD` must list only commits you wrote. Commits from another session are that session's to push — never push them, and don't stop to ask about them. Stage your files by name, never `git add -A`.
- When fixing a bug, grep the codebase for all call sites that use the same pattern or API. List them, then fix all of them in one pass. If there are more than 5 affected sites, confirm the list before touching anything.
- When fixing an issue in one API/code path, search for and fix ALL similar code paths in the same pass (e.g., if fixing FB API, also check Instagram, LinkedIn, etc.).
- **BG/EN parity.** This site has parallel BG (`novini/`, root pages) and EN (`en/`) versions of every public page. When fixing or adding anything to a BG page, always check and apply the same change to the corresponding EN page, and vice versa. Treat them as a pair — never fix one without checking the other.
- Read project documentation files (README, CLAUDE.md, docs/) BEFORE asking questions that may already be answered there.

---

## Shared code — ngo-cms first

One CMS lives in three repos:
- **ngo-cms** (`~/Code/oddminds-oss`, github `linnah08/ngo-cms`) — the upstream. Generic, de-branded, released as tags.
- **oddminds** (`~/Code/oddminds`) — the original site. Different history (Odd Minds copy, Lafetki subdomain, other migration numbers), so it can't `git merge` ngo-cms; changes are ported by hand.
- **lafetki** (`~/Code/lafetki`, github `linnah08/lafetki`) — a git fork of ngo-cms (`upstream` remote).

Rules:
- **Generic changes are built in ngo-cms first**, then ported to oddminds and merged into lafetki. "Generic" = anything that isn't one site's own copy, branding, theme, content or infra. If a live hotfix has to land on oddminds or lafetki first, port it to ngo-cms in the same session — never leave it for "the next sync".
- **lafetki takes ngo-cms only by `git merge`** (`git fetch upstream --tags && git merge upstream/main` or a release tag) — never by cherry-picking or copying files. Cherry-picks make git lose track of what's shared and turn every later sync into a manual diff.
- **Keep lafetki's own changes out of shared files** where possible: a feature flag in `site.config.php`, `includes/themes.php` + `assets/css/theme-lafetki.css`, or content. A lafetki change that another NGO could use goes upstream to ngo-cms instead.
- **Mark every oddminds commit with its sync status** as a last trailer line: `Ported: ngo-cms <sha>` when the same change exists in ngo-cms (either direction), or `Sync: skip` when it's oddminds-only. A commit with neither is still owed to ngo-cms.
- **Migrations:** a fork-only migration (lafetki) must not reuse a number/name ngo-cms has or is likely to use next; check `ls migrations` in ngo-cms before naming it.

## Target users — non-technical audience

The site is used by social workers, parents, and other non-technical people. When suggesting solutions or designing features:

- **Always assume the user is non-technical.** Never instruct users to edit HTML source, CSS, JSON, or any code — if the only solution requires touching source view, build a proper UI for it instead
- Prefer click-based, form-based, or WYSIWYG solutions over anything that requires technical knowledge
- Error messages and UI copy must be plain language, no jargon
- When a CMS/editor feature is missing (e.g. anchor links in TinyMCE), the right answer is to add the plugin or build a helper UI — not to tell the user to edit HTML manually

---

## UI/UX Conventions

- Currency display: show both EUR and BGN where applicable — confirm format with user before implementing currency features.
- For error states (cart, forms), use persistent, visible error UI — never silent clamping or auto-dismissing flash messages.

### Accessibility — always prioritise it

Accessibility is part of every UI change, not a pass that happens later. This site is used by
parents, social workers and donors on old phones, with screen readers, with keyboards only, and
with impaired vision. **When a visual or layout preference conflicts with accessibility,
accessibility wins.** This applies to admin screens as much as public pages — the admin is used
by the same non-technical people.

- **Keyboard first.** Every control is reachable and operable with Tab / Enter / Space, in a
  sensible order, with a visible focus outline. Never remove `outline` without replacing it with
  something at least as visible. Modals and overlays keep focus inside while open and hand it back
  where it came from on close.
- **Semantic HTML before ARIA.** Use a real `<button>`, `<label>`, `<nav>`, real heading levels.
  Reach for ARIA only when no element expresses the thing — e.g. `role="progressbar"` with
  `aria-valuenow`, `role="status"` for a region that updates on its own.
- **Announce what changes.** Anything that updates without a page load (progress, validation
  errors, AJAX results, cart totals) must sit in a live region, or a screen reader user simply
  never learns it happened.
- **Never colour alone.** Error, success, progress and required state always carry text or an
  icon as well as colour. Target WCAG AA contrast: 4.5:1 for body text, 3:1 for large text and for
  UI borders like input outlines.
- **Label every input** with a real `<label for>`, not a placeholder — a placeholder disappears
  exactly when the user needs it. Icon-only buttons need an `aria-label`.
- **Images** get meaningful `alt` text, or `alt=""` when they are purely decorative. Never leave
  the attribute off.
- **Respect the user's own settings.** Don't block pinch-zoom, honour `prefers-reduced-motion`,
  and keep tap targets around 44x44px.

---

## Feature scope — ask before building

Before implementing any new feature or UI element, ask at least one clarifying question about scope and UX. Do not start writing code until the answers make the implementation unambiguous. Typical questions: where does this appear, what triggers it, what does it include, one per order or per item, etc.

Before writing any code, outline: (1) the approach in 3-5 bullets, (2) edge cases and failure modes, (3) which files will change and why, (4) any UX decisions that need confirmation. Wait for approval before implementing.

For large features touching DB, logic, UI, and tests: plan as parallel subagent tasks — (1) DB schema + migration, (2) backend logic + email templates, (3) admin UI, (4) tests. Spawn agents in parallel where possible and report back with a consolidated diff before review.

---

## Database Migrations

- **Engines differ: production is MariaDB 10.5, local dev is MySQL 9.x.** Write SQL portable to BOTH.
- **Do NOT use `ADD COLUMN IF NOT EXISTS` / `DROP COLUMN IF EXISTS` on `ALTER TABLE`** — that is MariaDB-only and is a hard 1064 syntax error on MySQL, which halts `migrate.php` (it bit 026). `CREATE TABLE IF NOT EXISTS` is fine on both.
- Idempotency comes from the runner, not the SQL: `migrate.php` tracks applied files in `_migrations` and ignores "Duplicate column" / "already exists" errors. So write plain DDL (`ADD COLUMN images JSON ...`); a re-run on a DB that already has the column is skipped safely.
- Test migrations against a production-like schema snapshot before deploying.

---

## Database querying — local vs prod MCP

**Operator-only — a fresh clone has none of this.** `.mcp.json` and `scripts/` are both
gitignored, so the two MCP servers and the helper scripts named below exist only on the
maintainer's machine. If they are missing, you are on a clone without that setup: skip this
section and use whatever local database the checkout is already pointed at. Do not try to
recreate `.mcp.json`, and do not go looking for `scripts/`.

Two read-only MySQL MCP servers are configured in `.mcp.json`: `mysql` (local snapshot) and
`mysql-prod` (live production, reached over an SSH tunnel). Bring the tunnel up yourself with
`scripts/prod-db-tunnel.sh up` before querying `mysql-prod` — nothing opens it automatically.
Choose deliberately:

- **Live troubleshooting** — diagnosing a specific record/order that is wrong in production *right now* → use `mysql-prod`. Keep queries narrow and targeted (filter by id/email/date). Never run table scans or aggregations against it; it is the live DB real users are on.
- **Feature data analysis** — row counts, distributions, the general shape of the data → use local `mysql`. If the snapshot looks stale, refresh it with `scripts/sync-from-prod.sh` first (operator-only, see above), then analyse locally. Do not run analytical queries against `mysql-prod`.
- Both servers are read-only by config. Never loosen the `ALLOW_INSERT/UPDATE/DELETE/DDL` flags on `mysql-prod`.

---

## Deployment workflow

- **Always commit locally first.** Never `git push` unless explicitly asked or it is a critical hotfix.
- After every `git commit`, stop and wait for the user to say "push" or "deploy".

---

## Testing

- **Run `php vendor/bin/phpunit` after every non-trivial change.** If tests fail, fix before moving on.
- **Write tests for every new feature or AJAX endpoint.** If you add a new PHP function, DB query, or API handler, add a corresponding test in `tests/`.
- Tests live in `tests/` and use PHPUnit 13. Bootstrap is `tests/bootstrap.php`.
- Do not ship code that breaks existing tests, even if the change is "just UI".

---

## Security — job 0

Before considering any feature complete:

- **Validate all input at the boundary.** Use `(int)`, `trim()`, `filter_var()`, whitelist arrays for enums. Never trust `$_POST`, `$_GET`, or `$_REQUEST`.
- **CSRF on every state-changing POST.** All admin forms must call `csrf_verify()` at the top of the POST handler. AJAX endpoints must also verify CSRF (pass `csrf_token` in the request body).
- **Access control on every admin endpoint.** Every admin PHP file must call `admin_require_login()` or `admin_require_admin()` before any output or data access.
- **Parameterised queries only.** Never interpolate user data into SQL. Use PDO `prepare()` + `execute()`.
- **Escape all output.** Use `h()` for HTML output. Never echo raw user data.
- **No sensitive data in logs or error messages** returned to the browser.
- After adding or modifying any form, endpoint, or redirect: manually trace the attack surface — what happens if an unauthenticated user hits this URL? What if they send unexpected types or values?

---

## Code patterns

### DB access
Always use `get_pdo()` (defined in `admin/includes/db.php`). Never guess helper names — grep first.

### Feature gates on UI
Gate *functionality*, not *visibility*. Translate buttons, config-dependent features, etc. should always render; let the server or JS return a graceful error if the feature isn't configured. Hiding UI based on a backend condition that differs between environments causes invisible bugs.

### CSS for JS-driven overlays
Any modal or overlay triggered by JavaScript must use **fully inline styles** on the element itself. Do not rely on `admin.css` for critical rendering — the file may be stale-cached on the server.

### Public page structure
Every public-facing page must include `templates/header.php` and `templates/footer.php`. Never build a standalone page with its own `<!DOCTYPE html>` / `<head>` / `<body>`. Set `$page_title`, `$page_description`, and optionally `$page_head_extra` (for inline `<style>` or extra scripts) **before** requiring the header. The header already handles `<!DOCTYPE>`, `<head>`, fonts, GTM, and `<body>` opening; the footer closes `</body></html>` and renders the cookie banner.

### CSS for new public page sections
Any new section added to a public page must also use **fully inline styles** for layout-critical rules (grid, flex, dimensions). Do not rely on new classes added to `main.css` — the file on the server may be stale-cached and the section will break in production.

### Rich text editors
Always use **TinyMCE** (iframe-based). Never use DOM-rendered editors (Jodit, Quill, etc.) — the global `img { display:block; max-width:100% }` reset in `main.css` destroys their toolbar icons and there is no clean scoping fix.

Every `<textarea>` that holds rich/HTML content **must** have TinyMCE initialised on it — no exceptions. When adding a new textarea, immediately add its selector to a `tinymce.init()` call. When adding rows dynamically (via JS), assign a unique ID and call `tinymce.init()` right after appending to the DOM. Never leave a plain textarea where rich text is expected.

**Single shared config — always.** `window._tinyBase` is defined in `admin/includes/admin-header.php` (inside `<head>` so it's available when `tinymce.init()` calls run). Every change to toolbar, plugins, or options (e.g. adding `fontsize`, changing toolbar buttons, adding a plugin) must be made there and only there. Never duplicate or override `tinymce.init()` in individual admin pages — all instances must stay in sync.

### EN fields — always translatable
Every English text field in the admin must be auto-translatable from its Bulgarian counterpart. For a new EN field, add `data-translate-from="<bg field name or id>"` to it — the shared script in `admin/includes/admin-footer.php` then adds a "✦ Translate" button (works for inputs, textareas, TinyMCE and dynamically added rows; asks before overwriting existing EN text). `tests/Admin/TranslateButtonCoverageTest.php` fails for any `*_en` field without a translate hook; only fields with no BG counterpart go in its `EXEMPT` list, with the reason.

### Admin page-level variables
Set `$page_head_extra`, `$page_title_admin`, and `$active_nav` **before** `require admin-header.php`. Setting them after is a silent no-op — the `<head>` has already been rendered.

### Template variable scope
Before writing a form template, verify which variable holds the data at that point in the file. In `pages.php`, data is loaded into `$all_pages` (not `$pages`) — reading the wrong variable produces silently empty forms that overwrite saved content with blanks on submit.

### Destructive actions
Never use `window.confirm()` for delete confirmations — Chrome can suppress it silently. Use the custom modal (`_adminConfirm`) defined in `admin/includes/admin-footer.php`.

### Routing fixes
Before fixing any URL or redirect, grep for **all** occurrences of the hardcoded path first. Trace the complete request chain: page → form action → POST handler → redirect. Do not declare a routing fix done until the full round-trip is verified.

### Loops and conditionals
Before adding UI inside a `foreach`, read the full loop structure. Check for existing `$i === 0`, role guards (`admin_is_admin()`), or feature flags that might silently exclude your new element from rows other than the first.

### External APIs (DeepL, couriers, payment, Buffer, Anthropic)
Before wiring up a new API call, test one real request with representative data — especially edge cases like HTML content, empty strings, and large payloads. DeepL `tag_handling=html` fails on bare root text nodes; always wrap HTML in `<div>` before sending.

For any new GraphQL mutation or unfamiliar REST endpoint: verify field names, response shape, and error formats against the actual API with a real request (curl) before writing production code. Buffer GraphQL in particular returns HTTP 200 for errors — always check the response body.

Before using an external CDN URL for a script or stylesheet, verify it actually exists: `curl -sI <url>` and check for HTTP 200.

---

## Architecture

- PHP 8.4, no framework, MySQL + PDO.
- `config.php` — constants, helpers, auth functions. Included everywhere via `require_once`.
- `SITE_URL` in `config.php` is the single source of truth for the domain. All absolute URLs must derive from it.
- Content stored as JSON flat files under `content/`. Articles under `content/articles/{lang}/{slug}.json`.
- Admin session: `$_SESSION[ADMIN_SESSION_NAME]`. Roles: `admin`, `author`.
- CSRF token: `csrf_token()` / `csrf_field()` / `csrf_verify()`.
- Flash messages: `flash_set()` / `flash_get()`.
- DeepL integration: `deepl_translate()` / `deepl_is_configured()` in `includes/translator.php`.
- Translate AJAX endpoint: `POST /admin/translate-ajax.php`.
- Email: `send_mail()` / `render_email()` in `includes/mailer.php`. Templates in `includes/emails/`.
- PDF generation: mPDF via Composer. Generators in `includes/documents/` (base: `DocumentGenerator.php`, then `InvoiceGenerator`, `ReceiptGenerator`, `DonationCertGenerator`). Admin endpoints: `admin/generate-document.php` (POST, creates PDF + DB record) and `admin/download-document.php` (serves file with auth). Generated PDFs stored in `documents/{invoices|receipts|donation_certs}/{year}/`, `.htaccess`-protected, excluded from git. DB: `document_sequences` table for atomic numbering, `documents` table for records, `orders.invoice_data` JSON for B2B/donor data.
- **`admin/.user.ini` pins `DOCUMENT_ROOT` via `auto_prepend_file = pin-docroot.php`**, because some hosting vhosts don't reliably report the real project root and every `admin/*.php` does `require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php'` — a chicken-and-egg problem that has to be solved before that require even runs. **`auto_prepend_file` resolves relative to the executing script's own directory, not the directory the `.user.ini` lives in.** So this only fixes direct `admin/*.php` files — any new subdirectory under `admin/` that's directly URL-routable (like `admin/api/`) needs its own `<subdir>/.user.ini` + `<subdir>/pin-docroot.php` pair (adjust the `dirname(__DIR__, N)` depth). Forgetting this produces a bare, unlogged 500 in production only (the built-in PHP dev server doesn't apply `.user.ini`, so it works locally and silently breaks on deploy).

One-off operational runbooks (e.g. the domain-migration checklist) live in `docs/runbooks/`, not here.

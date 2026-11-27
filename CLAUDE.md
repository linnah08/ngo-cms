# CLAUDE.md — NGO Platform

Project-level rules for Claude Code. These override defaults.

Rules for one area load only when you work there — see `.claude/rules/` (admin pages, public pages,
migrations, PDF documents). Operator-only notes (prod DB access, machine paths) live in the
gitignored `CLAUDE.local.md` when it exists; a fresh clone has none and doesn't need it.

For cheap bulk work (lookups, greps, reading many files), delegate to a subagent pinned to Haiku
(`model: haiku`).

---

## Workflow

- **Always work in your own worktree.** Several Claude sessions run in these repos at the same time, by design — assume another one is active, don't check first. Before changing any file: `git fetch && git worktree add ../<repo>-wt/<task> -b <task> origin/main`, then symlink `vendor/`, `node_modules/`, the gitignored `*.config.php` files and `CLAUDE.local.md` (if present) into it. Work, test and commit there — never in the main checkout, where sessions stash/reset each other's work, `git add` sweeps up the other's files, and a push ships their unreviewed commits. Read-only questions can stay in the main checkout.
- **Commit locally, then stop.** Never push until the user says "push" or "deploy" (or it is a critical hotfix).
- **Pushing lands on `main`** — no PRs or long-lived branches for routine work. In your worktree: `git fetch && git rebase origin/main`, run the tests, check `git log --oneline origin/main..HEAD` lists only your own commits, then `git push origin HEAD:main`. Then `git worktree remove` it and delete its branch.
- **Push only your own commits.** Another session's commits are theirs to push — never push them, and don't stop to ask about them. Stage files by name, never `git add -A`.
- **Fix every instance, not just the one reported.** When fixing a bug, grep for every call site using the same pattern or API, and every parallel code path (fixing Facebook → also check Instagram, LinkedIn…). List them and fix them in one pass; if more than 5 sites are affected, confirm the list first.
- **Routing fixes:** grep for every occurrence of the hardcoded path, and trace page → form action → POST handler → redirect. Not done until the full round-trip is verified.
- **BG/EN parity.** Every public page has a BG version (`novini/`, root pages) and an EN version (`en/`). Change one → check and change the other.
- Read README, CLAUDE.md and `docs/` before asking questions they may already answer.

---

## Shared code — ngo-cms first

One CMS lives in three repos:
- **ngo-cms** (`~/Code/oddminds-oss`, github `linnah08/ngo-cms`) — the upstream. Generic, de-branded, released as tags.
- **oddminds** (`~/Code/oddminds`) — the original site. Different history (Odd Minds copy, Lafetki subdomain, other migration numbers), so it can't `git merge` ngo-cms; changes are ported by hand.
- **lafetki** (`~/Code/lafetki`, github `linnah08/lafetki`) — a git fork of ngo-cms (`upstream` remote). It is the site that proves the update flow before other NGOs are onboarded, so it takes ngo-cms code the way they will: as a tagged release, through the update button in its admin.

Rules:
- **Generic changes are built in ngo-cms first**, then ported to oddminds and reach lafetki with the next release. "Generic" = anything that isn't one site's own copy, branding, theme, content or infra. If a live hotfix has to land on oddminds or lafetki first, port it to ngo-cms in the same session — never leave it for "the next sync".
- **lafetki takes ngo-cms only as a release.** Tag a release in ngo-cms → update lafetki from its admin (the server installs the release). That's all: lafetki's `sync-release` workflow merges the tag the server runs into main — hourly, and as the first step of every deploy — so sessions never merge release tags by hand. If a lafetki push is rejected because main moved, the sync probably pushed a release merge: `git fetch && git rebase origin/main` and push again. The sync fails, saying what to do, only when a lafetki change clashes with the release or its deploy key (secret `SYNC_DEPLOY_KEY`, which lets it push workflow files too) is missing or no longer works. Never merge untagged `upstream/main` into lafetki, and never cherry-pick or copy files.
- **Keep lafetki's own changes out of shared files** — a shared file a site has edited is kept by the self-updater and can clash with the next release (that took lafetki.com down with v0.17.0). Use the site-owned extension points, which no release ships: its theme preset in `includes/themes-site.php` (pointing at its own `assets/css/theme-lafetki.css`, fonts CSS and `home_types` file), its wording in `content/{bg,en}/strings.site.json`, and its switches in `site.config.php`. A lafetki change another NGO could use goes upstream to ngo-cms instead; if a shared file has no extension point for what a site needs, add one in ngo-cms.
- **Mark every oddminds commit with its sync status** as a last trailer line: `Ported: ngo-cms <sha>` when the same change exists in ngo-cms (either direction), or `Sync: skip` when it's oddminds-only. A commit with neither is still owed to ngo-cms.

---

## Target users — non-technical

The site and its admin are used by social workers, parents, donors and other non-technical people.

- Never tell users to edit HTML, CSS, JSON or code. If that's the only way, build a UI for it (e.g. missing anchor links in TinyMCE → add the plugin or a helper, not "edit the source").
- Prefer click-, form- and WYSIWYG-based solutions.
- Error messages and UI copy in plain language, no jargon. Error states (cart, forms) are persistent and visible — never silent clamping or auto-dismissing flash messages.

### Accessibility — always

Part of every UI change, admin included, not a later pass. **When a visual or layout preference conflicts with accessibility, accessibility wins.**

- **Keyboard first.** Every control reachable and operable with Tab / Enter / Space, in a sensible order, with a visible focus outline (never remove `outline` without an equally visible replacement). Modals trap focus while open and return it on close.
- **Semantic HTML before ARIA.** Real `<button>`, `<label>`, `<nav>`, heading levels. ARIA only when no element expresses it (`role="progressbar"` + `aria-valuenow`, `role="status"`).
- **Announce what changes.** Anything updating without a page load (progress, validation, AJAX results, cart totals) sits in a live region.
- **Never colour alone.** Error/success/progress/required state also carries text or an icon. WCAG AA contrast: 4.5:1 body text, 3:1 large text and UI borders.
- **Label every input** with a real `<label for>`, not a placeholder. Icon-only buttons need `aria-label`.
- **Images** get meaningful `alt`, or `alt=""` if decorative — never missing.
- **Respect user settings.** Don't block pinch-zoom, honour `prefers-reduced-motion`, tap targets ~44×44px.

---

## Before building

- **Clear, small change** (a fix, a tweak, a field) → just do it.
- **Scope or UX unclear** (where it appears, what triggers it, per order or per item…) → ask before writing code.
- **Touches the DB, several areas, or user-facing behaviour** → first outline the approach (3–5 bullets), edge cases, files that change, and UX decisions to confirm. Wait for approval.

---

## Testing

- Run `php vendor/bin/phpunit` after every non-trivial change; fix failures before moving on. Don't ship code that breaks tests, even "just UI".
- Every new feature, function, DB query, API handler or AJAX endpoint gets a test in `tests/` (PHPUnit 13, bootstrap `tests/bootstrap.php`).
- When a rule can be checked mechanically, prefer a test over prose — see `TranslateButtonCoverageTest`, `EditModeCoverageTest`, `Security/AdminEndpointGuardsTest`, `NoNativeConfirmTest`, `MigrationPortabilityTest`, the asset-version tests.
- **Tests must pass with any module off.** This checkout runs with every optional module on; NGOs switch some off (lafetki: Дарения, Кампания). A test that needs a module starts with `skip_unless_modules_on('<module>')` (or `skip_unless_any_module_on()`); a test of the switches themselves (it sets `FEATURE_*` or the saved switches) goes in `#[Group('module-switches')]`. Before tagging a release, `tests/modules-off.sh` (the suite with every registry module off, `module-switches` left out) must pass — the pre-push hook runs it when a `v*` tag is pushed and blocks the push on a failure, so push the tag from a clean checkout of its commit.

---

## Security — job 0

Before any feature is complete:

- **Validate input at the boundary** — `(int)`, `trim()`, `filter_var()`, whitelists for enums. Never trust `$_POST` / `$_GET` / `$_REQUEST`.
- **CSRF on every state-changing POST** — `csrf_verify()` at the top of the handler; AJAX passes `csrf_token` in the body.
- **Access control on every admin endpoint** — an `admin_require_*()` guard (`login`, `admin`, `shop`, `editorial`…) before any output or data access.
- **Parameterised queries only** — PDO `prepare()` + `execute()`.
- **Escape all output** with `h()`.
- **No sensitive data** in logs or in errors returned to the browser.
- After adding or changing any form, endpoint or redirect, trace the attack surface: what if an unauthenticated user hits it? Unexpected types or values?

`tests/Security/AdminEndpointGuardsTest.php` enforces the login guard and CSRF on every routable admin script.

---

## Code patterns

- **DB access:** always `get_pdo()` (`admin/includes/db.php`). Never guess helper names — grep first.
- **Gate functionality, not visibility.** Config-dependent buttons (Translate, etc.) always render; the server or JS returns a graceful error when the feature isn't configured. Hiding UI on a backend condition that differs between environments causes invisible bugs.
- **Local CSS/JS load through `versioned_asset()`** (adds `?v=<file time>`) so an update is never hidden by a stale cache. `AdminAssetVersionTest` and `PublicAssetVersionTest` enforce it.
- **Loops:** before adding UI inside a `foreach`, read the whole loop — `$i === 0` checks, role guards (`admin_is_admin()`) or feature flags may silently exclude your element from later rows.
- **External APIs** (DeepL, couriers, payment, Buffer, Anthropic): before wiring a new call or unfamiliar endpoint, make one real request (curl) with representative data — HTML, empty strings, large payloads — and check field names, response shape and error format. Known traps: DeepL `tag_handling=html` fails on bare root text nodes (wrap in `<div>`); Buffer GraphQL returns HTTP 200 for errors (check the body).
- **External CDN URLs:** `curl -sI <url>` must return 200 before you use it.

---

## Architecture

- PHP 8.4, no framework, MySQL + PDO. Production DB is MariaDB 10.5, local is MySQL 9.x — SQL must run on both.
- `config.php` — constants, helpers, auth functions; `require_once`d everywhere. `SITE_URL` there is the single source of truth for the domain; all absolute URLs derive from it.
- Content is JSON flat files under `content/`; articles under `content/articles/{lang}/{slug}.json`.
- Admin session: `$_SESSION[ADMIN_SESSION_NAME]`; role guards are the `admin_require_*()` functions in `config.php`.
- CSRF: `csrf_token()` / `csrf_field()` / `csrf_verify()`. Flash: `flash_set()` / `flash_get()`.
- DeepL: `deepl_translate()` / `deepl_is_configured()` in `includes/translator.php`; AJAX at `POST /admin/translate-ajax.php`.
- Email: `send_mail()` / `render_email()` in `includes/mailer.php`; templates in `includes/emails/`.
- PDFs (invoices, receipts, donation certificates): `includes/documents/` — details in `.claude/rules/documents.md`.
- **`admin/.user.ini` pins `DOCUMENT_ROOT` via `auto_prepend_file = pin-docroot.php`**, because some hosts misreport the project root and every admin script starts with `require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php'`. **`auto_prepend_file` resolves relative to the executing script's directory**, so every directly routable subdirectory of `admin/` (like `admin/api/`) needs its own `.user.ini` + `pin-docroot.php` pair (adjust the `dirname(__DIR__, N)` depth). Missing it gives a bare, unlogged 500 in production only — the PHP dev server ignores `.user.ini`. `AdminEndpointGuardsTest` checks for the pair.

# Admin Autosave + Session Guard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add localStorage autosave to all 6 admin editor pages and a session guard that warns 10 minutes before expiry and handles already-expired sessions.

**Architecture:** Two standalone JS modules (`autosave.js`, `session-guard.js`) loaded globally via `admin-footer.php`. One new PHP endpoint (`session-ping.php`) handles liveness checks and keep-alive. One new helper `admin_session_refresh()` in `config.php`. Each editor page calls `initAutosave(...)` with its form ID and TinyMCE instance IDs.

**Tech Stack:** Vanilla JS, `localStorage` API, PHP 8.4, existing `admin_logged_in()` / `csrf_verify()` / `admin_user()` / `ADMIN_SESSION_HOURS` from `config.php`.

---

## File Map

| File | Action | Purpose |
|---|---|---|
| `admin/js/autosave.js` | Create | Autosave module — exposes `window.initAutosave()` |
| `admin/js/session-guard.js` | Create | Session guard — auto-initialises on page load |
| `admin/session-ping.php` | Create | JSON endpoint: liveness check + keep-alive |
| `config.php` | Modify | Add `admin_session_refresh()` helper |
| `admin/includes/admin-header.php` | Modify | Output `window._sessionExpiresAt` + `window._csrfToken` |
| `admin/includes/admin-footer.php` | Modify | Load `autosave.js` + `session-guard.js` |
| `admin/article-edit.php` | Modify | Add `initAutosave(...)` call |
| `admin/product-edit.php` | Modify | Add `initAutosave(...)` call |
| `admin/newsletter-compose.php` | Modify | Add `initAutosave(...)` call |
| `admin/campaign.php` | Modify | Add `initAutosave(...)` call |
| `admin/pages.php` | Modify | Add conditional `initAutosave(...)` calls per form |
| `admin/email-templates.php` | Modify | Add `initAutosave(...)` call |
| `tests/Admin/SessionPingTest.php` | Create | PHPUnit tests for `admin_session_refresh()` |

---

## Task 1: Create `admin/js/autosave.js`

**Files:**
- Create: `admin/js/autosave.js`

- [ ] **Step 1: Create the file**

```javascript
(function () {
  'use strict';

  window.initAutosave = function (cfg) {
    // cfg: { key: string, formId: string, tinyIds: string[] }
    var storageKey = 'autosave:' + cfg.key;
    var form = document.getElementById(cfg.formId);
    if (!form) return;
    var tinyIds = cfg.tinyIds || [];
    var timer = null;

    function snapshot() {
      var data = {};
      Array.from(form.elements).forEach(function (el) {
        if (!el.name || el.type === 'file' || el.type === 'hidden') return;
        if (el.type === 'checkbox') { data[el.name] = el.checked; return; }
        if (el.type === 'radio') { if (el.checked) data[el.name] = el.value; return; }
        data[el.name] = el.value;
      });
      tinyIds.forEach(function (id) {
        var ed = (typeof tinymce !== 'undefined') ? tinymce.get(id) : null;
        if (ed) data['_tiny_' + id] = ed.getContent();
      });
      return data;
    }

    function save() {
      try {
        localStorage.setItem(storageKey, JSON.stringify({ ts: Date.now(), data: snapshot() }));
      } catch (e) {}
    }

    function schedule() {
      clearTimeout(timer);
      timer = setTimeout(save, 2000);
    }

    // Hook regular form fields
    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);

    // Hook TinyMCE — current and future editors
    function hookEditor(ed) {
      if (tinyIds.indexOf(ed.id) === -1) return;
      ed.on('Change keyup', schedule);
    }
    if (typeof tinymce !== 'undefined') {
      tinymce.get().forEach(hookEditor);
      tinymce.on('AddEditor', function (e) { hookEditor(e.editor); });
    }

    // Clear draft on submit so a successful save never re-offers a stale draft
    form.addEventListener('submit', function () {
      clearTimeout(timer);
      try { localStorage.removeItem(storageKey); } catch (e) {}
    });

    // Restore banner — shown if a draft exists for this key
    (function () {
      var raw;
      try { raw = localStorage.getItem(storageKey); } catch (e) { return; }
      if (!raw) return;
      var saved;
      try { saved = JSON.parse(raw); } catch (e) { return; }
      if (!saved || !saved.data) return;

      var diff = Math.floor((Date.now() - (saved.ts || 0)) / 1000);
      var ago = diff < 60  ? ('преди ' + diff + ' сек.')
              : diff < 3600 ? ('преди ' + Math.floor(diff / 60) + ' мин.')
              : ('преди ' + Math.floor(diff / 3600) + ' ч.');

      var banner = document.createElement('div');
      banner.style.cssText = 'background:#fffbeb;border:1px solid #f59e0b;border-radius:6px;'
        + 'padding:.75rem 1rem;margin-bottom:1.25rem;display:flex;align-items:center;'
        + 'gap:.75rem;font-size:.9rem;flex-wrap:wrap;';
      banner.innerHTML =
        '<span style="flex:1;min-width:180px;">Имате незапазен черновец от ' + ago + '.</span>'
        + '<button type="button" id="_asRestore" style="padding:.4rem .9rem;background:#f59e0b;'
        + 'color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:.85rem;font-weight:600;">'
        + 'Върни черновата</button>'
        + '<button type="button" id="_asDiscard" style="padding:.4rem .9rem;background:#fff;'
        + 'color:#555;border:1px solid #d1d5db;border-radius:5px;cursor:pointer;font-size:.85rem;">'
        + 'Игнорирай</button>';

      form.insertBefore(banner, form.firstChild);

      document.getElementById('_asDiscard').addEventListener('click', function () {
        try { localStorage.removeItem(storageKey); } catch (e) {}
        banner.remove();
      });

      document.getElementById('_asRestore').addEventListener('click', function () {
        restore(saved.data);
        banner.remove();
      });
    }());

    function restore(data) {
      Object.keys(data).forEach(function (name) {
        if (name.indexOf('_tiny_') === 0) return;
        Array.from(form.elements).forEach(function (el) {
          if (el.name !== name) return;
          if (el.type === 'file' || el.type === 'hidden') return;
          if (el.type === 'checkbox') { el.checked = data[name]; return; }
          if (el.type === 'radio')    { el.checked = (el.value === data[name]); return; }
          el.value = data[name];
        });
      });
      tinyIds.forEach(function (id) {
        var key = '_tiny_' + id;
        if (!(key in data)) return;
        var ed = (typeof tinymce !== 'undefined') ? tinymce.get(id) : null;
        if (ed) {
          ed.setContent(data[key]);
        } else if (typeof tinymce !== 'undefined') {
          var handler = function (e) {
            if (e.editor.id === id) {
              e.editor.setContent(data[key]);
              tinymce.off('AddEditor', handler);
            }
          };
          tinymce.on('AddEditor', handler);
        }
      });
    }
  };
}());
```

- [ ] **Step 2: Commit**

```bash
git add admin/js/autosave.js
git commit -m "feat: add localStorage autosave module"
```

---

## Task 2: Create `admin/js/session-guard.js`

**Files:**
- Create: `admin/js/session-guard.js`

- [ ] **Step 1: Create the file**

```javascript
(function () {
  'use strict';

  var expiresAt  = (window._sessionExpiresAt || 0) * 1000; // server Unix ts → ms
  var csrfToken  = window._csrfToken || '';
  var WARNING_MS = 10 * 60 * 1000; // warn 10 min before expiry
  var warned     = false;
  var warnTimer  = null;

  function hasLocalDraft() {
    try {
      return Object.keys(localStorage).some(function (k) {
        return k.indexOf('autosave:') === 0;
      });
    } catch (e) { return false; }
  }

  function showExpiredBanner() {
    var hasDraft = hasLocalDraft();
    var banner = document.createElement('div');
    banner.style.cssText = 'position:fixed;top:0;left:0;right:0;background:#dc2626;color:#fff;'
      + 'padding:.85rem 1.25rem;text-align:center;z-index:99999;font-size:.95rem;line-height:1.5;';
    banner.innerHTML = 'Сесията ви е изтекла.'
      + (hasDraft ? ' Черновата е запазена — ' : ' ')
      + '<a href="/admin/login.php" style="color:#fff;font-weight:700;text-decoration:underline;">'
      + 'Влезте отново, за да продължите.</a>';
    document.body.prepend(banner);
  }

  function scheduleWarning() {
    clearTimeout(warnTimer);
    var delay = expiresAt - Date.now() - WARNING_MS;
    if (delay <= 0) { showWarning(); return; }
    warnTimer = setTimeout(showWarning, delay);
  }

  function showWarning() {
    if (warned) return;
    warned = true;

    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99998;'
      + 'display:flex;align-items:center;justify-content:center;padding:1rem;';

    var box = document.createElement('div');
    box.style.cssText = 'background:#fff;border-radius:10px;padding:2rem;max-width:400px;'
      + 'width:100%;box-shadow:0 24px 64px rgba(0,0,0,.3);text-align:center;';

    var msg = document.createElement('p');
    msg.style.cssText = 'font-size:1rem;margin:0 0 1.25rem;color:#111;line-height:1.5;';

    var btns = document.createElement('div');
    btns.style.cssText = 'display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;';

    var keepBtn = document.createElement('button');
    keepBtn.type = 'button';
    keepBtn.textContent = 'Остани в системата';
    keepBtn.style.cssText = 'padding:.55rem 1.1rem;background:#2563eb;color:#fff;border:none;'
      + 'border-radius:6px;cursor:pointer;font-size:.9rem;font-weight:600;';

    var dismissBtn = document.createElement('button');
    dismissBtn.type = 'button';
    dismissBtn.textContent = 'Затвори';
    dismissBtn.style.cssText = 'padding:.55rem 1.1rem;background:#fff;color:#555;'
      + 'border:1px solid #d1d5db;border-radius:6px;cursor:pointer;font-size:.9rem;';

    btns.appendChild(keepBtn);
    btns.appendChild(dismissBtn);
    box.appendChild(msg);
    box.appendChild(btns);
    overlay.appendChild(box);
    document.body.appendChild(overlay);

    function updateMsg() {
      var rem  = Math.max(0, expiresAt - Date.now());
      var mins = Math.floor(rem / 60000);
      var secs = String(Math.floor((rem % 60000) / 1000)).padStart(2, '0');
      msg.textContent = 'Сесията ви изтича след ' + mins + ':' + secs + '. Продължавате ли да работите?';
      if (rem <= 0) { clearInterval(ticker); overlay.remove(); showExpiredBanner(); }
    }
    updateMsg();
    var ticker = setInterval(updateMsg, 1000);

    keepBtn.addEventListener('click', function () {
      var fd = new FormData();
      fd.append('csrf_token', csrfToken);
      fetch('/admin/session-ping.php', { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          clearInterval(ticker);
          overlay.remove();
          warned = false;
          if (d.alive) {
            expiresAt = d.expiresAt * 1000;
            scheduleWarning();
          } else {
            showExpiredBanner();
          }
        })
        .catch(function () {
          clearInterval(ticker);
          overlay.remove();
          warned = false;
        });
    });

    dismissBtn.addEventListener('click', function () {
      clearInterval(ticker);
      overlay.remove();
      warned = false;
    });
  }

  // Page-load ping: detect already-expired session before user starts typing
  document.addEventListener('DOMContentLoaded', function () {
    fetch('/admin/session-ping.php', { method: 'GET', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.alive) { showExpiredBanner(); return; }
        if (d.expiresAt) expiresAt = d.expiresAt * 1000;
        scheduleWarning();
      })
      .catch(function () { /* server unreachable — skip guard */ });
  });
}());
```

- [ ] **Step 2: Commit**

```bash
git add admin/js/session-guard.js
git commit -m "feat: add session guard module"
```

---

## Task 3: Add `admin_session_refresh()` to `config.php` (TDD)

**Files:**
- Modify: `config.php` (around line 306, after `admin_user()`)
- Create: `tests/Admin/SessionPingTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Admin/SessionPingTest.php`:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('admin')]
final class SessionPingTest extends TestCase
{
    protected function setUp(): void
    {
        start_session();
        unset($_SESSION[ADMIN_SESSION_NAME]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION[ADMIN_SESSION_NAME]);
    }

    public function testRefreshDoesNothingWhenNoSession(): void
    {
        admin_session_refresh();
        $this->assertArrayNotHasKey(
            ADMIN_SESSION_NAME, $_SESSION,
            'admin_session_refresh() must not create a session when none exists'
        );
    }

    public function testRefreshUpdatesSessionTime(): void
    {
        $old = time() - 3600;
        $_SESSION[ADMIN_SESSION_NAME] = [
            'logged_in' => true,
            'email'     => 'admin@oddminds.org',
            'role'      => 'admin',
            'time'      => $old,
        ];

        admin_session_refresh();

        $this->assertGreaterThan(
            $old,
            $_SESSION[ADMIN_SESSION_NAME]['time'],
            'admin_session_refresh() must update the session time to now'
        );
        $this->assertEqualsWithDelta(
            time(),
            $_SESSION[ADMIN_SESSION_NAME]['time'],
            2,
            'Updated time must be within 2 seconds of now'
        );
    }

    public function testRefreshPreservesOtherSessionFields(): void
    {
        $_SESSION[ADMIN_SESSION_NAME] = [
            'logged_in' => true,
            'email'     => 'admin@oddminds.org',
            'role'      => 'admin',
            'time'      => time() - 100,
        ];

        admin_session_refresh();

        $this->assertSame('admin@oddminds.org', $_SESSION[ADMIN_SESSION_NAME]['email']);
        $this->assertSame('admin',              $_SESSION[ADMIN_SESSION_NAME]['role']);
    }
}
```

- [ ] **Step 2: Run the test — verify it FAILS**

```bash
php vendor/bin/phpunit tests/Admin/SessionPingTest.php --testdox
```

Expected: FAIL with `Call to undefined function admin_session_refresh()`

- [ ] **Step 3: Add `admin_session_refresh()` to `config.php`**

Open `config.php`. After the `admin_user()` function (around line 309), add:

```php
function admin_session_refresh(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION[ADMIN_SESSION_NAME])) {
        $_SESSION[ADMIN_SESSION_NAME]['time'] = time();
    }
}
```

- [ ] **Step 4: Run the test — verify it PASSES**

```bash
php vendor/bin/phpunit tests/Admin/SessionPingTest.php --testdox
```

Expected: 3 tests pass

- [ ] **Step 5: Run the full suite — verify no regressions**

```bash
php vendor/bin/phpunit
```

Expected: all existing tests still pass

- [ ] **Step 6: Commit**

```bash
git add config.php tests/Admin/SessionPingTest.php
git commit -m "feat: add admin_session_refresh() helper with tests"
```

---

## Task 4: Create `admin/session-ping.php`

**Files:**
- Create: `admin/session-ping.php`

- [ ] **Step 1: Create the endpoint**

```php
<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!admin_logged_in()) {
    http_response_code(401);
    echo json_encode(['alive' => false]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        http_response_code(403);
        echo json_encode(['error' => 'csrf']);
        exit;
    }
    admin_session_refresh();
}

$sess = admin_user();
echo json_encode([
    'alive'     => true,
    'expiresAt' => ($sess['time'] ?? 0) + ADMIN_SESSION_HOURS * 3600,
]);
```

- [ ] **Step 2: Commit**

```bash
git add admin/session-ping.php
git commit -m "feat: add session-ping endpoint for liveness check and keep-alive"
```

---

## Task 5: Update `admin/includes/admin-header.php` and `admin/includes/admin-footer.php`

**Files:**
- Modify: `admin/includes/admin-header.php` (after line 5: `$current_user = admin_user();`)
- Modify: `admin/includes/admin-footer.php` (before `</body>`)

- [ ] **Step 1: Add JS variables to `admin-header.php`**

In `admin/includes/admin-header.php`, the file currently looks like:

```php
<?php
if (!defined('SITE_NAME_BG')) require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
admin_require_login();
$current_user = admin_user();
?>
<!DOCTYPE html>
```

Change it to:

```php
<?php
if (!defined('SITE_NAME_BG')) require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
admin_require_login();
$current_user = admin_user();
?>
<!DOCTYPE html>
```

Then find the `<script>` block that defines `window._tinyBase` (around line 17) and add the two new variables just before it, still inside `<head>`:

```html
  <script>
  window._sessionExpiresAt = <?= ($current_user['time'] ?? 0) + ADMIN_SESSION_HOURS * 3600 ?>;
  window._csrfToken = <?= json_encode(csrf_token()) ?>;
  </script>
```

The `<head>` section should look like this after the edit (the new `<script>` block goes after `<?= $page_head_extra ?? '' ?>` and before the `_tinyBase` block):

```html
  <?= $page_head_extra ?? '' ?>
  <script>
  window._sessionExpiresAt = <?= ($current_user['time'] ?? 0) + ADMIN_SESSION_HOURS * 3600 ?>;
  window._csrfToken = <?= json_encode(csrf_token()) ?>;
  </script>
  <!-- ── Shared TinyMCE config … -->
  <script>
  window._tinyBase = { … };
  </script>
```

- [ ] **Step 2: Load the JS modules in `admin-footer.php`**

In `admin/includes/admin-footer.php`, find the last two lines:

```html
</body>
</html>
```

Change them to:

```html
<script src="/admin/js/autosave.js"></script>
<script src="/admin/js/session-guard.js"></script>
</body>
</html>
```

- [ ] **Step 3: Commit**

```bash
git add admin/includes/admin-header.php admin/includes/admin-footer.php
git commit -m "feat: expose session expiry + CSRF token to JS; load autosave and session-guard"
```

---

## Task 6: Wire up `admin/article-edit.php`

**Files:**
- Modify: `admin/article-edit.php`

The form already has `id="articleForm"` (line 209). TinyMCE targets `textarea.rich-editor` → IDs are `content` and `content_en`.

- [ ] **Step 1: Add `initAutosave` call**

Find the existing TinyMCE init block at the bottom of `article-edit.php` (around line 613):

```javascript
tinymce.init(Object.assign({}, window._tinyBase, { selector: 'textarea.rich-editor', min_height: 300 }));
```

Add the `initAutosave` call immediately after it:

```javascript
tinymce.init(Object.assign({}, window._tinyBase, { selector: 'textarea.rich-editor', min_height: 300 }));
initAutosave({
  key:     <?= json_encode('article:' . ($is_new ? 'new' : $slug_param)) ?>,
  formId:  'articleForm',
  tinyIds: ['content', 'content_en']
});
```

- [ ] **Step 2: Commit**

```bash
git add admin/article-edit.php
git commit -m "feat: wire autosave on article-edit"
```

---

## Task 7: Wire up `admin/product-edit.php`

**Files:**
- Modify: `admin/product-edit.php`

Form ID is `productForm` (line 242). TinyMCE IDs: `descBg`, `descEn`.

- [ ] **Step 1: Add `initAutosave` call**

Find the TinyMCE init block at the bottom of `product-edit.php` (around line 1088):

```javascript
tinymce.init(Object.assign({}, window._tinyBase, { selector: '#descBg, #descEn', min_height: 200 }));
```

Add immediately after:

```javascript
tinymce.init(Object.assign({}, window._tinyBase, { selector: '#descBg, #descEn', min_height: 200 }));
initAutosave({
  key:     'product:<?= $id ?: 'new' ?>',
  formId:  'productForm',
  tinyIds: ['descBg', 'descEn']
});
```

- [ ] **Step 2: Commit**

```bash
git add admin/product-edit.php
git commit -m "feat: wire autosave on product-edit"
```

---

## Task 8: Wire up `admin/newsletter-compose.php`

**Files:**
- Modify: `admin/newsletter-compose.php`

Form ID is `saveForm` (line 164). TinyMCE IDs: `bodyBg`, `bodyEn`. `$id` is the newsletter ID (line 17).

- [ ] **Step 1: Add `initAutosave` call**

Find the TinyMCE init block at the bottom of `newsletter-compose.php` (around line 210):

```javascript
tinymce.init(Object.assign({}, window._tinyBase, { selector: '#bodyBg, #bodyEn', min_height: 280 }));
```

Add immediately after:

```javascript
tinymce.init(Object.assign({}, window._tinyBase, { selector: '#bodyBg, #bodyEn', min_height: 280 }));
initAutosave({
  key:     'newsletter:<?= $id ?>',
  formId:  'saveForm',
  tinyIds: ['bodyBg', 'bodyEn']
});
```

- [ ] **Step 2: Commit**

```bash
git add admin/newsletter-compose.php
git commit -m "feat: wire autosave on newsletter-compose"
```

---

## Task 9: Wire up `admin/campaign.php`

**Files:**
- Modify: `admin/campaign.php`

Form ID is `generalForm` (line 307). TinyMCE IDs: `campaignDesc`, `campaignDescEn`, `campaignRisks`, `campaignRisksEn`, `eventDesc`. Single-campaign page — no URL ID.

- [ ] **Step 1: Add `initAutosave` call**

Find the TinyMCE init block at the bottom of `campaign.php` (around line 733):

```javascript
tinymce.init(Object.assign({}, window._tinyBase, { selector: '#campaignDesc, #campaignDescEn, #campaignRisks, #campaignRisksEn, #eventDesc', min_height: 200 }));
```

Add immediately after:

```javascript
tinymce.init(Object.assign({}, window._tinyBase, { selector: '#campaignDesc, #campaignDescEn, #campaignRisks, #campaignRisksEn, #eventDesc', min_height: 200 }));
initAutosave({
  key:     'campaign',
  formId:  'generalForm',
  tinyIds: ['campaignDesc', 'campaignDescEn', 'campaignRisks', 'campaignRisksEn', 'eventDesc']
});
```

- [ ] **Step 2: Commit**

```bash
git add admin/campaign.php
git commit -m "feat: wire autosave on campaign generalForm"
```

---

## Task 10: Wire up `admin/pages.php`

**Files:**
- Modify: `admin/pages.php`

`pages.php` renders different forms depending on the `?page=` URL param. Each form gets its own `initAutosave` call. Add the entire block at the very bottom of the file, before the `admin-footer.php` include (or after it if the file ends with the footer include — check that the `<script>` tags appear after `admin-footer.php` is rendered).

The page-load variable `$page` (line 10) drives which forms are rendered. `$edit_team_idx` (line 1159) is set when editing a team member. `$edit_idx` (line 1498) is set when editing a project. `$edit_way_idx` (line 1836) is set when editing a "way to help" item.

- [ ] **Step 1: Add the initAutosave block**

Find the last `</script>` block in `pages.php` (after all the TinyMCE inits for the legal forms, around line 2270+). Add the following immediately after the last `</script>` in the file:

```php
<script>
<?php if ($page === '' || $page === 'home'): ?>
initAutosave({ key: 'page:home',          formId: 'homeForm',     tinyIds: [] });
initAutosave({ key: 'page:campaign-text', formId: 'campaignForm', tinyIds: ['campTextBg', 'campTextEn'] });
<?php endif; ?>
<?php if ($page === 'about' && $edit_team_idx === null): ?>
initAutosave({ key: 'page:about', formId: 'aboutForm', tinyIds: ['aboutIntroBg', 'aboutIntroEn'] });
<?php endif; ?>
<?php if ($page === 'about' && $edit_team_idx !== null): ?>
initAutosave({ key: 'page:team:<?= (int)$edit_team_idx ?>', formId: 'teamEditForm', tinyIds: ['teamBioBg', 'teamBioEn'] });
<?php endif; ?>
<?php if ($page === 'projects' && $edit_idx !== null): ?>
initAutosave({ key: 'page:project:<?= (int)$edit_idx ?>', formId: 'projectEditForm', tinyIds: ['projTextBg', 'projTextEn'] });
<?php endif; ?>
<?php if ($page === 'how_to_help' && $edit_way_idx !== null): ?>
initAutosave({ key: 'page:way:<?= (int)$edit_way_idx ?>', formId: 'wayEditForm', tinyIds: ['wayTextBg', 'wayTextEn'] });
<?php endif; ?>
<?php if ($page === 'shop'): ?>
initAutosave({ key: 'page:shop', formId: 'shopForm', tinyIds: ['shopBg', 'shopEn'] });
<?php endif; ?>
<?php if ($page === 'legal_privacy'): ?>
initAutosave({ key: 'page:legal-privacy', formId: 'legalPrivacyForm', tinyIds: ['legalBg', 'legalEn'] });
<?php endif; ?>
<?php if ($page === 'legal_info'): ?>
initAutosave({ key: 'page:legal-info', formId: 'legalInfoForm', tinyIds: ['legalBg', 'legalEn'] });
<?php endif; ?>
<?php if ($page === 'legal_terms'): ?>
initAutosave({ key: 'page:legal-terms', formId: 'legalTermsForm', tinyIds: ['legalBg', 'legalEn'] });
<?php endif; ?>
</script>
```

- [ ] **Step 2: Commit**

```bash
git add admin/pages.php
git commit -m "feat: wire autosave on pages.php forms"
```

---

## Task 11: Wire up `admin/email-templates.php`

**Files:**
- Modify: `admin/email-templates.php`

Form ID is `tplForm` (line 126). TinyMCE IDs: `intro_bg`, `outro_bg`, `intro_en`, `outro_en`. Active template key is `$active_key` (line 76).

- [ ] **Step 1: Add `initAutosave` call**

Find the TinyMCE init block at the bottom of `email-templates.php` (around line 205):

```javascript
tinymce.init(Object.assign({}, window._tinyBase, {
  selector: '#intro_bg, #outro_bg, #intro_en, #outro_en',
```

Add the `initAutosave` call immediately after the `tinymce.init(...)` closing `));`:

```javascript
initAutosave({
  key:     <?= json_encode('email-template:' . $active_key) ?>,
  formId:  'tplForm',
  tinyIds: ['intro_bg', 'outro_bg', 'intro_en', 'outro_en']
});
```

- [ ] **Step 2: Run full test suite**

```bash
php vendor/bin/phpunit
```

Expected: all tests pass

- [ ] **Step 3: Commit**

```bash
git add admin/email-templates.php
git commit -m "feat: wire autosave on email-templates"
```

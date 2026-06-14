# Admin Autosave + Session Guard — Design Spec

**Date:** 2026-05-30  
**Status:** Approved

---

## Overview

Two related features for the admin panel:

1. **Autosave** — silently saves all form fields to `localStorage` as the user types, and offers to restore the draft on the next visit to the same editor.
2. **Session guard** — detects an already-expired session on page load and warns the user before expiry with the option to extend.

Both are motivated by non-technical users (social workers, parents) who may write long content and lose it to an expired session.

---

## Autosave Module

### File
`admin/js/autosave.js`

### Initialisation (per editor page)
```js
initAutosave({ key: 'article:42', formId: 'edit-form', tinyIds: ['body_bg', 'body_en'] })
```

- `key` — unique identifier for this draft in `localStorage` (see key table below)
- `formId` — the `id` attribute of the `<form>` element
- `tinyIds` — array of TinyMCE editor instance IDs on this page

### Saving
- Triggered by `input` and `change` events on all form fields, debounced 2 seconds
- TinyMCE fields are hooked via `editor.on('Change keyup', ...)`
- Excluded fields: `<input type="file">`, `<input type="hidden">` (includes CSRF token)
- Stored as JSON in `localStorage` under key `autosave:{key}`
- `localStorage` writes are wrapped in `try/catch`; if storage is full, save is silently skipped

### Restoring
- On page load (after DOM ready), check `localStorage` for the current key
- If a draft exists, show a banner at the top of the form:
  > *"You have an unsaved draft from [human-readable time]. Restore it?"*  
  > **[Restore]** **[Discard]**
- **Restore:** fills every input/select/textarea from saved values; for TinyMCE fields, waits for `tinymce.on('AddEditor', ...)` before setting content
- **Discard:** deletes the `localStorage` entry and removes the banner

### Clearing
- On form `submit` event, the draft key is deleted from `localStorage` so a successful save never re-offers a stale draft

### Draft Keys

| Editor page | localStorage key |
|---|---|
| `article-edit.php` | `autosave:article:{id}` / `autosave:article:new` |
| `product-edit.php` | `autosave:product:{id}` |
| `campaign.php` | `autosave:campaign:{id}` |
| `newsletter-compose.php` | `autosave:newsletter:{id}` |
| `pages.php` | `autosave:page:{slug}` |
| `email-templates.php` | `autosave:email-template:{id}` |

### Edge Cases
- **New article (no ID):** uses key `autosave:article:new`; cleared on submit, so stale drafts don't persist across separate new-article sessions
- **Multiple tabs open:** last write wins; acceptable for this audience
- **TinyMCE not ready on restore:** restoration deferred until `tinymce.on('AddEditor', ...)` fires for each expected editor ID

---

## Session Guard Module

### Files
- `admin/js/session-guard.js` — client-side guard, loaded globally
- `admin/session-ping.php` — lightweight server endpoint

### Session expiry variable
`admin-header.php` outputs one JS variable inside `<head>` (after the existing `admin_get_session()` call that populates `$sess`):
```php
$sess = admin_get_session();
// …existing auth check…
?>
<script>window._sessionExpiresAt = <?= ($sess['time'] ?? 0) + ADMIN_SESSION_HOURS * 3600 ?>;</script>
```
This gives the client the exact Unix timestamp when the current session will expire.

### On page load
`session-guard.js` immediately pings `admin/session-ping.php` via `fetch`.

- **Session already dead** (`alive: false`): show a persistent non-dismissible banner:
  > *"Your session has expired. Your draft has been saved — log in again to continue."*  
  > **[Log in]** (links to `admin/login.php`)
- **Session alive** (`alive: true`): set a JS countdown timer based on `window._sessionExpiresAt`

### Warning at 10 minutes
When `expiresAt - Date.now() < 10 * 60 * 1000`, show a modal overlay. Per project convention, the modal must use **fully inline styles** (no CSS classes) so it renders correctly regardless of cache state.

> *"Your session expires in **MM:SS**."*  
> **[Stay logged in]** **[Dismiss]**

The countdown ticks live inside the modal.

### Stay logged in
- Sends `POST admin/session-ping.php` with the CSRF token
- Server resets `$_SESSION[ADMIN_SESSION_NAME]['time'] = time()` and returns `{ alive: true, expiresAt: <new_timestamp> }`
- JS updates `window._sessionExpiresAt`, resets the countdown timer, dismisses the modal

### `admin/session-ping.php`
- Does NOT call `admin_require_login()` (which redirects to HTML login page). Instead performs a manual session check: if `admin_is_logged_in()` returns false, outputs HTTP 401 JSON `{ alive: false }` and exits.
- `GET` request: returns `{ alive: true, expiresAt: <timestamp> }` (used for the page-load ping)
- `POST` request with valid CSRF: refreshes session time, returns updated `{ alive: true, expiresAt: <new_timestamp> }`
- `POST` request with invalid CSRF: returns 403 `{ error: 'csrf' }`
- No HTML output, JSON only

---

## File Changes

| File | Change |
|---|---|
| `admin/js/autosave.js` | **New** — autosave module |
| `admin/js/session-guard.js` | **New** — session guard module |
| `admin/session-ping.php` | **New** — ping/keep-alive endpoint |
| `admin/includes/admin-header.php` | Add `window._sessionExpiresAt` JS variable |
| `admin/includes/admin-footer.php` | Load `autosave.js` and `session-guard.js` |
| `admin/article-edit.php` | Add `initAutosave(...)` call |
| `admin/product-edit.php` | Add `initAutosave(...)` call |
| `admin/campaign.php` | Add `initAutosave(...)` call |
| `admin/newsletter-compose.php` | Add `initAutosave(...)` call |
| `admin/pages.php` | Add `initAutosave(...)` call |
| `admin/email-templates.php` | Add `initAutosave(...)` call |

No database migrations required.

---

## What Is Not In Scope

- Server-side draft storage
- Multi-device draft sync
- Autosave for order/pledge edit forms (read-heavy, low write risk)
- Autosave status indicator ("Saved X seconds ago") — not needed given silent localStorage writes

# Projects Admin — Sortable List View

**Date:** 2026-05-17
**Status:** Approved

---

## Overview

Replace the current single-page stack of inline project edit cards with a two-view pattern: a compact sortable list (default) and a single-project edit form. Projects can be reordered via drag-and-drop (mouse) or ↑↓ buttons (keyboard/screen reader), satisfying WCAG 2.1 AA.

---

## Data Model

Each project object in `content/pages.json` under `pages.projects[]` gains an `order` field (integer, 0-based). On any save, projects are written back to the array sorted ascending by `order`.

Existing projects without an `order` field inherit their current array index on the next write — no migration script needed.

```json
{
  "title": "Лафетки",
  "title_en": "Lafetki",
  "text": "...",
  "text_en": "...",
  "images": [],
  "order": 0
}
```

The public `/proekti/index.php` renders `$pages['projects']` in array order. Since the array is always written sorted, no changes are needed to the public page.

---

## Admin — List View

**URL:** `/admin/pages.php?page=projects`

A compact `admin-table` with columns:

| Column | Content |
|---|---|
| (drag handle) | `⠿` grip icon, `cursor:grab`, `draggable="true"` on the `<tr>` |
| Thumbnail | First image at 48×48px, or a grey placeholder if none |
| Заглавие | `$project['title']` |
| Действия | ↑ button, ↓ button, Edit link → `?page=projects&edit=INDEX`, Delete button |

**Reorder — drag:**
- Native HTML5 drag events (`dragstart`, `dragover`, `drop`) on `<tr>` elements
- Visual feedback: dragged row gets `opacity:0.4`; target row gets a `2px solid var(--teal)` top border
- On `drop`: reorder the DOM rows, collect new order, POST to AJAX endpoint

**Reorder — keyboard (↑ ↓ buttons):**
- Each row has two small icon buttons: ↑ (move up) and ↓ (move down)
- `aria-label="Премести нагоре"` / `aria-label="Премести надолу"`
- First row: ↑ disabled. Last row: ↓ disabled.
- On click: swap rows in DOM, POST new order to AJAX endpoint, announce result via `aria-live="polite"` region: e.g. "Лафетки — преместен на позиция 1"

**AJAX save:**
- `POST /admin/projects-reorder-ajax.php`
- Body: `{ csrf_token, order: ["slug-or-title-0", "slug-or-title-1", ...] }` — ordered array of project titles used as identifiers (titles are unique in practice; no slug system exists for projects)
- Response: `{ ok: true }` or `{ ok: false, error: "..." }`
- On failure: show inline error, revert DOM to previous order

**Header:**
```
[← Назад]  Проекти          [+ Нов проект]
```

**Delete:** uses `_adminConfirm` modal (as per codebase pattern), POSTs `section=projects_delete&proj_index=N`.

---

## Admin — Edit View

**URL:** `/admin/pages.php?page=projects&edit=INDEX` where INDEX is the 0-based array index after sorting.

Layout mirrors the current inline card but full-page:

- `← Назад към проекти` link at top
- Save button top-right and bottom
- Fields: title BG, title EN (+ Translate button), rich text BG (TinyMCE), rich text EN (TinyMCE + Translate), images (existing thumbs with remove, file upload, media library picker)
- On save: POST `section=projects_save&proj_index=N`, redirect back to list with `?saved=1` flash

**New project:** `?page=projects&edit=new` — same form, `proj_index=new`, appended at end with `order = max_order + 1`.

---

## New AJAX endpoint

**File:** `admin/projects-reorder-ajax.php`

- `admin_require_admin()` + `csrf_verify()` at top
- Accepts POST JSON body: `{ csrf_token, order: [...titles] }`
- Loads `pages.json`, matches projects by title, reassigns `order` values, saves
- Returns JSON `{ ok: true }` or `{ ok: false, error }`

---

## Security

- `admin_require_admin()` on all new endpoints
- `csrf_verify()` on every POST (including AJAX — token passed in JSON body)
- Project index validated as `(int)` and bounds-checked against array length before use
- No user-supplied data interpolated into file paths

---

## Accessibility

- Drag handles have `aria-hidden="true"` (decorative); reordering via keyboard uses the ↑↓ buttons
- ↑↓ buttons have explicit `aria-label` text
- `aria-live="polite"` region announces position changes after keyboard reorder
- Delete confirmation uses `_adminConfirm` modal (keyboard accessible)
- All interactive elements reachable and operable by keyboard alone

---

## Testing

- PHPUnit: `tests/ProjectsReorderTest.php` — POST to reorder endpoint with valid/invalid CSRF, verify JSON order in saved file
- PHPUnit: projects_save creates new project, updates existing, rejects out-of-bounds index
- Manual: drag reorder, verify public page order matches; keyboard ↑↓ reorder, verify aria-live announcement

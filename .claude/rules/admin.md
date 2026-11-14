---
paths:
  - "admin/**"
  - "templates/tinymce-base.php"
---

# Admin pages

### Page-level variables
Set `$page_head_extra`, `$page_title_admin` and `$active_nav` **before** `require admin-header.php`. Setting them after is a silent no-op — the `<head>` is already rendered.

### Template variable scope
Before writing a form template, check which variable holds the data at that point. In `pages.php` it is `$all_pages`, not `$pages` — the wrong one gives a silently empty form that overwrites saved content with blanks on submit.

### Rich text — TinyMCE only
- Always TinyMCE (iframe-based). Never DOM-rendered editors (Jodit, Quill…) — `main.css`'s global `img { display:block; max-width:100% }` breaks their toolbar icons and can't be scoped away.
- Every `<textarea>` holding rich/HTML content gets TinyMCE — add its selector to a `tinymce.init()` call. Rows added by JS get a unique id and a `tinymce.init()` right after they are appended.
- **One shared config.** `window._tinyBase` lives in `templates/tinymce-base.php`, printed in the admin `<head>` by `admin/includes/admin-header.php` and in the public admin bar by `templates/header.php`. Toolbar/plugin/option changes go there only; never a page-specific `tinymce.init()` override.

### EN fields — always translatable
Every English field must be auto-translatable from its BG counterpart: add `data-translate-from="<bg field name or id>"` and the shared script in `admin/includes/admin-footer.php` adds the "✦ Translate" button (inputs, textareas, TinyMCE, dynamic rows; asks before overwriting). `tests/Admin/TranslateButtonCoverageTest.php` enforces it; only fields with no BG counterpart go in its `EXEMPT` list, with the reason.

### Destructive actions
Never the browser's `confirm()` — Chrome can suppress it silently. Use `_adminConfirm` from `admin/includes/admin-footer.php`. `tests/NoNativeConfirmTest.php` enforces it.

### Endpoints
`tests/Security/AdminEndpointGuardsTest.php` checks every routable admin script for a login guard, CSRF on POST, and the `.user.ini` + `pin-docroot.php` pair in subdirectories (see "Architecture" in CLAUDE.md for why). A file that is legitimately public goes in its exemption list with the reason.

---
paths:
  - "*.php"
  - "en/**"
  - "novini/**"
  - "templates/**"
  - "checkout/**"
  - "assets/**"
  - "content/**"
---

# Public pages

### Structure
Every public page includes `templates/header.php` and `templates/footer.php` — never its own `<!DOCTYPE>` / `<head>` / `<body>`. Set `$page_title`, `$page_description` and optionally `$page_head_extra` **before** requiring the header. The header handles `<head>`, fonts, GTM and `<body>`; the footer closes the page and renders the cookie banner.

### Everything visible is reachable from Edit mode
Every piece of text a visitor reads must be editable on the page in Edit mode or say where in the admin it is changed.
- Fixed wording (titles, labels, headings, form labels and buttons, "nothing here yet") → `te('key', 'BG', 'EN', $lang)` from `config.php`, with the key in both `content/{bg,en}/strings.json`. Not plain text, and not `t_or()` in a text position (`t_or()` is for attributes, `<title>` and `<option>`).
- Data owned by an admin page (organisation email/IBAN/address/socials, product and event details, visitor comments) → `cms_admin('organisation')` or `cms_admin([label, '/admin/…'])`.
- Rich text blocks get `data-cms-type="richtext"`; a card in a product/article list carries `data-cms-id`.
- `tests/EditModeCoverageTest.php` crawls every public page and fails on text that is neither — add new pages to its list.


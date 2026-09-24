# Configurable front-page sections — design

Date: 2026-09-21
Status: approved in chat, awaiting spec review

## Problem

The front page (`index.php`, `en/index.php`) is nine hard-coded sections duplicated across
two files. The admin can edit only the hero title/text, mission title/text/image and the
campaign. Section order, visibility, the hero image, every button link, section headings and
the bottom CTA text are fixed in code; the EN page has no inline editing at all. An installer
of ngo-cms cannot shape their own front page.

## Goal

A non-technical admin can, from the admin panel and without touching code:

- reorder, show/hide and fully edit every existing front-page section (BG + EN);
- add, duplicate and delete new sections from five generic block types.

Out of scope: free-form layouts, nested columns, per-block custom CSS, uploaded video files,
section management on the live page (inline text editing keeps working, but structure is
managed only in the admin).

## Data model

Stored in `content/home.json` (gitignored, like `content/pages.json`):

```json
{
  "version": 1,
  "rev": 17,
  "sections": [
    { "id": "s_hero", "type": "hero", "visible": true, "fields": { ... } },
    { "id": "s_8f3a2c", "type": "video", "visible": true, "fields": { ... } }
  ]
}
```

- `id`: `s_` + 6–12 lowercase hex/alnum. Built-ins use fixed ids (`s_hero`, `s_products`, …).
- `rev`: integer, incremented on every save; used for conflict detection.
- Translatable text fields are `{"bg": "...", "en": "..."}`. On render, an empty EN value falls
  back to BG.
- Link fields are also per-language `{"bg": "/kak-da-pomogna/", "en": "/en/how-to-help/"}`.
- Image fields are a path string plus a translatable `*_alt` field. Empty alt → `alt=""`.
- Every section has an optional `background`: `white | grey | warm`, mapped to the
  existing `section--*` classes. `teal` is offered only for the `cta` block, whose template
  turns its text and buttons white; elsewhere teal links/primary buttons would vanish, so a
  stored `teal` on any other block renders as that block's default.

### Built-in sections (exactly one each; hide/show and reorder; never deleted or duplicated)

| type | editable here | items still managed in |
|---|---|---|
| `hero` | title, text, image + alt, 2 buttons (label + link per lang) | — |
| `products` | heading, "view all" label + link | product editor "show on front page" |
| `impact` | heading | inline on the page |
| `campaign` | the campaign form (moved from `pages.php`); still gated by `feature_enabled('campaign')` and a campaign URL | — |
| `centres` | heading, intro | inline on the page |
| `mission` | title, text, image + alt | — |
| `news` | heading, count (3 or 6) | Articles |
| `partners` | heading | inline on the page |

If a built-in is missing from the file (hand-edited, older version), it is re-appended as
hidden on the next load so it can always be restored.

### Block types (any number; add, duplicate, delete)

| type | fields |
|---|---|
| `text_image` | heading, rich text, image + alt, image side (left/right), optional button (label + link) |
| `cta` | heading, short text, up to 2 buttons (label + link) |
| `richtext` | heading (optional), rich text (TinyMCE) |
| `cards` | heading (optional), 2–4 cards each: image + alt, title, short text, link |
| `video` | heading (optional), video URL → stored as `{provider, video_id}`, caption, local thumbnail path |

The current bottom CTA banner becomes a normal `cta` block in the seed.

### Validation (at save, in one place)

- `type` from the registry whitelist; `id` must exist (edits) and match the id pattern.
- Plain text: `trim()`, length caps per field; stored raw, escaped with `h()` on output.
- Rich text: sanitised with the same sanitiser used for articles (grep before implementing;
  do not introduce a second one).
- Links: must start with `/` (and not `//`) or be `https://…` / `http://…` passing
  `filter_var(FILTER_VALIDATE_URL)`. `javascript:`, `data:`, protocol-relative are rejected.
- Video: accepts `youtube.com/watch?v=`, `youtu.be/`, `youtube.com/shorts/`,
  `youtube.com/embed/`, `vimeo.com/<digits>`; stores provider + id only
  (YouTube id `[A-Za-z0-9_-]{11}`, Vimeo id digits). Anything else is a field error.
- Cards: 2–4. Image paths must be under `/assets/images/` or `/uploads/` (whatever the
  media library returns — confirm by grep).
- Errors are returned per field in plain Bulgarian.

### Seeding / migration

`home_load()` when `home.json` does not exist builds the default layout from current
`pages.json['home']` values plus today's fallbacks (`t('home.*')` strings and the hard-coded
CTA text), in today's order: hero, products, impact, campaign, centres, mission, news,
partners, cta. The result renders identically to the current page. It is written to disk on
the first admin save, not on a public page view. `pages.json['home']` is left intact
(rollback-safe) but no longer read by the front page.

### Persistence

- `home_save(array $doc, int $expected_rev)`: re-read file under `flock`, compare `rev`;
  mismatch → conflict error. Otherwise increment `rev`, write to temp file in the same dir,
  `rename()` over the target.
- `home_load()` on unreadable/invalid JSON: log server-side, return the seeded default and a
  `corrupt` flag the admin screen shows as a warning. The public page never errors.
- Unknown `type` in the file: skipped on render, preserved on save.

## Admin screen — `admin/home-sections.php`

`admin_require_login()` + `admin_require_admin()` (same as `pages.php`). `csrf_verify()` on
every POST. Every POST carries `rev`. Linked from `pages.php` (the "Начална страница" entry
now points here; the old home form is removed and the campaign form moves to the campaign
section's edit view).

### List view

- One row per section in order: "⠿ Премести" grip, type icon + name, short preview, status text
  "Видима / Скрита" (text, not colour alone), buttons Редактирай, Скрий/Покажи, and for blocks
  only Дублирай, Изтрий.
- Reorder by dragging the grip (mouse or finger — pointer events, `touch-action:none` on the
  grip), or from the keyboard: focus the grip, Space/Enter picks the row up (`aria-pressed`),
  ↑/↓ move it, Space/Enter drops, Esc puts it back. Instructions sit above the list and are
  the grip's `aria-describedby`; each move is announced („Място 3 от 7.“).
- A drop is saved straight away by `fetch` (`action=reorder`, `order[]` = every section id,
  `id` = the moved one, `rev`, `csrf_token`) and answered in JSON with the new `rev`, which is
  written into every other form on the page. `home_apply_reorder()` refuses any order that is
  not exactly the current sections. On a failure (conflict, bad order, session gone, offline)
  the list snaps back and a persistent `role="alert"` box says what to do.
- Toggle / duplicate / delete are each a small POST form (`action=toggle|duplicate|delete`,
  `id`, `rev`). Post-redirect-get back to the list; the status region (`role="status"`)
  announces the result and focus returns to the same control on that row.
- Delete uses `_adminConfirm`.
- "+ Добави секция" → type picker: five labelled buttons with one-line descriptions.
- "Виж началната страница" opens `/` in a new tab.
- Corrupt-file and conflict messages are persistent banners, not auto-dismissing.

### Edit view (`?edit=<id>` or `?add=<type>`)

- Form generated from the type registry: BG/EN side by side. Every EN field carries
  `data-translate-from="<bg field id>"` so the shared script in `admin-footer.php` adds the
  `✦ Translate` button (CLAUDE.md "EN fields — always translatable"; enforced by
  `tests/Admin/TranslateButtonCoverageTest.php`, including dynamically added card rows).
  Image fields with upload + cropper (`data-om-crop`) + "Избери от библиотека",
  rich text via TinyMCE using `window._tinyBase` only.
- Cards: add/remove card (2–4) and reorder with the same grip (drag or keyboard) within the
  form — the order is saved with the form; new card rows get unique ids and
  `tinymce.init` if they contain rich text.
- Video: on a valid pasted URL, show a preview thumbnail client-side from the stored/fetched
  one after save; invalid URL → persistent field error.
- `?add=<type>` does not write anything until Save. Cancel returns to the list.
- On validation failure the form re-renders with the submitted values, per-field errors
  (text + icon), and an error summary at the top linking to each field.
- On save the section's new position is: appended at the end for new blocks; unchanged for
  edits.

### Inline editing

The shared templates emit `data-cms-section="home:<id>"`. `admin/inline-save.php` gets a
branch for `home:` sections: resolves the section, whitelists the field against the type
registry, validates like the admin form, saves via `home_save()`. Existing auth/CSRF logic
unchanged. Inline editing therefore now also works on the EN page.

## Rendering

- `includes/home.php`: registry, `home_load()`, `home_save()`, `home_seed()`,
  `home_validate_section()`, `home_render(array $sections, string $lang)`, helper
  `hf(array $fields, string $key, string $lang): string` (EN→BG fallback).
- `templates/home/<type>.php`: one per type. Built-ins are today's markup moved over, with
  inline-edit attributes updated and ternaries replaced by `hf()`.
- `index.php` and `en/index.php` become thin wrappers: set `$lang`, page title/description,
  load, header, `home_render()`, footer.
- Data for built-ins (featured products, articles, partners, centres, impact, campaign) is
  loaded only if that section is visible.
- Layout-critical CSS (grid/flex, image side, card columns, mobile collapse) is inline per
  CLAUDE.md, plus one inline `<style>` for the 640px breakpoint emitted once.
- Only the hero renders an `<h1>`; section headings `<h2>`; card titles `<h3>`.

### Video block

- On save, the server fetches the thumbnail once (YouTube `i.ytimg.com/vi/<id>/hqdefault.jpg`;
  Vimeo oEmbed `thumbnail_url`), stores it locally under the uploads dir. Failure is not an
  error: the block renders a dark "▶ Пусни видеото" panel instead.
- Page output: a `<button>` with accessible name „Пусни видео: <heading or caption>" over
  the local thumbnail. No request to YouTube/Vimeo before click.
- On click, an inline script (emitted once) replaces the button with an iframe
  (`https://www.youtube-nocookie.com/embed/<id>?autoplay=1` or
  `https://player.vimeo.com/video/<id>?autoplay=1`) with `title`, `allow="fullscreen;
  autoplay"`, and moves focus to it. Nothing autoplays without the click.
- Verify both embed URL formats and the Vimeo oEmbed response with a real request before
  writing the code (CLAUDE.md external API rule).

## Accessibility

Follows CLAUDE.md "Accessibility — always prioritise it": keyboard-only operation, visible
focus, real buttons/labels, `role="status"` for list actions, errors never colour-only,
44px targets, AA contrast for every background option, alt text fields on every image,
reduced motion respected, no autoplay.

## Testing

- `tests/HomeSectionsTest.php`: seed from `pages.json` matches today's values and order;
  corrupt file fallback; unknown type skipped on render and preserved on save; missing
  built-in re-appended hidden; per-type validation (links reject `javascript:`, `data:`,
  `//host`; video URL forms accepted/rejected; cards 2–4); rich-text sanitising; `rev`
  conflict; atomic write.
- `tests/Admin/HomeSectionsAdminTest.php`: unauthenticated, `author` role, missing/bad CSRF,
  bogus id, bogus type all refused; move/toggle/duplicate/delete behave; built-ins cannot be
  deleted or duplicated; stale `rev` refused without writing.
- Render tests: correct language, EN→BG fallback, hidden sections absent, video output has
  no youtube/vimeo host before click, exactly one `<h1>`.
- `tests/InlineSaveTest.php`: `home:<id>` fields save; non-whitelisted field rejected.
- Playwright (`tests/Browser`): add a text+image block, move it up, hide it; check order and
  visibility on `/` and `/en/`.
- Full `php vendor/bin/phpunit` green; before/after screenshot of `/` and `/en/` on a site
  with no `home.json` shows no visual change.

## Files

New: `includes/home.php`, `templates/home/{hero,products,impact,campaign,centres,mission,news,partners,text_image,cta,richtext,cards,video}.php`,
`admin/home-sections.php`, tests above.

Changed: `index.php`, `en/index.php`, `admin/inline-save.php`, `admin/pages.php` (home entry
links to new screen; home + campaign forms removed from there), `.gitignore`
(`/content/home.json`).

No DB migration.

## Changes made while planning

1. No HTML sanitiser exists in the codebase (articles store TinyMCE output unfiltered;
   inline-save uses `strip_tags` allowlists, which keep `onclick`/`javascript:`). So this
   feature adds `home_clean_html()` (tag allowlist + attribute strip via `Dom\HTMLDocument`).
2. The mission section also gets its two buttons (label + link), which today are hard-coded —
   needed for "fully edit".
3. The campaign form stays in `admin/pages.php`, moved to its own view `?page=home_campaign`;
   the campaign section's Edit view links there. Moving its POST handler buys nothing.
4. Card fields are edited only in the admin form (no inline editing inside cards).

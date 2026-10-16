# Several photos beside text, a "Хора" block, and За нас built from blocks — design

Scoped 16.10.2026 with Lina. Started from: "I want to add 3–4 pics to the side of the
text on https://lafetki.com/za-nas/".

## Why

Admins can already build pages from blocks (Admin → Страници, since v0.27), but a block
holds only one photo beside its text. The About page (За нас) is still a fixed PHP page
with one intro text and a team list, so nothing on it can be laid out freely. Lina wants:

1. a column of 3–4 photos next to a text, one under the other (**not** a carousel);
2. the team list as a reusable block with photos, names and bios that any page can use.
   One planned use is a page about children the organisation has helped;
3. За нас rebuilt from those blocks, as the first step towards having no fixed content
   pages at all. That wider step is a separate task, not part of this design.

Success: an admin builds the current За нас (and its EN version, /en/about/) in the
block editor, adds four photos beside the intro and edits the team in a "Хора" block,
all without touching code. Another admin can drop the same blocks onto any created page.

## Goals

- **"Текст и снимка" takes 1–4 photos.** They stack vertically beside the text, on the
  side the admin picks (left/right, as today). On narrow screens they go below the text.
- **New block "Хора".** It has an optional heading, then a list of people, one row each:
  photo, name, a short role/subtitle line, and a bio with formatting. The photo sits on
  the left with the text on the right, like today's team list, and the photo goes above
  the text on phones. It can be used on the front page and on any created page.
- **За нас and /en/about/ are drawn from blocks.** The admin edits them in the same
  block editor as any created page. The current intro and team move over automatically,
  with nothing retyped.
- Everything works in BG and EN, and every EN text field gets the "✦ Translate" button
  (`data-translate-from`, enforced by `TranslateButtonCoverageTest`).
- Generic: built in ngo-cms, reaches lafetki through a tagged release and its update
  button, and is ported to oddminds by hand.

## Non-goals

- No carousel or slideshow. (News posts already have one: `templates/article-carousel.php`,
  spec `2026-10-03-post-photo-carousel-design.md`. Not touched here.)
- Converting the other fixed pages (kak-da-pomogna, kontakti, proekti, legal pages…).
  That is the logged follow-up "Replace fixed pages with block-built pages". This design
  should leave a pattern that follow-up can reuse, but it converts only За нас.
- Lightbox, zoom or captions under the photos.
- Changing how news posts work.

## Anti-goals (it fails even if it "works" when…)

- …the admin has to edit HTML, JSON or a file to add a photo or a person.
- …the existing За нас content disappears or must be retyped after the update.
- …a lafetki-only edit to a shared file is needed (that broke lafetki.com at v0.17.0).
- …the URLs /za-nas/ or /en/about/ change, or links in menus to them break.
- …photos of children get cropped in a way the admin didn't choose. In "Текст и снимка"
  photos are shown as uploaded. In "Хора" the admin picks what shows in the frame (see below).

## Decided with Lina

| Question | Decision |
|---|---|
| Carousel or stack? | Vertical stack beside the text, one after another |
| How many photos per text block | 3–4 was the ask. Cap at **4** |
| За нас: new block page or add a field to the old page? | Rebuild it from blocks (option 1) |
| The team list | A new reusable block with photo, name, bio |
| People on a children's page | Photos still shown (faces may be hidden in the photo itself); names may be first name only |
| Fixed pages in general | Should go away entirely. Logged as a separate task |
| Editing on the live page (Edit mode) | Page title, intro text and photos must stay clickable on the page, as today (`includes/about.php`, d81458c). People's names, roles and bios are edited only in Admin → Страници — an accepted change from today |
| "Хора" photo shape | Same 3:4 frame for everyone; the admin moves the image inside the frame to choose what shows |

## Calls I made (Lina to check)

- **Photos keep their own shape** in "Текст и снимка": full column width, natural height,
  no cropping. The admin decides what's in the frame.
- **The "Хора" photo** keeps today's team-list look: a fixed 3:4 portrait frame, about
  200px wide on desktop and about 160px on phones. **Lina decided:** 3:4 for every
  person is fine *as long as the admin can move the image inside the frame* to choose
  what shows. Each person's photo therefore gets a "Избери какво да се вижда" button that
  opens the existing shared cropper in pick mode (`OMCrop.pick(url, {aspectRatio: 3/4, data})`
  in `assets/js/image-cropper.js`). It returns a rectangle and doesn't re-upload or
  re-encode the file. The template draws that part of the image inside the frame
  (`object-position`/scale, or the builder's equivalent). With no choice saved, the
  image is centred and covers the frame (today's look). The chosen part must be validated
  server-side (numbers within the image, shape 3:4).
- **No photo → no placeholder.** If a person has no photo, their text takes the full row
  width. Today's grey 👤 box goes away.
- **Fields per person:** name (required, up to 100 chars), role/subtitle (optional, up to
  100), photo (optional), photo description for screen readers (optional, `alt`), bio
  (optional, rich text using the shared TinyMCE config, up to 5000 chars). All text fields
  are BG + EN pairs. The photo is shared by both languages.
- **Limits:** at least 1 person, at most 30 per block (enough for a team, keeps the admin
  form usable).
- **Photo descriptions:** every photo in both blocks gets its own optional description
  (BG + EN), used as `alt`. Empty means decorative (`alt=""`), as today. For "Хора",
  an empty description falls back to the person's name, matching today's page.
- **Order:** photos and people are reordered in the admin form with up/down buttons
  (keyboard-operable, no drag-only). Photos can be added and removed there too.
  Clicking a photo on the live page to replace it (the existing admin overlay)
  keeps working for each photo.
- **Old data:** an existing "Текст и снимка" block with one `image` keeps working with
  no migration. It reads as a one-photo list and is written in the new shape on the next save.

## Constraints

- Follow CLAUDE.md: inline styles for layout-critical rules in new public sections, labels
  on every input, live-region errors, focus visible, `h()` on output, CSRF + admin checks on
  every save path, images only under the page's upload dir (`home_upload_dir()`), and
  `home_valid_image_path()` on every stored path.
- Production is MariaDB 10.5. This design needs no DB changes: blocks live in JSON
  (`content/home.json`, `content/pages/<id>.json`).
- `content/pages/` is site data, never shipped in a release and never touched by the updater.
- Fork rule: nothing here may require lafetki to change a shared file.

## How it fits the existing code (pointers for the builder)

- Block types live in `home_types()` in `includes/home.php`. Each type has a template in
  `templates/home/<type>.php`, and its admin form comes from `hs_field()` in
  `includes/home_admin.php`.
- "Карти" already has a repeating list of sub-items (`kind => 'cards'`, `HOME_CARD_FIELDS`,
  `hs_cards()`/`hs_card_row()`, uploads via `up_card[i]` in `home_apply_uploads()`, fixed
  2–4 count). The photo list and the people list are the same idea with different item
  fields and limits. **Builder's call:** generalise that into one repeating-list field kind
  (item fields, min, max, labels) used by cards, photos and people, or add two sibling
  kinds. Generalising is preferred if it doesn't destabilise cards.
- "Текст и снимка": today `image` + `image_alt`. The new shape is a list of
  `{image, image_alt}` (field name is the builder's call), with `image`/`image_alt` read as
  a one-item list when the list is absent. `home_inline_save()` (live-page editing) handles
  only single image fields today. **Required:** each photo of the list can be replaced
  on the live page, so inline save must address one list item by position (and refuse
  stale positions safely, e.g. via the document's `rev`). Inline editing of "Хора" texts
  is *not* required.
- **Page title on the live page:** the За нас title is clickable in Edit mode today and must
  stay so. Whatever renders the block page's header must expose the title (BG/EN) to inline
  editing.
- `home_section_preview()` should describe the new blocks in the section list
  (e.g. "4 снимки", "6 души").
- Site-specific block types (`home_site_types()`) must keep working unchanged.

## За нас from blocks

Today `za-nas/index.php` and `en/about/index.php` read `content/pages.json` → `about`
(`title`, `intro` HTML, `team[]` with `name`, `role`, `bio`, `photo`, and EN equivalents
in the EN file/keys; the builder checks exactly where). A real folder always wins over a
created page in `.htaccess`, and `about` is a reserved created-page slug.

Wanted behaviour:

- The two About URLs show a block document instead of the fixed layout. The page header
  (label "За нас" + title) stays as it is now, unless the block document already provides a
  heading. **Builder's call** on the mechanism. Options: the fixed page renders a
  created page linked to it, or a "built-in page with blocks" kind of document. Pick the
  one the follow-up (all fixed pages) can reuse.
- The first time the new code runs on a site with no block document for About, it seeds one
  from `pages.json` → `about`: intro → "Свободен текст" or "Текст и снимка" (with no photos,
  so the site looks the same until the admin adds photos), team → "Хора" (heading
  "Защо го правим?" / its EN text, label "Екипът" kept if the block supports a label, or
  dropped as the builder's call). Seeding must be idempotent and must not overwrite a document an admin
  already edited.
- The admin finds За нас in Admin → Страници and edits it there. Its address can't be
  changed or deleted (it's a built-in page), and its old admin editing screen, if any,
  points to the new one.
- Until the admin changes anything, the page looks the same as before (apart from the
  missing 👤 placeholder, if any team member lacks a photo).

## Testing expectations

PHPUnit tests (in `tests/`) for: cleaning/validating both new list fields (limits, bad image
paths, missing names, BG/EN pairs), old one-image "Текст и снимка" data still rendering,
upload handling for list items, the За нас seed (idempotent, maps every team member,
leaves an edited document alone), template rendering (photo count, no placeholder, alt
fallback), and `TranslateButtonCoverageTest` passing for the new EN fields. Then a browser
check of За нас BG/EN on desktop and phone width, keyboard-only reordering in the admin, and
a screen-reader-sensible structure (people as a list, each name a heading).

## Rollout

ngo-cms commit(s) → tag a release → lafetki updates from its admin → check
lafetki.com/za-nas/ and /en/about/, then add the photos there. Port to oddminds by hand
with a `Ported: ngo-cms <sha>` trailer, checking that oddminds' About page and data shape
match (it has a different history).

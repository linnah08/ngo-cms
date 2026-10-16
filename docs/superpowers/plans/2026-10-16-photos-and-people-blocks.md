# Photos beside text, "Хора" block, За нас from blocks — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** "Текст и снимка" holds up to 4 stacked photos, a new reusable "Хора" block lists people (photo framed 3:4 with an admin-chosen part, name, role, bio), and /za-nas/ + /en/about/ are drawn from a block page seeded from today's content.

**Architecture:** Generalise the hard-coded `cards` sub-list into one repeating field kind `list` (item fields, min/max, stable item ids), and build the photo list and the people list on it. За нас becomes a created page with the fixed id `p_000about`. Its fixed folders (`za-nas/`, `en/about/`) render it through the same renderer `page.php` uses, after seeding it once from `pages.json` → `about`.

**Tech Stack:** PHP 8.4, no framework, JSON files under `content/`, vanilla JS, Cropper.js via `assets/js/image-cropper.js`, PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-10-16-photos-and-people-blocks-design.md`

## Global Constraints

- Work only in this worktree (`../oddminds-oss-wt/photos-people-blocks`). Commit locally after each task. Never push. Stage files by name.
- `php vendor/bin/phpunit` must pass at the end of every task.
- Every new public markup uses inline styles for layout-critical rules (grid/flex/size). Do not rely on new `main.css` classes.
- Every EN text input gets `data-translate-from` (comes free through `hs_field()`). `tests/Admin/TranslateButtonCoverageTest.php` must stay green.
- Admin copy is plain Bulgarian. Errors are shown persistently with `hs_error_html()` and the error summary, never with flash-only messages.
- Every stored image path passes `home_valid_image_path()`. Uploads go only to `home_upload_dir()`.
- All output is escaped with `h()`, except rich text already cleaned by `home_clean_html()`.
- Photos per "Текст и снимка": 0–4. People per "Хора": 1–30. Name ≤ 100, role ≤ 100, bio ≤ 5000 (html), photo description ≤ 200.
- "Хора" frame: 3:4, 200px wide on desktop, 160px wide on ≤640px; photo above text on ≤640px. "Текст и снимка" photos: natural shape, full column width.
- No placeholder when a person has no photo: their text takes the full row.
- People's name/role/bio are edited in the admin form only. Page title, intro text and every photo stay editable on the live page.
- No DB changes. No new shared-file edits needed in lafetki.

## Review Focus

1. **An old "Текст и снимка" with a single `image`** must render exactly as before and keep its photo after an admin re-saves it without touching it. Tested in Task 2.
2. **Reorder or remove a photo in the admin form while the live page is open in another tab, then replace a photo on the live page.** The replaced photo must be the one that was clicked, never a neighbour. Item ids, not positions, are tested in Task 3.
3. **A team member in `pages.json` with a missing/invalid photo path, no name, or only EN text** must still seed without the seed failing or dropping the others. Tested in Task 5.
4. **A crop rectangle posted by hand** (negative numbers, outside the image, wrong shape, non-numeric) must be dropped, and the photo then falls back to centred cover instead of raising an error. Tested in Task 4.
5. **Two visitors hitting /za-nas/ at the same moment on first load after the update** must give one page file, never two, and never overwrite an admin's edits. Tested in Task 5.

---

### Task 1: Generic repeating list field (`kind => 'list'`), cards moved onto it

**Files:**
- Modify: `includes/home.php` (`HOME_CARD_FIELDS` use, `home_types()` cards entry, `home_clean_field()` `case 'cards'`, `home_apply_uploads()`, `home_section_preview()`)
- Modify: `includes/home_admin.php` (`hs_field()` `case 'cards'`, `hs_card_row()`, `hs_cards()`, `hs_error_label()`)
- Modify: `admin/home-sections.php:533-575` (cards JS → any `[data-hs-list]`)
- Test: `tests/Home/HomeListFieldTest.php` (new). Existing `tests/Home/*` card tests must pass unchanged.

**Interfaces:**
- Produces: field definition shape
  `['kind' => 'list', 'label' => string, 'item' => string /* "Карта" */, 'add' => string /* "+ Добави карта" */, 'remove' => string, 'min' => int, 'max' => int, 'fields' => array<string, def>]`
- Produces: every stored item carries `'id' => string` matching `/^i_[a-z0-9]{6}$/`. Missing or invalid ids get a new one when the item is cleaned. Existing ids are kept.
- Produces: `home_list_item_id(): string` (new id), `home_list_find(array $items, string $item_id): ?int`.
- Produces: upload input name `up_list[<field key>][<row index>][<item field key>]`. `home_apply_uploads()` stores each upload as `home_store_upload($file, $sid, "{$key}{$row}{$ik}")`. Errors are keyed `"{$key}.{$pos}.{$ik}"`.
- Produces: admin ids/names follow the existing scheme: `f[<key>][<row>][<ik>]`, id `f_<key>_<row>_<ik>`, error `"<key>.<row>.<ik>"`. The hidden input `f[<key>][<row>][id]` keeps the item id.
- The cards type becomes `'cards' => ['kind' => 'list', 'label' => 'Карти', 'item' => 'Карта', 'add' => '+ Добави карта', 'remove' => 'Премахни картата', 'min' => 2, 'max' => 4, 'fields' => HOME_CARD_FIELDS]`. The count error stays exactly `'Добавете между 2 и 4 карти.'` (generic form: `"Добавете между {$min} и {$max} {$plural}."`, so add `'plural' => 'карти'` to the def).

- [ ] **Step 1: Write the failing tests** in `tests/Home/HomeListFieldTest.php`:
  - `test_items_get_stable_ids`: cleaning `[['title'=>['bg'=>'A','en'=>'']], ['id'=>'i_abc123','title'=>['bg'=>'B','en'=>'']]]` with the cards def gives item 0 an id matching `/^i_[a-z0-9]{6}$/` and leaves item 1 with id `i_abc123`.
  - `test_bad_ids_are_replaced`: item id `'../x'` is replaced with a valid new id.
  - `test_min_max_errors_use_the_def`: a def with min 1, max 2 and plural `'души'`, given 3 items, gives error `['k' => 'Добавете между 1 и 2 души.']`.
  - `test_item_field_errors_are_keyed_by_position`: a card with an empty required title at position 1 gives error key `cards.1.title.bg`.
  - `test_upload_goes_into_the_right_row`: with `$_FILES`-shaped `['up_list' => ['tmp_name' => ['cards' => [1 => ['image' => $tmpJpeg]]], 'error' => ['cards' => [1 => ['image' => UPLOAD_ERR_OK]]], 'name' => …]]`, `home_apply_uploads()` sets `$in['cards'][1]['image']` to a path under `home_upload_dir()`. Use the existing upload test helpers. `move_uploaded_file` needs the pattern used in `tests/InlineUploadTest.php`.
  - `test_admin_form_has_one_fieldset_per_item_with_hidden_id`: `hs_field('cards', $def, [['id'=>'i_abc123']], [])` contains `name="f[cards][0][id]" value="i_abc123"` and `<template id="hsListTpl_cards">`.

- [ ] **Step 2: Run them to see them fail.**
  Run: `php vendor/bin/phpunit tests/Home/HomeListFieldTest.php`
  Expected: failures. `home_list_item_id` is undefined, and the ids and the template are missing.

- [ ] **Step 3: Implement.**
  - In `home_clean_field()`, replace `case 'cards'` with `case 'list'` driven by `$def['fields']`, `min`, `max` and `plural`, assigning or keeping `id`.
  - Rename `hs_cards()`/`hs_card_row()` to `hs_list(string $key, array $def, array $items, array $errors): string` and `hs_list_row(string $key, array $def, string $i, array $item, array $errors, int $number): string`. Keep every existing attribute and the label wording via `$def['item']`, `$def['add']` and `$def['remove']`. Use data attributes `data-hs-list="<key>"`, `data-hs-item`, `data-hs-item-legend`, `data-hs-item-remove`, `data-hs-item-add`, `data-hs-min`, `data-hs-max`, and template id `hsListTpl_<key>`.
  - Generalise `hs_error_label()` to look up `home_types()[$type]['fields'][$parts[0]]['fields'][$parts[2]]['label']` and label the row `"{$def['item']} N"`.
  - Rewrite the JS at `admin/home-sections.php:533-575` to loop over every `[data-hs-list]`. It enforces min/max with the add/remove buttons (disabled when the limit is reached, with the reason shown as text next to the button, not by colour) and renumbers legends. It reuses the existing sort-handle wiring per list. A new row gets a fresh `__I__` index (`next++`) and a fresh `i_` id (`'i_' + Math.random().toString(36).slice(2, 8).padEnd(6, '0')`), and runs `tinymce.init(Object.assign({}, window._tinyBase, {selector: '#' + id}))` for each new `.hs-rich` textarea in the row.
  - `home_section_preview()` for cards keeps showing `"N карти"`. Generalise it to `count . ' ' . $def['plural']` for the first list field of a section.

- [ ] **Step 4: Run all Home tests.**
  Run: `php vendor/bin/phpunit tests/Home tests/Admin`
  Expected: PASS, including the existing card tests.

- [ ] **Step 5: Browser check of cards** (built-in server via `.claude/launch.json` if present). Add, remove and reorder cards with mouse and keyboard on a created page, then save. No console errors.

- [ ] **Step 6: Commit**
  `git add includes/home.php includes/home_admin.php admin/home-sections.php tests/Home/HomeListFieldTest.php && git commit -m "refactor(blocks): one repeating-list field kind; cards use it"`

---

### Task 2: "Текст и снимка" with up to 4 stacked photos

**Files:**
- Modify: `includes/home.php` (`text_image` fields, new helper)
- Modify: `templates/home/text_image.php`
- Test: `tests/Home/HomeTextImagePhotosTest.php` (new)

**Interfaces:**
- Consumes: `kind => 'list'` from Task 1.
- Produces: `text_image` fields become `heading`, `body`, `photos`, `image_side`, buttons, `background`. The old `image`/`image_alt` defs are removed from the type. `photos` = `['kind'=>'list','label'=>'Снимки','item'=>'Снимка','add'=>'+ Добави снимка','remove'=>'Премахни снимката','plural'=>'снимки','min'=>0,'max'=>4,'fields'=>['image'=>image def (required),'image_alt'=>alt def]]`. Add `'required' => true` handling for `kind image` in `home_clean_field()` (error `'Изберете снимка или премахнете реда.'`).
- Produces: `home_ti_photos(array $f): array` returns a list of `['id'=>string,'image'=>string,'image_alt'=>array{bg,en}]` with valid images only. When `photos` is absent and `image` is valid, it returns one item with id `'i_legacy'`.
- Old data: in `home_validate_section('text_image', $in)`, when `$in['photos']` is absent and `$in['image']` is a valid path, build `photos` from `image`/`image_alt` first, so a re-save keeps the photo.

- [ ] **Step 1: Write the failing tests:**
  - `test_legacy_single_image_reads_as_one_photo`: `home_ti_photos(['image'=>'/assets/images/a.jpg','image_alt'=>['bg'=>'x','en'=>'y']])` gives one item with that image and alt.
  - `test_legacy_section_keeps_photo_on_resave`: `home_validate_section('text_image', ['image'=>'/assets/images/a.jpg', 'body'=>['bg'=>'<p>т</p>','en'=>'']])` gives `fields['photos'][0]['image'] === '/assets/images/a.jpg'` and no errors.
  - `test_more_than_four_photos_is_an_error`: 5 photos give error key `photos` with `'Добавете между 0 и 4 снимки.'`. Change the copy for min 0 to `'Може да добавите до 4 снимки.'`: when `min === 0`, use `"Може да добавите до {$max} {$plural}."`.
  - `test_renders_photos_stacked_in_order`: render a section with 3 photos via `home_render_section()`. The output has 3 `<img` tags in stored order inside one wrapper with `display:flex;flex-direction:column`, each with its own `alt`. The grid stays `1fr 1fr`.
  - `test_no_photos_renders_text_full_width`: no photos give `grid-template-columns:1fr` and no `<img`.
  - `test_each_photo_is_replaceable_on_the_page`: with `$GLOBALS['_show_admin_bar'] = true`, each photo wrapper has `data-cms-field="photos.<item id>.image"`.

- [ ] **Step 2: Run to see them fail.** Run: `php vendor/bin/phpunit tests/Home/HomeTextImagePhotosTest.php`. Expected: FAIL.

- [ ] **Step 3: Implement** the defs, `home_ti_photos()` and the legacy mapping. In the template, the photo column becomes a `<div style="order:…;display:flex;flex-direction:column;gap:1.25rem;">`, one `.om-img-wrap` per photo using `home_img_attrs($sid, 'photos.' . $p['id'] . '.image')`, `width:100%;height:auto`, `border-radius:8px`, `loading="lazy"`. Add one `<style>` rule via the existing `home_shared_head()` mechanism only if it's needed for the ≤640px stacking: the current `.home-ti` mobile rule must still drop the photos below the text. Check where `.home-ti` is handled and reuse it.

- [ ] **Step 4: Run.** Run: `php vendor/bin/phpunit tests/Home`. Expected: PASS.

- [ ] **Step 5: Commit**
  `git add includes/home.php templates/home/text_image.php tests/Home/HomeTextImagePhotosTest.php && git commit -m "feat(blocks): Текст и снимка takes up to 4 photos, stacked beside the text"`

---

### Task 3: Replace one photo of a list on the live page

**Files:**
- Modify: `includes/home.php` (`home_inline_save()`)
- Test: `tests/Home/HomeInlineSaveTest.php` (add cases)

**Interfaces:**
- Consumes: item ids from Task 1. The field key `"<list key>.<item id>.<item field>"` is emitted by Task 2 (and Task 4).
- Produces: `home_inline_save($sid, ['photos.i_abc123.image' => ['bg' => $path, 'en' => $path]])` sets that item's image. Only item fields of kind `image` (and `alt`/`text`/`textarea`/`html`, to match top-level behaviour) are accepted. An unknown list key, item id or item field is ignored like any unknown field. If nothing is accepted, the existing `'Тези полета не могат да се променят оттук…'` error is returned.
- Inline-cms.js needs no change: it already posts `data-cms-field` verbatim.

- [ ] **Step 1: Write failing tests** in `HomeInlineSaveTest`:
  - `test_replaces_one_list_photo_by_item_id`: a `text_image` with photos `[i_aaaaaa:a.jpg, i_bbbbbb:b.jpg]`. Saving `photos.i_bbbbbb.image` = `/assets/images/c.jpg` changes only the second photo.
  - `test_list_photo_follows_its_id_after_reorder`: the stored order is swapped before the save, and the save still changes the `i_bbbbbb` item (Review Focus 2).
  - `test_unknown_item_id_is_refused`: `photos.i_zzzzzz.image` returns `ok === false`, and the file is unchanged (rev unchanged).
  - `test_invalid_path_for_list_photo_is_refused`: `'/etc/passwd'` gives `ok === false`.

- [ ] **Step 2: Run to see them fail.** Run: `php vendor/bin/phpunit tests/Home/HomeInlineSaveTest.php`. Expected: the new cases FAIL.

- [ ] **Step 3: Implement.** In the field loop, split the key on `.`. With 3 parts where `$defs[$p0]['kind'] === 'list'`, find the item with `home_list_find()`, take the item field def from `$defs[$p0]['fields'][$p2]`, clean it with `home_clean_field()`, and write it into that item.

- [ ] **Step 4: Run.** Run: `php vendor/bin/phpunit tests/Home tests/InlineSaveTest.php tests/InlineUploadTest.php`. Expected: PASS.

- [ ] **Step 5: Commit**
  `git add includes/home.php tests/Home/HomeInlineSaveTest.php && git commit -m "feat(blocks): replace one photo of a block's photo list on the live page"`

---

### Task 4: The "Хора" block

**Files:**
- Modify: `includes/home.php` (type `people`, field kind `focus`)
- Modify: `includes/home_admin.php` (`hs_field()` `case 'focus'`)
- Modify: `admin/home-sections.php` (focus button JS: one delegated listener)
- Create: `templates/home/people.php`
- Test: `tests/Home/HomePeopleTest.php` (new)

**Interfaces:**
- Consumes: `kind => 'list'` (Task 1) and the inline list photo key (Task 3).
- Produces: type
  `'people' => ['builtin'=>false,'icon'=>'👥','label'=>'Хора','desc'=>'Списък с хора — снимка, име, роля и кратък текст за всеки. За екип, доброволци или истории.','note'=>null,'fields'=>['heading'=>$head,'people'=>['kind'=>'list','label'=>'Хора','item'=>'Човек','add'=>'+ Добави човек','remove'=>'Премахни човека','plural'=>'души','min'=>1,'max'=>30,'fields'=>HOME_PERSON_FIELDS],'background'=>$bg('white')]]`
- Produces: `const HOME_PERSON_FIELDS = ['image'=>['kind'=>'image','label'=>'Снимка'], 'focus'=>['kind'=>'focus','label'=>'Коя част от снимката да се вижда','for'=>'image','ratio'=>[3,4]], 'image_alt'=>['kind'=>'alt','label'=>'Описание на снимката','max'=>200,'hint'=>'…'], 'name'=>['kind'=>'text','label'=>'Име','max'=>100,'required'=>true,'hint'=>'Може и само малко име.'], 'role'=>['kind'=>'text','label'=>'Роля или кратък надпис','max'=>100], 'bio'=>['kind'=>'html','label'=>'Текст','max'=>5000]]`
- Produces: the `focus` value is `null` or `['x'=>int,'y'=>int,'w'=>int,'h'=>int,'iw'=>int,'ih'=>int]`. Cleaning (`home_clean_field` `case 'focus'`) accepts an array or a JSON string. It returns `null` without an error (Review Focus 4) unless all six values are ints ≥ 0, `w,h,iw,ih > 0`, `x+w ≤ iw`, `y+h ≤ ih`, and `abs(w/h − 3/4) ≤ 0.02`. Comparing with the real file is not required.
- Produces: `home_focus_style(?array $focus): string`, the inline style for the `<img>` inside a `position:relative;overflow:hidden;aspect-ratio:3/4` frame. With `null` it returns `position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:50% 50%;`. Otherwise it returns `position:absolute;max-width:none;width:{iw/w*100}%;height:{ih/h*100}%;left:-{x/w*100}%;top:-{y/h*100}%;` with 4-decimal values.
- Produces: admin control for `focus`: a hidden input `f[people][<row>][focus]` (JSON), a 3:4 preview of the current choice, and a button `Избери какво да се вижда`. The button calls `OMCrop.pick(url, {aspectRatio: 3/4, data: {x,y,width:w,height:h}})` with the row's current image path and writes `{x,y,w:width,h:height,iw:naturalWidth,ih:naturalHeight}` back. When the image changes in that row, the JS clears the focus (it belonged to the old photo). When there's no image, the button is disabled and the text next to it says `Първо изберете снимка.`

- [ ] **Step 1: Write failing tests:**
  - `test_people_type_is_offered_on_created_pages`: after `home_target_page('p_12345678')`, `isset(home_doc_types()['people'])`.
  - `test_name_is_required`: a person without a name gives error `people.0.name.bg`.
  - `test_thirty_one_people_is_an_error`: gives error `people` = `'Добавете между 1 и 30 души.'`.
  - `test_focus_valid_rect_is_kept` / `test_focus_rejects_bad_rects`: use a data provider covering a negative x, x+w > iw, a 1:1 shape, strings, a missing key, and a JSON string that decodes to a valid rect (kept).
  - `test_focus_style_maps_rect_to_percentages`: `home_focus_style(['x'=>100,'y'=>50,'w'=>300,'h'=>400,'iw'=>1200,'ih'=>800])` contains `width:400.0000%`, `height:200.0000%`, `left:-33.3333%` and `top:-12.5000%`.
  - `test_render_people_rows`: 2 people, one without a photo. The output has `<ul` with `role="list"`, 2 `<li`, each name in an `<h3>`, one `<img` whose alt falls back to the name when `image_alt` is empty, and the photo-less row has no frame element and no `👤`.
  - `test_render_each_photo_replaceable`: the photo wrapper has `data-cms-field="people.<id>.image"` when the admin bar is on.

- [ ] **Step 2: Run to see them fail.** Run: `php vendor/bin/phpunit tests/Home/HomePeopleTest.php`. Expected: FAIL.

- [ ] **Step 3: Implement** the type, `HOME_PERSON_FIELDS`, `case 'focus'`, `home_focus_style()`, `hs_field()` `case 'focus'` and the JS. Template `templates/home/people.php`: section with `home_bg_class($f)`, optional heading (`home_cms_attrs`, like cards), then `<ul role="list" style="list-style:none;margin:3rem 0 0;padding:0;display:flex;flex-direction:column;gap:4rem;">`. Each `<li style="display:grid;grid-template-columns:200px 1fr;gap:3rem;align-items:start;">`, or `1fr` when there's no photo. Name `<h3 style="color:var(--teal);">`, role `<p>` styled like today's za-nas, then the bio. Add the ≤640px rule as a `<style>` the template prints once per request (guarded by a static flag), because templates run after the header and can't use `$page_head_extra`. The rule:: `grid-template-columns:1fr`, frame `max-width:160px`. Copy the visual values from `za-nas/index.php` lines 11 and 37-53.

- [ ] **Step 4: Run.** Run: `php vendor/bin/phpunit tests/Home tests/Admin`. Expected: PASS, including `TranslateButtonCoverageTest`.

- [ ] **Step 5: Browser check:** on a created page, add a Хора block with 3 people. Upload photos, pick the visible part with the keyboard only, save, and view the page at desktop and 375px widths. Then replace one photo on the live page. No console errors.

- [ ] **Step 6: Commit**
  `git add includes/home.php includes/home_admin.php admin/home-sections.php templates/home/people.php tests/Home/HomePeopleTest.php && git commit -m "feat(blocks): Хора block — people with framed photo, name, role and text"`

---

### Task 5: За нас becomes a block page (BG + EN)

**Files:**
- Modify: `includes/created_pages.php` (built-in page id, protections)
- Modify: `includes/about.php` (rewrite: seed + render helpers; drop `about_cms_attrs`/`about_inline_apply`)
- Create: `templates/block-page.php` (the shared hero + draft bar + sections body, extracted from `page.php`)
- Modify: `page.php`, `za-nas/index.php`, `en/about/index.php`
- Modify: `admin/inline-save.php` (drop the `about` branch; add `pagetitle:<id>`), `admin/inline-add.php` and `admin/inline-remove.php` (drop the about-team branches)
- Modify: `admin/pages.php` (the За нас row and editor → link to `page-edit.php?id=p_000about`), `admin/created-pages.php` (no delete/unpublish/address change for the built-in page)
- Test: `tests/AboutPageTest.php` (rewrite), `tests/CreatedPages/CreatedPagesBuiltinTest.php` (new), `tests/InlineAddRemoveTest.php` and `tests/InlineSaveTest.php` (drop the about cases)

**Interfaces:**
- Consumes: the `text_image` (`photos`) and `people` types from Tasks 2 and 4.
- Produces: `const CPAGE_ABOUT_ID = 'p_000about';`, `cpage_is_builtin(string $id): bool` (true for `CPAGE_ABOUT_ID`).
  - `cpage_delete()` returns false for it.
  - `cpage_set_status()` refuses `draft` with the message `'Тази страница е част от сайта и винаги се вижда.'`.
  - `cpage_save_settings()` ignores posted slugs for it and keeps `za-nas`/`about`.
  - The settings form shows the addresses read-only, with the text `Адресът на тази страница не може да се променя.`
- Produces: `about_page(): array`, the page (seeding it when missing), and `about_seed_page(array $about): array`, which is pure and returns the page array for `pages.json` → `about`:
  - `title_bg` = `about.title` or `'Кои сме ние?'`, and `title_en` = `about.title_en` or `'Who are we?'`. The slugs are `za-nas`/`about`, the status is `published`, and the id is `CPAGE_ABOUT_ID`.
  - Sections: (1) `text_image` with `body` = `{bg: home_clean_html(intro), en: home_clean_html(intro_en)}`, `photos` = `[]`, `background` `white`, visible; (2) only when `about_team()` is non-empty, `people` with `heading` `{bg:'Защо го правим?', en:'Why we do it'}`, `background` `grey`, and one person per member: `name` `{bg: name, en: name_en}`, `role` `{bg: role, en: role_en}`, `bio` `{bg: bio, en: bio_en}` (html-cleaned), `image` = `photo` if `home_valid_image_path()` else `''`, `focus` null. A member with an empty BG name takes its EN name. If both are empty it becomes `'—'`, so validation never drops the team.
  - Section and item ids come from `home_new_id()` / `home_list_item_id()`.
- Produces: seeding writes with `fopen($path, 'x')`. If the file already exists (another request won, or an admin edited it), it just reads it. It never overwrites.
- Produces: `templates/block-page.php` expects `$_bp_page` (page array), `$lang`, `$_bp_label` (`'За нас'`/`'About us'` for About, `''` for others) and `$_bp_draft` (bool). It renders the draft bar (moved from `page.php`), then the hero with the optional `<span class="section-label">` and an `<h1>` carrying `data-cms-section="pagetitle:<id>" data-cms-field="title" data-cms-type="text"` (+ `data-cms-bg`/`data-cms-en` when `_show_admin_bar`), then `home_target_page()` + `home_render()` + `home_target_reset()`.
- Produces: in `admin/inline-save.php`, section `pagetitle:<id>` with field `title` calls `cpage_update($id, …)`, setting `title_bg`/`title_en` from `home_plain_text()`. An empty BG title is refused with `'Заглавието не може да е празно.'`. This works for every created page.
- `za-nas/index.php` / `en/about/index.php`: `require includes/about.php`, `$_bp_page = about_page()`, set `$page_title` (= `cpage_title`) and `$page_description` (keep today's strings), then the header, `templates/block-page.php` with `$_bp_draft = false`, and the footer. Their SEO alternates and canonical stay as they are today.

- [ ] **Step 1: Write failing tests:**
  - `CreatedPagesBuiltinTest`: `test_about_cannot_be_deleted`, `test_about_cannot_be_unpublished`, `test_about_slugs_cannot_change`, `test_other_pages_unaffected` (a normal page can still be deleted).
  - `AboutPageTest`:
    - `test_seed_maps_intro_and_every_team_member` (3 members, mixed BG/EN, one with photo `'javascript:x'` → image `''`, one with no BG name → EN name; Review Focus 3).
    - `test_seed_without_team_has_no_people_section`.
    - `test_seed_is_written_once_and_never_overwrites`: call `about_page()`, change the title via `cpage_update`, call `about_page()` again, and the title stays changed (Review Focus 5).
    - `test_seed_race_keeps_existing_file`: create the file first, then `about_page()` returns it unchanged.
    - `test_bg_and_en_pages_render_blocks`: render `za-nas/index.php` and `en/about/index.php`. Each shows its title, intro, team names and the label `За нас`/`About us`, with no `👤`.
    - `test_title_is_editable_inline`: with the admin bar on, the `<h1>` has `data-cms-section="pagetitle:p_000about"`.
  - `InlineSaveTest`: `test_pagetitle_saves_both_languages`, `test_pagetitle_refuses_empty_bg`, `test_pagetitle_unknown_page`.

- [ ] **Step 2: Run to see them fail.** Run: `php vendor/bin/phpunit tests/AboutPageTest.php tests/CreatedPages tests/InlineSaveTest.php`. Expected: FAIL.

- [ ] **Step 3: Implement** in this order: the `created_pages.php` protections, `about_seed_page()`/`about_page()`, `templates/block-page.php`, and `page.php` switched to the template (behaviour unchanged, and its tests in `tests/CreatedPages/CreatedPagesRoutingTest.php` must stay green). Then the two About files, the inline-save `pagetitle:` branch, removal of the old about branches in inline-save/add/remove, and the admin links. In `admin/pages.php`, the За нас list row's edit link becomes `/admin/page-edit.php?id=p_000about`. Its old editor branch (`$page === 'about'`) shows only a link card to the new editor, and the POST branch for `section=about` is deleted. Grep `about` in `admin/` and `includes/` afterwards and list every remaining hit in the commit message body.

- [ ] **Step 4: Run the full suite.** Run: `php vendor/bin/phpunit`. Expected: PASS.

- [ ] **Step 5: Browser check** with a copy of real-looking `pages.json` (intro + 3 members, one without photo):
  - /za-nas/ and /en/about/ look as they did before, apart from the missing 👤.
  - In Edit mode, the title, the intro and the photos are clickable and save.
  - Add 4 photos to the intro block in Admin → Страници → За нас, then check desktop and 375px.
  - The page is listed in Страници with no delete button and fixed addresses.
  - Check with the keyboard only.

- [ ] **Step 6: Commit**
  `git add` the files listed above by name, then `git commit -m "feat(about): За нас is a block page — seeded once from pages.json, built-in, not deletable"`

---

### Task 6: Whole-branch check

- [ ] **Step 1:** `php vendor/bin/phpunit`, all green.
- [ ] **Step 2:** `git log --oneline origin/main..HEAD` lists only this work's commits: the spec, the plan and Tasks 1–5.
- [ ] **Step 3:** Stop. Report to Lina and wait for "push". After the push, the release, lafetki's update and the oddminds port happen in later steps of their own, as in the spec's Rollout section.

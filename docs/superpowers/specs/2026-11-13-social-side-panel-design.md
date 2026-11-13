# Social posts beside the article — design

Date: 2026-11-13 · Status: draft for approval

## Why

Writing an article and its social posts are two separate jobs today. The posts live on a
separate Social tab that only works after the first save, and Claude writes their text from
the *saved* file. The author then often has to correct that text, because the AI version
isn't good enough to post unchecked. The author wants to see what each platform will get
**while writing**, and to stop maintaining a second text for every post.

## Decisions (made by the author)

- **The post is the article.** Facebook and Instagram post the BG article text, and
  LinkedIn posts the EN article text. There is no separate post text and no AI-written post.
- **Long text is cut at the last full sentence that fits**, followed by a "read more" link
  to the article. This replaces the excerpt approach.
- **A "Socials" button opens a panel beside the article.** The panel is built from what is
  in the editor right now, saved or not. Clicking the button again closes it.
- **The previews refresh while you type**, about a second after you stop.

## Goals

- See the exact Facebook, Instagram and LinkedIn post while writing, including for an
  article that has never been saved.
- One text to write and correct, which is the article itself.
- Social posting works without Claude being configured.

## Non-goals

- Server-side, cross-device drafts. That is a separate job (Part 1 of the autosave work).
- Editing a post differently from the article.
- Changing how photos and Instagram crops are chosen. The existing crop UI keeps working.

## Anti-goals (failure even if it "works")

- A preview that differs from what Buffer actually receives.
- Social posting being blocked by a refusal or error when the text is "too long". It is
  cut instead — the same rule as photos: pick a shape and auto-crop, never refuse.
- The editor becoming cramped on a laptop when the author doesn't need the posts.

## Approaches considered

1. **Panel that is always open beside the editor.** You see everything at once, but the
   editor is about 60% wide all the time. Rejected because it squeezes writing.
2. **Panel opened by a "Socials" button, with live refresh.** ← **picked.** It is off by
   default, so the editor keeps its full width. Open, it gives the side-by-side view.
3. **Keep the separate Social tab but build it from the unsaved text.** This is the least
   work, but it is not side-by-side. Rejected.

How the preview text is made, which is my call (the "how" was handed to me):
- **Server endpoint (picked).** The editor sends its current HTML and the server returns
  the cut post text. It uses the same PHP function that scheduling uses, so the preview
  and the real post can't drift apart.
- A JS copy of the conversion was rejected, because two copies of the same logic would
  drift apart.

## Design

### Shared text builder — `includes/articles.php`

A new function, `social_post_text(string $title, string $html, string $url, string $platform): string`:
1. The post is **title, blank line, body**. This is how the Facebook text is pre-filled
   today. *(Builder's call: confirm with the author when reviewing.)* The body comes from
   `html_to_social_text()`, which already keeps paragraphs, line breaks and list items, so
   it only needs a regression test. Facebook and Instagram use the BG title and body.
   LinkedIn uses the EN title and body.
2. Platform limits: Instagram 2,200 characters, LinkedIn 3,000, Facebook none in
   practice. Characters are counted the way the platforms count them. The bold-letter
   symbols count as more than one, so use a conservative count.
3. If the text plus the link line fits, the result is the full text, a blank line, then
   the link line.
4. Otherwise the text is cut at the last full sentence end (`.`, `!`, `?`, `…`, closing
   quote) so that the text, `…`, and the link line all fit. If no sentence end fits, the
   cut falls on a word boundary.
5. The link line is "Прочетете повече: <url>" for BG and "Read more: <url>" for EN.
   The wording goes in `strings.json`, so each site can change it.

Instagram captions don't make links clickable. The link is still included as text, which
matches today's behaviour.

### Preview endpoint — `admin/social-preview-ajax.php` (new)

- POST with JSON `{csrf_token, content_bg, content_en, slug, slug_en, title, title_en}`.
  It calls `admin_require_login()`, `module_ajax_guard('social')` and `csrf_verify()`.
- Returns `{ok, fb, insta, linkedin}`. Each entry is `{text, length, limit, cut: bool}`.
- The URL comes from the slugs computed with `article_compute_slugs()`, so an unsaved
  article gets the URL it *will* have.
- It saves nothing and caps the input size at about 500 KB.
- The `admin/` directory already has `.user.ini`, so no extra docroot pin is needed.
- The **"read more" link line is new in the posted text.** Until now only the AI prompt
  added a link. Adding it is intended.

### Editor UI — `admin/article-edit.php`

- The Content and Social tabs are removed, and everything is on one page. A **"Socials"
  toggle button** (`aria-expanded`, `aria-controls`) sits in the editor toolbar area.
- **Open:** the layout becomes a two-column grid (article about 60%, panel about 40%).
  Below about 1100px the panel stacks under the article. Layout styles are inline, per
  CLAUDE.md. The open or closed state is remembered in `localStorage`.
- **Panel contents:** platform tabs for Facebook, Instagram and LinkedIn, made as real
  buttons with `role="tablist"`. Each platform shows:
  - a post-like preview of the text (with paragraph breaks) and the first photo, or the
    Instagram crops already in use;
  - a length counter such as "1 840 / 2 200". When the text is cut, a text note says
    "Текстът е съкратен до последното цяло изречение", not shown by colour alone;
  - the schedule controls for that platform, kept from today: date and time, "Schedule",
    "Publish now", the scheduled/posted status, and the Buffer edit link;
  - for LinkedIn, the existing "record the LinkedIn post URL" field stays.
- **Live refresh:** a debounced call (about 1 second) on TinyMCE `input`/`change` and on
  title and slug input, only while the panel is open. Only the latest response counts, so
  older replies are ignored. A `role="status"` live region announces "Прегледът е обновен"
  at most once per refresh and never on every keystroke.
- **Empty EN version:** LinkedIn shows "Няма английски текст — преведете статията" with
  the existing translate button. LinkedIn scheduling is disabled until the EN content
  exists.
- **The EN editor is collapsed by default.** The preview reads `content_en` from the
  TinyMCE instance if it has started, and from the textarea otherwise.

### Scheduling — `social-ajax.php`, `linkedin-ajax.php`

- **Scheduling from the panel first saves the article.** It sends the same form to the
  same save handler. When the request is sent by AJAX, the handler returns JSON instead of
  a flash message and redirect: `{ok, slug, slug_en, warnings}`, where `warnings` holds the
  image error the handler already reports separately. The page then uses the returned slugs,
  because saving can change them, and updates the address bar with `history.replaceState`.
  Scheduling happens only if the save succeeds. A failed save shows a visible error. A new
  article is saved with whatever status the form shows, which defaults to draft.
- **The server builds the posted text itself** from the saved article with
  `social_post_text()`. The `schedule` action no longer accepts `social_text` or
  `linkedin_text` from the client. This keeps the post identical to the preview and
  removes a client-supplied text input.
- **Instagram Post/Story switch stays.** A Story has no caption, as today, and the
  2,200-character limit applies only to Instagram posts.
- **No text means no scheduling.** If the saved BG body is empty, Facebook and Instagram
  scheduling is disabled with a text explanation. The same applies to LinkedIn and the EN
  body.
- **Scheduling a post publishes the article right away** (author's decision), whatever
  the post's date. The save that runs before scheduling sets the status to published and
  clears any auto-publish date. Both the BG and EN versions are published together, as the
  form already does. An article that's already published is left alone.
  - Before you click, the schedule controls say so in plain text when the article is still
    a draft, for example "Статията още е чернова — ще бъде публикувана веднага щом
    планирате публикацията." There's no `confirm()`. After scheduling, the status field in
    the editor shows "Публикувана".
  - Switching the article back to draft later does not cancel posts already in Buffer,
    which is the same as today. The editor shows a visible warning when a draft has
    scheduled posts.
- **Rescheduling a post already in Buffer** uses the existing Buffer edit-post path, now
  with the newly built text.
- **The `generate` actions and their prompts are removed from both `social-ajax.php` and
  `linkedin-ajax.php`.** The Social panel no longer
  depends on `claude_is_configured()`.

- **What stays and what goes in the panel:** the **emoji picker stays** (author's decision).
  Because the post is the article, it inserts the emoji at the cursor in the article
  editor — BG for Facebook/Instagram, EN for LinkedIn — so it also shows on the website.
  The **Copy** button stays, for posting by hand somewhere else. Crops,
  status badges, Buffer edit links and the LinkedIn "record URL" field stay. The editable
  textareas become read-only previews, so `TranslateButtonCoverageTest` is unaffected
  because no `*_en` inputs are added.

### Data

- Scheduling **stops writing** `fb_text`, `insta_text`, `social_text` and `linkedin_text`,
  and nothing reads them any more. Old values are left in the article JSON. No migration is needed. Posts already in
  Buffer keep the text they were sent with.
- No database changes.

## Edge cases

- **Article with no text yet:** the panel shows "Започнете да пишете — тук ще видите
  публикациите."
- **A sentence longer than the limit:** cut at a word boundary, with no error.
- **Slug changed after scheduling:** the link in an already-scheduled Buffer post goes
  stale. This is the same as today. Rescheduling rebuilds the text.
- **Two tabs on the same article:** each previews its own unsaved text. Saving is
  unchanged.
- **Social module switched off:** no button and no panel. The endpoint returns the
  module-guard error.
- **Network failure on refresh:** the old preview stays, with a visible "Прегледът не е
  обновен — опитайте отново" message and a retry button. The editor is never blocked.

## Tests (`tests/`)

- `social_post_text()`: text that fits, a cut at a sentence end, no sentence end (word
  cut), the bold-letter symbols, paragraph and list breaks, and the BG and EN link lines.
- `html_to_social_text()` paragraph handling, as a regression check.
- Preview endpoint: rejected without login, rejected without CSRF, rejected when the module
  is off, oversized input capped, and correct shape returned for an unsaved article.
- `schedule` ignores any client-supplied text and builds it from the saved article.

## Rollout

- Built in **ngo-cms** first, then ported to **oddminds** (commit trailer
  `Ported: ngo-cms <sha>`). It reaches **lafetki** with the next tagged release.
- Every NGO on ngo-cms gets this behaviour, and the release notes say that AI-written posts
  are gone.

## Left to the builder

- The exact grid breakpoints and the preview card styling, within the accessibility rules
  in CLAUDE.md.
- The exact sentence-end characters and the conservative character count.
- How the photo preview is chosen for an article whose photos aren't saved yet: use the
  photos currently in the form.
- Breaking the 1,700-line `article-edit.php` script into a separate
  `admin/js/social-panel.js` if that keeps it readable.

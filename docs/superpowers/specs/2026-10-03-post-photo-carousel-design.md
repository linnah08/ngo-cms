# Post photo carousel — design

Trello #17: "Add a carousel showing multiple pics per post and the author chooses
which one is used as the post image". Scoped 03.10.2026.

## Goal

A news post can have up to 10 photos instead of one. On the article page they
show as a swipeable carousel; the author marks one photo as **main**, and that
photo is the post's image everywhere a single image is used. When the post is
shared through Buffer, all photos go out: an Instagram carousel, a Facebook
multi-photo post and a LinkedIn document carousel.

Typical use: an event report with 5–8 photos, written by a non-technical author.

## Decisions (agreed in scoping)

| Question | Decision |
|---|---|
| Where the photos show | They replace the image at the top of the article (not a gallery inside the text) |
| Social posts | All photos go out, main photo first |
| Photo limit | 10 per post (Instagram's carousel limit) |
| Text per photo | Optional caption, BG + EN; used as the photo's `alt` and shown under it in the carousel |
| Adding photos | Pick many at once, no crop on upload; each photo has its own "Изрежи" button |
| Storage | A `photos` list in the post's JSON; `image` stays and always holds the main photo |
| Instagram shape | Square (1:1) for every slide |
| Facebook | Multi-photo post |
| LinkedIn | Document (PDF) carousel, one square photo per page with its EN caption underneath |

## Data

Posts stay JSON files, one per language: `content/articles/{bg,en}/{slug}.json`.
Two keys matter:

```json
"image":  "/assets/images/articles/camp-1.jpg",
"photos": [
  { "src": "/assets/images/articles/camp-1.jpg", "caption": "Децата на лагера в Банско" },
  { "src": "/assets/images/articles/camp-2.jpg", "caption": "" }
]
```

- **`photos`** lists the post's photos in display order. `src` values and their
  order are identical in the BG and EN files; `caption` is that file's language.
- **`image`** always equals the main photo's `src` (or `""` when there are no
  photos). The main photo is stored implicitly as *whichever entry `image`
  points to* — no separate flag, so the two can't disagree.
- **Old posts** have `image` and no `photos`. Read them as one photo,
  `[{src: image, caption: ""}]`. No migration; the list is written the next time
  the post is saved.

One helper owns this, in `includes/articles.php`:

- `article_photos(array $article): array` — the normalised list (falls back to
  `image`, drops entries with an invalid `src`, caps at 10), main photo
  included wherever it sits in the order.
- `article_main_photo_index(array $article): int` — the index of `image` in that
  list (0 when absent).

Everything that already reads `image` — news list, home news cards, share preview
(`og:image`, schema.org), sitemap — keeps working unchanged.

## Editor (`admin/article-edit.php`)

The single "Изображение" field becomes a **photo grid**, shared by BG and EN
(like date, author and status today). Each photo is a card large enough for
44×44 tap targets:

- the thumbnail;
- **"★ Основна"** — a real `<button aria-pressed>` marking the main photo; exactly
  one is pressed. The first photo added becomes main by default;
- **drag to reorder**, plus **↑ / ↓** buttons for keyboard users (the
  home-sections pattern: `hs_sort_handle` and its live announcements);
- **"Изрежи"** — opens the existing `OMCrop` modal on that photo and replaces it
  with the cropped file;
- **"Премахни"** — removes it from the post (the file stays on disk, as today);
- **caption fields** in the BG section and the EN section of the form, one per
  photo, labelled "Надпис под снимка 2". EN fields carry
  `data-translate-from` so the shared ✦ Translate button works, including on
  photos added later (dynamic rows are already supported).

Under the grid: **"Добави снимки"** (a file input with `multiple`) and
**"Избери от библиотека"** (the existing media picker, which can be used
repeatedly). Both stop at 10 with a persistent message: "Може да добавите до 10
снимки. Премахнете снимка, за да добавите нова."

Changes to the grid are announced in a `role="status"` region ("Снимка 3 е
основна", "Снимка 2 е премахната").

**Saving.** Uploads go through the existing checks (JPEG / PNG / WebP via
`mime_content_type`, `image_resize_to_fit`). Library picks keep the existing path
whitelist (`^/assets/images/[a-zA-Z0-9/_.\-]+$`). The POST carries the order and
the main index; the server rebuilds `photos` and `image` from them, then writes
**both** language files in the same request, as it does now. A failed upload
keeps the other photos and shows a separate warning, as the single image does
today.

## Article page (`novini/index.php` and `en/news/index.php`)

- **One photo or none:** unchanged markup.
- **Two or more:** the hero becomes a carousel (a shared template,
  `templates/article-carousel.php`, included by both pages):
  - opens on the **main** photo;
  - previous / next `<button>`s with `aria-label`s and a visible "2 / 5" counter;
  - swipe on touch screens, ← / → when the carousel has focus;
  - the caption shows under the photo and is its `alt`; with no caption the
    `alt` is "Снимка 2 от 5 — <post title>" ("Photo 2 of 5 — …" in EN);
  - the slide change is announced (`aria-live="polite"` on the counter);
  - **no autoplay**; no slide animation when `prefers-reduced-motion` is on;
  - only the visible photo loads eagerly, the rest `loading="lazy"`;
  - layout-critical styles inline (main.css may be stale-cached).

## Social posts (`admin/social-ajax.php`, `admin/linkedin-ajax.php`)

The Buffer `assets` list carries every photo, main first, then the rest in grid
order.

- **Instagram:** `metadata.instagram.type: carousel`, every photo cropped centred
  to 1:1 into a `-ig` copy (the existing resize/crop code, made to work on a list).
  One photo → unchanged single post.
- **Facebook:** all photos as image assets (`type: post`).
- **LinkedIn:** a PDF built with mPDF — one square page per photo, the EN caption
  underneath (BG when there's no EN caption), main photo first. Saved next to the
  post's images, regenerated when the photos change. Sent as one `document` asset
  (`url`, `title` = EN post title, `thumbnailUrl` = main photo). One photo →
  unchanged single-image post.

### Must be verified before any code is written

Per CLAUDE.md (Buffer returns HTTP 200 for errors — read the body):

1. A real Buffer test post with several image assets on Instagram as
   `type: carousel`, and on Facebook: accepted? how many images?
2. A real `document` asset on **this** LinkedIn channel (personal profile or company
   page): accepted? PDF size/page limits?

If LinkedIn refuses documents, stop and ask: fall back to a multi-photo post or
to the main photo only.

### Related

Rescheduling an already-scheduled post calls Buffer's removed `updatePost`
mutation (Trello #20). Fix #20 first or together with this work, otherwise changed
photos never reach a post that's already scheduled.

## Edge cases

- The main photo is removed → the next photo in the grid becomes main; no photos
  → `image` becomes `""`.
- A photo's file is missing on disk → skipped in the carousel and in the Buffer
  assets, with a log line (as the single image is today).
- The BG and EN files disagree on `photos` (hand-edited JSON, a half-finished
  save) → the BG file's list wins on the next save; the page renders whatever
  the language's own file says.
- A slug change → photos are paths under `/assets/images/articles/`, not tied to
  the slug, so nothing moves. The LinkedIn PDF is regenerated.
- More than 10 photos in a hand-edited file → only the first 10 are used.

## Out of scope

- Carousels or galleries inside the article text.
- Deleting unused photo files from disk.
- Video.
- A final LinkedIn slide with logo and link (can come later).

## Files

- `includes/articles.php` — `article_photos()`, `article_main_photo_index()`
- `admin/article-edit.php` — photo grid, saving
- `admin/translate-article-ajax.php` — the ✦ translate-to-English button copies the photos and translates captions
- `admin/inline-save-article.php` — the public page's "📷 Replace" swaps the main photo inside `photos`
- `templates/article-carousel.php` — new, shared by both article pages
- `novini/index.php`, `en/news/index.php` — use the carousel
- `admin/social-ajax.php` — multiple assets, Instagram carousel
- `admin/linkedin-ajax.php` — the PDF document asset
- `includes/documents/` — the LinkedIn PDF generator, on the existing mPDF setup

## Tests

- `article_photos()`: an old post (only `image`), the cap at 10, invalid paths
  dropped, the main photo found wherever it sits in the order.
- Saving: identical `src` order in BG and EN, separate captions, `image` equals the
  main photo, removing the main photo promotes the next one.
- The carousel template: a single photo renders no carousel; two or more render
  the controls, `alt` from the caption or the fallback, escaped captions, open on
  the main photo.
- Buffer request building: every photo in `assets`, main first; Instagram
  `type: carousel`; LinkedIn a single `document` asset.
- `TranslateButtonCoverageTest` passes for the EN caption fields.
- Security: the editor's photo POST is CSRF-checked and admin-only; library paths
  stay whitelisted; no raw caption reaches the page unescaped.

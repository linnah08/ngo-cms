# Image cropper — design spec

Date: 2026-06-10

## Goal
Add a crop step to every place an admin adds a photo (products, articles, pages, partners, campaign, newsletter, inline CMS). After a file is picked, a crop modal always opens; the user adjusts and confirms before upload. Crops must stay within Instagram/Facebook ratio limits to avoid Buffer scheduling rejections.

## Decisions
- **Trigger:** always show the cropper after a file is selected.
- **Aspect:** free-form drag + preset buttons.
- **Scope:** wired everywhere at once.
- **TinyMCE paste/drag:** out of scope for v1 (Insert-image button only).
- **IG ratio guard:** warn only, never block. No auto-crop in the cropper — see below.

## Instagram fitting is already handled server-side
`admin/social-ajax.php:290-336` already auto-fits images for Instagram at schedule time: too tall → center-crop to 4:5, too wide → center-crop to 1.91:1, written to a separate `-ig` copy with the original left intact. That is the safety net guaranteeing Buffer/Instagram never rejects on ratio.

The upload cropper does NOT duplicate this. Rationale: the cropper runs on every admin image (most never go to social); the same file serves both website and social; and the server crop is a dumb center-crop that can chop a subject. The cropper's job is to let the user *deliberately* frame the shot so the server's center-crop never has to guess. The two layers are complementary, not redundant.

## Architecture
- **Vendor Cropper.js** (~v1.6.x) self-hosted under `assets/vendor/cropperjs/` (`cropper.min.js` + `cropper.min.css`). No CDN. Verify the vendored files load (HTTP 200 path) before wiring.
- **Wrapper:** `assets/js/image-cropper.js` exposes `OMCrop` — builds the modal (fully inline styles per CLAUDE.md overlay rule), runs Cropper, returns a cropped `File`.
- **Load point:** both files included in `admin/includes/admin-header.php` so every admin page has them.

### Plain file inputs
Tag each `<input type="file">` that should crop with `data-om-crop`. A single global capture-phase listener:
1. Intercepts the first `change`, reads the file, opens the crop modal.
2. On confirm, builds a cropped `File`, sets `input.files` via `DataTransfer`, re-dispatches a synthetic `change` (guarded by a flag) so the page's existing upload handler runs unchanged with the cropped file.
3. On cancel, clears the input.

Inputs to tag: product main/gallery/variant/size-guide, article featured image, pages, partners, campaign, inline CMS. (Animated GIFs skip cropping and upload as-is.)

### TinyMCE inline images
Add `file_picker_callback` to the shared `window._tinyBase` in `admin-header.php`: Insert image → pick file → crop → return cropped blob to TinyMCE. Single shared config only — no per-page override.

## Cropper UI
- Free-form drag by default.
- Presets: **Square 1:1, Portrait 4:5, Landscape 1.91:1, 16:9, Original.**
- Live ratio note when crop is taller than 4:5 (0.8) or wider than 1.91:1: *"Instagram will auto-crop this to fit — crop it here if you want to control what's kept."* Non-blocking (warn only).
- Output: PNG when source has transparency (png/webp/gif), else JPEG ~0.9. Filename set so the upload endpoint's extension logic works.
- Accessible: focusable controls, Escape to close, labelled buttons (per accessibility-by-default).

## Server
No change required. `/admin/upload-product-image.php` already validates mime + size; cropped blob is uploaded as a named file.

## Tests
- JS-level: cropper produces a File; PNG vs JPEG selection by source type; GIF bypass.
- Endpoint: existing upload test still passes with a blob-sourced file.
- Manual: confirm crop works on product main image and article featured image in the browser before shipping.

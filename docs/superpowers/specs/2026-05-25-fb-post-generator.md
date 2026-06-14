# Spec: FB Post Generator + FB/Instagram Text Split

**Date:** 2026-05-25
**Status:** Approved

---

## Overview

The FB/Instagram social panel in `admin/article-edit.php` currently has one shared textarea and one generic "Генерирай текст (БГ)" button. This spec covers:

1. Adding a second FB generate button ("Генерирай FB пост") that uses a branded prompt and links to the BG article URL.
2. Renaming the existing generate button to "Генерирай FB новина".
3. Splitting the single shared textarea into separate FB and Instagram textareas.
4. Adding a "Копирай от FB" button for Instagram that copies FB text and strips links.

---

## UI Changes (article-edit.php)

### FB sub-section (within the existing social panel card)

**Generate row** — two buttons side by side (same row, same style as existing):
- `✦ Генерирай FB новина` — existing prompt, standalone news post
- `✦ Генерирай FB пост` — new branded prompt, teaser + article URL

Spinner and error span remain in the same row.

**FB textarea** (`id="fbText"`) — label row: "Текст за Facebook" on the left, Copy button on the right.

**Emoji picker** — collapsible toggle, FB only (unchanged behaviour).

**Char counter** — FB only (limit: 63 206).

**Schedule row** — datetime-local input + "Планирай в Facebook" + "Публикувай сега (FB)".

---

### Divider — thin `<hr>` with a small "Instagram" label

---

### Instagram sub-section

**Single line** — label "Текст за Instagram" on the left, "📋 Копирай от FB" button on the right (inline with the label, no separate row).

**Instagram textarea** (`id="instaText"`).

**Copy button** — inside the label row, same style as existing Copy buttons.

**Char counter** — IG only (limit: 2 200).

**Schedule row** — own datetime-local input + "Планирай в Instagram" + "Публикувай сега (IG)".

No emoji picker for Instagram.

---

## Data Model

Two new fields added to the BG article JSON:

| Field | Type | Notes |
|---|---|---|
| `fb_text` | string | Replaces `social_text` for FB |
| `insta_text` | string | New field for Instagram-specific text |

`social_text` is preserved in all saves for backward compatibility. When loading the editor, fall back to `social_text` if `fb_text` is empty.

`article-edit.php` POST handler must preserve both `fb_text` and `insta_text` in the same way it currently preserves `social_text`.

---

## Backend (social-ajax.php)

### `generate` action

New required field: `generate_type` — `'news'` or `'post'`.

**`news`** — uses the existing prompt (standalone post, no link). Saves result to `fb_text`.

**`post`** — uses the new branded prompt below. Article URL is constructed as `SITE_URL . '/novini/' . rawurlencode($slug) . '/'`. Saves result to `fb_text`.

New branded prompt for `post` (system instructions embedded in user message):

```
You write Facebook posts for Фондация Различни умове (Odd Minds Foundation) — a Bulgarian NGO supporting children with developmental differences and ones growing up without parental care.

VOICE: Warm, honest, occasionally dry/funny. Team voice ("ние"), never corporate. Story-first. Emotion through specific detail, not adjectives.

STRUCTURE: Hook → context/story → what the article covers → link. CTA at the end only.

FORMATTING RULES:
- Bulgarian only
- 100–200 words
- Short paragraphs, generous white space
- 1–2 emojis max, never mid-sentence
- No dashes or em-dashes — use full stops instead
- No hashtags unless explicitly requested
- No exclamation marks more than once per post
- End with: 👉 [ARTICLE_URL]

DO NOT:
- Start with "С радост съобщаваме", "Какво става когато" or "С удоволствие споделяме"
- Use NGO jargon: устойчивост, въздействие, екосистема, уязвими групи
- Use dashes to connect clauses (reads as AI-generated)
- Over-explain the foundation's mission
- Invent overly specific illustrative examples

Write a Facebook post for this article.
Title: {TITLE}
Content: {CONTENT}
Article URL: {ARTICLE_URL}

Return only the post text. No explanations, no alternatives.
```

---

### `schedule` action

- `channel: 'fb'` → reads `social_text` from request body, saves to `fb_text` in JSON (as before, also preserves `social_text`).
- `channel: 'insta'` → reads `social_text` from request body, saves to `insta_text` in JSON.

No change to the Buffer API call logic.

---

## JS Changes (article-edit.php inline script)

- `socGenerateBtn` is removed; replaced by `socGenerateNewsBtn` and `socGeneratePostBtn` — both call `socPost({ action: 'generate', generate_type: 'news'|'post' })` and set `fbText.value`.
- `socText` renamed to `fbText` throughout.
- New `instaText` textarea replaces the IG portion of the old `socText`.
- `_socGetText()` reads from `fbText` for FB scheduling and `instaText` for IG scheduling.
- `copy_to_insta` button is **client-side only**: takes `fbText.value`, strips lines that are bare URLs or start with `👉`, sets `instaText.value`. No AJAX call.
- Character counters updated: FB counter watches `fbText`, IG counter watches `instaText`.
- Emoji picker `_insertEmoji` inserts into `fbText`.

---

## Edge Cases

- **Existing articles** with `social_text` but no `fb_text`: the FB textarea pre-fills from `social_text`; `insta_text` textarea starts empty.
- **New articles** (unsaved): the generate and copy buttons are hidden (same condition as today — `!$is_new`).
- **Copy before FB text exists**: "Копирай от FB" is a no-op if `fbText` is empty (button does nothing).
- **Link stripping for Instagram**: remove any line whose trimmed content is only a URL, and any `👉 …` trailing line. Do not strip URLs embedded mid-sentence (edge case: shouldn't occur given the prompt).

---

## Files Changed

| File | Change |
|---|---|
| `admin/article-edit.php` | UI split, new buttons, rename IDs, preserve `fb_text`/`insta_text` in POST handler |
| `admin/social-ajax.php` | `generate` action: `generate_type` param + new prompt; new `copy_to_insta` action; `schedule` saves to correct field |

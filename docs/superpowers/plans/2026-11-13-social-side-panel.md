# Social Posts Beside the Article — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The article editor shows the Facebook, Instagram and LinkedIn posts in a "Socials" panel beside the article. The posts are the article itself, cut to fit each platform, built live from the unsaved text. Scheduling a post saves and publishes the article.

**Architecture:** One PHP function, `social_post_text()`, builds the post text. Both the preview endpoint (from unsaved editor HTML) and the schedule endpoints (from the saved article) use it, so the preview and the real post can't differ. The editor loses its Content/Social tabs. A toggle opens a panel whose JS lives in `admin/js/social-panel.js`. The AI `generate` actions are removed.

**Tech Stack:** PHP 8.4, no framework; flat-file JSON articles; TinyMCE; vanilla JS; PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-11-13-social-side-panel-design.md`

## Global Constraints

- Work in the worktree `/Users/detelinavasileva/Code/oddminds-oss-wt/social-side-panel` (branch `social-side-panel`). Symlink `vendor/`, `node_modules/` and the gitignored `*.config.php` from the main checkout before running tests.
- Run the whole suite with `php vendor/bin/phpunit` from the worktree root.
- Limits: Instagram post 2,200 characters, LinkedIn 3,000, Facebook unlimited (treat as 63,206). An Instagram Story has no caption.
- Facebook and Instagram use the BG title and body. LinkedIn uses the EN title and body.
- A post is the title, a blank line, the body, a blank line, then the link line.
- Link-line wording comes from the strings key `social.read_more`: BG "Прочетете повече", EN "Read more", followed by `: <url>`.
- Public URLs: BG `SITE_URL/novini/<slug>/`, EN `SITE_URL/en/news/<slug_en>/`.
- Social posting is never refused for length. The text is cut instead.
- Every admin endpoint calls `admin_require_login()`, `module_ajax_guard('social')` and `csrf_verify()`. The JS-driven panel's layout-critical styles are inline. There is no `window.confirm()`.
- Accessibility rules from CLAUDE.md apply: real buttons, `aria-expanded`/`aria-controls`, `role="status"`, text and not colour alone, 44px targets.
- Plain-language BG copy, exactly as the spec gives it:
  - "Текстът е съкратен до последното цяло изречение"
  - "Няма английски текст — преведете статията"
  - "Започнете да пишете — тук ще видите публикациите."
  - "Прегледът не е обновен — опитайте отново"
  - "Прегледът е обновен"
  - "Статията още е чернова — ще бъде публикувана веднага щом планирате публикацията."
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Commit locally only; never push.

## Review Focus

1. **The cut lands inside a URL, number or abbreviation** ("ул. Шипка", "3.5", "т.е."). A reasonable person expects no cut mid-word. Test: `testCutDoesNotSplitAbbreviationMidWord` in Task 1. A sentence end requires the punctuation to be followed by whitespace and a capital letter or end of text.
2. **Bold letters count double.** Text with `<strong>` goes over 2,200 for Instagram even though `mb_strlen` says it fits. Test: `testBoldTextCountedAsTwoUnits` in Task 1. Count UTF-16 code units, the conservative way.
3. **Out-of-order preview replies** on a slow phone, where an old reply overwrites a newer preview. Handled by a request counter in Task 5, with the source test `testPanelIgnoresStaleResponses`.
4. **Schedule clicked with an empty EN body after a BG-only save.** LinkedIn must refuse in plain text, not post an empty post. Test: `testLinkedInScheduleRefusesWithoutEnBody` in Task 4.
5. **The save before scheduling changes the slug** (the title was edited). The schedule must use the returned slug, not the page-load slug. Test: `testAjaxSaveReturnsNewSlug` in Task 3, plus the Task 6 source test `testScheduleUsesSlugFromSaveResponse`.

---

### Task 1: Post-text builder

**Files:**
- Modify: `includes/articles.php` (add after `html_to_social_text()`)
- Modify: `content/bg/strings.json`, `content/en/strings.json` (add `social.read_more`)
- Test: `tests/Admin/SocialPostTextTest.php` (new)

**Interfaces:**
- Produces:
  - `article_public_url(string $slug, string $lang): string` — `$lang` is `'bg'|'en'`.
  - `social_text_length(string $text): int` — counts UTF-16 code units.
  - `social_post_limit(string $platform): int` — `$platform` is `'fb'|'insta'|'linkedin'`.
  - `social_post_text(string $title, string $html, string $url, string $platform): array` returns `['text' => string, 'length' => int, 'limit' => int, 'cut' => bool]`. An empty title and an empty body give `text === ''`.

- [ ] **Step 1: Write the failing tests** in `SocialPostTextTest`:
  - `testShortArticleIsTitleBodyAndLink`: title `'Заглавие'` and body `'<p>Едно.</p><p>Две.</p>'` for `fb` give exactly `"Заглавие\n\nЕдно.\n\nДве.\n\nПрочетете повече: https://x/novini/a/"`, with `cut === false`.
  - `testLinkedInUsesEnglishReadMore`: the text for `linkedin` ends with `"Read more: <url>"`.
  - `testLongTextCutAtLastFullSentence`: 300 sentences of `'Изречение номер N е тук. '` for `insta`. Assert `length <= 2200`, `cut === true`, and that the body part ends with `'тук.'` followed by `" …\n\nПрочетете повече: "`.
  - `testNoSentenceEndCutsAtWord`: one 3,000-character sentence of words with no full stop. The result fits 2,200, ends at a whole word, then `…`.
  - `testCutDoesNotSplitAbbreviationMidWord`: text containing `'ул. Шипка'` repeated near the limit. The cut never leaves `'ул.'` as the last token.
  - `testBoldTextCountedAsTwoUnits`: `social_text_length('𝗔')` is 2.
  - `testFacebookNeverCuts`: 20,000 characters for `fb` gives `cut === false`.
  - `testEmptyArticleGivesEmptyText`: an empty title and body give `text === ''` and `length === 0`.
  - `testHtmlToSocialTextKeepsParagraphsAndLists` (regression): `'<p>A</p><ul><li>b</li></ul>'` gives `"A\n\n• b"`.
  - `testPublicUrls`: `article_public_url('x','bg')` ends with `/novini/x/`, and `article_public_url('y','en')` ends with `/en/news/y/`.

- [ ] **Step 2: Run them and confirm they fail.** Run `php vendor/bin/phpunit tests/Admin/SocialPostTextTest.php`. Expected: FAIL with "Call to undefined function".

- [ ] **Step 3: Implement the four functions in `includes/articles.php`. Add `"social.read_more"` to both `strings.json` files.**
  - The link line is `t_or('social.read_more', 'Прочетете повече', 'Read more', $lang) . ': ' . $url`, with `$lang` set to `en` for linkedin and `bg` otherwise.
  - The cut algorithm, in this order:
    1. The budget is the limit, minus the length of `" …\n\n" . $link`.
    2. Inside the first budget units of the body, find the last sentence end. A sentence end matches `/[.!?…]["»”]?(?=\s+[\p{Lu}\d"«„]|\s*$)/u`, excluding matches where the preceding token is 1–3 lowercase letters (an abbreviation such as `ул.` or `т.е.`).
    3. If none is found, cut at the last whitespace.
    4. Rtrim, then append `" …"`.
  - The title counts toward the body.

- [ ] **Step 4: Run the tests and confirm they pass.** Run the same command. Expected: PASS. Then run the full suite, which must stay green (`EditModeWordingTest` checks strings keys).

- [ ] **Step 5: Commit** with the message `feat(social): one builder for post text — article cut to each platform's limit`.

---

### Task 2: Preview endpoint

**Files:**
- Create: `admin/social-preview-ajax.php`
- Modify: `tests/Security/RoleGuardedAjaxTest.php` (list the new endpoint alongside `social-ajax.php`, with the same roles)
- Modify: `tests/ModulesGatingTest.php:343` (add the file to the module-guard loop)
- Test: `tests/Admin/SocialPreviewHttpTest.php` (new; copy the server and login harness from `tests/Admin/SocialCropHttpTest.php`)

**Interfaces:**
- Consumes: `social_post_text()`, `article_public_url()`, `article_compute_slugs()`.
- Produces: `POST /admin/social-preview-ajax.php` with a JSON body of `{csrf_token, title, content, title_en, content_en, slug, slug_en}`. It returns `{ok:true, fb:{text,length,limit,cut}, insta:{…}, linkedin:{…}}` or `{ok:false, error}`. It writes nothing.

- [ ] **Step 1: Write the failing tests** in `SocialPreviewHttpTest`:
  - `testRejectsWithoutLogin`: `ok === false`.
  - `testRejectsWithoutCsrf`: `ok === false`.
  - `testUnsavedArticlePreview`: logged in, with `title` `'Нова статия'`, `content` `'<p>Текст.</p>'` and empty slugs. `fb.text` contains `'/novini/nova-statiya/'` (the slug computed from the title, so check what `slug()` returns) and `linkedin.text === ''` because the EN body is empty.
  - `testOversizedInputRejected`: a 600 KB `content` gives `ok === false` and a plain-language error.
- [ ] **Step 2: Run them and confirm they fail** (404, or the JSON doesn't parse).
- [ ] **Step 3: Implement the endpoint.** Use the header and guards of `admin/social-ajax.php`, reading the JSON body and copying `csrf_token` into `$_POST` as that file does. Reject a raw body over 500 KB. Build the URLs from `article_compute_slugs($slug, $slug_en, $title, $title_en)`. If `content_en` is empty after `trim(strip_tags())`, the `linkedin` entry is `{text:'', length:0, limit:3000, cut:false}`.
- [ ] **Step 4: Run the new tests, `RoleGuardedAjaxTest` and `ModulesGatingTest`, and confirm they pass.**
- [ ] **Step 5: Commit** with the message `feat(social): preview endpoint builds posts from unsaved editor text`.

---

### Task 3: Save by AJAX, and "publish now"

**Files:**
- Modify: `admin/article-edit.php` (POST handler, roughly lines 28–190)
- Modify: `includes/articles.php` (a pure helper)
- Test: `tests/Admin/ArticleSaveLogicTest.php` (unit), `tests/Admin/ArticleAjaxSaveHttpTest.php` (new; HTTP harness as in Task 2)

**Interfaces:**
- Produces:
  - `article_apply_publish_now(string $status, bool $scheduled, bool $publish_now): array` returns `[string $status, bool $scheduled]`. With `$publish_now` it returns `['published', false]`; otherwise it returns the inputs unchanged.
  - The save POST accepts `_ajax=1` and `publish_now=1`. With `_ajax=1` it responds with `Content-Type: application/json`:
    - success: `{ok:true, slug, slug_en, status, warnings:[string]}`, where the image error, if any, goes in `warnings`;
    - failure: `{ok:false, error}`, with no flash message and no redirect.
    - A bad CSRF token returns `{ok:false, error:'Сесията е изтекла — презаредете страницата.'}` with HTTP 400.

- [ ] **Step 1: Write the failing tests.**
  - Unit: `testPublishNowForcesPublished` covers the three combinations.
  - HTTP:
    - `testAjaxSaveReturnsJson` creates an article with `_ajax=1` and asserts `ok`, `slug`, and that the file exists.
    - `testAjaxSaveReturnsNewSlug` saves again with a changed slug field and asserts the new `slug`.
    - `testPublishNowPublishesDraft` saves a draft with `publish_now=1` and asserts that the BG and EN JSON both have `status === 'published'` and `scheduled === false`.
  - Clean up the created files in `tearDownAfterClass`.
- [ ] **Step 2: Run them and confirm they fail.**
- [ ] **Step 3: Implement.**
  - In the handler, put `[$status, $scheduled] = article_apply_publish_now($status, $scheduled, !empty($_POST['publish_now']));` immediately after `$scheduled` is computed.
  - Read `$is_ajax = ($_POST['_ajax'] ?? '') === '1';` at the top of the POST branch.
  - Branch on `$is_ajax` at the CSRF failure, at every `$error` exit, and at the success redirect.
  - Leave the non-AJAX path byte-for-byte unchanged.
- [ ] **Step 4: Run the new tests and `ArticleSaveLogicTest`, and confirm they pass.**
- [ ] **Step 5: Commit** with the message `feat(articles): save over AJAX with JSON reply; publish_now publishes on save`.

---

### Task 4: Schedule endpoints build the text themselves; drop AI generate

**Files:**
- Modify: `admin/social-ajax.php` (remove the `generate` action, roughly lines 95–262; change `schedule`)
- Modify: `admin/linkedin-ajax.php` (remove `generate`, roughly lines 54–186; change `schedule` and `save_post`)
- Modify: `includes/articles.php` (helper)
- Modify: `tests/Admin/SocialAjaxTest.php`
- Test: `tests/Admin/SocialPostTextTest.php` (add tests)

**Interfaces:**
- Consumes: `social_post_text()`, `article_public_url()`.
- Produces: `social_schedule_text(array $bg, array $en, string $channel): string`, where `$channel` is `'fb'|'insta'|'linkedin'`. It returns `''` when that language's body is empty. FB and Instagram use `$bg['title']`, `$bg['content']` and `$bg['slug']`. LinkedIn uses `$en`, with `$bg['slug_en'] ?? $bg['slug']` as the EN slug.

- [ ] **Step 1: Write the failing tests.**
  - Add to `SocialPostTextTest`:
    - `testScheduleTextFromSavedArticle` checks the FB text built from a BG array.
    - `testLinkedInScheduleRefusesWithoutEnBody` checks that `social_schedule_text($bg, [], 'linkedin') === ''`.
  - Rewrite `SocialAjaxTest`:
    - Delete `testGenerateAction*`, `testLinkedInAjaxHandlesGenerateType`, `testLinkedInAjaxHasBrandedPrompt`, `testArticleEditHasTwoGenerateButtons`, `testArticleEditHasTwoLinkedInGenerateButtons`, `testArticleEditHasSplitTextareas` and `testArticleEditPreservesFbText`.
    - Add `testScheduleIgnoresClientText`, asserting that neither ajax file contains `$body['social_text']` or `$body['linkedin_text']`.
    - Add `testNoGenerateAction`, asserting that neither file contains `=== 'generate'` or `api.anthropic.com`.
    - Add `testScheduleStopsWritingTextFields`, asserting that neither file contains `$data['fb_text']`, `['insta_text']`, `['social_text']` or `['linkedin_text']` on the left of `=`.
    - Keep the story tests. Update `testScheduleActionDoesNotRequireTextForStory` to the new variable name and keep its intent: a story needs no text.
- [ ] **Step 2: Run them and confirm they fail.**
- [ ] **Step 3: Implement.**
  - **In both schedule paths:** load the saved BG and EN JSON and set `$text = social_schedule_text(...)`.
    - If `$text === ''` and the post isn't a story, return `{ok:false}`. The FB/IG error is 'Статията няма текст за публикуване.'; the LinkedIn error is 'Няма английски текст — преведете статията.'
    - Pass `$text` wherever `$social_text` or `$linkedin_text` went: the Buffer create query, `buffer_edit_post_query()` and `save_post`.
    - Delete the `*_text` writes.
  - **Leftovers:**
    - Delete the `$org_about`/`org_ai_context` use.
    - Leave `org_ai_context()` and its test alone (out of scope).
    - Update the header doc comments.
- [ ] **Step 4: Run the full suite and confirm it passes.**
- [ ] **Step 5: Commit** with the message `feat(social): posts are built from the saved article; AI post generation removed`.

---

### Task 5: Socials toggle, panel and live previews

**Files:**
- Modify: `admin/article-edit.php`:
  - remove the `$tab` logic and the tab links (roughly lines 203–280);
  - turn the `$tab === 'social'` blocks (roughly 457–800 and 1210–the end of the social JS) into one panel rendered for new and saved articles;
  - drop the `$claude_ready` gate around social (keep it for the AI keyword and excerpt helpers);
  - stop rendering the social badges list.
- Create: `admin/js/social-panel.js`
- Test: `tests/Admin/SocialPanelTest.php` (new, source-level, in the style of `SocialAjaxTest`)

**Interfaces:**
- Consumes: the Task 2 endpoint.
- Produces:
  - DOM ids `socialToggle` (button), `socialPanel` (region), `socPreview-fb`, `socPreview-insta`, `socPreview-linkedin`, `socStatus` (`role="status"`) and `socError`;
  - a JS global `window.SocialPanel = { refresh(): Promise, isOpen(): boolean }` for Task 6;
  - a `localStorage` key of `socialPanelOpen`.

- [ ] **Step 1: Write the failing tests** in `SocialPanelTest`:
  - `testNoTabsLeft`: `article-edit.php` doesn't contain `tab=social`.
  - `testToggleIsAccessibleButton`: it contains `id="socialToggle"`, `aria-expanded=` and `aria-controls="socialPanel"`.
  - `testPanelNotGatedOnClaude`: the panel markup isn't inside a `$claude_ready` condition. Assert that the string `$tab === 'social' && !$is_new && $claude_ready` is gone.
  - `testPanelRendersForNewArticle`: there's no `Запазете статията, за да публикувате` text.
  - `testPanelIgnoresStaleResponses`: `social-panel.js` contains a request sequence check (`seq !== ` or an equivalent named counter, as decided at implementation; the test asserts the counter name you pick).
  - `testCopyStrings`: the exact BG copy for the cut note, the empty article, the refresh failure and the "Прегледът е обновен" status, from Global Constraints.
- [ ] **Step 2: Run them and confirm they fail.**
- [ ] **Step 3: Implement.**
  - **Layout:**
    - Wrap the article form and the panel in a container with inline styles: `display:grid;grid-template-columns:minmax(0,3fr) minmax(0,2fr);gap:1.5rem` when open. Below 1100px use a single column, set from JS with `matchMedia`, since the styles are inline.
    - Remove `.admin-content{max-width:960px}` from `$page_head_extra`, or raise it to 1400px only while the panel is open.
  - **Panel:** platform buttons inside `role="tablist"`. Each preview is a read-only box with `white-space:pre-wrap` and the first photo (the Instagram crop previews as today), plus a length counter "N / LIMIT". When `cut` is set, show the cut note.
  - **Scheduling:** keep each platform's existing schedule controls, status lines, Buffer links, crop UI and the LinkedIn "record URL" field. Remove the textareas `fbText`, `instaText` and `liText`, and the Generate buttons.
  - **Refresh:**
    - Read BG from `tinymce.get('content')`, and EN from `tinymce.get('content_en')` or the `#content_en` textarea when the editor hasn't started. Read the title, title_en, slug and slug_en inputs.
    - Debounce 1000 ms on TinyMCE `input change` and on input events, only while the panel is open.
    - Use `seq` to drop stale responses.
    - Set `socStatus` to "Прегледът е обновен" after each successful refresh.
    - On failure keep the old preview and show `socError` with a retry button.
  - **Empty states:** an empty EN body shows the LinkedIn empty-EN text with the existing translate button. An empty article shows "Започнете да пишете…".
  - **Toggle:** flips `aria-expanded`, shows or hides the panel, remembers its state, refreshes on open, and returns focus to the toggle on close.
- [ ] **Step 4: Check by hand in the dev server** (preview_start). Check that:
  - the toggle opens and closes the panel and remembers it across a reload;
  - typing updates the previews after about a second;
  - a new, unsaved article shows previews;
  - the panel stacks below the article at 375px;
  - keyboard-only use reaches every control.
- [ ] **Step 5: Run the full suite** (`TranslateButtonCoverageTest`, `EditModeCoverageTest`, `ModulesGatingTest` and `ModulesOffHttpTest` must stay green; update `ModulesOffHttpTest` only if it asserted the old tab link). Then commit with the message `feat(articles): Socials panel beside the editor with live post previews`.

---

### Task 6: Schedule from the panel — save, publish, notices, emoji

**Files:**
- Modify: `admin/js/social-panel.js`, `admin/article-edit.php` (the panel markup and emoji picker block, roughly lines 541–560 and 1652–1680)
- Test: `tests/Admin/SocialPanelTest.php` (add tests)

**Interfaces:**
- Consumes: Task 3 (`_ajax=1`, `publish_now=1`, JSON `{ok, slug, slug_en, status, warnings}`), Task 4 (the schedule actions without client text), and Task 5 (`window.SocialPanel`).
- Produces: `SocialPanel.saveThenSchedule(channel: 'fb'|'insta'|'linkedin', body: object): Promise`.

- [ ] **Step 1: Write the failing tests:**
  - `testScheduleUsesSlugFromSaveResponse`: `social-panel.js` sends `_ajax` and `publish_now` and reads `.slug` from the save reply before calling `social-ajax.php` or `linkedin-ajax.php`.
  - `testScheduleBodySendsNoText`: the JS doesn't send `social_text` or `linkedin_text`.
  - `testDraftNoticeCopy`: the exact draft notice string from Global Constraints.
  - `testEmojiInsertsIntoArticleEditor`: the emoji handler calls `tinymce.get(` and `insertContent`.
  - `testNoWindowConfirm`: `social-panel.js` doesn't contain `confirm(`.
- [ ] **Step 2: Run them and confirm they fail.**
- [ ] **Step 3: Implement `saveThenSchedule`.**
  - Do `tinymce.triggerSave()`, then build `FormData(articleForm)` plus `_ajax=1` and `publish_now=1`, and POST to the form action.
  - If `!ok`, show the error in the platform's visible error area and stop.
  - Otherwise:
    - update `slug` and `slug_en` in the form inputs;
    - set the status `<select>` to `published` and uncheck "Публикувай автоматично";
    - `history.replaceState` to `?slug=<new>`;
    - show any `warnings`;
    - call the schedule endpoint with `{action:'schedule', slug: newSlug, scheduled_at, channel, post_type}` and the CSRF token;
    - update the status line as today.
  - **Draft notice:** show it next to every schedule button whenever the status select isn't `published`, and keep it current on `change`.
  - **Warning for a draft with scheduled posts:** if the status select is set to `draft` and any `*_scheduled_at` exists (pass booleans to the page as `data-` attributes), show a visible warning that the posts are not cancelled. Write that wording in the spec's plain style and add it to `testCopyStrings`.
  - **Emoji picker:** keep the existing picker markup and its keyboard handling. Its targets become `content` (FB/IG) and `content_en` (LinkedIn). Inserting calls `tinymce.get(target).insertContent(emoji)`, starting the EN editor first through `_expandEnSection()` if needed, then triggers a refresh.
- [ ] **Step 4: Check by hand in the dev server**, using a Buffer test channel or with the network call stubbed in devtools. Check that:
  - scheduling a draft publishes it (the status select shows Публикувана and the JSON on disk is published);
  - a changed title produces the new link in the preview and in the request;
  - an emoji appears in the article and in the preview.
- [ ] **Step 5: Run the full suite, then commit** with the message `feat(social): scheduling saves and publishes the article; emoji go into the article`.

---

### Task 7: Port to oddminds

**Files:** the same set in `~/Code/oddminds`, in its own worktree, per CLAUDE.md.

- [ ] **Step 1:** After ngo-cms is pushed (only when the author says "push"), create a worktree in oddminds off `origin/main`. Diff oddminds' `admin/article-edit.php`, `admin/social-ajax.php`, `admin/linkedin-ajax.php` and `includes/articles.php` against the ngo-cms versions from before this branch, to find oddminds-only differences such as Odd Minds copy and the Lafetki subdomain.
- [ ] **Step 2:** Port the commits from Tasks 1–6 by hand, keeping oddminds-only copy. Run `php vendor/bin/phpunit` there.
- [ ] **Step 3:** Commit with the trailer `Ported: ngo-cms <sha>` for each ported commit. Commit locally only.
- [ ] **Step 4:** When the next ngo-cms release is tagged, its release notes must say that social posts now mirror the article and that AI-written posts are gone. There's no changelog file, so pass this note to whoever tags the release.

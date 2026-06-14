# FB Post Generator — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a branded "Генерирай FB пост" button to the article editor that generates a teaser post linking to the BG article URL, and split the shared FB/Instagram textarea into two separate fields.

**Architecture:** Two PHP files change — `social-ajax.php` gains a `generate_type` param and a new branded prompt, and `article-edit.php` gets updated data preservation plus a reworked social panel UI with split textareas, two FB generate buttons, and a client-side copy-to-Instagram strip-links button.

**Tech Stack:** PHP 8.4, vanilla JS, Claude API (claude-sonnet-4-6), PHPUnit 13.

---

## File Map

| File | Change |
|---|---|
| `admin/social-ajax.php` | `generate` action: add `generate_type` param + new branded prompt; `schedule` action: save to `fb_text` / `insta_text` based on channel |
| `admin/article-edit.php` | POST handler: preserve `fb_text` + `insta_text`; social panel UI: split textareas, two generate buttons, copy-from-FB, split datetimes, updated JS |
| `tests/Admin/SocialAjaxTest.php` | New: source-level checks for `generate_type` handling and branded prompt presence |

---

## Task 1: Write failing tests

**Files:**
- Create: `tests/Admin/SocialAjaxTest.php`

- [ ] **Step 1: Create the test file**

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('admin')]
final class SocialAjaxTest extends TestCase
{
    private string $socialAjax;
    private string $articleEdit;

    protected function setUp(): void
    {
        $this->socialAjax  = $_SERVER['DOCUMENT_ROOT'] . '/admin/social-ajax.php';
        $this->articleEdit = $_SERVER['DOCUMENT_ROOT'] . '/admin/article-edit.php';
    }

    public function testGenerateActionHandlesGenerateType(): void
    {
        $src = file_get_contents($this->socialAjax);
        $this->assertStringContainsString(
            "generate_type",
            $src,
            "social-ajax.php generate action must read a 'generate_type' param"
        );
    }

    public function testGenerateActionHasBrandedPrompt(): void
    {
        $src = file_get_contents($this->socialAjax);
        // Key phrases from the branded FB post skill
        $this->assertStringContainsString(
            'Warm, honest',
            $src,
            "Branded prompt must include voice instructions"
        );
        $this->assertStringContainsString(
            'Hook',
            $src,
            "Branded prompt must include structural instructions"
        );
        $this->assertStringContainsString(
            'устойчивост',
            $src,
            "Branded prompt must include DO NOT jargon list"
        );
    }

    public function testGenerateActionSavesToFbText(): void
    {
        $src = file_get_contents($this->socialAjax);
        $this->assertStringContainsString(
            "'fb_text'",
            $src,
            "generate action must save to fb_text field"
        );
    }

    public function testScheduleActionSavesChannelSpecificField(): void
    {
        $src = file_get_contents($this->socialAjax);
        $this->assertStringContainsString(
            "'insta_text'",
            $src,
            "schedule action must save to insta_text for Instagram channel"
        );
    }

    public function testArticleEditPreservesFbText(): void
    {
        $src = file_get_contents($this->articleEdit);
        $this->assertStringContainsString(
            "'fb_text'",
            $src,
            "article-edit.php POST handler must preserve fb_text"
        );
        $this->assertStringContainsString(
            "'insta_text'",
            $src,
            "article-edit.php POST handler must preserve insta_text"
        );
    }

    public function testArticleEditHasTwoGenerateButtons(): void
    {
        $src = file_get_contents($this->articleEdit);
        $this->assertStringContainsString(
            'socGenerateNewsBtn',
            $src,
            "article-edit.php must have a Генерирай FB новина button"
        );
        $this->assertStringContainsString(
            'socGeneratePostBtn',
            $src,
            "article-edit.php must have a Генерирай FB пост button"
        );
    }

    public function testArticleEditHasSplitTextareas(): void
    {
        $src = file_get_contents($this->articleEdit);
        $this->assertStringContainsString('id="fbText"',    $src, "FB textarea must have id=fbText");
        $this->assertStringContainsString('id="instaText"', $src, "Instagram textarea must have id=instaText");
        $this->assertStringNotContainsString(
            'id="socText"',
            $src,
            "Old shared id=socText must be removed"
        );
    }
}
```

- [ ] **Step 2: Run tests to confirm they all fail**

```bash
php vendor/bin/phpunit tests/Admin/SocialAjaxTest.php --testdox
```

Expected: all 7 tests FAIL (the changes haven't been made yet).

- [ ] **Step 3: Commit the failing tests**

```bash
git add tests/Admin/SocialAjaxTest.php
git commit -m "test: add failing tests for FB post generator split"
```

---

## Task 2: Backend — social-ajax.php generate action

**Files:**
- Modify: `admin/social-ajax.php` lines 56–139 (the `generate` block)

- [ ] **Step 1: Replace the generate block**

In `admin/social-ajax.php`, replace the entire `if ($action === 'generate') { ... }` block (lines 56–140) with:

```php
// ── Generate ──────────────────────────────────────────────────────────────────
if ($action === 'generate') {
    $api_key = setting_get('claude_api_key');
    if ($api_key === '') {
        echo json_encode(['ok' => false, 'error' => 'Claude API key not configured (Плащания → Claude).']);
        exit;
    }

    $generate_type = $body['generate_type'] ?? 'news';
    $article = load_json($file);
    $title   = $article['title']   ?? '';
    $content = mb_substr(strip_tags($article['content'] ?? ''), 0, 3000);

    if ($generate_type === 'post') {
        $article_url = rtrim(SITE_URL, '/') . '/novini/' . rawurlencode($slug) . '/';
        $prompt = "You write Facebook posts for Фондация Различни умове (Odd Minds Foundation) — a Bulgarian NGO supporting children with developmental differences and ones growing up without parental care.

VOICE: Warm, honest, occasionally dry/funny. Team voice (\"ние\"), never corporate. Story-first. Emotion through specific detail, not adjectives.

STRUCTURE: Hook → context/story → what the article covers → link. CTA at the end only.

FORMATTING RULES:
- Bulgarian only
- 100–200 words
- Short paragraphs, generous white space
- 1–2 emojis max, never mid-sentence
- No dashes or em-dashes. Use full stops instead
- No hashtags unless explicitly requested
- No exclamation marks more than once per post
- End with: 👉 {$article_url}

DO NOT:
- Start with \"С радост съобщаваме\", \"Какво става когато\" or \"С удоволствие споделяме\"
- Use NGO jargon: устойчивост, въздействие, екосистема, уязвими групи
- Use dashes to connect clauses (reads as AI-generated)
- Over-explain the foundation's mission
- Invent overly specific illustrative examples

Write a Facebook post for this article.
Title: {$title}
Content: {$content}

Return only the post text. No explanations, no alternatives.";
    } else {
        $prompt = "Напиши публикация за Facebook и Instagram за Фондация Различни Умове - българска НПО, която подкрепя деца с различия в развитието и деца, лишени от родителска грижа. Фондацията също управлява Лафетки - социално предприятие, произвеждащо салфетки-разговорници, пакетирани от младежи от резиденции.

Тон: топъл, честен, близък. Не корпоративен. Истински истории, реален ефект. Подходящ за широката аудитория.

На базата на тази статия:
Заглавие: {$title}
Съдържание: {$content}

Напиши публикацията на БЪЛГАРСКИ. Насоки:
- 2–4 кратки абзаца
- Започни с нещо, което привлича внимание — въпрос, факт или момент
- Топло и автентично, подходящо за родители, доброволци, дарители
- Завърши с призив за действие или размисъл
- 3–5 хаштага на Bulgarian (напр. #РазличниУмове #НПО #Дарение)
- Обикновен текст, без markdown форматиране

Върни само текста на публикацията, нищо друго.";
    }

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 500,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        _om_log('ERROR', 'social-ajax generate: cURL: ' . $curl_err);
        echo json_encode(['ok' => false, 'error' => 'API connection failed.']);
        exit;
    }
    if ($http_code !== 200) {
        $d = json_decode((string)$response, true);
        _om_log('ERROR', "social-ajax generate: HTTP {$http_code}: " . ($d['error']['message'] ?? ''));
        echo json_encode(['ok' => false, 'error' => 'Claude API error (HTTP ' . $http_code . ').']);
        exit;
    }

    $d    = json_decode((string)$response, true);
    $text = trim($d['content'][0]['text'] ?? '');

    if ($text === '') {
        _om_log('ERROR', 'social-ajax generate: empty response from Claude');
        echo json_encode(['ok' => false, 'error' => 'Empty response from Claude.']);
        exit;
    }

    $data = load_json($file);
    $data['fb_text']    = $text;
    $data['social_text'] = $text; // backward compat
    if (!save_json($file, $data)) {
        _om_log('ERROR', "social-ajax generate: failed to save {$file}");
        echo json_encode(['ok' => false, 'error' => 'Текстът е генериран, но грешка при запис на файла.']);
        exit;
    }

    echo json_encode(['ok' => true, 'text' => $text]);
    exit;
}
```

- [ ] **Step 2: Run the generate-specific tests**

```bash
php vendor/bin/phpunit tests/Admin/SocialAjaxTest.php --filter testGenerateAction --testdox
```

Expected: `testGenerateActionHandlesGenerateType`, `testGenerateActionHasBrandedPrompt`, `testGenerateActionSavesToFbText` all PASS.

- [ ] **Step 3: Commit**

```bash
git add admin/social-ajax.php
git commit -m "feat: add generate_type param and branded FB post prompt to social-ajax"
```

---

## Task 3: Backend — social-ajax.php schedule saves to correct field

**Files:**
- Modify: `admin/social-ajax.php` — schedule action, two save locations

- [ ] **Step 1: Update the "Updated successfully" save block**

Find this block in the `schedule` action (inside the `$old_buffer_post_id !== ''` branch):

```php
            $data['social_text'] = $social_text;
            $data[$sched_key]    = $scheduled_at;
            $data[$due_key]      = $due_at;
```

Replace with:

```php
            $data['social_text'] = $social_text;
            if ($channel === 'fb') {
                $data['fb_text'] = $social_text;
            } else {
                $data['insta_text'] = $social_text;
            }
            $data[$sched_key]    = $scheduled_at;
            $data[$due_key]      = $due_at;
```

- [ ] **Step 2: Update the "Create fresh post" save block**

Find this block near the end of the schedule action:

```php
        $data['social_text']  = $social_text;
        $data[$post_id_key]   = $buffer_post_id;
        $data[$sched_key]     = $scheduled_at;
        $data[$due_key]       = $due_at;
```

Replace with:

```php
        $data['social_text']  = $social_text;
        if ($channel === 'fb') {
            $data['fb_text'] = $social_text;
        } else {
            $data['insta_text'] = $social_text;
        }
        $data[$post_id_key]   = $buffer_post_id;
        $data[$sched_key]     = $scheduled_at;
        $data[$due_key]       = $due_at;
```

- [ ] **Step 3: Run the schedule test**

```bash
php vendor/bin/phpunit tests/Admin/SocialAjaxTest.php --filter testScheduleActionSavesChannelSpecificField --testdox
```

Expected: PASS.

- [ ] **Step 4: Run full test suite to check no regressions**

```bash
php vendor/bin/phpunit
```

Expected: all previously passing tests still pass.

- [ ] **Step 5: Commit**

```bash
git add admin/social-ajax.php
git commit -m "feat: schedule action saves to fb_text/insta_text per channel"
```

---

## Task 4: Data layer — article-edit.php POST handler

**Files:**
- Modify: `admin/article-edit.php` lines 122–143 (the `$data = [...]` array)

- [ ] **Step 1: Add fb_text and insta_text to the save array**

Find the `$data = [` array in the POST handler. It currently ends with:

```php
            'social_text'          => $article['social_text']          ?? '',
            'fb_buffer_post_id'    => $article['fb_buffer_post_id']    ?? '',
```

Add two lines immediately after `'social_text'`:

```php
            'social_text'          => $article['social_text']          ?? '',
            'fb_text'              => $article['fb_text']              ?? '',
            'insta_text'           => $article['insta_text']           ?? '',
            'fb_buffer_post_id'    => $article['fb_buffer_post_id']    ?? '',
```

- [ ] **Step 2: Run the preservation test**

```bash
php vendor/bin/phpunit tests/Admin/SocialAjaxTest.php --filter testArticleEditPreservesFbText --testdox
```

Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add admin/article-edit.php
git commit -m "feat: preserve fb_text and insta_text in article-edit POST handler"
```

---

## Task 5: UI — split social panel in article-edit.php

This task replaces the entire social panel HTML and JS block. It is the largest change.

**Files:**
- Modify: `admin/article-edit.php` — the `<!-- ── FB / Insta panel ──` block and its `<script>` section

### 5a — HTML: replace the social panel

- [ ] **Step 1: Replace the panel HTML**

Find the comment `<!-- ── FB / Insta panel ───` and replace the entire `<div id="socialPanel">…</div>` block with:

```php
<!-- ── FB / Insta panel ───────────────────────────────────────────────────── -->
<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;margin-top:2rem;" id="socialPanel">
  <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem;flex-wrap:wrap;">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="#1877f2" style="flex-shrink:0;"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.236 2.686.236v2.97h-1.513c-1.491 0-1.956.93-1.956 1.886v2.267h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
    <svg width="18" height="18" viewBox="0 0 24 24" fill="url(#ig)" style="flex-shrink:0;">
      <defs><linearGradient id="ig" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" stop-color="#f09433"/><stop offset="25%" stop-color="#e6683c"/><stop offset="50%" stop-color="#dc2743"/><stop offset="75%" stop-color="#cc2366"/><stop offset="100%" stop-color="#bc1888"/></linearGradient></defs>
      <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/>
    </svg>
    <h2 style="font-size:.95rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin:0;">Facebook / Instagram</h2>
    <span style="margin-left:auto;font-size:.82rem;font-weight:600;display:flex;gap:.75rem;flex-wrap:wrap;">
      <span id="fbStatusBadge"><?php if ($fb_sched): ?><span style="color:#b45309;">⏱ FB: <?= h(date('d.m.Y H:i', strtotime($article['fb_scheduled_at']))) ?></span><?php endif; ?></span>
      <span id="instaStatusBadge"><?php if ($insta_sched): ?><span style="color:#b45309;">⏱ IG: <?= h(date('d.m.Y H:i', strtotime($article['insta_scheduled_at']))) ?></span><?php endif; ?></span>
    </span>
  </div>

  <!-- ── Facebook ──────────────────────────────────────────────────────────── -->
  <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.85rem;flex-wrap:wrap;">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="#1877f2"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.236 2.686.236v2.97h-1.513c-1.491 0-1.956.93-1.956 1.886v2.267h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
    <span style="font-size:.82rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;">Facebook</span>
  </div>

  <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">
    <button type="button" id="socGenerateNewsBtn" class="btn btn--outline" style="font-size:.82rem;">
      ✦ Генерирай FB новина
    </button>
    <button type="button" id="socGeneratePostBtn" class="btn btn--outline" style="font-size:.82rem;">
      ✦ Генерирай FB пост
    </button>
    <span id="socSpinner" style="display:none;font-size:.82rem;color:var(--text-muted);">Генерира се…</span>
    <span id="socError"   style="display:none;font-size:.82rem;color:#c0392b;"></span>
  </div>

  <div class="form-group" style="margin-bottom:1rem;">
    <label style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;flex-wrap:wrap;">
      <span style="font-size:.85rem;font-weight:600;">Текст за Facebook</span>
      <span style="display:flex;gap:.4rem;">
        <button type="button" id="socEmojiBtn" class="btn btn--outline" style="font-size:.75rem;padding:.25rem .6rem;" onclick="_toggleEmojiPicker()">😊 Емоджи</button>
        <button type="button" id="socCopyBtn" class="btn btn--outline" style="font-size:.75rem;padding:.25rem .6rem;">Копирай</button>
      </span>
    </label>
    <div id="socEmojiPicker" style="display:none;background:#fff;border:1px solid var(--border);border-radius:8px;padding:.6rem;margin-bottom:.5rem;line-height:1.8;font-size:1.2rem;max-height:160px;overflow-y:auto;">
      <?php
      $emojis = ['😀','😃','😄','😁','😆','😅','🤣','😂','🙂','🙃','😉','😊','😇','🥰','😍','🤩','😘','😗','😚','😙',
                 '🥲','😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔','🤐','🤨','😐','😑','😶','😏','😒','🙄','😬',
                 '🤥','😌','😔','😪','🤤','😴','😷','🤒','🤕','🤢','🤧','🥵','🥶','🥴','😵','🤯','🤠','🥳','🥸','😎',
                 '🤓','🧐','😕','😟','🙁','☹️','😮','😯','😲','😳','🥺','😦','😧','😨','😰','😥','😢','😭','😱','😖',
                 '😣','😞','😓','😩','😫','🥱','😤','😡','😠','🤬','😈','👿','💀','☠️','💩','🤡','👹','👺','👻','👽',
                 '👾','🤖','😺','😸','😹','😻','😼','😽','🙀','😿','😾',
                 '👋','🤚','🖐️','✋','🖖','👌','🤌','🤏','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','🖕','👇','☝️','👍',
                 '👎','✊','👊','🤛','🤜','👏','🙌','👐','🤲','🤝','🙏','💪','🦾','🦿','🦵','🦶','👂','🦻','👃',
                 '❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💟','☮️',
                 '✨','🌟','⭐','💫','🔥','🎉','🎊','🎈','🎁','🏆','🥇','🌈','☀️','🌙','⚡','❄️','🌺','🌸','🌻','🌹',
                 '🙏','🌍','🌎','🌏','💡','📢','📣','🔔','💬','💭','🗣️','👥','🫶','💪','🚀','🛡️','🔑','🌱','🤝'];
      foreach ($emojis as $e) {
          echo '<span onclick="_insertEmoji(\'' . $e . '\')" style="cursor:pointer;padding:.1rem .15rem;border-radius:4px;display:inline-block;" title="' . $e . '">' . $e . '</span>';
      }
      ?>
    </div>
    <textarea id="fbText" rows="7"
              style="width:100%;font-size:.88rem;line-height:1.65;resize:vertical;"><?php
      if (!empty($article['fb_text'])) {
          echo h($article['fb_text']);
      } elseif (!empty($article['social_text'])) {
          echo h($article['social_text']);
      } else {
          $_soc_body = _html_to_social_text($article['content'] ?? '');
          echo h(trim(($article['title'] ?? '') . ($_soc_body ? "\n\n" . $_soc_body : '')));
      }
    ?></textarea>
    <div id="fbCounter" style="display:flex;gap:1.25rem;margin-top:.3rem;font-size:.78rem;font-variant-numeric:tabular-nums;"></div>
  </div>

  <div style="margin-bottom:.75rem;">
    <label for="fbScheduleAt" style="font-size:.85rem;font-weight:600;display:block;margin-bottom:.3rem;">Планирай за</label>
    <input type="datetime-local" id="fbScheduleAt"
           value="<?= h(!empty($article['fb_scheduled_at']) ? date('Y-m-d\TH:i', strtotime($article['fb_scheduled_at'])) : $default_social_at) ?>"
           style="font-size:.88rem;">
  </div>
  <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.5rem;">
    <button type="button" id="socFbBtn" class="btn btn--primary" style="font-size:.85rem;white-space:nowrap;background:#1877f2;border-color:#1877f2;">
      Планирай в Facebook
    </button>
    <button type="button" id="socFbNowBtn" class="btn btn--outline" style="font-size:.85rem;white-space:nowrap;">
      Публикувай сега (FB)
    </button>
    <span id="socFbSpinner" style="display:none;font-size:.85rem;color:var(--text-muted);">Изпраща се…</span>
    <span id="socFbError"   style="display:none;font-size:.85rem;color:#c0392b;"></span>
  </div>
  <p style="margin:.25rem 0 1.25rem;font-size:.78rem;color:var(--text-muted);">
    Публикацията ще бъде планирана в Buffer. Редактирайте я в <a href="https://publish.buffer.com" target="_blank" rel="noopener">publish.buffer.com</a>.
  </p>

  <!-- ── Divider ────────────────────────────────────────────────────────────── -->
  <hr style="border:none;border-top:1px solid var(--border);margin:1.25rem 0;">

  <!-- ── Instagram ─────────────────────────────────────────────────────────── -->
  <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.85rem;">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="url(#ig2)" style="flex-shrink:0;">
      <defs><linearGradient id="ig2" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" stop-color="#f09433"/><stop offset="25%" stop-color="#e6683c"/><stop offset="50%" stop-color="#dc2743"/><stop offset="75%" stop-color="#cc2366"/><stop offset="100%" stop-color="#bc1888"/></linearGradient></defs>
      <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/>
    </svg>
    <span style="font-size:.82rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;">Instagram</span>
  </div>

  <div class="form-group" style="margin-bottom:1rem;">
    <label style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;flex-wrap:wrap;">
      <span style="font-size:.85rem;font-weight:600;">Текст за Instagram</span>
      <span style="display:flex;gap:.4rem;">
        <button type="button" id="socCopyFromFbBtn" class="btn btn--outline" style="font-size:.75rem;padding:.25rem .6rem;">📋 Копирай от FB</button>
        <button type="button" id="instaCopyBtn" class="btn btn--outline" style="font-size:.75rem;padding:.25rem .6rem;">Копирай</button>
      </span>
    </label>
    <textarea id="instaText" rows="7"
              style="width:100%;font-size:.88rem;line-height:1.65;resize:vertical;"><?php
      if (!empty($article['insta_text'])) {
          echo h($article['insta_text']);
      }
    ?></textarea>
    <div id="instaCounter" style="display:flex;gap:1.25rem;margin-top:.3rem;font-size:.78rem;font-variant-numeric:tabular-nums;"></div>
  </div>

  <div style="margin-bottom:.75rem;">
    <label for="instaScheduleAt" style="font-size:.85rem;font-weight:600;display:block;margin-bottom:.3rem;">Планирай за</label>
    <input type="datetime-local" id="instaScheduleAt"
           value="<?= h(!empty($article['insta_scheduled_at']) ? date('Y-m-d\TH:i', strtotime($article['insta_scheduled_at'])) : $default_social_at) ?>"
           style="font-size:.88rem;">
  </div>
  <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.5rem;">
    <button type="button" id="socInstaBtn" class="btn btn--primary" style="font-size:.85rem;white-space:nowrap;background:linear-gradient(135deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888);border:none;">
      Планирай в Instagram
    </button>
    <button type="button" id="socInstaNowBtn" class="btn btn--outline" style="font-size:.85rem;white-space:nowrap;">
      Публикувай сега (IG)
    </button>
    <span id="socInstaSpinner" style="display:none;font-size:.85rem;color:var(--text-muted);">Изпраща се…</span>
    <span id="socInstaError"   style="display:none;font-size:.85rem;color:#c0392b;"></span>
  </div>
  <p style="margin:.25rem 0 0;font-size:.78rem;color:var(--text-muted);">
    Публикацията ще бъде планирана в Buffer. Редактирайте я в <a href="https://publish.buffer.com" target="_blank" rel="noopener">publish.buffer.com</a>.
  </p>
</div>
```

### 5b — JS: replace the social panel script block

- [ ] **Step 2: Replace the Facebook / Instagram JS block**

Find the comment `// ── Facebook / Instagram panel` inside the `<script>` tag and replace the entire IIFE (from `(function () {` through the matching `})();`) plus the character counters block at the bottom with:

```javascript
// ── Facebook / Instagram panel ─────────────────────────────────────────────────
(function () {
  var generateNewsBtn = document.getElementById('socGenerateNewsBtn');
  if (!generateNewsBtn) return;

  var fbText   = document.getElementById('fbText');
  var instaText = document.getElementById('instaText');
  var slug     = '<?= h($edit_slug) ?>';

  async function socPost(body) {
    var r = await fetch('/admin/social-ajax.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(Object.assign({ csrf_token: '<?= csrf_token() ?>', slug: slug }, body))
    });
    return r.json();
  }

  function fmtSofia(due_at) {
    return new Date(due_at).toLocaleString('bg-BG', {
      timeZone: 'Europe/Sofia', day:'2-digit', month:'2-digit', year:'numeric',
      hour:'2-digit', minute:'2-digit'
    });
  }

  function _fbGetText() {
    var text = fbText.value.trim();
    if (text) return text;
    var title   = (document.getElementById('title') || {}).value || '';
    var rawHtml = _tinyGet('content');
    var tmp = document.createElement('div'); tmp.innerHTML = rawHtml;
    var bodyText = (tmp.textContent || tmp.innerText || '').trim();
    return title.trim() + (bodyText ? '\n\n' + bodyText : '');
  }

  function _instaGetText() {
    return instaText.value.trim();
  }

  // Generate (shared handler for both buttons)
  function _generate(generateType) {
    var spinner = document.getElementById('socSpinner');
    var errEl   = document.getElementById('socError');
    generateNewsBtn.disabled = true;
    document.getElementById('socGeneratePostBtn').disabled = true;
    spinner.style.display = '';
    errEl.style.display   = 'none';
    socPost({ action: 'generate', generate_type: generateType })
      .then(function(data) {
        if (!data.ok) {
          errEl.textContent   = data.error;
          errEl.style.display = '';
        } else {
          fbText.value = data.text;
          fbText.dispatchEvent(new Event('input'));
        }
      })
      .catch(function() {
        errEl.textContent   = 'Грешка при свързване.';
        errEl.style.display = '';
      })
      .finally(function() {
        generateNewsBtn.disabled = false;
        document.getElementById('socGeneratePostBtn').disabled = false;
        spinner.style.display = 'none';
      });
  }

  generateNewsBtn.addEventListener('click', function() { _generate('news'); });
  document.getElementById('socGeneratePostBtn').addEventListener('click', function() { _generate('post'); });

  // Copy FB text
  var copyBtn = document.getElementById('socCopyBtn');
  if (copyBtn) {
    copyBtn.addEventListener('click', function() {
      navigator.clipboard.writeText(fbText.value).then(function() {
        var orig = copyBtn.textContent;
        copyBtn.textContent = '✓ Копирано';
        setTimeout(function() { copyBtn.textContent = orig; }, 1500);
      }).catch(function() { fbText.select(); document.execCommand('copy'); });
    });
  }

  // Copy Instagram text
  var instaCopyBtn = document.getElementById('instaCopyBtn');
  if (instaCopyBtn) {
    instaCopyBtn.addEventListener('click', function() {
      navigator.clipboard.writeText(instaText.value).then(function() {
        var orig = instaCopyBtn.textContent;
        instaCopyBtn.textContent = '✓ Копирано';
        setTimeout(function() { instaCopyBtn.textContent = orig; }, 1500);
      }).catch(function() { instaText.select(); document.execCommand('copy'); });
    });
  }

  // Copy from FB → Instagram, stripping links
  var copyFromFbBtn = document.getElementById('socCopyFromFbBtn');
  if (copyFromFbBtn) {
    copyFromFbBtn.addEventListener('click', function() {
      var val = fbText.value.trim();
      if (!val) return;
      var lines = val.split('\n');
      var stripped = lines
        .filter(function(line) {
          var t = line.trim();
          if (/^👉/.test(t)) return false;
          if (/^https?:\/\/\S+$/.test(t)) return false;
          return true;
        })
        .join('\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
      instaText.value = stripped;
      instaText.dispatchEvent(new Event('input'));
    });
  }

  function _toggleEmojiPicker() {
    var p = document.getElementById('socEmojiPicker');
    p.style.display = p.style.display === 'none' ? 'block' : 'none';
  }

  function _insertEmoji(emoji) {
    var ta    = fbText;
    var start = ta.selectionStart;
    var end   = ta.selectionEnd;
    ta.value  = ta.value.slice(0, start) + emoji + ta.value.slice(end);
    ta.selectionStart = ta.selectionEnd = start + emoji.length;
    ta.focus();
    ta.dispatchEvent(new Event('input'));
  }

  // Schedule / publish-now helper
  async function scheduleChannel(channel, scheduled_at, btn, spinner, errEl, badgeId) {
    var text = channel === 'fb' ? _fbGetText() : _instaGetText();
    btn.disabled          = true;
    spinner.style.display = '';
    errEl.style.display   = 'none';
    try {
      var data = await socPost({ action: 'schedule', social_text: text, scheduled_at: scheduled_at, channel: channel });
      if (!data.ok) {
        errEl.textContent   = data.error;
        errEl.style.display = '';
      } else {
        var label = channel === 'fb' ? 'FB' : 'IG';
        if (data.due_at) {
          document.getElementById(badgeId).innerHTML =
            '<span style="color:#b45309;">⏱ ' + label + ': ' + fmtSofia(data.due_at) + '</span>';
        } else {
          document.getElementById(badgeId).innerHTML =
            '<span style="color:#2e7d32;">✓ ' + label + ': Публикувано сега</span>';
        }
      }
    } catch(e) {
      errEl.textContent   = 'Грешка при свързване.';
      errEl.style.display = '';
    }
    btn.disabled          = false;
    spinner.style.display = 'none';
  }

  var fbBtn = document.getElementById('socFbBtn');
  if (fbBtn) {
    fbBtn.addEventListener('click', function() {
      var schedAt = document.getElementById('fbScheduleAt').value;
      var errEl   = document.getElementById('socFbError');
      if (!schedAt) { errEl.textContent = 'Изберете дата и час.'; errEl.style.display = ''; return; }
      scheduleChannel('fb', schedAt, fbBtn,
        document.getElementById('socFbSpinner'), errEl, 'fbStatusBadge');
    });
  }

  var fbNowBtn = document.getElementById('socFbNowBtn');
  if (fbNowBtn) {
    fbNowBtn.addEventListener('click', function() {
      scheduleChannel('fb', 'now', fbNowBtn,
        document.getElementById('socFbSpinner'),
        document.getElementById('socFbError'),
        'fbStatusBadge');
    });
  }

  var instaBtn = document.getElementById('socInstaBtn');
  if (instaBtn) {
    instaBtn.addEventListener('click', function() {
      var schedAt = document.getElementById('instaScheduleAt').value;
      var errEl   = document.getElementById('socInstaError');
      if (!schedAt) { errEl.textContent = 'Изберете дата и час.'; errEl.style.display = ''; return; }
      scheduleChannel('insta', schedAt, instaBtn,
        document.getElementById('socInstaSpinner'), errEl, 'instaStatusBadge');
    });
  }

  var instaNowBtn = document.getElementById('socInstaNowBtn');
  if (instaNowBtn) {
    instaNowBtn.addEventListener('click', function() {
      scheduleChannel('insta', 'now', instaNowBtn,
        document.getElementById('socInstaSpinner'),
        document.getElementById('socInstaError'),
        'instaStatusBadge');
    });
  }
})();

// ── Character counters ─────────────────────────────────────────────────────────
(function () {
  var LIMITS = { ig: 2200, fb: 63206 };

  function counterSpan(label, count, limit) {
    var over   = count > limit;
    var warn   = count > limit * 0.9;
    var color  = over ? '#c0392b' : warn ? '#b45309' : '#6b7280';
    var weight = (over || warn) ? '600' : '400';
    return '<span style="color:' + color + ';font-weight:' + weight + ';">'
         + label + ': ' + count.toLocaleString('bg-BG') + ' / ' + limit.toLocaleString('bg-BG')
         + (over ? ' ✕' : '')
         + '</span>';
  }

  var fbEl = document.getElementById('fbText');
  var fbOut = document.getElementById('fbCounter');
  if (fbEl && fbOut) {
    fbEl.addEventListener('input', function() {
      fbOut.innerHTML = counterSpan('Facebook', fbEl.value.length, LIMITS.fb);
    });
    fbOut.innerHTML = counterSpan('Facebook', fbEl.value.length, LIMITS.fb);
  }

  var igEl = document.getElementById('instaText');
  var igOut = document.getElementById('instaCounter');
  if (igEl && igOut) {
    igEl.addEventListener('input', function() {
      igOut.innerHTML = counterSpan('Instagram', igEl.value.length, LIMITS.ig);
    });
    igOut.innerHTML = counterSpan('Instagram', igEl.value.length, LIMITS.ig);
  }
})();
```

- [ ] **Step 3: Run all new tests**

```bash
php vendor/bin/phpunit tests/Admin/SocialAjaxTest.php --testdox
```

Expected: all 7 tests PASS.

- [ ] **Step 4: Run full test suite**

```bash
php vendor/bin/phpunit
```

Expected: all previously passing tests still pass.

- [ ] **Step 5: Commit**

```bash
git add admin/article-edit.php
git commit -m "feat: split FB/Instagram textareas, add Генерирай FB пост button and copy-from-FB"
```

---

## Task 6: Manual verification

- [ ] **Step 1: Open an existing article in the editor**

Navigate to `/admin/article-edit.php?slug=<any-published-slug>` and verify:

- The social panel shows two separate sections: **Facebook** and **Instagram**, separated by a divider.
- Two generate buttons appear: "✦ Генерирай FB новина" and "✦ Генерирай FB пост".
- The FB textarea pre-fills from `fb_text` (or falls back to `social_text` for existing articles).
- The Instagram textarea is empty for existing articles (no `insta_text` yet).

- [ ] **Step 2: Test "Генерирай FB пост"**

Click "✦ Генерирай FB пост". Verify:

- The generated text appears in the **FB textarea** (not Instagram).
- The text is 100–200 words, in Bulgarian, with short paragraphs.
- The text ends with `👉 https://oddminds.org/novini/<slug>/` (the actual article URL).
- No dashes/em-dashes used to connect clauses.
- At most 2 emojis, none mid-sentence.
- Does **not** start with "С радост съобщаваме" or "С удоволствие споделяме".

- [ ] **Step 3: Test "Генерирай FB новина"**

Click "✦ Генерирай FB новина". Verify the generated text is a standalone news post (may include hashtags, no mandatory article link at the end).

- [ ] **Step 4: Test copy-from-FB**

With FB text generated (ending with `👉 https://oddminds.org/...`), click "📋 Копирай от FB". Verify:

- The Instagram textarea fills with the same text.
- The `👉 https://oddminds.org/...` line is **absent** from the Instagram text.
- No other content is removed.

- [ ] **Step 5: Test scheduling still works**

Schedule to Facebook using the FB "Планирай за" datetime and "Планирай в Facebook" button. Verify the `fbStatusBadge` updates. Repeat for Instagram.

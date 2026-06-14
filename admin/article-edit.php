<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/translator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ai_keywords.php';

/**
 * Convert HTML to plain text, turning <strong>/<b> into Unicode bold sans-serif.
 * Used for pre-filling social textareas from article content.
 */
function _html_to_social_text(string $html): string {
    static $bold_map = null;
    if ($bold_map === null) {
        $bold_map = [];
        foreach (range('A', 'Z') as $c) $bold_map[$c] = mb_chr(0x1D5D4 + (ord($c) - ord('A')));
        foreach (range('a', 'z') as $c) $bold_map[$c] = mb_chr(0x1D5EE + (ord($c) - ord('a')));
        foreach (range('0', '9') as $c) $bold_map[$c] = mb_chr(0x1D7EC + (ord($c) - ord('0')));
    }
    // Replace <strong>/<b> content with Unicode bold
    $html = preg_replace_callback(
        '/<(?:strong|b)(?:\s[^>]*)?>(.+?)<\/(?:strong|b)>/is',
        function ($m) use ($bold_map) {
            $inner = strip_tags($m[1]);
            return strtr($inner, $bold_map);
        },
        $html
    );
    return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$slug_param = basename(str_replace(['..', "\0"], '', $_GET['slug'] ?? ''));
$lang_param = ($_GET['lang'] ?? 'bg') === 'en' ? 'en' : 'bg';
$is_new     = $slug_param === '';

// Load existing articles
$article    = [];
$article_en = [];

if (!$is_new) {
    $article        = get_article($slug_param, 'bg') ?? [];
    $slug_en_stored = $article['slug_en'] ?? $slug_param;
    $article_en     = get_article($slug_en_stored, 'en') ?? [];
}

admin_require_editorial();
$current = admin_user();
$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    // ── BG fields ──────────────────────────────────────────────────────────────
    $title   = trim($_POST['title']   ?? '');
    $content = $_POST['content']       ?? '';
    $excerpt = trim($_POST['excerpt']  ?? '');
    $author  = trim($_POST['author']   ?? $current['name'] ?? '');
    $status  = in_array($_POST['status'] ?? '', ['published','draft']) ? $_POST['status'] : 'draft';
    $date    = $_POST['date'] ?? date('Y-m-d');
    $tags    = array_values(array_filter(array_map('trim', explode(',', $_POST['tags'] ?? ''))));
    // Sanitise the user-supplied slug (allow Cyrillic — strip only path-unsafe chars).
    $new_slug = slug(str_replace(['..', "\0", '/'], '', trim($_POST['slug'] ?? '')))
             ?: slug($title)
             ?: 'article-' . date('YmdHis');

    // ── EN fields (optional) ───────────────────────────────────────────────────
    $title_en   = trim($_POST['title_en']   ?? '');
    $content_en = $_POST['content_en']       ?? '';
    $excerpt_en = trim($_POST['excerpt_en']  ?? '');
    $has_en     = $title_en !== '' || $content_en !== '';

    // EN slug: explicit override > derived from EN title > ascii of BG title
    $slug_en_input   = slug(str_replace(['..', "\0", '/'], '', trim($_POST['slug_en'] ?? '')));
    $new_slug_en     = $slug_en_input !== ''
        ? $slug_en_input
        : (($title_en !== '') ? slug($title_en) : ascii_slug($title));
    if (!$new_slug_en) $new_slug_en = $new_slug;
    // Where the EN file currently lives (may differ from BG slug after migration)
    $slug_en_current = $is_new ? null : ($article['slug_en'] ?? $slug_param);

    if (!$title) {
        $error = 'Заглавието е задължително.';
    } else {
        $image = $article['image'] ?? '';

        // Handle image upload / removal
        // New upload always takes priority; remove only clears when no new file is provided
        if (!empty($_FILES['image']['tmp_name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $allowed_mime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $ftype = mime_content_type($_FILES['image']['tmp_name']);
            if (isset($allowed_mime[$ftype])) {
                $ext     = $allowed_mime[$ftype];
                $img_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/articles/';
                if (!is_dir($img_dir)) mkdir($img_dir, 0755, true);
                $filename = ascii_slug($new_slug) . '-' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $img_dir . $filename)) {
                    $image = '/assets/images/articles/' . $filename;
                } else {
                    $error = 'Неуспешен запис на снимката. Проверете правата на директорията.';
                }
            } else {
                $error = 'Позволени са само JPEG, PNG и WebP изображения.';
            }
        } elseif (!empty($_POST['remove_image'])) {
            $image = '';
        } elseif (!empty($_POST['image_from_library'])) {
            $lib = $_POST['image_from_library'];
            if (preg_match('#^/assets/images/[a-zA-Z0-9/_.\-]+$#', $lib)) {
                $image = $lib;
            }
        } elseif (isset($_FILES['image']['error']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $codes = [
                UPLOAD_ERR_INI_SIZE  => 'Снимката е прекалено голяма (upload_max_filesize).',
                UPLOAD_ERR_FORM_SIZE => 'Снимката е прекалено голяма.',
                UPLOAD_ERR_PARTIAL   => 'Снимката е качена само частично. Опитайте отново.',
            ];
            $error = $codes[$_FILES['image']['error']] ?? 'Грешка при качване на снимката (код ' . $_FILES['image']['error'] . ').';
        }

        if ($error) {
            // Upload or validation error — stop here so the user sees the message.
        } else {

        // If editing and BG slug changed, delete old BG + EN files
        if (!$is_new && $slug_param !== $new_slug) {
            $old_bg = ARTICLES_PATH . '/bg/' . $slug_param . '.json';
            $old_en = ARTICLES_PATH . '/en/' . ($article['slug_en'] ?? $slug_param) . '.json';
            if (file_exists($old_bg)) unlink($old_bg);
            if (file_exists($old_en)) unlink($old_en);
        } elseif (!$is_new && $slug_en_current && $slug_en_current !== $new_slug_en) {
            // BG slug unchanged but EN slug changed — delete old EN file
            $old_en = ARTICLES_PATH . '/en/' . $slug_en_current . '.json';
            if (file_exists($old_en)) unlink($old_en);
        }

        // Save BG article (preserve social metadata)
        $data = [
            'title'              => $title,
            'slug'               => $new_slug,
            'slug_en'            => $new_slug_en,
            'date'               => $date,
            'author'             => $author,
            'status'             => $status,
            'excerpt'            => $excerpt,
            'image'              => $image,
            'tags'               => $tags,
            'content'            => $content,
            'linkedin_text'        => $article['linkedin_text']        ?? '',
            'linkedin_url'         => $article['linkedin_url']         ?? '',
            'linkedin_posted_at'   => $article['linkedin_posted_at']   ?? '',
            'linkedin_scheduled_at'=> $article['linkedin_scheduled_at']?? '',
            'linkedin_due_at'      => $article['linkedin_due_at']      ?? '',
            'buffer_post_id'       => $article['buffer_post_id']       ?? '',
            'social_text'          => $article['social_text']          ?? '',
            'fb_text'              => $article['fb_text']              ?? '',
            'insta_text'           => $article['insta_text']           ?? '',
            'fb_buffer_post_id'    => $article['fb_buffer_post_id']    ?? '',
            'fb_scheduled_at'      => $article['fb_scheduled_at']      ?? '',
            'fb_due_at'            => $article['fb_due_at']            ?? '',
            'insta_buffer_post_id' => $article['insta_buffer_post_id'] ?? '',
            'insta_scheduled_at'   => $article['insta_scheduled_at']   ?? '',
            'insta_due_at'         => $article['insta_due_at']         ?? '',
        ];
        $dir_bg = ARTICLES_PATH . '/bg';
        if (!is_dir($dir_bg)) mkdir($dir_bg, 0755, true);
        save_json($dir_bg . '/' . $new_slug . '.json', $data);

        // Save EN article (if any EN field provided)
        if ($has_en) {
            $data_en = [
                'title'   => $title_en ?: $title,
                'slug'    => $new_slug_en,
                'date'    => $date,
                'author'  => $author,
                'status'  => $status,
                'excerpt' => $excerpt_en,
                'image'   => $image,
                'tags'    => $tags,
                'content' => $content_en,
            ];
            $dir_en = ARTICLES_PATH . '/en';
            if (!is_dir($dir_en)) mkdir($dir_en, 0755, true);
            save_json($dir_en . '/' . $new_slug_en . '.json', $data_en);
        }

        header('Location: /admin/articles.php?saved=1');
        exit;

        } // end !$error
    }
}

$page_title_admin = $is_new ? 'Нова статия' : 'Редактиране: ' . h($article['title'] ?? '');
$active_nav       = 'articles';
$tinymce_key     = setting_get('tinymce_api_key', 'no-api-key');
$page_head_extra  = '<script src="https://cdn.tiny.cloud/1/' . h($tinymce_key) . '/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>'
                  . '<style>.admin-content{max-width:960px;}</style>';
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';

$edit_slug    = $article['slug'] ?? '';
$edit_slug_en = $article['slug_en'] ?? '';
$edit_date    = $article['date'] ?? date('Y-m-d');
$edit_tags    = implode(', ', $article['tags'] ?? []);
$has_en_version = !empty($article_en);
$deepl_ready    = deepl_is_configured();
$claude_ready   = claude_is_configured();
?>

<div class="admin-page-header">
  <h1><?= $is_new ? 'Нова статия' : 'Редактиране на статия' ?></h1>
  <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
    <a href="/admin/articles.php" class="btn btn--outline">← Обратно</a>
    <?php if (!$is_new): ?>
      <a href="/novini/<?= urlencode($edit_slug) ?>/" target="_blank" class="btn btn--outline" style="font-size:.85rem;">↗ BG</a>
    <?php endif; ?>
    <?php if (!$is_new && $has_en_version): ?>
      <a href="/en/news/<?= urlencode($edit_slug_en ?: $edit_slug) ?>/" target="_blank" class="btn btn--outline" style="font-size:.85rem;">↗ EN</a>
    <?php endif; ?>
    <button type="submit" form="articleForm" class="btn btn--primary">Запази</button>
  </div>
</div>

<?php if ($error): ?>
  <div class="admin-alert admin-alert--error" style="margin-bottom:1.5rem;"><?= h($error) ?></div>
<?php endif; ?>

<form method="POST" action="/admin/article-edit.php<?= $is_new ? '' : '?slug=' . urlencode($slug_param) ?>"
      enctype="multipart/form-data" class="admin-form" id="articleForm">
  <?= csrf_field() ?>

  <!-- ── BG Section ─────────────────────────────────────────────────────────── -->
  <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;margin-bottom:1.5rem;">
    <h2 style="font-size:.95rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin:0 0 1.25rem;">
      Български <span style="font-size:.68rem;font-weight:700;background:#dcfce7;color:#166534;border-radius:3px;padding:.05rem .35rem;margin-left:.4rem;vertical-align:middle;">BG</span>
    </h2>

    <div class="form-group">
      <label for="title">Заглавие <span style="color:var(--teal);">*</span></label>
      <input type="text" id="title" name="title"
             value="<?= h($article['title'] ?? '') ?>"
             required>
    </div>

    <div class="form-group">
      <label for="slug">Slug (URL)</label>
      <input type="text" id="slug" name="slug"
             value="<?= h($edit_slug) ?>">
      <small style="color:var(--text-muted);">Оставете празно — генерира се автоматично от заглавието при запазване.</small>
    </div>

    <div class="form-group">
      <label>Съдържание</label>
      <textarea id="content" name="content" class="rich-editor"><?= $article['content'] ?? '' ?></textarea>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
      <div class="form-group">
        <label for="author">Автор</label>
        <input type="text" id="author" name="author"
               value="<?= h($article['author'] ?? ($current['name'] ?? '')) ?>">
      </div>
      <div class="form-group">
        <label for="date">Дата</label>
        <input type="date" id="date" name="date" value="<?= h($edit_date) ?>">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
      <div class="form-group">
        <label for="status">Статус</label>
        <select id="status" name="status">
          <option value="published" <?= ($article['status'] ?? '') === 'published' ? 'selected' : '' ?>>Публикувана</option>
          <option value="draft"     <?= ($article['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Чернова</option>
        </select>
      </div>
      <div class="form-group">
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:.4rem;">
          <label for="tagsInput" style="margin-bottom:0;">Тагове / SEO ключови думи <small style="font-weight:normal;text-transform:none;">(разделени със запетая)</small></label>
          <?php if ($claude_ready): ?>
            <button type="button" id="suggestKeywordsBtn" class="btn btn--outline" style="font-size:.8rem;padding:.3rem .7rem;">✦ Предложи ключови думи</button>
          <?php endif; ?>
        </div>
        <input type="text" id="tagsInput" placeholder="тег1, тег2" value="<?= h($edit_tags) ?>">
        <input type="hidden" name="tags" id="tagsHidden" value="<?= h($edit_tags) ?>">
        <div id="keywordSuggestions" style="display:none;margin-top:.75rem;"></div>
      </div>
    </div>

    <div class="form-group">
      <label>Изображение</label>
      <input type="hidden" name="remove_image" id="removeImageFlag" value="">
      <?php if (!empty($article['image'])): ?>
        <div id="currentImage" style="margin-bottom:.75rem;display:flex;align-items:flex-start;gap:.75rem;">
          <img src="<?= h($article['image']) ?>" alt="" style="max-height:150px;border-radius:4px;">
          <button type="button" class="btn btn--outline" style="font-size:.78rem;padding:.25rem .6rem;color:#dc2626;border-color:#dc2626;"
                  onclick="document.getElementById('removeImageFlag').value='1';document.getElementById('currentImage').style.display='none';">
            ✕ Премахни
          </button>
        </div>
      <?php endif; ?>
      <input type="file" id="imageFile" name="image" accept="image/jpeg,image/png,image/webp" data-om-crop>
      <input type="hidden" name="image_from_library" id="imageFromLibrary">
      <button type="button" class="btn btn--outline"
              style="margin-top:.5rem;font-size:.82rem;"
              onclick="_pickArticleImage()">Избери от библиотека</button>
      <div id="imagePreview" style="margin-top:.75rem;"></div>
    </div>
  </div>

  <!-- ── EN Section ─────────────────────────────────────────────────────────── -->
  <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;margin-bottom:1.5rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
      <h2 style="font-size:.95rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin:0;">
        English <span style="font-size:.68rem;font-weight:700;background:#dbeafe;color:#1d4ed8;border-radius:3px;padding:.05rem .35rem;margin-left:.4rem;vertical-align:middle;">EN</span>
        <?php if ($has_en_version): ?>
          <span class="badge badge--published" style="margin-left:.3rem;vertical-align:middle;font-size:.7rem;">✓</span>
        <?php endif; ?>
      </h2>
      <?php if ($deepl_ready): ?>
        <button type="button" id="translateToEnBtn" class="btn btn--outline" style="font-size:.8rem;">
          ✦ Auto-translate from Bulgarian
        </button>
      <?php else: ?>
        <a href="/admin/translate.php" style="font-size:.8rem;color:var(--text-muted);">Configure DeepL to enable auto-translate →</a>
      <?php endif; ?>
    </div>

    <div class="form-group">
      <label for="title_en">Title (EN)</label>
      <input type="text" id="title_en" name="title_en"
             value="<?= h($article_en['title'] ?? '') ?>"
             placeholder="English title">
    </div>

    <div class="form-group">
      <label for="slug_en">Slug (EN URL)</label>
      <input type="text" id="slug_en" name="slug_en"
             value="<?= h($edit_slug_en) ?>"
             placeholder="auto-generated from English title">
      <small style="color:var(--text-muted);">Leave empty to auto-generate from the English title. Use only letters, numbers, and hyphens.</small>
    </div>

    <div class="form-group">
      <label>Content (EN)</label>
      <textarea id="content_en" name="content_en" class="rich-editor"><?= $article_en['content'] ?? '' ?></textarea>
    </div>

    <p style="margin:.5rem 0 0;font-size:.8rem;color:var(--text-muted);">
      Leave all EN fields empty to skip creating/updating the English version.
      Date, author, image and status are shared between BG and EN.
    </p>
  </div>

  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>

<?php if (!$is_new && $claude_ready): ?>
<?php
$li_scheduled      = !empty($article['linkedin_scheduled_at']);
$li_posted         = !empty($article['linkedin_posted_at']);
$buffer_ready      = setting_is_set('buffer_linkedin_channel_id') && setting_is_set('buffer_api_key');
$fb_ready          = setting_is_set('buffer_facebook_channel_id')  && setting_is_set('buffer_api_key');
$insta_ready       = setting_is_set('buffer_instagram_channel_id') && setting_is_set('buffer_api_key');
$fb_sched          = !empty($article['fb_scheduled_at']);
$insta_sched       = !empty($article['insta_scheduled_at']);
$default_social_at = date('Y-m-d\TH:i', strtotime('+1 day 11:00'));
?>
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
  <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.85rem;">
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

<!-- ── LinkedIn panel ─────────────────────────────────────────────────────── -->
<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;margin-top:1.5rem;" id="linkedinPanel">
  <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem;flex-wrap:wrap;">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="#0a66c2" style="flex-shrink:0;"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
    <h2 style="font-size:.95rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin:0;">LinkedIn</h2>
    <span id="liStatusBadge" style="margin-left:auto;font-size:.82rem;font-weight:600;">
      <?php if ($li_posted): ?>
        <span style="color:#2e7d32;">✓ Публикувано <?= h(date('d.m.Y', strtotime($article['linkedin_posted_at']))) ?>
        <?php if (!empty($article['linkedin_url'])): ?>
          — <a href="<?= h($article['linkedin_url']) ?>" target="_blank" rel="noopener" style="color:#0a66c2;">Виж →</a>
        <?php endif; ?>
        </span>
      <?php elseif ($li_scheduled): ?>
        <span style="color:#b45309;">⏱ Планирано за <?= h(date('d.m.Y H:i', strtotime($article['linkedin_scheduled_at']))) ?></span>
      <?php endif; ?>
    </span>
  </div>

  <!-- Generate + actions row -->
  <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">
    <button type="button" id="liGenerateNewsBtn" class="btn btn--outline" style="font-size:.82rem;">
      ✦ Генерирай LinkedIn новина
    </button>
    <button type="button" id="liGeneratePostBtn" class="btn btn--outline" style="font-size:.82rem;">
      ✦ Генерирай LinkedIn пост
    </button>
    <span id="liSpinner" style="display:none;font-size:.82rem;color:var(--text-muted);">Генерира се…</span>
    <span id="liError" style="display:none;font-size:.82rem;color:#c0392b;"></span>
  </div>

  <!-- Text + scheduling (hidden until text is generated or already exists) -->
  <div id="liTextWrap">
    <div class="form-group" style="margin-bottom:1rem;">
      <label style="display:flex;justify-content:space-between;align-items:center;">
        <span>Текст за LinkedIn (EN)</span>
        <div style="display:flex;gap:.4rem;">
          <button type="button" id="liBoldBtn" title="Bold selected text (Unicode)"
                  style="padding:.2rem .55rem;border:1px solid var(--border);border-radius:4px;background:#fff;cursor:pointer;font-weight:700;font-size:.88rem;line-height:1.4;">𝗕</button>
          <button type="button" id="liCopyBtn" class="btn btn--outline" style="font-size:.75rem;padding:.25rem .6rem;">Копирай</button>
        </div>
      </label>
      <textarea id="liText" rows="8"
                style="width:100%;font-size:.88rem;line-height:1.65;resize:vertical;"><?php
        if (!empty($article['linkedin_text'])) {
            echo h($article['linkedin_text']);
        } else {
            $_li_title = ($article_en['title'] ?? '') ?: ($article['title'] ?? '');
            $_li_raw   = ($article_en['content'] ?? '') ?: ($article['content'] ?? '');
            $_li_body  = _html_to_social_text($_li_raw);
            echo h(trim($_li_title . ($_li_body ? "\n\n" . $_li_body : '')));
        }
      ?></textarea>
      <div id="liCounter" style="display:flex;gap:1.25rem;margin-top:.3rem;font-size:.78rem;font-variant-numeric:tabular-nums;"></div>
    </div>

    <!-- Buffer scheduling -->
    <div style="margin-bottom:.75rem;">
      <label for="liScheduleAt" style="font-size:.85rem;font-weight:600;display:block;margin-bottom:.3rem;">Планирай за</label>
      <input type="datetime-local" id="liScheduleAt"
             value="<?= h(!empty($article['linkedin_scheduled_at']) ? date('Y-m-d\TH:i', strtotime($article['linkedin_scheduled_at'])) : date('Y-m-d\TH:i', strtotime('+1 day 09:00'))) ?>"
             style="font-size:.88rem;">
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
      <button type="button" id="liScheduleBtn" class="btn btn--primary" style="font-size:.85rem;white-space:nowrap;">
        Планирай в Buffer
      </button>
      <button type="button" id="liNowBtn" class="btn btn--outline" style="font-size:.85rem;white-space:nowrap;">
        Публикувай сега
      </button>
      <span id="liScheduleSpinner" style="display:none;font-size:.85rem;color:var(--text-muted);">Изпраща се…</span>
      <span id="liScheduleError" style="display:none;font-size:.85rem;color:#c0392b;"></span>
    </div>
    <p style="margin:.4rem 0 0;font-size:.78rem;color:var(--text-muted);">
      Публикацията ще бъде планирана в Buffer за избрания час. Можете да я редактирате в <a href="https://publish.buffer.com" target="_blank" rel="noopener">publish.buffer.com</a>.
    </p>

    <!-- Posted URL (manual fallback) -->
    <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid #f0f0f0;">
      <label for="liUrl" style="font-size:.82rem;font-weight:600;display:block;margin-bottom:.3rem;color:var(--text-muted);">
        URL на публикуваната публикация (незадължително — за справка)
      </label>
      <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <input type="url" id="liUrl" placeholder="https://www.linkedin.com/posts/..."
               value="<?= h($article['linkedin_url'] ?? '') ?>"
               style="flex:1;min-width:200px;box-sizing:border-box;font-size:.88rem;">
        <button type="button" id="liSaveUrlBtn" class="btn btn--outline" style="font-size:.82rem;white-space:nowrap;">
          Запази URL
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if (!$is_new && !$claude_ready): ?>
<div style="margin-top:1.5rem;padding:1rem;background:#f9fafb;border:1px solid var(--border);border-radius:var(--radius-lg);font-size:.88rem;color:var(--text-muted);">
  Социални мрежи: конфигурирайте Claude API ключ в <a href="/admin/payment.php">Плащания → Claude</a>.
</div>
<?php endif; ?>

<div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
  <button type="submit" form="articleForm" class="btn btn--primary">Запази статията</button>
</div>

<script>

function _pickArticleImage() {
  openMediaPicker(function (p) {
    document.getElementById('imageFromLibrary').value = p;
    document.getElementById('imageFile').value = '';
    document.getElementById('imagePreview').innerHTML =
      '<img src="' + p + '" style="max-height:150px;border-radius:4px;">';
  });
}

document.getElementById('tagsInput').addEventListener('input', function () {
  document.getElementById('tagsHidden').value = this.value;
});

document.getElementById('imageFile').addEventListener('change', function () {
  var preview = document.getElementById('imagePreview');
  if (this.files && this.files[0]) {
    var r = new FileReader();
    r.onload = e => preview.innerHTML = '<img src="' + e.target.result + '" style="max-height:150px;border-radius:4px;">';
    r.readAsDataURL(this.files[0]);
  }
});

// ── TinyMCE rich text editors ─────────────────────────────────────────────────
tinymce.init(Object.assign({}, window._tinyBase, { selector: 'textarea.rich-editor', min_height: 300 }));
initAutosave({
  key:     <?= json_encode('article:' . ($is_new ? 'new' : $slug_param)) ?>,
  formId:  'articleForm',
  tinyIds: ['content', 'content_en']
});

function _tinyGet(id) {
  var ed = tinymce.get(id);
  return ed ? ed.getContent() : document.getElementById(id).value;
}
function _tinySet(id, html) {
  var ed = tinymce.get(id);
  if (ed) ed.setContent(html);
  else document.getElementById(id).value = html;
}

// ── Keyword suggestions ────────────────────────────────────────────────────────
var suggestBtn = document.getElementById('suggestKeywordsBtn');
if (suggestBtn) {
  suggestBtn.addEventListener('click', async function() {
    var btn = this;
    btn.disabled = true;
    btn.textContent = 'Зарежда…';

    var resp = await fetch('/admin/suggest-keywords-ajax.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        title_bg:   document.getElementById('title').value,
        content_bg: _tinyGet('content'),
        title_en:   document.getElementById('title_en').value,
        content_en: _tinyGet('content_en'),
      })
    });

    var data = await resp.json();
    var panel = document.getElementById('keywordSuggestions');

    if (!data.ok) {
      panel.innerHTML = '<p style="color:#c0392b;font-size:.85rem;margin:0;">✗ ' + data.error + '</p>';
      panel.style.display = 'block';
      btn.disabled = false;
      btn.textContent = '✦ Предложи ключови думи';
      return;
    }

    var existing = document.getElementById('tagsInput').value
      .split(',').map(t => t.trim().toLowerCase()).filter(Boolean);

    var html = '<p style="font-size:.8rem;color:var(--text-muted);margin:0 0 .5rem;">Кликни върху ключова дума, за да я добавиш:</p><div style="display:flex;flex-wrap:wrap;gap:.4rem;">';
    data.keywords.forEach(function(kw) {
      var already = existing.includes((kw.bg || '').toLowerCase());
      html += '<button type="button" class="kw-chip" data-bg="' + kw.bg.replace(/"/g,'&quot;') + '" '
            + 'style="padding:.3rem .65rem;border-radius:20px;border:1px solid var(--teal);background:'
            + (already ? 'var(--teal)' : '#fff') + ';color:' + (already ? '#fff' : 'var(--teal)')
            + ';cursor:pointer;font-size:.82rem;transition:background .15s,color .15s;" '
            + 'title="' + (kw.en || '').replace(/"/g,'&quot;') + '">'
            + kw.bg
            + '<span style="font-size:.7rem;opacity:.65;margin-left:.3rem;">EN: ' + kw.en + '</span>'
            + '</button>';
    });
    html += '</div>';
    panel.innerHTML = html;
    panel.style.display = 'block';

    panel.querySelectorAll('.kw-chip').forEach(function(chip) {
      chip.addEventListener('click', function() {
        var kw = this.dataset.bg;
        var input = document.getElementById('tagsInput');
        var tags  = input.value.split(',').map(t => t.trim()).filter(Boolean);
        var idx   = tags.findIndex(t => t.toLowerCase() === kw.toLowerCase());
        if (idx === -1) {
          tags.push(kw);
          this.style.background = 'var(--teal)';
          this.style.color = '#fff';
        } else {
          tags.splice(idx, 1);
          this.style.background = '#fff';
          this.style.color = 'var(--teal)';
        }
        input.value = tags.join(', ');
        document.getElementById('tagsHidden').value = input.value;
      });
    });

    btn.disabled = false;
    btn.textContent = '✦ Предложи ключови думи';
  });
}

// Auto-translate button
var translateBtn = document.getElementById('translateToEnBtn');
if (translateBtn) {
  translateBtn.addEventListener('click', async function() {
    var btn = this;
    btn.disabled = true;
    btn.textContent = 'Translating…';

    var bgTitle   = document.getElementById('title').value;
    var bgContent = _tinyGet('content');

    async function tx(text, isHtml) {
      if (!text.trim()) return '';
      var r = await fetch('/admin/translate-ajax.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({text: text, is_html: isHtml})
      });
      var d = await r.json();
      return d.ok ? d.translated : '';
    }

    try {
      var [enTitle, enContent] = await Promise.all([
        tx(bgTitle,   false),
        tx(bgContent, true),
      ]);
      if (enTitle)   document.getElementById('title_en').value = enTitle;
      if (enContent) _tinySet('content_en', enContent);
      btn.textContent = '✓ Translated';
    } catch(e) {
      btn.textContent = '✗ Error — check console';
      console.error(e);
    }
    btn.disabled = false;
  });
}

// ── LinkedIn panel ─────────────────────────────────────────────────────────────
(function () {
  var generateNewsBtn = document.getElementById('liGenerateNewsBtn');
  if (!generateNewsBtn) return;  // not shown for new articles

  var liTextArea   = document.getElementById('liText');
  var copyBtn      = document.getElementById('liCopyBtn');
  var scheduleBtn  = document.getElementById('liScheduleBtn');
  var saveUrlBtn   = document.getElementById('liSaveUrlBtn');
  var slug         = '<?= h($edit_slug) ?>';

  // Unicode bold sans-serif map (LinkedIn-compatible)
  var _boldMap = (function () {
    var m = {};
    'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'.split('').forEach(function (c) {
      var code = c.charCodeAt(0);
      var bold;
      if (c >= 'A' && c <= 'Z') bold = 0x1D5D4 + (code - 65);
      else if (c >= 'a' && c <= 'z') bold = 0x1D5EE + (code - 97);
      else bold = 0x1D7EC + (code - 48);
      m[c] = String.fromCodePoint(bold);
    });
    return m;
  })();

  function _toBold(str) {
    return str.split('').map(function (c) { return _boldMap[c] || c; }).join('');
  }

  function _isAlreadyBold(str) {
    // Check if first alphanumeric char is already a Unicode bold codepoint
    for (var i = 0; i < str.length; i++) {
      var cp = str.codePointAt(i);
      if ((cp >= 0x1D5D4 && cp <= 0x1D607) || (cp >= 0x1D7EC && cp <= 0x1D7F5)) return true;
      if (/[A-Za-z0-9]/.test(str[i])) return false;
    }
    return false;
  }

  function _fromBold(str) {
    return Array.from(str).map(function (c) {
      var cp = c.codePointAt(0);
      if (cp >= 0x1D5D4 && cp <= 0x1D5ED) return String.fromCharCode(cp - 0x1D5D4 + 65); // A-Z
      if (cp >= 0x1D5EE && cp <= 0x1D607) return String.fromCharCode(cp - 0x1D5EE + 97); // a-z
      if (cp >= 0x1D7EC && cp <= 0x1D7F5) return String.fromCharCode(cp - 0x1D7EC + 48); // 0-9
      return c;
    }).join('');
  }

  // Bold button — toggle bold on selection
  var boldBtn = document.getElementById('liBoldBtn');
  if (boldBtn) {
    boldBtn.addEventListener('click', function () {
      var start = liTextArea.selectionStart;
      var end   = liTextArea.selectionEnd;
      if (start === end) return; // nothing selected
      var sel     = liTextArea.value.substring(start, end);
      var toggled = _isAlreadyBold(sel) ? _fromBold(sel) : _toBold(sel);
      liTextArea.setRangeText(toggled, start, end, 'select');
      liTextArea.dispatchEvent(new Event('input'));
    });
  }

  async function liPost(body) {
    var r = await fetch('/admin/linkedin-ajax.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(Object.assign({ csrf_token: '<?= csrf_token() ?>', slug: slug }, body))
    });
    return r.json();
  }

  // Generate — shared handler for both buttons
  function _liGenerate(generateType) {
    var spinner = document.getElementById('liSpinner');
    var errEl   = document.getElementById('liError');
    generateNewsBtn.disabled = true;
    document.getElementById('liGeneratePostBtn').disabled = true;
    spinner.style.display = '';
    errEl.style.display   = 'none';
    liPost({ action: 'generate', generate_type: generateType })
      .then(function(data) {
        if (!data.ok) {
          errEl.textContent   = data.error;
          errEl.style.display = '';
        } else {
          liTextArea.value = data.text;
          document.getElementById('liTextWrap').style.display = '';
        }
      })
      .catch(function() {
        errEl.textContent   = 'Грешка при свързване.';
        errEl.style.display = '';
      })
      .finally(function() {
        generateNewsBtn.disabled = false;
        document.getElementById('liGeneratePostBtn').disabled = false;
        spinner.style.display = 'none';
      });
  }

  generateNewsBtn.addEventListener('click', function() { _liGenerate('news'); });
  document.getElementById('liGeneratePostBtn').addEventListener('click', function() { _liGenerate('post'); });

  // Copy
  if (copyBtn) {
    copyBtn.addEventListener('click', function() {
      navigator.clipboard.writeText(liTextArea.value).then(function() {
        var orig = copyBtn.textContent;
        copyBtn.textContent = '✓ Копирано';
        setTimeout(function() { copyBtn.textContent = orig; }, 1500);
      }).catch(function() { liTextArea.select(); document.execCommand('copy'); });
    });
  }

  function _liGetText() {
    var text = liTextArea.value.trim();
    if (text) return text;
    // Fallback: build from EN title/content (or BG if EN not filled)
    var enTitle   = (document.getElementById('title_en') || {}).value || '';
    var bgTitle   = (document.getElementById('title')    || {}).value || '';
    var useTitle  = enTitle.trim() || bgTitle.trim();
    var enContent = _tinyGet('content_en');
    var bgContent = _tinyGet('content');
    var rawHtml   = enContent.trim() || bgContent.trim();
    var tmp = document.createElement('div'); tmp.innerHTML = rawHtml;
    var bodyText = (tmp.textContent || tmp.innerText || '').trim();
    return useTitle + (bodyText ? '\n\n' + bodyText : '');
  }

  async function _liSend(scheduled_at, btn) {
    var text    = _liGetText();
    var spinner = document.getElementById('liScheduleSpinner');
    var errEl   = document.getElementById('liScheduleError');
    btn.disabled          = true;
    spinner.style.display = '';
    errEl.style.display   = 'none';
    try {
      var data = await liPost({ action: 'schedule', linkedin_text: text, scheduled_at: scheduled_at });
      if (!data.ok) {
        errEl.textContent   = data.error;
        errEl.style.display = '';
      } else {
        var badge = document.getElementById('liStatusBadge');
        if (data.due_at) {
          var dt      = new Date(data.due_at);
          var dateStr = dt.toLocaleString('bg-BG', {
            timeZone: 'Europe/Sofia', day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit'
          });
          badge.innerHTML = '<span style="color:#b45309;">⏱ Планирано за ' + dateStr + ' (Sofia)</span>';
        } else {
          badge.innerHTML = '<span style="color:#2e7d32;">✓ Публикувано сега</span>';
        }
      }
    } catch(e) {
      errEl.textContent   = 'Грешка при свързване.';
      errEl.style.display = '';
    }
    btn.disabled          = false;
    spinner.style.display = 'none';
  }

  // Schedule via Buffer
  if (scheduleBtn) {
    scheduleBtn.addEventListener('click', function() {
      var schedAt = document.getElementById('liScheduleAt').value;
      var errEl   = document.getElementById('liScheduleError');
      if (!schedAt) { errEl.textContent = 'Изберете дата и час.'; errEl.style.display = ''; return; }
      _liSend(schedAt, scheduleBtn);
    });
  }

  // Publish now
  var liNowBtn = document.getElementById('liNowBtn');
  if (liNowBtn) {
    liNowBtn.addEventListener('click', function() { _liSend('now', liNowBtn); });
  }

  // Save URL (manual — paste the LinkedIn post URL after publishing)
  if (saveUrlBtn) {
    saveUrlBtn.addEventListener('click', async function() {
      var url  = document.getElementById('liUrl').value.trim();
      var text = liTextArea.value;
      saveUrlBtn.disabled = true;
      try {
        var data = await liPost({ action: 'save_post', linkedin_url: url, linkedin_text: text });
        if (!data.ok) {
          alert(data.error);
        } else {
          var badge   = document.getElementById('liStatusBadge');
          var dateStr = data.posted_at ? data.posted_at.substring(0,10).split('-').reverse().join('.') : '';
          var html    = '<span style="color:#2e7d32;">✓ Публикувано ' + dateStr;
          if (url) html += ' — <a href="' + url.replace(/"/g,'&quot;') + '" target="_blank" rel="noopener" style="color:#0a66c2;">Виж →</a>';
          badge.innerHTML       = html + '</span>';
          saveUrlBtn.textContent = '✓ Запазено';
        }
      } catch(e) { alert('Грешка при запис.'); }
      saveUrlBtn.disabled = false;
    });
  }
})();

// ── Facebook / Instagram panel ─────────────────────────────────────────────────
(function () {
  var generateNewsBtn = document.getElementById('socGenerateNewsBtn');
  if (!generateNewsBtn) return;

  var fbText    = document.getElementById('fbText');
  var instaText = document.getElementById('instaText');
  var slug      = '<?= h($edit_slug) ?>';

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

  // Generate — shared handler for both buttons
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

  // Copy FB → Instagram, stripping link lines
  var copyFromFbBtn = document.getElementById('socCopyFromFbBtn');
  if (copyFromFbBtn) {
    copyFromFbBtn.addEventListener('click', function() {
      var val = fbText.value.trim();
      if (!val) return;
      var stripped = val.split('\n')
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
  var LIMITS = { ig: 2200, fb: 63206, li: 3000 };

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

  var fbEl  = document.getElementById('fbText');
  var fbOut = document.getElementById('fbCounter');
  if (fbEl && fbOut) {
    fbEl.addEventListener('input', function() {
      fbOut.innerHTML = counterSpan('Facebook', fbEl.value.length, LIMITS.fb);
    });
    fbOut.innerHTML = counterSpan('Facebook', fbEl.value.length, LIMITS.fb);
  }

  var igEl  = document.getElementById('instaText');
  var igOut = document.getElementById('instaCounter');
  if (igEl && igOut) {
    igEl.addEventListener('input', function() {
      igOut.innerHTML = counterSpan('Instagram', igEl.value.length, LIMITS.ig);
    });
    igOut.innerHTML = counterSpan('Instagram', igEl.value.length, LIMITS.ig);
  }

  var liEl  = document.getElementById('liText');
  var liOut = document.getElementById('liCounter');
  if (liEl && liOut) {
    liEl.addEventListener('input', function() {
      liOut.innerHTML = counterSpan('LinkedIn', liEl.value.length, LIMITS.li);
    });
    liOut.innerHTML = counterSpan('LinkedIn', liEl.value.length, LIMITS.li);
  }
})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/translator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ai_keywords.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ai_excerpt.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/articles.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/images.php';
admin_require_editorial();

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

$current = admin_user();
$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // The Socials panel saves in the background (_ajax=1) and wants JSON back, not a redirect.
    $is_ajax = ($_POST['_ajax'] ?? '') === '1';
    $ajax_reply = static function (array $body, int $code = 200): never {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
        exit;
    };
    if (!csrf_verify()) {
        if ($is_ajax) $ajax_reply(['ok' => false, 'error' => 'Сесията е изтекла — презаредете страницата.'], 400);
        http_response_code(400); exit('Invalid token');
    }

    // ── BG fields ──────────────────────────────────────────────────────────────
    $title   = trim($_POST['title']   ?? '');
    $content = $_POST['content']       ?? '';
    // No excerpt field on this form — it's edited inline on the news listing
    // (novini/index.php). Keep whatever is stored rather than wiping it on every
    // unrelated save.
    $excerpt = trim($article['excerpt'] ?? '');
    $author  = trim($_POST['author']   ?? $current['name'] ?? '');
    $status  = in_array($_POST['status'] ?? '', ['published','draft']) ? $_POST['status'] : 'draft';
    $date    = is_string($_POST['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date']) ? $_POST['date'] : date('Y-m-d');
    $scheduled = $status === 'draft' && !empty($_POST['scheduled']);
    [$status, $scheduled] = article_apply_publish_now($status, $scheduled, !empty($_POST['publish_now']));
    $tags    = array_values(array_filter(array_map('trim', explode(',', $_POST['tags'] ?? ''))));

    // ── EN fields (optional) ───────────────────────────────────────────────────
    $title_en   = trim($_POST['title_en']   ?? '');
    $content_en = $_POST['content_en']       ?? '';
    $excerpt_en = trim($article_en['excerpt'] ?? '');
    $has_en     = $title_en !== '' || $content_en !== '';

    // Derive both slugs (BG keeps its language unless „латиница“ is on in
    // Админ → Организация; EN prefers override/EN title). See
    // includes/articles.php::article_compute_slugs for the rules + unit tests.
    $slugs       = article_compute_slugs(
        is_string($_POST['slug'] ?? null) ? $_POST['slug'] : '',
        is_string($_POST['slug_en'] ?? null) ? $_POST['slug_en'] : '',
        $title, $title_en, '', article_slugs_latin(), $is_new ? '' : $slug_param
    );
    $new_slug    = $slugs['bg'];
    $new_slug_en = $slugs['en'];
    // Where the EN file currently lives (may differ from BG slug after migration)
    $slug_en_current = $is_new ? null : ($article['slug_en'] ?? $slug_param);

    if (!$title) {
        $error = 'Заглавието е задължително.';
        if ($is_ajax) $ajax_reply(['ok' => false, 'error' => $error]);
    } else {
        $image       = $article['image'] ?? '';
        // Image problems never block saving the article text — a rejected or
        // failed upload only shows a separate warning and keeps the old image.
        $image_error = '';

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
                    image_resize_to_fit($img_dir . $filename);
                    $image = '/assets/images/articles/' . $filename;
                } else {
                    $image_error = 'Неуспешен запис на снимката. Проверете правата на директорията.';
                }
            } else {
                $image_error = 'Позволени са само JPEG, PNG и WebP изображения.';
            }
        } elseif (!empty($_POST['remove_image'])) {
            $image = '';
        } elseif (!empty($_POST['image_from_library'])) {
            $lib = $_POST['image_from_library'];
            if (is_string($lib) && article_photo_path_ok($lib)) {
                $image = $lib;
            }
        } elseif (isset($_FILES['image']['error']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $codes = [
                UPLOAD_ERR_INI_SIZE  => 'Снимката е прекалено голяма (upload_max_filesize).',
                UPLOAD_ERR_FORM_SIZE => 'Снимката е прекалено голяма.',
                UPLOAD_ERR_PARTIAL   => 'Снимката е качена само частично. Опитайте отново.',
            ];
            $image_error = $codes[$_FILES['image']['error']] ?? 'Грешка при качване на снимката (код ' . $_FILES['image']['error'] . ').';
        }

        // The photo grid (Task 4) posts paths only — each file was already uploaded
        // through /admin/inline-upload.php. Without the grid, keep what is stored.
        if (($_POST['photos_present'] ?? '') === '1') {
            $grid      = article_photos_from_post($_POST, null, array_column(article_photos_for_editor($article), 'src'));
            $photos_bg = $grid['bg'];
            $photos_en = $grid['en'];
            $image     = $grid['image'];
        } else {
            $photos_bg = article_photos(array_merge($article, ['image' => $image]));
            $photos_en = $article_en ? article_photos(array_merge($article_en, ['image' => $image])) : $photos_bg;
            // A photo replaced through the old single field becomes the main photo.
            if ($image !== '' && !in_array($image, array_column($photos_bg, 'src'), true)) {
                array_unshift($photos_bg, ['src' => $image, 'caption' => '']);
                array_unshift($photos_en, ['src' => $image, 'caption' => '']);
                $photos_bg = array_slice($photos_bg, 0, ARTICLE_PHOTOS_MAX);
                $photos_en = array_slice($photos_en, 0, ARTICLE_PHOTOS_MAX);
            }
            if ($image === '') { $photos_bg = []; $photos_en = []; }
        }

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

        // Fill in an excerpt the post doesn't have yet (Claude when configured, else
        // the start of the text), so every post gets one for the listing and the
        // search-engine description without the author having to know about it.
        if ($excerpt === '') {
            $excerpt = article_auto_excerpt($title, $content, 'bg');
        }
        if ($has_en && $excerpt_en === '') {
            $excerpt_en = article_auto_excerpt($title_en ?: $title, $content_en, 'en');
        }

        // Save BG article (preserves social metadata from $article)
        $data = article_build_bg_data([
            'title'   => $title,   'slug'    => $new_slug, 'slug_en' => $new_slug_en,
            'date'    => $date,    'author'  => $author,   'status'  => $status,
            'excerpt' => $excerpt, 'image'   => $image,    'tags'    => $tags,
            'photos'  => $photos_bg,
            'content' => $content, 'scheduled' => $scheduled,
            // A renamed article keeps its old address working (301 from the news page).
            'slug_legacy' => $is_new ? '' : article_retire_slug($article, $slug_param, $new_slug),
        ], $article);
        $dir_bg = ARTICLES_PATH . '/bg';
        if (!is_dir($dir_bg)) mkdir($dir_bg, 0755, true);
        save_json($dir_bg . '/' . $new_slug . '.json', $data);

        // Save EN article (if any EN field provided)
        if ($has_en) {
            $data_en = article_build_en_data([
                'title'   => $title_en ?: $title, 'slug'    => $new_slug_en,
                'date'    => $date,    'author'  => $author,   'status'  => $status,
                'excerpt' => $excerpt_en, 'image' => $image,   'tags'    => $tags,
                'photos'  => $photos_en,
                'content' => $content_en, 'scheduled' => $scheduled,
                'slug_legacy' => ($is_new || !$article_en) ? '' : article_retire_slug($article_en, (string) $slug_en_current, $new_slug_en),
            ]);
            $dir_en = ARTICLES_PATH . '/en';
            if (!is_dir($dir_en)) mkdir($dir_en, 0755, true);
            save_json($dir_en . '/' . $new_slug_en . '.json', $data_en);
        }

        // Stay in the editor after saving: the next steps (the Social tab, the
        // public preview) are right here. "← Обратно" still leads to the list.
        if ($is_ajax) {
            $ajax_reply([
                'ok' => true, 'slug' => $new_slug, 'slug_en' => $new_slug_en, 'status' => $status,
                'warnings' => $image_error !== '' ? ['Снимката не бе запазена: ' . $image_error] : [],
            ]);
        }
        $as_key = 'article:' . ($is_new ? 'new' : $slug_param);
        flash_set('success', 'Статията е запазена.');
        if ($image_error !== '') {
            // Image problems never block saving the article text — surface the
            // warning as a separate banner instead of losing the whole save.
            flash_set('error', 'Снимката не бе запазена: ' . $image_error);
        }
        header('Location: ' . article_editor_url_after_save($new_slug, $as_key));
        exit;
    }
}

$page_title_admin = $is_new ? 'Нова статия' : 'Редактиране: ' . h($article['title'] ?? '');
$active_nav       = 'articles';
// 960px for the article alone; the Socials panel widens it to 1400px while open (social-panel.js).
$page_head_extra  = tinymce_script_tag()
                  . '<style>.admin-content{max-width:960px;}</style>';
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';

$edit_slug    = $article['slug'] ?? '';
$edit_slug_en = $article['slug_en'] ?? '';
$edit_date    = $article['date'] ?? date('Y-m-d');
$edit_scheduled = !$is_new && article_is_scheduled($article, @filemtime(ARTICLES_PATH . '/bg/' . $slug_param . '.json') ?: null);
$edit_tags    = implode(', ', $article['tags'] ?? []);
$has_en_version = !empty($article_en);
// Translate buttons only while „Помощ от изкуствен интелект“ is on (Admin → Модули).
$deepl_ready    = module_enabled_with_needs('ai_helpers') && deepl_is_configured();
$claude_ready   = claude_is_configured();

$grid_bg   = article_photos_for_editor($article);   // missing files included — they show as such, never drop silently
$grid_en   = $article_en ? article_photos_for_editor(array_merge($article_en, ['image' => $article['image'] ?? ''])) : [];
$grid_main = article_main_photo_index($article, $grid_bg);
$en_caps   = array_column($grid_en, 'caption', 'src');

// ── Socials panel — the Facebook / Instagram / LinkedIn posts beside the article,
// built live from the unsaved text (admin/js/social-panel.js). New and saved articles
// alike. „Социални мрежи“ switched off in Admin → Модули: no toggle, no panel.
$social_on = module_enabled_with_needs('social');

$li_scheduled      = !empty($article['linkedin_scheduled_at']);
$li_posted         = !empty($article['linkedin_posted_at']);
$fb_sched          = !empty($article['fb_scheduled_at']);
$insta_sched       = !empty($article['insta_scheduled_at']);
$insta_story_sched = !empty($article['insta_story_scheduled_at']);
// Switching back to draft doesn't cancel posts already in Buffer — the editor warns.
$has_social_scheduled = $fb_sched || $insta_sched || $insta_story_sched || $li_scheduled;
// Scheduling a post publishes the article; until then each platform says so.
$draft_note_hidden = ($article['status'] ?? 'draft') === 'published' ? ' hidden' : '';
$draft_note = '<p class="soc-draft-note"' . $draft_note_hidden . ' style="margin:0 0 .6rem;font-size:.82rem;font-weight:600;color:#1e3a8a;">'
            . '<span aria-hidden="true">ℹ</span> Статията още е чернова — ще бъде публикувана веднага щом планирате публикацията.</p>';
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
    <?php if ($social_on): ?>
      <button type="button" id="socialToggle" class="btn btn--outline" aria-expanded="false" aria-controls="socialPanel"
              style="min-height:44px;"><span aria-hidden="true">📣</span> Социални мрежи <span id="socialToggleState" aria-hidden="true">▸</span></button>
    <?php endif; ?>
    <button type="submit" form="articleForm" class="btn btn--primary">Запази</button>
  </div>
</div>

<?php foreach (flash_get() as $_flash): ?>
  <div class="admin-alert admin-alert--<?= h($_flash['type']) ?>" role="<?= $_flash['type'] === 'error' ? 'alert' : 'status' ?>" style="margin-bottom:1.5rem;"><?= h($_flash['message']) ?></div>
<?php endforeach; ?>
<?php if ($error): ?>
  <div class="admin-alert admin-alert--error" style="margin-bottom:1.5rem;"><?= h($error) ?></div>
<?php endif; ?>

<!-- The article, and the Socials panel beside it when open (two columns from 1100px,
     stacked below that — set by admin/js/social-panel.js, since the styles are inline). -->
<div id="editorLayout" style="display:block;">
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
      <small style="color:var(--text-muted);">Оставете празно — генерира се автоматично от заглавието при запазване.<?php if (article_slugs_latin()): ?> Новите адреси се изписват на латиница.<?php endif; ?><?php if (!$is_new): ?> Ако смените адреса, старият продължава да води към статията.<?php endif; ?></small>
    </div>

    <div class="form-group">
      <label>Съдържание</label>
      <textarea id="content" name="content" class="rich-editor"><?= h($article['content'] ?? '') ?></textarea>
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
        <label id="scheduledRow" style="display:flex;align-items:flex-start;gap:.5rem;margin-top:.75rem;font-weight:500;text-transform:none;letter-spacing:normal;cursor:pointer;">
          <input type="checkbox" name="scheduled" id="scheduled" value="1" <?= $edit_scheduled ? 'checked' : '' ?>
                 <?= ($article['status'] ?? 'draft') === 'published' ? 'disabled' : '' ?> style="margin-top:.2rem;flex-shrink:0;">
          <span>Публикувай автоматично на тази дата
            <small id="scheduledHint" style="display:block;font-weight:400;color:var(--text-muted);line-height:1.5;margin-top:.15rem;">
              Черновата ще се публикува сама сутринта на избраната дата. Без отметка остава чернова, докато не я публикувате ръчно.
            </small>
          </span>
        </label>
        <?php if ($social_on): ?>
        <!-- Filled by admin/js/social-panel.js while the status is „Чернова“ and posts are scheduled. -->
        <div id="socDraftWarn" role="status" aria-live="polite"></div>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:.4rem;">
          <label for="tagsInput" style="margin-bottom:0;">Тагове / SEO ключови думи <small style="font-weight:normal;text-transform:none;">(разделени със запетая)</small></label>
          <?php if ($claude_ready && module_enabled_with_needs('ai_helpers')): ?>
            <button type="button" id="suggestKeywordsBtn" class="btn btn--outline" style="font-size:.8rem;padding:.3rem .7rem;">✦ Предложи ключови думи</button>
          <?php endif; ?>
        </div>
        <input type="text" id="tagsInput" placeholder="тег1, тег2" value="<?= h($edit_tags) ?>">
        <input type="hidden" name="tags" id="tagsHidden" value="<?= h($edit_tags) ?>">
        <div id="keywordSuggestions" style="display:none;margin-top:.75rem;"></div>
      </div>
    </div>

    <div class="form-group">
      <h3 id="apTitle" style="font-size:1rem;margin:0 0 .25rem;">Снимки</h3>
      <p style="margin:0 0 .75rem;color:var(--text-muted);">До 10 снимки. Основната се показва в списъка с новини и при споделяне.</p>
      <input type="hidden" name="photos_present" value="1">
      <input type="hidden" name="photo_main" id="apMain" value="<?= (int) $grid_main ?>">
      <ul id="apGrid" aria-labelledby="apTitle"
          style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.75rem;padding:0;margin:0 0 .75rem;">
        <?php foreach ($grid_bg as $i => $photo): $total = count($grid_bg); $main = $i === $grid_main; $missing = !is_file($_SERVER['DOCUMENT_ROOT'] . $photo['src']); ?>
          <?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/admin/article-photo-row.php'; ?>
        <?php endforeach; ?>
      </ul>
      <template id="apRowTpl"><?php
        // Every var is set here: $missing would otherwise leak in from the loop's
        // last row and mark each newly added photo as a missing file.
        $photo = ['src' => '__SRC__', 'caption' => '']; $i = 0; $total = 1; $main = false; $missing = false;
        require $_SERVER['DOCUMENT_ROOT'] . '/templates/admin/article-photo-row.php'; ?></template>
      <p id="apLimit" role="alert" hidden
         style="border:2px solid #b91c1c;background:#fef2f2;color:#7f1d1d;border-radius:8px;padding:.75rem 1rem;font-weight:600;">
        Може да добавите до 10 снимки. Премахнете снимка, за да добавите нова.</p>
      <p id="apError" role="alert" hidden
         style="border:2px solid #b91c1c;background:#fef2f2;color:#7f1d1d;border-radius:8px;padding:.75rem 1rem;font-weight:600;"></p>
      <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
        <label class="btn btn--primary" for="apFiles" style="min-height:44px;cursor:pointer;">+ Добави снимки</label>
        <input type="file" id="apFiles" accept="image/jpeg,image/png,image/webp" multiple
               style="position:absolute;width:1px;height:1px;opacity:0;">
        <button type="button" class="btn btn--outline" id="apLibrary" style="min-height:44px;">Избери от библиотека</button>
      </div>
      <div id="apProgress"></div>
      <div id="apLive" role="status" aria-live="polite" style="position:absolute;left:-9999px;"></div>
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
      <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
        <?php if ($deepl_ready): ?>
          <button type="button" id="translateToEnBtn" class="btn btn--outline" style="font-size:.8rem;">
            ✦ Auto-translate from Bulgarian
          </button>
        <?php else: ?>
          <a href="/admin/translate.php" style="font-size:.8rem;color:var(--text-muted);">Configure DeepL to enable auto-translate →</a>
        <?php endif; ?>
        <button type="button" id="enToggleBtn" class="btn btn--outline" aria-expanded="false" aria-controls="enFieldsWrap" style="font-size:.8rem;min-height:44px;">▾ Покажи</button>
      </div>
    </div>

    <div id="enFieldsWrap" style="display:none;">

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
      <textarea id="content_en" name="content_en" class="rich-editor-en"><?= h($article_en['content'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
      <h3 style="font-size:1rem;margin:0 0 .5rem;">Photo captions (EN)</h3>
      <ol id="apEnCaps" style="padding-left:1.25rem;margin:0;">
        <?php foreach ($grid_bg as $i => $photo): $n = $i + 1; ?>
        <li data-key="k<?= $i ?>" style="margin-bottom:.5rem;">
          <label for="photoCapEn<?= $n ?>">Caption under photo <?= $n ?></label>
          <input type="text" id="photoCapEn<?= $n ?>" name="photo_caption_en[<?= $i ?>]" maxlength="300"
                 data-translate-from="photo_caption_bg[<?= $i ?>]"
                 value="<?= h($en_caps[$photo['src']] ?? '') ?>" style="width:100%;box-sizing:border-box;min-height:44px;">
        </li>
        <?php endforeach; ?>
      </ol>
    </div>

    <p style="margin:.5rem 0 0;font-size:.8rem;color:var(--text-muted);">
      Leave all EN fields empty to skip creating/updating the English version.
      Date, author, image and status are shared between BG and EN.
    </p>
    </div><!-- /#enFieldsWrap -->
  </div>

  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>
<?php
if ($social_on):
    $default_social_at = date('Y-m-d\TH:i', strtotime('+1 day 11:00'));
    // Which Instagram mode (Post/Story) is shown on load. A scheduled post/story
    // always wins (it's the thing most likely to need attention).
    $ig_default_story = !$insta_sched && $insta_story_sched;
    // Previews of exactly what Instagram gets: each photo cut to the shape it is sent at.
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/social_images.php';
    $ig_preview = social_insta_preview($article);
endif;
?>
<?php if ($social_on): ?>
<aside id="socialPanel" aria-labelledby="socialPanelTitle" hidden
       data-slug="<?= h($edit_slug) ?>" data-has-scheduled="<?= $has_social_scheduled ? '1' : '0' ?>" data-ig-mode="<?= $ig_default_story ? 'story' : 'post' ?>"
       style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;margin-bottom:1.5rem;box-sizing:border-box;min-width:0;">
  <style>
    #socialPanel .soc-tab:focus-visible,#socialPanel [role="tabpanel"]:focus-visible{outline:3px solid #0387A5;outline-offset:2px;}
  </style>
  <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.5rem;">
    <h2 id="socialPanelTitle" style="font-size:.95rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin:0;">Социални мрежи</h2>
    <button type="button" id="socialClose" class="btn btn--outline" aria-label="Затвори социалните мрежи" style="min-height:44px;min-width:44px;font-size:.85rem;"><span aria-hidden="true">✕</span> Затвори</button>
  </div>
  <p style="margin:0 0 .75rem;font-size:.85rem;color:var(--text-muted);">Публикациите са текстът на статията, съкратен за всяка мрежа. Обновяват се, докато пишете.</p>

  <p id="socStatus" role="status" aria-live="polite" style="margin:0 0 .5rem;min-height:1.2em;font-size:.8rem;color:var(--text-muted);"></p>
  <div id="socError" role="alert" hidden
       style="border:2px solid #b91c1c;background:#fef2f2;color:#7f1d1d;border-radius:8px;padding:.6rem .85rem;margin:0 0 .75rem;font-weight:600;font-size:.85rem;">
    <span aria-hidden="true">⚠</span> <span id="socErrorMsg">Прегледът не е обновен — опитайте отново</span>
    <button type="button" id="socRetry" class="btn btn--outline" style="min-height:44px;margin-left:.5rem;font-size:.82rem;">Опитай отново</button>
  </div>

  <!-- Problems the background save reported (e.g. a photo that wasn't stored). Stays until the next save. -->
  <p id="socSaveWarn" role="alert" hidden
     style="border:2px solid #b45309;background:#fffbeb;color:#78350f;border-radius:8px;padding:.6rem .85rem;margin:0 0 .75rem;font-weight:600;font-size:.85rem;"></p>

  <!-- What happened to the site post when a post was scheduled (social-panel.js). Before
       that, each platform's .soc-draft-note says a draft will be published. -->
  <p id="siteNotice" role="status" hidden
     style="border:2px solid #1d4ed8;background:#eff6ff;color:#1e3a8a;border-radius:8px;padding:.6rem .85rem;margin:0 0 1rem;font-weight:600;font-size:.85rem;"></p>

  <div role="tablist" aria-label="Социална мрежа" style="display:flex;gap:.25rem;border-bottom:1px solid var(--border);margin-bottom:1rem;flex-wrap:wrap;">
    <?php foreach (['fb' => 'Facebook', 'insta' => 'Instagram', 'linkedin' => 'LinkedIn'] as $_p => $_name): $_sel = $_p === 'fb'; ?>
    <button type="button" role="tab" id="socTab-<?= $_p ?>" class="soc-tab" data-platform="<?= $_p ?>"
            aria-selected="<?= $_sel ? 'true' : 'false' ?>" aria-controls="socTabpanel-<?= $_p ?>" tabindex="<?= $_sel ? '0' : '-1' ?>"
            style="min-height:44px;padding:.4rem .85rem;background:none;border:none;border-bottom:3px solid <?= $_sel ? 'var(--teal)' : 'transparent' ?>;margin-bottom:-1px;cursor:pointer;font-size:.9rem;font-weight:<?= $_sel ? '700' : '500' ?>;color:<?= $_sel ? 'var(--text)' : 'var(--text-muted)' ?>;"><?= h($_name) ?></button>
    <?php endforeach; ?>
  </div>

  <!-- ── Facebook ──────────────────────────────────────────────────────────── -->
  <div role="tabpanel" id="socTabpanel-fb" aria-labelledby="socTab-fb" tabindex="0">
    <p role="status" style="margin:0 0 .5rem;font-size:.82rem;font-weight:600;min-height:0;"><span id="fbStatusBadge"><?php if ($fb_sched): ?><span style="color:#b45309;">⏱ FB: <?= h(date('d.m.Y H:i', strtotime($article['fb_scheduled_at']))) ?></span><?php endif; ?></span></p>
    <div style="border:1px solid var(--border);border-radius:8px;padding:.85rem;background:#fafafa;margin-bottom:.4rem;">
      <div id="socPreview-fb" style="white-space:pre-wrap;overflow-wrap:anywhere;font-size:.88rem;line-height:1.55;">Зарежда се прегледът…</div>
      <img class="soc-photo" alt="Главната снимка на статията" style="display:none;width:100%;height:auto;margin-top:.75rem;border-radius:6px;">
    </div>
    <p id="socCount-fb" style="margin:0;font-size:.8rem;color:var(--text-muted);font-variant-numeric:tabular-nums;"></p>
    <p id="socCut-fb" hidden style="margin:.25rem 0 0;font-size:.8rem;font-weight:600;color:#92400e;"><span aria-hidden="true">✂</span> Текстът е съкратен до последното цяло изречение</p>
    <div data-emoji-anchor style="display:flex;gap:.4rem;flex-wrap:wrap;margin:.6rem 0 1rem;">
      <button type="button" class="btn btn--outline soc-copy" data-platform="fb" style="font-size:.8rem;min-height:44px;">Копирай текста</button>
      <!-- The emoji goes into the article itself (the BG text), at the cursor. -->
      <button type="button" id="socEmojiBtn" class="btn btn--outline" style="font-size:.8rem;min-height:44px;" data-emoji-target="content" aria-controls="socEmojiPicker" aria-expanded="false" onclick="_toggleEmojiPicker(this)"><span aria-hidden="true">😊</span> Емоджи в статията</button>
    </div>
      <div id="socEmojiPicker" role="group" aria-label="Емоджи" style="display:none;background:#fff;border:1px solid var(--border);border-radius:8px;padding:.4rem;margin-bottom:.5rem;max-height:220px;overflow-y:auto;">
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
            echo '<button type="button" onclick="_insertEmoji(this.textContent)" style="width:44px;height:44px;padding:0;border:none;background:none;border-radius:4px;cursor:pointer;font-size:1.3rem;line-height:1;">' . $e . '</button>';
        }
        ?>
      </div>

    <div style="margin-bottom:.75rem;">
      <label for="fbScheduleAt" style="font-size:.85rem;font-weight:600;display:block;margin-bottom:.3rem;">Планирай за</label>
      <input type="datetime-local" id="fbScheduleAt"
             value="<?= h(!empty($article['fb_scheduled_at']) ? date('Y-m-d\TH:i', strtotime($article['fb_scheduled_at'])) : $default_social_at) ?>"
             style="font-size:.88rem;min-height:44px;">
    </div>
    <?= $draft_note ?>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.5rem;">
      <button type="button" id="socFbBtn" class="btn btn--primary" style="font-size:.85rem;min-height:44px;white-space:nowrap;background:#1877f2;border-color:#1877f2;">
        Планирай в Facebook
      </button>
      <button type="button" id="socFbNowBtn" class="btn btn--outline" style="font-size:.85rem;min-height:44px;white-space:nowrap;">
        Публикувай сега (FB)
      </button>
      <span id="socFbSpinner" style="display:none;font-size:.85rem;color:var(--text-muted);">Изпраща се…</span>
      <span id="socFbError" role="alert" style="display:none;font-size:.85rem;color:#c0392b;"></span>
    </div>
    <p style="margin:.25rem 0 0;font-size:.78rem;color:var(--text-muted);">
      Публикацията ще бъде планирана в Buffer. Редактирайте я в <a href="https://publish.buffer.com" target="_blank" rel="noopener">publish.buffer.com</a>.
    </p>
  </div>

  <!-- ── Instagram ─────────────────────────────────────────────────────────── -->
  <div role="tabpanel" id="socTabpanel-insta" aria-labelledby="socTab-insta" tabindex="0" hidden>
    <p role="status" style="margin:0 0 .5rem;font-size:.82rem;font-weight:600;display:flex;gap:.75rem;flex-wrap:wrap;">
      <span id="instaStatusBadge"><?php if ($insta_sched): ?><span style="color:#b45309;">⏱ IG: <?= h(date('d.m.Y H:i', strtotime($article['insta_scheduled_at']))) ?></span><?php endif; ?></span>
      <span id="instaStoryStatusBadge"><?php if ($insta_story_sched): ?><span style="color:#b45309;">⏱ IG Story: <?= h(date('d.m.Y H:i', strtotime($article['insta_story_scheduled_at']))) ?></span><?php endif; ?></span>
    </p>

    <div role="group" aria-label="Вид публикация в Instagram" style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">
      <button type="button" id="igModePostBtn" class="btn <?= $ig_default_story ? 'btn--outline' : 'btn--primary' ?>" aria-pressed="<?= $ig_default_story ? 'false' : 'true' ?>" style="font-size:.82rem;min-height:44px;">📷 Публикация</button>
      <button type="button" id="igModeStoryBtn" class="btn <?= $ig_default_story ? 'btn--primary' : 'btn--outline' ?>" aria-pressed="<?= $ig_default_story ? 'true' : 'false' ?>" style="font-size:.82rem;min-height:44px;">🎬 Story</button>
    </div>

    <style>.ig-crop-thumb:focus-visible{outline:3px solid #0387A5;outline-offset:2px;}.ig-crop-thumb:hover{border-color:#0387A5 !important;}</style>
    <?php foreach (['post' => 'igCropPost', 'story' => 'igCropStory'] as $_kind => $_id):
        $_shown = $_kind === 'story' ? $ig_default_story : !$ig_default_story;
        $_items = $ig_preview[$_kind];
        $_sched = $_kind === 'story' ? $insta_story_sched : $insta_sched; ?>
    <div id="<?= $_id ?>" data-kind="<?= $_kind ?>" style="<?= $_shown ? '' : 'display:none;' ?>margin-bottom:1rem;">
      <?php if (!$_items): ?>
        <p style="margin:0;font-size:.82rem;color:var(--text-muted);">Добавете снимка към статията и я запазете, за да видите как ще изглежда в Instagram.</p>
      <?php else: ?>
        <?php if ($_kind === 'post'): ?>
        <div role="group" aria-label="Форма на публикацията в Instagram" style="display:flex;flex-wrap:wrap;align-items:center;gap:.4rem;margin-bottom:.6rem;">
          <span aria-hidden="true" style="font-size:.82rem;font-weight:600;margin-right:.2rem;">Форма:</span>
          <?php foreach (['auto' => 'Автоматично', 'landscape' => '▭ Хоризонтална', 'square' => '□ Квадрат', 'portrait' => '▯ Вертикална'] as $_sv => $_sl):
              $_on = $ig_preview['shape'] === $_sv; ?>
          <button type="button" class="ig-shape-btn btn <?= $_on ? 'btn--primary' : 'btn--outline' ?>" data-shape="<?= $_sv ?>" aria-pressed="<?= $_on ? 'true' : 'false' ?>"
                  style="font-size:.78rem;min-height:44px;padding:.3rem .7rem;"><?= h($_sl) ?></button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div role="list" aria-label="<?= $_kind === 'story' ? 'Снимка за Instagram Story' : 'Снимки за публикацията в Instagram' ?>" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;margin-bottom:.4rem;">
          <?php foreach ($_items as $_i => $_p):
              $_h  = $_kind === 'story' ? 128 : 104;
              $_w  = (int) round($_h * $_p['ar']);
              $_r  = $_p['rect'];
              $_lbl = $_kind === 'story'
                  ? 'Главната снимка в Story. Натиснете, за да изберете коя част да се вижда.'
                  : 'Снимка ' . ($_i + 1) . ' от ' . count($_items) . ($_i === 0 ? ' (главна)' : '') . '. Натиснете, за да изберете коя част да се вижда.';
              $_fit = $_p['fit'] ?? 'fill'; ?>
          <div role="listitem" style="display:flex;flex-direction:column;align-items:center;gap:.2rem;">
            <button type="button" class="ig-crop-thumb" aria-label="<?= h($_lbl) ?>"
                    data-src="<?= h($_p['src']) ?>" data-w="<?= (int) $_p['w'] ?>" data-h="<?= (int) $_p['h'] ?>" data-ar="<?= h((string) $_p['ar']) ?>" data-rect="<?= h(json_encode($_r)) ?>" data-fit="<?= h($_fit) ?>" data-fill-rect="<?= h(json_encode($_p['fill_rect'] ?? $_r)) ?>"
                    style="display:block;padding:0;margin:0;border:2px solid var(--border,#e8ddd5);border-radius:6px;background:#f3efe9;cursor:pointer;width:<?= $_w + 4 ?>px;height:<?= $_h + 4 ?>px;overflow:hidden;">
              <span style="display:block;position:relative;width:<?= $_w ?>px;height:<?= $_h ?>px;overflow:hidden;background:#fff;">
                <img src="<?= h($_p['src']) ?>" alt="" loading="lazy" draggable="false"
                     style="position:absolute;display:block;max-width:none;margin:0;width:<?= round(100 / $_r['w'], 4) ?>%;height:<?= round(100 / $_r['h'], 4) ?>%;left:<?= round(-100 * $_r['x'] / $_r['w'], 4) ?>%;top:<?= round(-100 * $_r['y'] / $_r['h'], 4) ?>%;">
              </span>
            </button>
            <span aria-hidden="true" style="font-size:.72rem;color:var(--text-muted);"><?= $_kind === 'story' ? '9:16' : ($_i === 0 ? 'Главна' : (string) ($_i + 1)) ?></span>
            <?php if ($_kind === 'post'): ?>
            <button type="button" class="ig-fit-btn btn <?= $_fit === 'whole' ? 'btn--primary' : 'btn--outline' ?>" aria-pressed="<?= $_fit === 'whole' ? 'true' : 'false' ?>"
                    aria-label="Снимка <?= $_i + 1 ?>: покажи цялата снимка, с бяла рамка" data-src="<?= h($_p['src']) ?>"
                    style="font-size:.72rem;min-height:44px;padding:.2rem .55rem;white-space:nowrap;"><span class="ig-fit-tick" aria-hidden="true"><?= $_fit === 'whole' ? '✓ ' : '' ?></span>Цялата снимка</button>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <p style="margin:0;font-size:.8rem;color:var(--text-muted);">Натиснете снимка, за да изберете коя част да се вижда в Instagram.<?php if ($_kind === 'post'): ?> „Цялата снимка“ я показва цяла, с бяла рамка отстрани — като „Mixed“ в Instagram.<?php endif; ?></p>
        <p class="ig-crop-sched-note" style="<?= $_sched ? '' : 'display:none;' ?>margin:.25rem 0 0;font-size:.8rem;color:#92400e;">
          <?= $_kind === 'story' ? 'Story-то вече е планирано.' : 'Публикацията вече е планирана.' ?> Ако промените изрязването, натиснете отново „Планирай в Instagram“, за да стигне промяната до Instagram.
        </p>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <p id="igCropStatus" role="status" aria-live="polite" style="margin:0 0 .75rem;font-size:.82rem;color:#2e7d32;min-height:0;"></p>
    <p id="igCropError" role="alert" style="display:none;margin:0 0 .75rem;font-size:.82rem;color:#c0392b;"></p>

    <div id="igPostTextGroup" style="<?= $ig_default_story ? 'display:none;' : '' ?>margin-bottom:1rem;">
      <div style="border:1px solid var(--border);border-radius:8px;padding:.85rem;background:#fafafa;margin-bottom:.4rem;">
        <div id="socPreview-insta" style="white-space:pre-wrap;overflow-wrap:anywhere;font-size:.88rem;line-height:1.55;">Зарежда се прегледът…</div>
      </div>
      <p id="socCount-insta" style="margin:0;font-size:.8rem;color:var(--text-muted);font-variant-numeric:tabular-nums;"></p>
      <p id="socCut-insta" hidden style="margin:.25rem 0 0;font-size:.8rem;font-weight:600;color:#92400e;"><span aria-hidden="true">✂</span> Текстът е съкратен до последното цяло изречение</p>
      <div data-emoji-anchor style="display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.6rem;">
        <button type="button" class="btn btn--outline soc-copy" data-platform="insta" style="font-size:.8rem;min-height:44px;">Копирай текста</button>
        <button type="button" id="socEmojiBtnInsta" class="btn btn--outline" style="font-size:.8rem;min-height:44px;" data-emoji-target="content" aria-controls="socEmojiPicker" aria-expanded="false" onclick="_toggleEmojiPicker(this)"><span aria-hidden="true">😊</span> Емоджи в статията</button>
      </div>
    </div>

    <p id="igStoryNoteGroup" style="<?= $ig_default_story ? '' : 'display:none;' ?>margin:0 0 1rem;font-size:.82rem;color:var(--text-muted);">
      Instagram Story се публикува само с главната снимка на статията, изрязана вертикално (9:16). Instagram не позволява текст или линк върху Story, публикувано автоматично.
    </p>

    <div style="margin-bottom:.75rem;<?= $ig_default_story ? 'display:none;' : '' ?>" id="igPostScheduleGroup">
      <label for="instaScheduleAt" style="font-size:.85rem;font-weight:600;display:block;margin-bottom:.3rem;">Планирай за</label>
      <input type="datetime-local" id="instaScheduleAt"
             value="<?= h(!empty($article['insta_scheduled_at']) ? date('Y-m-d\TH:i', strtotime($article['insta_scheduled_at'])) : $default_social_at) ?>"
             style="font-size:.88rem;min-height:44px;">
    </div>
    <div style="margin-bottom:.75rem;<?= $ig_default_story ? '' : 'display:none;' ?>" id="igStoryScheduleGroup">
      <label for="instaStoryScheduleAt" style="font-size:.85rem;font-weight:600;display:block;margin-bottom:.3rem;">Планирай Story за</label>
      <input type="datetime-local" id="instaStoryScheduleAt"
             value="<?= h(!empty($article['insta_story_scheduled_at']) ? date('Y-m-d\TH:i', strtotime($article['insta_story_scheduled_at'])) : $default_social_at) ?>"
             style="font-size:.88rem;min-height:44px;">
    </div>
    <?= $draft_note ?>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.5rem;">
      <button type="button" id="socInstaBtn" class="btn btn--primary" style="font-size:.85rem;min-height:44px;white-space:nowrap;background:linear-gradient(135deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888);border:none;">
        Планирай в Instagram
      </button>
      <button type="button" id="socInstaNowBtn" class="btn btn--outline" style="font-size:.85rem;min-height:44px;white-space:nowrap;">
        Публикувай сега (IG)
      </button>
      <span id="socInstaSpinner" style="display:none;font-size:.85rem;color:var(--text-muted);">Изпраща се…</span>
      <span id="socInstaError" role="alert" style="display:none;font-size:.85rem;color:#c0392b;"></span>
    </div>
    <p style="margin:.25rem 0 0;font-size:.78rem;color:var(--text-muted);">
      Публикацията ще бъде планирана в Buffer. Редактирайте я в <a href="https://publish.buffer.com" target="_blank" rel="noopener">publish.buffer.com</a>.
    </p>
  </div>

  <!-- ── LinkedIn (the English version) ────────────────────────────────────── -->
  <div role="tabpanel" id="socTabpanel-linkedin" aria-labelledby="socTab-linkedin" tabindex="0" hidden>
    <p id="liStatusBadge" role="status" style="margin:0 0 .5rem;font-size:.82rem;font-weight:600;">
      <?php if ($li_posted): ?>
        <span style="color:#2e7d32;">✓ Публикувано <?= h(date('d.m.Y', strtotime($article['linkedin_posted_at']))) ?>
        <?php if (!empty($article['linkedin_url'])): ?>
          — <a href="<?= h($article['linkedin_url']) ?>" target="_blank" rel="noopener" style="color:#0a66c2;">Виж →</a>
        <?php endif; ?>
        </span>
      <?php elseif ($li_scheduled): ?>
        <span style="color:#b45309;">⏱ Планирано за <?= h(date('d.m.Y H:i', strtotime($article['linkedin_scheduled_at']))) ?></span>
      <?php endif; ?>
    </p>
    <p style="margin:0 0 .5rem;font-size:.8rem;color:var(--text-muted);">LinkedIn получава английската версия на статията.</p>
    <div style="border:1px solid var(--border);border-radius:8px;padding:.85rem;background:#fafafa;margin-bottom:.4rem;">
      <div id="socPreview-linkedin" style="white-space:pre-wrap;overflow-wrap:anywhere;font-size:.88rem;line-height:1.55;">Зарежда се прегледът…</div>
      <img class="soc-photo" alt="Главната снимка на статията" style="display:none;width:100%;height:auto;margin-top:.75rem;border-radius:6px;">
    </div>
    <div id="socEnEmpty" hidden style="margin:.5rem 0;">
      <p style="margin:0 0 .5rem;font-size:.85rem;font-weight:600;"><span aria-hidden="true">ℹ</span> Няма английски текст — преведете статията</p>
      <button type="button" id="socTranslateBtn" class="btn btn--outline" style="font-size:.82rem;min-height:44px;"><?= $deepl_ready ? '✦ Преведи на английски' : 'Към английската версия' ?></button>
    </div>
    <p id="socCount-linkedin" style="margin:0;font-size:.8rem;color:var(--text-muted);font-variant-numeric:tabular-nums;"></p>
    <p id="socCut-linkedin" hidden style="margin:.25rem 0 0;font-size:.8rem;font-weight:600;color:#92400e;"><span aria-hidden="true">✂</span> Текстът е съкратен до последното цяло изречение</p>
    <div data-emoji-anchor style="display:flex;gap:.4rem;flex-wrap:wrap;margin:.6rem 0 1rem;">
      <button type="button" class="btn btn--outline soc-copy" data-platform="linkedin" style="font-size:.8rem;min-height:44px;">Копирай текста</button>
      <!-- LinkedIn is the English version, so its emoji go into the EN text. -->
      <button type="button" id="socEmojiBtnLi" class="btn btn--outline" style="font-size:.8rem;min-height:44px;" data-emoji-target="content_en" aria-controls="socEmojiPicker" aria-expanded="false" onclick="_toggleEmojiPicker(this)"><span aria-hidden="true">😊</span> Емоджи в английския текст</button>
    </div>

    <div style="margin-bottom:.75rem;">
      <label for="liScheduleAt" style="font-size:.85rem;font-weight:600;display:block;margin-bottom:.3rem;">Планирай за</label>
      <input type="datetime-local" id="liScheduleAt"
             value="<?= h(!empty($article['linkedin_scheduled_at']) ? date('Y-m-d\TH:i', strtotime($article['linkedin_scheduled_at'])) : date('Y-m-d\TH:i', strtotime('+1 day 09:00'))) ?>"
             style="font-size:.88rem;min-height:44px;">
    </div>
    <?= $draft_note ?>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
      <button type="button" id="liScheduleBtn" class="btn btn--primary" style="font-size:.85rem;min-height:44px;white-space:nowrap;">
        Планирай в LinkedIn
      </button>
      <button type="button" id="liNowBtn" class="btn btn--outline" style="font-size:.85rem;min-height:44px;white-space:nowrap;">
        Публикувай сега (LinkedIn)
      </button>
      <span id="liScheduleSpinner" style="display:none;font-size:.85rem;color:var(--text-muted);">Изпраща се…</span>
      <span id="liScheduleError" role="alert" style="display:none;font-size:.85rem;color:#c0392b;"></span>
    </div>
    <p style="margin:.4rem 0 0;font-size:.78rem;color:var(--text-muted);">
      Публикацията ще бъде планирана в Buffer за избрания час. Можете да я редактирате в <a href="https://publish.buffer.com" target="_blank" rel="noopener">publish.buffer.com</a>.
    </p>
  </div>
</aside>
<?php endif; // $social_on ?>
</div><!-- /#editorLayout -->

<div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
  <button type="submit" form="articleForm" class="btn btn--primary">Запази статията</button>
</div>

<script>
// ── Shared helpers ──────────────────────────────────────────────────────────────
function _tinyGet(id) {
  var ed = tinymce.get(id);
  if (ed) return ed.getContent();
  var el = document.getElementById(id);
  return el ? el.value : '';
}
function _tinySet(id, html) {
  var ed = tinymce.get(id);
  if (ed) { ed.setContent(html); return; }
  var el = document.getElementById(id);
  if (el) el.value = html;
}

document.getElementById('tagsInput').addEventListener('input', function () {
  document.getElementById('tagsHidden').value = this.value;
});

// ── Photo grid ───────────────────────────────────────────────────────────────
// Each card carries a data-key that never changes, and its English caption is matched
// to the card by that key — not by the photo's path, which changes when a photo is
// cropped and can repeat when the same library picture is added twice.
(function () {
  var MAX = 10;
  var MAX_BYTES = <?= (int)upload_max_bytes() ?>, MAX_LABEL = <?= json_encode(upload_max_label(), JSON_UNESCAPED_UNICODE) ?>;
  var OK_PATH = /^\/assets\/images\/[a-zA-Z0-9_\-][a-zA-Z0-9\/_.\-]*$/;   // same rule as article_photo_path_ok()
  var grid = document.getElementById('apGrid'), tpl = document.getElementById('apRowTpl');
  var enList = document.getElementById('apEnCaps'), mainInput = document.getElementById('apMain');
  var live = document.getElementById('apLive'), limit = document.getElementById('apLimit'), err = document.getElementById('apError');
  var form = document.getElementById('articleForm');
  var csrf = form.querySelector('input[name="csrf_token"]').value;
  var nextKey = 1000, uploading = 0;

  function rows() { return Array.prototype.slice.call(grid.querySelectorAll('.ap-photo')); }
  function isMain(r) { return r.querySelector('.ap-main').getAttribute('aria-pressed') === 'true'; }
  function say(msg) { live.textContent = ''; setTimeout(function () { live.textContent = msg; }, 50); }
  function showError(msg) { err.textContent = msg; err.hidden = !msg; }

  function setMain(row) {
    rows().forEach(function (r) {
      var on = r === row, b = r.querySelector('.ap-main');
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.className = 'btn ' + (on ? 'btn--primary' : 'btn--outline') + ' ap-main';
      b.innerHTML = on ? '<span aria-hidden="true">★</span> Основна снимка' : 'Направи основна';
      r.style.border = on ? '3px solid var(--teal)' : '1px solid var(--border)';
    });
  }

  // Renumber labels, rebuild the EN captions in card order, record the main photo's index.
  function sync() {
    var rs = rows(), oldEn = {};
    enList.querySelectorAll('li').forEach(function (li) { oldEn[li.dataset.key] = li.querySelector('input').value; });
    enList.innerHTML = '';
    if (rs.length && !rs.some(isMain)) setMain(rs[0]);
    var mainIdx = 0;
    rs.forEach(function (r, i) {
      var n = i + 1, id = 'photo_caption_bg' + n + '_' + r.dataset.key;
      var cap = r.querySelector('input[name^="photo_caption_bg"]'), lab = r.querySelector('label');
      cap.id = id; cap.name = 'photo_caption_bg[' + i + ']'; lab.htmlFor = id; lab.textContent = 'Надпис под снимка ' + n;
      r.querySelector('.ap-main').setAttribute('aria-label', 'Направи снимка ' + n + ' основна');
      r.querySelector('.ap-up').setAttribute('aria-label', 'Премести снимка ' + n + ' нагоре');
      r.querySelector('.ap-down').setAttribute('aria-label', 'Премести снимка ' + n + ' надолу');
      r.querySelector('.ap-crop').setAttribute('aria-label', 'Изрежи снимка ' + n);
      r.querySelector('.ap-remove').setAttribute('aria-label', 'Премахни снимка ' + n);
      if (isMain(r)) mainIdx = i;
      var li = document.createElement('li');
      li.dataset.key = r.dataset.key; li.style.marginBottom = '.5rem';
      li.innerHTML = '<label for="photoCapEn' + n + '">Caption under photo ' + n + '</label>'
        + '<input type="text" id="photoCapEn' + n + '" name="photo_caption_en[' + i + ']" maxlength="300"'
        + ' data-translate-from="photo_caption_bg[' + i + ']" style="width:100%;box-sizing:border-box;min-height:44px;">';
      li.querySelector('input').value = oldEn[r.dataset.key] || '';
      enList.appendChild(li);
    });
    mainInput.value = mainIdx;
    limit.hidden = rs.length < MAX;
  }

  function addRow(path) {
    if (rows().length >= MAX) { limit.hidden = false; return false; }
    var holder = document.createElement('div');
    holder.innerHTML = tpl.innerHTML.split('__SRC__').join(path);
    var row = holder.querySelector('.ap-photo');
    row.dataset.key = 'n' + (nextKey++);
    row.querySelector('.ap-main').setAttribute('aria-pressed', 'false');
    row.style.border = '1px solid var(--border)';
    row.draggable = true;
    grid.appendChild(row);
    return true;
  }

  // Rejects with the reason (size | type | server) so the summary can say what went wrong.
  // label: „Снимка 2 от 5“ when several go up, shown on the progress bar.
  function upload(file, label) {
    if (MAX_BYTES && file.size > MAX_BYTES) return Promise.reject('size');
    var fd = new FormData();
    fd.append('image', file); fd.append('section', 'article'); fd.append('csrf_token', csrf);
    return OMUpload.send('/admin/inline-upload.php', fd, { into: document.getElementById('apProgress'), label: label })
      .then(function (d) { if (!d.ok) throw (d.reason || 'server'); return d.path; },
            function (e) { throw (typeof e === 'string' ? e : 'server'); });
  }

  function failMessage(why) {
    var parts = [];
    if (why.size) parts.push(why.size === 1
      ? '1 снимка е твърде голяма — най-много ' + MAX_LABEL + '. Изберете по-малка снимка.'
      : why.size + ' снимки са твърде големи — най-много ' + MAX_LABEL + ' всяка. Изберете по-малки снимки.');
    if (why.type) parts.push(why.type === 1
      ? '1 файл не е снимка във формат JPEG, PNG или WebP.'
      : why.type + ' файла не са снимки във формат JPEG, PNG или WebP.');
    if (why.server) parts.push(why.server === 1
      ? '1 снимка не можа да се качи. Опитайте отново.'
      : why.server + ' снимки не можаха да се качат. Опитайте отново.');
    return parts.join(' ');
  }

  // Saving while photos are still uploading would silently leave them out.
  form.addEventListener('submit', function (e) {
    if (uploading > 0) {
      e.preventDefault();
      showError('Изчакайте снимките да се качат, после запазете.');
    }
  });

  document.getElementById('apFiles').addEventListener('change', function () {
    var files = Array.prototype.slice.call(this.files), room = MAX - rows().length;
    this.value = '';
    if (files.length > room) limit.hidden = false;
    files = files.slice(0, Math.max(0, room));
    if (!files.length) return;
    showError('');
    var failed = 0, done = 0, why = { size: 0, type: 0, server: 0 };
    uploading += files.length;
    say('Качване на ' + files.length + (files.length === 1 ? ' снимка…' : ' снимки…'));
    files.reduce(function (p, f) {
      return p.then(function () {
        var label = files.length > 1 ? 'Снимка ' + (done + 1) + ' от ' + files.length : '';
        return upload(f, label).then(function (path) { addRow(path); }, function (r) { failed++; why[why.hasOwnProperty(r) ? r : 'server']++; })
          .then(function () { uploading--; done++; if (done < files.length) say('Качване: ' + done + ' от ' + files.length); });
      });
    }, Promise.resolve()).then(function () {
      sync();
      var ok = files.length - failed;
      say(ok === 1 ? 'Добавена е 1 снимка' : 'Добавени са ' + ok + ' снимки');
      if (failed) showError(failMessage(why));
      else if (err.textContent.indexOf('Изчакайте') === 0) showError('');
    });
  });

  document.getElementById('apLibrary').addEventListener('click', function () {
    if (rows().length >= MAX) { limit.hidden = false; return; }
    openMediaPicker(function (p) {
      if (!OK_PATH.test(p) || p.indexOf('..') >= 0) {
        showError('Тази снимка не може да се използва — името на файла съдържа кирилица или интервали. Качете я отново с „Добави снимки“.');
        return;
      }
      showError('');
      if (addRow(p)) { sync(); say('Снимката е добавена'); }
    });
  });

  grid.addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    var row = b.closest('.ap-photo'), rs = rows(), i = rs.indexOf(row);
    if (b.classList.contains('ap-main')) {
      // The main photo moves to the front, so the order on screen is the order readers see.
      if (i > 0) grid.insertBefore(row, rs[0]);
      setMain(row); sync(); b.focus();
      say(i > 0 ? 'Снимка ' + (i + 1) + ' е основна и е преместена на първо място' : 'Снимка 1 е основна');
    } else if (b.classList.contains('ap-up') && i > 0) {
      grid.insertBefore(row, rs[i - 1]); sync(); b.focus(); say('Снимка е преместена на място ' + i);
    } else if (b.classList.contains('ap-down') && i < rs.length - 1) {
      grid.insertBefore(rs[i + 1], row); sync(); b.focus(); say('Снимка е преместена на място ' + (i + 2));
    } else if (b.classList.contains('ap-remove')) {
      var next = rs[i + 1] || rs[i - 1], wasMain = isMain(row);
      row.remove();
      if (wasMain && next) setMain(next);   // the next photo becomes main, as the author would expect
      sync();
      say('Снимка ' + (i + 1) + ' е премахната' + (wasMain && next ? '. Основна е снимка ' + (rows().indexOf(next) + 1) : ''));
      (next ? next.querySelector('.ap-remove') : document.getElementById('apLibrary')).focus();
    } else if (b.classList.contains('ap-crop')) {
      fetch(row.dataset.src).then(function (r) { return r.blob(); })
        .then(function (blob) { return OMCrop.open(new File([blob], 'photo.' + (blob.type.split('/')[1] || 'jpg'), { type: blob.type })); })
        .then(function (cropped) {
          if (!cropped) return;
          uploading++;
          return upload(cropped).then(function (path) {
            row.dataset.src = path;
            row.querySelector('input[name="photo_src[]"]').value = path;
            row.querySelector('img').src = path;
            sync(); say('Снимка ' + (i + 1) + ' е изрязана');
          }).finally(function () { uploading--; });
        })
        .catch(function () { showError('Снимката не можа да се изреже. Опитайте отново.'); });
    }
  });

  // Drag to reorder (mouse); ↑/↓ above are the keyboard way. preventDefault on drop
  // stops Firefox from opening the dragged image and leaving the editor.
  var dragged = null;
  grid.addEventListener('dragstart', function (e) {
    dragged = e.target.closest('.ap-photo');
    if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); }
  });
  grid.addEventListener('dragover', function (e) { e.preventDefault(); });
  grid.addEventListener('drop', function (e) {
    e.preventDefault();
    var over = e.target.closest('.ap-photo');
    if (dragged && over && over !== dragged) {
      var rs = rows();
      grid.insertBefore(dragged, rs.indexOf(dragged) < rs.indexOf(over) ? over.nextSibling : over);
      sync(); say('Редът на снимките е променен');
    }
    dragged = null;
  });
  rows().forEach(function (r) { r.draggable = true; });
})();

// ── TinyMCE rich text editors ─────────────────────────────────────────────────
tinymce.init(Object.assign({}, window._tinyBase, { selector: 'textarea.rich-editor', min_height: 300 }));
// Kept so the Socials panel's background save can clear the draft (social-panel.js).
window._articleAutosave = initAutosave({
  key:     <?= json_encode('article:' . ($is_new ? 'new' : $slug_param)) ?>,
  formId:  'articleForm',
  tinyIds: ['content', 'content_en']
});

// ── Collapsible EN section — collapsed by default, remembers the last state ────
// Its editor starts only when the section is first opened.
var _expandEnSection = (function () {
  var wrap       = document.getElementById('enFieldsWrap');
  var toggleBtn  = document.getElementById('enToggleBtn');
  var storageKey = 'enSectionOpen:' + <?= json_encode($is_new ? 'new' : $slug_param) ?>;
  var enTinyInited = false;

  function initEnTiny() {
    if (enTinyInited) return;
    enTinyInited = true;
    tinymce.init(Object.assign({}, window._tinyBase, { selector: '#content_en', min_height: 300 }));
  }

  function setOpen(open) {
    wrap.style.display = open ? '' : 'none';
    toggleBtn.textContent = open ? '▴ Скрий' : '▾ Покажи';
    toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) initEnTiny();
    try { localStorage.setItem(storageKey, open ? '1' : '0'); } catch (e) {}
  }

  toggleBtn.addEventListener('click', function () {
    setOpen(wrap.style.display === 'none');
  });

  // Open when it was left open, or when an unsaved draft exists — a restored draft
  // must land in a visible, running editor, not in a hidden textarea.
  var savedOpen = false;
  try {
    savedOpen = localStorage.getItem(storageKey) === '1'
      || localStorage.getItem('autosave:' + <?= json_encode('article:' . ($is_new ? 'new' : $slug_param)) ?>) !== null;
  } catch (e) {}
  setOpen(savedOpen);

  return function () { setOpen(true); };
})();

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
        csrf_token: '<?= csrf_token() ?>',
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
    _expandEnSection();

    var bgTitle   = document.getElementById('title').value;
    var bgContent = _tinyGet('content');

    async function tx(text, isHtml) {
      if (!text.trim()) return '';
      var r = await fetch('/admin/translate-ajax.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({text: text, is_html: isHtml, csrf_token: window._csrfToken})
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


<?php if ($social_on): ?>
// ── Emoji picker (Socials panel) ────────────────────────────────────────────────
// The post is the article, so an emoji goes into the article at the cursor: the BG
// text for Facebook/Instagram (data-emoji-target="content"), the EN text for LinkedIn
// ("content_en"). It then shows on the website too, and the previews refresh.
(function () {
  var _emojiTarget = 'content';
  var _emojiOpener = null;

  function _closeEmojiPicker(returnFocus) {
    var p = document.getElementById('socEmojiPicker');
    p.style.display = 'none';
    document.querySelectorAll('[data-emoji-target]').forEach(function(b) { b.setAttribute('aria-expanded', 'false'); });
    if (returnFocus && _emojiOpener) _emojiOpener.focus();
  }

  function _toggleEmojiPicker(btn) {
    var p          = document.getElementById('socEmojiPicker');
    var targetId   = btn.getAttribute('data-emoji-target');
    var isOpenHere = p.style.display !== 'none' && _emojiTarget === targetId;
    _closeEmojiPicker(false);
    if (isOpenHere) return;
    _emojiTarget = targetId;
    _emojiOpener = btn;
    // Move the shared picker right after the row of the emoji button that was clicked.
    btn.closest('[data-emoji-anchor]').insertAdjacentElement('afterend', p);
    p.style.display = 'block';
    btn.setAttribute('aria-expanded', 'true');
  }

  // Escape closes the picker and hands focus back to its button.
  document.getElementById('socEmojiPicker').addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { e.preventDefault(); _closeEmojiPicker(true); }
  });

  // Runs fn(editor) once the editor is ready. The EN editor starts only when its
  // section opens, so LinkedIn's emoji opens that section first.
  function _withEditor(id, fn) {
    var ed = window.tinymce && tinymce.get(id);
    if (ed && ed.initialized) { fn(ed); return; }
    if (!window.tinymce) return;
    if (ed) { ed.once('init', function () { fn(ed); }); return; }
    var onAdd = function (e) {
      if (e.editor.id !== id) return;
      tinymce.off('AddEditor', onAdd);
      e.editor.once('init', function () { fn(e.editor); });
    };
    tinymce.on('AddEditor', onAdd);
    if (id === 'content_en' && typeof window._expandEnSection === 'function') window._expandEnSection();
  }

  function _insertEmoji(emoji) {
    _withEditor(_emojiTarget, function (ed) {
      ed.insertContent(emoji);
      if (window.SocialPanel) window.SocialPanel.refresh();
    });
  }

  // Exposed on window: the emoji buttons call these via inline onclick=
  // attributes, which run in global scope and can't see IIFE-local functions.
  window._toggleEmojiPicker = _toggleEmojiPicker;
  window._insertEmoji       = _insertEmoji;
})();
<?php endif; ?>
</script>
<?php if ($social_on): ?>
<script src="<?= h(versioned_asset('/admin/js/social-panel.js')) ?>"></script>
<?php endif; ?>
<script>
// „Публикувай автоматично“ only applies to drafts.
(function () {
  var status = document.getElementById('status'), box = document.getElementById('scheduled');
  if (!status || !box) return;
  function sync() {
    var draft = status.value === 'draft';
    box.disabled = !draft;
    if (!draft) box.checked = false;
    document.getElementById('scheduledRow').style.opacity = draft ? '1' : '.5';
  }
  status.addEventListener('change', sync);
  sync();
})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/translator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pages_admin.php';
$active_nav = 'pages';

admin_require_login();
admin_require_admin();

$page = $_GET['page'] ?? '';

$page_labels = [
    'home'          => 'Начална страница',
    'impact'        => 'Показатели (числа)',
    'centres'       => 'Центрове',
    'about'         => 'За нас',
    'projects'      => 'Проекти',
    'how_to_help'   => 'Как да помогна',
    'shop'          => 'Магазин — текст за дарения',
    'legal_privacy' => 'Политика за поверителност',
    'legal_info'    => 'Правна информация',
    'legal_terms'   => 'Условия за ползване',
];

$page_urls = [
    'home'          => '/',
    'impact'        => '/',
    'centres'       => '/',
    'about'         => '/za-nas/',
    'projects'      => '/proekti/',
    'how_to_help'   => '/kak-da-pomogna/',
    'shop'          => '/magazin/',
    'legal_privacy' => '/politika-za-poveritelnost/',
    'legal_info'    => '/pravna-informaciya/',
    'legal_terms'   => '/usloviya/',
];

$page_title_admin = $page && isset($page_labels[$page])
    ? 'Съдържание — ' . $page_labels[$page]
    : 'Съдържание';

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $section = $_POST['section'] ?? '';

    if ($section === 'home') {
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $pages['home']['hero_title']       = trim($_POST['hero_title']       ?? '');
        $pages['home']['hero_text']        = trim($_POST['hero_text']        ?? '');
        $pages['home']['mission_title']    = trim($_POST['mission_title']    ?? '');
        $pages['home']['mission_text']     = trim($_POST['mission_text']     ?? '');
        $pages['home']['hero_title_en']    = trim($_POST['hero_title_en']    ?? '');
        $pages['home']['hero_text_en']     = trim($_POST['hero_text_en']     ?? '');
        $pages['home']['mission_title_en'] = trim($_POST['mission_title_en'] ?? '');
        $pages['home']['mission_text_en']  = trim($_POST['mission_text_en']  ?? '');

        // Mission image upload
        $mission_img_key = 'mission_image';
        if (!empty($_FILES[$mission_img_key]['tmp_name']) && $_FILES[$mission_img_key]['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $ftype   = mime_content_type($_FILES[$mission_img_key]['tmp_name']);
            if (isset($allowed[$ftype])) {
                $img_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/pages/';
                if (!is_dir($img_dir)) mkdir($img_dir, 0755, true);
                $filename = 'mission.' . $allowed[$ftype];
                if (move_uploaded_file($_FILES[$mission_img_key]['tmp_name'], $img_dir . $filename)) {
                    $pages['home']['mission_image'] = '/assets/images/pages/' . $filename;
                }
            }
        } elseif (!empty($_POST['mission_image_lib'])) {
            $lib = $_POST['mission_image_lib'];
            if (preg_match('#^/assets/images/[a-zA-Z0-9/_.\-]+$#', $lib)) {
                $pages['home']['mission_image'] = $lib;
            }
        } elseif (($_POST['mission_image_clear'] ?? '') === '1') {
            $pages['home']['mission_image'] = '';
        }

        save_json(CONTENT_PATH . '/pages.json', $pages);
        header('Location: /admin/pages.php?page=home&saved=1'); exit;

    } elseif ($section === 'campaign') {
        $url = trim($_POST['campaign_url'] ?? '');
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            header('Location: /admin/pages.php?page=home&campaign_error=1'); exit;
        }
        setting_set('campaign_url', $url);

        $pages = load_json(CONTENT_PATH . '/pages.json');
        $pages['campaign']['title']      = trim($_POST['campaign_title']      ?? '');
        $pages['campaign']['text']       = trim($_POST['campaign_text']       ?? '');
        $pages['campaign']['cta']        = trim($_POST['campaign_cta']        ?? '');
        $pages['campaign']['title_en']   = trim($_POST['campaign_title_en']   ?? '');
        $pages['campaign']['text_en']    = trim($_POST['campaign_text_en']    ?? '');
        $pages['campaign']['cta_en']     = trim($_POST['campaign_cta_en']     ?? '');

        // Image upload
        $img_key = 'campaign_image';
        if (!empty($_FILES[$img_key]['tmp_name']) && $_FILES[$img_key]['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $ftype   = mime_content_type($_FILES[$img_key]['tmp_name']);
            if (isset($allowed[$ftype])) {
                $img_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/pages/';
                if (!is_dir($img_dir)) mkdir($img_dir, 0755, true);
                $filename = 'campaign.' . $allowed[$ftype];
                if (move_uploaded_file($_FILES[$img_key]['tmp_name'], $img_dir . $filename)) {
                    $pages['campaign']['image'] = '/assets/images/pages/' . $filename;
                }
            }
        } elseif (!empty($_POST['campaign_image_lib'])) {
            $lib = $_POST['campaign_image_lib'];
            if (preg_match('#^/assets/images/[a-zA-Z0-9/_.\-]+$#', $lib)) {
                $pages['campaign']['image'] = $lib;
            }
        }

        save_json(CONTENT_PATH . '/pages.json', $pages);
        header('Location: /admin/pages.php?page=home&saved=1'); exit;

    } elseif ($section === 'impact_delete') {
        $items = pages_list_delete(load_json(IMPACT_FILE), (int)($_POST['item_index'] ?? -1));
        if ($items !== null) {
            save_json(IMPACT_FILE, $items);
            flash_set('success', 'Записът е изтрит.');
        }
        header('Location: /admin/pages.php?page=impact');
        exit;

    } elseif ($section === 'impact_save') {
        $item = [
            'number'   => trim($_POST['impact_number']   ?? ''),
            'label_bg' => trim($_POST['impact_label_bg'] ?? ''),
            'label_en' => trim($_POST['impact_label_en'] ?? ''),
        ];
        $items = pages_list_upsert(load_json(IMPACT_FILE), $_POST['item_index'] ?? 'new', $item);
        save_json(IMPACT_FILE, $items);
        header('Location: /admin/pages.php?page=impact&saved=1');
        exit;

    } elseif ($section === 'centres') {
        $centres         = load_json(CENTRES_FILE);
        $centre_ids      = $_POST['centre_id']      ?? [];
        $centre_names    = $_POST['centre_name_bg'] ?? [];
        $centre_descs    = $_POST['centre_desc_bg'] ?? [];
        $centre_names_en = $_POST['centre_name_en'] ?? [];
        $centre_descs_en = $_POST['centre_desc_en'] ?? [];

        $indexed = [];
        foreach ($centres as $c) { $indexed[$c['id']] = $c; }

        foreach ($centre_ids as $i => $id) {
            if (!isset($indexed[$id])) continue;
            $indexed[$id]['name_bg']        = trim($centre_names[$i]    ?? '');
            $indexed[$id]['description_bg'] = trim($centre_descs[$i]    ?? '');
            $indexed[$id]['name_en']        = trim($centre_names_en[$i] ?? '');
            $indexed[$id]['description_en'] = trim($centre_descs_en[$i] ?? '');

            $file_key = 'centre_image_' . $id;
            if (!empty($_FILES[$file_key]['tmp_name']) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES[$file_key]['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','webp'])) {
                    $dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/centres/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $filename = $id . '.' . $ext;
                    if (move_uploaded_file($_FILES[$file_key]['tmp_name'], $dir . $filename)) {
                        $indexed[$id]['image'] = '/assets/images/centres/' . $filename;
                    }
                }
            } elseif (!empty($_POST['centre_image_lib_' . $id])) {
                $lib = $_POST['centre_image_lib_' . $id];
                if (preg_match('#^/assets/images/[a-zA-Z0-9/_.\-]+$#', $lib)) {
                    $indexed[$id]['image'] = $lib;
                }
            }
        }
        save_json(CENTRES_FILE, array_values($indexed));
        header('Location: /admin/pages.php?page=centres&saved=1'); exit;

    } elseif ($section === 'about') {
        // Saves title and intro only — team is managed by team_save / team_delete
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $pages['about']['title']    = trim($_POST['about_title']    ?? '');
        $pages['about']['intro']    = trim($_POST['about_intro']    ?? '');
        $pages['about']['title_en'] = trim($_POST['about_title_en'] ?? '');
        $pages['about']['intro_en'] = trim($_POST['about_intro_en'] ?? '');
        save_json(CONTENT_PATH . '/pages.json', $pages);
        header('Location: /admin/pages.php?page=about&saved=1'); exit;

    } elseif ($section === 'team_delete') {
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $team  = pages_list_delete($pages['about']['team'] ?? [], (int)($_POST['team_index'] ?? -1));
        if ($team !== null) {
            $pages['about']['team'] = $team;
            save_json(CONTENT_PATH . '/pages.json', $pages);
            flash_set('success', 'Членът е изтрит.');
        }
        header('Location: /admin/pages.php?page=about');
        exit;

    } elseif ($section === 'team_save') {
        $pages = load_json(CONTENT_PATH . '/pages.json');

        // Handle file upload (kept in the controller — the list maths is pure).
        $photo = trim($_POST['member_photo_current'] ?? '');
        if (
            !empty($_FILES['member_photo_file']['tmp_name']) &&
            $_FILES['member_photo_file']['error'] === UPLOAD_ERR_OK
        ) {
            $ext = strtolower(pathinfo($_FILES['member_photo_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/team/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = 'member-' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['member_photo_file']['tmp_name'], $dir . $filename)) {
                    $photo = '/assets/images/team/' . $filename;
                }
            }
        }

        $member = [
            'name'    => trim($_POST['member_name']    ?? ''),
            'role'    => trim($_POST['member_role']    ?? ''),
            'role_en' => trim($_POST['member_role_en'] ?? ''),
            'photo'   => $photo,
            'bio'     => trim($_POST['member_bio']     ?? ''),
            'bio_en'  => trim($_POST['member_bio_en']  ?? ''),
        ];

        $pages['about']['team'] = pages_list_upsert($pages['about']['team'] ?? [], $_POST['team_index'] ?? 'new', $member);
        save_json(CONTENT_PATH . '/pages.json', $pages);
        header('Location: /admin/pages.php?page=about&saved=1');
        exit;

    } elseif ($section === 'projects_delete') {
        $pages   = load_json(CONTENT_PATH . '/pages.json');
        $current = pages_list_delete($pages['projects'] ?? [], (int)($_POST['proj_index'] ?? -1));
        if ($current !== null) {
            $pages['projects'] = $current;
            save_json(CONTENT_PATH . '/pages.json', $pages);
            flash_set('success', 'Проектът е изтрит.');
        }
        header('Location: /admin/pages.php?page=projects');
        exit;

    } elseif ($section === 'projects_save') {
        $pages = load_json(CONTENT_PATH . '/pages.json');

        $images = json_decode($_POST['proj_images'] ?? '[]', true);
        if (!is_array($images)) $images = [];

        // File upload (kept in the controller — the list maths is pure).
        if (
            !empty($_FILES['proj_image_file']['tmp_name']) &&
            $_FILES['proj_image_file']['error'] === UPLOAD_ERR_OK
        ) {
            $ext = strtolower(pathinfo($_FILES['proj_image_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp'])) {
                $dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/projects/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = 'project-' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['proj_image_file']['tmp_name'], $dir . $filename)) {
                    $images[] = '/assets/images/projects/' . $filename;
                }
            }
        }

        $project = [
            'title'    => trim($_POST['proj_title']    ?? ''),
            'title_en' => trim($_POST['proj_title_en'] ?? ''),
            'text'     => trim($_POST['proj_text']     ?? ''),
            'text_en'  => trim($_POST['proj_text_en']  ?? ''),
            'images'   => $images,
        ];

        $pages['projects'] = pages_list_upsert($pages['projects'] ?? [], $_POST['proj_index'] ?? 'new', $project);
        save_json(CONTENT_PATH . '/pages.json', $pages);
        header('Location: /admin/pages.php?page=projects&saved=1');
        exit;

    } elseif ($section === 'how_to_help') {
        // Saves title and intro only — ways are managed by way_save / way_delete
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $pages['how_to_help']['title']    = trim($_POST['help_title']    ?? '');
        $pages['how_to_help']['intro']    = trim($_POST['help_intro']    ?? '');
        $pages['how_to_help']['title_en'] = trim($_POST['help_title_en'] ?? '');
        $pages['how_to_help']['intro_en'] = trim($_POST['help_intro_en'] ?? '');
        save_json(CONTENT_PATH . '/pages.json', $pages);
        header('Location: /admin/pages.php?page=how_to_help&saved=1'); exit;

    } elseif ($section === 'way_delete') {
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $ways  = pages_list_delete($pages['how_to_help']['ways'] ?? [], (int)($_POST['way_index'] ?? -1));
        if ($ways !== null) {
            $pages['how_to_help']['ways'] = $ways;
            save_json(CONTENT_PATH . '/pages.json', $pages);
            flash_set('success', 'Начинът е изтрит.');
        }
        header('Location: /admin/pages.php?page=how_to_help');
        exit;

    } elseif ($section === 'way_save') {
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $way = [
            'title'    => trim($_POST['way_title']    ?? ''),
            'title_en' => trim($_POST['way_title_en'] ?? ''),
            'text'     => trim($_POST['way_text']     ?? ''),
            'text_en'  => trim($_POST['way_text_en']  ?? ''),
        ];
        $pages['how_to_help']['ways'] = pages_list_upsert($pages['how_to_help']['ways'] ?? [], $_POST['way_index'] ?? 'new', $way);
        save_json(CONTENT_PATH . '/pages.json', $pages);
        header('Location: /admin/pages.php?page=how_to_help&saved=1');
        exit;

    } elseif ($section === 'shop') {
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $pages['shop']['donation_text_bg'] = trim($_POST['donation_text_bg'] ?? '');
        $pages['shop']['donation_text_en'] = trim($_POST['donation_text_en'] ?? '');
        save_json(CONTENT_PATH . '/pages.json', $pages);
        header('Location: /admin/pages.php?page=shop&saved=1'); exit;

    } elseif ($section === 'legal') {
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $key   = $_POST['legal_key'] ?? '';
        if (in_array($key, ['privacy', 'legal_info', 'terms'], true)) {
            if (!isset($pages['legal'])) $pages['legal'] = [];
            $pages['legal'][$key]          = $_POST['legal_content']    ?? '';
            $pages['legal'][$key . '_en']  = $_POST['legal_content_en'] ?? '';
            save_json(CONTENT_PATH . '/pages.json', $pages);
            $legal_page_map = ['privacy' => 'legal_privacy', 'legal_info' => 'legal_info', 'terms' => 'legal_terms'];
            header('Location: /admin/pages.php?page=' . $legal_page_map[$key] . '&saved=1'); exit;
        }

    } elseif ($section === 'deepl') {
        if (isset($_POST['deepl_api_key'])) {
            $key = trim($_POST['deepl_api_key']);
            if ($key !== '') {
                setting_set('deepl_api_key', $key);
            } elseif (isset($_POST['clear_key'])) {
                setting_delete('deepl_api_key');
            }
        }
        header('Location: /admin/pages.php?saved=1'); exit;

    } elseif ($section === 'deepl_glossary_add') {
        $source = trim($_POST['glossary_source'] ?? '');
        $target = trim($_POST['glossary_target'] ?? '');
        if ($source !== '' && $target !== '') {
            $pairs   = deepl_load_glossary();
            $pairs[] = [$source, $target];
            deepl_save_glossary($pairs);
        }
        header('Location: /admin/pages.php?saved=1'); exit;

    } elseif ($section === 'deepl_glossary_delete') {
        $idx   = (int)($_POST['glossary_idx'] ?? -1);
        $pairs = deepl_load_glossary();
        if ($idx >= 0 && isset($pairs[$idx])) {
            array_splice($pairs, $idx, 1);
            deepl_save_glossary($pairs);
        }
        header('Location: /admin/pages.php?saved=1'); exit;
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────
$all_pages = load_json(CONTENT_PATH . '/pages.json');
$home      = $all_pages['home']        ?? [];
$impact    = get_impact();
$centres   = get_centres();
$about     = $all_pages['about']       ?? [];
$projects  = $all_pages['projects']    ?? [];
$help      = $all_pages['how_to_help'] ?? [];
$shop      = $all_pages['shop']        ?? [];
$legal     = $all_pages['legal']       ?? [];

$saved        = isset($_GET['saved']);
$deepl_ready  = deepl_is_configured();
$lbl_bg_badge = '<span style="font-size:.68rem;font-weight:700;background:#dcfce7;color:#166534;border-radius:3px;padding:.05rem .35rem;margin-left:.4rem;vertical-align:middle;">BG</span>';
$lbl_en_badge = '<span style="font-size:.68rem;font-weight:700;background:#dbeafe;color:#1d4ed8;border-radius:3px;padding:.05rem .35rem;margin-left:.4rem;vertical-align:middle;">EN</span>';

$_tinymce_key   = setting_get('tinymce_api_key', 'no-api-key');
$page_head_extra = '<script src="https://cdn.tiny.cloud/1/' . h($_tinymce_key) . '/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>';

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<?php if ($saved): ?>
  <div class="admin-alert admin-alert--success" style="margin-bottom:1.5rem;">Запазено успешно.</div>
<?php endif; ?>

<?php if (!$page): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     INDEX — list of all editable pages
     ══════════════════════════════════════════════════════════════════════════ -->
<h1 style="margin-bottom:1.5rem;">Съдържание</h1>
<div class="admin-table-wrap">
  <table class="admin-table">
    <thead>
      <tr>
        <th>Страница</th>
        <th>URL</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php
      $rows = [
          ['home',          'Начална страница',               '/'],
          ['impact',        'Показатели (числа)',              '/'],
          ['centres',       'Центрове',                        '/'],
          ['about',         'За нас',                          '/za-nas/'],
          ['projects',      'Проекти',                         '/proekti/'],
          ['how_to_help',   'Как да помогна',                  '/kak-da-pomogna/'],
          ['shop',          'Магазин — текст за дарения',      '/magazin/'],
          ['legal_privacy', 'Политика за поверителност',       '/politika-za-poveritelnost/'],
          ['legal_info',    'Правна информация',               '/pravna-informaciya/'],
          ['legal_terms',   'Условия за ползване',             '/usloviya/'],
      ];
      foreach ($rows as [$key, $label, $url]):
      ?>
      <tr>
        <td><strong><?= h($label) ?></strong></td>
        <td style="color:var(--text-muted);font-size:0.85rem;">
          <?php if ($url): ?>
            <a href="<?= h($url) ?>" target="_blank" rel="noopener"
               style="color:inherit;"><?= h($url) ?> ↗</a>
          <?php endif; ?>
        </td>
        <td style="text-align:right;">
          <a href="/admin/pages.php?page=<?= h($key) ?>" class="btn btn--outline" style="font-size:0.85rem;padding:0.4rem 0.9rem;">
            Редактиране
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ── DeepL settings card ──────────────────────────────────────────────────── -->
<h2 style="margin:2rem 0 1rem;font-size:1rem;">Автоматичен превод (DeepL)</h2>
<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;max-width:560px;">
  <p style="font-size:.875rem;color:var(--text-muted);margin:0 0 1rem;">
    API ключ за автоматичен превод на статии БГ → EN.
    Безплатен ключ от <a href="https://www.deepl.com/pro#developer" target="_blank" rel="noopener" style="color:var(--teal);">deepl.com/pro</a>
    (500 000 знака/месец, ключовете завършват с <code>:fx</code>).
  </p>
  <form method="POST" action="/admin/pages.php" class="admin-form">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="deepl">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.75rem;">
      <div style="position:relative;flex:1;min-width:240px;">
        <input type="password" name="deepl_api_key" id="deeplApiKey"
               value="<?= $deepl_ready ? h(setting_get('deepl_api_key')) : '' ?>"
               placeholder="<?= $deepl_ready ? '' : 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx:fx' ?>"
               style="width:100%;box-sizing:border-box;font-family:monospace;padding-right:4.5rem;">
        <button type="button" onclick="togglePwd(this)" class="pwd-toggle">Покажи</button>
      </div>
      <button type="submit" class="btn btn--primary">Запази</button>
      <?php if ($deepl_ready): ?>
        <button type="submit" name="clear_key" value="1" class="btn btn--outline"
                style="color:#c0392b;border-color:#f0c4c0;"
                data-confirm="Изтриване на DeepL API ключа?">Изтрий</button>
      <?php endif; ?>
    </div>
    <?php if ($deepl_ready): ?>
      <p style="margin:0;font-size:.8rem;color:#2d6a35;">✓ API ключ е конфигуриран — автоматичният превод е активен.</p>
    <?php else: ?>
      <p style="margin:0;font-size:.8rem;color:#856404;">⚠ Няма конфигуриран API ключ — автоматичният превод е деактивиран.</p>
    <?php endif; ?>
  </form>
</div>

<!-- ── DeepL glossary card ──────────────────────────────────────────────────── -->
<h2 style="margin:2rem 0 1rem;font-size:1rem;">Речник за превод (DeepL)</h2>
<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;max-width:560px;">
  <p style="font-size:.875rem;color:var(--text-muted);margin:0 0 1rem;">
    Думи или фрази, които DeepL трябва да превежда по конкретен начин. Пример: <strong>марка → Brand</strong>.
  </p>
  <?php $glossary_pairs = deepl_load_glossary(); ?>
  <?php if ($glossary_pairs): ?>
  <table style="width:100%;border-collapse:collapse;margin-bottom:1rem;font-size:.875rem;">
    <thead>
      <tr style="text-align:left;color:var(--text-muted);font-size:.78rem;text-transform:uppercase;">
        <th style="padding:.3rem .5rem .3rem 0;font-weight:600;">БГ (оригинал)</th>
        <th style="padding:.3rem .5rem;font-weight:600;">EN (превод)</th>
        <th style="width:36px;"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($glossary_pairs as $i => $pair): ?>
      <tr style="border-top:1px solid var(--border);">
        <td style="padding:.45rem .5rem .45rem 0;"><?= h($pair[0]) ?></td>
        <td style="padding:.45rem .5rem;"><?= h($pair[1]) ?></td>
        <td style="padding:.45rem 0;text-align:center;">
          <form method="POST" action="/admin/pages.php" style="margin:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="section" value="deepl_glossary_delete">
            <input type="hidden" name="glossary_idx" value="<?= $i ?>">
            <button type="submit" style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:1rem;line-height:1;padding:0;" title="Изтрий">✕</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <form method="POST" action="/admin/pages.php" class="admin-form" style="margin:0;">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="deepl_glossary_add">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
      <input type="text" name="glossary_source" placeholder="Дума на БГ" required
             style="flex:1;min-width:140px;">
      <span style="color:var(--text-muted);">→</span>
      <input type="text" name="glossary_target" placeholder="Word in EN" required
             style="flex:1;min-width:140px;">
      <button type="submit" class="btn btn--primary">Добави</button>
    </div>
  </form>
</div>

<?php elseif ($page === 'home'): ?>
<!-- ══ HOME ══ -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад</a>
    <h1 style="margin:0;">Начална страница</h1>
  </div>
  <button type="submit" form="homeForm" class="btn btn--primary">Запази</button>
</div>
<form id="homeForm" method="POST" action="/admin/pages.php?page=home" class="admin-form" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="home">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div class="form-group">
      <label>Hero заглавие <?= $lbl_bg_badge ?></label>
      <input type="text" id="heroTitleBg" name="hero_title" value="<?= h($home['hero_title'] ?? '') ?>">
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;"><label style="margin:0;">Hero title <?= $lbl_en_badge ?></label><button type="button" class="btn btn--outline" onclick="txField('heroTitleBg','heroTitleEn',this)" style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button></div>
      <input type="text" id="heroTitleEn" name="hero_title_en" value="<?= h($home['hero_title_en'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Hero текст <?= $lbl_bg_badge ?></label>
      <textarea id="heroTextBg" name="hero_text" rows="3"><?= h($home['hero_text'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;"><label style="margin:0;">Hero text <?= $lbl_en_badge ?></label><button type="button" class="btn btn--outline" onclick="txField('heroTextBg','heroTextEn',this)" style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button></div>
      <textarea id="heroTextEn" name="hero_text_en" rows="3"><?= h($home['hero_text_en'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <label>Заглавие на мисията <?= $lbl_bg_badge ?></label>
      <input type="text" id="missionTitleBg" name="mission_title" value="<?= h($home['mission_title'] ?? '') ?>">
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;"><label style="margin:0;">Mission title <?= $lbl_en_badge ?></label><button type="button" class="btn btn--outline" onclick="txField('missionTitleBg','missionTitleEn',this)" style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button></div>
      <input type="text" id="missionTitleEn" name="mission_title_en" value="<?= h($home['mission_title_en'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Текст на мисията <?= $lbl_bg_badge ?></label>
      <textarea id="missionTextBg" name="mission_text" rows="4"><?= h($home['mission_text'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;"><label style="margin:0;">Mission text <?= $lbl_en_badge ?></label><button type="button" class="btn btn--outline" onclick="txField('missionTextBg','missionTextEn',this)" style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button></div>
      <textarea id="missionTextEn" name="mission_text_en" rows="4"><?= h($home['mission_text_en'] ?? '') ?></textarea>
    </div>
    <div class="form-group" style="grid-column:1/-1;">
      <label>Снимка за секция Мисия</label>
      <?php if (!empty($home['mission_image'])): ?>
        <img src="<?= h($home['mission_image']) ?>?t=<?= time() ?>" alt="Mission image"
             style="width:100%;max-width:320px;border-radius:4px;margin-bottom:.75rem;display:block;">
      <?php endif; ?>
      <input type="hidden" name="mission_image_lib" id="missionImageLib">
      <input type="hidden" name="mission_image_clear" id="missionImageClear" value="">
      <input type="file" name="mission_image" accept="image/jpeg,image/png,image/webp" data-om-crop>
      <button type="button" class="btn btn--outline"
              style="margin-top:.5rem;font-size:.82rem;"
              onclick="_pickMissionImage(this)">Избери от библиотека</button>
      <?php if (!empty($home['mission_image'])): ?>
        <button type="button" class="btn btn--outline"
                style="margin-top:.5rem;font-size:.82rem;color:#a00;border-color:#a00;"
                onclick="_adminConfirm('Изтрий снимката за секция Мисия?').then(function(ok){ if(ok){ document.getElementById('missionImageClear').value='1'; document.getElementById('homeForm').submit(); } })">Изтрий снимката</button>
      <?php endif; ?>
      <p style="font-size:.8rem;color:var(--text-muted);margin-top:.25rem;">JPEG, PNG или WebP. Ако не е зададена, мисията се показва като централизиран текст.</p>
    </div>
  </div>
  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>

<!-- ── Campaign Section ── -->
<hr style="margin:2rem 0;border:none;border-top:1px solid var(--border);">
<?php
  $campaign    = $all_pages['campaign'] ?? [];
  $campaign_url = setting_get('campaign_url');
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.25rem;flex-wrap:wrap;gap:1rem;">
  <h2 style="margin:0;">Кампания</h2>
  <button type="submit" form="campaignForm" class="btn btn--primary">Запази кампанията</button>
</div>
<p style="color:var(--text-muted);font-size:0.9rem;margin-bottom:1.5rem;">
  Попълнете всички полета и добавете URL, за да се покаже секцията на сайта. Оставете URL празен, за да скриете секцията.
</p>
<?php if (isset($_GET['campaign_error'])): ?>
  <p style="color:#a00;margin-bottom:1rem;">Невалиден URL — моля въведете пълен адрес (https://...).</p>
<?php endif; ?>
<form id="campaignForm" method="POST" action="/admin/pages.php?page=home" class="admin-form" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="campaign">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div class="form-group">
      <label>Заглавие <?= $lbl_bg_badge ?></label>
      <input type="text" id="campTitleBg" name="campaign_title" value="<?= h($campaign['title'] ?? '') ?>">
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Title <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline" onclick="txField('campTitleBg','campTitleEn',this)" style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <input type="text" id="campTitleEn" name="campaign_title_en" value="<?= h($campaign['title_en'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Текст <?= $lbl_bg_badge ?></label>
      <textarea id="campTextBg" name="campaign_text" rows="5"><?= h($campaign['text'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Text <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline" onclick="txField('campTextBg','campTextEn',this)" style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <textarea id="campTextEn" name="campaign_text_en" rows="5"><?= h($campaign['text_en'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <label>Текст на бутона <?= $lbl_bg_badge ?></label>
      <input type="text" id="campCtaBg" name="campaign_cta" value="<?= h($campaign['cta'] ?? 'Подкрепи кампанията →') ?>">
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Button text <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline" onclick="txField('campCtaBg','campCtaEn',this)" style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <input type="text" id="campCtaEn" name="campaign_cta_en" value="<?= h($campaign['cta_en'] ?? 'Support our campaign →') ?>">
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-top:0.5rem;">
    <div class="form-group">
      <label>Снимка</label>
      <?php if (!empty($campaign['image'])): ?>
        <img src="<?= h($campaign['image']) ?>?t=<?= time() ?>" alt="Campaign image"
             style="width:100%;max-width:320px;border-radius:4px;margin-bottom:.75rem;display:block;">
      <?php endif; ?>
      <input type="hidden" name="campaign_image_lib" id="campaignImageLib">
      <input type="file" name="campaign_image" accept="image/jpeg,image/png,image/webp" data-om-crop>
      <button type="button" class="btn btn--outline"
              style="margin-top:.5rem;font-size:.82rem;"
              onclick="_pickCampaignImage(this)">Избери от библиотека</button>
      <p style="font-size:.8rem;color:var(--text-muted);margin-top:.25rem;">JPEG, PNG или WebP.</p>
    </div>
    <div class="form-group">
      <label for="campaignUrl">URL на кампанията</label>
      <input type="url" id="campaignUrl" name="campaign_url"
             value="<?= h($campaign_url) ?>"
             placeholder="https://www.indiegogo.com/...">
      <p style="font-size:.8rem;color:var(--text-muted);margin-top:.25rem;">Оставете празно, за да скриете секцията от сайта.</p>
      <?php if ($campaign_url !== ''): ?>
        <a href="<?= h($campaign_url) ?>" target="_blank" rel="noopener noreferrer"
           style="font-size:.85rem;">Провери линка ↗</a>
      <?php endif; ?>
    </div>
  </div>

  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази кампанията</button>
  </div>
</form>
<script>
function _pickCampaignImage(btn) {
  openMediaPicker(function (p) {
    document.getElementById('campaignImageLib').value = p;
    var fg  = btn.closest('.form-group');
    var img = fg.querySelector('img');
    if (img) {
      img.src = p;
    } else {
      img = document.createElement('img');
      img.src = p; img.alt = 'Campaign image';
      img.style.cssText = 'width:100%;max-width:320px;border-radius:4px;margin-bottom:.75rem;display:block;';
      fg.insertBefore(img, fg.querySelector('input[name="campaign_image_lib"]'));
    }
  });
}
function _pickMissionImage(btn) {
  openMediaPicker(function (p) {
    document.getElementById('missionImageLib').value = p;
    var fg  = btn.closest('.form-group');
    var img = fg.querySelector('img');
    if (img) {
      img.src = p;
    } else {
      img = document.createElement('img');
      img.src = p; img.alt = 'Mission image';
      img.style.cssText = 'width:100%;max-width:320px;border-radius:4px;margin-bottom:.75rem;display:block;';
      fg.insertBefore(img, fg.querySelector('input[name="mission_image_lib"]'));
    }
  });
}
function _pickCentreImage(btn) {
  openMediaPicker(function (p) {
    var card = btn.closest('.centre-card');
    card.querySelector('.centre-image-lib').value = p;
    var fg  = btn.closest('.form-group');
    var img = fg.querySelector('img');
    if (img) {
      img.src = p;
    } else {
      img = document.createElement('img');
      img.src = p; img.alt = '';
      img.style.cssText = 'height:80px;border-radius:4px;margin-bottom:.5rem;display:block;';
      fg.insertBefore(img, fg.querySelector('.centre-image-lib'));
    }
  });
}
</script>
<script>
tinymce.init(Object.assign({}, window._tinyBase, { selector: '#campTextBg, #campTextEn', min_height: 200 }));
</script>

<?php elseif ($page === 'impact'): ?>
<!-- ══ IMPACT ══ -->
<?php
// ── Single item edit ──────────────────────────────────────────────────────────
$edit_idx = $_GET['edit'] ?? null;
if ($edit_idx !== null):
    foreach ($impact as $i => &$_p2) {
        if (!isset($_p2['order'])) $_p2['order'] = $i;
    }
    unset($_p2);
    usort($impact, fn($a, $b) => (int)$a['order'] - (int)$b['order']);

    $is_new    = ($edit_idx === 'new');
    $edit_item = $is_new ? ['number' => '', 'label_bg' => '', 'label_en' => ''] : ($impact[(int)$edit_idx] ?? null);
    if (!$is_new && !$edit_item): ?>
        <p>Записът не е намерен. <a href="/admin/pages.php?page=impact">← Назад</a></p>
<?php else: ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php?page=impact" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад към показатели</a>
    <h1 style="margin:0;"><?= $is_new ? 'Нов показател' : h($edit_item['label_bg']) ?></h1>
  </div>
  <button type="submit" form="impactEditForm" class="btn btn--primary">Запази</button>
</div>

<form id="impactEditForm" method="POST" action="/admin/pages.php?page=impact" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="section"    value="impact_save">
  <input type="hidden" name="item_index" value="<?= h($edit_idx) ?>">

  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1.5rem;">
    <div class="form-group">
      <label>Число</label>
      <input type="text" name="impact_number" value="<?= h($edit_item['number'] ?? '') ?>" placeholder="62" required>
    </div>
    <div class="form-group">
      <label>Етикет <?= $lbl_bg_badge ?></label>
      <input type="text" id="impactLabelBg" name="impact_label_bg" value="<?= h($edit_item['label_bg'] ?? '') ?>" placeholder="деца" required>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Label <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('impactLabelBg','impactLabelEn',this)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <input type="text" id="impactLabelEn" name="impact_label_en" value="<?= h($edit_item['label_en'] ?? '') ?>" placeholder="children">
    </div>
  </div>

  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>
<?php endif; ?>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php';
exit;
endif; // end $edit_idx !== null
?>

<!-- ══ IMPACT LIST ══ -->
<?php
foreach ($impact as $i => &$_p) {
    if (!isset($_p['order'])) $_p['order'] = $i;
}
unset($_p);
usort($impact, fn($a, $b) => (int)$a['order'] - (int)$b['order']);
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад</a>
    <h1 style="margin:0;">Показатели (числа)</h1>
  </div>
  <a href="/admin/pages.php?page=impact&edit=new" class="btn btn--primary">+ Нов показател</a>
</div>

<div id="reorderAnnounce" aria-live="polite" aria-atomic="true"
     style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;"></div>

<?php $flash_msgs = flash_get(); foreach ($flash_msgs as $f): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;
  <?= $f['type']==='success' ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;' : 'background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;' ?>">
  <?= h($f['message']) ?>
</div>
<?php endforeach; ?>

<?php if (!empty($_GET['saved'])): ?>
  <div class="admin-alert admin-alert--success" style="margin-bottom:1.5rem;">Запазено успешно.</div>
<?php endif; ?>

<input type="hidden" id="impactCsrfToken" name="csrf_token" value="<?= csrf_token() ?>">

<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;">
  <div style="overflow-x:auto;">
  <table class="admin-table" style="width:100%;border-collapse:collapse;" id="impactTable">
    <thead>
      <tr style="background:var(--off-white);">
        <th style="padding:.65rem .75rem;width:36px;" aria-label="Пренареди"></th>
        <th style="padding:.65rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Показател</th>
        <th style="padding:.65rem 1rem;width:120px;"></th>
        <th style="padding:.65rem .5rem;width:64px;text-align:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Ред</th>
        <th style="padding:.65rem 1rem;width:120px;"></th>
      </tr>
    </thead>
    <tbody id="impactBody">
      <?php if (empty($impact)): ?>
        <tr><td colspan="5" style="padding:2rem;text-align:center;color:var(--text-muted);">Няма показатели. <a href="/admin/pages.php?page=impact&edit=new">Създайте първия →</a></td></tr>
      <?php else: foreach ($impact as $idx => $item): ?>
        <tr data-key="<?= h($item['label_bg']) ?>" style="border-top:1px solid var(--border);cursor:default;">
          <td style="padding:.5rem .75rem;text-align:center;">
            <span class="impact-drag-handle" draggable="true" aria-hidden="true"
                  style="cursor:grab;font-size:1.1rem;color:var(--text-muted);user-select:none;display:inline-block;padding:.2rem .3rem;"
                  title="Влачи за пренареждане">⠿</span>
          </td>
          <td style="padding:.75rem 1rem;">
            <div style="display:flex;align-items:center;gap:.75rem;">
              <div style="width:48px;height:48px;background:var(--off-white);border-radius:4px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:700;color:var(--text-muted);"><?= h($item['number']) ?></div>
              <strong style="font-size:.9rem;"><?= h($item['label_bg']) ?></strong>
            </div>
          </td>
          <td style="padding:.75rem 1rem;text-align:right;">
            <a href="/admin/pages.php?page=impact&edit=<?= $idx ?>" class="btn btn--outline" style="font-size:.78rem;padding:.3rem .7rem;">Редактирай</a>
          </td>
          <td style="padding:.5rem;text-align:center;">
            <div style="display:flex;flex-direction:column;gap:.2rem;align-items:center;">
              <button type="button" class="btn btn--outline impact-up"
                      style="padding:.2rem .5rem;font-size:.75rem;line-height:1;"
                      aria-label="Премести нагоре — <?= h($item['label_bg']) ?>">↑</button>
              <button type="button" class="btn btn--outline impact-down"
                      style="padding:.2rem .5rem;font-size:.75rem;line-height:1;"
                      aria-label="Премести надолу — <?= h($item['label_bg']) ?>">↓</button>
            </div>
          </td>
          <td style="padding:.75rem 1rem;text-align:right;">
            <form method="POST" action="/admin/pages.php?page=impact" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="section"    value="impact_delete">
              <input type="hidden" name="item_index" value="<?= $idx ?>">
              <button type="submit" class="btn btn--outline"
                      style="font-size:.78rem;padding:.3rem .7rem;color:#c0392b;border-color:#f0c4c0;"
                      data-confirm="Изтриване на показателя?">Изтрий</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
(function () {
  var tbody    = document.getElementById('impactBody');
  var announce = document.getElementById('reorderAnnounce');
  if (!tbody) return;

  function getRows() { return Array.from(tbody.querySelectorAll('tr[data-key]')); }
  function keysOrder() { return getRows().map(function(r){ return r.dataset.key; }); }

  function saveOrder(prevRows) {
    var token   = document.getElementById('impactCsrfToken');
    var csrfVal = token ? token.value : '';
    fetch('/admin/impact-reorder-ajax.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ csrf_token: csrfVal, order: keysOrder() })
    }).then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.ok) {
          if (prevRows) { prevRows.forEach(function(row) { tbody.appendChild(row); }); updateAriaButtons(); }
          alert('Грешка при запис: ' + (d.error || ''));
        }
      })
      .catch(function(e){
        if (prevRows) { prevRows.forEach(function(row) { tbody.appendChild(row); }); updateAriaButtons(); }
        alert('Грешка: ' + e.message);
      });
  }

  function updateAriaButtons() {
    var rows = getRows();
    rows.forEach(function(row, i) {
      row.querySelector('.impact-up').disabled   = (i === 0);
      row.querySelector('.impact-down').disabled = (i === rows.length - 1);
    });
  }

  tbody.addEventListener('click', function(e) {
    var btn = e.target.closest('.impact-up, .impact-down');
    if (!btn) return;
    var row      = btn.closest('tr');
    var rows     = getRows();
    var idx      = rows.indexOf(row);
    var prevRows = getRows();
    if (btn.classList.contains('impact-up') && idx > 0) {
      tbody.insertBefore(row, rows[idx - 1]);
    } else if (btn.classList.contains('impact-down') && idx < rows.length - 1) {
      tbody.insertBefore(rows[idx + 1], row);
    } else { return; }
    updateAriaButtons();
    var newPos = getRows().indexOf(row) + 1;
    announce.textContent = row.dataset.key + ' — преместен на позиция ' + newPos;
    saveOrder(prevRows);
  });

  var dragging = null;
  tbody.addEventListener('dragstart', function(e) {
    var handle = e.target.closest('.impact-drag-handle');
    if (!handle) { e.preventDefault(); return; }
    dragging = handle.closest('tr');
    dragging.style.opacity = '0.4';
    e.dataTransfer.effectAllowed = 'move';
  });
  tbody.addEventListener('dragend', function() {
    if (dragging) { dragging.style.opacity = ''; dragging = null; }
    getRows().forEach(function(r){ r.style.borderTop = ''; });
  });
  tbody.addEventListener('dragover', function(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    var target = e.target.closest('tr[data-key]');
    getRows().forEach(function(r){ r.style.borderTop = ''; });
    if (target && target !== dragging) target.style.borderTop = '2px solid var(--teal)';
  });
  tbody.addEventListener('drop', function(e) {
    e.preventDefault();
    var target = e.target.closest('tr[data-key]');
    if (!target || target === dragging || !dragging) return;
    var prevRows = getRows();
    tbody.insertBefore(dragging, target);
    getRows().forEach(function(r){ r.style.borderTop = ''; });
    updateAriaButtons();
    saveOrder(prevRows);
  });

  updateAriaButtons();
}());
</script>

<?php elseif ($page === 'centres'): ?>
<!-- ══ CENTRES ══ -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад</a>
    <h1 style="margin:0;">Центрове</h1>
  </div>
  <button type="submit" form="centresForm" class="btn btn--primary">Запази центровете</button>
</div>
<form id="centresForm" method="POST" action="/admin/pages.php?page=centres" enctype="multipart/form-data" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="centres">
  <?php foreach ($centres as $centre): ?>
    <div class="centre-card" style="padding:1.5rem;border:1px solid var(--border);border-radius:8px;margin-bottom:1.5rem;">
      <input type="hidden" name="centre_id[]" value="<?= h($centre['id']) ?>">
      <h3 style="margin-bottom:1rem;"><?= h($centre['name_bg']) ?></h3>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div class="form-group">
          <label>Ime <?= $lbl_bg_badge ?></label>
          <input type="text" name="centre_name_bg[]" class="c-name-bg" value="<?= h($centre['name_bg']) ?>">
        </div>
        <div class="form-group">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
            <label style="margin:0;">Name <?= $lbl_en_badge ?></label>
            <button type="button" class="btn btn--outline" onclick="var c=this.closest('.centre-card');txEl(c.querySelector('.c-name-bg'),c.querySelector('.c-name-en'),this)" style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
          </div>
          <input type="text" name="centre_name_en[]" class="c-name-en" value="<?= h($centre['name_en'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Описание <?= $lbl_bg_badge ?></label>
          <textarea name="centre_desc_bg[]" class="c-desc-bg" rows="3"><?= h($centre['description_bg']) ?></textarea>
        </div>
        <div class="form-group">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
            <label style="margin:0;">Description <?= $lbl_en_badge ?></label>
            <button type="button" class="btn btn--outline" onclick="var c=this.closest('.centre-card');txEl(c.querySelector('.c-desc-bg'),c.querySelector('.c-desc-en'),this)" style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
          </div>
          <textarea name="centre_desc_en[]" class="c-desc-en" rows="3"><?= h($centre['description_en'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="form-group">
        <label>Снимка</label>
        <?php if (!empty($centre['image'])): ?>
          <img src="<?= h($centre['image']) ?>" alt="" style="height:80px;border-radius:4px;margin-bottom:0.5rem;display:block;">
        <?php endif; ?>
        <input type="hidden" name="centre_image_lib_<?= h($centre['id']) ?>"
               class="centre-image-lib" data-centre-id="<?= h($centre['id']) ?>">
        <input type="file" name="centre_image_<?= h($centre['id']) ?>" accept="image/*" data-om-crop>
        <button type="button" class="btn btn--outline"
                style="margin-top:.5rem;font-size:.82rem;"
                onclick="_pickCentreImage(this)">Избери от библиотека</button>
      </div>
    </div>
  <?php endforeach; ?>
  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази центровете</button>
  </div>
</form>

<?php elseif ($page === 'about'): ?>
<!-- ══ ABOUT ══ -->
<?php
// ── Single team member edit ───────────────────────────────────────────────────
$edit_team_idx = $_GET['edit_team'] ?? null;
if ($edit_team_idx !== null):
    $team_data = $about['team'] ?? [];
    foreach ($team_data as $i => &$_p2) {
        if (!isset($_p2['order'])) $_p2['order'] = $i;
    }
    unset($_p2);
    usort($team_data, fn($a, $b) => (int)$a['order'] - (int)$b['order']);

    $is_new      = ($edit_team_idx === 'new');
    $edit_member = $is_new
        ? ['name' => '', 'role' => '', 'role_en' => '', 'photo' => '', 'bio' => '', 'bio_en' => '']
        : ($team_data[(int)$edit_team_idx] ?? null);
    if (!$is_new && !$edit_member): ?>
        <p>Членът не е намерен. <a href="/admin/pages.php?page=about">← Назад</a></p>
<?php else: ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php?page=about" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад към За нас</a>
    <h1 style="margin:0;"><?= $is_new ? 'Нов член на екипа' : h($edit_member['name']) ?></h1>
  </div>
  <button type="submit" form="teamEditForm" class="btn btn--primary">Запази</button>
</div>

<form id="teamEditForm" method="POST" action="/admin/pages.php?page=about"
      enctype="multipart/form-data" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="section"              value="team_save">
  <input type="hidden" name="team_index"           value="<?= h($edit_team_idx) ?>">
  <input type="hidden" name="member_photo_current" value="<?= h($edit_member['photo'] ?? '') ?>">

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div class="form-group">
      <label>Имена <?= $lbl_bg_badge ?></label>
      <input type="text" id="memberNameBg" name="member_name"
             value="<?= h($edit_member['name'] ?? '') ?>" required>
    </div>
    <div class="form-group">
      <!-- name intentionally not translated — it's a proper noun -->
    </div>
    <div class="form-group">
      <label>Роля <?= $lbl_bg_badge ?></label>
      <input type="text" id="memberRoleBg" name="member_role"
             value="<?= h($edit_member['role'] ?? '') ?>">
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Role <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('memberRoleBg','memberRoleEn',this)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <input type="text" id="memberRoleEn" name="member_role_en"
             value="<?= h($edit_member['role_en'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Биография <?= $lbl_bg_badge ?></label>
      <textarea id="teamBioBg" name="member_bio" rows="8"><?= h($edit_member['bio'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Bio <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('teamBioBg','teamBioEn',this,true)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <textarea id="teamBioEn" name="member_bio_en" rows="8"><?= h($edit_member['bio_en'] ?? '') ?></textarea>
    </div>
  </div>

  <div class="form-group" style="margin-top:.5rem;">
    <label>Снимка</label>
    <?php if (!empty($edit_member['photo'])): ?>
      <img src="<?= h($edit_member['photo']) ?>" alt=""
           style="height:80px;border-radius:4px;margin-bottom:0.5rem;display:block;">
    <?php endif; ?>
    <input type="file" name="member_photo_file" accept="image/*" data-om-crop>
    <small style="color:var(--text-muted);display:block;margin-top:.25rem;">Качи нова снимка (замества текущата).</small>
  </div>

  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>
<script>
if (window._tinyBase) {
  tinymce.init(Object.assign({}, window._tinyBase, { selector: '#teamBioBg, #teamBioEn', min_height: 200 }));
}
</script>
<?php endif; ?>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php';
exit;
endif; // end $edit_team_idx !== null
?>

<!-- ══ ABOUT — title/intro form ══ -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад</a>
    <h1 style="margin:0;">За нас</h1>
  </div>
  <button type="submit" form="aboutForm" class="btn btn--primary">Запази</button>
</div>

<?php if (!empty($_GET['saved'])): ?>
  <div class="admin-alert admin-alert--success" style="margin-bottom:1.5rem;">Запазено успешно.</div>
<?php endif; ?>

<form id="aboutForm" method="POST" action="/admin/pages.php?page=about" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="about">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div class="form-group">
      <label>Заглавие <?= $lbl_bg_badge ?></label>
      <input type="text" id="aboutTitleBg" name="about_title" value="<?= h($about['title'] ?? '') ?>">
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Title <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('aboutTitleBg','aboutTitleEn',this)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <input type="text" id="aboutTitleEn" name="about_title_en" value="<?= h($about['title_en'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Уводен текст <?= $lbl_bg_badge ?></label>
      <textarea id="aboutIntroBg" name="about_intro" rows="8"><?= h($about['intro'] ?? '') ?></textarea>
      <small style="color:var(--text-muted);">Двоен нов ред = нов параграф.</small>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Intro <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('aboutIntroBg','aboutIntroEn',this)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <textarea id="aboutIntroEn" name="about_intro_en" rows="8"><?= h($about['intro_en'] ?? '') ?></textarea>
    </div>
  </div>
  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>
<script>
if (window._tinyBase) {
  tinymce.init(Object.assign({}, window._tinyBase, { selector: '#aboutIntroBg, #aboutIntroEn', min_height: 200 }));
}
</script>

<!-- ══ ABOUT — team sortable list ══ -->
<?php
$team_list = $about['team'] ?? [];
foreach ($team_list as $i => &$_tp) {
    if (!isset($_tp['order'])) $_tp['order'] = $i;
}
unset($_tp);
usort($team_list, fn($a, $b) => (int)$a['order'] - (int)$b['order']);
?>
<h2 style="margin:2.5rem 0 1rem;">Екип</h2>

<div id="teamReorderAnnounce" aria-live="polite" aria-atomic="true"
     style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;"></div>

<?php $flash_msgs = flash_get(); foreach ($flash_msgs as $f): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;
  <?= $f['type']==='success' ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;' : 'background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;' ?>">
  <?= h($f['message']) ?>
</div>
<?php endforeach; ?>

<div style="display:flex;justify-content:flex-end;margin-bottom:1rem;">
  <a href="/admin/pages.php?page=about&edit_team=new" class="btn btn--primary">+ Нов член</a>
</div>

<input type="hidden" id="teamCsrfToken" name="csrf_token" value="<?= csrf_token() ?>">

<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;">
  <div style="overflow-x:auto;">
  <table class="admin-table" style="width:100%;border-collapse:collapse;" id="teamTable">
    <thead>
      <tr style="background:var(--off-white);">
        <th style="padding:.65rem .75rem;width:36px;" aria-label="Пренареди"></th>
        <th style="padding:.65rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Член</th>
        <th style="padding:.65rem 1rem;width:120px;"></th>
        <th style="padding:.65rem .5rem;width:64px;text-align:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Ред</th>
        <th style="padding:.65rem 1rem;width:120px;"></th>
      </tr>
    </thead>
    <tbody id="teamBody">
      <?php if (empty($team_list)): ?>
        <tr><td colspan="5" style="padding:2rem;text-align:center;color:var(--text-muted);">Няма членове. <a href="/admin/pages.php?page=about&edit_team=new">Добавете първия →</a></td></tr>
      <?php else: foreach ($team_list as $idx => $member):
        $thumb = !empty($member['photo']) ? $member['photo'] : '';
      ?>
        <tr data-key="<?= h($member['name']) ?>" style="border-top:1px solid var(--border);cursor:default;">
          <td style="padding:.5rem .75rem;text-align:center;">
            <span class="team-drag-handle" draggable="true" aria-hidden="true"
                  style="cursor:grab;font-size:1.1rem;color:var(--text-muted);user-select:none;display:inline-block;padding:.2rem .3rem;"
                  title="Влачи за пренареждане">⠿</span>
          </td>
          <td style="padding:.75rem 1rem;">
            <div style="display:flex;align-items:center;gap:.75rem;">
              <?php if ($thumb): ?>
                <img src="<?= h($thumb) ?>" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:50%;flex-shrink:0;">
              <?php else: ?>
                <div style="width:48px;height:48px;background:var(--off-white);border-radius:50%;flex-shrink:0;"></div>
              <?php endif; ?>
              <div>
                <strong style="font-size:.9rem;"><?= h($member['name']) ?></strong>
                <?php if (!empty($member['role'])): ?>
                  <div style="font-size:.78rem;color:var(--text-muted);"><?= h($member['role']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td style="padding:.75rem 1rem;text-align:right;">
            <a href="/admin/pages.php?page=about&edit_team=<?= $idx ?>" class="btn btn--outline" style="font-size:.78rem;padding:.3rem .7rem;">Редактирай</a>
          </td>
          <td style="padding:.5rem;text-align:center;">
            <div style="display:flex;flex-direction:column;gap:.2rem;align-items:center;">
              <button type="button" class="btn btn--outline team-up"
                      style="padding:.2rem .5rem;font-size:.75rem;line-height:1;"
                      aria-label="Премести нагоре — <?= h($member['name']) ?>">↑</button>
              <button type="button" class="btn btn--outline team-down"
                      style="padding:.2rem .5rem;font-size:.75rem;line-height:1;"
                      aria-label="Премести надолу — <?= h($member['name']) ?>">↓</button>
            </div>
          </td>
          <td style="padding:.75rem 1rem;text-align:right;">
            <form method="POST" action="/admin/pages.php?page=about" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="section"    value="team_delete">
              <input type="hidden" name="team_index" value="<?= $idx ?>">
              <button type="submit" class="btn btn--outline"
                      style="font-size:.78rem;padding:.3rem .7rem;color:#c0392b;border-color:#f0c4c0;"
                      data-confirm="Изтриване на члена?">Изтрий</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
(function () {
  var tbody    = document.getElementById('teamBody');
  var announce = document.getElementById('teamReorderAnnounce');
  if (!tbody) return;

  function getRows() { return Array.from(tbody.querySelectorAll('tr[data-key]')); }
  function keysOrder() { return getRows().map(function(r){ return r.dataset.key; }); }

  function saveOrder(prevRows) {
    var token   = document.getElementById('teamCsrfToken');
    var csrfVal = token ? token.value : '';
    fetch('/admin/team-reorder-ajax.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ csrf_token: csrfVal, order: keysOrder() })
    }).then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.ok) {
          if (prevRows) { prevRows.forEach(function(row) { tbody.appendChild(row); }); updateAriaButtons(); }
          alert('Грешка при запис: ' + (d.error || ''));
        }
      })
      .catch(function(e){
        if (prevRows) { prevRows.forEach(function(row) { tbody.appendChild(row); }); updateAriaButtons(); }
        alert('Грешка: ' + e.message);
      });
  }

  function updateAriaButtons() {
    var rows = getRows();
    rows.forEach(function(row, i) {
      row.querySelector('.team-up').disabled   = (i === 0);
      row.querySelector('.team-down').disabled = (i === rows.length - 1);
    });
  }

  tbody.addEventListener('click', function(e) {
    var btn = e.target.closest('.team-up, .team-down');
    if (!btn) return;
    var row      = btn.closest('tr');
    var rows     = getRows();
    var idx      = rows.indexOf(row);
    var prevRows = getRows();
    if (btn.classList.contains('team-up') && idx > 0) {
      tbody.insertBefore(row, rows[idx - 1]);
    } else if (btn.classList.contains('team-down') && idx < rows.length - 1) {
      tbody.insertBefore(rows[idx + 1], row);
    } else { return; }
    updateAriaButtons();
    var newPos = getRows().indexOf(row) + 1;
    announce.textContent = row.dataset.key + ' — преместен на позиция ' + newPos;
    saveOrder(prevRows);
  });

  var dragging = null;
  tbody.addEventListener('dragstart', function(e) {
    var handle = e.target.closest('.team-drag-handle');
    if (!handle) { e.preventDefault(); return; }
    dragging = handle.closest('tr');
    dragging.style.opacity = '0.4';
    e.dataTransfer.effectAllowed = 'move';
  });
  tbody.addEventListener('dragend', function() {
    if (dragging) { dragging.style.opacity = ''; dragging = null; }
    getRows().forEach(function(r){ r.style.borderTop = ''; });
  });
  tbody.addEventListener('dragover', function(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    var target = e.target.closest('tr[data-key]');
    getRows().forEach(function(r){ r.style.borderTop = ''; });
    if (target && target !== dragging) target.style.borderTop = '2px solid var(--teal)';
  });
  tbody.addEventListener('drop', function(e) {
    e.preventDefault();
    var target = e.target.closest('tr[data-key]');
    if (!target || target === dragging || !dragging) return;
    var prevRows = getRows();
    tbody.insertBefore(dragging, target);
    getRows().forEach(function(r){ r.style.borderTop = ''; });
    updateAriaButtons();
    saveOrder(prevRows);
  });

  updateAriaButtons();
}());
</script>

<?php elseif ($page === 'projects'): ?>
<?php
// ── Single project edit ───────────────────────────────────────────────────────
$edit_idx = $_GET['edit'] ?? null;
if ($edit_idx !== null):
  // Sort first so index matches sorted position
  foreach ($projects as $i => &$_p2) {
      if (!isset($_p2['order'])) $_p2['order'] = $i;
  }
  unset($_p2);
  usort($projects, fn($a, $b) => (int)$a['order'] - (int)$b['order']);

  $is_new  = ($edit_idx === 'new');
  $project = $is_new ? ['title'=>'','title_en'=>'','text'=>'','text_en'=>'','images'=>[]] : ($projects[(int)$edit_idx] ?? null);
  if (!$is_new && !$project): ?>
    <p>Проектът не е намерен. <a href="/admin/pages.php?page=projects">← Назад</a></p>
<?php else: ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php?page=projects" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад към проекти</a>
    <h1 style="margin:0;"><?= $is_new ? 'Нов проект' : h($project['title']) ?></h1>
  </div>
  <button type="submit" form="projectEditForm" class="btn btn--primary">Запази</button>
</div>

<form id="projectEditForm" method="POST"
      action="/admin/pages.php?page=projects"
      enctype="multipart/form-data" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="projects_save">
  <input type="hidden" name="proj_index" value="<?= h($edit_idx) ?>">
  <input type="hidden" class="proj-images-field" name="proj_images"
         value="<?= h(json_encode($project['images'] ?? [], JSON_UNESCAPED_UNICODE)) ?>">

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div class="form-group">
      <label>Заглавие <?= $lbl_bg_badge ?></label>
      <input type="text" id="projTitleBg" name="proj_title"
             value="<?= h($project['title']) ?>" required>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Title <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('projTitleBg','projTitleEn',this)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <input type="text" id="projTitleEn" name="proj_title_en"
             value="<?= h($project['title_en'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Текст <?= $lbl_bg_badge ?></label>
      <textarea id="projTextBg" name="proj_text" rows="8"><?= h($project['text'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Text <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('projTextBg','projTextEn',this,true)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <textarea id="projTextEn" name="proj_text_en" rows="8"><?= h($project['text_en'] ?? '') ?></textarea>
    </div>
  </div>

  <div class="form-group" style="margin-top:.5rem;">
    <label>Снимки</label>
    <div class="proj-thumbs" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem;">
      <?php foreach (($project['images'] ?? []) as $img): ?>
        <div class="proj-thumb" style="position:relative;display:inline-block;">
          <img src="<?= h($img) ?>" style="height:70px;border-radius:4px;object-fit:cover;">
          <button type="button"
                  onclick="removeProjectImage(this,<?= h(json_encode($img)) ?>)"
                  style="position:absolute;top:-4px;right:-4px;background:#c0392b;color:#fff;
                         border:none;border-radius:50%;width:18px;height:18px;font-size:11px;
                         cursor:pointer;line-height:1;"
                  aria-label="Премахни снимка">✕</button>
        </div>
      <?php endforeach; ?>
    </div>
    <input type="file" name="proj_image_file" accept="image/*" data-om-crop>
    <button type="button" class="btn btn--outline"
            style="margin-top:.5rem;font-size:.82rem;"
            onclick="_pickProjectImage(this)">Избери от библиотека</button>
    <small style="color:var(--text-muted);display:block;margin-top:.25rem;">Качи нова снимка (добавя се към съществуващите).</small>
  </div>

  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>

<script>
tinymce.init(Object.assign({}, window._tinyBase, {
  selector: '#projTextBg, #projTextEn',
  min_height: 200
}));
function removeProjectImage(btn, imgPath) {
  var thumb  = btn.closest('.proj-thumb');
  var hidden = document.querySelector('.proj-images-field');
  var images = JSON.parse(hidden.value || '[]');
  images = images.filter(function(p){ return p !== imgPath; });
  hidden.value = JSON.stringify(images);
  thumb.remove();
}
function _pickProjectImage(btn) {
  openMediaPicker(function(p) {
    var hidden = document.querySelector('.proj-images-field');
    var images = JSON.parse(hidden.value || '[]');
    images.push(p);
    hidden.value = JSON.stringify(images);
    var thumb = document.createElement('div');
    thumb.className = 'proj-thumb';
    thumb.style.cssText = 'position:relative;display:inline-block;';
    thumb.innerHTML =
      '<img src="' + p + '" style="height:70px;border-radius:4px;object-fit:cover;">' +
      '<button type="button" onclick="removeProjectImage(this,' + JSON.stringify(p) + ')" ' +
        'style="position:absolute;top:-4px;right:-4px;background:#c0392b;color:#fff;' +
               'border:none;border-radius:50%;width:18px;height:18px;font-size:11px;cursor:pointer;line-height:1;" ' +
        'aria-label="Премахни снимка">✕</button>';
    document.querySelector('.proj-thumbs').appendChild(thumb);
  });
}
</script>

<?php endif; ?>
<?php // Exit early — don't render the list view
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php';
exit;
endif; // end $edit_idx !== null
?>
<!-- ══ PROJECTS LIST ══ -->
<?php
  // Sort existing projects by order field; assign defaults if missing
  foreach ($projects as $i => &$_p) {
      if (!isset($_p['order'])) $_p['order'] = $i;
  }
  unset($_p);
  usort($projects, fn($a, $b) => (int)$a['order'] - (int)$b['order']);
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад</a>
    <h1 style="margin:0;">Проекти</h1>
  </div>
  <a href="/admin/pages.php?page=projects&edit=new" class="btn btn--primary">+ Нов проект</a>
</div>

<!-- aria-live region for keyboard reorder announcements -->
<div id="reorderAnnounce" aria-live="polite" aria-atomic="true"
     style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;"></div>

<?php $flash_msgs = flash_get(); foreach ($flash_msgs as $f): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;
  <?= $f['type']==='success' ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;' : 'background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;' ?>">
  <?= h($f['message']) ?>
</div>
<?php endforeach; ?>

<?php if (!empty($_GET['saved'])): ?>
  <div class="admin-alert admin-alert--success" style="margin-bottom:1.5rem;">Запазено успешно.</div>
<?php endif; ?>

<!-- standalone CSRF token for reorder AJAX when no delete forms are present -->
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;">
  <div style="overflow-x:auto;">
  <table class="admin-table" style="width:100%;border-collapse:collapse;" id="projectsTable">
    <thead>
      <tr style="background:var(--off-white);">
        <th style="padding:.65rem .75rem;width:36px;" aria-label="Пренареди"></th>
        <th style="padding:.65rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Проект</th>
        <th style="padding:.65rem 1rem;width:120px;"></th>
        <th style="padding:.65rem .5rem;width:64px;text-align:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Ред</th>
        <th style="padding:.65rem 1rem;width:120px;"></th>
      </tr>
    </thead>
    <tbody id="projectsBody">
      <?php if (empty($projects)): ?>
        <tr><td colspan="5" style="padding:2rem;text-align:center;color:var(--text-muted);">Няма проекти. <a href="/admin/pages.php?page=projects&edit=new">Създайте първия →</a></td></tr>
      <?php else: foreach ($projects as $idx => $project):
        $thumb = !empty($project['images'][0]) ? $project['images'][0] : '';
      ?>
        <tr data-title="<?= h($project['title']) ?>" style="border-top:1px solid var(--border);cursor:default;">
          <td style="padding:.5rem .75rem;text-align:center;">
            <span class="proj-drag-handle" draggable="true"
                  aria-hidden="true"
                  style="cursor:grab;font-size:1.1rem;color:var(--text-muted);user-select:none;display:inline-block;padding:.2rem .3rem;"
                  title="Влачи за пренареждане">⠿</span>
          </td>
          <td style="padding:.75rem 1rem;">
            <div style="display:flex;align-items:center;gap:.75rem;">
              <?php if ($thumb): ?>
                <img src="<?= h($thumb) ?>" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:4px;flex-shrink:0;">
              <?php else: ?>
                <div style="width:48px;height:48px;background:var(--off-white);border-radius:4px;flex-shrink:0;"></div>
              <?php endif; ?>
              <strong style="font-size:.9rem;"><?= h($project['title']) ?></strong>
            </div>
          </td>
          <td style="padding:.75rem 1rem;text-align:right;">
            <a href="/admin/pages.php?page=projects&edit=<?= $idx ?>" class="btn btn--outline" style="font-size:.78rem;padding:.3rem .7rem;">Редактирай</a>
          </td>
          <td style="padding:.5rem;text-align:center;">
            <div style="display:flex;flex-direction:column;gap:.2rem;align-items:center;">
              <button type="button" class="btn btn--outline proj-up"
                      style="padding:.2rem .5rem;font-size:.75rem;line-height:1;"
                      aria-label="Премести нагоре — <?= h($project['title']) ?>">↑</button>
              <button type="button" class="btn btn--outline proj-down"
                      style="padding:.2rem .5rem;font-size:.75rem;line-height:1;"
                      aria-label="Премести надолу — <?= h($project['title']) ?>">↓</button>
            </div>
          </td>
          <td style="padding:.75rem 1rem;text-align:right;">
            <form method="POST" action="/admin/pages.php?page=projects" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="section" value="projects_delete">
              <input type="hidden" name="proj_index" value="<?= $idx ?>">
              <button type="submit" class="btn btn--outline"
                      style="font-size:.78rem;padding:.3rem .7rem;color:#c0392b;border-color:#f0c4c0;"
                      data-confirm="Изтриване на проекта?">Изтрий</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
(function () {
  var tbody    = document.getElementById('projectsBody');
  var announce = document.getElementById('reorderAnnounce');
  if (!tbody) return;

  // ── helpers ────────────────────────────────────────────────────────────────

  function getRows() { return Array.from(tbody.querySelectorAll('tr[data-title]')); }

  function titlesOrder() { return getRows().map(function(r){ return r.dataset.title; }); }

  function saveOrder(prevRows) {
    var token = document.querySelector('input[name="csrf_token"]');
    var csrfVal = token ? token.value : '';
    fetch('/admin/projects-reorder-ajax.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ csrf_token: csrfVal, order: titlesOrder() })
    }).then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.ok) {
          if (prevRows) { prevRows.forEach(function(row) { tbody.appendChild(row); }); updateAriaButtons(); }
          alert('Грешка при запис: ' + (d.error || ''));
        }
      })
      .catch(function(e){
        if (prevRows) { prevRows.forEach(function(row) { tbody.appendChild(row); }); updateAriaButtons(); }
        alert('Грешка: ' + e.message);
      });
  }

  function updateAriaButtons() {
    var rows = getRows();
    rows.forEach(function(row, i) {
      row.querySelector('.proj-up').disabled   = (i === 0);
      row.querySelector('.proj-down').disabled = (i === rows.length - 1);
    });
  }

  // ── keyboard ↑ ↓ ──────────────────────────────────────────────────────────

  tbody.addEventListener('click', function(e) {
    var btn = e.target.closest('.proj-up, .proj-down');
    if (!btn) return;
    var row  = btn.closest('tr');
    var rows = getRows();
    var idx  = rows.indexOf(row);
    var prevRows = getRows();
    if (btn.classList.contains('proj-up') && idx > 0) {
      tbody.insertBefore(row, rows[idx - 1]);
    } else if (btn.classList.contains('proj-down') && idx < rows.length - 1) {
      tbody.insertBefore(rows[idx + 1], row);
    } else {
      return;
    }
    updateAriaButtons();
    var newRows = getRows();
    var newPos  = newRows.indexOf(row) + 1;
    announce.textContent = row.dataset.title + ' — преместен на позиция ' + newPos;
    saveOrder(prevRows);
  });

  // ── drag-and-drop ──────────────────────────────────────────────────────────

  var dragging = null;

  tbody.addEventListener('dragstart', function(e) {
    var handle = e.target.closest('.proj-drag-handle');
    if (!handle) { e.preventDefault(); return; }
    dragging = handle.closest('tr');
    dragging.style.opacity = '0.4';
    e.dataTransfer.effectAllowed = 'move';
  });

  tbody.addEventListener('dragend', function() {
    if (dragging) { dragging.style.opacity = ''; dragging = null; }
    getRows().forEach(function(r){ r.style.borderTop = ''; });
  });

  tbody.addEventListener('dragover', function(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    var target = e.target.closest('tr[data-title]');
    getRows().forEach(function(r){ r.style.borderTop = ''; });
    if (target && target !== dragging) {
      target.style.borderTop = '2px solid var(--teal)';
    }
  });

  tbody.addEventListener('drop', function(e) {
    e.preventDefault();
    var target = e.target.closest('tr[data-title]');
    if (!target || target === dragging || !dragging) return;
    var prevRows = getRows();
    tbody.insertBefore(dragging, target);
    getRows().forEach(function(r){ r.style.borderTop = ''; });
    updateAriaButtons();
    saveOrder(prevRows);
  });

  // ── init ───────────────────────────────────────────────────────────────────
  updateAriaButtons();
}());
</script>

<?php elseif ($page === 'how_to_help'): ?>
<!-- ══ HOW TO HELP ══ -->
<?php
// ── Single way edit ───────────────────────────────────────────────────────────
$edit_way_idx = $_GET['edit_way'] ?? null;
if ($edit_way_idx !== null):
    $ways_data = $help['ways'] ?? [];
    foreach ($ways_data as $i => &$_p2) {
        if (!isset($_p2['order'])) $_p2['order'] = $i;
    }
    unset($_p2);
    usort($ways_data, fn($a, $b) => (int)$a['order'] - (int)$b['order']);

    $is_new  = ($edit_way_idx === 'new');
    $edit_way = $is_new
        ? ['title' => '', 'title_en' => '', 'text' => '', 'text_en' => '']
        : ($ways_data[(int)$edit_way_idx] ?? null);
    if (!$is_new && !$edit_way): ?>
        <p>Начинът не е намерен. <a href="/admin/pages.php?page=how_to_help">← Назад</a></p>
<?php else: ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php?page=how_to_help" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад към Как да помогна</a>
    <h1 style="margin:0;"><?= $is_new ? 'Нов начин за помощ' : h($edit_way['title']) ?></h1>
  </div>
  <button type="submit" form="wayEditForm" class="btn btn--primary">Запази</button>
</div>

<form id="wayEditForm" method="POST" action="/admin/pages.php?page=how_to_help" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="section"   value="way_save">
  <input type="hidden" name="way_index" value="<?= h($edit_way_idx) ?>">

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div class="form-group">
      <label>Заглавие <?= $lbl_bg_badge ?></label>
      <input type="text" id="wayTitleBg" name="way_title"
             value="<?= h($edit_way['title'] ?? '') ?>" required>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Title <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('wayTitleBg','wayTitleEn',this)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <input type="text" id="wayTitleEn" name="way_title_en"
             value="<?= h($edit_way['title_en'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Текст <?= $lbl_bg_badge ?></label>
      <textarea id="wayTextBg" name="way_text" rows="8"><?= h($edit_way['text'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Text <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('wayTextBg','wayTextEn',this,true)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <textarea id="wayTextEn" name="way_text_en" rows="8"><?= h($edit_way['text_en'] ?? '') ?></textarea>
    </div>
  </div>

  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>
<script>
if (window._tinyBase) {
  tinymce.init(Object.assign({}, window._tinyBase, { selector: '#wayTextBg, #wayTextEn', min_height: 200 }));
}
</script>
<?php endif; ?>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php';
exit;
endif; // end $edit_way_idx !== null
?>

<!-- ══ HOW TO HELP — title/intro form ══ -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад</a>
    <h1 style="margin:0;">Как да помогна</h1>
  </div>
  <button type="submit" form="helpForm" class="btn btn--primary">Запази</button>
</div>

<?php if (!empty($_GET['saved'])): ?>
  <div class="admin-alert admin-alert--success" style="margin-bottom:1.5rem;">Запазено успешно.</div>
<?php endif; ?>

<form id="helpForm" method="POST" action="/admin/pages.php?page=how_to_help" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="how_to_help">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div class="form-group">
      <label>Заглавие <?= $lbl_bg_badge ?></label>
      <input type="text" id="helpTitleBg" name="help_title" value="<?= h($help['title'] ?? '') ?>">
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Title <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('helpTitleBg','helpTitleEn',this)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <input type="text" id="helpTitleEn" name="help_title_en" value="<?= h($help['title_en'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Уводен текст <?= $lbl_bg_badge ?></label>
      <input type="text" id="helpIntroBg" name="help_intro" value="<?= h($help['intro'] ?? '') ?>">
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Intro <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('helpIntroBg','helpIntroEn',this)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <input type="text" id="helpIntroEn" name="help_intro_en" value="<?= h($help['intro_en'] ?? '') ?>">
    </div>
  </div>
  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>

<!-- ══ HOW TO HELP — ways sortable list ══ -->
<?php
$ways_list = $help['ways'] ?? [];
foreach ($ways_list as $i => &$_wp) {
    if (!isset($_wp['order'])) $_wp['order'] = $i;
}
unset($_wp);
usort($ways_list, fn($a, $b) => (int)$a['order'] - (int)$b['order']);
?>
<h2 style="margin:2.5rem 0 1rem;">Начини за помощ</h2>

<div id="waysReorderAnnounce" aria-live="polite" aria-atomic="true"
     style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;"></div>

<?php $flash_msgs = flash_get(); foreach ($flash_msgs as $f): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;
  <?= $f['type']==='success' ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;' : 'background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;' ?>">
  <?= h($f['message']) ?>
</div>
<?php endforeach; ?>

<div style="display:flex;justify-content:flex-end;margin-bottom:1rem;">
  <a href="/admin/pages.php?page=how_to_help&edit_way=new" class="btn btn--primary">+ Нов начин</a>
</div>

<input type="hidden" id="waysCsrfToken" name="csrf_token" value="<?= csrf_token() ?>">

<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;">
  <div style="overflow-x:auto;">
  <table class="admin-table" style="width:100%;border-collapse:collapse;" id="waysTable">
    <thead>
      <tr style="background:var(--off-white);">
        <th style="padding:.65rem .75rem;width:36px;" aria-label="Пренареди"></th>
        <th style="padding:.65rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Начин</th>
        <th style="padding:.65rem 1rem;width:120px;"></th>
        <th style="padding:.65rem .5rem;width:64px;text-align:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Ред</th>
        <th style="padding:.65rem 1rem;width:120px;"></th>
      </tr>
    </thead>
    <tbody id="waysBody">
      <?php if (empty($ways_list)): ?>
        <tr><td colspan="5" style="padding:2rem;text-align:center;color:var(--text-muted);">Няма начини. <a href="/admin/pages.php?page=how_to_help&edit_way=new">Добавете първия →</a></td></tr>
      <?php else: foreach ($ways_list as $idx => $way): ?>
        <tr data-key="<?= h($way['title']) ?>" style="border-top:1px solid var(--border);cursor:default;">
          <td style="padding:.5rem .75rem;text-align:center;">
            <span class="way-drag-handle" draggable="true" aria-hidden="true"
                  style="cursor:grab;font-size:1.1rem;color:var(--text-muted);user-select:none;display:inline-block;padding:.2rem .3rem;"
                  title="Влачи за пренареждане">⠿</span>
          </td>
          <td style="padding:.75rem 1rem;">
            <div style="display:flex;align-items:center;gap:.75rem;">
              <div style="width:48px;height:48px;background:var(--off-white);border-radius:4px;flex-shrink:0;"></div>
              <strong style="font-size:.9rem;"><?= h($way['title']) ?></strong>
            </div>
          </td>
          <td style="padding:.75rem 1rem;text-align:right;">
            <a href="/admin/pages.php?page=how_to_help&edit_way=<?= $idx ?>" class="btn btn--outline" style="font-size:.78rem;padding:.3rem .7rem;">Редактирай</a>
          </td>
          <td style="padding:.5rem;text-align:center;">
            <div style="display:flex;flex-direction:column;gap:.2rem;align-items:center;">
              <button type="button" class="btn btn--outline way-up"
                      style="padding:.2rem .5rem;font-size:.75rem;line-height:1;"
                      aria-label="Премести нагоре — <?= h($way['title']) ?>">↑</button>
              <button type="button" class="btn btn--outline way-down"
                      style="padding:.2rem .5rem;font-size:.75rem;line-height:1;"
                      aria-label="Премести надолу — <?= h($way['title']) ?>">↓</button>
            </div>
          </td>
          <td style="padding:.75rem 1rem;text-align:right;">
            <form method="POST" action="/admin/pages.php?page=how_to_help" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="section"   value="way_delete">
              <input type="hidden" name="way_index" value="<?= $idx ?>">
              <button type="submit" class="btn btn--outline"
                      style="font-size:.78rem;padding:.3rem .7rem;color:#c0392b;border-color:#f0c4c0;"
                      data-confirm="Изтриване на начина?">Изтрий</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
(function () {
  var tbody    = document.getElementById('waysBody');
  var announce = document.getElementById('waysReorderAnnounce');
  if (!tbody) return;

  function getRows() { return Array.from(tbody.querySelectorAll('tr[data-key]')); }
  function keysOrder() { return getRows().map(function(r){ return r.dataset.key; }); }

  function saveOrder(prevRows) {
    var token   = document.getElementById('waysCsrfToken');
    var csrfVal = token ? token.value : '';
    fetch('/admin/ways-reorder-ajax.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ csrf_token: csrfVal, order: keysOrder() })
    }).then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.ok) {
          if (prevRows) { prevRows.forEach(function(row) { tbody.appendChild(row); }); updateAriaButtons(); }
          alert('Грешка при запис: ' + (d.error || ''));
        }
      })
      .catch(function(e){
        if (prevRows) { prevRows.forEach(function(row) { tbody.appendChild(row); }); updateAriaButtons(); }
        alert('Грешка: ' + e.message);
      });
  }

  function updateAriaButtons() {
    var rows = getRows();
    rows.forEach(function(row, i) {
      row.querySelector('.way-up').disabled   = (i === 0);
      row.querySelector('.way-down').disabled = (i === rows.length - 1);
    });
  }

  tbody.addEventListener('click', function(e) {
    var btn = e.target.closest('.way-up, .way-down');
    if (!btn) return;
    var row      = btn.closest('tr');
    var rows     = getRows();
    var idx      = rows.indexOf(row);
    var prevRows = getRows();
    if (btn.classList.contains('way-up') && idx > 0) {
      tbody.insertBefore(row, rows[idx - 1]);
    } else if (btn.classList.contains('way-down') && idx < rows.length - 1) {
      tbody.insertBefore(rows[idx + 1], row);
    } else { return; }
    updateAriaButtons();
    var newPos = getRows().indexOf(row) + 1;
    announce.textContent = row.dataset.key + ' — преместен на позиция ' + newPos;
    saveOrder(prevRows);
  });

  var dragging = null;
  tbody.addEventListener('dragstart', function(e) {
    var handle = e.target.closest('.way-drag-handle');
    if (!handle) { e.preventDefault(); return; }
    dragging = handle.closest('tr');
    dragging.style.opacity = '0.4';
    e.dataTransfer.effectAllowed = 'move';
  });
  tbody.addEventListener('dragend', function() {
    if (dragging) { dragging.style.opacity = ''; dragging = null; }
    getRows().forEach(function(r){ r.style.borderTop = ''; });
  });
  tbody.addEventListener('dragover', function(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    var target = e.target.closest('tr[data-key]');
    getRows().forEach(function(r){ r.style.borderTop = ''; });
    if (target && target !== dragging) target.style.borderTop = '2px solid var(--teal)';
  });
  tbody.addEventListener('drop', function(e) {
    e.preventDefault();
    var target = e.target.closest('tr[data-key]');
    if (!target || target === dragging || !dragging) return;
    var prevRows = getRows();
    tbody.insertBefore(dragging, target);
    getRows().forEach(function(r){ r.style.borderTop = ''; });
    updateAriaButtons();
    saveOrder(prevRows);
  });

  updateAriaButtons();
}());
</script>

<?php elseif ($page === 'shop'): ?>
<!-- ══ SHOP ══ -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад</a>
    <h1 style="margin:0;">Магазин — текст за дарения</h1>
  </div>
  <button type="submit" form="shopForm" class="btn btn--primary">Запази</button>
</div>
<form id="shopForm" method="POST" action="/admin/pages.php?page=shop" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="shop">
  <div class="form-group">
    <label>Текст <?= $lbl_bg_badge ?></label>
    <textarea id="shopBg" name="donation_text_bg" rows="4"><?= h($shop['donation_text_bg'] ?? '') ?></textarea>
  </div>
  <div class="form-group">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
      <label style="margin:0;">Text <?= $lbl_en_badge ?></label>
      <button type="button" class="btn btn--outline translate-legal-btn" data-src="shopBg" data-tgt="shopEn" style="font-size:.78rem;padding:.25rem .6rem;">✦ Translate from BG</button>
    </div>
    <textarea id="shopEn" name="donation_text_en" rows="4"><?= h($shop['donation_text_en'] ?? '') ?></textarea>
  </div>
  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>
<script>
tinymce.init(Object.assign({}, window._tinyBase, { selector: '#shopBg, #shopEn', min_height: 160 }));
</script>

<?php elseif ($page === 'legal_privacy'): ?>
<!-- ══ PRIVACY POLICY ══ -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад</a>
    <h1 style="margin:0;">Политика за поверителност</h1>
  </div>
  <button type="submit" form="legalPrivacyForm" class="btn btn--primary">Запази</button>
</div>
<p style="color:var(--text-muted);font-size:0.9rem;margin-bottom:1.5rem;">Съдържанието се записва като HTML. Използвайте &lt;h2&gt;, &lt;p&gt;, &lt;ul&gt;/&lt;li&gt; и &lt;a&gt; тагове.</p>
<form id="legalPrivacyForm" method="POST" action="/admin/pages.php?page=legal_privacy" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="legal">
  <input type="hidden" name="legal_key" value="privacy">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div class="form-group">
      <label>Съдържание <?= $lbl_bg_badge ?></label>
      <textarea id="legalBg" name="legal_content" rows="30" style="font-family:monospace;font-size:0.85rem;"><?= h($legal['privacy'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Content <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline translate-legal-btn" data-src="legalBg" data-tgt="legalEn" style="font-size:.78rem;padding:.25rem .6rem;">✦ Translate from BG</button>
      </div>
      <textarea id="legalEn" name="legal_content_en" rows="30" style="font-family:monospace;font-size:0.85rem;"><?= h($legal['privacy_en'] ?? '') ?></textarea>
    </div>
  </div>
  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>
<script>
tinymce.init(Object.assign({}, window._tinyBase, { selector: '#legalBg, #legalEn', min_height: 400, menubar: true }));
</script>

<?php elseif ($page === 'legal_info'): ?>
<!-- ══ LEGAL INFO ══ -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад</a>
    <h1 style="margin:0;">Правна информация</h1>
  </div>
  <button type="submit" form="legalInfoForm" class="btn btn--primary">Запази</button>
</div>
<p style="color:var(--text-muted);font-size:0.9rem;margin-bottom:1.5rem;">Съдържанието се записва като HTML.</p>
<form id="legalInfoForm" method="POST" action="/admin/pages.php?page=legal_info" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="legal">
  <input type="hidden" name="legal_key" value="legal_info">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div class="form-group">
      <label>Съдържание <?= $lbl_bg_badge ?></label>
      <textarea id="legalBg" name="legal_content" rows="30" style="font-family:monospace;font-size:0.85rem;"><?= h($legal['legal_info'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Content <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline translate-legal-btn" data-src="legalBg" data-tgt="legalEn" style="font-size:.78rem;padding:.25rem .6rem;">✦ Translate from BG</button>
      </div>
      <textarea id="legalEn" name="legal_content_en" rows="30" style="font-family:monospace;font-size:0.85rem;"><?= h($legal['legal_info_en'] ?? '') ?></textarea>
    </div>
  </div>
  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>
<script>
tinymce.init(Object.assign({}, window._tinyBase, { selector: '#legalBg, #legalEn', min_height: 400, menubar: true }));
</script>

<?php elseif ($page === 'legal_terms'): ?>
<!-- ══ TERMS ══ -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад</a>
    <h1 style="margin:0;">Условия за ползване</h1>
  </div>
  <button type="submit" form="legalTermsForm" class="btn btn--primary">Запази</button>
</div>
<p style="color:var(--text-muted);font-size:0.9rem;margin-bottom:1.5rem;">Съдържанието се записва като HTML.</p>
<form id="legalTermsForm" method="POST" action="/admin/pages.php?page=legal_terms" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="legal">
  <input type="hidden" name="legal_key" value="terms">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div class="form-group">
      <label>Съдържание <?= $lbl_bg_badge ?></label>
      <textarea id="legalBg" name="legal_content" rows="30" style="font-family:monospace;font-size:0.85rem;"><?= h($legal['terms'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Content <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline translate-legal-btn" data-src="legalBg" data-tgt="legalEn" style="font-size:.78rem;padding:.25rem .6rem;">✦ Translate from BG</button>
      </div>
      <textarea id="legalEn" name="legal_content_en" rows="30" style="font-family:monospace;font-size:0.85rem;"><?= h($legal['terms_en'] ?? '') ?></textarea>
    </div>
  </div>
  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>
<script>
tinymce.init(Object.assign({}, window._tinyBase, { selector: '#legalBg, #legalEn', min_height: 400, menubar: true }));
</script>

<?php else: ?>
  <p>Непозната страница. <a href="/admin/pages.php">← Назад</a></p>
<?php endif; ?>

<!-- translate helpers (txField/txEl/_tmGet/_tmSet) live in admin-footer.php so they load on every screen, incl. edit sub-pages that exit before this point -->

<style>
.pwd-toggle {
    position:absolute;right:.5rem;top:50%;transform:translateY(-50%);
    background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:.8rem;padding:.25rem .4rem;
}
.pwd-toggle:hover { color:var(--text); }
</style>
<script>
function togglePwd(btn) {
    var input = btn.previousElementSibling;
    var show  = input.type === 'password';
    input.type      = show ? 'text' : 'password';
    btn.textContent = show ? 'Скрий' : 'Покажи';
}
</script>

<script>
<?php if ($page === '' || $page === 'home'): ?>
initAutosave({ key: 'page:home',          formId: 'homeForm',     tinyIds: [] });
initAutosave({ key: 'page:campaign-text', formId: 'campaignForm', tinyIds: ['campTextBg', 'campTextEn'] });
<?php endif; ?>
<?php if ($page === 'about' && $edit_team_idx === null): ?>
initAutosave({ key: 'page:about', formId: 'aboutForm', tinyIds: ['aboutIntroBg', 'aboutIntroEn'] });
<?php endif; ?>
<?php if ($page === 'about' && $edit_team_idx !== null): ?>
initAutosave({ key: 'page:team:<?= (int)$edit_team_idx ?>', formId: 'teamEditForm', tinyIds: ['teamBioBg', 'teamBioEn'] });
<?php endif; ?>
<?php if ($page === 'projects' && $edit_idx !== null): ?>
initAutosave({ key: 'page:project:<?= (int)$edit_idx ?>', formId: 'projectEditForm', tinyIds: ['projTextBg', 'projTextEn'] });
<?php endif; ?>
<?php if ($page === 'how_to_help' && $edit_way_idx !== null): ?>
initAutosave({ key: 'page:way:<?= (int)$edit_way_idx ?>', formId: 'wayEditForm', tinyIds: ['wayTextBg', 'wayTextEn'] });
<?php endif; ?>
<?php if ($page === 'shop'): ?>
initAutosave({ key: 'page:shop', formId: 'shopForm', tinyIds: ['shopBg', 'shopEn'] });
<?php endif; ?>
<?php if ($page === 'legal_privacy'): ?>
initAutosave({ key: 'page:legal-privacy', formId: 'legalPrivacyForm', tinyIds: ['legalBg', 'legalEn'] });
<?php endif; ?>
<?php if ($page === 'legal_info'): ?>
initAutosave({ key: 'page:legal-info', formId: 'legalInfoForm', tinyIds: ['legalBg', 'legalEn'] });
<?php endif; ?>
<?php if ($page === 'legal_terms'): ?>
initAutosave({ key: 'page:legal-terms', formId: 'legalTermsForm', tinyIds: ['legalBg', 'legalEn'] });
<?php endif; ?>
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>

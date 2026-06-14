<?php
$page_title_admin = 'Кампания';
$active_nav       = 'campaign';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/translator.php';
admin_require_admin();

$_tinymce_key    = setting_get('tinymce_api_key', 'no-api-key');
$deepl_ready     = deepl_is_configured();
$page_head_extra = '<script src="https://cdn.tiny.cloud/1/' . h($_tinymce_key) . '/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>';

$pdo    = get_pdo();
$saved  = false;
$errors = [];

// Ensure EN columns exist (idempotent)
try { $pdo->exec("ALTER TABLE campaign_rewards ADD COLUMN title_en VARCHAR(200) NOT NULL DEFAULT '' AFTER title"); } catch (Throwable) {}
try { $pdo->exec("ALTER TABLE campaign_rewards ADD COLUMN description_en TEXT NOT NULL AFTER description"); } catch (Throwable) {}
// Ensure ticket columns exist (idempotent)
try { $pdo->exec("ALTER TABLE campaign_pledges ADD COLUMN pledge_type ENUM('donation','ticket') NOT NULL DEFAULT 'donation' AFTER pledge_number"); } catch (Throwable) {}
try { $pdo->exec("ALTER TABLE campaign_pledges ADD COLUMN ticket_code VARCHAR(64) NULL"); } catch (Throwable) {}
try { $pdo->exec("ALTER TABLE campaign_pledges ADD COLUMN ticket_path VARCHAR(255) NULL"); } catch (Throwable) {}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $section = $_POST['section'] ?? '';

    // ── General settings ──────────────────────────────────────────────────────
    if ($section === 'general') {
        $active    = isset($_POST['campaign_active']) ? '1' : '0';
        $title        = trim($_POST['campaign_title']            ?? '');
        $title_en     = trim($_POST['campaign_title_en']         ?? '');
        $meta_desc    = trim($_POST['campaign_meta_description']    ?? '');
        $meta_desc_en = trim($_POST['campaign_meta_description_en'] ?? '');
        $desc         = trim($_POST['campaign_description']      ?? '');
        $desc_en      = trim($_POST['campaign_description_en']   ?? '');
        $target       = (float)($_POST['campaign_target_eur']    ?? 5000);
        $end_date     = trim($_POST['campaign_end_date']         ?? '');
        $total_packs  = max(0, (int)($_POST['lafetki_total_packs'] ?? 100));

        if ($target <= 0) $errors[] = 'Целевата сума трябва да е положително число.';
        if (empty($errors)) {
            setting_set('campaign_active',               $active);
            setting_set('campaign_title',                $title);
            setting_set('campaign_title_en',             $title_en);
            setting_set('campaign_meta_description',     $meta_desc);
            setting_set('campaign_meta_description_en',  $meta_desc_en);
            setting_set('campaign_description',          $desc);
            setting_set('campaign_description_en',       $desc_en);
            setting_set('campaign_target_eur',           (string)$target);
            setting_set('campaign_end_date',             $end_date);
            setting_set('lafetki_total_packs',           (string)$total_packs);
            if ($active === '1') setting_set('campaign_url', SITE_URL . '/campaign/');
            $saved = true;
        }
    }

    // ── Photo upload / library ────────────────────────────────────────────────
    if ($section === 'photos') {
        $photos  = json_decode(setting_get('campaign_photos', '[]'), true) ?: [];
        $img_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/campaign/';
        if (!is_dir($img_dir)) mkdir($img_dir, 0755, true);

        $del = trim($_POST['delete_photo'] ?? '');
        if ($del !== '') {
            $photos = array_values(array_filter($photos, fn($p) => $p !== $del));
            $full   = $_SERVER['DOCUMENT_ROOT'] . $del;
            if (is_file($full)) @unlink($full);
            setting_set('campaign_photos', json_encode($photos));
            $saved = true;
        }

        if (!empty($_POST['image_from_library'])) {
            $lib = $_POST['image_from_library'];
            if (preg_match('#^/assets/images/[a-zA-Z0-9/_.\-]+$#', $lib)) {
                if (!in_array($lib, $photos)) $photos[] = $lib;
                setting_set('campaign_photos', json_encode($photos));
                $saved = true;
            }
        }

        if (!empty($_FILES['photos']['tmp_name'])) {
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            foreach ($_FILES['photos']['tmp_name'] as $i => $tmp) {
                if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $ftype = mime_content_type($tmp);
                if (!isset($allowed[$ftype])) continue;
                $name = 'photo_' . time() . '_' . $i . '.' . $allowed[$ftype];
                if (move_uploaded_file($tmp, $img_dir . $name)) {
                    $photos[] = '/assets/images/campaign/' . $name;
                }
            }
            setting_set('campaign_photos', json_encode($photos));
            $saved = true;
        }
    }

    // ── Budget ────────────────────────────────────────────────────────────────
    if ($section === 'budget') {
        $labels    = $_POST['budget_label']    ?? [];
        $labels_en = $_POST['budget_label_en'] ?? [];
        $amounts   = $_POST['budget_amount']   ?? [];
        $paid      = $_POST['budget_paid']     ?? [];
        $rows      = [];
        foreach ($labels as $i => $lbl) {
            $lbl    = trim($lbl);
            $lbl_en = trim($labels_en[$i] ?? '');
            $amt    = (float)($amounts[$i] ?? 0);
            if ($lbl === '' && $amt <= 0) continue;
            $rows[] = [
                'label'      => $lbl,
                'label_en'   => $lbl_en,
                'amount_eur' => $amt,
                'paid'       => (($paid[$i] ?? '0') === '1'),
            ];
        }
        setting_set('campaign_budget', json_encode($rows, JSON_UNESCAPED_UNICODE));
        $saved = true;
    }

    // ── FAQ ───────────────────────────────────────────────────────────────────
    if ($section === 'faq') {
        $questions    = $_POST['faq_q']    ?? [];
        $answers      = $_POST['faq_a']    ?? [];
        $questions_en = $_POST['faq_q_en'] ?? [];
        $answers_en   = $_POST['faq_a_en'] ?? [];
        $rows         = [];
        foreach ($questions as $i => $q) {
            $q    = trim($q);
            $a    = trim($answers[$i]      ?? '');
            $q_en = trim($questions_en[$i] ?? '');
            $a_en = trim($answers_en[$i]   ?? '');
            if ($q === '' && $a === '' && $q_en === '' && $a_en === '') continue;
            $rows[] = ['q' => $q, 'a' => $a, 'q_en' => $q_en, 'a_en' => $a_en];
        }
        setting_set('campaign_faq', json_encode($rows, JSON_UNESCAPED_UNICODE));
        $saved = true;
    }

    // ── Risks ─────────────────────────────────────────────────────────────────
    if ($section === 'risks') {
        setting_set('campaign_risks',    trim($_POST['campaign_risks']    ?? ''));
        setting_set('campaign_risks_en', trim($_POST['campaign_risks_en'] ?? ''));
        $saved = true;
    }

    // ── Rewards ───────────────────────────────────────────────────────────────
    if ($section === 'rewards') {
        $ids     = $_POST['reward_id']      ?? [];
        $titles  = $_POST['reward_title']   ?? [];
        $ten     = $_POST['reward_title_en'] ?? [];
        $descs   = $_POST['reward_desc']    ?? [];
        $den     = $_POST['reward_desc_en'] ?? [];
        $amts    = $_POST['reward_amount']  ?? [];
        $acts    = $_POST['reward_active']  ?? [];

        foreach ($ids as $pos => $id) {
            $id  = (int)$id;
            $amt = (float)($amts[$pos] ?? 0);
            if (!$id || $amt <= 0) continue;
            $pdo->prepare("
                UPDATE campaign_rewards
                SET title=?, title_en=?, description=?, description_en=?, amount_eur=?, active=?, position=?
                WHERE id=?
            ")->execute([
                trim($titles[$pos] ?? ''),
                trim($ten[$pos]    ?? ''),
                trim($descs[$pos]  ?? ''),
                trim($den[$pos]    ?? ''),
                $amt,
                isset($acts[$id]) ? 1 : 0,
                $pos + 1,
                $id,
            ]);
        }
        $saved = true;
    }

    // ── Event / ticket settings ───────────────────────────────────────────────
    if ($section === 'event') {
        $ev_active  = isset($_POST['event_active']) ? '1' : '0';
        $ev_name    = trim($_POST['event_name']    ?? '');
        $ev_date    = trim($_POST['event_date']    ?? '');
        $ev_time    = trim($_POST['event_time']    ?? '');
        $ev_place   = trim($_POST['event_place']   ?? '');
        $ev_fb_url  = trim($_POST['event_fb_url']  ?? '');
        $ev_price   = round((float)($_POST['event_ticket_price'] ?? 0), 2);
        $ev_desc    = trim($_POST['event_description'] ?? '');

        if ($ev_name === '')  $errors[] = 'Въведете название на събитието.';
        if ($ev_date === '')  $errors[] = 'Въведете дата на събитието.';
        if ($ev_price <= 0)   $errors[] = 'Цената на билета трябва да е положително число.';

        if (empty($errors)) {
            setting_set('event_active',        $ev_active);
            setting_set('event_name',          $ev_name);
            setting_set('event_date',          $ev_date);
            setting_set('event_time',          $ev_time);
            setting_set('event_place',         $ev_place);
            setting_set('event_fb_url',        $ev_fb_url);
            setting_set('event_ticket_price',  (string)$ev_price);
            setting_set('event_description',   $ev_desc);
            $saved = true;
        }
    }

    if (empty($errors)) {
        header('Location: /admin/campaign.php' . ($saved ? '?saved=1' : ''));
        exit;
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────
$active      = setting_get('campaign_active', '0');
$title        = setting_get('campaign_title',               'Помогни на Лафетки');
$title_en     = setting_get('campaign_title_en',            '');
$meta_desc    = setting_get('campaign_meta_description',    '');
$meta_desc_en = setting_get('campaign_meta_description_en', '');
$desc         = setting_get('campaign_description',         '');
$desc_en      = setting_get('campaign_description_en',      '');
$target_eur  = (float)(setting_get('campaign_target_eur', '5000') ?: '5000');
$end_date    = setting_get('campaign_end_date', '');
$total_packs = (int)setting_get('lafetki_total_packs', '100');
$photos      = json_decode(setting_get('campaign_photos', '[]'), true) ?: [];
$budget      = json_decode(setting_get('campaign_budget', '[]'), true) ?: [];
$faq         = json_decode(setting_get('campaign_faq',    '[]'), true) ?: [];
$risks       = setting_get('campaign_risks', '');
$risks_en    = setting_get('campaign_risks_en', '');
$rewards     = $pdo->query("SELECT * FROM campaign_rewards ORDER BY position")->fetchAll();

// Event / ticket settings
$ev_active  = setting_get('event_active',        '0');
$ev_name    = setting_get('event_name',          '');
$ev_date    = setting_get('event_date',          '');
$ev_time    = setting_get('event_time',          '');
$ev_place   = setting_get('event_place',         '');
$ev_fb_url  = setting_get('event_fb_url',        '');
$ev_price   = setting_get('event_ticket_price',  '');
$ev_desc    = setting_get('event_description',   '');

try {
    $ticket_stats = $pdo->query("
        SELECT COUNT(*) AS sold FROM campaign_pledges WHERE payment_status='paid' AND pledge_type='ticket'
    ")->fetch();
} catch (Throwable) {
    $ticket_stats = ['sold' => 0];
}

try {
    $stats = $pdo->query("
        SELECT COUNT(*) AS funders, COALESCE(SUM(amount_eur),0) AS raised
        FROM campaign_pledges WHERE payment_status='paid'
    ")->fetch();
} catch (Throwable) {
    $stats = ['funders' => 0, 'raised' => 0];
}

// ── Shared label styles ───────────────────────────────────────────────────────
$lbl = 'display:block;font-size:.8rem;font-weight:600;margin-bottom:.25rem;';
$inp = 'width:100%;padding:.45rem .6rem;border:1px solid #d1d5db;border-radius:5px;font-size:.88rem;box-sizing:border-box;';
$lbl_en_badge = '<span style="font-size:.68rem;font-weight:700;background:#dbeafe;color:#1d4ed8;border-radius:3px;padding:.05rem .35rem;margin-left:.4rem;vertical-align:middle;">EN</span>';

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<?php if (isset($_GET['saved']) || $saved): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;">
  Промените са запазени.
</div>
<?php endif; ?>
<?php foreach ($errors as $e): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1rem;background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;"><?= h($e) ?></div>
<?php endforeach; ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem;">
  <h1 style="margin:0;font-size:1.4rem;">Кампания</h1>
  <div style="display:flex;gap:.75rem;">
    <a href="/campaign/" target="_blank" class="btn btn--secondary" style="font-size:.85rem;">↗ Виж публичната страница</a>
    <a href="/admin/campaign-backers.php" class="btn btn--secondary" style="font-size:.85rem;">Поддръжници →</a>
  </div>
</div>

<!-- Stats bar -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:2rem;">
  <?php
    $raised_bgn = $stats['raised'] * EUR_BGN_RATE;
    $target_bgn = $target_eur    * EUR_BGN_RATE;
    $pct        = $target_eur > 0 ? min(100, round($stats['raised'] / $target_eur * 100)) : 0;
  ?>
  <div style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:1.1rem 1.25rem;">
    <div style="font-size:.75rem;text-transform:uppercase;color:#9b9590;letter-spacing:.05em;margin-bottom:.3rem;">Набрано</div>
    <div style="font-size:1.5rem;font-weight:700;color:#1b998b;"><?= number_format($stats['raised'], 2, '.', ' ') ?> EUR</div>
    <div style="font-size:.8rem;color:#6b6560;"><?= $pct ?>% от <?= number_format($target_eur, 2, '.', ' ') ?> EUR &nbsp;·&nbsp; <?= number_format($raised_bgn, 0, '.', ' ') ?> лв</div>
  </div>
  <div style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:1.1rem 1.25rem;">
    <div style="font-size:.75rem;text-transform:uppercase;color:#9b9590;letter-spacing:.05em;margin-bottom:.3rem;">Поддръжници</div>
    <div style="font-size:1.5rem;font-weight:700;"><?= (int)$stats['funders'] ?></div>
  </div>
  <div style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:1.1rem 1.25rem;">
    <div style="font-size:.75rem;text-transform:uppercase;color:#9b9590;letter-spacing:.05em;margin-bottom:.3rem;">Статус</div>
    <div style="font-size:1.1rem;font-weight:600;color:<?= $active === '1' ? '#2d6a35' : '#c0392b' ?>;">
      <?= $active === '1' ? 'Активна' : 'Неактивна' ?>
    </div>
  </div>
</div>

<!-- ── Section quick-nav ─────────────────────────────────────────────────────── -->
<div id="secNav" style="position:sticky;top:0;z-index:50;background:#fff;border-bottom:2px solid #e8ddd5;margin-bottom:1.5rem;padding:.5rem 0;display:flex;gap:.4rem;flex-wrap:wrap;box-shadow:0 2px 4px rgba(0,0,0,.06);">
  <a href="#sec-general" data-sec="sec-general" style="text-decoration:none;padding:.3rem .8rem;border-radius:20px;font-size:.8rem;font-weight:600;cursor:pointer;background:#1e3a5f;color:#fff;">📋 Основни</a>
  <a href="#sec-rewards" data-sec="sec-rewards" style="text-decoration:none;padding:.3rem .8rem;border-radius:20px;font-size:.8rem;font-weight:600;cursor:pointer;background:#f3f4f6;color:#374151;">🏅 Награди</a>
  <a href="#sec-budget"  data-sec="sec-budget"  style="text-decoration:none;padding:.3rem .8rem;border-radius:20px;font-size:.8rem;font-weight:600;cursor:pointer;background:#f3f4f6;color:#374151;">💰 Бюджет</a>
  <a href="#sec-photos"  data-sec="sec-photos"  style="text-decoration:none;padding:.3rem .8rem;border-radius:20px;font-size:.8rem;font-weight:600;cursor:pointer;background:#f3f4f6;color:#374151;">🖼 Снимки</a>
  <a href="#sec-faq"     data-sec="sec-faq"     style="text-decoration:none;padding:.3rem .8rem;border-radius:20px;font-size:.8rem;font-weight:600;cursor:pointer;background:#f3f4f6;color:#374151;">❓ FAQ</a>
  <a href="#sec-risks"   data-sec="sec-risks"   style="text-decoration:none;padding:.3rem .8rem;border-radius:20px;font-size:.8rem;font-weight:600;cursor:pointer;background:#f3f4f6;color:#374151;">⚠️ Рискове</a>
  <a href="#sec-event"   data-sec="sec-event"   style="text-decoration:none;padding:.3rem .8rem;border-radius:20px;font-size:.8rem;font-weight:600;cursor:pointer;background:#f3f4f6;color:#374151;">🎟 Събитие</a>
</div>

<!-- ── General settings ──────────────────────────────────────────────────────── -->
<div id="sec-general" style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:1.5rem;margin-bottom:1.5rem;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem;">
    <h2 style="margin:0;font-size:1.05rem;">Основни настройки</h2>
    <div style="display:flex;gap:.5rem;">
      <?php if ($deepl_ready): ?>
      <button type="button" id="translateGeneralBtn" class="btn btn--outline" style="font-size:.82rem;">🌐 Преведи на EN</button>
      <?php endif; ?>
      <button type="submit" form="generalForm" class="btn btn--primary">Запази</button>
    </div>
  </div>
  <form method="POST" id="generalForm">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="general">

    <div style="margin-bottom:1rem;">
      <label style="display:flex;align-items:center;gap:.5rem;font-weight:600;cursor:pointer;">
        <input type="checkbox" name="campaign_active" value="1" <?= $active === '1' ? 'checked' : '' ?>>
        Кампанията е активна (видима публично)
      </label>
    </div>

    <!-- Title -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
      <div>
        <label style="<?= $lbl ?>">Заглавие (БГ)</label>
        <input type="text" name="campaign_title" value="<?= h($title) ?>" style="<?= $inp ?>">
      </div>
      <div>
        <label style="<?= $lbl ?>">Title <?= $lbl_en_badge ?></label>
        <input type="text" name="campaign_title_en" value="<?= h($title_en) ?>" style="<?= $inp ?>">
      </div>
    </div>

    <!-- Meta description -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
      <div>
        <label style="<?= $lbl ?>">Мета описание (БГ) <span style="font-size:.72rem;color:#9b9590;font-weight:400;">— текстът, показван при споделяне на връзката</span></label>
        <input type="text" name="campaign_meta_description" id="campaignMetaDesc"
               value="<?= h($meta_desc) ?>" style="<?= $inp ?>"
               placeholder="Подкрепи Лафетки — ...">
      </div>
      <div>
        <label style="<?= $lbl ?>">Meta description <?= $lbl_en_badge ?></label>
        <input type="text" name="campaign_meta_description_en" id="campaignMetaDescEn"
               value="<?= h($meta_desc_en) ?>" style="<?= $inp ?>"
               placeholder="Support Lafetki — ...">
      </div>
    </div>

    <!-- Description -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
      <div>
        <label style="<?= $lbl ?>">Описание (БГ)</label>
        <textarea name="campaign_description" id="campaignDesc" rows="5"
                  style="<?= $inp ?> resize:vertical;"><?= $desc ?></textarea>
      </div>
      <div>
        <label style="<?= $lbl ?>">Description <?= $lbl_en_badge ?></label>
        <textarea name="campaign_description_en" id="campaignDescEn" rows="5"
                  style="<?= $inp ?> resize:vertical;"><?= $desc_en ?></textarea>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
      <div>
        <label style="<?= $lbl ?>">Целева сума (EUR)</label>
        <input type="number" name="campaign_target_eur" value="<?= h((string)$target_eur) ?>" min="1" step="0.01"
               style="<?= $inp ?>">
      </div>
      <div>
        <label style="<?= $lbl ?>">Крайна дата</label>
        <input type="date" name="campaign_end_date" value="<?= h($end_date) ?>"
               style="<?= $inp ?>">
      </div>
    </div>
    <div style="margin-top:1rem;">
      <label style="<?= $lbl ?>">Общо отпечатани пакета „Лафетки“</label>
      <input type="number" name="lafetki_total_packs" value="<?= h((string)$total_packs) ?>" min="0" step="1"
             style="<?= $inp ?>">
      <div style="font-size:.8rem;color:#6b6560;margin-top:.35rem;">
        Броячът „продадени пакета“ на страницата показва това число минус текущата наличност на Ледоразбивачи. Увеличете го, ако отпечатате още.
      </div>
    </div>
    <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
      <button type="submit" class="btn btn--primary">Запази</button>
    </div>
  </form>
</div>

<!-- ── Rewards ────────────────────────────────────────────────────────────────── -->
<div id="sec-rewards" style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:1.5rem;margin-bottom:1.5rem;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem;">
    <h2 style="margin:0;font-size:1.05rem;">Награди (4 нива)</h2>
    <div style="display:flex;gap:.5rem;">
      <?php if ($deepl_ready): ?>
      <button type="button" id="translateRewardsBtn" class="btn btn--outline" style="font-size:.82rem;">🌐 Преведи на EN</button>
      <?php endif; ?>
      <button type="submit" form="rewardsForm" class="btn btn--primary">Запази</button>
    </div>
  </div>
  <form method="POST" id="rewardsForm">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="rewards">
    <div style="display:grid;gap:1rem;">
      <?php foreach ($rewards as $i => $r): ?>
      <div style="border:1px solid #e8ddd5;border-radius:6px;padding:1rem;">
        <input type="hidden" name="reward_id[<?= $i ?>]" value="<?= $r['id'] ?>">
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.75rem;flex-wrap:wrap;">
          <span style="font-size:.8rem;color:#9b9590;font-weight:700;">#<?= $i+1 ?></span>
          <label style="display:flex;align-items:center;gap:.4rem;font-size:.85rem;cursor:pointer;margin-left:auto;">
            <input type="checkbox" name="reward_active[<?= $r['id'] ?>]" value="1" <?= $r['active'] ? 'checked' : '' ?>>
            Активна
          </label>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.5rem;">
          <div>
            <label style="<?= $lbl ?>">Заглавие (БГ)</label>
            <input type="text" name="reward_title[<?= $i ?>]" value="<?= h($r['title']) ?>"
                   style="<?= $inp ?>">
          </div>
          <div>
            <label style="<?= $lbl ?>">Title <?= $lbl_en_badge ?></label>
            <input type="text" name="reward_title_en[<?= $i ?>]" value="<?= h($r['title_en'] ?? '') ?>"
                   style="<?= $inp ?>">
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.5rem;">
          <div>
            <label style="<?= $lbl ?>">Описание (БГ)</label>
            <textarea name="reward_desc[<?= $i ?>]" class="reward-desc" rows="2"
                      style="<?= $inp ?> resize:vertical;"><?= $r['description'] ?></textarea>
          </div>
          <div>
            <label style="<?= $lbl ?>">Description <?= $lbl_en_badge ?></label>
            <textarea name="reward_desc_en[<?= $i ?>]" class="reward-desc-en" rows="2"
                      style="<?= $inp ?> resize:vertical;"><?= $r['description_en'] ?? '' ?></textarea>
          </div>
        </div>
        <div style="width:50%;padding-right:.375rem;box-sizing:border-box;">
          <label style="<?= $lbl ?>">Сума (EUR)</label>
          <input type="number" name="reward_amount[<?= $i ?>]" value="<?= h((string)$r['amount_eur']) ?>" min="1" step="0.01"
                 style="<?= $inp ?>">
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
      <button type="submit" class="btn btn--primary">Запази</button>
    </div>
  </form>
</div>

<!-- ── Budget ─────────────────────────────────────────────────────────────────── -->
<div id="sec-budget" style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:1.5rem;margin-bottom:1.5rem;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem;">
    <h2 style="margin:0;font-size:1.05rem;">Бюджет</h2>
    <div style="display:flex;gap:.5rem;">
      <?php if ($deepl_ready): ?>
      <button type="button" id="translateBudgetBtn" class="btn btn--outline" style="font-size:.82rem;">🌐 Преведи на EN</button>
      <?php endif; ?>
      <button type="submit" form="budgetForm" class="btn btn--primary">Запази</button>
    </div>
  </div>
  <form method="POST" id="budgetForm">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="budget">
    <table style="width:100%;border-collapse:collapse;margin-bottom:1rem;" id="budgetTable">
      <thead>
        <tr style="font-size:.78rem;text-transform:uppercase;color:#6b6560;">
          <th style="text-align:left;padding:.4rem .6rem;font-weight:600;">Перо (БГ)</th>
          <th style="text-align:left;padding:.4rem .6rem;font-weight:600;">Item <?= $lbl_en_badge ?></th>
          <th style="text-align:left;padding:.4rem .6rem;font-weight:600;width:130px;">Сума (EUR)</th>
          <th style="text-align:center;padding:.4rem .6rem;font-weight:600;width:80px;">Платено</th>
          <th style="width:36px;"></th>
        </tr>
      </thead>
      <tbody id="budgetBody">
        <?php foreach ($budget as $i => $row): ?>
        <tr>
          <td style="padding:.35rem .6rem;">
            <input type="text" name="budget_label[]" value="<?= h($row['label']) ?>"
                   style="<?= $inp ?>">
          </td>
          <td style="padding:.35rem .6rem;">
            <input type="text" name="budget_label_en[]" value="<?= h($row['label_en'] ?? '') ?>"
                   style="<?= $inp ?>">
          </td>
          <td style="padding:.35rem .6rem;">
            <input type="number" name="budget_amount[]" value="<?= h((string)$row['amount_eur']) ?>" min="0" step="0.01"
                   style="<?= $inp ?>">
          </td>
          <td style="padding:.35rem .6rem;text-align:center;">
            <input type="hidden" name="budget_paid[]" value="<?= !empty($row['paid']) ? '1' : '0' ?>">
            <input type="checkbox" class="budget-paid-cb" <?= !empty($row['paid']) ? 'checked' : '' ?>
                   title="Перото е платено" style="width:18px;height:18px;cursor:pointer;">
          </td>
          <td style="padding:.35rem .5rem;text-align:center;">
            <button type="button" onclick="this.closest('tr').remove();updateTotal()"
                    style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:1.1rem;line-height:1;">✕</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="border-top:2px solid #e8ddd5;font-weight:700;">
          <td style="padding:.6rem .6rem;font-size:.9rem;" colspan="2">Общо / Total</td>
          <td style="padding:.6rem .6rem;font-size:.9rem;" id="budgetTotal">
            <?= number_format(array_sum(array_column($budget, 'amount_eur')), 2) ?> EUR
          </td>
          <td></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
    <button type="button" id="addBudgetRow" class="btn btn--secondary" style="font-size:.85rem;">+ Добави ред</button>
    <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
      <button type="submit" class="btn btn--primary">Запази</button>
    </div>
  </form>
</div>

<!-- ── Photos ─────────────────────────────────────────────────────────────────── -->
<div id="sec-photos" style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:1.5rem;margin-bottom:1.5rem;">
  <h2 style="margin:0 0 1.25rem;font-size:1.05rem;">Снимки</h2>
  <div style="display:flex;flex-wrap:wrap;gap:.75rem;margin-bottom:1rem;">
    <?php foreach ($photos as $photo): ?>
    <div style="position:relative;width:120px;">
      <img src="<?= h($photo) ?>" alt="" style="width:120px;height:80px;object-fit:cover;border-radius:5px;border:1px solid #e8ddd5;">
      <form method="POST" style="position:absolute;top:4px;right:4px;">
        <?= csrf_field() ?>
        <input type="hidden" name="section" value="photos">
        <input type="hidden" name="delete_photo" value="<?= h($photo) ?>">
        <button type="submit"
                style="background:rgba(0,0,0,.55);color:#fff;border:none;border-radius:50%;width:22px;height:22px;font-size:.75rem;cursor:pointer;line-height:22px;text-align:center;padding:0;">✕</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <form method="POST" enctype="multipart/form-data" id="photosForm">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="photos">
    <input type="hidden" name="image_from_library" id="campaignPhotoLib">
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:.5rem;">
      <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple style="font-size:.88rem;">
      <button type="submit" class="btn btn--primary">Качи снимки</button>
    </div>
    <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem;">
      <button type="button" class="btn btn--secondary" style="font-size:.85rem;" onclick="_pickCampaignPhoto()">Избери от библиотека</button>
      <span id="campaignPhotoPreviewName" style="font-size:.82rem;color:#6b6560;"></span>
    </div>
    <div style="font-size:.78rem;color:#9b9590;">JPEG, PNG или WebP. Можеш да избереш няколко наведнъж.</div>
  </form>
</div>

<!-- ── FAQ ────────────────────────────────────────────────────────────────────── -->
<div id="sec-faq" style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:1.5rem;margin-bottom:1.5rem;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem;">
    <h2 style="margin:0;font-size:1.05rem;">Въпроси и отговори (FAQ)</h2>
    <div style="display:flex;gap:.5rem;">
      <?php if ($deepl_ready): ?>
      <button type="button" id="translateFaqBtn" class="btn btn--outline" style="font-size:.82rem;">🌐 Преведи на EN</button>
      <?php endif; ?>
      <button type="submit" form="faqForm" class="btn btn--primary">Запази</button>
    </div>
  </div>
  <form method="POST" id="faqForm">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="faq">
    <div id="faqBody" style="display:grid;gap:.75rem;margin-bottom:1rem;">
      <?php foreach ($faq as $idx => $row): ?>
      <div class="faq-row" style="border:1px solid #e8ddd5;border-radius:6px;padding:.85rem;">
        <div style="display:flex;justify-content:flex-end;margin-bottom:.5rem;">
          <button type="button" onclick="removeFaqRow(this)"
                  style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:1rem;line-height:1;">✕</button>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.5rem;">
          <div>
            <label style="<?= $lbl ?>">Въпрос (БГ)</label>
            <input type="text" name="faq_q[]" value="<?= h($row['q']) ?>"
                   style="<?= $inp ?>">
          </div>
          <div>
            <label style="<?= $lbl ?>">Question <?= $lbl_en_badge ?></label>
            <input type="text" name="faq_q_en[]" value="<?= h($row['q_en'] ?? '') ?>"
                   style="<?= $inp ?>">
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
          <div>
            <label style="<?= $lbl ?>">Отговор (БГ)</label>
            <textarea name="faq_a[]" class="faq-answer" rows="3"
                      style="<?= $inp ?> resize:vertical;"><?= $row['a'] ?></textarea>
          </div>
          <div>
            <label style="<?= $lbl ?>">Answer <?= $lbl_en_badge ?></label>
            <textarea name="faq_a_en[]" class="faq-answer-en" rows="3"
                      style="<?= $inp ?> resize:vertical;"><?= $row['a_en'] ?? '' ?></textarea>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <button type="button" id="addFaqRow" class="btn btn--secondary" style="font-size:.85rem;">+ Добави въпрос</button>
    <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
      <button type="submit" class="btn btn--primary">Запази</button>
    </div>
  </form>
</div>

<!-- ── Risks ──────────────────────────────────────────────────────────────────── -->
<div id="sec-risks" style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:1.5rem;margin-bottom:1.5rem;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem;">
    <h2 style="margin:0;font-size:1.05rem;">Рискове</h2>
    <div style="display:flex;gap:.5rem;">
      <?php if ($deepl_ready): ?>
      <button type="button" id="translateRisksBtn" class="btn btn--outline" style="font-size:.82rem;">🌐 Преведи на EN</button>
      <?php endif; ?>
      <button type="submit" form="risksForm" class="btn btn--primary">Запази</button>
    </div>
  </div>
  <form method="POST" id="risksForm">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="risks">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
      <div>
        <label style="<?= $lbl ?> margin-bottom:.4rem;">Рискове (БГ)</label>
        <textarea name="campaign_risks" id="campaignRisks" rows="6"
                  style="<?= $inp ?> resize:vertical;"><?= $risks ?></textarea>
      </div>
      <div>
        <label style="<?= $lbl ?> margin-bottom:.4rem;">Risks <?= $lbl_en_badge ?></label>
        <textarea name="campaign_risks_en" id="campaignRisksEn" rows="6"
                  style="<?= $inp ?> resize:vertical;"><?= $risks_en ?></textarea>
      </div>
    </div>
    <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
      <button type="submit" class="btn btn--primary">Запази</button>
    </div>
  </form>
</div>

<!-- ── Event / Ticket ────────────────────────────────────────────────────────── -->
<div id="sec-event" style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:1.5rem;margin-bottom:1.5rem;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem;">
    <h2 style="margin:0;font-size:1.05rem;">🎟 Събитие &amp; билети</h2>
    <div style="display:flex;gap:.5rem;align-items:center;">
      <span style="font-size:.82rem;color:var(--text-muted);">Продадени билети: <strong><?= (int)$ticket_stats['sold'] ?></strong></span>
      <button type="button" class="btn btn--primary" onclick="tinymce.triggerSave();document.getElementById('eventForm').submit();">Запази</button>
    </div>
  </div>
  <form method="POST" id="eventForm">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="event">

    <div style="margin-bottom:1.25rem;">
      <label style="display:flex;align-items:center;gap:.5rem;font-weight:600;cursor:pointer;">
        <input type="checkbox" name="event_active" value="1" <?= $ev_active === '1' ? 'checked' : '' ?>>
        Събитието е активно (показва блок за билети на публичната страница)
      </label>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
      <div>
        <label style="<?= $lbl ?>">Название на събитието</label>
        <input type="text" name="event_name" value="<?= h($ev_name) ?>" style="<?= $inp ?>" placeholder="напр. Лафетки — Парти за старт">
      </div>
      <div>
        <label style="<?= $lbl ?>">Линк към Facebook събитие</label>
        <input type="url" name="event_fb_url" value="<?= h($ev_fb_url) ?>" style="<?= $inp ?>" placeholder="https://www.facebook.com/events/...">
      </div>
      <div>
        <label style="<?= $lbl ?>">Дата</label>
        <input type="date" name="event_date" value="<?= h($ev_date) ?>" style="<?= $inp ?>">
      </div>
      <div>
        <label style="<?= $lbl ?>">Час (незадължително)</label>
        <input type="time" name="event_time" value="<?= h($ev_time) ?>" style="<?= $inp ?>">
      </div>
      <div>
        <label style="<?= $lbl ?>">Място (незадължително)</label>
        <input type="text" name="event_place" value="<?= h($ev_place) ?>" style="<?= $inp ?>" placeholder="напр. Лаборатория за приключения, Лозен">
      </div>
      <div>
        <label style="<?= $lbl ?>">Цена на билет (EUR)</label>
        <input type="number" name="event_ticket_price" value="<?= h($ev_price) ?>" min="1" step="0.01" style="<?= $inp ?>">
      </div>
    </div>

    <div>
      <label style="<?= $lbl ?>">Описание на събитието (незадължително)</label>
      <textarea id="eventDesc" name="event_description" rows="3" style="<?= $inp ?> resize:vertical;"><?= h($ev_desc) ?></textarea>
    </div>
    <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
      <button type="button" class="btn btn--primary" onclick="tinymce.triggerSave();document.getElementById('eventForm').submit();">Запази</button>
    </div>
  </form>
</div>

<script>
// ── Budget: add row + running total ─────────────────────────────────────────
document.getElementById('addBudgetRow').addEventListener('click', function () {
  var tbody = document.getElementById('budgetBody');
  var tr = document.createElement('tr');
  tr.innerHTML = '<td style="padding:.35rem .6rem;"><input type="text" name="budget_label[]" style="width:100%;padding:.4rem .6rem;border:1px solid #d1d5db;border-radius:5px;font-size:.88rem;box-sizing:border-box;"></td>'
    + '<td style="padding:.35rem .6rem;"><input type="text" name="budget_label_en[]" style="width:100%;padding:.4rem .6rem;border:1px solid #d1d5db;border-radius:5px;font-size:.88rem;box-sizing:border-box;"></td>'
    + '<td style="padding:.35rem .6rem;"><input type="number" name="budget_amount[]" value="0" min="0" step="0.01" style="width:100%;padding:.4rem .6rem;border:1px solid #d1d5db;border-radius:5px;font-size:.88rem;box-sizing:border-box;"></td>'
    + '<td style="padding:.35rem .6rem;text-align:center;"><input type="hidden" name="budget_paid[]" value="0"><input type="checkbox" class="budget-paid-cb" title="Перото е платено" style="width:18px;height:18px;cursor:pointer;"></td>'
    + '<td style="padding:.35rem .5rem;text-align:center;"><button type="button" onclick="this.closest(\'tr\').remove();updateTotal()" style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:1.1rem;">✕</button></td>';
  tbody.appendChild(tr);
  tr.querySelector('input[name="budget_amount[]"]').addEventListener('input', updateTotal);
});

// Keep each row's hidden budget_paid[] input (which submits positionally with
// budget_label[]) in sync with its checkbox. Delegated so it covers added rows.
document.getElementById('budgetBody').addEventListener('change', function (e) {
  if (!e.target.classList.contains('budget-paid-cb')) return;
  var hidden = e.target.parentElement.querySelector('input[name="budget_paid[]"]');
  if (hidden) hidden.value = e.target.checked ? '1' : '0';
});

function updateTotal() {
  var sum = 0;
  document.querySelectorAll('input[name="budget_amount[]"]').forEach(function (el) { sum += parseFloat(el.value) || 0; });
  document.getElementById('budgetTotal').textContent = sum.toFixed(2) + ' EUR';
}
document.querySelectorAll('input[name="budget_amount[]"]').forEach(function (el) {
  el.addEventListener('input', updateTotal);
});

// ── FAQ: add / remove row ─────────────────────────────────────────────────────
function removeFaqRow(btn) {
  var row = btn.closest('.faq-row');
  row.querySelectorAll('textarea').forEach(function (ta) {
    if (ta.id && tinymce.get(ta.id)) tinymce.get(ta.id).remove();
  });
  row.remove();
}

document.getElementById('addFaqRow').addEventListener('click', function () {
  var body  = document.getElementById('faqBody');
  var uid   = 'faq_a_'    + Date.now();
  var uid_en = 'faq_a_en_' + Date.now();
  var inp   = 'width:100%;padding:.45rem .6rem;border:1px solid #d1d5db;border-radius:5px;font-size:.88rem;box-sizing:border-box;';
  var div   = document.createElement('div');
  div.className = 'faq-row';
  div.style.cssText = 'border:1px solid #e8ddd5;border-radius:6px;padding:.85rem;';
  div.innerHTML = '<div style="display:flex;justify-content:flex-end;margin-bottom:.5rem;">'
    + '<button type="button" onclick="removeFaqRow(this)" style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:1rem;line-height:1;">✕</button>'
    + '</div>'
    + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.5rem;">'
    + '<div><label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.25rem;">Въпрос (БГ)</label>'
    + '<input type="text" name="faq_q[]" style="' + inp + '"></div>'
    + '<div><label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.25rem;">Question <span style="font-size:.68rem;font-weight:700;background:#dbeafe;color:#1d4ed8;border-radius:3px;padding:.05rem .35rem;">EN</span></label>'
    + '<input type="text" name="faq_q_en[]" style="' + inp + '"></div>'
    + '</div>'
    + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">'
    + '<div><label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.25rem;">Отговор (БГ)</label>'
    + '<textarea name="faq_a[]" id="' + uid + '" class="faq-answer" rows="3" style="' + inp + ' resize:vertical;"></textarea></div>'
    + '<div><label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.25rem;">Answer <span style="font-size:.68rem;font-weight:700;background:#dbeafe;color:#1d4ed8;border-radius:3px;padding:.05rem .35rem;">EN</span></label>'
    + '<textarea name="faq_a_en[]" id="' + uid_en + '" class="faq-answer-en" rows="3" style="' + inp + ' resize:vertical;"></textarea></div>'
    + '</div>';
  body.appendChild(div);
  tinymce.init(Object.assign({}, window._tinyBase, { selector: '#' + uid,    min_height: 180 }));
  tinymce.init(Object.assign({}, window._tinyBase, { selector: '#' + uid_en, min_height: 180 }));
});

// ── Photos: pick from library ────────────────────────────────────────────────
function _pickCampaignPhoto() {
  openMediaPicker(function (p) {
    document.getElementById('campaignPhotoLib').value = p;
    document.getElementById('campaignPhotoPreviewName').textContent = p.split('/').pop();
  });
}
</script>

<script>
tinymce.init(Object.assign({}, window._tinyBase, { selector: '#campaignDesc, #campaignDescEn, #campaignRisks, #campaignRisksEn, #eventDesc', min_height: 200 }));
initAutosave({
  key:     'campaign',
  formId:  'generalForm',
  tinyIds: ['campaignDesc', 'campaignDescEn', 'campaignRisks', 'campaignRisksEn', 'eventDesc']
});
tinymce.init(Object.assign({}, window._tinyBase, { selector: '.reward-desc, .reward-desc-en', min_height: 120 }));
tinymce.init(Object.assign({}, window._tinyBase, { selector: '.faq-answer, .faq-answer-en', min_height: 120 }));
</script>

<?php if ($deepl_ready): ?>
<script>
(function () {
  async function tx(text, isHtml) {
    if (!text.trim()) return '';
    var r = await fetch('/admin/translate-ajax.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ text: text, is_html: isHtml })
    });
    var d;
    try { d = await r.json(); } catch (_) { throw new Error('Невалиден отговор от сървъра (HTTP ' + r.status + ')'); }
    if (!d.ok) throw new Error(d.error || 'Преводът се провали.');
    return d.translated;
  }

  function tinyGet(id) {
    var ed = tinymce.get(id);
    return ed ? ed.getContent() : (document.getElementById(id) ? document.getElementById(id).value : '');
  }
  function tinySet(id, html) {
    var ed = tinymce.get(id);
    if (ed) ed.setContent(html);
    else if (document.getElementById(id)) document.getElementById(id).value = html;
  }

  function withBtn(btn, label, fn) {
    btn.disabled = true;
    btn.textContent = 'Превежда…';
    fn().then(function () {
      btn.textContent = '✓ ' + label;
    }).catch(function (e) {
      btn.textContent = '✗ Грешка';
      alert('Грешка при превод: ' + e.message);
      console.error(e);
    }).finally(function () {
      btn.disabled = false;
    });
  }

  // ── General ───────────────────────────────────────────────────────────────────
  var genBtn = document.getElementById('translateGeneralBtn');
  if (genBtn) {
    genBtn.addEventListener('click', function () {
      withBtn(genBtn, 'Преведено', async function () {
        var titleBg    = document.querySelector('[name="campaign_title"]').value;
        var metaDescBg = document.getElementById('campaignMetaDesc').value;
        var descBg     = tinyGet('campaignDesc');
        var [titleEn, metaDescEn, descEn] = await Promise.all([
          tx(titleBg,    false),
          tx(metaDescBg, false),
          tx(descBg,     true),
        ]);
        if (titleEn)    document.querySelector('[name="campaign_title_en"]').value = titleEn;
        if (metaDescEn) document.getElementById('campaignMetaDescEn').value = metaDescEn;
        if (descEn)     tinySet('campaignDescEn', descEn);
      });
    });
  }

  // ── Risks ─────────────────────────────────────────────────────────────────────
  var risksBtn = document.getElementById('translateRisksBtn');
  if (risksBtn) {
    risksBtn.addEventListener('click', function () {
      withBtn(risksBtn, 'Преведено', async function () {
        var html = tinyGet('campaignRisks');
        var en   = await tx(html, true);
        if (en) tinySet('campaignRisksEn', en);
      });
    });
  }

  // ── Budget ────────────────────────────────────────────────────────────────────
  var budgetBtn = document.getElementById('translateBudgetBtn');
  if (budgetBtn) {
    budgetBtn.addEventListener('click', function () {
      withBtn(budgetBtn, 'Преведено', async function () {
        var bgInputs = document.querySelectorAll('#budgetBody input[name="budget_label[]"]');
        var enInputs = document.querySelectorAll('#budgetBody input[name="budget_label_en[]"]');
        var texts    = Array.from(bgInputs).map(function (el) { return el.value; });
        var results  = await Promise.all(texts.map(function (t) { return tx(t, false); }));
        results.forEach(function (v, i) { if (v && enInputs[i]) enInputs[i].value = v; });
      });
    });
  }

  // ── Rewards ───────────────────────────────────────────────────────────────────
  var rewardsBtn = document.getElementById('translateRewardsBtn');
  if (rewardsBtn) {
    rewardsBtn.addEventListener('click', function () {
      withBtn(rewardsBtn, 'Преведено', async function () {
        var rows = document.querySelectorAll('#rewardsForm .faq-row, #rewardsForm > div > div[style*="border"]');
        // Collect all reward title and desc inputs
        var titleBgEls  = document.querySelectorAll('[name^="reward_title["]');
        var titleEnEls  = document.querySelectorAll('[name^="reward_title_en["]');
        var promises    = [];

        titleBgEls.forEach(function (el, i) {
          promises.push(tx(el.value, false).then(function (v) {
            if (v && titleEnEls[i]) titleEnEls[i].value = v;
          }));
        });

        // reward desc — TinyMCE instances with class reward-desc / reward-desc-en
        var descBgEds = Array.from(document.querySelectorAll('textarea.reward-desc')).map(function(el) { return tinymce.get(el.id); }).filter(Boolean);
        var descEnEds = Array.from(document.querySelectorAll('textarea.reward-desc-en')).map(function(el) { return tinymce.get(el.id); }).filter(Boolean);
        descBgEds.forEach(function (ed, i) {
          promises.push(tx(ed.getContent(), true).then(function (v) {
            if (v && descEnEds[i]) descEnEds[i].setContent(v);
          }));
        });

        await Promise.all(promises);
      });
    });
  }

  // ── FAQ ───────────────────────────────────────────────────────────────────────
  var faqBtn = document.getElementById('translateFaqBtn');
  if (faqBtn) {
    faqBtn.addEventListener('click', function () {
      withBtn(faqBtn, 'Преведено', async function () {
        var qBgEls = document.querySelectorAll('[name="faq_q[]"]');
        var qEnEls = document.querySelectorAll('[name="faq_q_en[]"]');
        var promises = [];

        qBgEls.forEach(function (el, i) {
          promises.push(tx(el.value, false).then(function (v) {
            if (v && qEnEls[i]) qEnEls[i].value = v;
          }));
        });

        var aBgEds = Array.from(document.querySelectorAll('textarea.faq-answer')).map(function(el) { return tinymce.get(el.id); }).filter(Boolean);
        var aEnEds = Array.from(document.querySelectorAll('textarea.faq-answer-en')).map(function(el) { return tinymce.get(el.id); }).filter(Boolean);
        aBgEds.forEach(function (ed, i) {
          promises.push(tx(ed.getContent(), true).then(function (v) {
            if (v && aEnEds[i]) aEnEds[i].setContent(v);
          }));
        });

        await Promise.all(promises);
      });
    });
  }
})();
</script>
<?php endif; ?>

<script>
(function () {
  var topbar = document.querySelector('.admin-topbar');
  var secNav = document.getElementById('secNav');
  var topbarH = topbar ? topbar.offsetHeight : 0;
  var navH    = secNav  ? secNav.offsetHeight  : 44;
  var NAV_H   = topbarH + navH + 8; // total offset for scroll targets

  // Move secNav to be a direct flex child of .admin-main (sibling of .admin-topbar).
  // Keeping it inside .admin-content (a flex item inside a flex column with min-height:100vh)
  // causes Chrome to double-count the sticky element's range in scrollHeight, which combined
  // with TinyMCE autoresize reflows creates unbounded scroll ("scrolls to infinity").
  var adminMain    = document.querySelector('.admin-main');
  var adminContent = document.querySelector('.admin-content');
  if (secNav && adminMain && adminContent) {
    secNav.style.marginBottom = '0';
    secNav.style.padding      = '.5rem 2rem';
    adminMain.insertBefore(secNav, adminContent);
  }

  if (secNav) secNav.style.top = topbarH + 'px';

  function setActive(secId) {
    document.querySelectorAll('#secNav [data-sec]').forEach(function (a) {
      var active = a.dataset.sec === secId;
      a.style.background = active ? '#1e3a5f' : '#f3f4f6';
      a.style.color       = active ? '#fff'    : '#374151';
    });
  }

  // Smooth scroll on pill click
  document.querySelectorAll('#secNav [data-sec]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      var target = document.getElementById(a.dataset.sec);
      if (!target) return;
      var top = target.getBoundingClientRect().top + window.pageYOffset - NAV_H;
      window.scrollTo({ top: top, behavior: 'smooth' });
      setActive(a.dataset.sec);
    });
  });

  // Scrollspy: activate pill when section top crosses below both sticky bars
  var obs = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting) setActive(entry.target.id);
    });
  }, { rootMargin: '-' + NAV_H + 'px 0px -55% 0px', threshold: 0 });

  ['sec-general','sec-rewards','sec-budget','sec-photos','sec-faq','sec-risks','sec-event']
    .forEach(function (id) {
      var el = document.getElementById(id);
      if (el) obs.observe(el);
    });
})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>

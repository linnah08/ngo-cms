<?php
$page_title_admin = 'Имейл шаблони';
$active_nav       = 'email-templates';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/email-templates.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/translator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
admin_require_admin();

$deepl_ready    = deepl_is_configured();
$_tinymce_key   = setting_get('tinymce_api_key', 'no-api-key');
$page_head_extra = '<script src="https://cdn.tiny.cloud/1/' . h($_tinymce_key) . '/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>';

// Template meta: key → human label + available {{vars}}
$templates = [
    'campaign-confirmation' => [
        'label' => 'Потвърждение на дарение (кампания)',
        'vars'  => ['{{name}}', '{{pledge_number}}'],
    ],
    'campaign-ticket' => [
        'label' => 'Потвърждение за билет',
        'vars'  => ['{{name}}', '{{pledge_number}}', '{{event_name}}'],
    ],
    'order-confirmation-customer' => [
        'label' => 'Потвърждение на поръчка',
        'vars'  => ['{{customer_name}}', '{{order_number}}'],
    ],
    'order-shipped-customer' => [
        'label' => 'Изпратена поръчка',
        'vars'  => ['{{customer_name}}', '{{order_number}}', '{{courier}}'],
    ],
    'donation-confirmation-customer' => [
        'label' => 'Потвърждение на дарение (банков превод)',
        'vars'  => ['{{donor_name}}', '{{amount_eur}}'],
    ],
    'order-cancelled-customer' => [
        'label' => 'Отменена поръчка (с възстановяване)',
        'vars'  => ['{{customer_name}}', '{{order_number}}'],
    ],
    'credit-note-customer' => [
        'label' => 'Кредитен документ (отменена поръчка)',
        'vars'  => ['{{customer_name}}', '{{order_number}}', '{{doc_number}}'],
    ],
    'error-alert' => [
        'label' => 'Известие за грешка (единично)',
        'vars'  => ['{{error_class}}', '{{url}}', '{{timestamp}}'],
    ],
    'error-digest' => [
        'label' => 'Обобщение на грешки (дневно/седмично)',
        'vars'  => ['{{count}}', '{{period}}', '{{from_date}}', '{{to_date}}'],
    ],
];

// ── POST: save one template ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $save_key = $_POST['tpl_key'] ?? '';
    if (array_key_exists($save_key, $templates)) {
        $data = [
            'subject_bg' => trim($_POST['subject_bg'] ?? ''),
            'intro_bg'   => trim($_POST['intro_bg']   ?? ''),
            'outro_bg'   => trim($_POST['outro_bg']   ?? ''),
            'subject_en' => trim($_POST['subject_en'] ?? ''),
            'intro_en'   => trim($_POST['intro_en']   ?? ''),
            'outro_en'   => trim($_POST['outro_en']   ?? ''),
        ];
        setting_set('email_tpl_' . $save_key, json_encode($data, JSON_UNESCAPED_UNICODE));
        flash_set('success', 'Шаблонът е запазен.');
    }
    header('Location: /admin/email-templates.php?tpl=' . urlencode($save_key));
    exit;
}

$active_key = $_GET['tpl'] ?? array_key_first($templates);
if (!array_key_exists($active_key, $templates)) {
    $active_key = array_key_first($templates);
}
$raw   = email_tpl_raw($active_key);
$flash = flash_get();

$lbl_bg_badge = '<span style="display:inline-block;font-size:.68rem;font-weight:700;padding:.1rem .42rem;border-radius:3px;background:#dcfce7;color:#166534;letter-spacing:.04em;vertical-align:middle;margin-left:.4rem;">BG</span>';
$lbl_en_badge = '<span style="display:inline-block;font-size:.68rem;font-weight:700;padding:.1rem .42rem;border-radius:3px;background:#dbeafe;color:#1d4ed8;letter-spacing:.04em;vertical-align:middle;margin-left:.4rem;">EN</span>';

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1>Имейл шаблони</h1>
  <button type="submit" form="tplForm" class="btn btn--primary" onclick="syncAndSave()">Запази</button>
</div>

<?php foreach ($flash as $f): ?>
<div class="admin-alert admin-alert--<?= $f['type'] === 'success' ? 'success' : 'error' ?>" style="margin-bottom:1.5rem;"><?= h($f['message']) ?></div>
<?php endforeach; ?>

<div style="display:grid;grid-template-columns:260px 1fr;gap:2rem;align-items:start;">

  <!-- Sidebar: template list -->
  <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;">
    <?php foreach ($templates as $key => $meta): ?>
    <a href="?tpl=<?= urlencode($key) ?>"
       style="display:block;padding:.75rem 1rem;font-size:.875rem;text-decoration:none;border-bottom:1px solid var(--border);
         <?= $key === $active_key ? 'background:var(--teal-light);color:var(--teal);font-weight:600;' : 'color:var(--text);' ?>">
      <?= h($meta['label']) ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Editor -->
  <div>
    <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.75rem;">

      <?php $tmeta = $templates[$active_key]; ?>
      <h2 style="margin:0 0 .4rem;font-size:1.1rem;"><?= h($tmeta['label']) ?></h2>
      <?php if ($tmeta['vars']): ?>
      <p style="font-size:.8rem;color:var(--text-muted);margin:0 0 1.5rem;">
        Налични променливи:
        <?php foreach ($tmeta['vars'] as $v): ?>
        <code style="background:#f0ede9;padding:.1rem .35rem;border-radius:3px;margin-right:.3rem;"><?= h($v) ?></code>
        <?php endforeach; ?>
      </p>
      <?php endif; ?>

      <form method="POST" id="tplForm">
        <?= csrf_field() ?>
        <input type="hidden" name="tpl_key" value="<?= h($active_key) ?>">

        <!-- ── BG ─────────────────────────────────────────────────────── -->
        <div style="background:var(--warm-grey);border-radius:8px;padding:1.25rem 1.5rem;margin-bottom:1.5rem;">
          <div style="font-size:.75rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted);margin-bottom:1rem;">
            Българска версия <?= $lbl_bg_badge ?>
          </div>

          <div class="form-group">
            <label>Тема <?= $lbl_bg_badge ?></label>
            <input type="text" name="subject_bg" value="<?= h($raw['subject_bg']) ?>"
                   style="width:100%;box-sizing:border-box;">
          </div>

          <div class="form-group">
            <label>Въведение <?= $lbl_bg_badge ?><br>
              <span style="font-size:.76rem;font-weight:400;color:var(--text-muted);">Заглавие + поздрав + встъпителен параграф</span>
            </label>
            <textarea name="intro_bg" id="intro_bg" rows="6"><?= h($raw['intro_bg']) ?></textarea>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label>Заключение <?= $lbl_bg_badge ?><br>
              <span style="font-size:.76rem;font-weight:400;color:var(--text-muted);">Параграф след данните + подпис</span>
            </label>
            <textarea name="outro_bg" id="outro_bg" rows="4"><?= h($raw['outro_bg']) ?></textarea>
          </div>
        </div>

        <!-- ── EN ─────────────────────────────────────────────────────── -->
        <div style="background:var(--warm-grey);border-radius:8px;padding:1.25rem 1.5rem;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
            <div style="font-size:.75rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted);">
              Английска версия <?= $lbl_en_badge ?>
            </div>
            <?php if ($deepl_ready): ?>
            <button type="button" class="btn btn--outline" style="font-size:.78rem;padding:.3rem .75rem;"
                    onclick="translateAll()">
              Преведи от BG с DeepL
            </button>
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label>Subject <?= $lbl_en_badge ?></label>
            <div style="display:flex;gap:.5rem;">
              <input type="text" name="subject_en" id="subject_en" value="<?= h($raw['subject_en']) ?>"
                     style="flex:1;">
              <?php if ($deepl_ready): ?>
              <button type="button" class="btn btn--outline" style="font-size:.78rem;padding:.35rem .7rem;white-space:nowrap;"
                      onclick="txSubject()">Преведи</button>
              <?php endif; ?>
            </div>
          </div>

          <div class="form-group">
            <label>Introduction <?= $lbl_en_badge ?></label>
            <textarea name="intro_en" id="intro_en" rows="6"><?= h($raw['intro_en']) ?></textarea>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label>Closing <?= $lbl_en_badge ?></label>
            <textarea name="outro_en" id="outro_en" rows="4"><?= h($raw['outro_en']) ?></textarea>
          </div>
        </div>

        <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
          <button type="submit" form="tplForm" class="btn btn--primary" onclick="syncAndSave()">Запази</button>
        </div>

      </form>
    </div>
  </div>

</div>

<script>
tinymce.init(Object.assign({}, window._tinyBase, {
  selector: '#intro_bg, #outro_bg, #intro_en, #outro_en',
  min_height: 160,
  content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; }',
}));
initAutosave({
  key:     <?= json_encode('email-template:' . $active_key) ?>,
  formId:  'tplForm',
  tinyIds: ['intro_bg', 'outro_bg', 'intro_en', 'outro_en']
});

function syncAndSave() {
  tinymce.triggerSave();
}

<?php if ($deepl_ready): ?>
async function deepl(text, isHtml) {
  const resp = await fetch('/admin/translate-ajax.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      text,
      source_lang: 'BG',
      target_lang: 'EN',
      tag_handling: isHtml ? 'html' : null,
      csrf_token: document.querySelector('[name=csrf_token]').value,
    }),
  });
  const data = await resp.json();
  if (data.error) throw new Error(data.error);
  return data.translated;
}

async function txSubject() {
  const src = document.getElementById('subject_en');
  const bgVal = document.querySelector('[name=subject_bg]').value.trim();
  if (!bgVal) return;
  src.disabled = true;
  try {
    src.value = await deepl(bgVal, false);
  } catch(e) { alert('DeepL грешка: ' + e.message); }
  src.disabled = false;
}

async function translateAll() {
  tinymce.triggerSave();
  const fields = [
    { bgId: 'subject_bg', enId: 'subject_en', isHtml: false, isTiny: false },
    { bgId: 'intro_bg',   enId: 'intro_en',   isHtml: true,  isTiny: true },
    { bgId: 'outro_bg',   enId: 'outro_en',   isHtml: true,  isTiny: true },
  ];
  for (const f of fields) {
    const bgEl = f.isTiny ? null : document.getElementById(f.bgId);
    const bgVal = f.isTiny
      ? (tinymce.get(f.bgId)?.getContent() ?? document.getElementById(f.bgId)?.value ?? '')
      : bgEl?.value ?? '';
    if (!bgVal.trim()) continue;
    try {
      const translated = await deepl(
        f.isHtml ? '<div>' + bgVal + '</div>' : bgVal,
        f.isHtml
      );
      const result = f.isHtml ? translated.replace(/^<div>|<\/div>$/g, '') : translated;
      if (f.isTiny) {
        const ed = tinymce.get(f.enId);
        if (ed) ed.setContent(result);
        else {
          const ta = document.getElementById(f.enId);
          if (ta) ta.value = result;
        }
      } else {
        document.getElementById(f.enId).value = result;
      }
    } catch(e) { alert('DeepL грешка за ' + f.bgId + ': ' + e.message); break; }
  }
}
<?php endif; ?>
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>

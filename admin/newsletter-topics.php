<?php
/**
 * Admin → Бюлетин → Теми: the topics people pick when they sign up for the
 * newsletter. Add, rename, reorder, hide and show again. Hiding never deletes:
 * subscribers keep their choice and campaigns already aimed at the topic keep
 * their audience (includes/newsletter.php, "Topic management").
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';
$page_title_admin = 'Теми на бюлетина';
$active_nav       = 'newsletter-topics';
admin_require_admin();
module_admin_guard('newsletter');

// ── POST actions ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $topic  = newsletter_topic_get((int) ($_POST['id'] ?? 0));
    $bg     = newsletter_clean_topic_name($_POST['name_bg'] ?? '');
    $en     = newsletter_clean_topic_name($_POST['name_en'] ?? '');

    if ($action === 'add') {
        if ($bg === '') {
            flash_set('error', 'Новата тема не е добавена: попълнете името ѝ на български.');
        } else {
            newsletter_topic_add($bg, $en);
            flash_set('success', "Темата „{$bg}“ е добавена. Вече се вижда във формите за абониране.");
        }
    } elseif ($topic) {
        $name = newsletter_topic_name($topic, 'bg');
        switch ($action) {
            case 'save':
                if ($bg === '') {
                    flash_set('error', "Името на темата „{$name}“ не е променено: името на български не може да е празно.");
                } else {
                    newsletter_topic_rename((int) $topic['id'], $bg, $en);
                    flash_set('success', "Темата „{$bg}“ е запазена.");
                }
                break;
            case 'up':
            case 'down':
                newsletter_topic_move((int) $topic['id'], $action === 'up' ? -1 : 1);
                break;
            case 'hide':
                newsletter_topic_set_shown((int) $topic['id'], false);
                flash_set('success', "Темата „{$name}“ е скрита и вече не се показва на сайта. Абонатите, които са я избрали, не губят нищо — можете да я покажете отново по-долу.");
                break;
            case 'show':
                newsletter_topic_set_shown((int) $topic['id'], true);
                flash_set('success', "Темата „{$name}“ отново се показва във формите за абониране.");
                break;
        }
    }
    header('Location: /admin/newsletter-topics.php');
    exit;
}

// ── View ────────────────────────────────────────────────────────────────────────
$shown  = newsletter_topic_list();
$hidden = array_values(array_filter(newsletter_topic_list(true), fn($t) => !(int) $t['is_active']));
$chosen = newsletter_topic_subscriber_counts();
$flash  = flash_get();

$btn_small = 'min-height:44px;min-width:44px;padding:.4rem .8rem;font-size:.85rem;';
$input     = 'width:100%;box-sizing:border-box;padding:.6rem .75rem;border:1px solid #8a8378;border-radius:6px;font-size:1rem;font-family:inherit;';
$label     = 'display:block;font-size:.85rem;font-weight:600;margin-bottom:.3rem;';

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<?php foreach ($flash as $f): $err = ($f['type'] ?? '') === 'error'; ?>
<div <?= $err ? 'role="alert"' : '' ?> style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;
  <?= $err ? 'background:#fdf0ef;border:1px solid #f0c4c0;color:#a4243d;' : 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;' ?>">
  <?= $err ? '⚠ ' : '✓ ' ?><?= h($f['message']) ?>
</div>
<?php endforeach; ?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <p style="margin:0;max-width:640px;font-size:.95rem;line-height:1.6;color:var(--text-muted);">
    Когато някой се абонира, избира кои от тези теми иска да получава. Когато пишете бюлетин,
    можете да го изпратите на всички или само на хората, избрали дадена тема.
    При <strong>една тема или без теми</strong> във формите няма отметки — абонирането е за всичко.
    Абонатите без избрана тема (напр. внесени от клиенти или добавени ръчно) получават всички бюлетини.
  </p>
  <div style="display:flex;flex-wrap:wrap;gap:.75rem;">
    <a href="/admin/newsletter-subscribers.php" class="btn btn--outline" style="font-size:.85rem;">Абонати</a>
    <a href="/admin/newsletter.php" class="btn btn--outline" style="font-size:.85rem;">← Кампании</a>
  </div>
</div>

<?php if (count($shown) > NEWSLETTER_TOPICS_SOFT_MAX): ?>
<p style="padding:.9rem 1.25rem;border-radius:6px;margin:0 0 1.5rem;background:#fff8e6;border:1px solid #e8c66a;color:#6b4e00;">
  ⚠ Показвате <?= count($shown) ?> теми. Дълъг списък често отказва хората да се абонират — помислете дали някои не могат да се обединят.
</p>
<?php endif; ?>

<h2 style="font-size:1.1rem;margin:0 0 .75rem;">Теми във формите за абониране</h2>
<?php if (!$shown): ?>
<p style="padding:1.25rem;background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);color:var(--text-muted);margin:0 0 1.5rem;">
  Няма показани теми — хората се абонират за всичко. Добавете тема по-долу, ако искате да им дадете избор.
</p>
<?php else: ?>
<ol style="list-style:none;margin:0 0 1.5rem;padding:0;display:flex;flex-direction:column;gap:.75rem;">
  <?php foreach ($shown as $i => $t): $tid = (int) $t['id']; $tname = newsletter_topic_name($t, 'bg'); ?>
  <li style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem 1.25rem;">
    <form method="POST" style="margin:0;">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $tid ?>">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
        <div>
          <label for="topic-bg-<?= $tid ?>" style="<?= $label ?>">Име на български</label>
          <input type="text" id="topic-bg-<?= $tid ?>" name="name_bg" required maxlength="150"
                 value="<?= h($tname) ?>" style="<?= $input ?>">
        </div>
        <div>
          <label for="topic-en-<?= $tid ?>" style="<?= $label ?>">Име на английски</label>
          <input type="text" id="topic-en-<?= $tid ?>" name="name_en" maxlength="150" data-translate-from="name_bg"
                 value="<?= h(trim((string) $t['name_en']) !== '' || !empty($t['legacy_key']) ? newsletter_topic_name($t, 'en') : '') ?>" style="<?= $input ?>">
        </div>
      </div>
      <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;margin-top:.75rem;">
        <button type="submit" name="action" value="save" class="btn btn--primary" style="<?= $btn_small ?>">Запази</button>
        <button type="submit" name="action" value="up" class="btn btn--outline" style="<?= $btn_small ?>"
                formnovalidate aria-label="Премести „<?= h($tname) ?>“ нагоре"<?= $i === 0 ? ' disabled' : '' ?>>↑</button>
        <button type="submit" name="action" value="down" class="btn btn--outline" style="<?= $btn_small ?>"
                formnovalidate aria-label="Премести „<?= h($tname) ?>“ надолу"<?= $i === count($shown) - 1 ? ' disabled' : '' ?>>↓</button>
        <button type="submit" name="action" value="hide" formnovalidate class="btn btn--outline" style="<?= $btn_small ?>">Скрий темата</button>
        <span style="font-size:.85rem;color:var(--text-muted);margin-left:auto;">
          Избрана от <?= (int) ($chosen[$tid] ?? 0) ?> активни абонати
        </span>
      </div>
    </form>
  </li>
  <?php endforeach; ?>
</ol>
<?php endif; ?>

<section aria-labelledby="add-topic-h" style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem 1.25rem;margin-bottom:2rem;">
  <h2 id="add-topic-h" style="font-size:1.1rem;margin:0 0 .75rem;">Добави тема</h2>
  <form method="POST" style="margin:0;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
      <div>
        <label for="new-topic-bg" style="<?= $label ?>">Име на български</label>
        <input type="text" id="new-topic-bg" name="name_bg" required maxlength="150"
               placeholder="напр. Покани за събития" style="<?= $input ?>">
      </div>
      <div>
        <label for="new-topic-en" style="<?= $label ?>">Име на английски</label>
        <input type="text" id="new-topic-en" name="name_en" maxlength="150" data-translate-from="name_bg" style="<?= $input ?>">
      </div>
    </div>
    <button type="submit" class="btn btn--primary" style="margin-top:.75rem;min-height:44px;">+ Добави темата</button>
  </form>
</section>

<?php if ($hidden): ?>
<h2 style="font-size:1.1rem;margin:0 0 .4rem;">Скрити теми</h2>
<p style="margin:0 0 .75rem;font-size:.9rem;color:var(--text-muted);">
  Не се показват на сайта и не могат да се избират за нов бюлетин. Абонатите, които са ги избрали, ги запазват.
</p>
<ul style="list-style:none;margin:0 0 1.5rem;padding:0;display:flex;flex-direction:column;gap:.5rem;">
  <?php foreach ($hidden as $t): $tid = (int) $t['id']; ?>
  <li style="display:flex;flex-wrap:wrap;align-items:center;gap:.75rem;background:var(--off-white);border:1px solid var(--border);border-radius:var(--radius-lg);padding:.6rem 1.25rem;">
    <span style="font-weight:600;"><?= h(newsletter_topic_name($t, 'bg')) ?></span>
    <span style="font-size:.85rem;color:var(--text-muted);">избрана от <?= (int) ($chosen[$tid] ?? 0) ?> активни абонати</span>
    <form method="POST" style="margin:0 0 0 auto;">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $tid ?>">
      <button type="submit" name="action" value="show" class="btn btn--outline" style="<?= $btn_small ?>">Покажи отново</button>
    </form>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>

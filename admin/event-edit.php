<?php
/**
 * Admin → Събития → one event: create or edit it, and see who bought tickets.
 * Module "events".
 */
$page_title_admin = 'Събитие';
$active_nav       = 'events';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/events.php';

admin_require_shop();

// Module switched off in Admin → Модули — says so, with a way back. After the
// auth call, so an anonymous request still gets the login redirect.
module_admin_guard('events');

$pdo = get_pdo();
events_adopt_legacy($pdo);

$id    = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$event = $id ? event_get($pdo, $id) : null;
if ($id && !$event) {
    flash_set('error', 'Това събитие не съществува (може да е изтрито).');
    header('Location: /admin/events.php');
    exit;
}

$errors = [];
$form   = $event ?? [
    'title' => '', 'title_en' => '', 'event_date' => '', 'event_time' => '', 'place' => '', 'place_en' => '',
    'description' => '', 'description_en' => '', 'fb_url' => '', 'price_eur' => '', 'capacity' => null,
    'published' => 0, 'sales_open' => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors['_form'] = 'Страницата беше отворена твърде дълго и сесията изтече. Презаредете страницата и опитайте отново — промените не са запазени.';
        $form = array_merge($form, array_intersect_key($_POST, $form));
    } else {
        $r = event_validate($_POST);
        $errors = $r['errors'];
        $form   = array_merge($form, $r['values']);
        if (!$errors) {
            $saved_id = event_save($pdo, $r['values'], $id);
            flash_set('success', $id ? 'Промените са запазени.' : 'Събитието е създадено.');
            header('Location: /admin/event-edit.php?id=' . $saved_id);
            exit;
        }
    }
}

$buyers = [];
if ($event) {
    $st = $pdo->prepare(
        "SELECT p.id, p.pledge_number, p.name, p.email, p.ticket_qty, p.amount_eur, p.payment_status, p.created_at, o.id AS order_id
           FROM campaign_pledges p
           LEFT JOIN orders o ON o.order_number = p.pledge_number AND o.type = 'ticket'
          WHERE p.event_id = ? AND p.pledge_type = 'ticket' AND p.payment_status IN ('paid', 'pending')
          ORDER BY p.payment_status = 'paid' DESC, p.created_at DESC"
    );
    $st->execute([(int) $event['id']]);
    $buyers = $st->fetchAll(PDO::FETCH_ASSOC);
}
$sold    = $event ? event_tickets_sold($pdo, (int) $event['id']) : 0;
$flashes = flash_get();
$page_title_admin = $event ? 'Събитие: ' . $event['title'] : 'Ново събитие';
$_tinymce_key     = setting_get('tinymce_api_key', 'no-api-key');
$page_head_extra  = '<script src="https://cdn.tiny.cloud/1/' . h($_tinymce_key) . '/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>';

$inp = 'width:100%;box-sizing:border-box;min-height:44px;padding:.55rem .75rem;border:1.5px solid #8a8f98;border-radius:6px;font-size:1rem;font-family:inherit;';
$lbl = 'display:block;font-weight:600;margin-bottom:.3rem;text-transform:none;letter-spacing:normal;font-size:.95rem;';
$err = static function (string $f) use ($errors): string {
    return isset($errors[$f]) ? '<p id="err-' . $f . '" style="margin:.3rem 0 0;color:#9b2c1f;font-weight:600;">⚠ ' . h($errors[$f]) . '</p>' : '';
};
$aria = static fn(string $f, string $more = ''): string =>
    (isset($errors[$f]) ? ' aria-invalid="true"' : '') . (isset($errors[$f]) || $more ? ' aria-describedby="' . trim((isset($errors[$f]) ? 'err-' . $f : '') . ' ' . $more) . '"' : '');

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<?php foreach ($flashes as $f): $ok = ($f['type'] ?? '') === 'success'; ?>
  <div role="<?= $ok ? 'status' : 'alert' ?>" style="padding:.9rem 1.25rem;border-radius:8px;margin-bottom:1rem;line-height:1.5;<?= $ok ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#1e5128;' : 'background:#fdf0ef;border:1px solid #f0c4c0;color:#9b2c1f;' ?>">
    <?= $ok ? '✓ ' : '' ?><?= h((string) $f['message']) ?>
  </div>
<?php endforeach; ?>

<?php if ($errors): ?>
  <div role="alert" tabindex="-1" id="form-errors" style="padding:.9rem 1.25rem;border-radius:8px;margin-bottom:1rem;line-height:1.5;background:#fdf0ef;border:1px solid #f0c4c0;color:#9b2c1f;">
    <strong>Събитието не е запазено.</strong> <?= h($errors['_form'] ?? 'Поправете отбелязаните полета по-долу.') ?>
  </div>
<?php endif; ?>

<p style="margin:0 0 1rem;"><a href="/admin/events.php">← Всички събития</a>
<?php if ($event): ?>
  · <a href="<?= h(event_url($event, 'bg')) ?>" target="_blank" rel="noopener">Виж на сайта ↗</a>
  · <a href="/admin/ticket-checklist.php?event=<?= (int) $event['id'] ?>">Списък за входа</a>
<?php endif; ?></p>

<?php if ($event): $badge = event_admin_badge($pdo, $event); ?>
  <p style="margin:0 0 1.25rem;"><strong>На сайта сега:</strong> <?= h($badge['text']) ?> · <strong>Продадени билети:</strong> <?= $sold ?><?= $event['capacity'] !== null ? ' от ' . (int) $event['capacity'] : '' ?></p>
<?php endif; ?>

<form method="post" id="eventForm" novalidate style="background:#fff;border:1px solid var(--border,#e2e0db);border-radius:10px;padding:1.25rem;margin-bottom:2rem;">
  <?= csrf_field() ?>

  <fieldset style="border:0;padding:0;margin:0 0 1.25rem;">
    <legend style="font-weight:700;font-size:1.1rem;margin-bottom:.75rem;">На сайта</legend>
    <label for="published" style="display:flex;align-items:center;gap:.6rem;min-height:44px;font-weight:600;text-transform:none;letter-spacing:normal;cursor:pointer;">
      <input type="checkbox" id="published" name="published" value="1" <?= !empty($form['published']) ? 'checked' : '' ?> style="width:22px;height:22px;accent-color:var(--teal,#0387A5);">
      Показвай събитието на сайта
    </label>
    <label for="sales_open" style="display:flex;align-items:center;gap:.6rem;min-height:44px;font-weight:600;text-transform:none;letter-spacing:normal;cursor:pointer;">
      <input type="checkbox" id="sales_open" name="sales_open" value="1" <?= !empty($form['sales_open']) ? 'checked' : '' ?> aria-describedby="sales-hint" style="width:22px;height:22px;accent-color:var(--teal,#0387A5);">
      Продавай билети онлайн
    </label>
    <p id="sales-hint" style="margin:.2rem 0 0;color:#4b5563;line-height:1.5;">Билетите се продават само докато събитието е показано на сайта и още не е минало.</p>
  </fieldset>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:1rem 1.5rem;margin-bottom:1rem;">
    <div>
      <label for="title" style="<?= $lbl ?>">Име на събитието (български) *</label>
      <input type="text" id="title" name="title" maxlength="255" required value="<?= h((string) $form['title']) ?>" style="<?= $inp ?>"<?= $aria('title') ?>>
      <?= $err('title') ?>
    </div>
    <div>
      <label for="title_en" style="<?= $lbl ?>">Име на събитието (английски)</label>
      <input type="text" id="title_en" name="title_en" maxlength="255" data-translate-from="title" value="<?= h((string) $form['title_en']) ?>" style="<?= $inp ?>">
    </div>
    <div>
      <label for="event_date" style="<?= $lbl ?>">Дата</label>
      <input type="date" id="event_date" name="event_date" value="<?= h((string) $form['event_date']) ?>" style="<?= $inp ?>"<?= $aria('event_date') ?>>
      <?= $err('event_date') ?>
    </div>
    <div>
      <label for="event_time" style="<?= $lbl ?>">Начален час</label>
      <input type="time" id="event_time" name="event_time" value="<?= h((string) $form['event_time']) ?>" style="<?= $inp ?>"<?= $aria('event_time') ?>>
      <?= $err('event_time') ?>
    </div>
    <div>
      <label for="place" style="<?= $lbl ?>">Място (български)</label>
      <input type="text" id="place" name="place" maxlength="255" value="<?= h((string) $form['place']) ?>" placeholder="напр. Читалище „Светлина“, София" style="<?= $inp ?>">
    </div>
    <div>
      <label for="place_en" style="<?= $lbl ?>">Място (английски)</label>
      <input type="text" id="place_en" name="place_en" maxlength="255" data-translate-from="place" value="<?= h((string) $form['place_en']) ?>" style="<?= $inp ?>">
    </div>
    <div>
      <label for="price_eur" style="<?= $lbl ?>">Цена на билет (EUR)</label>
      <input type="text" id="price_eur" name="price_eur" inputmode="decimal" value="<?= h($form['price_eur'] === '' ? '' : number_format((float) $form['price_eur'], 2, '.', '')) ?>" placeholder="напр. 15" style="<?= $inp ?>"<?= $aria('price_eur') ?>>
      <?= $err('price_eur') ?>
    </div>
    <div>
      <label for="capacity" style="<?= $lbl ?>">Брой места</label>
      <input type="text" id="capacity" name="capacity" inputmode="numeric" value="<?= h($form['capacity'] === null ? '' : (string) $form['capacity']) ?>" style="<?= $inp ?>"<?= $aria('capacity', 'capacity-hint') ?>>
      <p id="capacity-hint" style="margin:.3rem 0 0;color:#4b5563;">Оставете празно, ако няма ограничение. Когато местата свършат, сайтът спира продажбата сам.</p>
      <?= $err('capacity') ?>
    </div>
    <div>
      <label for="fb_url" style="<?= $lbl ?>">Събитието във Facebook (по желание)</label>
      <input type="url" id="fb_url" name="fb_url" maxlength="500" value="<?= h((string) $form['fb_url']) ?>" placeholder="https://www.facebook.com/events/…" style="<?= $inp ?>"<?= $aria('fb_url') ?>>
      <?= $err('fb_url') ?>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:1rem 1.5rem;margin-bottom:1.25rem;">
    <div>
      <label for="ev-desc" style="<?= $lbl ?>">Описание (български)</label>
      <textarea id="ev-desc" name="description" rows="8" style="<?= $inp ?>"><?= h((string) $form['description']) ?></textarea>
    </div>
    <div>
      <label for="ev-desc-en" style="<?= $lbl ?>">Описание (английски)</label>
      <textarea id="ev-desc-en" name="description_en" rows="8" data-translate-from="ev-desc" style="<?= $inp ?>"><?= h((string) $form['description_en']) ?></textarea>
    </div>
  </div>

  <button type="submit" class="btn btn--primary" style="min-height:44px;"><?= $event ? 'Запази промените' : 'Създай събитието' ?></button>
</form>

<?php if ($event): ?>
<section aria-labelledby="buyers-title" style="margin-bottom:2rem;">
  <h2 id="buyers-title" style="font-size:1.2rem;margin:0 0 .75rem;">Купувачи</h2>
  <?php if (!$buyers): ?>
    <p style="margin:0;">Още няма купени билети.</p>
  <?php else: ?>
  <div style="position:relative;overflow-x:auto;background:#fff;border:1px solid var(--border,#e2e0db);border-radius:10px;">
    <table style="width:100%;border-collapse:collapse;min-width:560px;">
      <thead>
        <tr style="text-align:left;border-bottom:2px solid var(--border,#e2e0db);">
          <th scope="col" style="padding:.6rem .75rem;">Купувач</th>
          <th scope="col" style="padding:.6rem .75rem;text-align:right;">Билети</th>
          <th scope="col" style="padding:.6rem .75rem;text-align:right;">Сума</th>
          <th scope="col" style="padding:.6rem .75rem;">Плащане</th>
          <th scope="col" style="padding:.6rem .75rem;">Дата</th>
          <th scope="col" style="padding:.6rem .75rem;"><span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">Поръчка</span></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($buyers as $b): $paid = $b['payment_status'] === 'paid'; ?>
        <tr style="border-bottom:1px solid #f0ede9;">
          <td style="padding:.6rem .75rem;"><?= h($b['name']) ?><div style="font-size:.88rem;color:#4b5563;"><?= h($b['email']) ?></div></td>
          <td style="padding:.6rem .75rem;text-align:right;"><?= (int) $b['ticket_qty'] ?></td>
          <td style="padding:.6rem .75rem;text-align:right;white-space:nowrap;"><?= number_format((float) $b['amount_eur'], 2, '.', ' ') ?> EUR</td>
          <td style="padding:.6rem .75rem;"><span style="display:inline-block;padding:.15rem .55rem;border-radius:999px;font-size:.85rem;font-weight:600;<?= $paid ? 'background:#e6f4ea;color:#1e5128;' : 'background:#fff4e5;color:#7a3e00;' ?>"><?= $paid ? '✓ Платено' : 'Чака плащане' ?></span></td>
          <td style="padding:.6rem .75rem;white-space:nowrap;"><?= h(date('d.m.Y H:i', strtotime((string) $b['created_at']))) ?></td>
          <td style="padding:.6rem .75rem;white-space:nowrap;">
            <?php if ($b['order_id']): ?><a href="/admin/order-view.php?id=<?= (int) $b['order_id'] ?>">Билети и имейл →</a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p style="margin:.6rem 0 0;color:#4b5563;">От „Билети и имейл“ можете да изтеглите билетите или да ги изпратите отново на купувача.</p>
  <?php endif; ?>
</section>
<?php endif; ?>

<script>
tinymce.init(Object.assign({}, window._tinyBase, { selector: '#ev-desc, #ev-desc-en', min_height: 260 }));
document.getElementById('eventForm').addEventListener('submit', function () { if (window.tinymce) tinymce.triggerSave(); });
(function () { var e = document.getElementById('form-errors'); if (e) e.focus(); })();
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>

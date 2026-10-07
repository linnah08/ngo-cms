<?php
/**
 * Admin → Събития: every event, with tickets sold, filters and bulk actions
 * (show/hide on the site, open/close ticket sales, delete events without
 * tickets). Module "events".
 */
$page_title_admin = 'Събития';
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

// ── Bulk actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        http_response_code(400);
        exit('Страницата беше отворена твърде дълго. Презаредете я и опитайте отново.');
    }
    $action = (string) ($_POST['bulk_action'] ?? '');
    $ids    = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
    if (!in_array($action, EVENT_BULK_ACTIONS, true)) {
        flash_set('error', 'Непознато действие. Презаредете страницата и опитайте отново.');
    } elseif (!$ids) {
        flash_set('error', 'Не сте избрали събития.');
    } else {
        $r = events_bulk_apply($pdo, $action, $ids);
        $done = [
            'publish'     => 'Показани на сайта: %d.',
            'hide'        => 'Скрити от сайта: %d.',
            'open_sales'  => 'Продажбата на билети е отворена за %d.',
            'close_sales' => 'Продажбата на билети е спряна за %d.',
            'delete'      => 'Изтрити събития: %d.',
        ][$action];
        flash_set('success', sprintf($done, $r['changed']));
        if ($r['kept']) {
            flash_set('error', 'Не са изтрити, защото вече имат продадени билети (те трябва да останат видими в Поръчки): '
                . implode(', ', array_map(fn($t) => '„' . $t . '“', $r['kept'])) . '. Можете да ги скриете от сайта.');
        }
    }
    $back = array_intersect_key($_GET, array_flip(['when', 'state']));
    header('Location: /admin/events.php' . ($back ? '?' . http_build_query($back) : ''));
    exit;
}

// ── Filters ──────────────────────────────────────────────────────────────────
$when  = in_array($_GET['when'] ?? '', ['upcoming', 'past', 'all'], true) ? $_GET['when'] : 'upcoming';
$state = in_array($_GET['state'] ?? '', ['all', 'on_sale', 'visible', 'hidden'], true) ? $_GET['state'] : 'all';
$today = date('Y-m-d');

$where = [];
$args  = [];
if ($when === 'upcoming') { $where[] = '(e.event_date IS NULL OR e.event_date >= ?)'; $args[] = $today; }
if ($when === 'past')     { $where[] = 'e.event_date < ?'; $args[] = $today; }
if ($state === 'visible') $where[] = 'e.published = 1';
if ($state === 'hidden')  $where[] = 'e.published = 0';
if ($state === 'on_sale') $where[] = 'e.published = 1 AND e.sales_open = 1';
$st = $pdo->prepare(
    "SELECT e.*,
            (SELECT COALESCE(SUM(p.ticket_qty), 0) FROM campaign_pledges p
              WHERE p.event_id = e.id AND p.pledge_type = 'ticket' AND p.payment_status = 'paid') AS sold,
            (SELECT COALESCE(SUM(p.amount_eur), 0) FROM campaign_pledges p
              WHERE p.event_id = e.id AND p.pledge_type = 'ticket' AND p.payment_status = 'paid') AS revenue
       FROM events e" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "
      ORDER BY " . ($when === 'past' ? 'e.event_date DESC' : 'e.event_date IS NULL, e.event_date') . ", e.id DESC"
);
$st->execute($args);
$events = $st->fetchAll(PDO::FETCH_ASSOC);
if ($state === 'on_sale') {
    $events = array_values(array_filter($events, fn($e) => event_sale_state($pdo, $e, $today) === 'on_sale'));
}

$flashes = flash_get();
$tones = [
    'ok'   => 'background:#e6f4ea;color:#1e5128;',
    'warn' => 'background:#fff4e5;color:#7a3e00;',
    'off'  => 'background:#f1f2f4;color:#374151;',
];
$chip = static function (string $href, string $label, bool $on): string {
    return '<a href="' . h($href) . '"' . ($on ? ' aria-current="true"' : '')
        . ' style="display:inline-flex;align-items:center;min-height:40px;padding:.3rem .9rem;border-radius:20px;font-size:.9rem;text-decoration:none;font-weight:600;'
        . ($on ? 'background:var(--teal,#0387A5);color:#fff;' : 'background:#f0ede9;color:#374151;') . '">' . h($label) . '</a>';
};
$qs = static fn(array $over) => '/admin/events.php?' . http_build_query(array_merge(['when' => $when, 'state' => $state], $over));

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<?php foreach ($flashes as $f): $ok = ($f['type'] ?? '') === 'success'; ?>
  <div role="<?= $ok ? 'status' : 'alert' ?>" style="padding:.9rem 1.25rem;border-radius:8px;margin-bottom:1rem;line-height:1.5;<?= $ok ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#1e5128;' : 'background:#fdf0ef;border:1px solid #f0c4c0;color:#9b2c1f;' ?>">
    <?= $ok ? '✓ ' : '' ?><?= h((string) $f['message']) ?>
  </div>
<?php endforeach; ?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
  <p style="margin:0;max-width:44rem;line-height:1.6;">Създайте събитие, включете продажбата на билети и купувачите получават билетите си в PDF по имейл. На входа ползвайте „Списък за входа“.</p>
  <a href="/admin/event-edit.php" class="btn btn--primary" style="display:inline-flex;align-items:center;min-height:44px;">+ Ново събитие</a>
</div>

<nav aria-label="Филтри" style="display:flex;flex-wrap:wrap;gap:1.25rem;margin-bottom:1.25rem;">
  <div style="display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;">
    <span style="font-weight:600;margin-right:.25rem;">Кога:</span>
    <?= $chip($qs(['when' => 'upcoming']), 'Предстоящи', $when === 'upcoming') ?>
    <?= $chip($qs(['when' => 'past']), 'Отминали', $when === 'past') ?>
    <?= $chip($qs(['when' => 'all']), 'Всички', $when === 'all') ?>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;">
    <span style="font-weight:600;margin-right:.25rem;">Състояние:</span>
    <?= $chip($qs(['state' => 'all']), 'Всички', $state === 'all') ?>
    <?= $chip($qs(['state' => 'on_sale']), 'Продават билети', $state === 'on_sale') ?>
    <?= $chip($qs(['state' => 'visible']), 'Показани', $state === 'visible') ?>
    <?= $chip($qs(['state' => 'hidden']), 'Скрити', $state === 'hidden') ?>
  </div>
</nav>

<?php if (!$events): ?>
  <div class="admin-card" style="padding:2rem;text-align:center;line-height:1.6;">
    <?= $when === 'upcoming' && $state === 'all'
        ? 'Още няма предстоящи събития. Натиснете „+ Ново събитие“, за да създадете първото.'
        : 'Няма събития, които да отговарят на филтрите.' ?>
  </div>
<?php else: ?>
<div style="overflow-x:auto;background:#fff;border:1px solid var(--border,#e2e0db);border-radius:10px;">
  <table style="width:100%;border-collapse:collapse;min-width:640px;">
    <caption style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">Събития</caption>
    <thead>
      <tr style="text-align:left;border-bottom:2px solid var(--border,#e2e0db);">
        <th scope="col" style="padding:.7rem .75rem;width:44px;"><input type="checkbox" id="select-all" aria-label="Избери всички показани събития" style="width:20px;height:20px;cursor:pointer;"></th>
        <th scope="col" style="padding:.7rem .75rem;">Събитие</th>
        <th scope="col" style="padding:.7rem .75rem;">Кога</th>
        <th scope="col" style="padding:.7rem .75rem;">Състояние</th>
        <th scope="col" style="padding:.7rem .75rem;text-align:right;">Продадени</th>
        <th scope="col" style="padding:.7rem .75rem;"><span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">Действия</span></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($events as $e):
        $badge = event_admin_badge($pdo, $e, $today);
        $cap   = $e['capacity'] !== null ? ' / ' . (int) $e['capacity'] : ''; ?>
      <tr style="border-bottom:1px solid #f0ede9;">
        <td style="padding:.7rem .75rem;">
          <input type="checkbox" class="row-cb" value="<?= (int) $e['id'] ?>" id="ev-<?= (int) $e['id'] ?>"
                 data-published="<?= (int) $e['published'] ?>" data-sales="<?= (int) $e['sales_open'] ?>"
                 aria-label="Избери „<?= h($e['title']) ?>“" style="width:20px;height:20px;cursor:pointer;">
        </td>
        <td style="padding:.7rem .75rem;min-width:200px;">
          <a href="/admin/event-edit.php?id=<?= (int) $e['id'] ?>" style="font-weight:600;"><?= h($e['title']) ?></a>
          <?php if ($e['place'] !== ''): ?><div style="font-size:.88rem;color:#4b5563;"><?= h($e['place']) ?></div><?php endif; ?>
        </td>
        <td style="padding:.7rem .75rem;white-space:nowrap;"><?= h(event_when($e, 'bg') ?: '—') ?></td>
        <td style="padding:.7rem .75rem;">
          <span style="display:inline-block;padding:.2rem .6rem;border-radius:999px;font-size:.85rem;font-weight:600;<?= $tones[$badge['tone']] ?>"><?= h($badge['text']) ?></span>
        </td>
        <td style="padding:.7rem .75rem;text-align:right;white-space:nowrap;">
          <strong><?= (int) $e['sold'] ?></strong><?= h($cap) ?>
          <div style="font-size:.85rem;color:#4b5563;"><?= number_format((float) $e['revenue'], 2, '.', ' ') ?> EUR</div>
        </td>
        <td style="padding:.7rem .75rem;white-space:nowrap;text-align:right;">
          <a href="/admin/event-edit.php?id=<?= (int) $e['id'] ?>" style="display:inline-block;padding:.5rem;">Редактирай</a>
          <a href="/admin/ticket-checklist.php?event=<?= (int) $e['id'] ?>" style="display:inline-block;padding:.5rem;">Списък за входа</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- Bulk action bar: appears when rows are selected. Inline styles on purpose (admin.css may be cached). -->
<div id="bulk-bar" role="region" aria-label="Действия с избраните"
     style="position:fixed;bottom:0;left:0;right:0;background:#1a1a2e;color:#fff;padding:.85rem 1rem;display:none;flex-wrap:wrap;align-items:center;gap:.6rem;z-index:1000;box-shadow:0 -2px 8px rgba(0,0,0,.3);">
  <span id="bulk-count" aria-live="polite" style="font-weight:600;">0 избрани</span>
  <button type="button" id="bulk-publish" class="btn btn--primary" style="min-height:44px;">Покажи на сайта</button>
  <button type="button" id="bulk-sales" class="btn btn--primary" style="min-height:44px;">Отвори продажбите</button>
  <button type="button" id="bulk-delete" class="btn btn--danger" style="min-height:44px;">Изтрий</button>
  <button type="button" id="bulk-clear" class="btn-link" style="color:#ddd;margin-left:auto;min-height:44px;">✕ Изчисти избора</button>
</div>
<form id="bulk-form" method="post" action="<?= h($qs([])) ?>" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="bulk_action" value="">
</form>

<script>
(function () {
  var all = document.getElementById('select-all');
  if (!all) return;
  var bar = document.getElementById('bulk-bar'), count = document.getElementById('bulk-count');
  var pub = document.getElementById('bulk-publish'), sales = document.getElementById('bulk-sales');
  var form = document.getElementById('bulk-form');
  function boxes() { return Array.prototype.slice.call(document.querySelectorAll('.row-cb')); }
  function picked() { return boxes().filter(function (b) { return b.checked; }); }
  function every(sel, key) { return sel.length > 0 && sel.every(function (b) { return b.dataset[key] === '1'; }); }
  function update() {
    var sel = picked(), n = sel.length;
    bar.style.display = n ? 'flex' : 'none';
    document.body.style.paddingBottom = n ? '5rem' : '';
    count.textContent = n === 1 ? '1 избрано' : n + ' избрани';
    pub.textContent   = every(sel, 'published') ? 'Скрий от сайта' : 'Покажи на сайта';
    sales.textContent = every(sel, 'sales') ? 'Спри продажбите' : 'Отвори продажбите';
    var b = boxes();
    all.checked = b.length > 0 && n === b.length;
    all.indeterminate = n > 0 && n < b.length;
  }
  function send(action) {
    var sel = picked();
    if (!sel.length) return;
    form.querySelectorAll('input[name="ids[]"]').forEach(function (i) { i.remove(); });
    sel.forEach(function (b) { var i = document.createElement('input'); i.type = 'hidden'; i.name = 'ids[]'; i.value = b.value; form.appendChild(i); });
    form.elements.bulk_action.value = action;
    form.submit();
  }
  all.addEventListener('change', function () { boxes().forEach(function (b) { b.checked = all.checked; }); update(); });
  boxes().forEach(function (b) { b.addEventListener('change', update); });
  pub.addEventListener('click', function () { send(every(picked(), 'published') ? 'hide' : 'publish'); });
  sales.addEventListener('click', function () { send(every(picked(), 'sales') ? 'close_sales' : 'open_sales'); });
  document.getElementById('bulk-delete').addEventListener('click', function () {
    var n = picked().length;
    _adminConfirm('Изтриване на ' + (n === 1 ? '1 събитие' : n + ' събития') + '. Събитията с продадени билети няма да бъдат изтрити — тях можете само да скриете. Продължавате?', 'Изтрий')
      .then(function (ok) { if (ok) send('delete'); });
  });
  document.getElementById('bulk-clear').addEventListener('click', function () { boxes().forEach(function (b) { b.checked = false; }); update(); all.focus(); });
})();
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>

<?php
/**
 * Events — /sabitiya/ (BG) and /en/events/ (a thin wrapper), module "events".
 *
 *   /sabitiya/          upcoming published events
 *   /sabitiya/<slug>/   one event, with the ticket form while sales are open
 *
 * Apache routes /sabitiya/<slug>/ and /en/events/<slug>/ here (.htaccess);
 * PHP's built-in server finds this index.php on its own.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/events.php';
start_session();

// Module switched off in Admin → Модули — the page does not exist (site's 404).
module_public_guard('events');

$lang  = get_lang();
$is_en = $lang === 'en';
$pdo   = get_pdo();
events_adopt_legacy($pdo);

// The slug is the path segment after the listing's own address.
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$base = rtrim(events_path('list', $lang), '/');
$slug = trim(substr($path, strlen($base)), '/');
if ($slug === 'index.php') $slug = '';

$event = null;
if ($slug !== '') {
    $event = preg_match('/^[a-z0-9-]{1,190}$/', $slug) ? event_by_slug($pdo, $slug) : null;
    if (!$event) {
        require $_SERVER['DOCUMENT_ROOT'] . '/errors/404.php';
        exit;
    }
}

$money = static fn(float $v): string => number_format($v, 2, '.', ' ') . ' EUR';
$state_label = static function (string $state) use ($lang): string {
    return match ($state) {
        'on_sale'  => t_or('events.state.on_sale', 'Билети в продажба', 'Tickets on sale', $lang),
        'sold_out' => t_or('events.state.sold_out', 'Разпродадено', 'Sold out', $lang),
        'past'     => t_or('events.state.past', 'Събитието вече се е състояло', 'This event has already taken place', $lang),
        default    => t_or('events.state.closed', 'Билетите още не се продават онлайн', 'Tickets are not on sale online', $lang),
    };
};
$icon_cal   = '<svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:2px;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
$icon_place = '<svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:2px;"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1118 0z"/><circle cx="12" cy="10" r="3"/></svg>';

// ── Listing ──────────────────────────────────────────────────────────────────
if (!$event):
    $events = events_public_list($pdo);
    $page_title       = t_or('events.title', 'Събития', 'Events', $lang);
    $page_description = t_or('events.description', 'Предстоящи събития и билети.', 'Upcoming events and tickets.', $lang);
    require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>
<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container" style="max-width:960px;">
    <span class="section-label"><?= h(t_or('events.label', 'Събития', 'Events', $lang)) ?></span>
    <h1><?= h(t_or('events.heading', 'Предстоящи събития', 'Upcoming events', $lang)) ?></h1>
  </div>
</section>

<section class="section" style="padding-top:2rem;">
  <div class="container" style="max-width:960px;">
    <?php if (!$events): ?>
      <p style="font-size:1.05rem;line-height:1.7;margin:0;"><?= h(t_or('events.none', 'В момента няма предстоящи събития. Заповядайте отново скоро!', 'There are no upcoming events right now. Please check back soon!', $lang)) ?></p>
    <?php else: ?>
      <ul style="list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,280px),1fr));gap:1.25rem;">
      <?php foreach ($events as $e):
          $state = event_sale_state($pdo, $e);
          $when  = event_when($e, $lang);
          $place = event_text($e, 'place', $lang); ?>
        <li style="display:flex;flex-direction:column;background:#fff;border:1px solid var(--border,#e8ddd5);border-top:5px solid var(--teal,#0387A5);border-radius:12px;padding:1.25rem 1.25rem 1.35rem;min-width:0;">
          <h2 style="font-size:1.25rem;line-height:1.3;margin:0 0 .75rem;overflow-wrap:anywhere;">
            <a href="<?= h(event_url($e, $lang)) ?>" style="color:inherit;"><?= h(event_text($e, 'title', $lang)) ?></a>
          </h2>
          <?php if ($when): ?><p style="display:flex;gap:.5rem;margin:0 0 .4rem;line-height:1.5;"><?= $icon_cal ?><span><?= h($when) ?></span></p><?php endif; ?>
          <?php if ($place): ?><p style="display:flex;gap:.5rem;margin:0 0 .4rem;line-height:1.5;overflow-wrap:anywhere;"><?= $icon_place ?><span><?= h($place) ?></span></p><?php endif; ?>
          <p style="margin:.5rem 0 1rem;font-weight:600;color:<?= $state === 'on_sale' ? '#1e5128' : '#4b5563' ?>;">
            <?= $state === 'on_sale' ? '✓ ' : '' ?><?= h($state_label($state)) ?><?php if ($state === 'on_sale'): ?> · <?= h($money((float) $e['price_eur'])) ?><?php endif; ?>
          </p>
          <a href="<?= h(event_url($e, $lang)) ?>" class="btn btn--primary" style="margin-top:auto;display:inline-flex;align-items:center;justify-content:center;min-height:44px;text-align:center;">
            <?= h($state === 'on_sale' ? t_or('events.cta.buy', 'Виж и купи билет', 'See details and buy a ticket', $lang) : t_or('events.cta.view', 'Виж събитието', 'See the event', $lang)) ?>
            <span class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;">: <?= h(event_text($e, 'title', $lang)) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>
<?php
    require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php';
    exit;
endif;

// ── One event ────────────────────────────────────────────────────────────────
$state = event_sale_state($pdo, $event);
$title = event_text($event, 'title', $lang);
$when  = event_when($event, $lang);
$place = event_text($event, 'place', $lang);
$desc  = event_text($event, 'description', $lang);
$price = (float) $event['price_eur'];
$left  = $state === 'on_sale' ? event_seats_left($pdo, $event) : null;
$max   = min(EVENT_MAX_TICKETS_PER_ORDER, $left ?? EVENT_MAX_TICKETS_PER_ORDER);

// A failed attempt comes back with its message and what was typed.
$flash = $_SESSION['event_error'] ?? null;
unset($_SESSION['event_error']);
if (!is_array($flash) || ($flash['event'] ?? 0) !== (int) $event['id']) $flash = null;
$old = $flash['old'] ?? [];

$page_title       = $title;
$page_description = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($desc))), 0, 160) ?: ($when . ($place ? ' · ' . $place : ''));
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
$in_style  = 'width:100%;box-sizing:border-box;min-height:48px;padding:.7rem .9rem;border:1.5px solid #8a8f98;border-radius:8px;font-size:1rem;font-family:inherit;background:#fff;color:inherit;';
$lbl_style = 'display:block;font-weight:600;font-size:.95rem;margin-bottom:.35rem;';
?>
<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container" style="max-width:960px;">
    <a href="<?= h(events_path('list', $lang)) ?>" style="display:inline-block;margin-bottom:.75rem;font-weight:600;">← <?= h(t_or('events.back', 'Всички събития', 'All events', $lang)) ?></a>
    <h1 style="overflow-wrap:anywhere;margin:0 0 1rem;"><?= h($title) ?></h1>
    <?php if ($when): ?><p style="display:flex;gap:.55rem;margin:0 0 .4rem;font-size:1.05rem;line-height:1.5;"><?= $icon_cal ?><strong><?= h($when) ?></strong></p><?php endif; ?>
    <?php if ($place): ?><p style="display:flex;gap:.55rem;margin:0;font-size:1.05rem;line-height:1.5;overflow-wrap:anywhere;"><?= $icon_place ?><span><?= h($place) ?></span></p><?php endif; ?>
  </div>
</section>

<section class="section" style="padding-top:2rem;">
  <div class="container" style="max-width:960px;display:flex;flex-wrap:wrap;gap:2rem;align-items:flex-start;">
    <div style="flex:1 1 340px;min-width:0;">
      <?php if (trim(strip_tags($desc)) !== ''): ?>
        <div class="rich-text" style="line-height:1.75;overflow-wrap:anywhere;"><?= $desc ?></div>
      <?php endif; ?>
      <?php if ($event['fb_url'] !== ''): ?>
        <p style="margin:1.25rem 0 0;"><a href="<?= h($event['fb_url']) ?>" target="_blank" rel="noopener noreferrer" style="font-weight:600;">
          <?= h(t_or('events.facebook', 'Събитието във Facebook', 'The event on Facebook', $lang)) ?>
          <span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;"><?= h(t_or('events.new_tab', '(отваря се в нов прозорец)', '(opens in a new window)', $lang)) ?></span> ↗</a></p>
      <?php endif; ?>
    </div>

    <aside aria-labelledby="buy-title" style="flex:1 1 300px;max-width:440px;min-width:0;background:#fff;border:1px solid var(--border,#e8ddd5);border-radius:12px;padding:1.5rem;box-sizing:border-box;">
      <h2 id="buy-title" style="font-size:1.2rem;margin:0 0 1rem;"><?= h(t_or('events.buy.title', 'Билети', 'Tickets', $lang)) ?></h2>

      <?php if ($flash): ?>
        <div role="alert" id="buy-error" style="background:#fdf0ef;border:1px solid #f0c4c0;color:#9b2c1f;border-radius:8px;padding:.8rem 1rem;margin-bottom:1rem;line-height:1.5;">
          <strong><?= h(t_or('events.buy.error', 'Билетът не е купен.', 'The ticket was not bought.', $lang)) ?></strong> <?= h((string) $flash['message']) ?>
        </div>
      <?php endif; ?>
      <?php if ($state !== 'on_sale'): ?>
        <p style="margin:0;line-height:1.6;font-weight:600;"><?= h($state_label($state)) ?></p>
        <?php if ($state !== 'past'): ?>
          <p style="margin:.5rem 0 0;line-height:1.6;color:#4b5563;"><?= h(t_or('events.buy.contact', 'За въпроси пишете ни на', 'For questions, write to us at', $lang)) ?> <a href="mailto:<?= h(SITE_EMAIL) ?>"><?= h(SITE_EMAIL) ?></a>.</p>
        <?php endif; ?>
      <?php else: ?>
        <p style="margin:0 0 1rem;"><span style="font-size:1.6rem;font-weight:800;color:var(--teal,#0387A5);"><?= h($money($price)) ?></span>
          <span style="color:#4b5563;"> <?= h(t_or('events.buy.per', 'на билет', 'per ticket', $lang)) ?></span></p>
        <?php if ($left !== null && $left <= 20): ?>
          <p style="margin:-.5rem 0 1rem;color:#92400e;font-weight:600;"><?= h(t_or('events.buy.left', 'Остават {n} места.', '{n} seats left.', $lang, ['n' => $left])) ?></p>
        <?php endif; ?>


        <form method="post" action="<?= h(events_path('checkout')) ?>" id="ticketForm" novalidate<?= $flash ? ' aria-describedby="buy-error"' : '' ?>>
          <?= csrf_field() ?>
          <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
          <input type="hidden" name="lang" value="<?= h($lang) ?>">

          <div style="margin-bottom:1rem;">
            <label for="tk-name" style="<?= $lbl_style ?>"><?= h(t_or('events.buy.name', 'Вашето име', 'Your name', $lang)) ?></label>
            <input id="tk-name" type="text" name="name" required maxlength="200" autocomplete="name" value="<?= h((string) ($old['name'] ?? '')) ?>" style="<?= $in_style ?>">
          </div>
          <div style="margin-bottom:1rem;">
            <label for="tk-email" style="<?= $lbl_style ?>"><?= h(t_or('events.buy.email', 'Имейл', 'Email', $lang)) ?></label>
            <input id="tk-email" type="email" name="email" required maxlength="200" autocomplete="email" inputmode="email" aria-describedby="tk-email-hint" value="<?= h((string) ($old['email'] ?? '')) ?>" style="<?= $in_style ?>">
            <p id="tk-email-hint" style="margin:.3rem 0 0;font-size:.88rem;color:#4b5563;"><?= h(t_or('events.buy.email_hint', 'Тук ще изпратим билетите в PDF.', 'We will send the tickets here as PDF files.', $lang)) ?></p>
          </div>
          <div style="margin-bottom:1rem;">
            <label for="tk-qty" style="<?= $lbl_style ?>"><?= h(t_or('events.buy.qty', 'Брой билети', 'Number of tickets', $lang)) ?></label>
            <div style="display:flex;align-items:center;gap:.5rem;">
              <button type="button" data-qty="-1" aria-controls="tk-qty" aria-label="<?= h(t_or('events.buy.less', 'Един билет по-малко', 'One ticket fewer', $lang)) ?>"
                      style="width:44px;height:44px;border:1.5px solid #8a8f98;border-radius:8px;background:#f8f6f2;font-size:1.3rem;line-height:1;cursor:pointer;font-family:inherit;color:inherit;">−</button>
              <input id="tk-qty" type="number" name="ticket_qty" value="<?= max(1, min($max, (int) ($old['ticket_qty'] ?? 1))) ?>" min="1" max="<?= $max ?>" inputmode="numeric"
                     style="width:4.5rem;min-height:44px;text-align:center;border:1.5px solid #8a8f98;border-radius:8px;font-size:1rem;font-family:inherit;">
              <button type="button" data-qty="1" aria-controls="tk-qty" aria-label="<?= h(t_or('events.buy.more', 'Един билет повече', 'One ticket more', $lang)) ?>"
                      style="width:44px;height:44px;border:1.5px solid #8a8f98;border-radius:8px;background:#f8f6f2;font-size:1.3rem;line-height:1;cursor:pointer;font-family:inherit;color:inherit;">+</button>
            </div>
          </div>
          <p style="display:flex;justify-content:space-between;align-items:center;gap:1rem;background:var(--teal-light,#e4f0f5);border-radius:10px;padding:.8rem 1rem;margin:0 0 1rem;">
            <span><?= h(t_or('events.buy.total', 'Общо', 'Total', $lang)) ?></span>
            <strong id="tk-total" aria-live="polite" style="font-size:1.25rem;"><?= h($money($price * max(1, min($max, (int) ($old['ticket_qty'] ?? 1))))) ?></strong>
          </p>
          <button type="submit" class="btn btn--primary" style="width:100%;min-height:48px;font-size:1.05rem;">
            <?= h(t_or('events.buy.submit', 'Към плащане с карта', 'Continue to card payment', $lang)) ?>
          </button>
          <p style="font-size:.88rem;color:#4b5563;margin:.75rem 0 0;line-height:1.5;">
            <?= h(t_or('events.buy.note', 'Плащате на защитената страница на банката. След плащането билетите идват на имейла ви.', 'You pay on the bank’s secure page. After payment the tickets arrive in your email.', $lang)) ?>
          </p>
        </form>
        <script>
        (function () {
          var qty = document.getElementById('tk-qty'), total = document.getElementById('tk-total');
          var price = <?= json_encode($price) ?>, max = <?= (int) $max ?>;
          function fmt(v) { return v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' EUR'; }
          function sync() {
            var n = Math.min(max, Math.max(1, parseInt(qty.value, 10) || 1));
            if (String(n) !== qty.value) qty.value = n;
            total.textContent = fmt(price * n);
          }
          document.querySelectorAll('#ticketForm [data-qty]').forEach(function (b) {
            b.addEventListener('click', function () { qty.value = (parseInt(qty.value, 10) || 1) + parseInt(b.dataset.qty, 10); sync(); });
          });
          qty.addEventListener('change', sync);
          qty.addEventListener('input', function () { if (qty.value !== '') sync(); });
        })();
        </script>
      <?php endif; ?>
    </aside>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>

<?php
/**
 * After a ticket payment — /sabitiya/confirmation/?pledge=CP-… (EN /en/events/confirmation/).
 * Paid: thank you, tickets are in your email. Not paid: what happened and a
 * "try again" button. The buyer's email address shows only in the browser
 * that made the purchase; the number in the address is easy to guess.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/order_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/events.php';
start_session();

// Module switched off in Admin → Модули — the page does not exist (site's 404).
module_public_guard('events');

$lang   = get_lang();
$pdo    = get_pdo();
$number = trim((string) ($_GET['pledge'] ?? ''));
$pledge = event_ticket_pledge($pdo, $number);
if (!$pledge) {
    header('Location: ' . events_path('list', $lang));
    exit;
}
$mine  = order_session_owns($pledge['pledge_number']);
$event = event_for_pledge($pdo, $pledge);
$paid  = $pledge['payment_status'] === 'paid';
$title = event_text($event, 'title', $lang);
$qty   = max(1, (int) $pledge['ticket_qty']);

$page_title = $paid
    ? t_or('events.done.title', 'Благодарим ви!', 'Thank you!', $lang)
    : t_or('events.failed.title', 'Плащането не е завършено', 'The payment was not completed', $lang);
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>
<section class="section" style="min-height:55vh;">
  <div class="container" style="max-width:640px;margin:0 auto;">
    <?php if ($paid): ?>
      <h1 style="margin:0 0 1rem;color:var(--teal,#0387A5);">✓ <?= h($page_title) ?></h1>
      <div role="status">
        <p style="font-size:1.1rem;line-height:1.7;margin:0 0 1rem;">
          <?= h(t_or('events.done.text', 'Плащането е получено. Вашите билети ({qty}) за „{event}“ са изпратени по имейл.',
                'Your payment was received. Your tickets ({qty}) for “{event}” have been sent by email.', $lang, ['qty' => $qty, 'event' => $title])) ?>
          <?php if ($mine): ?><br><?= h(t_or('events.done.email', 'Изпратихме ги на {email}.', 'We sent them to {email}.', $lang, ['email' => $pledge['email']])) ?><?php endif; ?>
        </p>
      </div>
      <?php if ($w = event_when($event, $lang)): ?><p style="margin:0 0 .4rem;"><strong><?= h($w) ?></strong></p><?php endif; ?>
      <?php if ($pl = event_text($event, 'place', $lang)): ?><p style="margin:0 0 1rem;"><?= h($pl) ?></p><?php endif; ?>
      <p style="color:#4b5563;line-height:1.6;margin:0 0 1.5rem;">
        <?= h(t_or('events.done.spam', 'Ако не виждате имейла до няколко минути, проверете папка „Спам“ или ни пишете на', 'If you don’t see the email within a few minutes, check your spam folder or write to us at', $lang)) ?>
        <a href="mailto:<?= h(SITE_EMAIL) ?>"><?= h(SITE_EMAIL) ?></a>.
        <br><?= h(t_or('events.done.number', 'Номер на покупката: {n}', 'Purchase number: {n}', $lang, ['n' => $pledge['pledge_number']])) ?>
      </p>
    <?php else: ?>
      <h1 style="margin:0 0 1rem;"><?= h($page_title) ?></h1>
      <div role="alert" style="background:#fdf0ef;border:1px solid #f0c4c0;color:#9b2c1f;border-radius:8px;padding:1rem 1.25rem;line-height:1.6;margin-bottom:1.25rem;">
        <?= h(t_or('events.failed.text', 'Не сме получили плащане за билетите за „{event}“ и не сме ви таксували. Можете да опитате отново.',
              'We have not received a payment for your tickets for “{event}”, and you have not been charged. You can try again.', $lang, ['event' => $title])) ?>
      </div>
      <?php if ($pledge['payment_status'] === 'pending' && $mine): ?>
        <a href="/api/event-payment-return.php?pledge=<?= h(urlencode($pledge['pledge_number'])) ?>&amp;retry=1" class="btn btn--primary" style="display:inline-flex;align-items:center;min-height:48px;margin:0 .75rem .75rem 0;">
          <?= h(t_or('events.failed.retry', 'Опитай да платиш отново', 'Try paying again', $lang)) ?>
        </a>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($event['slug'])): ?>
      <a href="<?= h(event_url($event, $lang)) ?>" style="display:inline-block;font-weight:600;min-height:44px;line-height:44px;">← <?= h(t_or('events.done.back', 'Към събитието', 'Back to the event', $lang)) ?></a>
    <?php else: ?>
      <a href="<?= h(events_path('list', $lang)) ?>" style="display:inline-block;font-weight:600;min-height:44px;line-height:44px;">← <?= h(t_or('events.back', 'Всички събития', 'All events', $lang)) ?></a>
    <?php endif; ?>
  </div>
</section>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>

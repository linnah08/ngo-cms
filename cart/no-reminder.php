<?php
/**
 * "Don't remind me": the link at the bottom of a reminder email (?t=token).
 * GET only shows a confirm page and changes nothing, so a mail scanner that
 * follows the link cannot opt anyone out; the button posts the form (CSRF
 * checked), sets opted_out_at on that one row and forgets its email and name.
 * An unknown or malformed link gets a plain message (HTTP 404) and nothing from
 * any row.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/saved_carts.php';
start_session();

$req_lang = $_REQUEST['lang'] ?? '';
$nr_lang  = in_array($req_lang, ['bg', 'en'], true) ? $req_lang : get_lang();

$token = $_REQUEST['t'] ?? '';
$row   = null;
if (is_string($token) && saved_cart_token_valid($token)) {
    $row = saved_cart_find(get_pdo(), $token);
}
if ($row !== null) $nr_lang = $row['lang'] === 'en' ? 'en' : 'bg';

$state = 'unknown';   // unknown | confirm | done | retry
if ($row !== null) {
    $state = 'confirm';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (csrf_verify()) {
            saved_cart_opt_out(get_pdo(), $token);   // also forgets the email address and name
            $state = 'done';
        } else {
            $state = 'retry';
        }
    }
} else {
    http_response_code(404);
}

$page_title       = t_or('cart.noreminder.title', 'Без напомняния', 'No reminders', $nr_lang);
$page_description = '';
$page_head_extra  = '<meta name="robots" content="noindex,nofollow">';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';

$_home = $nr_lang === 'en' ? '/en/' : '/';
?>

<section class="section" style="padding-top:3rem;padding-bottom:3rem;">
  <div class="container" style="max-width:36rem;margin:0 auto;padding:0 1rem;">
    <h1 style="font-size:1.6rem;line-height:1.3;margin:0 0 1rem;"><?= h($page_title) ?></h1>
    <div role="status">
<?php if ($state === 'done'): ?>
      <p style="font-size:1.05rem;line-height:1.6;"><?= h(t_or('cart.noreminder.done', 'Готово. Повече няма да ви напомняме за тази поръчка.', 'Done. We won\'t remind you about this order again.', $nr_lang)) ?></p>
<?php elseif ($state === 'unknown'): ?>
      <p style="font-size:1.05rem;line-height:1.6;"><?= h(t_or('cart.noreminder.unknown', 'Тази връзка вече не е валидна. Ако все още получавате напомняния, пишете ни и ще ги спрем.', 'This link is no longer valid. If you are still getting reminders, write to us and we will stop them.', $nr_lang)) ?></p>
<?php elseif ($state === 'retry'): ?>
      <p style="font-size:1.05rem;line-height:1.6;color:#8a1c1c;font-weight:600;"><?= h(t_or('cart.noreminder.retry', 'Не успяхме да запазим избора ви. Моля, опитайте отново.', 'We could not save your choice. Please try again.', $nr_lang)) ?></p>
<?php endif; ?>
    </div>
<?php if ($state === 'confirm' || $state === 'retry'): ?>
    <p style="font-size:1.05rem;line-height:1.6;"><?= h(t_or('cart.noreminder.ask', 'Искате ли да спрем напомнянията по имейл за тази поръчка?', 'Do you want us to stop emailing you reminders about this order?', $nr_lang)) ?></p>
    <form method="post" action="<?= get_lang() === 'en' ? '/en' : '' ?>/cart/no-reminder.php?t=<?= h($token) ?>" style="margin:1.25rem 0;">
      <?= csrf_field() ?>
      <button type="submit" style="min-height:44px;padding:0 1.5rem;border:2px solid #0387A5;border-radius:6px;background:#0387A5;color:#fff;font-size:1rem;font-weight:700;cursor:pointer;"><?= h(t_or('cart.noreminder.button', 'Да, не ми напомняйте', 'Yes, don\'t remind me', $nr_lang)) ?></button>
    </form>
<?php endif; ?>
    <p><a href="<?= h($_home) ?>" style="display:inline-block;min-height:44px;line-height:44px;color:#0387A5;font-weight:600;text-decoration:underline;"><?= h(t_or('cart.noreminder.home', 'Към началната страница', 'Back to the home page', $nr_lang)) ?></a></p>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>

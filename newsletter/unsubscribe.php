<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';

$token = trim($_GET['token'] ?? '');
$found = newsletter_unsubscribe($token);
$lang  = get_lang();

// Always show the same success page regardless of whether the token was found —
// avoids leaking whether an email address is in the list.
$page_title       = $lang === 'bg' ? 'Отписани сте' : 'Unsubscribed';
$page_description = '';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>
<section class="section">
  <div class="container" style="max-width:520px;text-align:center;padding:5rem 1rem;">
    <div style="font-size:3rem;margin-bottom:1.5rem;">✓</div>
    <h1 style="margin-bottom:1rem;">
      <?= $lang === 'bg' ? 'Отписани сте успешно' : 'You have been unsubscribed' ?>
    </h1>
    <p style="color:var(--text-muted);margin-bottom:2rem;">
      <?= $lang === 'bg'
          ? 'Няма да получавате повече имейли от нас. Ако се прехвърлихте по грешка, можете да се запишете отново по всяко време.'
          : 'You will no longer receive emails from us. If you unsubscribed by mistake, you can sign up again at any time.' ?>
    </p>
    <a href="<?= $lang === 'bg' ? '/' : '/en/' ?>" class="btn btn--outline">
      <?= $lang === 'bg' ? '← Начало' : '← Home' ?>
    </a>
  </div>
</section>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>

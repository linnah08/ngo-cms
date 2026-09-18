<?php
$page_title_admin = 'Поддръжка';
$active_nav       = 'support';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
admin_require_login(); // any logged-in user may report a problem; changing the code is admin-only (checked below)

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/support.php';

$is_admin   = admin_is_admin();
$errors     = [];
$code_saved = false;
$result     = null; // ['ok'=>bool, 'reference'=>?string, 'error'=>?string]

// Form values — kept on failure so the user never has to retype.
$form = [
    'subject'     => '',
    'description' => '',
    'page'        => support_validate_page((string)($_GET['from'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // An upload bigger than post_max_size empties $_POST entirely (so CSRF
    // would fail with a confusing message) — explain it plainly instead.
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'Прикаченият файл е твърде голям. Максималният размер на снимката е 5 MB. Моля, изберете по-малка снимка и опитайте отново.';
    } elseif (!csrf_verify()) {
        $errors[] = 'Страницата беше отворена твърде дълго и сесията изтече. Моля, опитайте отново.';
        $form['subject']     = (string)($_POST['subject'] ?? '');
        $form['description'] = (string)($_POST['description'] ?? '');
        $form['page']        = support_validate_page((string)($_POST['page'] ?? ''));
    } else {
        $action = (string)($_POST['action'] ?? '');

        // ── Save / change the support code (admin only, enforced server-side) ──
        if ($action === 'save_code') {
            if (!$is_admin) {
                http_response_code(403);
                $errors[] = 'Само администратор може да въвежда или променя кода за поддръжка.';
            } else {
                $code = trim((string)($_POST['support_code'] ?? ''));
                if ($code === '') {
                    $errors[] = 'Моля, поставете кода за поддръжка, който получихте от нас.';
                } elseif (!support_code_is_valid_format($code)) {
                    $errors[] = 'Кодът изглежда непълен или съдържа интервали. Моля, копирайте го отново точно както го получихте.';
                } elseif (!support_set_code($code)) {
                    $errors[] = 'Кодът не можа да бъде запазен. Моля, опитайте отново или се свържете с нас.';
                } else {
                    $code_saved = true;
                }
            }
        }

        // ── Send a ticket ────────────────────────────────────────────────────
        if ($action === 'send_ticket') {
            $form['subject']     = trim((string)($_POST['subject'] ?? ''));
            $form['description'] = trim((string)($_POST['description'] ?? ''));
            $form['page']        = support_validate_page((string)($_POST['page'] ?? ''));

            $text_error = support_validate_text($form['subject'], $form['description']);
            if ($text_error !== null) {
                $result = ['ok' => false, 'reference' => null, 'error' => $text_error];
            } elseif (rate_limit_exceeded('support_ticket', 10, 3600)) {
                $result = ['ok' => false, 'reference' => null,
                           'error' => 'Изпратихте много сигнали за кратко време. Моля, опитайте отново след около час.'];
            } else {
                $upload = (isset($_FILES['screenshot']) && is_array($_FILES['screenshot'])
                           && !is_array($_FILES['screenshot']['error'] ?? null))
                    ? $_FILES['screenshot'] : null;
                $result = support_send_ticket($form['subject'], $form['description'], $form['page'], $upload);
            }

            if ($result['ok']) {
                // Post/Redirect/Get so a page refresh can't send the ticket twice.
                $_SESSION['support_last_reference'] = (string)$result['reference'];
                header('Location: /admin/support.php?sent=1');
                exit;
            }
        }
    }
}

// Success message after the redirect (shown until the user leaves the page).
$sent_reference = null;
if (isset($_GET['sent']) && !empty($_SESSION['support_last_reference'])) {
    $sent_reference = (string)$_SESSION['support_last_reference'];
    unset($_SESSION['support_last_reference']);
}

$configured     = support_is_configured();
$show_code_form = $is_admin && (!$configured || isset($_GET['change']) || (!$code_saved && ($_POST['action'] ?? '') === 'save_code'));

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1>Поддръжка</h1>
</div>

<?php foreach ($errors as $e): ?>
  <div class="admin-alert admin-alert--error" role="alert"><?= h($e) ?></div>
<?php endforeach; ?>

<?php if ($code_saved): ?>
  <div class="admin-alert admin-alert--success" role="status">Кодът за поддръжка е запазен. Вече можете да ни изпращате сигнали за проблеми.</div>
<?php endif; ?>

<?php if ($sent_reference !== null): ?>
  <div class="admin-alert admin-alert--success" role="status" style="margin-bottom:1.5rem;">
    <strong>Благодарим! Сигналът е изпратен.</strong><br>
    Номер на сигнала: <strong><?= h($sent_reference) ?></strong><br>
    Запишете си този номер — ако се свържете с нас за този проблем, той ще ни помогне да го намерим бързо.
  </div>
<?php endif; ?>

<?php if ($result !== null && !$result['ok']): ?>
  <div class="admin-alert admin-alert--error" role="alert" style="margin-bottom:1.5rem;">
    <strong>Сигналът не беше изпратен.</strong><br>
    <?= h((string)$result['error']) ?><br>
    Всичко, което написахте, е запазено по-долу — не е нужно да го пишете отново.
    <?php if (!empty($_FILES['screenshot']['name'] ?? '')): ?>
      Ако сте прикачили снимка, моля, изберете я отново.
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$configured && !$is_admin): ?>
  <!-- ── Not configured, non-admin ─────────────────────────────────────────── -->
  <section class="admin-card" style="margin-bottom:2rem;">
    <h2 class="admin-card__title">Докладване на проблем</h2>
    <p>
      Докладването на проблеми все още не е включено за този сайт.
      Моля, помолете администратора на сайта да въведе кода за поддръжка в тази страница.
    </p>
  </section>
<?php endif; ?>

<?php if ($show_code_form): ?>
  <!-- ── Support code (admin only) ─────────────────────────────────────────── -->
  <section class="admin-card" style="margin-bottom:2rem;">
    <h2 class="admin-card__title">Код за поддръжка</h2>
    <p class="admin-meta">
      <?php if (!$configured): ?>
        За да можете да ни пишете директно от тук, когато нещо не работи, поставете кода за поддръжка,
        който получихте от нас при настройването на сайта. Ако нямате такъв код, свържете се с нас.
      <?php else: ?>
        Поставете новия код за поддръжка, който сте получили от нас. Старият код ще бъде заменен.
      <?php endif; ?>
      <br>Кодът се пази криптиран и не се показва никъде.
    </p>
    <form method="post" action="/admin/support.php" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_code">
      <label>Код за поддръжка
        <input type="password" name="support_code" required maxlength="200"
               style="width:100%;max-width:28rem;box-sizing:border-box;"
               placeholder="Поставете кода тук">
      </label>
      <div style="margin-top:1rem;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
        <button type="submit" class="btn btn--primary">Запази кода</button>
        <?php if ($configured): ?>
          <a href="/admin/support.php" class="btn">Отказ</a>
        <?php endif; ?>
      </div>
    </form>
  </section>
<?php endif; ?>

<?php if ($configured && !$show_code_form): ?>
  <!-- ── Ticket form ──────────────────────────────────────────────────────── -->
  <section class="admin-card" style="margin-bottom:2rem;">
    <h2 class="admin-card__title">Докладвай проблем</h2>
    <p class="admin-meta">
      Нещо не работи както трябва? Опишете какво се случи и ние ще го прегледаме.
    </p>

    <form method="post" action="/admin/support.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="send_ticket">
      <input type="hidden" name="page" value="<?= h($form['page']) ?>">
      <!-- Browser-side hint only; the real 5 MB check happens on the server. -->
      <input type="hidden" name="MAX_FILE_SIZE" value="<?= SUPPORT_SCREENSHOT_MAX_BYTES ?>">

      <div style="display:flex;flex-direction:column;gap:1rem;max-width:44rem;">
        <label style="display:block;">Тема
          <input type="text" name="subject" required maxlength="<?= SUPPORT_SUBJECT_MAX ?>"
                 value="<?= h($form['subject']) ?>"
                 placeholder="Например: Не мога да запазя статия"
                 style="width:100%;box-sizing:border-box;">
        </label>

        <label style="display:block;">Описание
          <textarea name="description" required maxlength="<?= SUPPORT_DESCRIPTION_MAX ?>" rows="8"
                    placeholder="Какво правехте, какво очаквахте да стане и какво стана вместо това?"
                    style="width:100%;box-sizing:border-box;"><?= h($form['description']) ?></textarea>
        </label>

        <label style="display:block;">Снимка на екрана (по желание)
          <input type="file" name="screenshot" accept="image/png,image/jpeg,image/webp"
                 style="display:block;margin-top:.35rem;">
          <span class="admin-meta" style="display:block;margin-top:.25rem;">PNG, JPG или WEBP, до 5 MB.</span>
        </label>

        <?php if ($form['page'] !== ''): ?>
          <p class="admin-meta" style="margin:0;">Страница, от която докладвате: <strong><?= h($form['page']) ?></strong></p>
        <?php endif; ?>

        <div style="background:var(--off-white, #f7f7f5);border:1px solid var(--border, #e5e5e5);border-radius:6px;padding:.75rem 1rem;font-size:.9rem;">
          <strong>Какво ще изпратим заедно със сигнала</strong><br>
          За да открием проблема по-бързо, към сигнала автоматично се добавят:
          адресът и името на сайта, версията на сайта, версията на PHP, начинът на изпращане на имейли,
          вашето име, имейл и роля, страницата, от която докладвате, вашият браузър, текущият час на сървъра
          и последните до 5 грешки, записани от сайта (само часът и кратко описание).
          Пароли, ключове и лични данни на дарители или клиенти <strong>не се изпращат</strong>.
        </div>

        <div>
          <button type="submit" class="btn btn--primary">Изпрати сигнала</button>
        </div>
      </div>
    </form>
  </section>

  <?php if ($is_admin): ?>
    <p class="admin-meta">
      <a href="/admin/support.php?change=1">Промени кода за поддръжка</a>
    </p>
  <?php endif; ?>
<?php endif; ?>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>

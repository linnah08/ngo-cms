<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';

$page_title       = 'Контакти';
$page_description = 'Свържете се с Фондация Различни умове.';
$page_head_extra  = '<style>@media(max-width:640px){.contact-us-form-wrapper{grid-template-columns:1fr!important;gap:2rem!important;}}</style>';

$sent  = false;
$error = '';

// Load contact topics (JSON array stored in settings)
$_raw_topics = setting_get('contact_topics');
$_topics     = $_raw_topics ? (json_decode($_raw_topics, true) ?? []) : [];
if (empty($_topics)) {
    $_topics = [
        'Искам да стана партньор',
        'Искам да стана доброволец',
        'Мога да ви запозная с потенциален партньор',
        'Друго',
    ];
}

$_preselect_topic = trim($_GET['topic'] ?? '');
if (!in_array($_preselect_topic, $_topics, true)) {
    $_preselect_topic = '';
}

// Ensure DB table exists
$pdo = get_pdo();
$pdo->exec("
    CREATE TABLE IF NOT EXISTS contact_submissions (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(200) NOT NULL,
        email      VARCHAR(255) NOT NULL,
        topic      VARCHAR(200) NOT NULL DEFAULT '',
        message    TEXT         NOT NULL,
        status     ENUM('new','read','archived') NOT NULL DEFAULT 'new',
        ip         VARCHAR(45)  NOT NULL DEFAULT '',
        lang       VARCHAR(5)   NOT NULL DEFAULT 'bg',
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status_date (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Невалидна заявка.';
    } else {
        $name    = sanitize($_POST['name']    ?? '');
        $email   = sanitize($_POST['email']   ?? '');
        $topic   = sanitize($_POST['topic']   ?? '');
        $message = sanitize($_POST['message'] ?? '');

        if (!$name || !$email || !$topic || !$message) {
            $error = 'Моля попълнете всички полета.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Невалиден имейл адрес.';
        } elseif (!in_array($topic, $_topics, true)) {
            $error = 'Моля изберете валидна тема.';
        } else {
            // Save to DB
            $pdo->prepare("
                INSERT INTO contact_submissions (name, email, topic, message, ip, lang)
                VALUES (?, ?, ?, ?, ?, 'bg')
            ")->execute([$name, $email, $topic, $message, $_SERVER['REMOTE_ADDR'] ?? '']);

            // Send email notification
            $subject = '[Контакт] ' . $topic . ' — ' . $name;
            $body    = '
                <p><strong>Имена:</strong> ' . h($name) . '</p>
                <p><strong>Имейл:</strong> ' . h($email) . '</p>
                <p><strong>Тема:</strong> ' . h($topic) . '</p>
                <p><strong>Съобщение:</strong></p>
                <p>' . nl2br(h($message)) . '</p>
            ';
            if (send_mail(SITE_EMAIL, $subject, $body, $email)) {
                $sent = true;
            } else {
                $error = 'Грешка при изпращане. Моля опитайте отново или пишете директно на ' . SITE_EMAIL;
            }
        }
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container">
    <span class="section-label">Контакти</span>
    <h1>Свържете се с нас</h1>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="contact-us-form-wrapper" style="display:grid;grid-template-columns:1fr 1.6fr;gap:4rem;align-items:start;">

      <!-- Contact info -->
      <div>
        <h3 style="margin-bottom:1.5rem;">Информация за контакт</h3>
        <div style="display:flex;flex-direction:column;gap:1.5rem;">
          <div>
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:0.35rem;">Телефон</div>
            <a href="tel:<?= SITE_PHONE ?>" style="font-size:1.1rem;"><?= SITE_PHONE ?></a>
          </div>
          <div>
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:0.35rem;">Имейл</div>
            <a href="mailto:<?= SITE_EMAIL ?>" style="font-size:1.1rem;"><?= SITE_EMAIL ?></a>
          </div>
          <div>
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:0.35rem;">Дарения (IBAN)</div>
            <div style="font-family:monospace;font-size:1rem;"><?= SITE_IBAN ?></div>
          </div>
          <div>
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:0.75rem;">Социални мрежи</div>
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
              <?php $social_variant = 'outline'; require $_SERVER['DOCUMENT_ROOT'] . '/templates/social-links.php'; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Contact form -->
      <div>
        <h3 style="margin-bottom:1.5rem;">Изпратете съобщение</h3>

        <?php if ($sent): ?>
          <div style="background:var(--teal-light);border:1px solid var(--teal);border-radius:8px;padding:1.5rem;color:var(--teal);">
            Благодарим! Ще се свържем с вас скоро.
          </div>
        <?php else: ?>
          <?php if ($error): ?>
            <div style="background:#fdf0ef;border:1px solid #f0c4c0;border-radius:8px;padding:1rem;color:#c0392b;margin-bottom:1.5rem;">
              <?= h($error) ?>
            </div>
          <?php endif; ?>
          <form method="POST" action="/kontakti/">
            <?= csrf_field() ?>
            <div class="form-group">
              <label for="name">Имена</label>
              <input type="text" id="name" name="name"
                     value="<?= h($_POST['name'] ?? '') ?>"
                     required autocomplete="name">
            </div>
            <div class="form-group">
              <label for="email">Имейл</label>
              <input type="email" id="email" name="email"
                     value="<?= h($_POST['email'] ?? '') ?>"
                     required autocomplete="email">
            </div>
            <div class="form-group">
              <label for="topic">Как можете да помогнете?</label>
              <select id="topic" name="topic" required
                      style="width:100%;padding:.55rem .75rem;border:1px solid var(--border);border-radius:var(--radius);font-size:1rem;background:#fff;">
                <option value="" disabled <?= (empty($_POST['topic']) && $_preselect_topic === '') ? 'selected' : '' ?>>— Изберете тема —</option>
                <?php foreach ($_topics as $t): ?>
                  <option value="<?= h($t) ?>" <?= (($_POST['topic'] ?? $_preselect_topic) === $t) ? 'selected' : '' ?>><?= h($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="message">Съобщение</label>
              <textarea id="message" name="message" required><?= h($_POST['message'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn--primary">Изпрати съобщение</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>

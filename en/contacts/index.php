<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';

$page_title       = 'Contacts';
$page_description = 'Get in touch with ' . SITE_NAME_EN . '.';

$sent  = false;
$error = '';

// Load contact topics (EN version stored separately; fallback to defaults)
$_raw_topics = setting_get('contact_topics_en');
$_topics     = $_raw_topics ? (json_decode($_raw_topics, true) ?? []) : [];
if (empty($_topics)) {
    $_topics = [
        'I want to become a partner',
        'I want to volunteer',
        'I can introduce you to a potential partner',
        'Other',
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
        $error = 'Invalid request.';
    } else {
        $name    = sanitize($_POST['name']    ?? '');
        $email   = sanitize($_POST['email']   ?? '');
        $topic   = sanitize($_POST['topic']   ?? '');
        $message = sanitize($_POST['message'] ?? '');

        if (!$name || !$email || !$topic || !$message) {
            $error = 'Please fill in all fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif (!in_array($topic, $_topics, true)) {
            $error = 'Please select a valid topic.';
        } else {
            // Save to DB
            $pdo->prepare("
                INSERT INTO contact_submissions (name, email, topic, message, ip, lang)
                VALUES (?, ?, ?, ?, ?, 'en')
            ")->execute([$name, $email, $topic, $message, $_SERVER['REMOTE_ADDR'] ?? '']);

            // Send email notification
            $subject = '[Contact] ' . $topic . ' — ' . $name;
            $body    = '
                <p><strong>Name:</strong> ' . h($name) . '</p>
                <p><strong>Email:</strong> ' . h($email) . '</p>
                <p><strong>Topic:</strong> ' . h($topic) . '</p>
                <p><strong>Message:</strong></p>
                <p>' . nl2br(h($message)) . '</p>
            ';
            if (send_mail(SITE_EMAIL, $subject, $body, $email)) {
                $sent = true;
            } else {
                $error = 'There was an error sending your message. Please try again or email us directly at ' . SITE_EMAIL;
            }
        }
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container">
    <span class="section-label">Contacts</span>
    <h1>Get in touch</h1>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="contact-us-form-wrapper" style="display:grid;grid-template-columns:1fr 1.6fr;gap:4rem;align-items:start;">

      <div>
        <h3 style="margin-bottom:1.5rem;">Contact information</h3>
        <div style="display:flex;flex-direction:column;gap:1.5rem;">
          <div>
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:0.35rem;">Phone</div>
            <a href="tel:<?= SITE_PHONE ?>" style="font-size:1.1rem;"><?= SITE_PHONE ?></a>
          </div>
          <div>
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:0.35rem;">Email</div>
            <a href="mailto:<?= SITE_EMAIL ?>" style="font-size:1.1rem;"><?= SITE_EMAIL ?></a>
          </div>
          <div>
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:0.35rem;">Donations (IBAN)</div>
            <div style="font-family:monospace;font-size:1rem;"><?= SITE_IBAN ?></div>
          </div>
          <div>
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:0.75rem;">Social media</div>
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
              <?php $social_variant = 'outline'; require $_SERVER['DOCUMENT_ROOT'] . '/templates/social-links.php'; ?>
            </div>
          </div>
        </div>
      </div>

      <div>
        <h3 style="margin-bottom:1.5rem;">Send a message</h3>

        <?php if ($sent): ?>
          <div style="background:var(--teal-light);border:1px solid var(--teal);border-radius:8px;padding:1.5rem;color:var(--teal);">
            Thank you! We will be in touch soon.
          </div>
        <?php else: ?>
          <?php if ($error): ?>
            <div style="background:#fdf0ef;border:1px solid #f0c4c0;border-radius:8px;padding:1rem;color:#c0392b;margin-bottom:1.5rem;">
              <?= h($error) ?>
            </div>
          <?php endif; ?>
          <form method="POST" action="/en/contacts/">
            <?= csrf_field() ?>
            <div class="form-group">
              <label for="name">Full name</label>
              <input type="text" id="name" name="name"
                     value="<?= h($_POST['name'] ?? '') ?>"
                     required autocomplete="name">
            </div>
            <div class="form-group">
              <label for="email">Email</label>
              <input type="email" id="email" name="email"
                     value="<?= h($_POST['email'] ?? '') ?>"
                     required autocomplete="email">
            </div>
            <div class="form-group">
              <label for="topic">How can you help?</label>
              <select id="topic" name="topic" required
                      style="width:100%;padding:.55rem .75rem;border:1px solid var(--border);border-radius:var(--radius);font-size:1rem;background:#fff;">
                <option value="" disabled <?= (empty($_POST['topic']) && $_preselect_topic === '') ? 'selected' : '' ?>>— Select a topic —</option>
                <?php foreach ($_topics as $t): ?>
                  <option value="<?= h($t) ?>" <?= (($_POST['topic'] ?? $_preselect_topic) === $t) ? 'selected' : '' ?>><?= h($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="message">Message</label>
              <textarea id="message" name="message" required><?= h($_POST['message'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn--primary">Send message</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>

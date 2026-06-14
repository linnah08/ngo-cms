<?php
/**
 * Shared layout for error pages.
 * Variables expected:
 *   $code        int    — HTTP status code
 *   $title       string — short title (e.g. "Страницата не е намерена")
 *   $title_en    string
 *   $message     string — one-sentence explanation (BG)
 *   $message_en  string
 *   $show_home   bool   — show "Към началото" button (default true)
 */
$show_home = $show_home ?? true;
$lang = 'bg';
if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    if (stripos($_SERVER['HTTP_ACCEPT_LANGUAGE'], 'en') !== false &&
        stripos($_SERVER['HTTP_ACCEPT_LANGUAGE'], 'bg') === false) {
        $lang = 'en';
    }
}
// Simple path-based detection
$path = $_SERVER['REQUEST_URI'] ?? '';
if (str_starts_with($path, '/en/') || $path === '/en') {
    $lang = 'en';
}
$is_en = $lang === 'en';
$display_title   = $is_en ? $title_en   : $title;
$display_message = $is_en ? $message_en : $message;
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $code ?> — <?= htmlspecialchars($display_title, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="icon" href="/assets/images/favicon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Jura:wght@300;400;500;600&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Jura', system-ui, sans-serif;
      background: #f7f5f2;
      color: #1a2e2c;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 2rem 1.25rem;
      text-align: center;
    }
    .err-logo { height: 56px; width: auto; margin-bottom: 2.5rem; }
    .err-code {
      font-size: 6rem;
      font-weight: 300;
      line-height: 1;
      color: #0387A5;
      letter-spacing: -.04em;
      margin-bottom: .5rem;
    }
    .err-title {
      font-size: 1.35rem;
      font-weight: 600;
      margin-bottom: .75rem;
    }
    .err-message {
      font-size: .97rem;
      color: #6b7280;
      line-height: 1.7;
      max-width: 400px;
      margin-bottom: 2rem;
    }
    .err-actions { display: flex; gap: 1rem; flex-wrap: wrap; justify-content: center; }
    .btn {
      display: inline-block;
      padding: .65rem 1.5rem;
      border-radius: 8px;
      font-size: .95rem;
      font-weight: 600;
      font-family: inherit;
      text-decoration: none;
      cursor: pointer;
      transition: background .15s, color .15s;
    }
    .btn--primary { background: #0387A5; color: #fff; }
    .btn--primary:hover { background: #026f89; }
    .btn--outline { background: transparent; color: #0387A5; border: 1.5px solid #0387A5; }
    .btn--outline:hover { background: #0387A5; color: #fff; }
    .err-divider {
      width: 48px;
      height: 3px;
      background: #0387A5;
      border-radius: 2px;
      margin: 1.5rem auto;
      opacity: .4;
    }
  </style>
</head>
<body>
  <a href="<?= $is_en ? '/en/' : '/' ?>">
    <img src="/assets/images/logo.png" alt="Odd Minds" class="err-logo">
  </a>

  <div class="err-code"><?= $code ?></div>
  <div class="err-divider"></div>
  <h1 class="err-title"><?= htmlspecialchars($display_title, ENT_QUOTES, 'UTF-8') ?></h1>
  <p class="err-message"><?= htmlspecialchars($display_message, ENT_QUOTES, 'UTF-8') ?></p>

  <div class="err-actions">
    <?php if ($show_home): ?>
    <a href="<?= $is_en ? '/en/' : '/' ?>" class="btn btn--primary">
      <?= $is_en ? '← Home' : '← Към началото' ?>
    </a>
    <?php endif; ?>
    <a href="<?= $is_en ? '/en/contacts/' : '/kontakti/' ?>" class="btn btn--outline">
      <?= $is_en ? 'Contact us' : 'Свържи се с нас' ?>
    </a>
  </div>
</body>
</html>

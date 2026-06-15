<?php
// Variables: $name, $subject, $body (HTML-safe nl2br'd text)
?>
<h2><?= htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') ?></h2>
<p>Здравей, <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>,</p>
<div style="line-height:1.75;"><?= $body ?></div>
<p style="color:#6b6560;font-size:14px;margin-top:2rem;">
  С признателност,<br>Екипът на <?= defined('SITE_NAME_BG') ? htmlspecialchars(SITE_NAME_BG, ENT_QUOTES, 'UTF-8') : '' ?><br>
  <a href="mailto:<?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?>"><?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?></a>
</p>

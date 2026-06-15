<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
$page_title       = 'Legal Information';
$page_description = 'Legal information of ' . SITE_NAME_EN . '.';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container">
    <span class="section-label">Legal</span>
    <h1>Legal Information</h1>
    <p style="margin-top:0.5rem;color:var(--text-muted);font-size:0.9rem;">Last updated: 5 October 2025</p>
  </div>
</section>

<section class="section">
  <div class="container container--narrow">

    <h2>1. Publisher information</h2>
    <p>
      <?= h(SITE_NAME_EN) ?><br>
      Address: ul. Varovita 12, Lozen<br>
      Phone: +359896670346<br>
      Email: <a href="mailto:<?= h(SITE_EMAIL) ?>"><?= h(SITE_EMAIL) ?></a><br>
      Representative: Detelina Boyanova Vasileva, Director<br>
      IBAN: BG40STSA93000032062526, BIC: STSABGSF, Bank: DSK
    </p>

    <h2>2. Purpose and activities</h2>
    <p><?= h(SITE_NAME_EN) ?> is a non-profit legal entity operating in the public interest in accordance with the Non-Profit Legal Entities Act of the Republic of Bulgaria.</p>

    <h2>3. Copyright</h2>
    <p>All content is the property of the Foundation. Personal non-commercial use is permitted with retention of copyright notices. Copying for commercial purposes without written permission is prohibited.</p>

    <h2>4. Links to external sites</h2>
    <p>We are not responsible for the content of external sites.</p>

    <h2>5. Disclaimer</h2>
    <p>Information is provided "as is". We are not responsible for interruptions, technical errors or data loss.</p>

    <h2>6. Website use</h2>
    <p>You undertake to comply with applicable law and not to engage in hacking, spam or copyright infringement.</p>

    <h2>7. Donations</h2>
    <p>Donations may be used for tax relief pursuant to applicable Bulgarian tax law. The Foundation issues the necessary documents. Donations are non-refundable except in cases of technical error.</p>

    <h2>8. Data protection</h2>
    <p>Governed by the <a href="/en/privacy-policy/">Privacy Policy</a>.</p>

    <h2>9. Changes</h2>
    <p>The Foundation reserves the right to change this information at any time.</p>

    <h2>10. Applicable law</h2>
    <p>Legislation of the Republic of Bulgaria. Disputes are resolved by the competent Bulgarian courts.</p>

    <h2>11. Contacts</h2>
    <p>
      Email: <a href="mailto:<?= h(SITE_EMAIL) ?>"><?= h(SITE_EMAIL) ?></a><br>
      Phone: +359896670346
    </p>

  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
$page_title       = 'Terms of Use';
$page_description = 'Terms of use for the ' . SITE_NAME_EN . ' online shop.';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container">
    <span class="section-label">Legal</span>
    <h1>Terms of Use</h1>
  </div>
</section>

<section class="section">
  <div class="container container--narrow">

    <h2>I. General provisions</h2>
    <p>These Terms govern the relationship between <?= h(SITE_NAME_EN) ?> and any natural person who uses the online shop at <?= h(SITE_URL) ?>/. By using the site, the User declares that they accept these Terms.</p>

    <h2>II. Subject of activity</h2>
    <p>The online shop offers paper napkins with creative and communication content. Net revenues fund the Foundation's projects. Purchasing a product does not constitute a donation unless explicitly stated otherwise.</p>

    <h2>III. Territorial scope</h2>
    <p>Orders with delivery within the Republic of Bulgaria only.</p>

    <h2>IV. Orders</h2>
    <p>Orders can be placed without registration. The User is obliged to provide accurate data. The Foundation may refuse an order in case of incomplete data, suspicion of abuse or technical impossibility.</p>

    <h2>V. Prices and payment methods</h2>
    <p>All prices are in euros (EUR), excluding VAT. Payment by debit/credit card via V-POS. Visa and Mastercard cards are accepted. Transactions are secured through MasterCard Identity Check and VISA Secure. We do not store bank card data. In case of return, the amount will be refunded to the card used for payment.</p>

    <h2>VI. Delivery</h2>
    <p>Via courier service to the User's address. Timeframes are indicative. Delivery costs are at the User's expense.</p>

    <h2>VII. Right of withdrawal (returns)</h2>
    <p>14-day period from receipt of the product in accordance with consumer protection legislation. Notification to <a href="mailto:<?= h(SITE_EMAIL) ?>"><?= h(SITE_EMAIL) ?></a> with order number, name and preferred refund method. The product must be returned in original packaging without signs of use. Return costs are at the User's expense. The Foundation will refund the amount in EUR within 14 days.</p>

    <h2>VIII. Complaints</h2>
    <p>Within 2 years of receipt. To <a href="mailto:<?= h(SITE_EMAIL) ?>"><?= h(SITE_EMAIL) ?></a> with order number and description. Response within 30 days.</p>

    <h2>IX. Limitation of liability</h2>
    <p>We are not responsible for access interruptions or the subjective interpretation of content.</p>

    <h2>X. Intellectual property</h2>
    <p>All texts, designs and graphics are the property of the Foundation.</p>

    <h2>XI. Personal data protection</h2>
    <p>In accordance with GDPR and applicable Bulgarian legislation. See <a href="/en/privacy-policy/">Privacy Policy</a>.</p>

    <h2>XII. Applicable law</h2>
    <p>Legislation of the Republic of Bulgaria.</p>

    <h2>XIII. Contacts</h2>
    <p>
      <?= h(SITE_NAME_EN) ?><br>
      Lozen village, ul. Varovita 12<br>
      <a href="mailto:<?= h(SITE_EMAIL) ?>"><?= h(SITE_EMAIL) ?></a>
    </p>

  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>

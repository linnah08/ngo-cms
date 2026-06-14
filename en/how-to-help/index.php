<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
$pages            = load_json(CONTENT_PATH . '/pages.json');
$how              = $pages['how_to_help'] ?? [];
$ways             = $how['ways'] ?? [];
$page_title       = 'How to Help';
$page_description = 'Find out how you can support Odd Minds Foundation.';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container">
    <span class="section-label">How to help</span>
    <h1><?= h($how['title_en'] ?? 'How can I help?') ?></h1>
    <p class="lead" style="margin-top:1rem;"><?= h($how['intro_en'] ?? "Everyone can get involved! Here's how:") ?></p>
  </div>
</section>

<section class="section">
  <div class="container">
    <?php
    $_way_ctas = [
        'Become a volunteer' => [
            'label' => 'Get in touch',
            'href'  => '/en/contacts/?topic=' . urlencode('I want to volunteer'),
            'style' => 'primary',
        ],
        'Become a partner' => [
            'label' => 'Get in touch',
            'href'  => '/en/contacts/?topic=' . urlencode('I want to become a partner'),
            'style' => 'primary',
        ],
        'Become a donor' => [
            'label' => 'Donate now',
            'href'  => '/donation/checkout.php',
            'style' => 'primary',
        ],
        'Follow us on social media' => ['social' => true],
        'Support Lafetki' => [
            'label' => 'Learn more',
            'href'  => '/en/campaign/',
            'style' => 'outline',
        ],
    ];
    ?>
    <div class="how-to-help--list-wrapper" style="display:flex;flex-direction:column;gap:0;">
      <?php foreach ($ways as $i => $way): ?>
        <div style="padding:2.5rem 0;<?= $i > 0 ? 'border-top:1px solid var(--border);' : '' ?>display:grid;grid-template-columns:200px 1fr;gap:2rem;align-items:start;">
          <h3 style="color:var(--teal);margin:0;"><?= h($way['title_en'] ?? $way['title']) ?></h3>
          <div>
            <?= $way['text_en'] ?? $way['text'] ?>
            <?php $_cta = $_way_ctas[$way['title_en'] ?? $way['title']] ?? null; ?>
            <?php if ($_cta): ?>
              <?php if (!empty($_cta['social'])): ?>
                <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-top:1rem;">
                  <?php $social_variant = 'primary'; require $_SERVER['DOCUMENT_ROOT'] . '/templates/social-links.php'; ?>
                </div>
              <?php else: ?>
                <div style="margin-top:1.25rem;">
                  <a href="<?= h($_cta['href']) ?>" class="btn btn--<?= h($_cta['style']) ?>"
                     aria-label="<?= h($_cta['label']) ?> — <?= h($way['title_en'] ?? $way['title']) ?>"><?= h($_cta['label']) ?></a>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:3rem;padding:2.5rem;background:var(--off-white);border-radius:8px;">
      <h3 style="margin-bottom:1.5rem;">Get in touch</h3>
      <div style="display:flex;gap:3rem;flex-wrap:wrap;">
        <div>
          <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:0.35rem;">Phone</div>
          <a href="tel:<?= SITE_PHONE ?>"><?= SITE_PHONE ?></a>
        </div>
        <div>
          <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:0.35rem;">Email</div>
          <a href="mailto:<?= SITE_EMAIL ?>"><?= SITE_EMAIL ?></a>
        </div>
        <div>
          <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:0.35rem;">Donations IBAN</div>
          <span style="font-family:monospace;"><?= SITE_IBAN ?></span>
        </div>
      </div>
    </div>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
$pages = load_json(CONTENT_PATH . '/pages.json');
$help = $pages['how_to_help'] ?? [];
$page_title = 'Как да помогна';
$page_description = 'Научете как можете да подкрепите ' . SITE_NAME_BG . '.';
$page_head_extra = '<style>@media(max-width:640px){.how-to-help--list-wrapper>div{grid-template-columns:1fr!important;gap:.75rem!important;}}</style>';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container">
    <span class="section-label">Как да помогна</span>
    <h1><?= h($help['title'] ?? 'Как да помогна?') ?></h1>
    <?php if (!empty($help['intro'])): ?>
      <p class="lead" style="margin-top:1rem;"><?= h($help['intro']) ?></p>
    <?php endif; ?>
  </div>
</section>

<section class="section">
  <div class="container">
    <?php
    $_way_ctas = [
        'Стани доброволец' => [
            'label' => 'Свържи се с нас',
            'href'  => '/kontakti/?topic=' . urlencode('Искам да стана доброволец'),
            'style' => 'primary',
        ],
        'Стани партньор' => [
            'label' => 'Свържи се с нас',
            'href'  => '/kontakti/?topic=' . urlencode('Искам да стана партньор'),
            'style' => 'primary',
        ],
        'Стани дарител' => [
            'label' => 'Дари сега',
            'href'  => '/donation/checkout.php',
            'style' => 'primary',
        ],
        'Стани наш приятел в социалните мрежи' => ['social' => true],
        'Подкрепи кампанията' => [
            'label' => 'Научи повече',
            'href'  => '/campaign/',
            'style' => 'outline',
        ],
    ];
    ?>
    <div class="how-to-help--list-wrapper" style="display:flex;flex-direction:column;gap:0;">
      <?php foreach (($help['ways'] ?? []) as $i => $way): ?>
        <div style="padding:2.5rem 0;<?= $i > 0 ? 'border-top:1px solid var(--border);' : '' ?>display:grid;grid-template-columns:200px 1fr;gap:2rem;align-items:start;">
          <h3 style="color:var(--teal);margin:0;"><?= h($way['title']) ?></h3>
          <div>
            <?= $way['text'] ?>
            <?php $_cta = $_way_ctas[$way['title']] ?? null; ?>
            <?php if ($_cta): ?>
              <?php if (!empty($_cta['social'])): ?>
                <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-top:1rem;">
                  <?php $social_variant = 'primary'; require $_SERVER['DOCUMENT_ROOT'] . '/templates/social-links.php'; ?>
                </div>
              <?php else: ?>
                <div style="margin-top:1.25rem;">
                  <a href="<?= h($_cta['href']) ?>" class="btn btn--<?= h($_cta['style']) ?>"
                     aria-label="<?= h($_cta['label']) ?> — <?= h($way['title']) ?>"><?= h($_cta['label']) ?></a>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:3rem;padding:2.5rem;background:var(--off-white);border-radius:8px;">
      <h3 style="margin-bottom:1.5rem;">Свържете се с нас</h3>
      <div style="display:flex;gap:3rem;flex-wrap:wrap;">
        <div>
          <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:0.35rem;">Телефон</div>
          <a href="tel:<?= SITE_PHONE ?>"><?= SITE_PHONE ?></a>
        </div>
        <div>
          <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:0.35rem;">Имейл</div>
          <a href="mailto:<?= SITE_EMAIL ?>"><?= SITE_EMAIL ?></a>
        </div>
        <div>
          <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:0.35rem;">Дарения IBAN</div>
          <span style="font-family:monospace;"><?= SITE_IBAN ?></span>
        </div>
      </div>
    </div>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>

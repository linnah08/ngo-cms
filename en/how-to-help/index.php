<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/donation.php';
$pages            = load_json(CONTENT_PATH . '/pages.json');
$how              = $pages['how_to_help'] ?? [];
$ways             = $how['ways'] ?? [];
$page_title       = 'How to Help';
$page_description = t_or('help.page_description', 'Научете как можете да подкрепите {name}.', 'Find out how you can support {name}.', null, ['name' => SITE_NAME_EN]);
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey page-hero" style="padding-top:3rem;padding-bottom:1.5rem;">
  <div class="container">
    <h1 style="margin:0;" data-cms-section="how_to_help" data-cms-field="title" data-cms-type="text"<?= $_show_admin_bar ? ' data-cms-bg="' . h($how['title'] ?? '') . '" data-cms-en="' . h($how['title_en'] ?? '') . '"' : '' ?>><?= h(($how['title_en'] ?? '') !== '' ? $how['title_en'] : 'How can I help?') ?></h1>
    <p class="lead" style="margin-top:1rem;" data-cms-section="how_to_help" data-cms-field="intro" data-cms-type="text"<?= $_show_admin_bar ? ' data-cms-bg="' . h($how['intro'] ?? '') . '" data-cms-en="' . h($how['intro_en'] ?? '') . '"' : '' ?>><?= h(($how['intro_en'] ?? '') !== '' ? $how['intro_en'] : "Everyone can get involved! Here's how:") ?></p>
  </div>
</section>

<section class="section">
  <div class="container">
    <?php
    $_way_ctas = [
        'Become a volunteer' => [
            'label' => 'contact',
            'href'  => '/en/contacts/?topic=' . urlencode('I want to volunteer'),
            'style' => 'primary',
        ],
        'Become a partner' => [
            'label' => 'contact',
            'href'  => '/en/contacts/?topic=' . urlencode('I want to become a partner'),
            'style' => 'primary',
        ],
        'Become a donor' => [
            'label' => 'donate',
            'href'  => donation_path('form', 'en'),
            'style' => 'primary',
        ],
        'Follow us on social media' => ['social' => true],
        'Support our campaign' => [
            'label' => 'campaign',
            'href'  => '/en/campaign/',
            'style' => 'outline',
        ],
    ];
    if (!feature_enabled('donations')) unset($_way_ctas['Become a donor']);
    // See the note in kak-da-pomogna/index.php.
    if (!feature_enabled('campaign')) {
        unset($_way_ctas['Support our campaign']);
    }
    ?>
    <div class="how-to-help--list-wrapper" style="display:flex;flex-direction:column;gap:0;">
      <?php foreach ($ways as $i => $way): ?>
        <div style="padding:2.5rem 0;<?= $i > 0 ? 'border-top:1px solid var(--border);' : '' ?>display:grid;grid-template-columns:200px 1fr;gap:2rem;align-items:start;">
          <h3 style="color:var(--teal);margin:0;"<?= cms_admin(['Съдържание → Как да помогна', '/admin/pages.php?page=how_to_help']) ?>><?= h($way['title_en'] ?? $way['title']) ?></h3>
          <div class="rich-text">
            <div<?= cms_admin(['Съдържание → Как да помогна', '/admin/pages.php?page=how_to_help']) ?>><?= $way['text_en'] ?? $way['text'] ?></div>
            <?php $_cta = $_way_ctas[$way['title_en'] ?? $way['title']] ?? null; ?>
            <?php if ($_cta): ?>
              <?php if (!empty($_cta['social'])): ?>
                <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-top:1rem;">
                  <?php $social_variant = 'primary'; require $_SERVER['DOCUMENT_ROOT'] . '/templates/social-links.php'; ?>
                </div>
              <?php else: ?>
                <?php $_cta_html = match ($_cta['label']) {
                    'contact' => te('help.cta.contact', 'Свържи се с нас', 'Get in touch'),
                    'donate'  => te('help.cta.donate', 'Дари сега', 'Donate now'),
                    default   => te('help.cta.campaign', 'Научи повече', 'Learn more'),
                }; ?>
                <div style="margin-top:1.25rem;">
                  <a href="<?= h($_cta['href']) ?>" class="btn btn--<?= h($_cta['style']) ?>"
                     aria-label="<?= h(html_entity_decode(strip_tags($_cta_html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?> — <?= h($way['title_en'] ?? $way['title']) ?>"><?= $_cta_html ?></a>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:3rem;padding:2.5rem;background:var(--off-white);border-radius:8px;">
      <h3 style="margin-bottom:1.5rem;"><?= te('help.contact.title', 'Свържете се с нас', 'Get in touch') ?></h3>
      <div style="display:flex;gap:3rem;flex-wrap:wrap;">
        <?php if (site_phone_public() !== ''): ?>
        <div>
          <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:0.35rem;"><?= te('help.contact.phone', 'Телефон', 'Phone') ?></div>
          <a href="tel:<?= h(site_phone_public()) ?>"<?= cms_admin('organisation') ?>><?= h(site_phone_public()) ?></a>
        </div>
        <?php endif; ?>
        <div>
          <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:0.35rem;"><?= te('help.contact.email', 'Имейл', 'Email') ?></div>
          <a href="mailto:<?= SITE_EMAIL ?>"<?= cms_admin('organisation') ?>><?= SITE_EMAIL ?></a>
        </div>
        <div>
          <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:0.35rem;"><?= te('help.contact.iban', 'Дарения IBAN', 'Donations IBAN') ?></div>
          <span style="font-family:monospace;"<?= cms_admin('organisation') ?>><?= h(SITE_IBAN) ?></span>
        </div>
      </div>
    </div>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>

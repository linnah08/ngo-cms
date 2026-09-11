<?php
// templates/lang-switcher.php
// Expects: $lang, $bg_href, $en_href in scope.
// Optional: $switcher_class to append extra classes (e.g. for the mobile variant).
?>
<div class="lang-switcher<?= isset($switcher_class) ? ' ' . $switcher_class : '' ?>" role="group" aria-label="<?= $lang === 'bg' ? 'Избор на език' : 'Language' ?>">
  <a href="<?= h($bg_href) ?>"
     class="lang-switcher__flag<?= $lang === 'bg' ? ' active' : '' ?>"
     lang="bg" hreflang="bg"
     aria-label="Български"<?= $lang === 'bg' ? ' aria-current="true"' : '' ?>>
    <img src="/assets/images/flags/bg.svg" alt="" width="26" height="16">
  </a>
  <a href="<?= h($en_href) ?>"
     class="lang-switcher__flag<?= $lang === 'en' ? ' active' : '' ?>"
     lang="en" hreflang="en"
     aria-label="English"<?= $lang === 'en' ? ' aria-current="true"' : '' ?>>
    <img src="/assets/images/flags/gb.svg" alt="" width="26" height="16">
  </a>
</div>

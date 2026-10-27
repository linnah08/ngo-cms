<?php
$page_title_admin = 'Организация';
$active_nav       = 'organisation';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';
admin_require_admin();

$themes    = brand_themes();
$theme_bg  = ['classic' => 'Класически', 'friendly' => 'Приветлив', 'modern' => 'Модерен', 'editorial' => 'Списание'];
$errors    = [];   // field => message (plus '_form' for errors not tied to a field)
$form      = null; // re-filled values after a failed save

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // PHP drops the whole request body when it exceeds post_max_size.
        $errors['_form'] = 'Качената картинка е твърде голяма и нищо не беше запазено. Максимумът е 2 MB — намалете размера ѝ и опитайте отново.';
    } elseif (!csrf_verify()) {
        $errors['_form'] = 'Страницата беше отворена твърде дълго и сесията изтече. Презаредете страницата и опитайте отново.';
    } else {
        $result = org_validate($_POST, array_keys($themes));
        $form   = $result['values'];
        $errors = $result['errors'];

        $images   = $_SERVER['DOCUMENT_ROOT'] . '/assets/images';
        $has_file = static fn(string $k): bool => is_array($_FILES[$k] ?? null)
            && ($_FILES[$k]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $has_logo  = $has_file('logo');
        $has_share = $has_file('share_image');

        if (!$errors && $has_logo) {
            $logo_err = org_save_logo($_FILES['logo'], $images);
            if ($logo_err !== null) $errors['logo'] = $logo_err;
        }
        if (!$errors && $has_share) {
            $share_err = org_save_share_image($_FILES['share_image'], $images);
            if ($share_err !== null) $errors['share_image'] = $share_err;
        } elseif (!$errors && ($_POST['share_image_remove'] ?? '') === '1') {
            @unlink($images . '/share-upload.jpg');
        }

        if (!$errors) {
            if (org_save_overrides($form)) {
                // The automatic share picture follows the logo and the main colour.
                org_make_share_image($images . '/logo.png', $images . '/share.png', $form['brand_primary'] ?? '');
                flash_set('success', $has_logo || $has_share
                    ? 'Промените и новата картинка бяха запазени. Ако на сайта още виждате старата, презаредете страницата.'
                    : 'Промените бяха запазени и вече се виждат на сайта.');
                header('Location: /admin/organisation.php');
                exit;
            }
            $errors['_form'] = 'Промените не можаха да се запишат на сървъра. Опитайте отново или се свържете с поддръжката.';
        } else {
            if ($has_logo && !isset($errors['logo'])) {
                $errors['logo'] = 'Логото не беше качено, защото има грешки в другите полета. След като ги поправите, изберете файла отново.';
            }
            if ($has_share && !isset($errors['share_image'])) {
                $errors['share_image'] = 'Картинката за споделяне не беше качена, защото има грешки в другите полета. След като ги поправите, изберете файла отново.';
            }
        }
    }
}

// Current (effective) values — the saved override, else site.config.php.
$const = static fn(string $c): string => defined($c) ? (string) constant($c) : '';
$current = [];
foreach (org_fields() as $field => $c) $current[$field] = $const($c);
if (!isset($themes[$current['brand_theme']])) $current['brand_theme'] = 'classic';
$active_theme = $themes[$current['brand_theme']];
if (!org_color_valid($current['brand_primary'])) $current['brand_primary'] = $active_theme['primary'];
if (!org_color_valid($current['brand_accent']))  $current['brand_accent']  = $active_theme['accent'];
$nl_choices = newsletter_band_choices();

$val = static fn(string $k): string => (string) (($form ?? $current)[$k] ?? '');

$field_errors = array_diff_key($errors, ['_form' => 1]);
$flashes      = flash_get();

$box_ok  = 'padding:.9rem 1.25rem;border-radius:8px;font-size:.9rem;margin-bottom:1rem;background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;';
$box_err = 'padding:.9rem 1.25rem;border-radius:8px;font-size:.9rem;margin-bottom:1rem;background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;';
$err_css = 'display:block;margin-top:.3rem;color:#c0392b;font-size:.85rem;font-weight:600;line-height:1.4;text-transform:none;letter-spacing:normal;';
$hint    = 'display:block;margin:.25rem 0 0;font-size:.8rem;font-weight:400;line-height:1.5;color:#6b7280;text-transform:none;letter-spacing:normal;';
$bad_css = 'border-color:#c0392b;';
$card    = 'margin-bottom:1.5rem;';

/** Inline error under a field, if any. */
$ferr = static function (string $k) use ($errors, $err_css): string {
    return isset($errors[$k])
        ? '<span id="err-' . h($k) . '" role="alert" style="' . $err_css . '">' . h($errors[$k]) . '</span>'
        : '';
};
/** aria/style attributes for a field that has an error. */
$fattr = static function (string $k) use ($errors, $bad_css): string {
    return isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="err-' . h($k) . '" style="' . $bad_css . '"' : '';
};

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<?php foreach ($flashes as $f): ?>
  <div class="admin-alert admin-alert--<?= ($f['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="status"
       style="<?= ($f['type'] ?? '') === 'success' ? $box_ok : $box_err ?>"><?= h((string) ($f['message'] ?? '')) ?></div>
<?php endforeach; ?>

<?php if ($errors): ?>
  <div class="admin-alert admin-alert--error" role="alert" style="<?= $box_err ?>">
    <strong>Промените не бяха запазени.</strong>
    <?php if (isset($errors['_form'])): ?>
      <?= h($errors['_form']) ?>
    <?php else: ?>
      Поправете отбелязаните в червено полета по-долу и натиснете „Запази“ отново:
      <ul style="margin:.4rem 0 0 1.1rem;padding:0;">
        <?php foreach ($field_errors as $k => $msg): ?>
          <li><a href="#f-<?= h($k) ?>" style="color:inherit;"><?= h($msg) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
<?php endif; ?>

<p class="admin-meta" style="margin:0 0 1.5rem;line-height:1.6;max-width:60rem;">
  Тук можете да промените данните на организацията, които се виждат на сайта, в имейлите и в документите
  (фактури, сертификати за дарение). Промените важат веднага. Вече издадените документи не се променят.
</p>

<form method="post" enctype="multipart/form-data" novalidate id="orgForm">
  <?= csrf_field() ?>

  <!-- ── Name ──────────────────────────────────────────────────────────────── -->
  <section class="admin-card" style="<?= $card ?>">
    <h2 class="admin-card__title">Име на организацията</h2>
    <div class="admin-form-grid">
      <label id="f-site_name_bg">На български
        <input type="text" name="site_name_bg" value="<?= h($val('site_name_bg')) ?>" maxlength="150" required<?= $fattr('site_name_bg') ?>>
        <?= $ferr('site_name_bg') ?>
      </label>
      <label id="f-site_name_en">На английски
        <input type="text" name="site_name_en" data-translate-from="site_name_bg" value="<?= h($val('site_name_en')) ?>" maxlength="150" required<?= $fattr('site_name_en') ?>>
        <?= $ferr('site_name_en') ?>
      </label>
    </div>

    <h3 style="margin:1.5rem 0 .25rem;font-size:.95rem;">Юридическо име</h3>
    <p style="<?= $hint ?>margin:0 0 .75rem;">
      Официалното име от регистрацията, например „Фондация Пример“ / „Example Foundation“.
      С това име се издават сертификатите за дарение, фактурите и разписките — попълнете го, ако сайтът е марка или проект на фондация или сдружение.
      Автоматичният превод винаги превежда това име и името на организацията по-горе точно така, както са написани тук.
      Не е задължително — оставете двете полета празни, ако юридическото име е същото като името на организацията.
    </p>
    <div class="admin-form-grid">
      <label id="f-site_legal_name_bg">Юридическо име на български
        <input type="text" name="site_legal_name_bg" value="<?= h($val('site_legal_name_bg')) ?>" maxlength="150"<?= $fattr('site_legal_name_bg') ?>>
        <?= $ferr('site_legal_name_bg') ?>
      </label>
      <label id="f-site_legal_name_en">Юридическо име на английски
        <input type="text" name="site_legal_name_en" data-translate-from="site_legal_name_bg" value="<?= h($val('site_legal_name_en')) ?>" maxlength="150"<?= $fattr('site_legal_name_en') ?>>
        <?= $ferr('site_legal_name_en') ?>
      </label>
    </div>
    <label class="admin-checkbox" style="margin-top:.75rem;">
      <input type="checkbox" name="site_legal_same" value="1" <?= $val('site_legal_same') === '1' ? 'checked' : '' ?>>
      Организацията е регистрирана с името по-горе — няма отделно юридическо име
    </label>

    <h3 style="margin:1.5rem 0 .25rem;font-size:.95rem;">Регистрация</h3>
    <p style="<?= $hint ?>margin:0 0 .75rem;">
      От документите за регистрация на организацията (Търговски регистър или БУЛСТАТ). Законът изисква сайтът да посочва кой стои зад него, а фактурите — ЕИК и адрес.
    </p>
    <div class="admin-form-grid">
      <label id="f-site_eik">ЕИК / БУЛСТАТ
        <input type="text" name="site_eik" value="<?= h($val('site_eik')) ?>" maxlength="20" inputmode="numeric" autocomplete="off" spellcheck="false" placeholder="123456789"<?= $fattr('site_eik') ?>>
        <?= $ferr('site_eik') ?>
      </label>
      <label id="f-site_mol">Представляващ (МОЛ)
        <input type="text" name="site_mol" value="<?= h($val('site_mol')) ?>" maxlength="150" placeholder="Иван Иванов"<?= $fattr('site_mol') ?>>
        <?= $ferr('site_mol') ?>
      </label>
      <label id="f-site_address" style="grid-column:1/-1;">Адрес на управление
        <input type="text" name="site_address" value="<?= h($val('site_address')) ?>" maxlength="250" placeholder="гр. София 1000, ул. Примерна 1"<?= $fattr('site_address') ?>>
        <?= $ferr('site_address') ?>
      </label>
    </div>
  </section>

  <!-- ── Contacts ──────────────────────────────────────────────────────────── -->
  <section class="admin-card" style="<?= $card ?>">
    <h2 class="admin-card__title">Контакти</h2>
    <div class="admin-form-grid">
      <label id="f-site_email">Имейл за контакт
        <input type="email" name="site_email" value="<?= h($val('site_email')) ?>" maxlength="255" required spellcheck="false"<?= $fattr('site_email') ?>>
        <?= $ferr('site_email') ?>
        <small style="<?= $hint ?>">Показва се на сайта и в имейлите до дарители и клиенти.</small>
      </label>
      <label id="f-site_phone">Телефон за контакт
        <input type="tel" name="site_phone" value="<?= h($val('site_phone')) ?>" maxlength="30" placeholder="+359 88 123 4567"<?= $fattr('site_phone') ?>>
        <?= $ferr('site_phone') ?>
        <small style="<?= $hint ?>">Оставете празно, ако не искате да се показва телефон.</small>
      </label>
    </div>
  </section>

  <!-- ── Bank ──────────────────────────────────────────────────────────────── -->
  <section class="admin-card" style="<?= $card ?>">
    <h2 class="admin-card__title">Банкова сметка</h2>
    <p class="admin-meta" style="margin:0 0 1rem;line-height:1.6;">
      Показва се в „Контакти“ и във фактурите<?= module_enabled_with_needs('donations') ? ', и на дарителите' : '' ?>. Проверяваме IBAN-а автоматично, така че сгрешена цифра няма да мине.
      Оставете полетата празни, ако не искате да показвате сметка.
    </p>
    <div class="admin-form-grid">
      <label id="f-site_iban">IBAN
        <input type="text" name="site_iban" value="<?= h($val('site_iban')) ?>" maxlength="42" placeholder="BG80 BNBG 9661 1020 3456 78"
               autocomplete="off" spellcheck="false" style="font-family:monospace;<?= isset($errors['site_iban']) ? $bad_css : '' ?>"
               <?= isset($errors['site_iban']) ? 'aria-invalid="true" aria-describedby="err-site_iban"' : '' ?>>
        <?= $ferr('site_iban') ?>
      </label>
      <label id="f-site_bic">BIC
        <input type="text" name="site_bic" value="<?= h($val('site_bic')) ?>" maxlength="11" placeholder="STSABGSF"
               autocomplete="off" spellcheck="false" style="font-family:monospace;<?= isset($errors['site_bic']) ? $bad_css : '' ?>"
               <?= isset($errors['site_bic']) ? 'aria-invalid="true" aria-describedby="err-site_bic"' : '' ?>>
        <?= $ferr('site_bic') ?>
      </label>
      <label id="f-site_bank_name">Име на банката
        <input type="text" name="site_bank_name" value="<?= h($val('site_bank_name')) ?>" maxlength="150"<?= $fattr('site_bank_name') ?>>
        <?= $ferr('site_bank_name') ?>
      </label>
    </div>
  </section>

  <?php if (module_enabled_with_needs('shop') || module_enabled_with_needs('donations')): ?>
  <!-- ── Order numbers ─────────────────────────────────────────────────────── -->
  <section class="admin-card" style="<?= $card ?>">
    <h2 class="admin-card__title">Номера на поръчките</h2>
    <p class="admin-meta" style="margin:0 0 1rem;line-height:1.6;">
      С тези букви започва номерът на всяка нова поръчка и дарение, например <strong><?= h(order_number_prefix()) ?>-<?= date('Ymd') ?>-1A2B</strong>.
      Изберете букви, по които да познаете вашия сайт, ако получавате поръчки и от друг сайт. Вече направените поръчки запазват номерата си.
    </p>
    <div class="admin-form-grid">
      <label id="f-order_prefix">Начални букви
        <input type="text" name="order_prefix" value="<?= h($val('order_prefix')) ?>" maxlength="5" placeholder="<?= h(ORDER_PREFIX_DEFAULT) ?>"
               autocomplete="off" spellcheck="false" aria-describedby="hint-order_prefix<?= isset($errors['order_prefix']) ? ' err-order_prefix' : '' ?>"
               style="font-family:monospace;text-transform:uppercase;<?= isset($errors['order_prefix']) ? $bad_css : '' ?>"<?= isset($errors['order_prefix']) ? ' aria-invalid="true"' : '' ?>>
        <span id="hint-order_prefix" style="<?= $hint ?>">От 2 до 5 латински букви. Ако оставите полето празно: <?= h(ORDER_PREFIX_DEFAULT) ?>.</span>
        <?= $ferr('order_prefix') ?>
      </label>
    </div>
  </section>
  <?php endif; ?>

  <!-- ── Brand ─────────────────────────────────────────────────────────────── -->
  <section class="admin-card" style="<?= $card ?>">
    <h2 class="admin-card__title">Визия</h2>

    <fieldset id="f-brand_theme" style="border:none;margin:0 0 1rem;padding:0;min-width:0;">
      <legend style="font-weight:600;margin-bottom:.5rem;padding:0;">Стил</legend>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.6rem;">
        <?php foreach ($themes as $tk => $tv): $checked = $val('brand_theme') === $tk; ?>
          <label style="display:flex;align-items:center;gap:.5rem;padding:.6rem .75rem;border:2px solid <?= $checked ? 'var(--teal,#0387A5)' : 'var(--border,#ddd)' ?>;border-radius:8px;cursor:pointer;margin:0;font-weight:500;">
            <input type="radio" name="brand_theme" value="<?= h($tk) ?>" <?= $checked ? 'checked' : '' ?>
                   data-primary="<?= h($tv['primary']) ?>" data-accent="<?= h($tv['accent']) ?>"
                   data-nl-bg="<?= h(theme_newsletter_default($tv)['bg']) ?>" data-nl-fg="<?= h(theme_newsletter_default($tv)['fg']) ?>"
                   style="margin:0;flex-shrink:0;">
            <span style="width:18px;height:18px;border-radius:50%;flex-shrink:0;background:<?= h($tv['primary']) ?>;"></span>
            <span><?= h($theme_bg[$tk] ?? $tv['label']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <?= $ferr('brand_theme') ?>
      <small style="<?= $hint ?>">Стилът определя шрифта и формата на бутоните. При смяна на стила цветовете се попълват според него — можете да ги промените.</small>
    </fieldset>

    <div class="admin-form-grid">
      <label id="f-brand_primary">Основен цвят
        <input type="color" name="brand_primary" id="brandPrimary" value="<?= h($val('brand_primary')) ?>"
               style="height:44px;padding:3px;width:100%;box-sizing:border-box;cursor:pointer;">
        <?= $ferr('brand_primary') ?>
      </label>
      <label id="f-brand_accent">Допълнителен цвят
        <input type="color" name="brand_accent" id="brandAccent" value="<?= h($val('brand_accent')) ?>"
               style="height:44px;padding:3px;width:100%;box-sizing:border-box;cursor:pointer;">
        <?= $ferr('brand_accent') ?>
      </label>
    </div>

    <?php
    // Newsletter band: the coloured strip above the footer on every public page.
    // The admin picks only the background; the text colour follows for contrast.
    $nl_choice = $val('newsletter_band');
    if (!isset($nl_choices[$nl_choice])) $nl_choice = 'default';
    $nl_custom = org_color_valid($val('newsletter_band_color')) ? $val('newsletter_band_color') : $val('brand_primary');
    $nl_now    = newsletter_band_colors($nl_choice, $nl_custom, $val('brand_primary'), $val('brand_accent'))
              ?? theme_newsletter_default($themes[$val('brand_theme')] ?? $active_theme);
    $nl_read   = 'Текстът се чете добре.';
    $nl_hard   = 'Текстът се чете трудно — изберете по-тъмен или по-светъл цвят.';
    ?>
    <?php if (module_enabled_with_needs('newsletter')): ?>
    <fieldset id="f-newsletter_band" style="border:none;margin:1.5rem 0 0;padding:0;min-width:0;">
      <legend style="font-weight:600;margin-bottom:.25rem;padding:0;">Цвят на лентата за бюлетина</legend>
      <small id="nlHint" style="<?= $hint ?>margin:0 0 .6rem;">Цветната лента над долната част на всяка страница, в която посетителите се записват за бюлетина. Цветът на текста се избира сам, така че да се чете.</small>
      <div style="display:flex;flex-wrap:wrap;gap:.5rem 1.25rem;">
        <?php foreach ($nl_choices as $ck => $clabel): ?>
          <label style="display:flex;align-items:center;gap:.5rem;min-height:44px;margin:0;font-weight:500;cursor:pointer;">
            <input type="radio" name="newsletter_band" value="<?= h($ck) ?>" <?= $nl_choice === $ck ? 'checked' : '' ?>
                   aria-describedby="nlHint" style="margin:0;width:1.1rem;height:1.1rem;">
            <span><?= h($clabel) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <?= $ferr('newsletter_band') ?>
      <label id="f-newsletter_band_color" for="nlColor" style="display:<?= $nl_choice === 'custom' ? 'block' : 'none' ?>;margin-top:.75rem;max-width:20rem;">Цвят на лентата
        <input type="color" name="newsletter_band_color" id="nlColor" value="<?= h($nl_custom) ?>"
               style="height:44px;padding:3px;width:100%;box-sizing:border-box;cursor:pointer;"<?= $fattr('newsletter_band_color') ?>>
        <?= $ferr('newsletter_band_color') ?>
      </label>

      <div style="margin-top:1rem;">
        <div style="font-size:.85rem;font-weight:600;margin-bottom:.4rem;">Как ще изглежда:</div>
        <div id="nlPreview" aria-hidden="true"
             style="background:<?= h($nl_now['bg']) ?>;color:<?= h($nl_now['fg']) ?>;padding:1.25rem 1rem;border-radius:8px;text-align:center;border:1px solid var(--border,#ddd);">
          <div style="font-weight:700;font-size:1.05rem;margin-bottom:.3rem;"><?= h(newsletter_banner_text('title', 'bg')) ?></div>
          <div style="font-size:.85rem;margin-bottom:.8rem;"><?= h(newsletter_banner_text('text', 'bg')) ?></div>
          <div style="display:flex;gap:.4rem;justify-content:center;flex-wrap:wrap;">
            <span style="display:inline-block;background:#fff;color:#6b7280;border-radius:4px;padding:.4rem .75rem;font-size:.85rem;min-width:10rem;text-align:left;"><?= h(t_or('newsletter.banner.placeholder', 'Вашият имейл адрес', 'Your email address', 'bg')) ?></span>
            <span id="nlPreviewBtn" style="display:inline-block;border-radius:4px;padding:.4rem 1rem;font-size:.85rem;font-weight:700;background:<?= h($nl_now['fg']) ?>;color:<?= h($nl_now['bg']) ?>;"><?= h(t_or('newsletter.banner.submit', 'Запишете се', 'Subscribe', 'bg')) ?></span>
          </div>
        </div>
        <p id="nlReadable" role="status" aria-live="polite" style="margin:.5rem 0 0;font-size:.9rem;font-weight:600;color:<?= $nl_now['readable'] ? '#2d6a35' : '#c0392b' ?>;">
          <span aria-hidden="true"><?= $nl_now['readable'] ? '✓' : '⚠' ?></span>
          <span data-nl-msg><?= h($nl_now['readable'] ? $nl_read : $nl_hard) ?></span>
        </p>
      </div>
    </fieldset>
    <?php endif; ?>

    <div id="f-logo" style="margin-top:1.5rem;">
      <div style="font-weight:600;margin-bottom:.5rem;">Лого</div>
      <div style="display:flex;flex-wrap:wrap;align-items:center;gap:1rem;">
        <div style="padding:.75rem;border:1px solid var(--border,#ddd);border-radius:8px;background:#fff;display:flex;align-items:center;justify-content:center;min-width:120px;max-width:100%;box-sizing:border-box;">
          <img src="<?= h(logo_url()) ?>" alt="Сегашно лого" id="logoPreview" style="display:block;max-height:80px;max-width:240px;width:auto;height:auto;">
        </div>
        <div style="display:flex;align-items:center;gap:.5rem;">
          <img src="<?= h(favicon_url()) ?>" alt="" width="32" height="32" style="display:block;width:32px;height:32px;">
          <span class="admin-meta" style="margin:0;">Иконка в раздела на браузъра</span>
        </div>
      </div>
      <label style="display:block;margin-top:1rem;">Качете ново лого <span style="font-weight:400;text-transform:none;letter-spacing:normal;color:#6b7280;">(PNG, JPG или WebP, до 2 MB)</span>
        <input type="file" name="logo" id="logoInput" accept="image/png,image/jpeg,image/webp"
               style="display:block;margin-top:.4rem;max-width:100%;<?= isset($errors['logo']) ? $bad_css : '' ?>"
               <?= isset($errors['logo']) ? 'aria-invalid="true" aria-describedby="err-logo"' : '' ?>>
        <?= $ferr('logo') ?>
        <small style="<?= $hint ?>">Най-добре изглежда лого с прозрачен фон. Иконката в браузъра се създава автоматично от него. Ако не изберете файл, сегашното лого остава.</small>
      </label>
    </div>
  </section>

  <!-- ── Sharing (link previews) ───────────────────────────────────────────── -->
  <?php
    $share_url    = org_share_image_url();
    $share_custom = org_share_upload_exists();
    $share_host   = (string) (parse_url(SITE_URL, PHP_URL_HOST) ?: '');
  ?>
  <section class="admin-card" style="<?= $card ?>" aria-labelledby="share-title">
    <h2 class="admin-card__title" id="share-title">Когато някой сподели сайта</h2>
    <p class="admin-meta" style="margin:0 0 1rem;line-height:1.6;max-width:60rem;">
      Когато някой изпрати връзка към сайта във Facebook, Viber, Messenger или друго приложение, под нея се показват
      картинка, името на сайта и кратко описание. Описанието се вижда и в Google под името на сайта.
    </p>

    <div style="display:flex;flex-wrap:wrap;gap:1.5rem;align-items:flex-start;">
      <div style="flex:1 1 20rem;min-width:0;">
        <label id="f-site_description_bg" style="display:block;">Кратко описание на български
          <textarea name="site_description_bg" id="siteDescBg" rows="3" maxlength="<?= ORG_DESCRIPTION_MAX ?>"<?= $fattr('site_description_bg') ?>><?= h($val('site_description_bg')) ?></textarea>
          <?= $ferr('site_description_bg') ?>
          <small style="<?= $hint ?>">Едно-две изречения за това какво прави организацията, например „Подкрепяме деца с увреждания и техните семейства.“ До <?= ORG_DESCRIPTION_MAX ?> знака.</small>
        </label>
        <label id="f-site_description_en" style="display:block;margin-top:1rem;">Кратко описание на английски
          <textarea name="site_description_en" id="siteDescEn" rows="3" maxlength="<?= ORG_DESCRIPTION_MAX ?>" data-translate-from="site_description_bg"<?= $fattr('site_description_en') ?>><?= h($val('site_description_en')) ?></textarea>
          <?= $ferr('site_description_en') ?>
        </label>

        <div id="f-share_image" style="margin-top:1.25rem;">
          <label style="display:block;">Качете своя картинка <span style="font-weight:400;text-transform:none;letter-spacing:normal;color:#6b7280;">(по желание; PNG, JPG или WebP, до 2 MB)</span>
            <input type="file" name="share_image" id="shareInput" accept="image/png,image/jpeg,image/webp"
                   style="display:block;margin-top:.4rem;max-width:100%;<?= isset($errors['share_image']) ? $bad_css : '' ?>"
                   <?= isset($errors['share_image']) ? 'aria-invalid="true" aria-describedby="err-share_image"' : '' ?>>
            <?= $ferr('share_image') ?>
            <small style="<?= $hint ?>">Най-добре е картинка в широк формат, 1200 × 630 пиксела. Ако е с друг размер, се изрязва от средата.
              <?= $share_custom ? '' : 'Докато не качите своя, картинката се прави автоматично от логото и основния цвят.' ?></small>
          </label>
          <?php if ($share_custom): ?>
            <label style="display:flex;gap:.5rem;align-items:flex-start;margin-top:.75rem;font-weight:400;text-transform:none;letter-spacing:normal;">
              <input type="checkbox" name="share_image_remove" value="1" style="margin-top:.2rem;width:auto;">
              <span>Махнете качената картинка и използвайте автоматичната от логото</span>
            </label>
          <?php endif; ?>
        </div>
      </div>

      <figure style="flex:0 1 22rem;min-width:0;margin:0;">
        <figcaption style="font-size:.85rem;font-weight:600;margin-bottom:.4rem;">Как ще изглежда:</figcaption>
        <div style="border:1px solid var(--border,#ddd);border-radius:10px;overflow:hidden;background:#fff;max-width:22rem;">
          <img src="<?= h($share_url) ?>" id="sharePreview" alt="Картинка за споделяне" width="1200" height="630"
               style="display:block;width:100%;height:auto;aspect-ratio:1200/630;object-fit:contain;background:#fff;">
          <div style="padding:.6rem .8rem;background:#f0f2f5;">
            <div style="font-size:.75rem;color:#65676b;text-transform:uppercase;letter-spacing:.02em;"><?= h($share_host) ?></div>
            <div style="font-weight:700;color:#1c1e21;line-height:1.3;margin-top:.15rem;"><?= h($val('site_name_bg')) ?></div>
            <div id="shareDescPreview" style="font-size:.85rem;color:#4b4f56;line-height:1.4;margin-top:.15rem;"><?= h($val('site_description_bg') !== '' ? $val('site_description_bg') : $val('site_name_bg')) ?></div>
          </div>
        </div>
        <p class="admin-meta" style="margin:.5rem 0 0;font-size:.8rem;line-height:1.5;">
          Приложенията помнят картинката за известно време — при вече споделяни връзки новата може да се появи след ден-два.
        </p>
      </figure>
    </div>
  </section>

  <!-- ── Social ────────────────────────────────────────────────────────────── -->
  <section class="admin-card" style="<?= $card ?>">
    <h2 class="admin-card__title">Социални мрежи</h2>
    <p class="admin-meta" style="margin:0 0 1rem;">Поставете адреса на страницата си. Иконката се показва на сайта само ако адресът е попълнен.</p>
    <div class="admin-form-grid">
      <?php foreach (['social_facebook' => ['Facebook', 'https://www.facebook.com/…'],
                      'social_instagram' => ['Instagram', 'https://www.instagram.com/…'],
                      'social_linkedin' => ['LinkedIn', 'https://www.linkedin.com/company/…']] as $k => [$label, $ph]): ?>
        <label id="f-<?= h($k) ?>"><?= h($label) ?>
          <input type="url" name="<?= h($k) ?>" value="<?= h($val($k)) ?>" maxlength="255" placeholder="<?= h($ph) ?>" spellcheck="false"<?= $fattr($k) ?>>
          <?= $ferr($k) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ── Google ────────────────────────────────────────────────────────────── -->
  <?php
  /** Like $fattr, for a field that also has a hint (hint-<field>): both describe it. */
  $gattr = static function (string $k, bool $hasHint = true) use ($errors, $bad_css): string {
      $ids = trim(($hasHint ? 'hint-' . $k : '') . (isset($errors[$k]) ? ' err-' . $k : ''));
      return ($ids !== '' ? ' aria-describedby="' . h($ids) . '"' : '')
           . (isset($errors[$k]) ? ' aria-invalid="true" style="' . $bad_css . '"' : '');
  };
  ?>
  <section class="admin-card" style="<?= $card ?>" aria-labelledby="google-title">
    <h2 class="admin-card__title" id="google-title">Google</h2>
    <p class="admin-meta" style="margin:0 0 1rem;line-height:1.6;">
      Свързва сайта с услугите на Google. Всички полета са по желание — оставете празно това, което не ползвате.
      Статистиката и рекламите се включват само за посетители, които са приели бисквитките.
    </p>

    <label class="admin-checkbox" id="f-site_noindex" style="margin-bottom:.25rem;">
      <input type="checkbox" name="site_noindex" value="1" aria-describedby="hint-site_noindex" <?= $val('site_noindex') === '1' ? 'checked' : '' ?>>
      Скрий сайта от търсачките
    </label>
    <small id="hint-site_noindex" style="<?= $hint ?>margin-bottom:1.25rem;">
      Докато е отметнато, Google и другите търсачки не показват сайта в резултатите си — полезно, докато сайтът още не е готов.
      Махнете отметката, за да могат хората да ви намират в Google. Появяването в резултатите отнема от няколко дни до няколко седмици.
    </small>

    <div class="admin-form-grid">
      <label id="f-google_site_verification" style="grid-column:1/-1;">Код за потвърждение от Google Search Console
        <input type="text" name="google_site_verification" value="<?= h($val('google_site_verification')) ?>" maxlength="300"
               spellcheck="false" autocomplete="off"<?= $gattr('google_site_verification') ?>>
        <?= $ferr('google_site_verification') ?>
        <small id="hint-google_site_verification" style="<?= $hint ?>">
          В <a href="https://search.google.com/search-console" target="_blank" rel="noopener">Google Search Console</a>
          добавете адреса на сайта (<?= h(rtrim(SITE_URL, '/')) ?>/), изберете начин за потвърждение „HTML маркер“ (HTML tag)
          и поставете тук целия ред, който Google показва. Запазете и след това натиснете „Потвърждаване“ в Search Console.
          После там добавете картата на сайта: <?= h(rtrim(SITE_URL, '/')) ?>/sitemap.xml
        </small>
      </label>
      <label id="f-ga4_id">Google Analytics — идентификатор (G-…)
        <input type="text" name="ga4_id" value="<?= h($val('ga4_id')) ?>" maxlength="30" placeholder="G-AB12CD34EF"
               spellcheck="false" autocomplete="off"<?= $gattr('ga4_id') ?>>
        <?= $ferr('ga4_id') ?>
        <small id="hint-ga4_id" style="<?= $hint ?>">В Google Analytics: Администриране → Потоци от данни → вашият сайт → „Идентификатор на измерването“.</small>
      </label>
      <label id="f-gtm_id">Google Tag Manager (GTM-…)
        <input type="text" name="gtm_id" value="<?= h($val('gtm_id')) ?>" maxlength="20" placeholder="GTM-AB12CD3"
               spellcheck="false" autocomplete="off"<?= $gattr('gtm_id') ?>>
        <?= $ferr('gtm_id') ?>
        <small id="hint-gtm_id" style="<?= $hint ?>">Само ако някой ви е настроил Tag Manager. За обикновена статистика стига Google Analytics.</small>
      </label>
      <label id="f-google_ads_id">Google Ads — номер (AW-…)
        <input type="text" name="google_ads_id" value="<?= h($val('google_ads_id')) ?>" maxlength="20" placeholder="AW-123456789"
               spellcheck="false" autocomplete="off"<?= $gattr('google_ads_id', false) ?>>
        <?= $ferr('google_ads_id') ?>
      </label>
      <label id="f-google_ads_purchase_label">Google Ads — етикет за реализация
        <input type="text" name="google_ads_purchase_label" value="<?= h($val('google_ads_purchase_label')) ?>" maxlength="64"
               spellcheck="false" autocomplete="off"<?= $gattr('google_ads_purchase_label') ?>>
        <?= $ferr('google_ads_purchase_label') ?>
        <small id="hint-google_ads_purchase_label" style="<?= $hint ?>">Отчита добавянето на продукт в количката като реализация. Нужен е само ако правите реклами в Google Ads.</small>
      </label>
    </div>
  </section>

  <!-- ── Pre-launch notice ─────────────────────────────────────────────────── -->
  <section class="admin-card" style="<?= $card ?>">
    <h2 class="admin-card__title">Съобщение в началото на сайта</h2>
    <p class="admin-meta" style="margin:0 0 1rem;line-height:1.6;">
      Показва тъмна лента най-горе на всяка страница от сайта — полезно, докато
      още добавяте продукти и съдържание. Посетителите могат да я затворят.
      Лентата не спира нищо: всички страници, цените и поръчките продължават да
      работят нормално. Махнете отметката, когато сайтът е готов.
    </p>
    <label class="admin-checkbox" style="margin-bottom:1rem;">
      <input type="checkbox" name="launch_banner" value="1" <?= $val('launch_banner') === '1' ? 'checked' : '' ?>>
      Показвай съобщението
    </label>
    <div class="admin-form-grid">
      <label id="f-launch_banner_bg">Текст на български
        <input type="text" name="launch_banner_bg" value="<?= h($val('launch_banner_bg')) ?>" maxlength="200"
               placeholder="<?= h(launch_banner_text('bg')) ?>"<?= $fattr('launch_banner_bg') ?>>
        <?= $ferr('launch_banner_bg') ?>
        <small style="<?= $hint ?>">Оставете празно, за да се показва текстът по подразбиране.</small>
      </label>
      <label id="f-launch_banner_en">Текст на английски
        <input type="text" name="launch_banner_en" data-translate-from="launch_banner_bg" value="<?= h($val('launch_banner_en')) ?>" maxlength="200"
               placeholder="<?= h(launch_banner_text('en')) ?>"<?= $fattr('launch_banner_en') ?>>
        <?= $ferr('launch_banner_en') ?>
        <small style="<?= $hint ?>">Оставете празно, за да се показва текстът по подразбиране.</small>
      </label>
    </div>
  </section>

  <?php if (module_enabled_with_needs('newsletter') && module_enabled_with_needs('donations')): ?>
  <!-- ── Newsletter donate box ──────────────────────────────────────────────── -->
  <section class="admin-card" style="<?= $card ?>" aria-labelledby="nl-donate-title">
    <h2 class="admin-card__title" id="nl-donate-title">Покана за дарение в бюлетина</h2>
    <p class="admin-meta" style="margin:0 0 1rem;line-height:1.6;">
      Добавя кутийка с бутон „Дарете сега“ най-долу във всеки бюлетин, който изпращате на абонатите —
      над връзката за отписване. Изключена е, докато не я включите. Как изглежда, виждате в „Преглед“ на всяка кампания.
    </p>
    <label class="admin-checkbox" style="margin-bottom:1rem;">
      <input type="checkbox" name="newsletter_donate_cta" value="1" <?= in_array($val('newsletter_donate_cta'), ['1', 'true'], true) ? 'checked' : '' ?>>
      Показвай поканата за дарение в бюлетина
    </label>
    <div class="admin-form-grid">
      <label id="f-newsletter_donate_heading_bg">Заглавие на български
        <input type="text" name="newsletter_donate_heading_bg" value="<?= h($val('newsletter_donate_heading_bg')) ?>" maxlength="120"
               placeholder="<?= h(t_or('newsletter.donate.heading', 'Подкрепете каузата ни', 'Support our cause', 'bg')) ?>"<?= $fattr('newsletter_donate_heading_bg') ?>>
        <?= $ferr('newsletter_donate_heading_bg') ?>
      </label>
      <label id="f-newsletter_donate_heading_en">Заглавие на английски
        <input type="text" name="newsletter_donate_heading_en" data-translate-from="newsletter_donate_heading_bg" value="<?= h($val('newsletter_donate_heading_en')) ?>" maxlength="120"
               placeholder="<?= h(t_or('newsletter.donate.heading', 'Подкрепете каузата ни', 'Support our cause', 'en')) ?>"<?= $fattr('newsletter_donate_heading_en') ?>>
        <?= $ferr('newsletter_donate_heading_en') ?>
      </label>
      <label id="f-newsletter_donate_text_bg">Текст на български
        <input type="text" name="newsletter_donate_text_bg" value="<?= h($val('newsletter_donate_text_bg')) ?>" maxlength="300"
               placeholder="<?= h(t_or('newsletter.donate.text', 'Всяко дарение ни помага да продължим работата си.', 'Every donation helps us continue our work.', 'bg')) ?>"<?= $fattr('newsletter_donate_text_bg') ?>>
        <?= $ferr('newsletter_donate_text_bg') ?>
        <small style="<?= $hint ?>">Оставете празно, за да се показва текстът по подразбиране.</small>
      </label>
      <label id="f-newsletter_donate_text_en">Текст на английски
        <input type="text" name="newsletter_donate_text_en" data-translate-from="newsletter_donate_text_bg" value="<?= h($val('newsletter_donate_text_en')) ?>" maxlength="300"
               placeholder="<?= h(t_or('newsletter.donate.text', 'Всяко дарение ни помага да продължим работата си.', 'Every donation helps us continue our work.', 'en')) ?>"<?= $fattr('newsletter_donate_text_en') ?>>
        <?= $ferr('newsletter_donate_text_en') ?>
        <small style="<?= $hint ?>">Оставете празно, за да се показва текстът по подразбиране.</small>
      </label>
    </div>
  </section>
  <?php endif; ?>

  <p class="admin-meta" style="margin:0 0 1.5rem;line-height:1.6;">
    Кои части от сайта ползвате (дарения, кампании и др.) се избира на страница <a href="/admin/modules.php">„Модули“</a>.
  </p>

  <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.75rem;margin-bottom:2rem;">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>

<script>
(function () {
    // Picking a style fills in its default colours (the admin can still change them).
    var primary = document.getElementById('brandPrimary'), accent = document.getElementById('brandAccent');
    document.querySelectorAll('input[name="brand_theme"]').forEach(function (r) {
        r.addEventListener('change', function () {
            if (primary && r.dataset.primary) primary.value = r.dataset.primary;
            if (accent && r.dataset.accent) accent.value = r.dataset.accent;
            document.querySelectorAll('input[name="brand_theme"]').forEach(function (o) {
                o.closest('label').style.borderColor = o.checked ? 'var(--teal,#0387A5)' : 'var(--border,#ddd)';
            });
        });
    });
    // Newsletter band preview: the same rules as theme_text_on() in
    // includes/themes.php — white or dark text, whichever contrasts more, and
    // "readable" at WCAG AA 4.5:1.
    var nlColor = document.getElementById('nlColor'), nlColorWrap = document.getElementById('f-newsletter_band_color');
    var nlBox = document.getElementById('nlPreview'), nlBtn = document.getElementById('nlPreviewBtn');
    var nlLine = document.getElementById('nlReadable');
    var LIGHT = <?= json_encode(THEME_TEXT_LIGHT) ?>, DARK = <?= json_encode(THEME_TEXT_DARK) ?>;
    var MSG_OK = <?= json_encode($nl_read, JSON_UNESCAPED_UNICODE) ?>, MSG_HARD = <?= json_encode($nl_hard, JSON_UNESCAPED_UNICODE) ?>;
    function lum(hex) {
        var c = [1, 3, 5].map(function (i) {
            var v = parseInt(hex.substr(i, 2), 16) / 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
    }
    function contrast(a, b) {
        var l1 = lum(a), l2 = lum(b);
        return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    }
    function nlUpdate() {
        if (!nlBox) return;
        var pick = document.querySelector('input[name="newsletter_band"]:checked');
        var choice = pick ? pick.value : 'default', bg, fg;
        if (nlColorWrap) nlColorWrap.style.display = choice === 'custom' ? 'block' : 'none';
        if (choice === 'primary') bg = primary.value;
        else if (choice === 'accent') bg = accent.value;
        else if (choice === 'custom') bg = nlColor.value;
        if (bg && /^#[0-9a-fA-F]{6}$/.test(bg)) {
            fg = contrast(bg, LIGHT) >= contrast(bg, DARK) ? LIGHT : DARK;
        } else {
            var theme = document.querySelector('input[name="brand_theme"]:checked');
            bg = (theme && theme.dataset.nlBg) || '#0387A5';
            fg = (theme && theme.dataset.nlFg) || LIGHT;
        }
        var ok = contrast(bg, fg) >= 4.5;
        nlBox.style.background = bg; nlBox.style.color = fg;
        nlBtn.style.background = fg; nlBtn.style.color = bg;
        var msg = ok ? MSG_OK : MSG_HARD, msgEl = nlLine.querySelector('[data-nl-msg]');
        nlLine.style.color = ok ? '#2d6a35' : '#c0392b';
        nlLine.querySelector('[aria-hidden]').textContent = ok ? '✓' : '⚠';
        // Only rewrite the live region when the verdict changes, so a screen
        // reader is not told the same thing on every step of a colour drag.
        if (msgEl.textContent !== msg) msgEl.textContent = msg;
    }
    document.querySelectorAll('input[name="newsletter_band"], input[name="brand_theme"]').forEach(function (r) {
        r.addEventListener('change', function () { nlUpdate(); });
    });
    [primary, accent, nlColor].forEach(function (el) {
        if (el) el.addEventListener('input', function () { nlUpdate(); });
    });
    // Share preview: the description as it's typed, and a newly chosen picture.
    (function () {
      var desc = document.getElementById('siteDescBg'), out = document.getElementById('shareDescPreview');
      var name = document.querySelector('[name="site_name_bg"]');
      if (desc && out) desc.addEventListener('input', function () {
        out.textContent = desc.value.trim() || (name ? name.value : '');
      });
      var file = document.getElementById('shareInput'), img = document.getElementById('sharePreview');
      if (file && img) file.addEventListener('change', function () {
        if (file.files && file.files[0]) img.src = URL.createObjectURL(file.files[0]);
      });
    })();
    // Preview the chosen logo before saving.
    var input = document.getElementById('logoInput'), preview = document.getElementById('logoPreview');
    if (input && preview && window.URL) {
        input.addEventListener('change', function () {
            if (input.files && input.files[0]) preview.src = URL.createObjectURL(input.files[0]);
        });
    }
})();
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>

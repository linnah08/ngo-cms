<?php
$page_title_admin = 'Организация';
$active_nav       = 'organisation';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
admin_require_admin();

$themes    = brand_themes();
$theme_bg  = ['classic' => 'Класически', 'friendly' => 'Приветлив', 'modern' => 'Модерен', 'editorial' => 'Списание'];
$errors    = [];   // field => message (plus '_form' for errors not tied to a field)
$form      = null; // re-filled values after a failed save

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // PHP drops the whole request body when it exceeds post_max_size.
        $errors['_form'] = 'Файлът с логото е твърде голям и нищо не беше запазено. Максимумът е 2 MB — намалете размера му и опитайте отново.';
    } elseif (!csrf_verify()) {
        $errors['_form'] = 'Страницата беше отворена твърде дълго и сесията изтече. Презаредете страницата и опитайте отново.';
    } else {
        $result = org_validate($_POST, array_keys($themes));
        $form   = $result['values'];
        $errors = $result['errors'];

        $has_logo = is_array($_FILES['logo'] ?? null)
            && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if (!$errors && $has_logo) {
            $logo_err = org_save_logo($_FILES['logo'], $_SERVER['DOCUMENT_ROOT'] . '/assets/images');
            if ($logo_err !== null) $errors['logo'] = $logo_err;
        }

        if (!$errors) {
            if (org_save_overrides($form)) {
                flash_set('success', $has_logo
                    ? 'Промените и новото лого бяха запазени. Ако на сайта още виждате старото лого, презаредете страницата.'
                    : 'Промените бяха запазени и вече се виждат на сайта.');
                header('Location: /admin/organisation.php');
                exit;
            }
            $errors['_form'] = 'Промените не можаха да се запишат на сървъра. Опитайте отново или се свържете с поддръжката.';
        } elseif ($has_logo && !isset($errors['logo'])) {
            $errors['logo'] = 'Логото не беше качено, защото има грешки в другите полета. След като ги поправите, изберете файла отново.';
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

<form method="post" enctype="multipart/form-data" novalidate>
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
    <h2 class="admin-card__title">Банкова сметка за дарения</h2>
    <p class="admin-meta" style="margin:0 0 1rem;line-height:1.6;">
      Показва се на дарителите и във фактурите. Проверяваме IBAN-а автоматично, така че сгрешена цифра няма да мине.
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

  <!-- ── Brand ─────────────────────────────────────────────────────────────── -->
  <section class="admin-card" style="<?= $card ?>">
    <h2 class="admin-card__title">Визия</h2>

    <fieldset id="f-brand_theme" style="border:none;margin:0 0 1rem;padding:0;min-width:0;">
      <legend style="font-weight:600;margin-bottom:.5rem;padding:0;">Стил</legend>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.6rem;">
        <?php foreach ($themes as $tk => $tv): $checked = $val('brand_theme') === $tk; ?>
          <label style="display:flex;align-items:center;gap:.5rem;padding:.6rem .75rem;border:2px solid <?= $checked ? 'var(--teal,#0387A5)' : 'var(--border,#ddd)' ?>;border-radius:8px;cursor:pointer;margin:0;font-weight:500;">
            <input type="radio" name="brand_theme" value="<?= h($tk) ?>" <?= $checked ? 'checked' : '' ?>
                   data-primary="<?= h($tv['primary']) ?>" data-accent="<?= h($tv['accent']) ?>" style="margin:0;flex-shrink:0;">
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

<?php /* Built-in: centres (items edited inline, section="centre"). Vars: $s $f $sid $lang $ctx $show_admin */
$centres = $ctx['centres'];
$admin   = admin_logged_in();
if (empty($centres) && !$admin) return;
$intro = hf($f, 'intro', $lang);
?>
<section class="section<?= home_bg_class($f) ?>">
  <div class="container">
    <div class="section-header">
      <h2<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2>
      <?php if ($intro !== '' || $show_admin): ?><p class="lead" style="margin-top:1rem;"<?= home_cms_attrs($sid, 'intro', $f) ?>><?= h($intro) ?></p><?php endif; ?>
    </div>
    <div class="grid grid--2">
      <?php $i = 0; foreach ($centres as $centre):
        $cname = $lang === 'bg' ? ($centre['name_bg'] ?? '') : (($centre['name_en'] ?? '') ?: ($centre['name_bg'] ?? ''));
        $cdesc = $lang === 'bg' ? ($centre['description_bg'] ?? '') : (($centre['description_en'] ?? '') ?: ($centre['description_bg'] ?? '')); ?>
        <div class="centre-card om-removable" data-cms-remove-type="centre" data-cms-remove-id="<?= $i ?>">
          <?php if (!empty($centre['image'])): ?>
            <div class="centre-card__image">
              <span class="om-img-wrap" data-cms-field="image_<?= $i ?>" data-cms-section="centre">
                <img src="<?= h($centre['image']) ?>" alt="<?= h($cname) ?>" loading="lazy">
                <?php if ($show_admin): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
              </span>
            </div>
          <?php endif; ?>
          <div class="centre-card__body">
            <h3 data-cms-field="name" data-cms-section="centre" data-cms-type="text"
                data-cms-bg="<?= h($centre['name_bg'] ?? '') ?>" data-cms-en="<?= h($centre['name_en'] ?? '') ?>"><?= h($cname) ?></h3>
            <p data-cms-field="description" data-cms-section="centre" data-cms-type="text"
               data-cms-bg="<?= h($centre['description_bg'] ?? '') ?>" data-cms-en="<?= h($centre['description_en'] ?? '') ?>"><?= h($cdesc) ?></p>
          </div>
        </div>
      <?php $i++; endforeach; ?>
    </div>
    <?php if ($admin): ?><button class="om-add-btn" data-cms-add="centre">+ Add centre</button><?php endif; ?>
  </div>
</section>

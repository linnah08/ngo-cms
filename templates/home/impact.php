<?php /* Built-in: impact numbers (items edited inline, section="impact"). Vars: $s $f $sid $lang $ctx $show_admin */
$impact  = $ctx['impact'];
$admin   = admin_logged_in();
if (empty($impact) && !$admin) return;
$heading = hf($f, 'heading', $lang);
?>
<section class="section section--sm section--teal">
  <div class="container">
    <?php if ($heading !== '' || $admin): ?>
    <div class="section-header section-header--center" style="margin-bottom:1.5rem;">
      <h2<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h($heading) ?></h2>
    </div>
    <?php endif; ?>
    <div class="impact-grid">
      <?php $i = 0; foreach ($impact as $item): ?>
        <div class="impact-item om-removable" data-cms-remove-type="impact" data-cms-remove-id="<?= $i ?>">
          <div class="impact-item__number" data-cms-field="number" data-cms-section="impact" data-cms-type="text"
               data-cms-bg="<?= h($item['number'] ?? '') ?>" data-cms-en="<?= h($item['number'] ?? '') ?>"><?= h($item['number'] ?? '') ?></div>
          <div class="impact-item__label" style="color:rgba(255,255,255,0.8);" data-cms-field="label" data-cms-section="impact" data-cms-type="text"
               data-cms-bg="<?= h($item['label_bg'] ?? '') ?>" data-cms-en="<?= h($item['label_en'] ?? '') ?>"><?= h($lang === 'bg' ? ($item['label_bg'] ?? '') : (($item['label_en'] ?? '') ?: ($item['label_bg'] ?? ''))) ?></div>
        </div>
      <?php $i++; endforeach; ?>
    </div>
    <?php if ($admin): ?><button class="om-add-btn" data-cms-add="impact">+ Add impact number</button><?php endif; ?>
  </div>
</section>

<?php /* Built-in: partners (logos edited inline, section="partner"). Vars: $s $f $sid $lang $ctx $show_admin */
$partners = $ctx['partners'];
$admin    = admin_logged_in();
if (empty($partners) && !$admin) return;
?>
<section class="section<?= home_bg_class($f, 'grey') ?>">
  <div class="container">
    <div class="section-header section-header--center">
      <h2<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2>
    </div>
    <div class="partners-grid">
      <?php $i = 0; foreach ($partners as $partner): $tag = !empty($partner['url']) ? 'a' : 'div'; ?>
        <<?= $tag ?> class="partner-item om-removable" data-cms-remove-type="partner" data-cms-remove-id="<?= $i ?>"
          <?php if (!empty($partner['url'])): ?> href="<?= h($partner['url']) ?>" target="_blank" rel="noopener"<?php endif; ?>>
          <span class="om-img-wrap" data-cms-field="logo_<?= $i ?>" data-cms-section="partner">
            <img src="<?= h($partner['logo']) ?>" alt="<?= h($partner['name']) ?>" loading="lazy">
            <?php if ($show_admin): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
          </span>
        </<?= $tag ?>>
      <?php $i++; endforeach; ?>
    </div>
    <?php if ($admin): ?><button class="om-add-btn" data-cms-add="partner">+ Add partner</button><?php endif; ?>
  </div>
</section>

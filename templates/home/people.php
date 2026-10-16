<?php /* People list. Vars: $s $f $sid $lang $ctx $show_admin */
$people = [];
foreach (is_array($f['people'] ?? null) ? $f['people'] : [] as $p) {
    if (is_array($p)) $people[] = $p;
}
if (!$people) return;
$style_printed = !empty($GLOBALS['_om_people_style']);
?>
<?php if (!$style_printed): $GLOBALS['_om_people_style'] = true; ?>
<style>@media(max-width:640px){.home-people .home-people__row{grid-template-columns:1fr!important;gap:1.5rem!important;}.home-people .home-people__frame{max-width:160px;}}</style>
<?php endif; ?>
<section class="section<?= home_bg_class($f) ?>">
  <div class="container">
    <?php if (hf($f, 'heading', $lang) !== '' || $show_admin): ?>
    <div class="section-header"><h2<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2></div>
    <?php endif; ?>
    <ul class="home-people" role="list" style="list-style:none;margin:3rem 0 0;padding:0;display:flex;flex-direction:column;gap:4rem;">
      <?php foreach ($people as $p):
        $name  = hf($p, 'name', $lang);
        $role  = hf($p, 'role', $lang);
        $bio   = hf($p, 'bio', $lang);
        $img   = home_valid_image_path((string) ($p['image'] ?? '')) ? (string) $p['image'] : '';
        $alt   = hf($p, 'image_alt', $lang);
        if ($alt === '') $alt = $name;
      ?>
      <li class="home-people__row" style="display:grid;grid-template-columns:<?= $img !== '' ? '200px 1fr' : '1fr' ?>;gap:3rem;align-items:start;">
        <?php if ($img !== ''): ?>
        <span class="om-img-wrap home-people__frame"<?= home_img_attrs($sid, 'people.' . (string) ($p['id'] ?? '') . '.image') ?> style="display:block;position:relative;overflow:hidden;aspect-ratio:3/4;width:100%;border-radius:8px;">
          <span aria-hidden="true" style="display:block;padding-top:133.3333%;"></span>
          <img data-cms-img-style="<?= h(home_focus_style(null)) ?>" src="<?= asset_url($img) ?>" alt="<?= h($alt) ?>" loading="lazy" style="<?= h(home_focus_style(is_array($p['focus'] ?? null) ? $p['focus'] : null)) ?>">
          <?php if ($show_admin): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
        </span>
        <?php endif; ?>
        <div style="min-width:0;">
          <h3 style="color:var(--teal);"><?= h($name) ?></h3>
          <?php if ($role !== ''): ?><p style="color:var(--text-muted);font-size:0.85rem;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:1.5rem;"><?= h($role) ?></p><?php endif; ?>
          <?php if ($bio !== ''): ?><div class="home-rich"><?= $bio ?></div><?php endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

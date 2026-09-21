<?php /* Cards row (2–4). Vars: $s $f $sid $lang $ctx $show_admin */
$cards = array_values(array_filter(is_array($f['cards'] ?? null) ? $f['cards'] : [], 'is_array'));
if (!$cards) return;
$cols = max(1, min(4, count($cards)));
?>
<section class="section<?= home_bg_class($f) ?>">
  <div class="container">
    <?php if (hf($f, 'heading', $lang) !== '' || $show_admin): ?>
    <div class="section-header"><h2<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2></div>
    <?php endif; ?>
    <ul class="home-cards" role="list" style="list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(<?= $cols ?>,minmax(0,1fr));gap:2rem;">
      <?php foreach ($cards as $c):
        $title = hf($c, 'title', $lang);
        $link  = hf($c, 'link', $lang);
        $link  = home_clean_link($link) ? $link : '';
        $img   = home_valid_image_path((string) ($c['image'] ?? '')) ? $c['image'] : '';
        $ext   = preg_match('#^https?://#i', $link) ? ' target="_blank" rel="noopener noreferrer"' : '';
      ?>
      <li class="card" style="display:flex;flex-direction:column;">
        <?php if ($img !== ''): ?>
        <div class="card__image"><img src="<?= asset_url($img) ?>" alt="<?= h(hf($c, 'image_alt', $lang)) ?>" loading="lazy"></div>
        <?php endif; ?>
        <div class="card__body">
          <h3 class="card__title"><?php if ($link !== ''): ?><a href="<?= h($link) ?>"<?= $ext ?>><?= h($title) ?></a><?php else: ?><?= h($title) ?><?php endif; ?></h3>
          <?php if (hf($c, 'text', $lang) !== ''): ?><p class="card__excerpt"><?= h(hf($c, 'text', $lang)) ?></p><?php endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

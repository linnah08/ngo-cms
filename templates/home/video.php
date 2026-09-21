<?php /* Video block — nothing loads from YouTube/Vimeo until the visitor clicks. Vars: $s $f $sid $lang $ctx $show_admin */
$v = $f['video'] ?? null;
if (!is_array($v) || !home_video_valid($v)) return;
$heading  = hf($f, 'heading', $lang);
$caption  = hf($f, 'caption', $lang);
$label    = $heading ?: ($caption ?: ($lang === 'bg' ? 'видео' : 'video'));
$thumb    = home_valid_image_path((string) ($f['thumb'] ?? '')) ? $f['thumb'] : '';
$provider = $v['provider'] === 'youtube' ? 'YouTube' : 'Vimeo';
?>
<section class="section<?= home_bg_class($f) ?>">
  <div class="container container--narrow">
    <?php if ($heading !== '' || $show_admin): ?>
    <h2 style="margin-bottom:1.5rem;"<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h($heading) ?></h2>
    <?php endif; ?>
    <figure style="margin:0;">
      <div style="position:relative;aspect-ratio:16/9;border-radius:8px;overflow:hidden;background:#111;">
        <button type="button" class="home-video__play"
                data-embed="<?= h(home_video_embed_url($v)) ?>"
                data-title="<?= h($label) ?>"
                aria-label="<?= h(($lang === 'bg' ? 'Пусни видео: ' : 'Play video: ') . $label) ?>"
                style="position:absolute;inset:0;width:100%;height:100%;border:0;padding:0;margin:0;cursor:pointer;background:#111;color:#fff;display:flex;align-items:center;justify-content:center;">
          <?php if ($thumb !== ''): ?>
          <img src="<?= asset_url($thumb) ?>" alt="" loading="lazy" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
          <?php endif; ?>
          <span aria-hidden="true" style="position:relative;display:inline-flex;align-items:center;gap:.6rem;background:rgba(0,0,0,.8);padding:.9rem 1.5rem;border-radius:999px;font-size:1.1rem;font-weight:600;">▶ <?= $lang === 'bg' ? 'Пусни видеото' : 'Play video' ?></span>
        </button>
      </div>
      <?php if ($caption !== '' || $show_admin): ?>
      <figcaption style="margin-top:.75rem;"<?= home_cms_attrs($sid, 'caption', $f) ?>><?= h($caption) ?></figcaption>
      <?php endif; ?>
      <p style="margin:.35rem 0 0;font-size:.85rem;color:var(--text-muted);"><?= h($lang === 'bg' ? "При пускане видеото се зарежда от $provider." : "The video loads from $provider when you play it.") ?></p>
    </figure>
  </div>
</section>

<?php
/**
 * The image area at the top of a post. One photo: a plain image (as before). Two or more:
 * a carousel that opens on the main photo, with previous/next buttons, a counter, swipe
 * and ←/→. Never moves on its own; no slide animation under prefers-reduced-motion.
 * Captions show under the photo and are its alt text.
 *
 * Vars: $article, $lang ('bg'|'en'); $photos_exist (callable) only in tests.
 * Inline styles only — main.css may be stale-cached.
 */
$_ac_photos = article_photos($article, $photos_exist ?? null);
if (!$_ac_photos) return;
$_ac_title = (string) ($article['title'] ?? '');
$_ac_total = count($_ac_photos);
$_ac_start = article_main_photo_index($article, $_ac_photos);
$_ac_alt = static function (array $p, int $n) use ($lang, $_ac_total, $_ac_title): string {
    if ($p['caption'] !== '') return $p['caption'];
    return $lang === 'en' ? "Photo $n of $_ac_total — $_ac_title" : "Снимка $n от $_ac_total — $_ac_title";
};
$_ac_img = 'width:100%;height:auto;border-radius:var(--radius-lg);display:block;';
?>
<?php if ($_ac_total === 1): $p = $_ac_photos[0]; ?>
  <figure style="margin:0 0 2rem;">
    <img src="<?= asset_url($p['src']) ?>" alt="<?= h($p['caption'] !== '' ? $p['caption'] : $_ac_title) ?>" style="<?= $_ac_img ?>">
    <?php if ($p['caption'] !== ''): ?>
      <figcaption style="margin-top:.5rem;font-size:.9rem;color:var(--text-muted);"><?= h($p['caption']) ?></figcaption>
    <?php endif; ?>
  </figure>
<?php else: ?>
  <section data-carousel data-start="<?= $_ac_start ?>" tabindex="0"
           aria-roledescription="<?= $lang === 'en' ? 'carousel' : 'въртележка' ?>"
           aria-label="<?= h($lang === 'en' ? "Photos: $_ac_title" : "Снимки: $_ac_title") ?>"
           style="margin:0 0 2rem;outline-offset:4px;">
    <?php foreach ($_ac_photos as $i => $p): ?>
      <figure data-slide <?= $i === $_ac_start ? '' : 'hidden' ?> style="margin:0;">
        <img src="<?= asset_url($p['src']) ?>" alt="<?= h($_ac_alt($p, $i + 1)) ?>"
             <?= $i === $_ac_start ? '' : 'loading="lazy"' ?>
             style="<?= $_ac_img ?>aspect-ratio:3/2;object-fit:cover;">
        <?php if ($p['caption'] !== ''): ?>
          <figcaption style="margin-top:.5rem;font-size:.9rem;color:var(--text-muted);"><?= h($p['caption']) ?></figcaption>
        <?php endif; ?>
      </figure>
    <?php endforeach; ?>
    <div style="display:flex;align-items:center;justify-content:center;gap:1rem;margin-top:.75rem;">
      <button type="button" data-prev class="btn btn--outline" aria-label="<?= $lang === 'en' ? 'Previous photo' : 'Предишна снимка' ?>"
              style="min-width:44px;min-height:44px;padding:0;justify-content:center;"><span aria-hidden="true">‹</span></button>
      <span data-count aria-live="polite" style="min-width:4rem;text-align:center;font-weight:600;"><?= $_ac_start + 1 ?> / <?= $_ac_total ?></span>
      <button type="button" data-next class="btn btn--outline" aria-label="<?= $lang === 'en' ? 'Next photo' : 'Следваща снимка' ?>"
              style="min-width:44px;min-height:44px;padding:0;justify-content:center;"><span aria-hidden="true">›</span></button>
    </div>
  </section>
  <script>
  (function () {
    var c = document.currentScript.previousElementSibling;
    var slides = c.querySelectorAll('[data-slide]'), count = c.querySelector('[data-count]');
    var i = parseInt(c.dataset.start, 10) || 0, n = slides.length, x0 = null;
    var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function show(k) {
      slides[i].hidden = true;
      i = (k + n) % n;
      slides[i].hidden = false;
      if (!still) { slides[i].style.opacity = 0; slides[i].style.transition = 'opacity .25s'; requestAnimationFrame(function () { slides[i].style.opacity = 1; }); }
      count.textContent = (i + 1) + ' / ' + n;
    }
    c.querySelector('[data-prev]').addEventListener('click', function () { show(i - 1); });
    c.querySelector('[data-next]').addEventListener('click', function () { show(i + 1); });
    c.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { e.preventDefault(); show(i - 1); }
      if (e.key === 'ArrowRight') { e.preventDefault(); show(i + 1); }
    });
    c.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    c.addEventListener('touchend', function (e) {
      if (x0 === null) return;
      var dx = e.changedTouches[0].clientX - x0; x0 = null;
      if (Math.abs(dx) > 40) show(i + (dx < 0 ? 1 : -1));
    }, { passive: true });
  })();
  </script>
<?php endif; ?>

<?php
/**
 * One photo card in the post editor's grid. Rendered by PHP for stored photos and,
 * with placeholder values, inside a <template> that the editor's JS clones for new ones.
 * Vars: $photo ['src','caption'], $i (0-based), $total, $main (bool), $missing (bool, optional:
 * the file isn't on disk — the card says so and the path is still posted).
 * Inline styles only — admin.css may be stale-cached.
 */
$n   = $i + 1;
$uid = 'photo_caption_bg' . $n . '_' . substr(md5($photo['src']), 0, 6);  // id prefix = the BG field's name, so the translate coverage test can resolve it
// Cards can be ~180px wide: buttons may wrap and use tight padding, so no label spills out.
$b   = 'min-height:44px;min-width:44px;white-space:normal;padding:.4rem .6rem;justify-content:center;text-align:center;line-height:1.2;';
$missing = $missing ?? false;
?>
<li class="ap-photo" data-src="<?= h($photo['src']) ?>" data-key="k<?= $i ?>"
    style="list-style:none;border:<?= $main ? '3px solid var(--teal)' : '1px solid var(--border)' ?>;border-radius:8px;padding:.75rem;background:#fff;display:flex;flex-direction:column;gap:.5rem;">
  <input type="hidden" name="photo_src[]" value="<?= h($photo['src']) ?>">
  <?php if ($missing): ?>
    <p style="margin:0;aspect-ratio:1;display:flex;align-items:center;justify-content:center;text-align:center;background:#fef2f2;color:#7f1d1d;border:2px dashed #b91c1c;border-radius:6px;font-weight:600;padding:.5rem;">
      <span><span aria-hidden="true">⚠</span> Файлът липсва на сървъра. Снимката остава, докато не я премахнете.</span></p>
  <?php else: ?>
    <img src="<?= h($photo['src']) ?>" alt="" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:6px;display:block;">
  <?php endif; ?>
  <div style="display:flex;flex-wrap:wrap;gap:.35rem;">
    <button type="button" class="btn <?= $main ? 'btn--primary' : 'btn--outline' ?> ap-main" aria-pressed="<?= $main ? 'true' : 'false' ?>"
            aria-label="Направи снимка <?= $n ?> основна" style="<?= $b ?>flex:1 1 100%;"><?= $main ? '<span aria-hidden="true">★</span> Основна снимка' : 'Направи основна' ?></button>
    <button type="button" class="btn btn--outline ap-up" aria-label="Премести снимка <?= $n ?> нагоре" style="<?= $b ?>">↑</button>
    <button type="button" class="btn btn--outline ap-down" aria-label="Премести снимка <?= $n ?> надолу" style="<?= $b ?>">↓</button>
    <button type="button" class="btn btn--outline ap-crop" aria-label="Изрежи снимка <?= $n ?>" style="<?= $b ?>">Изрежи</button>
    <button type="button" class="btn btn--outline ap-remove" aria-label="Премахни снимка <?= $n ?>"
            style="<?= $b ?>color:#b91c1c;border-color:#b91c1c;">Премахни</button>
  </div>
  <label for="<?= $uid ?>">Надпис под снимка <?= $n ?></label>
  <input type="text" id="<?= $uid ?>" name="photo_caption_bg[<?= $i ?>]" maxlength="300" value="<?= h($photo['caption']) ?>"
         style="width:100%;box-sizing:border-box;min-height:44px;">
</li>

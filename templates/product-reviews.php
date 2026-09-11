<?php
/**
 * Product reviews partial — include in the product detail view.
 * Expected variables:
 *   $slug    string   product slug (shared across languages)
 *   $lang    string   'bg' | 'en'
 *   $reviews array    approved review rows (from product_reviews_fetch_approved)
 */
if (!function_exists('product_reviews_aggregate')) return;

$is_en = $lang === 'en';
$agg   = product_reviews_aggregate($reviews);

$rf            = product_review_flash_get();
$flash_success = $rf !== null && $rf['type'] === 'success';
$flash_error   = ($rf !== null && $rf['type'] === 'error') ? $rf['message'] : '';

$label_title    = $is_en ? 'Reviews'                  : 'Отзиви';
$label_name     = $is_en ? 'Your name'                : 'Твоето име';
$label_email    = $is_en ? 'Email (not shown publicly)' : 'Имейл (не се показва публично)';
$label_rating   = $is_en ? 'Your rating'             : 'Твоята оценка';
$label_review   = $is_en ? 'Your review'             : 'Твоят отзив';
$label_submit   = $is_en ? 'Post review'            : 'Публикувай отзив';
$label_pending  = $is_en ? 'Your review has been received and is awaiting moderation. Thank you!'
                         : 'Отзивът ти е получен и очаква одобрение. Благодарим!';
$label_none     = $is_en ? 'No reviews yet. Be the first!' : 'Все още няма отзиви. Бъди първият!';
$label_verified = $is_en ? '✓ Confirmed purchase' : '✓ Потвърдена покупка';

/** Render filled/empty stars for display (decorative; value announced via aria-label on wrapper). */
if (!function_exists('pr_stars')) {
    function pr_stars(float $value): string {
        $full = (int)round($value);
        $out = '';
        for ($i = 1; $i <= 5; $i++) {
            $out .= '<span aria-hidden="true" style="color:' . ($i <= $full ? '#f5a623' : '#d9d9d9') . ';">★</span>';
        }
        return $out;
    }
}
?>

<section class="section" id="reviews" style="border-top:1px solid var(--border);padding-top:3rem;">
  <div class="container container--narrow">

    <h2 style="font-size:1.3rem;margin-bottom:1rem;">
      <?= $label_title ?>
      <?php if ($agg['count']): ?>
        <span style="font-size:.85rem;font-weight:400;color:var(--text-muted);margin-left:.5rem;">(<?= $agg['count'] ?>)</span>
      <?php endif; ?>
    </h2>

    <?php if ($agg['count']): ?>
      <p style="margin:0 0 2rem;" aria-label="<?= $is_en ? 'Average rating' : 'Средна оценка' ?> <?= h((string)$agg['avg']) ?> / 5">
        <span style="font-size:1.15rem;letter-spacing:2px;"><?= pr_stars($agg['avg']) ?></span>
        <span style="font-size:.9rem;color:var(--text-muted);margin-left:.5rem;"><?= h((string)$agg['avg']) ?> / 5</span>
      </p>
    <?php endif; ?>

    <?php if ($flash_success): ?>
      <div style="background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;padding:.85rem 1.1rem;border-radius:6px;margin-bottom:2rem;">
        ✓ <?= $label_pending ?>
      </div>
    <?php endif; ?>
    <?php if ($flash_error): ?>
      <div style="background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;padding:.85rem 1.1rem;border-radius:6px;margin-bottom:2rem;">
        <?= h($flash_error) ?>
      </div>
    <?php endif; ?>

    <?php if ($reviews): ?>
      <div style="margin-bottom:3rem;display:flex;flex-direction:column;gap:1.5rem;">
        <?php foreach ($reviews as $r): ?>
          <div style="border-left:3px solid var(--teal);padding:.75rem 1rem .75rem 1.25rem;background:var(--off-white);border-radius:0 6px 6px 0;">
            <div style="display:flex;align-items:baseline;gap:.75rem;margin-bottom:.4rem;flex-wrap:wrap;">
              <strong style="font-size:.95rem;"><?= h($r['author_name']) ?></strong>
              <?php if (!empty($r['verified_purchase'])): ?>
                <span style="font-size:.75rem;font-weight:600;color:#2d6a35;background:#e6f4ea;border-radius:4px;padding:.15rem .5rem;"><?= $label_verified ?></span>
              <?php endif; ?>
              <span style="letter-spacing:1px;" aria-label="<?= (int)$r['rating'] ?> / 5"><?= pr_stars((float)$r['rating']) ?></span>
              <time style="font-size:.78rem;color:var(--text-muted);"><?= h(format_date(substr($r['created_at'], 0, 10), $lang)) ?></time>
            </div>
            <p style="margin:0;line-height:1.7;white-space:pre-wrap;"><?= h($r['content']) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p style="color:var(--text-muted);margin-bottom:2.5rem;"><?= $label_none ?></p>
    <?php endif; ?>

    <form method="POST" action="/api/review-submit.php" style="display:flex;flex-direction:column;gap:1rem;">
      <?= csrf_field() ?>
      <input type="hidden" name="product_slug" value="<?= h($slug) ?>">
      <input type="hidden" name="lang"         value="<?= h($lang) ?>">

      <div style="display:none;" aria-hidden="true">
        <label for="website">Website</label>
        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
      </div>

      <div>
        <label for="review-name" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.35rem;"><?= $label_name ?> *</label>
        <input type="text" id="review-name" name="author_name" required maxlength="100"
               style="width:100%;box-sizing:border-box;padding:.6rem .85rem;border:1px solid var(--border);border-radius:var(--radius);font-size:.95rem;">
      </div>

      <div>
        <label for="review-email" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.35rem;"><?= $label_email ?> *</label>
        <input type="email" id="review-email" name="author_email" required maxlength="255"
               style="width:100%;box-sizing:border-box;padding:.6rem .85rem;border:1px solid var(--border);border-radius:var(--radius);font-size:.95rem;">
      </div>

      <fieldset style="border:0;padding:0;margin:0;">
        <legend style="font-size:.85rem;font-weight:600;margin-bottom:.35rem;"><?= $label_rating ?> *</legend>
        <div style="display:flex;gap:.4rem;">
          <?php for ($i = 1; $i <= 5; $i++): ?>
            <label style="display:inline-flex;align-items:center;gap:.2rem;font-size:.9rem;cursor:pointer;">
              <input type="radio" name="rating" value="<?= $i ?>" required> <?= $i ?>
            </label>
          <?php endfor; ?>
        </div>
      </fieldset>

      <div>
        <label for="review-content" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.35rem;"><?= $label_review ?> *</label>
        <textarea id="review-content" name="content" required minlength="5" maxlength="2000" rows="5"
                  style="width:100%;box-sizing:border-box;padding:.6rem .85rem;border:1px solid var(--border);border-radius:var(--radius);font-size:.95rem;resize:vertical;font-family:inherit;"></textarea>
      </div>

      <?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/turnstile-widget.php'; ?>

      <div>
        <button type="submit" class="btn btn--primary"><?= $label_submit ?></button>
      </div>
    </form>

  </div>
</section>

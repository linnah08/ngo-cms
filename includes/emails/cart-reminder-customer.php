<?php
// Variables: $tpl (rendered subject/intro/outro), $lang, $lines (shop rows: name, variant, qty, price, image),
//            $pledge (pledge rows: reward, amount, campaign), $restore_url, $optout_url
$_lang = ($lang ?? 'bg') === 'en' ? 'en' : 'bg';
$_esc  = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

echo $tpl['intro'];
?>

<?php if (!empty($pledge)): ?>
<div class="box">
  <strong><?= $_lang === 'en' ? 'Campaign' : 'Кампания' ?>:</strong> <?= $_esc($pledge['campaign']) ?><br>
<?php if ($pledge['reward'] !== ''): ?>
  <strong><?= $_lang === 'en' ? 'Reward' : 'Награда' ?>:</strong> <?= $_esc($pledge['reward']) ?><br>
<?php endif; ?>
  <strong><?= $_lang === 'en' ? 'Your support' : 'Вашата подкрепа' ?>:</strong> <?= $_esc(number_format((float)$pledge['amount'], 2, '.', '')) ?> €
</div>
<?php elseif (!empty($lines)): ?>
<table>
  <thead>
    <tr>
      <th colspan="2"><?= $_lang === 'en' ? 'Product' : 'Продукт' ?></th>
      <th style="text-align:right;"><?= $_lang === 'en' ? 'Qty' : 'Бр.' ?></th>
      <th style="text-align:right;"><?= $_lang === 'en' ? 'Price' : 'Цена' ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($lines as $_l): ?>
    <tr>
      <td style="width:64px;">
<?php if ($_l['image'] !== ''): ?>
        <img src="<?= $_esc($_l['image']) ?>" alt="" width="56" height="56" style="display:block;width:56px;height:56px;object-fit:cover;border-radius:4px;">
<?php endif; ?>
      </td>
      <td>
        <?= $_esc($_l['name']) ?>
<?php if ($_l['variant'] !== ''): ?>
        <br><span style="font-size:12px;color:#6b6560;"><?= $_esc($_l['variant']) ?></span>
<?php endif; ?>
      </td>
      <td style="text-align:right;"><?= (int)$_l['qty'] ?></td>
      <td style="text-align:right;"><?= $_esc(number_format((float)$_l['price'] * (int)$_l['qty'], 2, '.', '')) ?> €</td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<p style="text-align:center;margin:28px 0;">
  <a href="<?= $_esc($restore_url) ?>" style="display:inline-block;background:#0387A5;color:#ffffff;text-decoration:none;font-weight:bold;padding:14px 32px;border-radius:6px;font-size:16px;">
    <?= $_esc(!empty($pledge) ? ($_lang === 'en' ? 'Finish my support' : 'Довършете подкрепата') : ($_lang === 'en' ? 'Back to my cart' : 'Към количката')) ?>
  </a>
</p>

<?php echo $tpl['outro']; ?>

<p style="color:#6b6560;font-size:14px;">
  <a href="<?= $_esc($optout_url) ?>" style="color:#6b6560;"><?= $_lang === 'en' ? "Don't remind me" : 'Не ми напомняйте' ?></a>
</p>

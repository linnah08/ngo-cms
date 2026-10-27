<?php
$page_title_admin = 'SumUp — продажби на място';
$active_nav       = 'sumup';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/sumup.php';
admin_require_shop();   // shop admins answer the waiting sales; connecting the key is for admins only
module_admin_guard('shop');

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $sale   = (int) ($_POST['sale_id'] ?? 0);
    $anchor = '';

    if (in_array($action, ['connect', 'disconnect'], true) && !admin_is_admin()) {
        flash_set('error', 'Само администратор може да свързва или прекъсва SumUp.');
        header('Location: /admin/sumup.php');
        exit;
    }

    try {
        switch ($action) {
            case 'connect':
                $m = sumup_connect(is_string($_POST['api_key'] ?? null) ? $_POST['api_key'] : '');
                setting_delete('sumup_last_error');
                flash_set('success', 'SumUp е свързан с профила „' . $m['name'] . '“. От днес продажбите с терминала ще намаляват наличността в магазина.');
                break;

            case 'disconnect':
                sumup_disconnect();
                flash_set('success', 'Връзката със SumUp е прекъсната. Продажбите с терминала вече не променят наличността. Досегашните остават в списъка.');
                break;

            case 'sync':
                $s = sumup_sync($pdo);
                setting_delete('sumup_last_error');
                flash_set('success', $s['new_sales'] === 0 && $s['refunds'] === 0
                    ? 'Проверено — няма нови продажби от последната проверка.'
                    : 'Проверено: ' . $s['new_sales'] . ' нови продажби, ' . $s['items_out'] . ' бр. извадени от наличността'
                      . ($s['refunds'] ? ', ' . $s['refunds'] . ' върнати продажби (' . $s['items_back'] . ' бр. обратно в наличност)' : '')
                      . ($s['attention'] ? '. ' . $s['attention'] . ' чакат да изберете продукт — вижте по-долу.' : '.'));
                $anchor = $s['attention'] ? '#waiting' : '';
                break;

            case 'resolve_line':
                $msg = sumup_resolve_line($pdo, $sale, (int) ($_POST['line'] ?? -1), is_string($_POST['target'] ?? null) ? $_POST['target'] : '');
                $msg === '' ? flash_set('success', ($_POST['target'] ?? '') === 'skip' ? 'Отбелязано — наличността не е променена.' : 'Готово — продуктът е изваден от наличността.')
                            : flash_set('error', $msg);
                $anchor = '#waiting';
                break;

            case 'resolve_sale':
                $targets = array_values(array_filter((array) ($_POST['target'] ?? []), 'is_string'));
                $qtys    = array_values((array) ($_POST['qty'] ?? []));
                $picks   = [];
                foreach ($targets as $i => $t) $picks[] = [$t, (int) ($qtys[$i] ?? 1)];
                $msg = sumup_resolve_sale_products($pdo, $sale, $picks);
                $msg === '' ? flash_set('success', 'Готово — продуктите са извадени от наличността.') : flash_set('error', $msg);
                $anchor = '#waiting';
                break;

            case 'dismiss':
                sumup_dismiss_sale($pdo, $sale);
                flash_set('success', 'Отбелязано — тази продажба не променя наличността.');
                $anchor = '#waiting';
                break;

            default:
                flash_set('error', 'Непознато действие. Презаредете страницата и опитайте отново.');
        }
    } catch (SumUpError $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: /admin/sumup.php' . $anchor);
    exit;
}

// ── Page data ─────────────────────────────────────────────────────────────────
$connected  = sumup_is_connected();
$catalog    = sumup_catalog($pdo);
$by_target  = [];
foreach ($catalog as $e) $by_target[sumup_target_key($e['product_id'], $e['variant_id'])] = $e;
$waiting    = $pdo->query('SELECT * FROM sumup_sales WHERE needs_attention = 1 AND refunded_at IS NULL ORDER BY sold_at')->fetchAll(PDO::FETCH_ASSOC);
$recent     = $pdo->query('SELECT * FROM sumup_sales ORDER BY sold_at DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
$last       = json_decode(setting_get('sumup_last_sync'), true) ?: null;
$last_error = json_decode(setting_get('sumup_last_error'), true) ?: null;
$started    = setting_get('sumup_connected_at');
$flashes    = flash_get();

$fmt_time = static fn (string $dt): string => date('d.m.Y H:i', strtotime($dt));
$btn      = 'min-height:44px;';
$sr       = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;';
$cell     = 'padding:.5rem .6rem;border-bottom:1px solid var(--border,#e5e7eb);text-align:left;vertical-align:top;';
$note     = 'margin:0 0 1rem;padding:.6rem .8rem;background:#fff8eb;border:1px solid #f3d9a4;border-radius:6px;color:#5c3d06;font-size:.9rem;line-height:1.5;';

/** <option>s for every product/variant a sale can take stock from. */
$options = static function () use ($catalog): string {
    $html = '<option value="">— изберете продукт —</option>';
    foreach ($catalog as $e) {
        $html .= '<option value="' . h(sumup_target_key($e['product_id'], $e['variant_id'])) . '">'
               . h($e['label']) . ' (налични: ' . (int) $e['stock'] . ')</option>';
    }
    return $html;
};

/** "Чаша ×2, Тениска — M ×1" for what a sale took. */
$taken = static function (array $lines) use ($by_target): string {
    $parts = [];
    foreach ($lines as $l) {
        if (empty($l['done'])) continue;
        $e = $by_target[sumup_target_key((int) $l['product_id'], $l['variant_id'] ? (int) $l['variant_id'] : null)] ?? null;
        $parts[] = ($e['label'] ?? ($l['name'] !== '' ? $l['name'] : 'изтрит продукт')) . ' ×' . (int) $l['quantity'];
    }
    return implode(', ', $parts);
};

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>
<?php foreach ($flashes as $_f): $_err = ($_f['type'] ?? '') === 'error'; ?>
  <div class="admin-alert admin-alert--<?= $_err ? 'error' : 'success' ?>" role="<?= $_err ? 'alert' : 'status' ?>" style="margin-bottom:1.5rem;"><?= $_err ? '⚠️ ' : '✅ ' ?><?= h($_f['message'] ?? '') ?></div>
<?php endforeach; ?>

<!-- ── Connection ──────────────────────────────────────────────────────────── -->
<section class="admin-card" style="margin-bottom:1.5rem;">
  <h2 class="admin-card__title">Връзка със SumUp</h2>
  <p style="margin:0 0 1rem;line-height:1.6;">
    Когато продавате на място с картовия терминал на SumUp, сайтът всяка нощ проверява продажбите
    и намалява наличността на продадените продукти в онлайн магазина. Ако върнете пари в SumUp,
    продуктите се връщат в наличност.
  </p>

  <?php if ($connected): ?>
    <p style="margin:0 0 .5rem;"><span style="color:#2e7d32;font-weight:600;">✓ Свързан</span> с профила „<?= h(setting_get('sumup_merchant_name')) ?>“.</p>
    <p class="admin-meta" style="margin:0 0 1rem;">
      Отчитат се продажбите от <?= ctype_digit($started) ? h(date('d.m.Y H:i', (int) $started)) : '—' ?> нататък.
      <?php if ($last): ?>
        Последна проверка: <?= h(date('d.m.Y H:i', (int) $last['at'])) ?>.
      <?php else: ?>
        Още няма проверка — първата ще е тази нощ, или натиснете „Провери сега“.
      <?php endif; ?>
    </p>
    <?php if ($last_error): ?>
      <p role="alert" style="<?= $note ?>">⚠️ Последната нощна проверка (<?= h(date('d.m.Y H:i', (int) $last_error['at'])) ?>) не успя: <?= h($last_error['message']) ?></p>
    <?php endif; ?>
    <div style="display:flex;flex-wrap:wrap;gap:.75rem;">
      <form method="POST" style="margin:0;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="sync">
        <button type="submit" class="btn btn--primary" style="<?= $btn ?>">Провери сега</button>
      </form>
      <?php if (admin_is_admin()): ?>
      <form method="POST" style="margin:0;" data-confirm="Да прекъснем ли връзката със SumUp? Продажбите с терминала вече няма да намаляват наличността.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="disconnect">
        <button type="submit" class="btn btn--outline" style="<?= $btn ?>">Прекъсни връзката</button>
      </form>
      <?php endif; ?>
    </div>
  <?php elseif (!admin_is_admin()): ?>
    <p style="margin:0;">SumUp още не е свързан. Помолете администратор да го свърже от тази страница.</p>
  <?php else: ?>
    <ol style="margin:0 0 1rem;padding-left:1.25rem;line-height:1.7;">
      <li>На компютър влезте в <a href="https://me.sumup.com/settings/api-keys" target="_blank" rel="noopener">SumUp → Настройки → За разработчици → API ключове<span style="<?= $sr ?>"> (отваря се в нов прозорец)</span></a>.</li>
      <li>Натиснете „Create“ (Създай), дайте име, например „Сайт“, и копирайте ключа. SumUp го показва само веднъж.</li>
      <li>Поставете ключа тук и натиснете „Свържи със SumUp“.</li>
    </ol>
    <form method="POST" style="margin:0;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="connect">
      <label for="sumup-key" style="display:block;font-weight:600;margin-bottom:.35rem;">Ключ от SumUp</label>
      <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;">
        <input type="password" id="sumup-key" name="api_key" required autocomplete="off" spellcheck="false"
               style="flex:1 1 280px;min-width:0;min-height:44px;box-sizing:border-box;">
        <button type="submit" class="btn btn--primary" style="<?= $btn ?>">Свържи със SumUp</button>
      </div>
      <p class="admin-meta" style="margin:.5rem 0 0;">Отчитат се само продажбите след свързването — по-старите не променят наличността.</p>
    </form>
  <?php endif; ?>
</section>

<!-- ── Waiting for the admin ────────────────────────────────────────────────── -->
<section class="admin-card" id="waiting" style="margin-bottom:1.5rem;">
  <h2 class="admin-card__title">Чакат да изберете продукт<?= $waiting ? ' (' . count($waiting) . ')' : '' ?></h2>
  <?php if (!$waiting): ?>
    <p style="margin:0;">✓ Няма продажби, които чакат. Всичко продадено е отчетено в наличността.</p>
  <?php else: ?>
    <p style="margin:0 0 1rem;line-height:1.6;">
      Тези продажби не можаха да се свържат с продукт от магазина и наличността им още не е намалена.
      Изберете какво е продадено. Сайтът запомня избора ви за всяко име, така че следващия път ще го разпознае сам.
    </p>
    <?php foreach ($waiting as $s): $lines = (array) json_decode((string) $s['lines'], true); ?>
      <div style="border:1px solid var(--border,#e5e7eb);border-radius:8px;padding:1rem;margin-bottom:1rem;">
        <p style="margin:0 0 .75rem;font-weight:600;">
          <?= h($fmt_time($s['sold_at'])) ?> · <?= h(number_format((float) $s['amount'], 2, ',', ' ')) ?> <?= h($s['currency']) ?>
          <span class="admin-meta" style="font-weight:400;">· код <?= h($s['transaction_code'] ?: '—') ?></span>
        </p>

        <?php if (!$lines): ?>
          <p style="<?= $note ?>">ℹ️ Сумата е въведена ръчно в SumUp, без да е избран продукт. Изберете какво продадохте.</p>
          <form method="POST" style="margin:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="resolve_sale">
            <input type="hidden" name="sale_id" value="<?= (int) $s['id'] ?>">
            <?php for ($i = 0; $i < 3; $i++): $fid = 'sale' . (int) $s['id'] . '-' . $i; ?>
              <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;margin-bottom:.5rem;">
                <div style="flex:1 1 260px;min-width:0;">
                  <label for="<?= $fid ?>-p" style="display:block;font-size:.85rem;margin-bottom:.2rem;">Продукт <?= $i + 1 ?><?= $i ? ' (ако има още)' : '' ?></label>
                  <select id="<?= $fid ?>-p" name="target[]" style="width:100%;min-height:44px;"><?= $options() ?></select>
                </div>
                <div style="flex:0 0 90px;">
                  <label for="<?= $fid ?>-q" style="display:block;font-size:.85rem;margin-bottom:.2rem;">Брой</label>
                  <input id="<?= $fid ?>-q" type="number" name="qty[]" value="1" min="1" max="999" style="width:100%;min-height:44px;box-sizing:border-box;">
                </div>
              </div>
            <?php endfor; ?>
            <div style="display:flex;flex-wrap:wrap;gap:.75rem;margin-top:.75rem;">
              <button type="submit" class="btn btn--primary" style="<?= $btn ?>">Запази и намали наличността</button>
              <button type="submit" form="dismiss-<?= (int) $s['id'] ?>" class="btn btn--outline" style="<?= $btn ?>">Не е продукт от магазина</button>
            </div>
          </form>
          <form method="POST" id="dismiss-<?= (int) $s['id'] ?>" style="display:none;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="dismiss">
            <input type="hidden" name="sale_id" value="<?= (int) $s['id'] ?>">
          </form>
        <?php else: ?>
          <?php foreach ($lines as $i => $l): $fid = 'line' . (int) $s['id'] . '-' . $i; ?>
            <?php if (!empty($l['done'])): ?>
              <p style="margin:0 0 .5rem;">✓ <?= h($l['name']) ?> ×<?= (int) $l['quantity'] ?> — <?= h($taken([$l])) ?></p>
            <?php elseif (!empty($l['skipped'])): ?>
              <p style="margin:0 0 .5rem;" class="admin-meta">— <?= h($l['name']) ?> ×<?= (int) $l['quantity'] ?> — не е продукт от магазина</p>
            <?php else: ?>
              <form method="POST" style="margin:0 0 .75rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resolve_line">
                <input type="hidden" name="sale_id" value="<?= (int) $s['id'] ?>">
                <input type="hidden" name="line" value="<?= (int) $i ?>">
                <label for="<?= $fid ?>" style="display:block;margin-bottom:.3rem;">
                  В SumUp: <strong>„<?= h($l['name']) ?>“</strong> ×<?= (int) $l['quantity'] ?> — кой продукт е това?
                </label>
                <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
                  <select id="<?= $fid ?>" name="target" required style="flex:1 1 260px;min-width:0;min-height:44px;"><?= $options() ?></select>
                  <button type="submit" class="btn btn--primary" style="<?= $btn ?>">Запази</button>
                  <button type="submit" name="target" value="skip" formnovalidate class="btn btn--outline" style="<?= $btn ?>">Не е продукт от магазина</button>
                </div>
                <?php if (sumup_name_lacks_variant($pdo, sumup_norm((string) $l['name']))): ?>
                  <p class="admin-meta" style="margin:.4rem 0 0;">ℹ️ В името няма размер или вариант, затова сайтът няма да го запомни. Добавете варианта към името в SumUp (вижте списъка по-долу).</p>
                <?php endif; ?>
              </form>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<!-- ── How to set up SumUp ──────────────────────────────────────────────────── -->
<section class="admin-card" style="margin-bottom:1.5rem;">
  <h2 class="admin-card__title">Как да подготвите SumUp</h2>
  <ol style="margin:0 0 1rem;padding-left:1.25rem;line-height:1.7;">
    <li>В приложението SumUp (или на <a href="https://me.sumup.com" target="_blank" rel="noopener">me.sumup.com<span style="<?= $sr ?>"> (отваря се в нов прозорец)</span></a> → Артикули) добавете всеки продукт, който продавате на място, <strong>с името от списъка по-долу</strong>.</li>
    <li>За продукт с размери или цветове добавете отделен артикул за всеки вариант с името от списъка, например „Тениска - M“.</li>
    <li>На щанда <strong>избирайте продукта</strong> от списъка в SumUp, вместо да въвеждате сума. Така сайтът знае какво е продадено.</li>
  </ol>
  <p class="admin-meta" style="margin:0 0 1rem;">
    Не е страшно, ако име се различава или въведете сума — продажбата ще се появи в „Чакат да изберете продукт“ и ще изберете продукта с един клик.
  </p>
  <?php if ($catalog): ?>
  <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:.92rem;">
      <caption style="<?= $sr ?>">Имена на продуктите за SumUp</caption>
      <thead><tr><th scope="col" style="<?= $cell ?>">Име в SumUp</th><th scope="col" style="<?= $cell ?>">Цена</th><th scope="col" style="<?= $cell ?>">Наличност в магазина</th></tr></thead>
      <tbody>
      <?php
        $prices = [];
        foreach ($pdo->query('SELECT id, price_eur FROM products')->fetchAll(PDO::FETCH_KEY_PAIR) as $pid => $pr) $prices[(int) $pid] = (float) $pr;
      ?>
      <?php foreach ($catalog as $e): ?>
        <tr>
          <td style="<?= $cell ?>user-select:all;"><?= h($e['variant_id'] ? str_replace(' — ', ' - ', $e['label']) : $e['label']) ?></td>
          <td style="<?= $cell ?>white-space:nowrap;"><?= h(number_format($prices[$e['product_id']] ?? 0, 2, ',', ' ')) ?> €</td>
          <td style="<?= $cell ?>"><?= (int) $e['stock'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<!-- ── Recent sales ─────────────────────────────────────────────────────────── -->
<section class="admin-card" style="margin-bottom:1.5rem;">
  <h2 class="admin-card__title">Последни продажби от SumUp</h2>
  <?php if (!$recent): ?>
    <p style="margin:0;" class="admin-meta">Още няма отчетени продажби.</p>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:.92rem;">
      <thead><tr>
        <th scope="col" style="<?= $cell ?>">Дата</th><th scope="col" style="<?= $cell ?>">Сума</th>
        <th scope="col" style="<?= $cell ?>">Извадено от наличността</th><th scope="col" style="<?= $cell ?>">Състояние</th>
      </tr></thead>
      <tbody>
      <?php foreach ($recent as $s): $lines = (array) json_decode((string) $s['lines'], true); ?>
        <tr>
          <td style="<?= $cell ?>white-space:nowrap;"><?= h($fmt_time($s['sold_at'])) ?></td>
          <td style="<?= $cell ?>white-space:nowrap;"><?= h(number_format((float) $s['amount'], 2, ',', ' ')) ?> <?= h($s['currency']) ?></td>
          <td style="<?= $cell ?>"><?= h($taken($lines) ?: '—') ?></td>
          <td style="<?= $cell ?>white-space:nowrap;">
            <?php if ($s['refunded_at'] !== null): ?>↩︎ Върната
            <?php elseif ((int) $s['needs_attention']): ?><a href="#waiting">⚠️ Чака избор</a>
            <?php else: ?>✓ Отчетена<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<script>
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
  f.addEventListener('submit', function (e) {
    if (f.dataset.confirmed) return;
    e.preventDefault();
    window._adminConfirm(f.dataset.confirm, 'Прекъсни').then(function (ok) {
      if (ok) { f.dataset.confirmed = '1'; f.submit(); }
    });
  });
});
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>

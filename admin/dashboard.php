<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';

$page_title_admin = 'Начало';
$active_nav       = 'dashboard';

$can_sign = admin_can_sign();
$can_shop = admin_can_manage_shop();
$pdo      = get_pdo();

// ── Unsigned donation certs ───────────────────────────────────────────────────
$unsigned_certs = [];
if ($can_sign) {
    $stmt = $pdo->query(
        "SELECT d.id, d.formatted_number, d.generated_at, o.customer_name
         FROM documents d
         JOIN orders o ON o.id = d.order_id
         WHERE d.type = 'donation_cert' AND d.signed_at IS NULL
         ORDER BY d.generated_at ASC"
    );
    $unsigned_certs = $stmt->fetchAll();
}

// ── Paid donations without a cert ────────────────────────────────────────────
$uncerted_donations = [];
if ($can_shop) {
    $stmt = $pdo->query(
        "SELECT o.id, o.order_number, o.customer_name, o.total_eur
         FROM orders o
         LEFT JOIN documents d ON d.order_id = o.id AND d.type = 'donation_cert'
         WHERE o.type = 'donation' AND o.payment_status = 'paid' AND d.id IS NULL
         ORDER BY o.created_at ASC"
    );
    $uncerted_donations = $stmt->fetchAll();
}

// ── Physical orders awaiting shipping (new + confirmed, must have delivery_type) ──
// delivery_type is always set at checkout for real physical orders; filtering it
// out also drops any orders that were accidentally stored with type='physical'.
$shipping_items = [];
if ($can_shop) {
    $stmt = $pdo->query(
        "SELECT id, order_number AS number, customer_name AS name,
                items, created_at, status, 'order' AS kind
         FROM orders
         WHERE type = 'physical'
           AND status IN ('new', 'confirmed')
           AND delivery_type IN ('office','apt','address','locker')
         ORDER BY created_at ASC"
    );
    foreach ($stmt->fetchAll() as $o) {
        $items      = json_decode($o['items'] ?? '[]', true) ?: [];
        $item_count = array_sum(array_column($items, 'qty')) ?: count($items);
        $shipping_items[] = [
            'kind'    => 'order',
            'link'    => '/admin/order-view.php?id=' . (int)$o['id'],
            'number'  => $o['number'],
            'name'    => $o['name'],
            'meta'    => $item_count . ' арт.',
            'date'    => $o['created_at'],
        ];
    }

    // ── Campaign pledges awaiting shipping (reward OR arranged delivery) ───────
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pledge_shipping.php';
    foreach (pledges_awaiting_shipping($pdo) as $p) {
        // Link to order-view (label + status live there); pledge-view as fallback.
        $link = !empty($p['order_id'])
            ? '/admin/order-view.php?id=' . (int)$p['order_id']
            : '/admin/pledge-view.php?id=' . (int)$p['id'];
        $shipping_items[] = [
            'kind'   => 'pledge',
            'link'   => $link,
            'number' => $p['number'],
            'name'   => $p['name'],
            'meta'   => $p['reward_title'] ?: 'Награда',
            'date'   => $p['created_at'],
        ];
    }

    usort($shipping_items, fn($a, $b) => strcmp($a['date'], $b['date']));
}

// ── Calendar: 4 weeks starting from Monday of the current week ───────────────
$tz        = new DateTimeZone('Europe/Sofia');
$today_dt  = new DateTimeImmutable('today', $tz);
$today_str = $today_dt->format('Y-m-d');

$dow_today = (int)$today_dt->format('N'); // 1=Mon … 7=Sun
$cal_start = $today_dt->modify('-' . ($dow_today - 1) . ' days');

$cal_dates = [];
for ($i = 0; $i <= 27; $i++) {
    $cal_dates[] = $cal_start->modify("+{$i} days")->format('Y-m-d');
}
$calendar = array_fill_keys($cal_dates, []);

$articles_dir = ARTICLES_PATH . '/bg';
if (is_dir($articles_dir)) {
    foreach (glob($articles_dir . '/*.json') as $file) {
        $a = load_json($file);
        if (!is_array($a)) continue;
        $title = $a['title'] ?? basename($file, '.json');
        $slug  = $a['slug'] ?? '';

        // Website: draft article whose publish date falls in range
        if (($a['status'] ?? '') === 'draft' && !empty($a['date'])) {
            $d = $a['date'];
            if (isset($calendar[$d])) {
                $calendar[$d][] = ['ch' => 'web', 'title' => $title, 'time' => 'публикуване', 'slug' => $slug];
            }
        }

        // Social channels via *_scheduled_at (Europe/Sofia datetime-local strings)
        foreach ([
            'fb_scheduled_at'      => 'fb',
            'insta_scheduled_at'   => 'ig',
            'linkedin_scheduled_at' => 'li',
        ] as $field => $ch) {
            if (empty($a[$field]) || $a[$field] === 'now') continue;
            try {
                $dt = new DateTimeImmutable($a[$field], $tz);
                $d  = $dt->format('Y-m-d');
                $t  = $dt->format('H:i');
                if (isset($calendar[$d])) {
                    $calendar[$d][] = ['ch' => $ch, 'title' => $title, 'time' => $t, 'slug' => $slug];
                }
            } catch (Exception $e) { /* skip malformed date */ }
        }

        // Published-now FB/IG posts: scheduled_at='now', actual time stored in *_due_at (UTC)
        foreach ([
            'fb_scheduled_at'    => ['fb_due_at',    'fb'],
            'insta_scheduled_at' => ['insta_due_at', 'ig'],
        ] as $sched_field => [$due_field, $ch]) {
            if (($a[$sched_field] ?? '') !== 'now' || empty($a[$due_field])) continue;
            try {
                $dt = (new DateTimeImmutable($a[$due_field]))->setTimezone($tz);
                $d  = $dt->format('Y-m-d');
                $t  = $dt->format('H:i');
                if (isset($calendar[$d])) {
                    $calendar[$d][] = ['ch' => $ch, 'title' => $title, 'time' => $t, 'slug' => $slug];
                }
            } catch (Exception $e) { /* skip malformed date */ }
        }

        // Published LinkedIn posts (linkedin_posted_at)
        if (!empty($a['linkedin_posted_at'])) {
            try {
                $dt = new DateTimeImmutable($a['linkedin_posted_at'], $tz);
                $d  = $dt->format('Y-m-d');
                $t  = $dt->format('H:i');
                if (isset($calendar[$d])) {
                    $calendar[$d][] = ['ch' => 'li', 'title' => $title, 'time' => $t, 'slug' => $slug];
                }
            } catch (Exception $e) { /* skip malformed date */ }
        }
    }
}

// ── Locale helpers ────────────────────────────────────────────────────────────
$bg_days_short   = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Нд'];
$bg_months_short = ['', 'яну', 'фев', 'мар', 'апр', 'май', 'юни', 'юли', 'авг', 'сеп', 'окт', 'ное', 'дек'];

$ch_labels = ['web' => 'Сайт', 'fb' => 'FB', 'ig' => 'IG', 'li' => 'LI'];
$ch_colors = [
    'web' => 'background:#e4f0f5;color:#0387A5;',
    'fb'  => 'background:#e8f0fe;color:#1877f2;',
    'ig'  => 'background:#fce4ec;color:#c2185b;',
    'li'  => 'background:#e3f2fd;color:#0a66c2;',
];

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1>Добре дошли, <?= h($current_user['name'] ?? 'Admin') ?></h1>
</div>

<?php if ($can_sign || $can_shop): ?>
<div style="display:flex;gap:.75rem;margin-bottom:.75rem;align-items:flex-start;">

  <?php if ($can_sign): ?>
  <!-- Unsigned donation certs -->
  <div style="flex:1;min-width:0;background:#fff;border:1px solid var(--border);border-radius:8px;padding:.85rem 1rem;">
    <div style="display:flex;align-items:center;gap:.4rem;margin-bottom:.6rem;">
      <h3 style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin:0;flex:1;">Сертификати за подписване</h3>
      <?php if ($unsigned_certs): ?>
      <span style="display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:10px;font-size:.67rem;font-weight:700;color:#fff;background:#c0392b;"><?= count($unsigned_certs) ?></span>
      <?php endif; ?>
    </div>
    <?php if (!$unsigned_certs): ?>
      <p style="font-size:.78rem;color:var(--text-muted);font-style:italic;margin:0;">Всички сертификати са подписани.</p>
    <?php else: ?>
      <div style="max-height:168px;overflow-y:auto;">
      <?php foreach ($unsigned_certs as $c): ?>
      <form method="POST" action="/admin/sign-document.php" style="margin:0 0 .28rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="doc_id" value="<?= (int)$c['id'] ?>">
        <button type="submit"
           style="width:100%;display:flex;align-items:center;gap:.45rem;padding:.35rem .5rem;border-radius:4px;font-size:.76rem;background:#fff;border:1px solid var(--border);color:inherit;cursor:pointer;font-family:inherit;text-align:left;"
           onmouseover="this.style.background='var(--warm-grey)'" onmouseout="this.style.background='#fff'">
          <span style="width:6px;height:6px;border-radius:50%;flex-shrink:0;background:#c0392b;"></span>
          <span style="font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($c['formatted_number']) ?></span>
          <span style="color:var(--text-muted);font-size:.68rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:8rem;"><?= h($c['customer_name']) ?></span>
          <span style="font-size:.68rem;color:var(--teal);font-weight:600;flex-shrink:0;">Подпиши →</span>
        </button>
      </form>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($can_shop): ?>
  <!-- Paid donations without a cert -->
  <div style="flex:1;min-width:0;background:#fff;border:1px solid var(--border);border-radius:8px;padding:.85rem 1rem;">
    <div style="display:flex;align-items:center;gap:.4rem;margin-bottom:.6rem;">
      <h3 style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin:0;flex:1;">Дарения без сертификат</h3>
      <?php if ($uncerted_donations): ?>
      <span style="display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:10px;font-size:.67rem;font-weight:700;color:#fff;background:var(--teal);"><?= count($uncerted_donations) ?></span>
      <?php endif; ?>
    </div>
    <?php if (!$uncerted_donations): ?>
      <p style="font-size:.78rem;color:var(--text-muted);font-style:italic;margin:0;">Всички дарения имат сертификат.</p>
    <?php else: ?>
      <div style="max-height:168px;overflow-y:auto;">
      <?php foreach ($uncerted_donations as $don): ?>
      <a href="/admin/order-view.php?id=<?= (int)$don['id'] ?>"
         style="display:flex;align-items:center;gap:.45rem;padding:.35rem .5rem;border-radius:4px;font-size:.76rem;background:#fff;margin-bottom:.28rem;border:1px solid var(--border);text-decoration:none;color:inherit;"
         onmouseover="this.style.background='var(--warm-grey)'" onmouseout="this.style.background='#fff'">
        <span style="width:6px;height:6px;border-radius:50%;flex-shrink:0;background:var(--teal);"></span>
        <span style="font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($don['order_number']) ?></span>
        <span style="color:var(--text-muted);font-size:.68rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:8rem;"><?= h($don['customer_name']) ?> · <?= format_eur((float)$don['total_eur']) ?></span>
        <span style="font-size:.68rem;color:var(--teal);font-weight:600;flex-shrink:0;">Генерирай →</span>
      </a>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Physical orders + pledges awaiting shipping -->
  <div style="flex:1;min-width:0;background:#fff;border:1px solid var(--border);border-radius:8px;padding:.85rem 1rem;">
    <div style="display:flex;align-items:center;gap:.4rem;margin-bottom:.6rem;">
      <h3 style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin:0;flex:1;">За изпращане</h3>
      <?php if ($shipping_items): ?>
      <span style="display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:10px;font-size:.67rem;font-weight:700;color:#fff;background:var(--amber);"><?= count($shipping_items) ?></span>
      <?php endif; ?>
    </div>
    <?php if (!$shipping_items): ?>
      <p style="font-size:.78rem;color:var(--text-muted);font-style:italic;margin:0;">Няма неизпратени поръчки.</p>
    <?php else: ?>
      <div style="max-height:168px;overflow-y:auto;">
      <?php foreach ($shipping_items as $s):
        $dot = $s['kind'] === 'pledge' ? 'var(--green-dark)' : 'var(--amber)';
      ?>
      <a href="<?= h($s['link']) ?>"
         style="display:flex;align-items:center;gap:.45rem;padding:.35rem .5rem;border-radius:4px;font-size:.76rem;background:#fff;margin-bottom:.28rem;border:1px solid var(--border);text-decoration:none;color:inherit;"
         onmouseover="this.style.background='var(--warm-grey)'" onmouseout="this.style.background='#fff'">
        <span style="width:6px;height:6px;border-radius:50%;flex-shrink:0;background:<?= $dot ?>;"></span>
        <span style="font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($s['number']) ?></span>
        <span style="color:var(--text-muted);font-size:.68rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:9rem;"><?= h($s['name']) ?> · <?= h($s['meta']) ?></span>
        <span style="font-size:.68rem;color:var(--teal);font-weight:600;flex-shrink:0;">Виж →</span>
      </a>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>
<?php endif; ?>

<!-- Calendar: 4 weeks -->
<div style="background:#fff;border:1px solid var(--border);border-radius:8px;padding:.85rem 1rem;">
  <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.75rem;">
    <h3 style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin:0;">Планирано съдържание</h3>
    <span style="font-size:.72rem;color:var(--text-muted);">
      <?php
      $first_dt = new DateTimeImmutable($cal_dates[0],  $tz);
      $last_dt  = new DateTimeImmutable($cal_dates[27], $tz);
      $range    = $first_dt->format('j') . ' ' . $bg_months_short[(int)$first_dt->format('n')];
      if ($first_dt->format('Y') !== $last_dt->format('Y')) {
          $range .= ' ' . $first_dt->format('Y');
      }
      $range .= ' — ' . $last_dt->format('j') . ' ' . $bg_months_short[(int)$last_dt->format('n')] . ' ' . $last_dt->format('Y');
      echo h($range);
      ?>
    </span>
  </div>
  <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:.4rem;">
    <!-- Day-of-week header row -->
    <?php foreach (['Пн','Вт','Ср','Чт','Пт','Сб','Нд'] as $dow_hdr): ?>
    <div style="text-align:center;font-size:.67rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;padding-bottom:.3rem;"><?= $dow_hdr ?></div>
    <?php endforeach; ?>
    <!-- Day cells -->
    <?php foreach ($cal_dates as $date_str):
      $cell_dt  = new DateTimeImmutable($date_str, $tz);
      $day_num  = (int)$cell_dt->format('j');
      $is_today = ($date_str === $today_str);
      $events   = $calendar[$date_str];
    ?>
    <div style="background:<?= $is_today ? '#f0f9fc' : 'var(--off-white)' ?>;border:1px solid <?= $is_today ? 'var(--teal)' : 'var(--border)' ?>;border-radius:6px;padding:.4rem .35rem;min-height:58px;display:flex;flex-direction:column;gap:.2rem;">
      <div style="font-size:.68rem;font-weight:700;color:<?= $is_today ? 'var(--teal)' : 'var(--text-muted)' ?>;margin-bottom:.1rem;"><?= $day_num ?><?= $is_today ? ' ●' : '' ?></div>
      <div style="display:flex;flex-wrap:wrap;gap:2px;">
        <?php foreach ($events as $ev): ?>
        <button
          onclick="dashPopShow(this)"
          data-ch="<?= h($ev['ch']) ?>"
          data-title="<?= h($ev['title']) ?>"
          data-time="<?= h($ev['time']) ?>"
          data-slug="<?= h($ev['slug']) ?>"
          style="<?= $ch_colors[$ev['ch']] ?? '' ?>display:inline-flex;align-items:center;padding:2px 5px;border-radius:3px;font-size:.62rem;font-weight:700;cursor:pointer;border:none;font-family:inherit;line-height:1.4;"
          onmouseover="this.style.opacity='.75'" onmouseout="this.style.opacity='1'"
        ><?= h($ch_labels[$ev['ch']] ?? $ev['ch']) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Popup overlay (transparent, closes popup on click outside) -->
<div id="dashPopOverlay" onclick="dashPopClose()"
     style="display:none;position:fixed;inset:0;z-index:999;"></div>

<!-- Popup card -->
<div id="dashPop"
     style="display:none;position:fixed;z-index:1000;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,.15);padding:.9rem 1rem 1rem;min-width:210px;max-width:290px;">
  <button onclick="dashPopClose()"
          style="position:absolute;top:.45rem;right:.6rem;background:none;border:none;cursor:pointer;font-size:.9rem;color:var(--text-muted);line-height:1;padding:0;">✕</button>
  <div id="dashPopCh"    style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:.7rem;font-weight:700;margin-bottom:.5rem;"></div>
  <div id="dashPopTitle" style="font-weight:700;color:var(--text);margin-bottom:.3rem;font-size:.85rem;line-height:1.35;"></div>
  <div id="dashPopTime"  style="color:var(--text-muted);font-size:.75rem;margin-bottom:.55rem;"></div>
  <a id="dashPopLink" href="#"
     style="font-size:.75rem;color:var(--teal);font-weight:600;text-decoration:none;">Редактирай статията →</a>
</div>

<script>
(function () {
  var chStyle = {
    web: 'background:#e4f0f5;color:#0387A5;',
    fb:  'background:#e8f0fe;color:#1877f2;',
    ig:  'background:#fce4ec;color:#c2185b;',
    li:  'background:#e3f2fd;color:#0a66c2;'
  };
  var chLabel = { web: 'Сайт', fb: 'Facebook', ig: 'Instagram', li: 'LinkedIn' };

  window.dashPopShow = function (btn) {
    var ch    = btn.dataset.ch;
    var title = btn.dataset.title;
    var time  = btn.dataset.time;
    var slug  = btn.dataset.slug;

    var chEl = document.getElementById('dashPopCh');
    chEl.textContent = chLabel[ch] || ch;
    chEl.setAttribute('style',
      (chStyle[ch] || '') +
      'display:inline-block;padding:2px 8px;border-radius:3px;font-size:.7rem;font-weight:700;margin-bottom:.5rem;'
    );
    document.getElementById('dashPopTitle').textContent = title;
    document.getElementById('dashPopTime').textContent  = '🕐 ' + time;
    document.getElementById('dashPopLink').href =
      '/admin/article-edit.php?slug=' + encodeURIComponent(slug);

    var rect = btn.getBoundingClientRect();
    var pop  = document.getElementById('dashPop');
    pop.style.display = 'block';
    pop.style.top  = (rect.bottom + 6) + 'px';
    pop.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 298)) + 'px';
    document.getElementById('dashPopOverlay').style.display = 'block';
  };

  window.dashPopClose = function () {
    document.getElementById('dashPop').style.display = 'none';
    document.getElementById('dashPopOverlay').style.display = 'none';
  };
})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>

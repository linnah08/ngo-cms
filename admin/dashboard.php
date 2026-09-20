<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/articles.php';

$page_title_admin = 'Начало';
$active_nav       = 'dashboard';

$can_sign      = admin_can_sign();
$can_shop      = admin_can_manage_shop();
$can_editorial = admin_can_editorial();
$pdo           = get_pdo();

// ── Mail transport check: warn admins when no way to send email is configured ──
$mail_not_configured = false;
if (admin_is_admin()) {
    try { $mail_not_configured = mail_transport() === 'none'; } catch (Throwable $e) { error_log('dashboard mail_transport: ' . $e->getMessage()); }
}

// Scheduled jobs that this site needs but that aren't running (admins only — they fix it in cPanel).
$job_problems = [];
if (admin_is_admin()) {
    try {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/scheduled_jobs.php';
        $job_problems = scheduled_jobs_problems();
    } catch (Throwable $e) { error_log('dashboard scheduled_jobs: ' . $e->getMessage()); }
}

// ── Unified moderation inbox: pending comments, reviews & new contacts ──
$inbox = [];
if ($can_editorial) {
    try {
        foreach ($pdo->query("SELECT author_name, content, created_at FROM comments WHERE status='pending' ORDER BY created_at DESC")->fetchAll() as $c) {
            $inbox[] = [
                'kind' => 'comment', 'icon' => '💬', 'label' => 'Коментар',
                'who' => $c['author_name'], 'context' => '',
                'snippet' => $c['content'], 'date' => $c['created_at'],
                'link' => '/admin/comments.php?source=comments&filter=pending',
            ];
        }
    } catch (Throwable $e) { /* table may not exist */ }
    try {
        foreach ($pdo->query("SELECT name, message, created_at FROM contact_submissions WHERE status='new' ORDER BY created_at DESC")->fetchAll() as $m) {
            $inbox[] = [
                'kind' => 'contact', 'icon' => '✉', 'label' => 'Контакт',
                'who' => $m['name'], 'context' => '',
                'snippet' => $m['message'], 'date' => $m['created_at'],
                'link' => '/admin/comments.php?source=contacts&cfilter=new',
            ];
        }
    } catch (Throwable $e) { /* table may not exist */ }
}
if ($can_shop) {
    try {
        foreach ($pdo->query("SELECT r.author_name, r.content, r.created_at, r.product_id, p.name_bg AS product_name
                              FROM product_reviews r LEFT JOIN products p ON p.id = r.product_id
                              WHERE r.status='pending' ORDER BY r.created_at DESC")->fetchAll() as $r) {
            $inbox[] = [
                'kind' => 'review', 'icon' => '⭐', 'label' => 'Отзив',
                'who' => $r['author_name'], 'context' => $r['product_name'] ?? ('#' . (int)$r['product_id']),
                'snippet' => $r['content'], 'date' => $r['created_at'],
                'link' => '/admin/product-reviews.php?product_id=' . (int)$r['product_id'] . '&filter=pending',
            ];
        }
    } catch (Throwable $e) { /* table may not exist */ }
}
usort($inbox, fn($a, $b) => strcmp($b['date'], $a['date'])); // newest first

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
// Online payments (card / IRIS) only count once paid — an unpaid order must not
// be shipped; it shows as "Неплатена" in Поръчки instead.
$shipping_items = [];
if ($can_shop) {
    $stmt = $pdo->query(
        "SELECT id, order_number AS number, customer_name AS name,
                items, created_at, status, 'order' AS kind
         FROM orders
         WHERE type = 'physical'
           AND status IN ('new', 'confirmed')
           AND delivery_type IN ('office','apt','address','locker')
           AND (payment_status = 'paid' OR COALESCE(payment_method, '') NOT IN ('card', 'iris'))
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
    // Gated at the collection step, not at the card: the "За изпращане" card is
    // shared with physical orders, so skipping the card itself would hide those
    // too. With no pledges collected the card simply renders orders only.
    if (feature_enabled('campaign')) {
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
        if (article_is_scheduled($a, @filemtime($file) ?: null)) {
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

// Relative time in Bulgarian for the inbox.
if (!function_exists('dash_time_ago')) {
    function dash_time_ago(string $datetime): string {
        $ts = strtotime($datetime);
        if ($ts === false) return '';
        $diff = time() - $ts;
        if ($diff < 60)     return 'току-що';
        if ($diff < 3600)   return 'преди ' . (int)($diff / 60) . ' мин';
        if ($diff < 86400)  return 'преди ' . (int)($diff / 3600) . ' ч';
        if ($diff < 172800) return 'вчера';
        if ($diff < 604800) return 'преди ' . (int)($diff / 86400) . ' дни';
        return date('d.m.Y', $ts);
    }
}
// Single-line snippet from possibly-HTML text.
if (!function_exists('dash_snippet')) {
    function dash_snippet(string $text, int $len = 60): string {
        $t = trim(preg_replace('/\s+/', ' ', strip_tags($text)));
        return mb_strimwidth($t, 0, $len, '…');
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1>Добре дошли, <?= h($current_user['name'] ?? 'Admin') ?></h1>
</div>

<?php if ($mail_not_configured): ?>
<div role="alert" style="display:flex;flex-wrap:wrap;align-items:center;gap:.75rem 1rem;background:#fdf0ef;border:1px solid #f0c4c0;border-left:4px solid #c0392b;border-radius:8px;padding:.9rem 1.1rem;margin-bottom:.75rem;color:#7a2318;">
  <div style="flex:1 1 320px;min-width:0;font-size:.9rem;line-height:1.5;">
    <strong style="display:block;color:#c0392b;margin-bottom:.15rem;">Сайтът не изпраща имейли</strong>
    Писмата за забравена парола, потвържденията на поръчки и съобщенията от формата за контакт не достигат до никого,
    защото не е настроен имейл сървър.
  </div>
  <a href="/admin/email-settings.php" style="flex:0 0 auto;display:inline-block;background:#c0392b;color:#fff;text-decoration:none;font-weight:600;font-size:.85rem;padding:.55rem 1rem;border-radius:6px;">Настрой имейла</a>
</div>
<?php endif; ?>

<?php if ($job_problems): ?>
<div role="alert" style="display:flex;flex-wrap:wrap;align-items:center;gap:.75rem 1rem;background:#fdf0ef;border:1px solid #f0c4c0;border-left:4px solid #c0392b;border-radius:8px;padding:.9rem 1.1rem;margin-bottom:.75rem;color:#7a2318;">
  <div style="flex:1 1 320px;min-width:0;font-size:.9rem;line-height:1.5;">
    <strong style="display:block;color:#c0392b;margin-bottom:.15rem;">
      <?= count($job_problems) === 1 ? 'Една автоматична задача не работи' : count($job_problems) . ' автоматични задачи не работят' ?>
    </strong>
    <ul style="margin:.2rem 0 0;padding-left:1.1rem;">
      <?php foreach ($job_problems as $jk => $p): ?>
        <li>
          <a href="/admin/scheduled-jobs.php#job-<?= h($jk) ?>" style="color:inherit;font-weight:600;"><?= h($p['job']['label']) ?></a> —
          <?= $p['status'] === 'late'
              ? 'спряла, последно ' . h(scheduled_jobs_ago((int) $p['last'], time()))
              : 'не е настроена' ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <a href="/admin/scheduled-jobs.php" style="flex:0 0 auto;display:inline-block;background:#c0392b;color:#fff;text-decoration:none;font-weight:600;font-size:.85rem;padding:.55rem 1rem;border-radius:6px;">Как да <?= count($job_problems) === 1 ? 'я' : 'ги' ?> настроя</a>
</div>
<?php endif; ?>

<?php if ($can_editorial || $can_shop): ?>
<?php
  $kind_pill = [
      'review'  => 'background:#fff4e0;color:#b8860b;',
      'comment' => 'background:#e8f0fe;color:#1877f2;',
      'contact' => 'background:#e6f4ea;color:#2d6a35;',
  ];
?>
<div style="background:#fff;border:1px solid var(--border);border-radius:8px;padding:.85rem 1rem;margin-bottom:.75rem;">
  <div style="display:flex;align-items:center;gap:.4rem;margin-bottom:.6rem;">
    <h3 style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin:0;flex:1;">Нуждае се от внимание</h3>
    <?php if ($inbox): ?>
    <span style="display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:10px;font-size:.67rem;font-weight:700;color:#fff;background:#c0392b;"><?= count($inbox) ?></span>
    <?php endif; ?>
  </div>
  <?php if (!$inbox): ?>
    <p style="font-size:.78rem;color:var(--text-muted);font-style:italic;margin:0;">Няма нищо за преглед.</p>
  <?php else: ?>
    <div style="max-height:240px;overflow-y:auto;">
    <?php foreach ($inbox as $it): ?>
      <a href="<?= h($it['link']) ?>"
         style="display:flex;align-items:center;gap:.5rem;padding:.4rem .5rem;border-radius:4px;font-size:.78rem;background:#fff;margin-bottom:.28rem;border:1px solid var(--border);text-decoration:none;color:inherit;"
         onmouseover="this.style.background='var(--warm-grey)'" onmouseout="this.style.background='#fff'">
        <span style="<?= $kind_pill[$it['kind']] ?? '' ?>display:inline-flex;align-items:center;gap:.25rem;padding:.1rem .45rem;border-radius:3px;font-size:.68rem;font-weight:700;flex-shrink:0;white-space:nowrap;"><?= $it['icon'] ?> <?= h($it['label']) ?></span>
        <span style="font-weight:600;flex-shrink:0;max-width:11rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($it['who']) ?><?php if ($it['context'] !== ''): ?> · <?= h($it['context']) ?><?php endif; ?></span>
        <span style="color:var(--text-muted);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h(dash_snippet($it['snippet'])) ?></span>
        <span style="color:var(--text-muted);font-size:.68rem;white-space:nowrap;flex-shrink:0;"><?= h(dash_time_ago($it['date'])) ?></span>
        <span style="font-size:.68rem;color:var(--teal);font-weight:600;flex-shrink:0;">Виж →</span>
      </a>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

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

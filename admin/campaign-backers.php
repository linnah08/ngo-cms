<?php
$page_title_admin = 'Поддръжници на кампанията';
$active_nav       = 'campaign';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DocumentGenerator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/TicketGenerator.php';
admin_require_admin();

$_tinymce_key    = setting_get('tinymce_api_key', 'no-api-key');
$page_head_extra = '<script src="https://cdn.tiny.cloud/1/' . h($_tinymce_key) . '/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>';

$pdo   = get_pdo();
$flash = [];

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $action = $_POST['action'] ?? '';

    // Toggle reward_shipped
    if ($action === 'toggle_shipped') {
        $id       = (int)($_POST['pledge_id'] ?? 0);
        $shipped  = (int)($_POST['shipped']   ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE campaign_pledges SET reward_shipped=? WHERE id=?")
                ->execute([$shipped ? 0 : 1, $id]);
        }
        header('Location: /admin/campaign-backers.php?shipped_ok=1');
        exit;
    }

    // Resend ticket email
    if ($action === 'resend_ticket') {
        $id = (int)($_POST['pledge_id'] ?? 0);
        $pledge = $id ? $pdo->prepare('SELECT * FROM campaign_pledges WHERE id=? AND pledge_type=\'ticket\''): null;
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM campaign_pledges WHERE id=? AND pledge_type='ticket'");
            $stmt->execute([$id]);
            $pledge = $stmt->fetch();
        }
        if ($pledge && $pledge['payment_status'] === 'paid') {
            // Regenerate ticket PDF if missing
            if (empty($pledge['ticket_code']) || empty($pledge['ticket_path']) || !file_exists($_SERVER['DOCUMENT_ROOT'] . ($pledge['ticket_path'] ?? ''))) {
                $ticket_code = 'TKT-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $year        = date('Y');
                $dir         = $_SERVER['DOCUMENT_ROOT'] . "/documents/tickets/{$year}/";
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = $ticket_code . '_' . $pledge['pledge_number'] . '.pdf';
                $filepath = $dir . $filename;
                $order_row = [
                    'name'          => $pledge['name'],
                    'email'         => $pledge['email'],
                    'pledge_number' => $pledge['pledge_number'],
                    'amount_eur'    => (float)$pledge['amount_eur'],
                    'created_at'    => $pledge['created_at'],
                ];
                $doc_row = [
                    'ticket_code'  => $ticket_code,
                    'event_name'   => setting_get('event_name',  'Събитие'),
                    'event_date'   => setting_get('event_date',  ''),
                    'event_time'   => setting_get('event_time',  ''),
                    'event_place'  => setting_get('event_place', ''),
                ];
                $pdf_bytes = (new TicketGenerator())->generate($order_row, [], $doc_row);
                file_put_contents($filepath, $pdf_bytes);
                $rel_path = "/documents/tickets/{$year}/{$filename}";
                $pdo->prepare("UPDATE campaign_pledges SET ticket_code=?, ticket_path=? WHERE id=?")
                    ->execute([$ticket_code, $rel_path, $pledge['id']]);
                $pledge['ticket_code'] = $ticket_code;
                $pledge['ticket_path'] = $rel_path;
            }
            // Send email
            $attachments = [];
            $ticket_file = $_SERVER['DOCUMENT_ROOT'] . $pledge['ticket_path'];
            if (file_exists($ticket_file)) {
                $attachments[] = ['path' => $ticket_file, 'name' => 'ticket-' . $pledge['pledge_number'] . '.pdf'];
            }
            $ok = send_mail(
                $pledge['email'],
                'Твоят билет за ' . setting_get('event_name', 'събитието') . ' — ' . $pledge['pledge_number'],
                render_email('campaign-ticket', ['pledge' => $pledge]),
                '',
                $attachments
            );
            flash_set($ok ? 'success' : 'error', $ok ? 'Билетът е изпратен отново.' : 'Грешка при изпращане.');
        } else {
            flash_set('error', 'Билетът не е намерен или не е платен.');
        }
        header('Location: /admin/campaign-backers.php?tab=tickets');
        exit;
    }

    // Send newsletter to all paid backers
    if ($action === 'newsletter') {
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body']    ?? '');
        if ($subject === '' || $body === '') {
            flash_set('error', 'Темата и текстът са задължителни.');
        } else {
            $backers = $pdo->query("SELECT name, email FROM campaign_pledges WHERE payment_status='paid'")->fetchAll();
            $sent    = 0;
            // Body comes from TinyMCE — already HTML, use directly
            foreach ($backers as $b) {
                $html = render_email('campaign-newsletter', [
                    'name'    => $b['name'],
                    'subject' => $subject,
                    'body'    => $body,
                ]);
                if (send_mail($b['email'], $subject, $html)) $sent++;
            }
            flash_set('success', "Изпратено до {$sent} поддръжника.");
        }
        header('Location: /admin/campaign-backers.php');
        exit;
    }
}

// ── CSV export ────────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $rows = $pdo->query("
        SELECT p.pledge_number, p.name, p.email, p.amount_eur, p.payment_status,
               r.title AS reward_title, p.delivery_address,
               p.reward_shipped, p.created_at
        FROM campaign_pledges p
        LEFT JOIN campaign_rewards r ON r.id = p.reward_id
        ORDER BY p.created_at DESC
    ")->fetchAll();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="campaign-backers-' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Номер','Име','Email','Сума EUR','Статус','Награда','Адрес','Изпратена','Дата']);
    foreach ($rows as $r) {
        $addr = $r['delivery_address'] ? json_decode($r['delivery_address'], true) : [];
        $addr_str = $addr
            ? implode(', ', array_filter([
                $addr['address'] ?? '', $addr['city'] ?? '', $addr['postcode'] ?? '', $addr['phone'] ?? ''
              ]))
            : '';
        fputcsv($out, [
            $r['pledge_number'],
            $r['name'],
            $r['email'],
            number_format($r['amount_eur'], 2, '.', ''),
            $r['payment_status'],
            $r['reward_title'] ?? '—',
            $addr_str,
            $r['reward_shipped'] ? 'Да' : 'Не',
            substr($r['created_at'], 0, 10),
        ]);
    }
    fclose($out);
    exit;
}

// ── Load backers ──────────────────────────────────────────────────────────────
$tab    = in_array($_GET['tab'] ?? '', ['tickets'], true) ? $_GET['tab'] : 'donations';
$filter = $_GET['status'] ?? 'paid';
$valid_statuses = ['paid','pending','failed','all'];
$filter = in_array($filter, $valid_statuses, true) ? $filter : 'paid';

$pledge_type_clause = $tab === 'tickets'
    ? "AND p.pledge_type = 'ticket'"
    : "AND (p.pledge_type = 'donation' OR p.pledge_type IS NULL)";
$status_clause = $filter !== 'all'
    ? "AND p.payment_status = " . $pdo->quote($filter)
    : '';

$backers = $pdo->query("
    SELECT p.*, r.title AS reward_title
    FROM campaign_pledges p
    LEFT JOIN campaign_rewards r ON r.id = p.reward_id
    WHERE 1=1 {$pledge_type_clause} {$status_clause}
    ORDER BY p.created_at DESC
")->fetchAll();

$ticket_stats = $pdo->query("
    SELECT COUNT(*) AS n, COALESCE(SUM(amount_eur),0) AS total
    FROM campaign_pledges WHERE pledge_type='ticket' AND payment_status='paid'
")->fetch();

$stats = $pdo->query("
    SELECT payment_status, COUNT(*) AS n, COALESCE(SUM(amount_eur),0) AS total
    FROM campaign_pledges GROUP BY payment_status
")->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_UNIQUE);

$flash = flash_get();

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<?php foreach ($flash as $f): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;
  <?= $f['type']==='success' ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;' : 'background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;' ?>">
  <?= h($f['message']) ?>
</div>
<?php endforeach; ?>

<?php if (isset($_GET['shipped_ok'])): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;">Статусът е обновен.</div>
<?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem;">
  <h1 style="margin:0;font-size:1.4rem;">Поддръжници</h1>
  <div style="display:flex;gap:.75rem;">
    <a href="/admin/campaign-backers.php?export=csv" class="btn btn--secondary" style="font-size:.85rem;">⬇ Изтегли CSV</a>
    <a href="/admin/campaign.php" class="btn btn--secondary" style="font-size:.85rem;">← Настройки</a>
  </div>
</div>

<!-- Stats -->
<div style="display:flex;gap:.75rem;margin-bottom:1.5rem;flex-wrap:wrap;">
  <?php
    $paid_total = (float)($stats['paid']['total'] ?? 0);
    $paid_n     = (int)($stats['paid']['n']     ?? 0);
  ?>
  <div style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:.85rem 1.1rem;min-width:160px;">
    <div style="font-size:.72rem;text-transform:uppercase;color:#9b9590;margin-bottom:.2rem;">Платени</div>
    <div style="font-size:1.25rem;font-weight:700;color:#1b998b;"><?= $paid_n ?> бр.</div>
    <div style="font-size:.82rem;color:#6b6560;"><?= number_format($paid_total, 2, '.', ' ') ?> EUR &nbsp;·&nbsp; <?= number_format($paid_total * EUR_BGN_RATE, 0, '.', ' ') ?> лв</div>
  </div>
  <?php foreach (['pending'=>'Чакащи','failed'=>'Неуспешни'] as $st => $lbl): ?>
  <div style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:.85rem 1.1rem;min-width:120px;">
    <div style="font-size:.72rem;text-transform:uppercase;color:#9b9590;margin-bottom:.2rem;"><?= $lbl ?></div>
    <div style="font-size:1.25rem;font-weight:700;"><?= (int)($stats[$st]['n'] ?? 0) ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Ticket stats card -->
<?php if ((int)($ticket_stats['n'] ?? 0) > 0): ?>
<div style="display:flex;gap:.75rem;margin-bottom:1.25rem;flex-wrap:wrap;">
  <div style="background:#e4f0f5;border:1px solid #b2dbd7;border-radius:8px;padding:.85rem 1.1rem;min-width:200px;">
    <div style="font-size:.72rem;text-transform:uppercase;color:#2d5a60;margin-bottom:.2rem;">Билети продадени</div>
    <div style="font-size:1.25rem;font-weight:700;color:#0387A5;"><?= (int)$ticket_stats['n'] ?> бр.</div>
    <div style="font-size:.82rem;color:#2d5a60;"><?= number_format((float)$ticket_stats['total'], 2, '.', ' ') ?> EUR</div>
  </div>
</div>
<?php endif; ?>

<!-- Type + status tabs -->
<div style="display:flex;gap:.5rem;margin-bottom:.75rem;flex-wrap:wrap;">
  <a href="?tab=donations&status=<?= h($filter) ?>" style="padding:.4rem .9rem;border-radius:20px;font-size:.85rem;text-decoration:none;font-weight:600;
    <?= $tab === 'donations' ? 'background:#1b998b;color:#fff;' : 'background:#f0ede9;color:#444;' ?>">Дарения</a>
  <a href="?tab=tickets&status=<?= h($filter) ?>" style="padding:.4rem .9rem;border-radius:20px;font-size:.85rem;text-decoration:none;font-weight:600;
    <?= $tab === 'tickets' ? 'background:#0387A5;color:#fff;' : 'background:#f0ede9;color:#444;' ?>">Билети</a>
</div>
<div style="display:flex;gap:.5rem;margin-bottom:1.25rem;">
  <?php foreach (['paid'=>'Платени','pending'=>'Чакащи','failed'=>'Неуспешни','all'=>'Всички'] as $s => $lbl): ?>
  <a href="?tab=<?= h($tab) ?>&status=<?= $s ?>" style="padding:.4rem .9rem;border-radius:20px;font-size:.85rem;text-decoration:none;
    <?= $filter === $s ? 'background:#555;color:#fff;' : 'background:#f0ede9;color:#444;' ?>">
    <?= $lbl ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Backers table -->
<div style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;overflow:hidden;margin-bottom:2rem;">
  <?php if (empty($backers)): ?>
  <div style="padding:2rem;text-align:center;color:#9b9590;">Няма записи.</div>
  <?php else: ?>
  <table style="width:100%;border-collapse:collapse;">
    <thead style="background:#f8f6f2;">
      <tr style="font-size:.78rem;text-transform:uppercase;color:#6b6560;">
        <th style="padding:.65rem 1rem;text-align:left;font-weight:600;">Дата</th>
        <th style="padding:.65rem 1rem;text-align:left;font-weight:600;">Номер</th>
        <th style="padding:.65rem 1rem;text-align:left;font-weight:600;">Поддръжник</th>
        <th style="padding:.65rem 1rem;text-align:right;font-weight:600;">Сума</th>
        <?php if ($tab === 'donations'): ?>
        <th style="padding:.65rem 1rem;text-align:left;font-weight:600;">Награда</th>
        <th style="padding:.65rem 1rem;text-align:center;font-weight:600;">Изпратена</th>
        <?php else: ?>
        <th style="padding:.65rem 1rem;text-align:left;font-weight:600;">Код за вход</th>
        <th style="padding:.65rem 1rem;text-align:center;font-weight:600;">Действия</th>
        <?php endif; ?>
        <th style="padding:.65rem 1rem;text-align:center;font-weight:600;">Статус</th>
        <?php if ($tab === 'donations'): ?><th style="padding:.65rem 1rem;"></th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($backers as $b): ?>
      <?php
        $addr = $b['delivery_address'] ? json_decode($b['delivery_address'], true) : null;
        $status_colors = [
          'paid'    => '#2d6a35',
          'pending' => '#b5870a',
          'failed'  => '#c0392b',
          'reversed'=> '#666',
        ];
        $status_labels = ['paid'=>'Платено','pending'=>'Чакащо','failed'=>'Неуспешно','reversed'=>'Върнато'];
      ?>
      <tr style="border-top:1px solid #f0ede9;" <?= $addr ? 'class="has-addr"' : '' ?>>
        <td style="padding:.65rem 1rem;font-size:.82rem;color:#6b6560;"><?= substr($b['created_at'],0,10) ?></td>
        <td style="padding:.65rem 1rem;font-size:.8rem;font-family:monospace;color:#6b6560;"><?= h($b['pledge_number']) ?></td>
        <td style="padding:.65rem 1rem;">
          <div style="font-weight:600;font-size:.9rem;"><?= h($b['name']) ?></div>
          <div style="font-size:.8rem;color:#6b6560;"><?= h($b['email']) ?></div>
          <?php if ($addr): ?>
          <div style="font-size:.78rem;color:#9b9590;margin-top:.2rem;">
            <?= h(implode(', ', array_filter([$addr['address']??'', $addr['city']??'', $addr['postcode']??'']))) ?>
            <?php if (!empty($addr['phone'])): ?> · <?= h($addr['phone']) ?><?php endif; ?>
          </div>
          <?php endif; ?>
        </td>
        <td style="padding:.65rem 1rem;text-align:right;font-weight:600;">
          <?= number_format($b['amount_eur'], 2) ?> EUR
          <div style="font-size:.75rem;color:#9b9590;"><?= number_format($b['amount_eur'] * EUR_BGN_RATE, 2, '.', ' ') ?> лв</div>
        </td>
        <?php if ($tab === 'donations'): ?>
        <td style="padding:.65rem 1rem;font-size:.85rem;"><?= $b['reward_title'] ? h($b['reward_title']) : '—' ?></td>
        <td style="padding:.65rem 1rem;text-align:center;">
          <?php if ($b['reward_id']): ?>
          <form method="POST" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_shipped">
            <input type="hidden" name="pledge_id" value="<?= $b['id'] ?>">
            <input type="hidden" name="shipped" value="<?= $b['reward_shipped'] ?>">
            <button type="submit" style="background:none;border:none;cursor:pointer;font-size:1.1rem;"
                    title="<?= $b['reward_shipped'] ? 'Маркирай като неизпратена' : 'Маркирай като изпратена' ?>">
              <?= $b['reward_shipped'] ? '✅' : '⬜' ?>
            </button>
          </form>
          <?php else: ?>—<?php endif; ?>
        </td>
        <?php else: ?>
        <td style="padding:.65rem 1rem;">
          <?php if ($b['ticket_code']): ?>
          <span style="font-family:monospace;font-size:.82rem;color:#0387A5;font-weight:700;"><?= h($b['ticket_code']) ?></span>
          <?php else: ?>
          <span style="color:#9b9590;font-size:.82rem;">—</span>
          <?php endif; ?>
        </td>
        <td style="padding:.65rem 1rem;text-align:center;">
          <?php if ($b['payment_status'] === 'paid'): ?>
          <form method="POST" style="display:inline;"
                data-confirm="Изпрати билета отново до <?= h($b['email']) ?>?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="resend_ticket">
            <input type="hidden" name="pledge_id" value="<?= $b['id'] ?>">
            <button type="submit" class="btn-link" title="Изпрати отново">Изпрати билет</button>
          </form>
          <?php else: ?>—<?php endif; ?>
        </td>
        <?php endif; ?>
        <td style="padding:.65rem 1rem;text-align:center;">
          <span style="display:inline-block;padding:.2rem .6rem;border-radius:12px;font-size:.75rem;font-weight:600;
            color:<?= $status_colors[$b['payment_status']] ?? '#666' ?>;
            background:<?= $b['payment_status']==='paid' ? '#e6f4ea' : ($b['payment_status']==='pending' ? '#fff8e6' : '#fdf0ef') ?>;">
            <?= $status_labels[$b['payment_status']] ?? h($b['payment_status']) ?>
          </span>
        </td>
        <?php if ($tab === 'donations'): ?>
        <td style="padding:.65rem 1rem;text-align:right;">
          <a href="/admin/pledge-view.php?id=<?= (int)$b['id'] ?>"
             style="font-size:.82rem;color:#0387A5;text-decoration:none;font-weight:600;">Виж →</a>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- Newsletter to all paid backers -->
<div style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:1.5rem;">
  <h2 style="margin:0 0 1.25rem;font-size:1.05rem;">Изпрати съобщение до всички платили поддръжници</h2>
  <form method="POST" id="newsletterForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="newsletter">
    <div style="margin-bottom:.9rem;">
      <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.35rem;">Тема</label>
      <input type="text" name="subject"
             style="width:100%;padding:.55rem .7rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;box-sizing:border-box;">
    </div>
    <div style="margin-bottom:1rem;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.35rem;">
        <label style="font-size:.85rem;font-weight:600;">Текст (ще се покаже в имейл шаблона)</label>
        <button type="button" id="insertImageBtn" class="btn btn--outline" style="font-size:.78rem;padding:.25rem .6rem;">
          + Снимка от библиотека
        </button>
      </div>
      <textarea name="body" id="newsletterBody" rows="10"></textarea>
    </div>
    <button type="button" class="btn btn--primary" id="sendNewsletterBtn">
      Изпрати до <?= $paid_n ?> поддръжника
    </button>
  </form>
</div>

<script>
tinymce.init(Object.assign({}, window._tinyBase, {
  selector: '#newsletterBody',
  min_height: 280,
  file_picker_types: '',
}));

document.getElementById('insertImageBtn').addEventListener('click', function () {
  openMediaPicker(function (path) {
    var ed = tinymce.get('newsletterBody');
    if (ed) {
      ed.insertContent('<img src="' + path.replace(/"/g, '&quot;') + '" alt="" style="max-width:100%;height:auto;">');
    }
  });
});

document.getElementById('sendNewsletterBtn').addEventListener('click', function () {
  // Sync TinyMCE before submit
  tinymce.triggerSave();
  _adminConfirm('Сигурна ли си? Ще се изпрати до <?= $paid_n ?> поддръжника.', 'Изпрати').then(function (ok) {
    if (ok) document.getElementById('newsletterForm').submit();
  });
});
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>

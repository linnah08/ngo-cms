<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';

admin_require_admin();

$pdo = get_pdo();

$rows = $pdo->query("
    SELECT ticket_code, ticket_path, ticket_qty, name, created_at, amount_eur
    FROM campaign_pledges
    WHERE pledge_type = 'ticket'
      AND payment_status = 'paid'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Expand multi-ticket pledges: one row per individual ticket.
$tickets = [];
foreach ($rows as $r) {
    $qty   = max(1, (int)($r['ticket_qty'] ?? 1));
    $paths = json_decode($r['ticket_path'] ?? '', true);
    if (!is_array($paths)) {
        $paths = $r['ticket_path'] ? [$r['ticket_path']] : [];
    }
    $price_each = $qty > 1 ? round((float)$r['amount_eur'] / $qty, 2) : (float)$r['amount_eur'];

    if ($qty === 1 || empty($paths)) {
        $tickets[] = [
            'name'       => $r['name'],
            'ticket_code'=> $r['ticket_code'] ?: '—',
            'created_at' => $r['created_at'],
            'amount_eur' => $price_each,
        ];
    } else {
        foreach ($paths as $path) {
            // Extract ticket code from filename: TKT-YYYYMMDD-XXXXXXXX_<pledge>.pdf
            $filename = basename($path);
            $code = strstr($filename, '_', true) ?: ($r['ticket_code'] ?: '—');
            $tickets[] = [
                'name'       => $r['name'],
                'ticket_code'=> $code,
                'created_at' => $r['created_at'],
                'amount_eur' => $price_each,
            ];
        }
    }
}

$total     = count($tickets);
$total_eur = array_sum(array_column($tickets, 'amount_eur'));
$generated = date('d.m.Y H:i');
?><!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="UTF-8">
<title>Списък с билети — <?= $generated ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; font-size: 10pt; color: #000; background: #fff; }

.screen-header {
    display: flex; align-items: center; gap: 1rem;
    padding: .75rem 1.25rem; background: #f5f5f5;
    border-bottom: 1px solid #ccc;
}
.screen-header h1 { font-size: 1rem; }
.screen-header .meta { font-size: .8rem; color: #555; margin-left: auto; }
.btn-print {
    padding: .4rem .9rem; background: #000; color: #fff;
    border: none; border-radius: 4px; cursor: pointer; font-size: .85rem;
}

.print-header { display: none; margin-bottom: .5cm; }
.print-header h1 { font-size: 13pt; }
.print-header p  { font-size: 8pt; color: #555; margin-top: 2px; }

table { width: auto; border-collapse: collapse; margin: 0 auto; }
thead th {
    font-size: 8pt; text-transform: uppercase; letter-spacing: .05em;
    border-bottom: 2px solid #000; padding: 3px 6px; text-align: left;
    background: #fff;
}
thead th.right { text-align: right; }
tbody td { padding: 3px 6px; font-size: 9pt; border-bottom: 1px solid #e0e0e0; }
tbody td.mono { font-family: monospace; font-size: 8pt; color: #444; }
tbody td.right { text-align: right; }
tfoot td {
    border-top: 2px solid #000; padding: 4px 6px; font-size: 9pt;
    font-weight: bold;
}
tfoot td.right { text-align: right; }

.empty { padding: 2rem; text-align: center; color: #888; font-size: .9rem; }

@media print {
    .screen-header { display: none; }
    .print-header  { display: block; }
    body { font-size: 9pt; }
@page { margin: 8mm 10mm; }
}
</style>
</head>
<body>

<div class="screen-header">
    <h1>Списък с билети</h1>
    <span class="meta">Генериран <?= $generated ?> &nbsp;·&nbsp; <?= $total ?> билета &nbsp;·&nbsp; <?= number_format($total_eur, 2, '.', ' ') ?> EUR</span>
    <button class="btn-print" onclick="window.print()">Принтирай</button>
</div>

<div style="padding:.75rem 1rem;">

<div class="print-header">
    <h1>Списък с билети</h1>
    <p>Генериран <?= $generated ?> &nbsp;·&nbsp; <?= $total ?> билета &nbsp;·&nbsp; <?= number_format($total_eur, 2, '.', ' ') ?> EUR</p>
</div>

<?php if (empty($tickets)): ?>
<div class="empty">Няма платени билети.</div>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th>#</th>
            <th style="min-width:180px;">Купувач</th>
            <th class="mono" style="min-width:160px;">Код на билет</th>
            <th style="min-width:80px;">Дата</th>
            <th class="right" style="min-width:60px;">EUR</th>
            <th style="min-width:40px;">Вход</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($tickets as $i => $t): ?>
        <tr>
            <td style="color:#999;"><?= $i + 1 ?></td>
            <td><?= h($t['name']) ?></td>
            <td class="mono"><?= h($t['ticket_code'] ?: '—') ?></td>
            <td><?= substr($t['created_at'], 0, 10) ?></td>
            <td class="right"><?= number_format((float)$t['amount_eur'], 2) ?></td>
            <td></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="4">Общо: <?= $total ?> билета</td>
            <td class="right"><?= number_format($total_eur, 2, '.', ' ') ?></td>
            <td></td>
        </tr>
    </tfoot>
</table>
<?php endif; ?>

</div>
</body>
</html>

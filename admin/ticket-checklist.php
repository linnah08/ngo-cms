<?php
/**
 * Door list for one event: every paid ticket, one row per ticket, by buyer
 * name, with an empty "Вход" column to tick off at the door. Printable.
 * ?event=<id>. Module "events".
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/events.php';

admin_require_shop();

// Events module switched off in Admin → Модули — says so, with a way back.
// Deliberately after the auth call: an anonymous request still gets the normal
// login redirect, so this never becomes an oracle for which modules a site runs.
module_admin_guard('events');

$pdo = get_pdo();
events_adopt_legacy($pdo);

$event_id = filter_var($_GET['event'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$event    = $event_id ? event_get($pdo, $event_id) : null;
if (!$event) {
    // No event chosen: the only event with tickets, if there is exactly one,
    // otherwise back to the list, where each event has its own door list.
    $ids = $pdo->query("SELECT DISTINCT event_id FROM campaign_pledges
                         WHERE pledge_type = 'ticket' AND payment_status = 'paid' AND event_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) === 1) {
        header('Location: /admin/ticket-checklist.php?event=' . (int) $ids[0]);
    } else {
        flash_set('error', 'Изберете събитие — „Списък за входа“ е до всяко събитие в списъка.');
        header('Location: /admin/events.php?when=all');
    }
    exit;
}

$tickets = event_door_list($pdo, (int) $event['id']);
$total     = count($tickets);
$total_eur = array_sum(array_column($tickets, 'amount_eur'));
$generated = date('d.m.Y H:i');
?><!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Списък за входа — <?= h($event['title']) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; font-size: 10pt; color: #000; background: #fff; }

.screen-header {
    display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem;
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
    <a href="/admin/event-edit.php?id=<?= (int) $event['id'] ?>" style="font-size:.9rem;">← Към събитието</a>
    <h1>Списък за входа — <?= h($event['title']) ?></h1>
    <span class="meta">Генериран <?= $generated ?> &nbsp;·&nbsp; <?= $total ?> билета &nbsp;·&nbsp; <?= number_format($total_eur, 2, '.', ' ') ?> EUR</span>
    <button type="button" class="btn-print" onclick="window.print()" style="min-height:40px;">Принтирай</button>
</div>

<div style="padding:.75rem 1rem;">

<div class="print-header">
    <h1><?= h($event['title']) ?><?= event_when($event, 'bg') !== '' ? ' — ' . h(event_when($event, 'bg')) : '' ?></h1>
    <p>Генериран <?= $generated ?> &nbsp;·&nbsp; <?= $total ?> билета &nbsp;·&nbsp; <?= number_format($total_eur, 2, '.', ' ') ?> EUR</p>
</div>

<?php if (empty($tickets)): ?>
<div class="empty">Още няма платени билети за това събитие.</div>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th scope="col">#</th>
            <th scope="col" style="min-width:180px;">Купувач</th>
            <th scope="col" class="mono" style="min-width:160px;">Код на билет</th>
            <th scope="col" style="min-width:80px;">Купен на</th>
            <th scope="col" class="right" style="min-width:60px;">EUR</th>
            <th scope="col" style="min-width:40px;">Вход</th>
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

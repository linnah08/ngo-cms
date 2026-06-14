<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
admin_require_iris();

$pdo = get_pdo();

// Next cert number
$seq = $pdo->prepare('SELECT last_number FROM document_sequences WHERE type = ?');
$seq->execute(['iris_donation_cert']);
$seq_row     = $seq->fetch();
$next_number = $seq_row ? (int)$seq_row['last_number'] + 1 : 1;

// Pending IRIS donations (no iris_donation_cert issued yet)
$pending = $pdo->query("
    SELECT o.id, o.customer_name, o.customer_email, o.total_eur, o.payment_method, o.created_at
    FROM orders o
    LEFT JOIN documents d ON d.order_id = o.id AND d.type = 'iris_donation_cert'
    WHERE o.status = 'confirmed'
      AND o.payment_status = 'paid'
      AND o.items LIKE '%\"recipient\":\"iris\"%'
      AND d.id IS NULL
    ORDER BY o.created_at DESC
")->fetchAll();

$pay_labels = [
    'card'          => 'Банкова карта',
    'bank_transfer' => 'Банков превод',
    'cod'           => 'В брой',
];
?>
<!DOCTYPE html>
<html lang="bg">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ЦСРИ Ирис — Сертификати</title>
  <link rel="stylesheet" href="/assets/css/main.css">
  <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body>

<div style="max-width:960px;margin:0 auto;padding:2rem 1.25rem;">

  <!-- Header -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;padding-bottom:1rem;border-bottom:2px solid var(--border);">
    <div>
      <h1 style="margin:0;font-size:1.3rem;">ЦСРИ Ирис — Сертификати за дарение</h1>
      <p style="margin:.3rem 0 0;font-size:.85rem;color:var(--text-muted);">
        Следващ номер: <strong>#<?= str_pad((string)$next_number, 5, '0', STR_PAD_LEFT) ?></strong>
      </p>
    </div>
    <div style="display:flex;gap:1rem;align-items:center;">
      <a href="/iris/cert.php" class="btn btn--primary">+ Нов сертификат</a>
      <a href="/iris/logout.php" style="font-size:.85rem;color:var(--text-muted);">Изход</a>
    </div>
  </div>

  <!-- Pending queue -->
  <h2 style="font-size:1rem;margin-bottom:1rem;">Чакащи сертификати от сайта</h2>

  <?php if (empty($pending)): ?>
    <p style="color:var(--text-muted);font-size:.9rem;">Няма чакащи сертификати.</p>
  <?php else: ?>
    <table class="admin-table" style="width:100%;">
      <thead>
        <tr>
          <th>Дата</th>
          <th>Дарител</th>
          <th>Сума (EUR)</th>
          <th>Начин на предаване</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pending as $row): ?>
          <tr>
            <td><?= h(substr($row['created_at'], 0, 10)) ?></td>
            <td><?= h($row['customer_name']) ?></td>
            <td><?= number_format((float)$row['total_eur'], 2, '.', ' ') ?> €</td>
            <td><?= h($pay_labels[$row['payment_method']] ?? $row['payment_method']) ?></td>
            <td style="text-align:right;">
              <a href="/iris/cert.php?order_id=<?= (int)$row['id'] ?>" class="btn btn--outline btn--sm">Генерирай</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

</div>
</body>
</html>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';
$page_title_admin = 'Импорт на абонати';
$active_nav       = 'newsletter';
admin_require_admin();

$pdo = get_pdo();

// Require explicit confirmation to prevent accidental double-run
if (($_GET['confirm'] ?? '') !== 'yes') {
    require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
    $stmt = $pdo->query("SELECT COUNT(DISTINCT customer_email) FROM orders WHERE payment_status='paid'");
    $eligible = (int)$stmt->fetchColumn();
    $stmt2 = $pdo->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE source='customer_import'");
    $already = (int)$stmt2->fetchColumn();
    ?>
    <div style="max-width:560px;">
      <h2 style="margin-bottom:1.5rem;">Импорт на клиенти като абонати</h2>
      <p>Ще бъдат импортирани всички клиенти с платени поръчки, които все още не са абонати.</p>
      <div style="background:var(--off-white);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;margin:1.5rem 0;">
        <div>Платени клиенти в системата: <strong><?= $eligible ?></strong></div>
        <div style="margin-top:.4rem;">Вече импортирани: <strong><?= $already ?></strong></div>
      </div>
      <div style="background:#fdf0ef;border:1px solid #f0c4c0;border-radius:var(--radius-lg);padding:1rem 1.25rem;margin-bottom:1.5rem;color:#c0392b;font-size:.9rem;">
        ⚠ Това действие ще изпрати бюлетин на хора, които не са го поискали изрично. Уверете се, че разполагате с правно основание (например е-търговска комуникация по GDPR).
      </div>
      <a href="/admin/newsletter-import.php?confirm=yes" class="btn btn--primary">
        Потвърди импорта →
      </a>
      <a href="/admin/newsletter-subscribers.php" style="margin-left:1rem;font-size:.9rem;color:var(--text-muted);">Откажи</a>
    </div>
    <?php
    require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php';
    exit;
}

// ── Run import ────────────────────────────────────────────────────────────────
$stmt = $pdo->query("
    SELECT DISTINCT customer_email, customer_name
    FROM orders
    WHERE payment_status = 'paid'
      AND customer_email NOT IN (SELECT email FROM newsletter_subscribers)
");
$rows = $stmt->fetchAll();

$imported = 0;
$skipped  = 0;
foreach ($rows as $row) {
    $result = newsletter_subscribe($row['customer_email'], $row['customer_name'], 'bg', 'customer_import');
    $result['duplicate'] ? $skipped++ : $imported++;
}

flash_set('success', "Импортирани: {$imported} нови абоната. Пропуснати (вече съществуващи): {$skipped}.");
header('Location: /admin/newsletter-subscribers.php');
exit;

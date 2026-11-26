<?php
// Fixture for `semgrep --test`: proves our own rules in .semgrep/ngo-cms.yml
// flag what they should (ruleid) and stay quiet on safe code (ok).
// Never included or run by the site.

// ruleid: ngo-cms-unescaped-request-output
echo $_GET['q'];

// ruleid: ngo-cms-unescaped-request-output
echo '<p>' . $_POST['name'] . '</p>';

$term = trim($_GET['term'] ?? '');
// ruleid: ngo-cms-unescaped-request-output
echo "<input value=\"$term\">";

// ok: ngo-cms-unescaped-request-output
echo h($_GET['q']);

// ok: ngo-cms-unescaped-request-output
echo (int) $_GET['page'];

$safe = h($_POST['name']);
// ok: ngo-cms-unescaped-request-output
echo '<p>' . $safe . '</p>';

// ok: ngo-cms-unescaped-request-output
echo json_encode(['q' => $_GET['q']]);

// json_encode only keeps "</script>" out while slashes stay escaped.
// ruleid: ngo-cms-unescaped-request-output
echo json_encode(['q' => $_GET['q']], JSON_UNESCAPED_SLASHES);

// ruleid: ngo-cms-unescaped-request-output
echo json_encode(['q' => $_GET['q']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$pdo = get_pdo();

// ruleid: ngo-cms-request-in-raw-sql
$pdo->query("SELECT * FROM orders WHERE email = '" . $_GET['email'] . "'");

$status = $_POST['status'];
// ruleid: ngo-cms-request-in-raw-sql
$pdo->exec("UPDATE orders SET status = '$status'");

// ok: ngo-cms-request-in-raw-sql
$pdo->query('SELECT * FROM orders WHERE id = ' . (int) $_GET['id']);

// ok: ngo-cms-request-in-raw-sql
$stmt = $pdo->prepare('SELECT * FROM orders WHERE email = ?');
$stmt->execute([$_GET['email']]);

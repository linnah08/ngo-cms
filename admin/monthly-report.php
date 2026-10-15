<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/reports/monthly-orders.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/site_extensions.php';   // the site's own report files

$page_title_admin = 'Месечен отчет';
$active_nav       = 'monthly-report';

admin_require_login();

// Default to previous month
$default_year  = (int) date('Y', strtotime('first day of last month'));
$default_month = (int) date('n', strtotime('first day of last month'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify() || (http_response_code(400) && exit('Invalid token'));

    $year  = (int) ($_POST['year']  ?? $default_year);
    $month = (int) ($_POST['month'] ?? $default_month);

    if ($year < 2020 || $year > 2099 || $month < 1 || $month > 12) {
        http_response_code(400);
        exit('Invalid date.');
    }

    $report = (string) ($_POST['report'] ?? '');
    if ($report !== '' && isset(site_reports()[$report])) {
        $built = site_report_build($report, $year, $month);
        if ($built === null) {
            http_response_code(500);
            exit('Отчетът не можа да бъде създаден. Опитайте отново или се свържете с поддръжката.');
        }
        [$csv, $filename] = [$built['csv'], $built['name']];
    } else {
        $csv      = generate_monthly_orders_csv($year, $month);
        $filename = sprintf('report-%04d-%02d.csv', $year, $month);
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-content">
  <h1 class="admin-h1">Месечен отчет</h1>
  <p style="color:#666;margin-bottom:24px;">Изтеглете таблица (CSV) за счетоводителя с платените и върнатите поръчки за избран месец. Даренията са на отделни редове — и когато са добавени към покупка, а издадените, но още неплатени фактури са отбелязани.</p>

  <form method="post" action="/admin/monthly-report.php">
    <?= csrf_field() ?>

    <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
      <div>
        <label for="mr-month" style="display:block;font-weight:600;margin-bottom:4px;">Месец</label>
        <select id="mr-month" name="month" style="padding:8px 12px;border:1px solid #ccc;border-radius:6px;font-size:14px;">
          <?php
          $month_names = [
            1 => 'Януари', 2 => 'Февруари', 3 => 'Март',     4 => 'Април',
            5 => 'Май',    6 => 'Юни',      7 => 'Юли',      8 => 'Август',
            9 => 'Септември', 10 => 'Октомври', 11 => 'Ноември', 12 => 'Декември',
          ];
          foreach ($month_names as $num => $name):
          ?>
            <option value="<?= $num ?>" <?= $num === $default_month ? 'selected' : '' ?>>
              <?= h($name) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="mr-year" style="display:block;font-weight:600;margin-bottom:4px;">Година</label>
        <select id="mr-year" name="year" style="padding:8px 12px;border:1px solid #ccc;border-radius:6px;font-size:14px;">
          <?php for ($y = (int)date('Y'); $y >= 2024; $y--): ?>
            <option value="<?= $y ?>" <?= $y === $default_year ? 'selected' : '' ?>>
              <?= $y ?>
            </option>
          <?php endfor; ?>
        </select>
      </div>

      <div>
        <button type="submit"
                style="padding:8px 20px;background:#1a56db;color:#fff;border:none;border-radius:6px;font-size:14px;cursor:pointer;font-weight:600;">
          Изтегли CSV
        </button>
      </div>
      <?php foreach (site_reports() as $rk => $rv): ?>
      <div>
        <button type="submit" name="report" value="<?= h($rk) ?>"
                style="padding:8px 20px;background:#fff;color:#1a56db;border:2px solid #1a56db;border-radius:6px;font-size:14px;cursor:pointer;font-weight:600;">
          <?= h($rv['label']) ?>
        </button>
      </div>
      <?php endforeach; ?>
    </div>
  </form>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>

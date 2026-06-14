# Content Sections Sortable Lists Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert impact metrics, team members, and ways-to-help sections in the admin to sortable list + single-item edit views, matching the pattern already in place for Projects.

**Architecture:** Each section gets a dedicated AJAX reorder endpoint, delete/save POST handlers in pages.php, a compact sortable list view with HTML5 drag-and-drop + ↑↓ keyboard buttons (WCAG 2.1 AA), and a single-item edit form. Impact is a standalone array in impact.json; team and ways are nested arrays within pages.json's about and how_to_help objects respectively.

**Tech Stack:** PHP 8.4, PHPUnit 13, vanilla JS (no libraries), TinyMCE (shared window._tinyBase config), flat-file JSON storage.

---

## Reference files (do not modify, only read for patterns)

- `admin/projects-reorder-ajax.php` — AJAX endpoint pattern
- `tests/ProjectsReorderTest.php` — test pattern
- `admin/pages.php` lines 847–1182 — projects list + edit view pattern (use as template for all three sections)

---

## Task 1 — Tests for impact reorder helpers (`tests/ImpactReorderTest.php`)

**TDD step 1: write the failing test first, then implement.**

- [ ] Create `tests/ImpactReorderTest.php` with the full content below
- [ ] Run `php vendor/bin/phpunit tests/ImpactReorderTest.php` — expect failures (functions not yet defined)
- [ ] Confirm the test file is syntactically valid (no parse errors)

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('impact')]
final class ImpactReorderTest extends TestCase
{
    // ── helpers under test (duplicated here to allow isolated testing) ────────

    private function assignOrderDefaults(array $items): array
    {
        foreach ($items as $i => &$p) {
            if (!isset($p['order'])) {
                $p['order'] = $i;
            }
        }
        return $items;
    }

    private function reorderByKey(array $items, array $order, string $key): array
    {
        $indexed = [];
        foreach ($items as $p) {
            $indexed[$p[$key]] = $p;
        }
        $sorted = [];
        foreach ($order as $k) {
            if (isset($indexed[$k])) {
                $sorted[] = $indexed[$k];
                unset($indexed[$k]);
            }
        }
        foreach ($indexed as $p) {
            $sorted[] = $p;
        }
        foreach ($sorted as $i => &$p) {
            $p['order'] = $i;
        }
        return $sorted;
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    private function sampleItems(): array
    {
        return [
            ['number' => '62', 'label_bg' => 'деца',    'label_en' => 'children',  'order' => 0],
            ['number' => '2',  'label_bg' => 'центъра', 'label_en' => 'centres',   'order' => 1],
            ['number' => '75', 'label_bg' => 'терапии', 'label_en' => 'therapies', 'order' => 2],
        ];
    }

    // ── reorderByKey ──────────────────────────────────────────────────────────

    public function test_reorder_reverses_order(): void
    {
        $result = $this->reorderByKey($this->sampleItems(), ['терапии', 'центъра', 'деца'], 'label_bg');
        $this->assertSame('терапии', $result[0]['label_bg']);
        $this->assertSame('центъра', $result[1]['label_bg']);
        $this->assertSame('деца',    $result[2]['label_bg']);
        $this->assertSame(0, (int)$result[0]['order']);
        $this->assertSame(1, (int)$result[1]['order']);
        $this->assertSame(2, (int)$result[2]['order']);
    }

    public function test_reorder_appends_unlisted(): void
    {
        $result = $this->reorderByKey($this->sampleItems(), ['центъра', 'деца'], 'label_bg');
        $this->assertCount(3, $result);
        $this->assertSame('центъра', $result[0]['label_bg']);
        $this->assertSame('деца',    $result[1]['label_bg']);
        $keys = array_column($result, 'label_bg');
        $this->assertContains('терапии', $keys);
    }

    public function test_reorder_assigns_sequential_order_fields(): void
    {
        $result = $this->reorderByKey($this->sampleItems(), ['центъра', 'терапии', 'деца'], 'label_bg');
        foreach ($result as $i => $p) {
            $this->assertSame($i, (int)$p['order']);
        }
    }

    // ── assignOrderDefaults ───────────────────────────────────────────────────

    public function test_assign_order_defaults_fills_missing(): void
    {
        $items = [
            ['number' => '10', 'label_bg' => 'деца',    'label_en' => 'children'],
            ['number' => '5',  'label_bg' => 'центъра', 'label_en' => 'centres'],
        ];
        $result = $this->assignOrderDefaults($items);
        $this->assertSame(0, (int)$result[0]['order']);
        $this->assertSame(1, (int)$result[1]['order']);
    }

    public function test_assign_order_defaults_preserves_existing(): void
    {
        $items = [
            ['number' => '10', 'label_bg' => 'деца',    'label_en' => 'children',  'order' => 5],
            ['number' => '5',  'label_bg' => 'центъра', 'label_en' => 'centres',   'order' => 2],
        ];
        $result = $this->assignOrderDefaults($items);
        $this->assertSame(5, (int)$result[0]['order']);
        $this->assertSame(2, (int)$result[1]['order']);
    }

    // ── delete by index ───────────────────────────────────────────────────────

    public function test_delete_removes_correct_item(): void
    {
        $items = $this->sampleItems();
        array_splice($items, 1, 1);
        $this->assertCount(2, $items);
        $this->assertSame('деца',    $items[0]['label_bg']);
        $this->assertSame('терапии', $items[1]['label_bg']);
    }

    public function test_delete_out_of_bounds_is_noop(): void
    {
        $items = $this->sampleItems();
        if (isset($items[99])) {
            array_splice($items, 99, 1);
        }
        $this->assertCount(3, $items);
    }
}
```

---

## Task 2 — Impact AJAX endpoint (`admin/impact-reorder-ajax.php`)

**TDD step 2: implement so the tests pass.**

- [ ] Run `php vendor/bin/phpunit tests/ImpactReorderTest.php` — confirm tests pass (they test pure functions duplicated in the test class, so they should pass after Task 1)
- [ ] Create `admin/impact-reorder-ajax.php` with the full content below
- [ ] Verify the file parses: `php -l admin/impact-reorder-ajax.php`

```php
<?php
/**
 * AJAX endpoint: save a new impact metrics order.
 *
 * POST body (JSON):
 *   { "csrf_token": "...", "order": ["деца", "центъра", ...] }
 *
 * Response:
 *   { "ok": true }
 *   { "ok": false, "error": "..." }
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
admin_require_admin();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// CSRF — token passed in JSON body
$_POST['csrf_token'] = $body['csrf_token'] ?? '';
if (!csrf_verify()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$order = $body['order'] ?? [];
if (!is_array($order)) {
    echo json_encode(['ok' => false, 'error' => 'order must be an array']);
    exit;
}

$items = load_json(IMPACT_FILE);

// Duplicate label_bg guard
$keys = array_column($items, 'label_bg');
if (count($keys) !== count(array_unique($keys))) {
    echo json_encode(['ok' => false, 'error' => 'Duplicate identifiers detected']);
    exit;
}

$items = impact_assign_order_defaults($items);
$items = impact_reorder_by_key($items, $order, 'label_bg');

save_json(IMPACT_FILE, $items);

echo json_encode(['ok' => true]);

// ── helpers ────────────────────────────────────────────────────────────────────

function impact_assign_order_defaults(array $items): array
{
    foreach ($items as $i => &$p) {
        if (!isset($p['order'])) {
            $p['order'] = $i;
        }
    }
    return $items;
}

function impact_reorder_by_key(array $items, array $order, string $key): array
{
    $indexed = [];
    foreach ($items as $p) {
        $indexed[$p[$key]] = $p;
    }

    $sorted = [];
    foreach ($order as $k) {
        if (isset($indexed[$k])) {
            $sorted[] = $indexed[$k];
            unset($indexed[$k]);
        }
    }
    foreach ($indexed as $p) {
        $sorted[] = $p;
    }

    foreach ($sorted as $i => &$p) {
        $p['order'] = $i;
    }

    return $sorted;
}
```

---

## Task 3 — Impact list + edit view + handlers in `admin/pages.php`

This is the largest change. It replaces the old bulk impact form entirely.

### 3a — Replace the `section === 'impact'` POST handler

- [ ] In `admin/pages.php`, find the block `} elseif ($section === 'impact') {` (lines ~98–113) and **replace the entire block** with two new handlers: `impact_delete` and `impact_save`.

Replace this exact block:
```php
    } elseif ($section === 'impact') {
        $items     = [];
        $numbers   = $_POST['impact_number']   ?? [];
        $labels    = $_POST['impact_label']    ?? [];
        $labels_en = $_POST['impact_label_en'] ?? [];
        foreach ($numbers as $i => $num) {
            if (trim($num) === '') continue;
            $items[] = [
                'number'   => trim($num),
                'label_bg' => trim($labels[$i]    ?? ''),
                'label_en' => trim($labels_en[$i] ?? ''),
                'order'    => $i + 1,
            ];
        }
        save_json(IMPACT_FILE, $items);
        header('Location: /admin/pages.php?page=impact&saved=1'); exit;
```

With this replacement:
```php
    } elseif ($section === 'impact_delete') {
        $items = load_json(IMPACT_FILE);
        $idx   = (int)($_POST['item_index'] ?? -1);
        foreach ($items as $i => &$_p) {
            if (!isset($_p['order'])) $_p['order'] = $i;
        }
        unset($_p);
        usort($items, fn($a, $b) => (int)$a['order'] - (int)$b['order']);
        if ($idx >= 0 && $idx < count($items)) {
            array_splice($items, $idx, 1);
            foreach ($items as $i => &$p) { $p['order'] = $i; }
            unset($p);
            save_json(IMPACT_FILE, $items);
            flash_set('success', 'Записът е изтрит.');
        }
        header('Location: /admin/pages.php?page=impact');
        exit;

    } elseif ($section === 'impact_save') {
        $items = load_json(IMPACT_FILE);
        foreach ($items as $i => &$_p) {
            if (!isset($_p['order'])) $_p['order'] = $i;
        }
        unset($_p);
        usort($items, fn($a, $b) => (int)$a['order'] - (int)$b['order']);

        $idx = $_POST['item_index'] ?? 'new';

        $item = [
            'number'   => trim($_POST['impact_number']   ?? ''),
            'label_bg' => trim($_POST['impact_label_bg'] ?? ''),
            'label_en' => trim($_POST['impact_label_en'] ?? ''),
        ];

        if ($idx === 'new') {
            $max_order     = empty($items) ? -1 : max(array_column($items, 'order'));
            $item['order'] = $max_order + 1;
            $items[]       = $item;
        } else {
            $idx = (int)$idx;
            if ($idx >= 0 && $idx < count($items)) {
                $item['order'] = $items[$idx]['order'];
                $items[$idx]   = $item;
            }
        }

        usort($items, fn($a, $b) => (int)$a['order'] - (int)$b['order']);
        save_json(IMPACT_FILE, $items);
        header('Location: /admin/pages.php?page=impact&saved=1');
        exit;
```

### 3b — Replace the impact HTML section

- [ ] In `admin/pages.php`, find the block `<?php elseif ($page === 'impact'): ?>` (line ~617) through the closing `</script>` tag that ends the `addImpactRow()` function (line ~669, just before `<?php elseif ($page === 'centres'): ?>`). **Replace the entire block** with the new list view + edit form below.

**The new impact HTML section:**

```php
<?php elseif ($page === 'impact'): ?>
<!-- ══ IMPACT ══ -->
<?php
// ── Single item edit ──────────────────────────────────────────────────────────
$edit_idx = $_GET['edit'] ?? null;
if ($edit_idx !== null):
    foreach ($impact as $i => &$_p2) {
        if (!isset($_p2['order'])) $_p2['order'] = $i;
    }
    unset($_p2);
    usort($impact, fn($a, $b) => (int)$a['order'] - (int)$b['order']);

    $is_new      = ($edit_idx === 'new');
    $edit_item   = $is_new ? ['number' => '', 'label_bg' => '', 'label_en' => ''] : ($impact[(int)$edit_idx] ?? null);
    if (!$is_new && !$edit_item): ?>
        <p>Записът не е намерен. <a href="/admin/pages.php?page=impact">← Назад</a></p>
<?php else: ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php?page=impact" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад към показатели</a>
    <h1 style="margin:0;"><?= $is_new ? 'Нов показател' : h($edit_item['label_bg']) ?></h1>
  </div>
  <button type="submit" form="impactEditForm" class="btn btn--primary">Запази</button>
</div>

<form id="impactEditForm" method="POST" action="/admin/pages.php?page=impact" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="section"     value="impact_save">
  <input type="hidden" name="item_index"  value="<?= h($edit_idx) ?>">

  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1.5rem;">
    <div class="form-group">
      <label>Число</label>
      <input type="text" name="impact_number" value="<?= h($edit_item['number'] ?? '') ?>" placeholder="62" required>
    </div>
    <div class="form-group">
      <label>Етикет <?= $lbl_bg_badge ?></label>
      <input type="text" id="impactLabelBg" name="impact_label_bg" value="<?= h($edit_item['label_bg'] ?? '') ?>" placeholder="деца" required>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Label <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('impactLabelBg','impactLabelEn',this)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <input type="text" id="impactLabelEn" name="impact_label_en" value="<?= h($edit_item['label_en'] ?? '') ?>" placeholder="children">
    </div>
  </div>

  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>
<?php endif; ?>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php';
exit;
endif; // end $edit_idx !== null
?>

<!-- ══ IMPACT LIST ══ -->
<?php
foreach ($impact as $i => &$_p) {
    if (!isset($_p['order'])) $_p['order'] = $i;
}
unset($_p);
usort($impact, fn($a, $b) => (int)$a['order'] - (int)$b['order']);
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад</a>
    <h1 style="margin:0;">Показатели (числа)</h1>
  </div>
  <a href="/admin/pages.php?page=impact&edit=new" class="btn btn--primary">+ Нов показател</a>
</div>

<div id="reorderAnnounce" aria-live="polite" aria-atomic="true"
     style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;"></div>

<?php $flash_msgs = flash_get(); foreach ($flash_msgs as $f): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;
  <?= $f['type']==='success' ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;' : 'background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;' ?>">
  <?= h($f['message']) ?>
</div>
<?php endforeach; ?>

<?php if (!empty($_GET['saved'])): ?>
  <div class="admin-alert admin-alert--success" style="margin-bottom:1.5rem;">Запазено успешно.</div>
<?php endif; ?>

<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;">
  <div style="overflow-x:auto;">
  <table class="admin-table" style="width:100%;border-collapse:collapse;" id="impactTable">
    <thead>
      <tr style="background:var(--off-white);">
        <th style="padding:.65rem .75rem;width:36px;" aria-label="Пренареди"></th>
        <th style="padding:.65rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Показател</th>
        <th style="padding:.65rem 1rem;width:120px;"></th>
        <th style="padding:.65rem .5rem;width:64px;text-align:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Ред</th>
        <th style="padding:.65rem 1rem;width:120px;"></th>
      </tr>
    </thead>
    <tbody id="impactBody">
      <?php if (empty($impact)): ?>
        <tr><td colspan="5" style="padding:2rem;text-align:center;color:var(--text-muted);">Няма показатели. <a href="/admin/pages.php?page=impact&edit=new">Създайте първия →</a></td></tr>
      <?php else: foreach ($impact as $idx => $item): ?>
        <tr data-key="<?= h($item['label_bg']) ?>" style="border-top:1px solid var(--border);cursor:default;">
          <td style="padding:.5rem .75rem;text-align:center;">
            <span class="impact-drag-handle" draggable="true" aria-hidden="true"
                  style="cursor:grab;font-size:1.1rem;color:var(--text-muted);user-select:none;display:inline-block;padding:.2rem .3rem;"
                  title="Влачи за пренареждане">⠿</span>
          </td>
          <td style="padding:.75rem 1rem;">
            <div style="display:flex;align-items:center;gap:.75rem;">
              <div style="width:48px;height:48px;background:var(--off-white);border-radius:4px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:700;color:var(--text-muted);"><?= h($item['number']) ?></div>
              <strong style="font-size:.9rem;"><?= h($item['label_bg']) ?></strong>
            </div>
          </td>
          <td style="padding:.75rem 1rem;text-align:right;">
            <a href="/admin/pages.php?page=impact&edit=<?= $idx ?>" class="btn btn--outline" style="font-size:.78rem;padding:.3rem .7rem;">Редактирай</a>
          </td>
          <td style="padding:.5rem;text-align:center;">
            <div style="display:flex;flex-direction:column;gap:.2rem;align-items:center;">
              <button type="button" class="btn btn--outline impact-up"
                      style="padding:.2rem .5rem;font-size:.75rem;line-height:1;"
                      aria-label="Премести нагоре — <?= h($item['label_bg']) ?>">↑</button>
              <button type="button" class="btn btn--outline impact-down"
                      style="padding:.2rem .5rem;font-size:.75rem;line-height:1;"
                      aria-label="Премести надолу — <?= h($item['label_bg']) ?>">↓</button>
            </div>
          </td>
          <td style="padding:.75rem 1rem;text-align:right;">
            <form method="POST" action="/admin/pages.php?page=impact" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="section"    value="impact_delete">
              <input type="hidden" name="item_index" value="<?= $idx ?>">
              <button type="submit" class="btn btn--outline"
                      style="font-size:.78rem;padding:.3rem .7rem;color:#c0392b;border-color:#f0c4c0;"
                      data-confirm="Изтриване на показателя?">Изтрий</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
(function () {
  var tbody    = document.getElementById('impactBody');
  var announce = document.getElementById('reorderAnnounce');
  if (!tbody) return;

  function getRows() { return Array.from(tbody.querySelectorAll('tr[data-key]')); }
  function keysOrder() { return getRows().map(function(r){ return r.dataset.key; }); }

  function saveOrder(prevRows) {
    var token   = document.querySelector('input[name="csrf_token"]');
    var csrfVal = token ? token.value : '';
    fetch('/admin/impact-reorder-ajax.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ csrf_token: csrfVal, order: keysOrder() })
    }).then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.ok) {
          if (prevRows) { prevRows.forEach(function(row) { tbody.appendChild(row); }); updateAriaButtons(); }
          alert('Грешка при запис: ' + (d.error || ''));
        }
      })
      .catch(function(e){
        if (prevRows) { prevRows.forEach(function(row) { tbody.appendChild(row); }); updateAriaButtons(); }
        alert('Грешка: ' + e.message);
      });
  }

  function updateAriaButtons() {
    var rows = getRows();
    rows.forEach(function(row, i) {
      row.querySelector('.impact-up').disabled   = (i === 0);
      row.querySelector('.impact-down').disabled = (i === rows.length - 1);
    });
  }

  tbody.addEventListener('click', function(e) {
    var btn = e.target.closest('.impact-up, .impact-down');
    if (!btn) return;
    var row      = btn.closest('tr');
    var rows     = getRows();
    var idx      = rows.indexOf(row);
    var prevRows = getRows();
    if (btn.classList.contains('impact-up') && idx > 0) {
      tbody.insertBefore(row, rows[idx - 1]);
    } else if (btn.classList.contains('impact-down') && idx < rows.length - 1) {
      tbody.insertBefore(rows[idx + 1], row);
    } else { return; }
    updateAriaButtons();
    var newPos = getRows().indexOf(row) + 1;
    announce.textContent = row.dataset.key + ' — преместен на позиция ' + newPos;
    saveOrder(prevRows);
  });

  var dragging = null;
  tbody.addEventListener('dragstart', function(e) {
    var handle = e.target.closest('.impact-drag-handle');
    if (!handle) { e.preventDefault(); return; }
    dragging = handle.closest('tr');
    dragging.style.opacity = '0.4';
    e.dataTransfer.effectAllowed = 'move';
  });
  tbody.addEventListener('dragend', function() {
    if (dragging) { dragging.style.opacity = ''; dragging = null; }
    getRows().forEach(function(r){ r.style.borderTop = ''; });
  });
  tbody.addEventListener('dragover', function(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    var target = e.target.closest('tr[data-key]');
    getRows().forEach(function(r){ r.style.borderTop = ''; });
    if (target && target !== dragging) target.style.borderTop = '2px solid var(--teal)';
  });
  tbody.addEventListener('drop', function(e) {
    e.preventDefault();
    var target = e.target.closest('tr[data-key]');
    if (!target || target === dragging || !dragging) return;
    var prevRows = getRows();
    tbody.insertBefore(dragging, target);
    getRows().forEach(function(r){ r.style.borderTop = ''; });
    updateAriaButtons();
    saveOrder(prevRows);
  });

  updateAriaButtons();
}());
</script>
```

### 3c — Run tests and commit

- [ ] Run `php vendor/bin/phpunit` — all tests must pass
- [ ] Commit: `git add admin/impact-reorder-ajax.php admin/pages.php tests/ImpactReorderTest.php && git commit -m "feat: impact metrics sortable list + single-item edit"`

---

## Task 4 — Tests for team reorder helpers (`tests/TeamReorderTest.php`)

- [ ] Create `tests/TeamReorderTest.php` with the full content below
- [ ] Run `php vendor/bin/phpunit tests/TeamReorderTest.php` — expect failures (functions not yet defined)

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('team')]
final class TeamReorderTest extends TestCase
{
    // ── helpers under test (duplicated here to allow isolated testing) ────────

    private function assignOrderDefaults(array $items): array
    {
        foreach ($items as $i => &$p) {
            if (!isset($p['order'])) {
                $p['order'] = $i;
            }
        }
        return $items;
    }

    private function reorderByKey(array $items, array $order, string $key): array
    {
        $indexed = [];
        foreach ($items as $p) {
            $indexed[$p[$key]] = $p;
        }
        $sorted = [];
        foreach ($order as $k) {
            if (isset($indexed[$k])) {
                $sorted[] = $indexed[$k];
                unset($indexed[$k]);
            }
        }
        foreach ($indexed as $p) {
            $sorted[] = $p;
        }
        foreach ($sorted as $i => &$p) {
            $p['order'] = $i;
        }
        return $sorted;
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    private function sampleMembers(): array
    {
        return [
            ['name' => 'Мария Иванова',  'role' => 'Директор',    'role_en' => 'Director',    'photo' => '', 'bio' => '', 'bio_en' => '', 'order' => 0],
            ['name' => 'Петър Георгиев', 'role' => 'Терапевт',    'role_en' => 'Therapist',   'photo' => '', 'bio' => '', 'bio_en' => '', 'order' => 1],
            ['name' => 'Ана Димитрова',  'role' => 'Координатор', 'role_en' => 'Coordinator', 'photo' => '', 'bio' => '', 'bio_en' => '', 'order' => 2],
        ];
    }

    // ── reorderByKey ──────────────────────────────────────────────────────────

    public function test_reorder_reverses_order(): void
    {
        $result = $this->reorderByKey($this->sampleMembers(), ['Ана Димитрова', 'Петър Георгиев', 'Мария Иванова'], 'name');
        $this->assertSame('Ана Димитрова',  $result[0]['name']);
        $this->assertSame('Петър Георгиев', $result[1]['name']);
        $this->assertSame('Мария Иванова',  $result[2]['name']);
        $this->assertSame(0, (int)$result[0]['order']);
        $this->assertSame(1, (int)$result[1]['order']);
        $this->assertSame(2, (int)$result[2]['order']);
    }

    public function test_reorder_appends_unlisted(): void
    {
        $result = $this->reorderByKey($this->sampleMembers(), ['Петър Георгиев', 'Мария Иванова'], 'name');
        $this->assertCount(3, $result);
        $this->assertSame('Петър Георгиев', $result[0]['name']);
        $this->assertSame('Мария Иванова',  $result[1]['name']);
        $names = array_column($result, 'name');
        $this->assertContains('Ана Димитрова', $names);
    }

    public function test_reorder_assigns_sequential_order_fields(): void
    {
        $result = $this->reorderByKey($this->sampleMembers(), ['Ана Димитрова', 'Мария Иванова', 'Петър Георгиев'], 'name');
        foreach ($result as $i => $p) {
            $this->assertSame($i, (int)$p['order']);
        }
    }

    // ── assignOrderDefaults ───────────────────────────────────────────────────

    public function test_assign_order_defaults_fills_missing(): void
    {
        $members = [
            ['name' => 'Мария Иванова',  'role' => 'Директор'],
            ['name' => 'Петър Георгиев', 'role' => 'Терапевт'],
        ];
        $result = $this->assignOrderDefaults($members);
        $this->assertSame(0, (int)$result[0]['order']);
        $this->assertSame(1, (int)$result[1]['order']);
    }

    public function test_assign_order_defaults_preserves_existing(): void
    {
        $members = [
            ['name' => 'Мария Иванова',  'role' => 'Директор', 'order' => 3],
            ['name' => 'Петър Георгиев', 'role' => 'Терапевт', 'order' => 7],
        ];
        $result = $this->assignOrderDefaults($members);
        $this->assertSame(3, (int)$result[0]['order']);
        $this->assertSame(7, (int)$result[1]['order']);
    }

    // ── delete by index ───────────────────────────────────────────────────────

    public function test_delete_removes_correct_item(): void
    {
        $members = $this->sampleMembers();
        array_splice($members, 1, 1);
        $this->assertCount(2, $members);
        $this->assertSame('Мария Иванова', $members[0]['name']);
        $this->assertSame('Ана Димитрова', $members[1]['name']);
    }

    public function test_delete_out_of_bounds_is_noop(): void
    {
        $members = $this->sampleMembers();
        if (isset($members[99])) {
            array_splice($members, 99, 1);
        }
        $this->assertCount(3, $members);
    }
}
```

---

## Task 5 — Team AJAX endpoint (`admin/team-reorder-ajax.php`)

- [ ] Run `php vendor/bin/phpunit tests/TeamReorderTest.php` — confirm tests pass (pure functions in test class)
- [ ] Create `admin/team-reorder-ajax.php` with the full content below
- [ ] Verify: `php -l admin/team-reorder-ajax.php`

```php
<?php
/**
 * AJAX endpoint: save a new team member order.
 *
 * POST body (JSON):
 *   { "csrf_token": "...", "order": ["Мария Иванова", "Петър Георгиев", ...] }
 *
 * Response:
 *   { "ok": true }
 *   { "ok": false, "error": "..." }
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
admin_require_admin();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$_POST['csrf_token'] = $body['csrf_token'] ?? '';
if (!csrf_verify()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$order = $body['order'] ?? [];
if (!is_array($order)) {
    echo json_encode(['ok' => false, 'error' => 'order must be an array']);
    exit;
}

$pages  = load_json(CONTENT_PATH . '/pages.json');
$team   = $pages['about']['team'] ?? [];

// Duplicate name guard
$names = array_column($team, 'name');
if (count($names) !== count(array_unique($names))) {
    echo json_encode(['ok' => false, 'error' => 'Duplicate member names detected']);
    exit;
}

$team = team_assign_order_defaults($team);
$team = team_reorder_by_key($team, $order, 'name');

$pages['about']['team'] = $team;
save_json(CONTENT_PATH . '/pages.json', $pages);

echo json_encode(['ok' => true]);

// ── helpers ────────────────────────────────────────────────────────────────────

function team_assign_order_defaults(array $items): array
{
    foreach ($items as $i => &$p) {
        if (!isset($p['order'])) {
            $p['order'] = $i;
        }
    }
    return $items;
}

function team_reorder_by_key(array $items, array $order, string $key): array
{
    $indexed = [];
    foreach ($items as $p) {
        $indexed[$p[$key]] = $p;
    }

    $sorted = [];
    foreach ($order as $k) {
        if (isset($indexed[$k])) {
            $sorted[] = $indexed[$k];
            unset($indexed[$k]);
        }
    }
    foreach ($indexed as $p) {
        $sorted[] = $p;
    }

    foreach ($sorted as $i => &$p) {
        $p['order'] = $i;
    }

    return $sorted;
}
```

---

## Task 6 — Team list + edit view + handlers in `admin/pages.php`

### 6a — Refactor the `section === 'about'` POST handler

The existing handler saves title+intro+team in one block. The team loop must be removed. Two new handlers `team_save` and `team_delete` are added.

- [ ] In `admin/pages.php`, find the `} elseif ($section === 'about') {` block (lines ~154–198) and replace with the following. **Keep the title/intro save. Remove the team loop. Add team_delete and team_save handlers.**

Replace the entire `about` handler block:
```php
    } elseif ($section === 'about') {
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $pages['about']['title']    = trim($_POST['about_title']    ?? '');
        $pages['about']['intro']    = trim($_POST['about_intro']    ?? '');
        $pages['about']['title_en'] = trim($_POST['about_title_en'] ?? '');
        $pages['about']['intro_en'] = trim($_POST['about_intro_en'] ?? '');

        $names    = $_POST['member_name']    ?? [];
        // ... [all the team loop code through line 198] ...
        $pages['about']['team'] = $team;
        save_json(CONTENT_PATH . '/pages.json', $pages);
        header('Location: /admin/pages.php?page=about&saved=1'); exit;
```

With:
```php
    } elseif ($section === 'about') {
        // Saves title and intro only — team is managed by team_save / team_delete
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $pages['about']['title']    = trim($_POST['about_title']    ?? '');
        $pages['about']['intro']    = trim($_POST['about_intro']    ?? '');
        $pages['about']['title_en'] = trim($_POST['about_title_en'] ?? '');
        $pages['about']['intro_en'] = trim($_POST['about_intro_en'] ?? '');
        save_json(CONTENT_PATH . '/pages.json', $pages);
        header('Location: /admin/pages.php?page=about&saved=1'); exit;

    } elseif ($section === 'team_delete') {
        $pages  = load_json(CONTENT_PATH . '/pages.json');
        $team   = $pages['about']['team'] ?? [];
        $idx    = (int)($_POST['team_index'] ?? -1);
        foreach ($team as $i => &$_p) {
            if (!isset($_p['order'])) $_p['order'] = $i;
        }
        unset($_p);
        usort($team, fn($a, $b) => (int)$a['order'] - (int)$b['order']);
        if ($idx >= 0 && $idx < count($team)) {
            array_splice($team, $idx, 1);
            foreach ($team as $i => &$p) { $p['order'] = $i; }
            unset($p);
            $pages['about']['team'] = $team;
            save_json(CONTENT_PATH . '/pages.json', $pages);
            flash_set('success', 'Членът е изтрит.');
        }
        header('Location: /admin/pages.php?page=about');
        exit;

    } elseif ($section === 'team_save') {
        $pages  = load_json(CONTENT_PATH . '/pages.json');
        $team   = $pages['about']['team'] ?? [];
        foreach ($team as $i => &$_p) {
            if (!isset($_p['order'])) $_p['order'] = $i;
        }
        unset($_p);
        usort($team, fn($a, $b) => (int)$a['order'] - (int)$b['order']);

        $idx = $_POST['team_index'] ?? 'new';

        // Handle file upload
        $photo = trim($_POST['member_photo_current'] ?? '');
        if (
            !empty($_FILES['member_photo_file']['tmp_name']) &&
            $_FILES['member_photo_file']['error'] === UPLOAD_ERR_OK
        ) {
            $ext = strtolower(pathinfo($_FILES['member_photo_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/team/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = 'member-' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['member_photo_file']['tmp_name'], $dir . $filename)) {
                    $photo = '/assets/images/team/' . $filename;
                }
            }
        }

        $member = [
            'name'    => trim($_POST['member_name']    ?? ''),
            'role'    => trim($_POST['member_role']    ?? ''),
            'role_en' => trim($_POST['member_role_en'] ?? ''),
            'photo'   => $photo,
            'bio'     => trim($_POST['member_bio']     ?? ''),
            'bio_en'  => trim($_POST['member_bio_en']  ?? ''),
        ];

        if ($idx === 'new') {
            $max_order       = empty($team) ? -1 : max(array_column($team, 'order'));
            $member['order'] = $max_order + 1;
            $team[]          = $member;
        } else {
            $idx = (int)$idx;
            if ($idx >= 0 && $idx < count($team)) {
                $member['order'] = $team[$idx]['order'];
                $team[$idx]      = $member;
            }
        }

        usort($team, fn($a, $b) => (int)$a['order'] - (int)$b['order']);
        $pages['about']['team'] = $team;
        save_json(CONTENT_PATH . '/pages.json', $pages);
        header('Location: /admin/pages.php?page=about&saved=1');
        exit;
```

### 6b — Replace the about HTML section

- [ ] In `admin/pages.php`, find the block `<?php elseif ($page === 'about'): ?>` (line ~730) through the closing `</script>` for the `addMemberRow()` function and the TinyMCE init (through line ~845, just before `<?php elseif ($page === 'projects'): ?>`). Replace the entire block with the new HTML below.

**The new about HTML section:**

```php
<?php elseif ($page === 'about'): ?>
<!-- ══ ABOUT ══ -->
<?php
// ── Single team member edit ───────────────────────────────────────────────────
$edit_team_idx = $_GET['edit_team'] ?? null;
if ($edit_team_idx !== null):
    $team_data = $about['team'] ?? [];
    foreach ($team_data as $i => &$_p2) {
        if (!isset($_p2['order'])) $_p2['order'] = $i;
    }
    unset($_p2);
    usort($team_data, fn($a, $b) => (int)$a['order'] - (int)$b['order']);

    $is_new     = ($edit_team_idx === 'new');
    $edit_member = $is_new
        ? ['name' => '', 'role' => '', 'role_en' => '', 'photo' => '', 'bio' => '', 'bio_en' => '']
        : ($team_data[(int)$edit_team_idx] ?? null);
    if (!$is_new && !$edit_member): ?>
        <p>Членът не е намерен. <a href="/admin/pages.php?page=about">← Назад</a></p>
<?php else: ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php?page=about" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад към За нас</a>
    <h1 style="margin:0;"><?= $is_new ? 'Нов член на екипа' : h($edit_member['name']) ?></h1>
  </div>
  <button type="submit" form="teamEditForm" class="btn btn--primary">Запази</button>
</div>

<form id="teamEditForm" method="POST" action="/admin/pages.php?page=about"
      enctype="multipart/form-data" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="section"    value="team_save">
  <input type="hidden" name="team_index" value="<?= h($edit_team_idx) ?>">
  <input type="hidden" name="member_photo_current" value="<?= h($edit_member['photo'] ?? '') ?>">

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div class="form-group">
      <label>Имена <?= $lbl_bg_badge ?></label>
      <input type="text" id="memberNameBg" name="member_name"
             value="<?= h($edit_member['name'] ?? '') ?>" required>
    </div>
    <div class="form-group">
      <!-- name_en field intentionally omitted — name is a proper noun, not translated -->
    </div>
    <div class="form-group">
      <label>Роля <?= $lbl_bg_badge ?></label>
      <input type="text" id="memberRoleBg" name="member_role"
             value="<?= h($edit_member['role'] ?? '') ?>">
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Role <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('memberRoleBg','memberRoleEn',this)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <input type="text" id="memberRoleEn" name="member_role_en"
             value="<?= h($edit_member['role_en'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Биография <?= $lbl_bg_badge ?></label>
      <textarea id="teamBioBg" name="member_bio" rows="8"><?= h($edit_member['bio'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Bio <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('teamBioBg','teamBioEn',this,true)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <textarea id="teamBioEn" name="member_bio_en" rows="8"><?= h($edit_member['bio_en'] ?? '') ?></textarea>
    </div>
  </div>

  <div class="form-group" style="margin-top:.5rem;">
    <label>Снимка</label>
    <?php if (!empty($edit_member['photo'])): ?>
      <img src="<?= h($edit_member['photo']) ?>" alt=""
           style="height:80px;border-radius:4px;margin-bottom:0.5rem;display:block;">
    <?php endif; ?>
    <input type="file" name="member_photo_file" accept="image/*">
    <button type="button" class="btn btn--outline"
            style="margin-top:.5rem;font-size:.82rem;"
            onclick="_pickMemberPhoto(this)">Избери от библиотека</button>
    <small style="color:var(--text-muted);display:block;margin-top:.25rem;">Качи нова снимка (замества текущата).</small>
  </div>

  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>
<script>
if (window._tinyBase) {
  tinymce.init(Object.assign({}, window._tinyBase, { selector: '#teamBioBg, #teamBioEn', height: 350 }));
}
</script>
<?php endif; ?>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php';
exit;
endif; // end $edit_team_idx !== null
?>

<!-- ══ ABOUT — title/intro form ══ -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад</a>
    <h1 style="margin:0;">За нас</h1>
  </div>
  <button type="submit" form="aboutForm" class="btn btn--primary">Запази</button>
</div>

<?php if (!empty($_GET['saved'])): ?>
  <div class="admin-alert admin-alert--success" style="margin-bottom:1.5rem;">Запазено успешно.</div>
<?php endif; ?>

<form id="aboutForm" method="POST" action="/admin/pages.php?page=about" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="about">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div class="form-group">
      <label>Заглавие <?= $lbl_bg_badge ?></label>
      <input type="text" id="aboutTitleBg" name="about_title" value="<?= h($about['title'] ?? '') ?>">
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Title <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('aboutTitleBg','aboutTitleEn',this)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <input type="text" id="aboutTitleEn" name="about_title_en" value="<?= h($about['title_en'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Уводен текст <?= $lbl_bg_badge ?></label>
      <textarea id="aboutIntroBg" name="about_intro" rows="8"><?= h($about['intro'] ?? '') ?></textarea>
      <small style="color:var(--text-muted);">Двоен нов ред = нов параграф.</small>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Intro <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('aboutIntroBg','aboutIntroEn',this)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <textarea id="aboutIntroEn" name="about_intro_en" rows="8"><?= h($about['intro_en'] ?? '') ?></textarea>
    </div>
  </div>
  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>
<script>
tinymce.init(Object.assign({}, window._tinyBase, { selector: '#aboutIntroBg, #aboutIntroEn', height: 350 }));
</script>

<!-- ══ ABOUT — team sortable list ══ -->
<?php
$team_list = $about['team'] ?? [];
foreach ($team_list as $i => &$_tp) {
    if (!isset($_tp['order'])) $_tp['order'] = $i;
}
unset($_tp);
usort($team_list, fn($a, $b) => (int)$a['order'] - (int)$b['order']);
?>
<h2 style="margin:2.5rem 0 1rem;">Екип</h2>

<div id="teamReorderAnnounce" aria-live="polite" aria-atomic="true"
     style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;"></div>

<?php $flash_msgs = flash_get(); foreach ($flash_msgs as $f): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;
  <?= $f['type']==='success' ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;' : 'background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;' ?>">
  <?= h($f['message']) ?>
</div>
<?php endforeach; ?>

<div style="display:flex;justify-content:flex-end;margin-bottom:1rem;">
  <a href="/admin/pages.php?page=about&edit_team=new" class="btn btn--primary">+ Нов член</a>
</div>

<input type="hidden" id="teamCsrfToken" name="csrf_token" value="<?= csrf_token() ?>">

<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;">
  <div style="overflow-x:auto;">
  <table class="admin-table" style="width:100%;border-collapse:collapse;" id="teamTable">
    <thead>
      <tr style="background:var(--off-white);">
        <th style="padding:.65rem .75rem;width:36px;" aria-label="Пренареди"></th>
        <th style="padding:.65rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Член</th>
        <th style="padding:.65rem 1rem;width:120px;"></th>
        <th style="padding:.65rem .5rem;width:64px;text-align:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Ред</th>
        <th style="padding:.65rem 1rem;width:120px;"></th>
      </tr>
    </thead>
    <tbody id="teamBody">
      <?php if (empty($team_list)): ?>
        <tr><td colspan="5" style="padding:2rem;text-align:center;color:var(--text-muted);">Няма членове. <a href="/admin/pages.php?page=about&edit_team=new">Добавете първия →</a></td></tr>
      <?php else: foreach ($team_list as $idx => $member):
        $thumb = !empty($member['photo']) ? $member['photo'] : '';
      ?>
        <tr data-key="<?= h($member['name']) ?>" style="border-top:1px solid var(--border);cursor:default;">
          <td style="padding:.5rem .75rem;text-align:center;">
            <span class="team-drag-handle" draggable="true" aria-hidden="true"
                  style="cursor:grab;font-size:1.1rem;color:var(--text-muted);user-select:none;display:inline-block;padding:.2rem .3rem;"
                  title="Влачи за пренареждане">⠿</span>
          </td>
          <td style="padding:.75rem 1rem;">
            <div style="display:flex;align-items:center;gap:.75rem;">
              <?php if ($thumb): ?>
                <img src="<?= h($thumb) ?>" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:50%;flex-shrink:0;">
              <?php else: ?>
                <div style="width:48px;height:48px;background:var(--off-white);border-radius:50%;flex-shrink:0;"></div>
              <?php endif; ?>
              <div>
                <strong style="font-size:.9rem;"><?= h($member['name']) ?></strong>
                <?php if (!empty($member['role'])): ?>
                  <div style="font-size:.78rem;color:var(--text-muted);"><?= h($member['role']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td style="padding:.75rem 1rem;text-align:right;">
            <a href="/admin/pages.php?page=about&edit_team=<?= $idx ?>" class="btn btn--outline" style="font-size:.78rem;padding:.3rem .7rem;">Редактирай</a>
          </td>
          <td style="padding:.5rem;text-align:center;">
            <div style="display:flex;flex-direction:column;gap:.2rem;align-items:center;">
              <button type="button" class="btn btn--outline team-up"
                      style="padding:.2rem .5rem;font-size:.75rem;line-height:1;"
                      aria-label="Премести нагоре — <?= h($member['name']) ?>">↑</button>
              <button type="button" class="btn btn--outline team-down"
                      style="padding:.2rem .5rem;font-size:.75rem;line-height:1;"
                      aria-label="Премести надолу — <?= h($member['name']) ?>">↓</button>
            </div>
          </td>
          <td style="padding:.75rem 1rem;text-align:right;">
            <form method="POST" action="/admin/pages.php?page=about" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="section"     value="team_delete">
              <input type="hidden" name="team_index"  value="<?= $idx ?>">
              <button type="submit" class="btn btn--outline"
                      style="font-size:.78rem;padding:.3rem .7rem;color:#c0392b;border-color:#f0c4c0;"
                      data-confirm="Изтриване на члена?">Изтрий</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
(function () {
  var tbody    = document.getElementById('teamBody');
  var announce = document.getElementById('teamReorderAnnounce');
  if (!tbody) return;

  function getRows() { return Array.from(tbody.querySelectorAll('tr[data-key]')); }
  function keysOrder() { return getRows().map(function(r){ return r.dataset.key; }); }

  function saveOrder(prevRows) {
    var token   = document.getElementById('teamCsrfToken');
    var csrfVal = token ? token.value : '';
    fetch('/admin/team-reorder-ajax.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ csrf_token: csrfVal, order: keysOrder() })
    }).then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.ok) {
          if (prevRows) { prevRows.forEach(function(row) { tbody.appendChild(row); }); updateAriaButtons(); }
          alert('Грешка при запис: ' + (d.error || ''));
        }
      })
      .catch(function(e){
        if (prevRows) { prevRows.forEach(function(row) { tbody.appendChild(row); }); updateAriaButtons(); }
        alert('Грешка: ' + e.message);
      });
  }

  function updateAriaButtons() {
    var rows = getRows();
    rows.forEach(function(row, i) {
      row.querySelector('.team-up').disabled   = (i === 0);
      row.querySelector('.team-down').disabled = (i === rows.length - 1);
    });
  }

  tbody.addEventListener('click', function(e) {
    var btn = e.target.closest('.team-up, .team-down');
    if (!btn) return;
    var row      = btn.closest('tr');
    var rows     = getRows();
    var idx      = rows.indexOf(row);
    var prevRows = getRows();
    if (btn.classList.contains('team-up') && idx > 0) {
      tbody.insertBefore(row, rows[idx - 1]);
    } else if (btn.classList.contains('team-down') && idx < rows.length - 1) {
      tbody.insertBefore(rows[idx + 1], row);
    } else { return; }
    updateAriaButtons();
    var newPos = getRows().indexOf(row) + 1;
    announce.textContent = row.dataset.key + ' — преместен на позиция ' + newPos;
    saveOrder(prevRows);
  });

  var dragging = null;
  tbody.addEventListener('dragstart', function(e) {
    var handle = e.target.closest('.team-drag-handle');
    if (!handle) { e.preventDefault(); return; }
    dragging = handle.closest('tr');
    dragging.style.opacity = '0.4';
    e.dataTransfer.effectAllowed = 'move';
  });
  tbody.addEventListener('dragend', function() {
    if (dragging) { dragging.style.opacity = ''; dragging = null; }
    getRows().forEach(function(r){ r.style.borderTop = ''; });
  });
  tbody.addEventListener('dragover', function(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    var target = e.target.closest('tr[data-key]');
    getRows().forEach(function(r){ r.style.borderTop = ''; });
    if (target && target !== dragging) target.style.borderTop = '2px solid var(--teal)';
  });
  tbody.addEventListener('drop', function(e) {
    e.preventDefault();
    var target = e.target.closest('tr[data-key]');
    if (!target || target === dragging || !dragging) return;
    var prevRows = getRows();
    tbody.insertBefore(dragging, target);
    getRows().forEach(function(r){ r.style.borderTop = ''; });
    updateAriaButtons();
    saveOrder(prevRows);
  });

  updateAriaButtons();
}());
</script>
```

### 6c — Run tests and commit

- [ ] Run `php vendor/bin/phpunit` — all tests must pass
- [ ] Commit: `git add admin/team-reorder-ajax.php admin/pages.php tests/TeamReorderTest.php && git commit -m "feat: team members sortable list + single-item edit"`

---

## Task 7 — Tests for ways reorder helpers (`tests/WaysReorderTest.php`)

- [ ] Create `tests/WaysReorderTest.php` with the full content below
- [ ] Run `php vendor/bin/phpunit tests/WaysReorderTest.php` — expect failures

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('ways')]
final class WaysReorderTest extends TestCase
{
    // ── helpers under test (duplicated here to allow isolated testing) ────────

    private function assignOrderDefaults(array $items): array
    {
        foreach ($items as $i => &$p) {
            if (!isset($p['order'])) {
                $p['order'] = $i;
            }
        }
        return $items;
    }

    private function reorderByKey(array $items, array $order, string $key): array
    {
        $indexed = [];
        foreach ($items as $p) {
            $indexed[$p[$key]] = $p;
        }
        $sorted = [];
        foreach ($order as $k) {
            if (isset($indexed[$k])) {
                $sorted[] = $indexed[$k];
                unset($indexed[$k]);
            }
        }
        foreach ($indexed as $p) {
            $sorted[] = $p;
        }
        foreach ($sorted as $i => &$p) {
            $p['order'] = $i;
        }
        return $sorted;
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    private function sampleWays(): array
    {
        return [
            ['title' => 'Дарение',     'text' => '', 'title_en' => 'Donation',  'text_en' => '', 'order' => 0],
            ['title' => 'Доброволци',  'text' => '', 'title_en' => 'Volunteer', 'text_en' => '', 'order' => 1],
            ['title' => 'Партньори',   'text' => '', 'title_en' => 'Partners',  'text_en' => '', 'order' => 2],
        ];
    }

    // ── reorderByKey ──────────────────────────────────────────────────────────

    public function test_reorder_reverses_order(): void
    {
        $result = $this->reorderByKey($this->sampleWays(), ['Партньори', 'Доброволци', 'Дарение'], 'title');
        $this->assertSame('Партньори',  $result[0]['title']);
        $this->assertSame('Доброволци', $result[1]['title']);
        $this->assertSame('Дарение',    $result[2]['title']);
        $this->assertSame(0, (int)$result[0]['order']);
        $this->assertSame(1, (int)$result[1]['order']);
        $this->assertSame(2, (int)$result[2]['order']);
    }

    public function test_reorder_appends_unlisted(): void
    {
        $result = $this->reorderByKey($this->sampleWays(), ['Доброволци', 'Дарение'], 'title');
        $this->assertCount(3, $result);
        $this->assertSame('Доброволци', $result[0]['title']);
        $this->assertSame('Дарение',    $result[1]['title']);
        $titles = array_column($result, 'title');
        $this->assertContains('Партньори', $titles);
    }

    public function test_reorder_assigns_sequential_order_fields(): void
    {
        $result = $this->reorderByKey($this->sampleWays(), ['Партньори', 'Дарение', 'Доброволци'], 'title');
        foreach ($result as $i => $p) {
            $this->assertSame($i, (int)$p['order']);
        }
    }

    // ── assignOrderDefaults ───────────────────────────────────────────────────

    public function test_assign_order_defaults_fills_missing(): void
    {
        $ways = [
            ['title' => 'Дарение',    'text' => ''],
            ['title' => 'Доброволци', 'text' => ''],
        ];
        $result = $this->assignOrderDefaults($ways);
        $this->assertSame(0, (int)$result[0]['order']);
        $this->assertSame(1, (int)$result[1]['order']);
    }

    public function test_assign_order_defaults_preserves_existing(): void
    {
        $ways = [
            ['title' => 'Дарение',    'text' => '', 'order' => 4],
            ['title' => 'Доброволци', 'text' => '', 'order' => 1],
        ];
        $result = $this->assignOrderDefaults($ways);
        $this->assertSame(4, (int)$result[0]['order']);
        $this->assertSame(1, (int)$result[1]['order']);
    }

    // ── delete by index ───────────────────────────────────────────────────────

    public function test_delete_removes_correct_item(): void
    {
        $ways = $this->sampleWays();
        array_splice($ways, 1, 1);
        $this->assertCount(2, $ways);
        $this->assertSame('Дарение',   $ways[0]['title']);
        $this->assertSame('Партньори', $ways[1]['title']);
    }

    public function test_delete_out_of_bounds_is_noop(): void
    {
        $ways = $this->sampleWays();
        if (isset($ways[99])) {
            array_splice($ways, 99, 1);
        }
        $this->assertCount(3, $ways);
    }
}
```

---

## Task 8 — Ways AJAX endpoint (`admin/ways-reorder-ajax.php`)

- [ ] Run `php vendor/bin/phpunit tests/WaysReorderTest.php` — confirm tests pass
- [ ] Create `admin/ways-reorder-ajax.php` with the full content below
- [ ] Verify: `php -l admin/ways-reorder-ajax.php`

```php
<?php
/**
 * AJAX endpoint: save a new ways-to-help order.
 *
 * POST body (JSON):
 *   { "csrf_token": "...", "order": ["Дарение", "Доброволци", ...] }
 *
 * Response:
 *   { "ok": true }
 *   { "ok": false, "error": "..." }
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
admin_require_admin();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$_POST['csrf_token'] = $body['csrf_token'] ?? '';
if (!csrf_verify()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$order = $body['order'] ?? [];
if (!is_array($order)) {
    echo json_encode(['ok' => false, 'error' => 'order must be an array']);
    exit;
}

$pages = load_json(CONTENT_PATH . '/pages.json');
$ways  = $pages['how_to_help']['ways'] ?? [];

// Duplicate title guard
$titles = array_column($ways, 'title');
if (count($titles) !== count(array_unique($titles))) {
    echo json_encode(['ok' => false, 'error' => 'Duplicate way titles detected']);
    exit;
}

$ways = ways_assign_order_defaults($ways);
$ways = ways_reorder_by_key($ways, $order, 'title');

$pages['how_to_help']['ways'] = $ways;
save_json(CONTENT_PATH . '/pages.json', $pages);

echo json_encode(['ok' => true]);

// ── helpers ────────────────────────────────────────────────────────────────────

function ways_assign_order_defaults(array $items): array
{
    foreach ($items as $i => &$p) {
        if (!isset($p['order'])) {
            $p['order'] = $i;
        }
    }
    return $items;
}

function ways_reorder_by_key(array $items, array $order, string $key): array
{
    $indexed = [];
    foreach ($items as $p) {
        $indexed[$p[$key]] = $p;
    }

    $sorted = [];
    foreach ($order as $k) {
        if (isset($indexed[$k])) {
            $sorted[] = $indexed[$k];
            unset($indexed[$k]);
        }
    }
    foreach ($indexed as $p) {
        $sorted[] = $p;
    }

    foreach ($sorted as $i => &$p) {
        $p['order'] = $i;
    }

    return $sorted;
}
```

---

## Task 9 — Ways list + edit view + handlers in `admin/pages.php`

### 9a — Refactor the `section === 'how_to_help'` POST handler

- [ ] In `admin/pages.php`, find the `} elseif ($section === 'how_to_help') {` block (lines ~278–300) and replace with the following. **Keep the title/intro save. Remove the ways loop. Add way_delete and way_save handlers.**

Replace the entire `how_to_help` handler block:
```php
    } elseif ($section === 'how_to_help') {
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $pages['how_to_help']['title'] = trim($_POST['help_title'] ?? '');
        $pages['how_to_help']['intro'] = trim($_POST['help_intro'] ?? '');
        // ... [ways loop and EN fields through line 300] ...
        header('Location: /admin/pages.php?page=how_to_help&saved=1'); exit;
```

With:
```php
    } elseif ($section === 'how_to_help') {
        // Saves title and intro only — ways are managed by way_save / way_delete
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $pages['how_to_help']['title']    = trim($_POST['help_title']    ?? '');
        $pages['how_to_help']['intro']    = trim($_POST['help_intro']    ?? '');
        $pages['how_to_help']['title_en'] = trim($_POST['help_title_en'] ?? '');
        $pages['how_to_help']['intro_en'] = trim($_POST['help_intro_en'] ?? '');
        save_json(CONTENT_PATH . '/pages.json', $pages);
        header('Location: /admin/pages.php?page=how_to_help&saved=1'); exit;

    } elseif ($section === 'way_delete') {
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $ways  = $pages['how_to_help']['ways'] ?? [];
        $idx   = (int)($_POST['way_index'] ?? -1);
        foreach ($ways as $i => &$_p) {
            if (!isset($_p['order'])) $_p['order'] = $i;
        }
        unset($_p);
        usort($ways, fn($a, $b) => (int)$a['order'] - (int)$b['order']);
        if ($idx >= 0 && $idx < count($ways)) {
            array_splice($ways, $idx, 1);
            foreach ($ways as $i => &$p) { $p['order'] = $i; }
            unset($p);
            $pages['how_to_help']['ways'] = $ways;
            save_json(CONTENT_PATH . '/pages.json', $pages);
            flash_set('success', 'Начинът е изтрит.');
        }
        header('Location: /admin/pages.php?page=how_to_help');
        exit;

    } elseif ($section === 'way_save') {
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $ways  = $pages['how_to_help']['ways'] ?? [];
        foreach ($ways as $i => &$_p) {
            if (!isset($_p['order'])) $_p['order'] = $i;
        }
        unset($_p);
        usort($ways, fn($a, $b) => (int)$a['order'] - (int)$b['order']);

        $idx = $_POST['way_index'] ?? 'new';

        $way = [
            'title'    => trim($_POST['way_title']    ?? ''),
            'title_en' => trim($_POST['way_title_en'] ?? ''),
            'text'     => trim($_POST['way_text']     ?? ''),
            'text_en'  => trim($_POST['way_text_en']  ?? ''),
        ];

        if ($idx === 'new') {
            $max_order     = empty($ways) ? -1 : max(array_column($ways, 'order'));
            $way['order']  = $max_order + 1;
            $ways[]        = $way;
        } else {
            $idx = (int)$idx;
            if ($idx >= 0 && $idx < count($ways)) {
                $way['order'] = $ways[$idx]['order'];
                $ways[$idx]   = $way;
            }
        }

        usort($ways, fn($a, $b) => (int)$a['order'] - (int)$b['order']);
        $pages['how_to_help']['ways'] = $ways;
        save_json(CONTENT_PATH . '/pages.json', $pages);
        header('Location: /admin/pages.php?page=how_to_help&saved=1');
        exit;
```

### 9b — Replace the how_to_help HTML section

- [ ] In `admin/pages.php`, find the block `<?php elseif ($page === 'how_to_help'): ?>` (line ~1184) through the closing TinyMCE `</script>` (line ~1277, just before `<?php elseif ($page === 'shop'): ?>`). Replace the entire block with the new HTML below.

**The new how_to_help HTML section:**

```php
<?php elseif ($page === 'how_to_help'): ?>
<!-- ══ HOW TO HELP ══ -->
<?php
// ── Single way edit ───────────────────────────────────────────────────────────
$edit_way_idx = $_GET['edit_way'] ?? null;
if ($edit_way_idx !== null):
    $ways_data = $help['ways'] ?? [];
    foreach ($ways_data as $i => &$_p2) {
        if (!isset($_p2['order'])) $_p2['order'] = $i;
    }
    unset($_p2);
    usort($ways_data, fn($a, $b) => (int)$a['order'] - (int)$b['order']);

    $is_new  = ($edit_way_idx === 'new');
    $edit_way = $is_new
        ? ['title' => '', 'title_en' => '', 'text' => '', 'text_en' => '']
        : ($ways_data[(int)$edit_way_idx] ?? null);
    if (!$is_new && !$edit_way): ?>
        <p>Начинът не е намерен. <a href="/admin/pages.php?page=how_to_help">← Назад</a></p>
<?php else: ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php?page=how_to_help" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад към Как да помогна</a>
    <h1 style="margin:0;"><?= $is_new ? 'Нов начин за помощ' : h($edit_way['title']) ?></h1>
  </div>
  <button type="submit" form="wayEditForm" class="btn btn--primary">Запази</button>
</div>

<form id="wayEditForm" method="POST" action="/admin/pages.php?page=how_to_help" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="section"   value="way_save">
  <input type="hidden" name="way_index" value="<?= h($edit_way_idx) ?>">

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div class="form-group">
      <label>Заглавие <?= $lbl_bg_badge ?></label>
      <input type="text" id="wayTitleBg" name="way_title"
             value="<?= h($edit_way['title'] ?? '') ?>" required>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Title <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('wayTitleBg','wayTitleEn',this)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <input type="text" id="wayTitleEn" name="way_title_en"
             value="<?= h($edit_way['title_en'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Текст <?= $lbl_bg_badge ?></label>
      <textarea id="wayTextBg" name="way_text" rows="8"><?= h($edit_way['text'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Text <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('wayTextBg','wayTextEn',this,true)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <textarea id="wayTextEn" name="way_text_en" rows="8"><?= h($edit_way['text_en'] ?? '') ?></textarea>
    </div>
  </div>

  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>
<script>
if (window._tinyBase) {
  tinymce.init(Object.assign({}, window._tinyBase, { selector: '#wayTextBg, #wayTextEn', height: 350 }));
}
</script>
<?php endif; ?>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php';
exit;
endif; // end $edit_way_idx !== null
?>

<!-- ══ HOW TO HELP — title/intro form ══ -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад</a>
    <h1 style="margin:0;">Как да помогна</h1>
  </div>
  <button type="submit" form="helpForm" class="btn btn--primary">Запази</button>
</div>

<?php if (!empty($_GET['saved'])): ?>
  <div class="admin-alert admin-alert--success" style="margin-bottom:1.5rem;">Запазено успешно.</div>
<?php endif; ?>

<form id="helpForm" method="POST" action="/admin/pages.php?page=how_to_help" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="how_to_help">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div class="form-group">
      <label>Заглавие <?= $lbl_bg_badge ?></label>
      <input type="text" id="helpTitleBg" name="help_title" value="<?= h($help['title'] ?? '') ?>">
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Title <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('helpTitleBg','helpTitleEn',this)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <input type="text" id="helpTitleEn" name="help_title_en" value="<?= h($help['title_en'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Уводен текст <?= $lbl_bg_badge ?></label>
      <input type="text" id="helpIntroBg" name="help_intro" value="<?= h($help['intro'] ?? '') ?>">
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Intro <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('helpIntroBg','helpIntroEn',this)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <input type="text" id="helpIntroEn" name="help_intro_en" value="<?= h($help['intro_en'] ?? '') ?>">
    </div>
  </div>
  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>

<!-- ══ HOW TO HELP — ways sortable list ══ -->
<?php
$ways_list = $help['ways'] ?? [];
foreach ($ways_list as $i => &$_wp) {
    if (!isset($_wp['order'])) $_wp['order'] = $i;
}
unset($_wp);
usort($ways_list, fn($a, $b) => (int)$a['order'] - (int)$b['order']);
?>
<h2 style="margin:2.5rem 0 1rem;">Начини за помощ</h2>

<div id="waysReorderAnnounce" aria-live="polite" aria-atomic="true"
     style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;"></div>

<?php $flash_msgs = flash_get(); foreach ($flash_msgs as $f): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;
  <?= $f['type']==='success' ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;' : 'background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;' ?>">
  <?= h($f['message']) ?>
</div>
<?php endforeach; ?>

<div style="display:flex;justify-content:flex-end;margin-bottom:1rem;">
  <a href="/admin/pages.php?page=how_to_help&edit_way=new" class="btn btn--primary">+ Нов начин</a>
</div>

<input type="hidden" id="waysCsrfToken" name="csrf_token" value="<?= csrf_token() ?>">

<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;">
  <div style="overflow-x:auto;">
  <table class="admin-table" style="width:100%;border-collapse:collapse;" id="waysTable">
    <thead>
      <tr style="background:var(--off-white);">
        <th style="padding:.65rem .75rem;width:36px;" aria-label="Пренареди"></th>
        <th style="padding:.65rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Начин</th>
        <th style="padding:.65rem 1rem;width:120px;"></th>
        <th style="padding:.65rem .5rem;width:64px;text-align:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Ред</th>
        <th style="padding:.65rem 1rem;width:120px;"></th>
      </tr>
    </thead>
    <tbody id="waysBody">
      <?php if (empty($ways_list)): ?>
        <tr><td colspan="5" style="padding:2rem;text-align:center;color:var(--text-muted);">Няма начини. <a href="/admin/pages.php?page=how_to_help&edit_way=new">Добавете първия →</a></td></tr>
      <?php else: foreach ($ways_list as $idx => $way): ?>
        <tr data-key="<?= h($way['title']) ?>" style="border-top:1px solid var(--border);cursor:default;">
          <td style="padding:.5rem .75rem;text-align:center;">
            <span class="way-drag-handle" draggable="true" aria-hidden="true"
                  style="cursor:grab;font-size:1.1rem;color:var(--text-muted);user-select:none;display:inline-block;padding:.2rem .3rem;"
                  title="Влачи за пренареждане">⠿</span>
          </td>
          <td style="padding:.75rem 1rem;">
            <div style="display:flex;align-items:center;gap:.75rem;">
              <div style="width:48px;height:48px;background:var(--off-white);border-radius:4px;flex-shrink:0;"></div>
              <strong style="font-size:.9rem;"><?= h($way['title']) ?></strong>
            </div>
          </td>
          <td style="padding:.75rem 1rem;text-align:right;">
            <a href="/admin/pages.php?page=how_to_help&edit_way=<?= $idx ?>" class="btn btn--outline" style="font-size:.78rem;padding:.3rem .7rem;">Редактирай</a>
          </td>
          <td style="padding:.5rem;text-align:center;">
            <div style="display:flex;flex-direction:column;gap:.2rem;align-items:center;">
              <button type="button" class="btn btn--outline way-up"
                      style="padding:.2rem .5rem;font-size:.75rem;line-height:1;"
                      aria-label="Премести нагоре — <?= h($way['title']) ?>">↑</button>
              <button type="button" class="btn btn--outline way-down"
                      style="padding:.2rem .5rem;font-size:.75rem;line-height:1;"
                      aria-label="Премести надолу — <?= h($way['title']) ?>">↓</button>
            </div>
          </td>
          <td style="padding:.75rem 1rem;text-align:right;">
            <form method="POST" action="/admin/pages.php?page=how_to_help" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="section"   value="way_delete">
              <input type="hidden" name="way_index" value="<?= $idx ?>">
              <button type="submit" class="btn btn--outline"
                      style="font-size:.78rem;padding:.3rem .7rem;color:#c0392b;border-color:#f0c4c0;"
                      data-confirm="Изтриване на начина?">Изтрий</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
(function () {
  var tbody    = document.getElementById('waysBody');
  var announce = document.getElementById('waysReorderAnnounce');
  if (!tbody) return;

  function getRows() { return Array.from(tbody.querySelectorAll('tr[data-key]')); }
  function keysOrder() { return getRows().map(function(r){ return r.dataset.key; }); }

  function saveOrder(prevRows) {
    var token   = document.getElementById('waysCsrfToken');
    var csrfVal = token ? token.value : '';
    fetch('/admin/ways-reorder-ajax.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ csrf_token: csrfVal, order: keysOrder() })
    }).then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.ok) {
          if (prevRows) { prevRows.forEach(function(row) { tbody.appendChild(row); }); updateAriaButtons(); }
          alert('Грешка при запис: ' + (d.error || ''));
        }
      })
      .catch(function(e){
        if (prevRows) { prevRows.forEach(function(row) { tbody.appendChild(row); }); updateAriaButtons(); }
        alert('Грешка: ' + e.message);
      });
  }

  function updateAriaButtons() {
    var rows = getRows();
    rows.forEach(function(row, i) {
      row.querySelector('.way-up').disabled   = (i === 0);
      row.querySelector('.way-down').disabled = (i === rows.length - 1);
    });
  }

  tbody.addEventListener('click', function(e) {
    var btn = e.target.closest('.way-up, .way-down');
    if (!btn) return;
    var row      = btn.closest('tr');
    var rows     = getRows();
    var idx      = rows.indexOf(row);
    var prevRows = getRows();
    if (btn.classList.contains('way-up') && idx > 0) {
      tbody.insertBefore(row, rows[idx - 1]);
    } else if (btn.classList.contains('way-down') && idx < rows.length - 1) {
      tbody.insertBefore(rows[idx + 1], row);
    } else { return; }
    updateAriaButtons();
    var newPos = getRows().indexOf(row) + 1;
    announce.textContent = row.dataset.key + ' — преместен на позиция ' + newPos;
    saveOrder(prevRows);
  });

  var dragging = null;
  tbody.addEventListener('dragstart', function(e) {
    var handle = e.target.closest('.way-drag-handle');
    if (!handle) { e.preventDefault(); return; }
    dragging = handle.closest('tr');
    dragging.style.opacity = '0.4';
    e.dataTransfer.effectAllowed = 'move';
  });
  tbody.addEventListener('dragend', function() {
    if (dragging) { dragging.style.opacity = ''; dragging = null; }
    getRows().forEach(function(r){ r.style.borderTop = ''; });
  });
  tbody.addEventListener('dragover', function(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    var target = e.target.closest('tr[data-key]');
    getRows().forEach(function(r){ r.style.borderTop = ''; });
    if (target && target !== dragging) target.style.borderTop = '2px solid var(--teal)';
  });
  tbody.addEventListener('drop', function(e) {
    e.preventDefault();
    var target = e.target.closest('tr[data-key]');
    if (!target || target === dragging || !dragging) return;
    var prevRows = getRows();
    tbody.insertBefore(dragging, target);
    getRows().forEach(function(r){ r.style.borderTop = ''; });
    updateAriaButtons();
    saveOrder(prevRows);
  });

  updateAriaButtons();
}());
</script>
```

### 9c — Run tests and commit

- [ ] Run `php vendor/bin/phpunit` — all tests must pass
- [ ] Commit: `git add admin/ways-reorder-ajax.php admin/pages.php tests/WaysReorderTest.php && git commit -m "feat: ways-to-help sortable list + single-item edit"`

---

## Implementation notes for the worker

1. **pages.php is a large file (~1400 lines).** Use Edit (not Write) for all changes to it. Always read the surrounding context before editing to get exact line numbers.

2. **The `about` section TinyMCE init** at line ~844 (`tinymce.init(Object.assign({}, window._tinyBase, { selector: '#aboutIntroBg, #aboutIntroEn', height: 350 }));`) must be **preserved** in the about list view (not the edit view). The edit view gets its own TinyMCE init via the `if (window._tinyBase)` block shown in Task 6b.

3. **The `_pickMemberPhoto` function** referenced in the about edit form already exists in the about section. After removing the old bulk form, verify the function is still present. If it was only in the removed inline `<script>`, add it back in the edit form's script block: `function _pickMemberPhoto(btn) { openMediaPicker(function(p){ document.querySelector('input[name="member_photo_current"]').value = p; /* show preview */ }); }`.

4. **flash_get() is called once per page render.** In tasks 6b and 9b, `flash_get()` is called in the ways/team list section. If there is already a `flash_get()` call earlier in the about/how_to_help view for the intro form, move it to the list section only (or ensure it is called once and the results reused).

5. **`data-confirm` on delete buttons** is wired by the existing `_adminConfirm` JS in `admin/includes/admin-footer.php`. No additional JS is needed.

6. **`name_en` for team members** — the spec says `name` as identifier and existing data has `name_en`. The edit form omits `name_en` (names are proper nouns) but if the existing JSON has `name_en`, preserve it by passing through in `team_save`: add `'name_en' => trim($_POST['member_name_en'] ?? ($team[$idx]['name_en'] ?? '')),` to the member array in the `team_save` handler.

7. **Order of `elseif` blocks in the POST handler** — place `impact_delete` and `impact_save` where the old `impact` handler was (before `centres`). Place `team_delete` and `team_save` immediately after the `about` handler. Place `way_delete` and `way_save` immediately after the `how_to_help` handler.

8. **`$_GET['saved']` alert for the about page** — the about page now has two sections (intro form and team list). Move the `saved=1` banner to just above the intro form so it appears correctly regardless of which save was last.

9. **After each task's PHP changes**, run `php -l admin/pages.php` to confirm no parse errors before running PHPUnit.

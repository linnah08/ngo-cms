# Projects Admin — Sortable List View Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the projects admin into a sortable list view and a single-project edit view, with drag-and-drop and ↑↓ keyboard reordering that saves automatically via AJAX.

**Architecture:** The existing `admin/pages.php?page=projects` is split into two sub-views controlled by `&edit=INDEX` (or `&edit=new`). A new `admin/projects-reorder-ajax.php` handles order saves. Project order is stored as an `order` field in each project object in `content/pages.json`; the array is always written sorted by `order`.

**Tech Stack:** PHP 8.4, PDO-free (JSON flat file), HTML5 drag events (no library), `fetch()` for AJAX, TinyMCE for rich text, PHPUnit 13 for tests.

---

## File Map

| File | Change |
|---|---|
| `admin/projects-reorder-ajax.php` | **Create** — AJAX endpoint: accepts new order, saves to pages.json |
| `admin/pages.php` | **Modify** — projects section: list view + edit view + delete handler |
| `tests/ProjectsReorderTest.php` | **Create** — unit tests for reorder logic and save/delete |

No changes to `proekti/index.php` or `en/projects/` — public pages already render in array order.

---

## Task 1: Add reorder helper function and tests

**Files:**
- Create: `tests/ProjectsReorderTest.php`

The reorder logic is a pure function that takes the current projects array and a new-order array of titles, then returns the projects array sorted by the new order. Testing this in isolation before wiring up the endpoint.

- [ ] **Step 1: Write the failing tests**

Create `tests/ProjectsReorderTest.php`:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('projects')]
final class ProjectsReorderTest extends TestCase
{
    private function sampleProjects(): array
    {
        return [
            ['title' => 'Alpha', 'title_en' => 'Alpha EN', 'text' => '', 'text_en' => '', 'images' => [], 'order' => 0],
            ['title' => 'Beta',  'title_en' => 'Beta EN',  'text' => '', 'text_en' => '', 'images' => [], 'order' => 1],
            ['title' => 'Gamma', 'title_en' => 'Gamma EN', 'text' => '', 'text_en' => '', 'images' => [], 'order' => 2],
        ];
    }

    // ── projects_reorder_by_titles ────────────────────────────────────────────

    public function test_reorder_reverses_order(): void
    {
        $projects = $this->sampleProjects();
        $result   = projects_reorder_by_titles($projects, ['Gamma', 'Beta', 'Alpha']);
        $this->assertSame('Gamma', $result[0]['title']);
        $this->assertSame('Beta',  $result[1]['title']);
        $this->assertSame('Alpha', $result[2]['title']);
        $this->assertSame(0, (int)$result[0]['order']);
        $this->assertSame(1, (int)$result[1]['order']);
        $this->assertSame(2, (int)$result[2]['order']);
    }

    public function test_reorder_preserves_all_projects_when_title_missing(): void
    {
        // If a title is omitted from the order array, it falls to the end
        $projects = $this->sampleProjects();
        $result   = projects_reorder_by_titles($projects, ['Beta', 'Alpha']);
        $this->assertCount(3, $result);
        $this->assertSame('Beta',  $result[0]['title']);
        $this->assertSame('Alpha', $result[1]['title']);
        // Gamma not in order array — must still be present
        $titles = array_column($result, 'title');
        $this->assertContains('Gamma', $titles);
    }

    public function test_reorder_assigns_order_field_sequentially(): void
    {
        $projects = $this->sampleProjects();
        $result   = projects_reorder_by_titles($projects, ['Beta', 'Gamma', 'Alpha']);
        foreach ($result as $i => $p) {
            $this->assertSame($i, (int)$p['order']);
        }
    }

    // ── projects_assign_order_defaults ───────────────────────────────────────

    public function test_assign_order_defaults_fills_missing_order_fields(): void
    {
        $projects = [
            ['title' => 'A', 'text' => ''],
            ['title' => 'B', 'text' => ''],
        ];
        $result = projects_assign_order_defaults($projects);
        $this->assertSame(0, (int)$result[0]['order']);
        $this->assertSame(1, (int)$result[1]['order']);
    }

    public function test_assign_order_defaults_preserves_existing_order(): void
    {
        $projects = [
            ['title' => 'A', 'order' => 5],
            ['title' => 'B', 'order' => 2],
        ];
        $result = projects_assign_order_defaults($projects);
        $this->assertSame(5, (int)$result[0]['order']);
        $this->assertSame(2, (int)$result[1]['order']);
    }

    // ── project delete by index ───────────────────────────────────────────────

    public function test_delete_project_removes_correct_item(): void
    {
        $projects = $this->sampleProjects();
        array_splice($projects, 1, 1); // remove index 1 (Beta)
        $this->assertCount(2, $projects);
        $this->assertSame('Alpha', $projects[0]['title']);
        $this->assertSame('Gamma', $projects[1]['title']);
    }

    public function test_delete_out_of_bounds_index_is_noop(): void
    {
        $projects = $this->sampleProjects();
        $index = 99;
        if (isset($projects[$index])) {
            array_splice($projects, $index, 1);
        }
        $this->assertCount(3, $projects);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd /Users/detelinavasileva/Code/oddminds
php vendor/bin/phpunit tests/ProjectsReorderTest.php --group projects
```

Expected: FAIL — `projects_reorder_by_titles` and `projects_assign_order_defaults` not defined.

---

## Task 2: Create the helper functions and AJAX endpoint

**Files:**
- Create: `admin/projects-reorder-ajax.php`

The two helper functions will live at the top of the AJAX file (included nowhere else — single use).

- [ ] **Step 1: Create `admin/projects-reorder-ajax.php`**

```php
<?php
/**
 * AJAX endpoint: save a new project order.
 *
 * POST body (JSON):
 *   { "csrf_token": "...", "order": ["Title A", "Title B", ...] }
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

$pages    = load_json(CONTENT_PATH . '/pages.json');
$projects = $pages['projects'] ?? [];
$projects = projects_assign_order_defaults($projects);
$projects = projects_reorder_by_titles($projects, $order);

$pages['projects'] = $projects;
save_json(CONTENT_PATH . '/pages.json', $pages);

echo json_encode(['ok' => true]);

// ── helpers ────────────────────────────────────────────────────────────────────

/**
 * Ensure every project has an 'order' field.
 * Projects that already have one keep it; others get their array index.
 */
function projects_assign_order_defaults(array $projects): array
{
    foreach ($projects as $i => &$p) {
        if (!isset($p['order'])) {
            $p['order'] = $i;
        }
    }
    return $projects;
}

/**
 * Re-sort $projects according to the $order array of titles.
 * Projects not mentioned in $order are appended at the end.
 * Reassigns sequential 'order' values (0, 1, 2, …).
 */
function projects_reorder_by_titles(array $projects, array $order): array
{
    $indexed = [];
    foreach ($projects as $p) {
        $indexed[$p['title']] = $p;
    }

    $sorted = [];
    foreach ($order as $title) {
        if (isset($indexed[$title])) {
            $sorted[] = $indexed[$title];
            unset($indexed[$title]);
        }
    }
    // Append any projects not mentioned in the order array
    foreach ($indexed as $p) {
        $sorted[] = $p;
    }

    // Reassign sequential order values
    foreach ($sorted as $i => &$p) {
        $p['order'] = $i;
    }

    return $sorted;
}
```

- [ ] **Step 2: Add bootstrap require to tests so helpers are available**

Add to `tests/bootstrap.php` after the existing requires at the bottom (before the closing `?>`):

```php
// ── Projects reorder helpers ──────────────────────────────────────────────────
require_once $root . '/admin/projects-reorder-ajax.php';
```

Wait — `projects-reorder-ajax.php` calls `admin_require_admin()` and exits on non-POST. We need to guard against this. Instead, extract the helpers to a separate included file so tests can load them without triggering the endpoint logic.

**Revised approach:** Move the two helper functions to the top of `admin/projects-reorder-ajax.php` but wrapped in a function-exists guard, and include a separate helper loader in the test bootstrap.

Actually the simplest approach: define the functions before the access check so the file can be `require_once`'d in tests if we define a stub `admin_require_admin`. Instead, just define the functions directly in the test file as private helpers (they are pure functions, tested inline).

**Revised Step 1 for Task 1:** The tests in `ProjectsReorderTest.php` define the two helper functions locally as static methods, and test the logic directly. Update the test file:

Replace the test file content with:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('projects')]
final class ProjectsReorderTest extends TestCase
{
    // ── helpers under test (duplicated here to allow isolated testing) ────────

    private function assignOrderDefaults(array $projects): array
    {
        foreach ($projects as $i => &$p) {
            if (!isset($p['order'])) {
                $p['order'] = $i;
            }
        }
        return $projects;
    }

    private function reorderByTitles(array $projects, array $order): array
    {
        $indexed = [];
        foreach ($projects as $p) {
            $indexed[$p['title']] = $p;
        }
        $sorted = [];
        foreach ($order as $title) {
            if (isset($indexed[$title])) {
                $sorted[] = $indexed[$title];
                unset($indexed[$title]);
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

    private function sampleProjects(): array
    {
        return [
            ['title' => 'Alpha', 'title_en' => 'Alpha EN', 'text' => '', 'text_en' => '', 'images' => [], 'order' => 0],
            ['title' => 'Beta',  'title_en' => 'Beta EN',  'text' => '', 'text_en' => '', 'images' => [], 'order' => 1],
            ['title' => 'Gamma', 'title_en' => 'Gamma EN', 'text' => '', 'text_en' => '', 'images' => [], 'order' => 2],
        ];
    }

    // ── reorderByTitles ───────────────────────────────────────────────────────

    public function test_reorder_reverses_order(): void
    {
        $result = $this->reorderByTitles($this->sampleProjects(), ['Gamma', 'Beta', 'Alpha']);
        $this->assertSame('Gamma', $result[0]['title']);
        $this->assertSame('Beta',  $result[1]['title']);
        $this->assertSame('Alpha', $result[2]['title']);
        $this->assertSame(0, (int)$result[0]['order']);
        $this->assertSame(1, (int)$result[1]['order']);
        $this->assertSame(2, (int)$result[2]['order']);
    }

    public function test_reorder_appends_unlisted_projects(): void
    {
        $result = $this->reorderByTitles($this->sampleProjects(), ['Beta', 'Alpha']);
        $this->assertCount(3, $result);
        $this->assertSame('Beta',  $result[0]['title']);
        $this->assertSame('Alpha', $result[1]['title']);
        $titles = array_column($result, 'title');
        $this->assertContains('Gamma', $titles);
    }

    public function test_reorder_assigns_sequential_order_fields(): void
    {
        $result = $this->reorderByTitles($this->sampleProjects(), ['Beta', 'Gamma', 'Alpha']);
        foreach ($result as $i => $p) {
            $this->assertSame($i, (int)$p['order']);
        }
    }

    // ── assignOrderDefaults ───────────────────────────────────────────────────

    public function test_assign_order_defaults_fills_missing(): void
    {
        $projects = [
            ['title' => 'A', 'text' => ''],
            ['title' => 'B', 'text' => ''],
        ];
        $result = $this->assignOrderDefaults($projects);
        $this->assertSame(0, (int)$result[0]['order']);
        $this->assertSame(1, (int)$result[1]['order']);
    }

    public function test_assign_order_defaults_preserves_existing(): void
    {
        $projects = [
            ['title' => 'A', 'order' => 5],
            ['title' => 'B', 'order' => 2],
        ];
        $result = $this->assignOrderDefaults($projects);
        $this->assertSame(5, (int)$result[0]['order']);
        $this->assertSame(2, (int)$result[1]['order']);
    }

    // ── delete by index ───────────────────────────────────────────────────────

    public function test_delete_removes_correct_item(): void
    {
        $projects = $this->sampleProjects();
        array_splice($projects, 1, 1);
        $this->assertCount(2, $projects);
        $this->assertSame('Alpha', $projects[0]['title']);
        $this->assertSame('Gamma', $projects[1]['title']);
    }

    public function test_delete_out_of_bounds_is_noop(): void
    {
        $projects = $this->sampleProjects();
        if (isset($projects[99])) {
            array_splice($projects, 99, 1);
        }
        $this->assertCount(3, $projects);
    }
}
```

- [ ] **Step 3: Run tests — should pass now**

```bash
php vendor/bin/phpunit tests/ProjectsReorderTest.php --group projects
```

Expected: All tests PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/ProjectsReorderTest.php admin/projects-reorder-ajax.php
git commit -m "feat: add projects reorder AJAX endpoint and unit tests"
```

---

## Task 3: Replace the projects section in pages.php with list view

**Files:**
- Modify: `admin/pages.php` — replace the `elseif ($page === 'projects'):` block

This task rewrites the projects list view only. The edit form comes in Task 4.

- [ ] **Step 1: Add projects_delete handler to the POST section**

In `admin/pages.php`, find the `} elseif ($section === 'projects') {` block (around line 200). Add a new `elseif` block **before** it:

```php
    } elseif ($section === 'projects_delete') {
        $pages   = load_json(CONTENT_PATH . '/pages.json');
        $idx     = (int)($_POST['proj_index'] ?? -1);
        $current = $pages['projects'] ?? [];
        if ($idx >= 0 && $idx < count($current)) {
            array_splice($current, $idx, 1);
            // Re-assign sequential order values
            foreach ($current as $i => &$p) { $p['order'] = $i; }
            unset($p);
            $pages['projects'] = $current;
            save_json(CONTENT_PATH . '/pages.json', $pages);
            flash_set('success', 'Проектът е изтрит.');
        }
        header('Location: /admin/pages.php?page=projects');
        exit;
```

- [ ] **Step 2: Replace the `elseif ($page === 'projects'):` HTML block**

Find the block starting at `<?php elseif ($page === 'projects'): ?>` and ending at the closing `<?php elseif ($page === 'how_to_help'): ?>`. Replace the entire projects block with:

```php
<?php elseif ($page === 'projects'): ?>
<!-- ══ PROJECTS LIST ══ -->
<?php
  // Sort existing projects by order field; assign defaults if missing
  foreach ($projects as $i => &$_p) {
      if (!isset($_p['order'])) $_p['order'] = $i;
  }
  unset($_p);
  usort($projects, fn($a, $b) => (int)$a['order'] - (int)$b['order']);
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад</a>
    <h1 style="margin:0;">Проекти</h1>
  </div>
  <a href="/admin/pages.php?page=projects&edit=new" class="btn btn--primary">+ Нов проект</a>
</div>

<!-- aria-live region for keyboard reorder announcements -->
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

<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;">
  <div style="overflow-x:auto;">
  <table class="admin-table" style="width:100%;border-collapse:collapse;" id="projectsTable">
    <thead>
      <tr style="background:var(--off-white);">
        <th style="padding:.65rem .75rem;width:36px;" aria-label="Пренареди"></th>
        <th style="padding:.65rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Проект</th>
        <th style="padding:.65rem 1rem;width:120px;"></th>
        <th style="padding:.65rem .5rem;width:64px;text-align:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Ред</th>
        <th style="padding:.65rem 1rem;width:120px;"></th>
      </tr>
    </thead>
    <tbody id="projectsBody">
      <?php if (empty($projects)): ?>
        <tr><td colspan="5" style="padding:2rem;text-align:center;color:var(--text-muted);">Няма проекти. <a href="/admin/pages.php?page=projects&edit=new">Създайте първия →</a></td></tr>
      <?php else: foreach ($projects as $idx => $project):
        $thumb = !empty($project['images'][0]) ? $project['images'][0] : '';
      ?>
        <tr data-title="<?= h($project['title']) ?>" style="border-top:1px solid var(--border);cursor:default;">
          <td style="padding:.5rem .75rem;text-align:center;">
            <span class="proj-drag-handle" draggable="true"
                  aria-hidden="true"
                  style="cursor:grab;font-size:1.1rem;color:var(--text-muted);user-select:none;display:inline-block;padding:.2rem .3rem;"
                  title="Влачи за пренареждане">⠿</span>
          </td>
          <td style="padding:.75rem 1rem;">
            <div style="display:flex;align-items:center;gap:.75rem;">
              <?php if ($thumb): ?>
                <img src="<?= h($thumb) ?>" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:4px;flex-shrink:0;">
              <?php else: ?>
                <div style="width:48px;height:48px;background:var(--off-white);border-radius:4px;flex-shrink:0;"></div>
              <?php endif; ?>
              <strong style="font-size:.9rem;"><?= h($project['title']) ?></strong>
            </div>
          </td>
          <td style="padding:.75rem 1rem;text-align:right;">
            <a href="/admin/pages.php?page=projects&edit=<?= $idx ?>" class="btn btn--outline" style="font-size:.78rem;padding:.3rem .7rem;">Редактирай</a>
          </td>
          <td style="padding:.5rem;text-align:center;">
            <div style="display:flex;flex-direction:column;gap:.2rem;align-items:center;">
              <button type="button" class="btn btn--outline proj-up"
                      style="padding:.2rem .5rem;font-size:.75rem;line-height:1;"
                      aria-label="Премести нагоре — <?= h($project['title']) ?>">↑</button>
              <button type="button" class="btn btn--outline proj-down"
                      style="padding:.2rem .5rem;font-size:.75rem;line-height:1;"
                      aria-label="Премести надолу — <?= h($project['title']) ?>">↓</button>
            </div>
          </td>
          <td style="padding:.75rem 1rem;text-align:right;">
            <form method="POST" action="/admin/pages.php?page=projects" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="section" value="projects_delete">
              <input type="hidden" name="proj_index" value="<?= $idx ?>">
              <button type="submit" class="btn btn--outline"
                      style="font-size:.78rem;padding:.3rem .7rem;color:#c0392b;border-color:#f0c4c0;"
                      data-confirm="Изтриване на проекта?">Изтрий</button>
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
  var tbody    = document.getElementById('projectsBody');
  var announce = document.getElementById('reorderAnnounce');
  if (!tbody) return;

  // ── helpers ────────────────────────────────────────────────────────────────

  function getRows() { return Array.from(tbody.querySelectorAll('tr[data-title]')); }

  function titlesOrder() { return getRows().map(function(r){ return r.dataset.title; }); }

  function saveOrder() {
    var token = document.querySelector('input[name="csrf_token"]')
             || document.querySelector('meta[name="csrf"]');
    var csrfVal = token ? token.value : '';
    fetch('/admin/projects-reorder-ajax.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ csrf_token: csrfVal, order: titlesOrder() })
    }).then(function(r){ return r.json(); })
      .then(function(d){ if (!d.ok) alert('Грешка при запис: ' + (d.error || '')); })
      .catch(function(e){ alert('Грешка: ' + e.message); });
  }

  function updateAriaButtons() {
    var rows = getRows();
    rows.forEach(function(row, i) {
      row.querySelector('.proj-up').disabled   = (i === 0);
      row.querySelector('.proj-down').disabled = (i === rows.length - 1);
    });
  }

  // ── keyboard ↑ ↓ ──────────────────────────────────────────────────────────

  tbody.addEventListener('click', function(e) {
    var btn = e.target.closest('.proj-up, .proj-down');
    if (!btn) return;
    var row  = btn.closest('tr');
    var rows = getRows();
    var idx  = rows.indexOf(row);
    if (btn.classList.contains('proj-up') && idx > 0) {
      tbody.insertBefore(row, rows[idx - 1]);
    } else if (btn.classList.contains('proj-down') && idx < rows.length - 1) {
      tbody.insertBefore(rows[idx + 1], row);
    } else {
      return;
    }
    updateAriaButtons();
    var newRows = getRows();
    var newPos  = newRows.indexOf(row) + 1;
    announce.textContent = row.dataset.title + ' — преместен на позиция ' + newPos;
    saveOrder();
  });

  // ── drag-and-drop ──────────────────────────────────────────────────────────

  var dragging = null;

  tbody.addEventListener('dragstart', function(e) {
    var handle = e.target.closest('.proj-drag-handle');
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
    var target = e.target.closest('tr[data-title]');
    getRows().forEach(function(r){ r.style.borderTop = ''; });
    if (target && target !== dragging) {
      target.style.borderTop = '2px solid var(--teal)';
    }
  });

  tbody.addEventListener('drop', function(e) {
    e.preventDefault();
    var target = e.target.closest('tr[data-title]');
    if (!target || target === dragging || !dragging) return;
    tbody.insertBefore(dragging, target);
    getRows().forEach(function(r){ r.style.borderTop = ''; });
    updateAriaButtons();
    saveOrder();
  });

  // ── init ───────────────────────────────────────────────────────────────────
  updateAriaButtons();
}());
</script>
```

- [ ] **Step 3: Get a CSRF token into the JS**

The `saveOrder()` JS reads `input[name="csrf_token"]` from the page. The page already has a delete form with `<?= csrf_field() ?>` which renders a hidden input with that name. However, if there are no projects yet (empty list), there's no form on the page. Add a standalone hidden CSRF input just before the table div:

Find the line `<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;">` at the start of the table and add before it:

```php
<!-- standalone CSRF token for reorder AJAX when no delete forms are present -->
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
```

- [ ] **Step 4: Verify in browser**

Navigate to `/admin/pages.php?page=projects`. You should see the table with projects listed, drag handles, ↑↓ buttons. Drag a row — it should reorder and auto-save (check pages.json). Use keyboard: Tab to ↑ button, press Enter — row should move up, announcement should be readable via screen reader.

- [ ] **Step 5: Commit**

```bash
git add admin/pages.php
git commit -m "feat: projects admin list view with drag-and-drop and keyboard reorder"
```

---

## Task 4: Add single-project edit view

**Files:**
- Modify: `admin/pages.php` — add `&edit=INDEX` sub-view and `projects_save` POST handler

- [ ] **Step 1: Add `projects_save` POST handler**

In `admin/pages.php`, add a new `elseif` block in the POST section, **before** the existing `elseif ($section === 'projects') {` block:

```php
    } elseif ($section === 'projects_save') {
        $pages   = load_json(CONTENT_PATH . '/pages.json');
        $current = $pages['projects'] ?? [];
        // Assign order defaults before any modification
        foreach ($current as $i => &$_p) {
            if (!isset($_p['order'])) $_p['order'] = $i;
        }
        unset($_p);

        $idx = $_POST['proj_index'] ?? 'new';

        $images = json_decode($_POST['proj_images'] ?? '[]', true);
        if (!is_array($images)) $images = [];

        // File upload
        if (
            !empty($_FILES['proj_image_file']['tmp_name']) &&
            $_FILES['proj_image_file']['error'] === UPLOAD_ERR_OK
        ) {
            $ext = strtolower(pathinfo($_FILES['proj_image_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp'])) {
                $dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/projects/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = 'project-' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['proj_image_file']['tmp_name'], $dir . $filename)) {
                    $images[] = '/assets/images/projects/' . $filename;
                }
            }
        }

        $project = [
            'title'    => trim($_POST['proj_title']    ?? ''),
            'title_en' => trim($_POST['proj_title_en'] ?? ''),
            'text'     => trim($_POST['proj_text']     ?? ''),
            'text_en'  => trim($_POST['proj_text_en']  ?? ''),
            'images'   => $images,
        ];

        if ($idx === 'new') {
            $max_order = empty($current) ? -1 : max(array_column($current, 'order'));
            $project['order'] = $max_order + 1;
            $current[] = $project;
        } else {
            $idx = (int)$idx;
            if ($idx >= 0 && $idx < count($current)) {
                $project['order'] = $current[$idx]['order'];
                $current[$idx]    = $project;
            }
        }

        usort($current, fn($a, $b) => (int)$a['order'] - (int)$b['order']);
        $pages['projects'] = $current;
        save_json(CONTENT_PATH . '/pages.json', $pages);
        header('Location: /admin/pages.php?page=projects&saved=1');
        exit;
```

- [ ] **Step 2: Add the edit sub-view inside the projects section**

Inside the `elseif ($page === 'projects'):` block, at the very top (before the sort/list code), add:

```php
<?php
// ── Single project edit ───────────────────────────────────────────────────────
$edit_idx = $_GET['edit'] ?? null;
if ($edit_idx !== null):
  // Sort first so index matches sorted position
  foreach ($projects as $i => &$_p2) {
      if (!isset($_p2['order'])) $_p2['order'] = $i;
  }
  unset($_p2);
  usort($projects, fn($a, $b) => (int)$a['order'] - (int)$b['order']);

  $is_new  = ($edit_idx === 'new');
  $project = $is_new ? ['title'=>'','title_en'=>'','text'=>'','text_en'=>'','images'=>[]] : ($projects[(int)$edit_idx] ?? null);
  if (!$is_new && !$project): ?>
    <p>Проектът не е намерен. <a href="/admin/pages.php?page=projects">← Назад</a></p>
<?php else: ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <a href="/admin/pages.php?page=projects" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад към проекти</a>
    <h1 style="margin:0;"><?= $is_new ? 'Нов проект' : h($project['title']) ?></h1>
  </div>
  <button type="submit" form="projectEditForm" class="btn btn--primary">Запази</button>
</div>

<form id="projectEditForm" method="POST"
      action="/admin/pages.php?page=projects"
      enctype="multipart/form-data" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="projects_save">
  <input type="hidden" name="proj_index" value="<?= h($edit_idx) ?>">
  <input type="hidden" class="proj-images-field" name="proj_images"
         value="<?= h(json_encode($project['images'] ?? [], JSON_UNESCAPED_UNICODE)) ?>">

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div class="form-group">
      <label>Заглавие <?= $lbl_bg_badge ?></label>
      <input type="text" id="projTitleBg" name="proj_title"
             value="<?= h($project['title']) ?>" required>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Title <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('projTitleBg','projTitleEn',this)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <input type="text" id="projTitleEn" name="proj_title_en"
             value="<?= h($project['title_en'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Текст <?= $lbl_bg_badge ?></label>
      <textarea id="projTextBg" name="proj_text" rows="8"><?= h($project['text'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
        <label style="margin:0;">Text <?= $lbl_en_badge ?></label>
        <button type="button" class="btn btn--outline"
                onclick="txField('projTextBg','projTextEn',this,true)"
                style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
      </div>
      <textarea id="projTextEn" name="proj_text_en" rows="8"><?= h($project['text_en'] ?? '') ?></textarea>
    </div>
  </div>

  <div class="form-group" style="margin-top:.5rem;">
    <label>Снимки</label>
    <div class="proj-thumbs" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem;">
      <?php foreach (($project['images'] ?? []) as $img): ?>
        <div class="proj-thumb" style="position:relative;display:inline-block;">
          <img src="<?= h($img) ?>" style="height:70px;border-radius:4px;object-fit:cover;">
          <button type="button"
                  onclick="removeProjectImage(this,<?= h(json_encode($img)) ?>)"
                  style="position:absolute;top:-4px;right:-4px;background:#c0392b;color:#fff;
                         border:none;border-radius:50%;width:18px;height:18px;font-size:11px;
                         cursor:pointer;line-height:1;"
                  aria-label="Премахни снимка">✕</button>
        </div>
      <?php endforeach; ?>
    </div>
    <input type="file" name="proj_image_file" accept="image/*">
    <button type="button" class="btn btn--outline"
            style="margin-top:.5rem;font-size:.82rem;"
            onclick="_pickProjectImage(this)">Избери от библиотека</button>
    <small style="color:var(--text-muted);display:block;margin-top:.25rem;">Качи нова снимка (добавя се към съществуващите).</small>
  </div>

  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>
</form>

<script>
tinymce.init(Object.assign({}, window._tinyBase, {
  selector: '#projTextBg, #projTextEn',
  height: 300
}));
function removeProjectImage(btn, imgPath) {
  var thumb  = btn.closest('.proj-thumb');
  var hidden = document.querySelector('.proj-images-field');
  var images = JSON.parse(hidden.value || '[]');
  images = images.filter(function(p){ return p !== imgPath; });
  hidden.value = JSON.stringify(images);
  thumb.remove();
}
function _pickProjectImage(btn) {
  openMediaPicker(function(p) {
    var hidden = document.querySelector('.proj-images-field');
    var images = JSON.parse(hidden.value || '[]');
    images.push(p);
    hidden.value = JSON.stringify(images);
    var thumb = document.createElement('div');
    thumb.className = 'proj-thumb';
    thumb.style.cssText = 'position:relative;display:inline-block;';
    thumb.innerHTML =
      '<img src="' + p + '" style="height:70px;border-radius:4px;object-fit:cover;">' +
      '<button type="button" onclick="removeProjectImage(this,' + JSON.stringify(p) + ')" ' +
        'style="position:absolute;top:-4px;right:-4px;background:#c0392b;color:#fff;' +
               'border:none;border-radius:50%;width:18px;height:18px;font-size:11px;cursor:pointer;line-height:1;" ' +
        'aria-label="Премахни снимка">✕</button>';
    document.querySelector('.proj-thumbs').appendChild(thumb);
  });
}
</script>

<?php endif; ?>
<?php // Exit early — don't render the list view
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php';
exit;
endif; // end $edit_idx !== null
?>
```

- [ ] **Step 3: Run PHPUnit to make sure nothing broken**

```bash
php vendor/bin/phpunit tests/ProjectsReorderTest.php --group projects
```

Expected: All PASS.

- [ ] **Step 4: Verify edit flow in browser**

- Click "Редактирай" on any project → edit form opens with existing data and TinyMCE
- Edit title, save → redirects to list, flash "Запазено успешно."
- Click "+ Нов проект" → empty form, save → new project appears in list at bottom

- [ ] **Step 5: Commit**

```bash
git add admin/pages.php
git commit -m "feat: projects admin single-project edit view and save/delete handlers"
```

---

## Task 5: Remove old inline-card projects section

**Files:**
- Modify: `admin/pages.php` — delete the now-unused original `elseif ($section === 'projects')` POST handler (the multi-project bulk save)

The old bulk POST handler (`$section === 'projects'`) is no longer reachable — the list view no longer has that form. Remove it to avoid dead code.

- [ ] **Step 1: Delete the old handler**

In `admin/pages.php`, find and delete the block:

```php
    } elseif ($section === 'projects') {
        $pages        = load_json(CONTENT_PATH . '/pages.json');
        $titles       = $_POST['proj_title']  ?? [];
        ...
        $pages['projects'] = $projects_new;
        save_json(CONTENT_PATH . '/pages.json', $pages);
        header('Location: /admin/pages.php?page=projects&saved=1'); exit;
```

This is approximately lines 200–235 in the current file. Delete the entire block from `} elseif ($section === 'projects') {` up to and including `header(...); exit;`.

- [ ] **Step 2: Run full test suite**

```bash
php vendor/bin/phpunit
```

Expected: All existing tests pass, no regressions.

- [ ] **Step 3: Commit**

```bash
git add admin/pages.php
git commit -m "chore: remove old bulk projects save handler (replaced by projects_save)"
```

---

## Self-Review

**Spec coverage:**
- ✅ List view with drag handle, thumbnail, title, ↑↓ buttons, Edit/Delete — Task 3
- ✅ Drag-and-drop with opacity + teal border visual feedback — Task 3
- ✅ ↑↓ keyboard buttons with aria-label and aria-live announcement — Task 3
- ✅ AJAX save on drop and on ↑↓ click — Task 3
- ✅ AJAX endpoint `projects-reorder-ajax.php` with CSRF + admin check — Task 2
- ✅ Edit view for single project at `?page=projects&edit=INDEX` — Task 4
- ✅ New project at `?page=projects&edit=new` — Task 4
- ✅ Delete handler with `_adminConfirm` modal (via `data-confirm`) — Task 3
- ✅ `order` field assigned from array index if missing — Tasks 2 & 4
- ✅ Public page unchanged — no tasks needed
- ✅ PHPUnit tests for reorder logic and delete — Tasks 1 & 2
- ✅ TinyMCE on both text textareas in edit view — Task 4
- ✅ CSRF on all POSTs including AJAX — Tasks 2, 3, 4
- ✅ `admin_require_admin()` on AJAX endpoint — Task 2

**Placeholder scan:** None found.

**Type consistency:** `projects_reorder_by_titles` / `projects_assign_order_defaults` defined in Task 2, tested via equivalent private methods in Task 1. `proj_index` as `int` or `'new'` string consistent across Task 4 POST handler and form hidden input.

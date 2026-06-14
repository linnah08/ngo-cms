    </div><!-- .admin-content -->
  </main><!-- .admin-main -->
</div><!-- .admin-wrapper -->

<!-- ── Confirmation modal ──────────────────────────────────────────────────── -->
<div id="adminConfirmOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;padding:1rem;"
     role="dialog" aria-modal="true">
  <div style="background:#fff;border-radius:10px;padding:2rem;max-width:420px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,.25);">
    <p id="adminConfirmMsg" style="font-size:1rem;line-height:1.55;margin:0 0 1.5rem;color:#111;"></p>
    <div style="display:flex;gap:.75rem;justify-content:flex-end;">
      <button id="adminConfirmCancel"
              style="padding:.55rem 1.1rem;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;font-size:.9rem;">Отказ</button>
      <button id="adminConfirmOk"
              style="padding:.55rem 1.1rem;border:1px solid #c0392b;border-radius:6px;background:#c0392b;color:#fff;cursor:pointer;font-size:.9rem;font-weight:600;">Изтрий</button>
    </div>
  </div>
</div>

<!-- ── Media library picker ──────────────────────────────────────────────── -->
<div id="mediaLibOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:10000;align-items:flex-start;justify-content:center;padding:2rem;overflow-y:auto;"
     role="dialog" aria-modal="true">
  <div style="background:#fff;border-radius:10px;padding:1.5rem;width:100%;max-width:780px;box-shadow:0 24px 64px rgba(0,0,0,.25);margin:auto;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
      <h2 style="margin:0;font-size:1rem;font-weight:600;">Избери снимка от библиотеката</h2>
      <button id="mediaLibClose" type="button"
              style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#666;line-height:1;padding:.25rem .5rem;">✕</button>
    </div>
    <div id="mediaLibGrid"
         style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));grid-auto-rows:80px;gap:.5rem;max-height:440px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px;padding:.5rem;min-height:80px;"></div>
  </div>
</div>

<!-- ── Table sorting ────────────────────────────────────────────────────── -->
<script>
(function () {
  document.querySelectorAll('.admin-table th[data-sort]').forEach(function (th) {
    th.addEventListener('click', function () {
      var table = th.closest('table');
      var tbody = table.querySelector('tbody');
      var ths   = Array.from(th.closest('thead').querySelectorAll('th'));
      var col   = ths.indexOf(th);
      var asc   = !th.classList.contains('sort-asc');

      // Reset all headers
      ths.forEach(function (h) { h.classList.remove('sort-asc', 'sort-desc'); });
      th.classList.add(asc ? 'sort-asc' : 'sort-desc');

      var rows = Array.from(tbody.querySelectorAll('tr'));
      rows.sort(function (a, b) {
        var aVal = cellText(a, col);
        var bVal = cellText(b, col);
        // Detect date (YYYY-MM-DD) or number
        if (/^\d{4}-\d{2}-\d{2}/.test(aVal) && /^\d{4}-\d{2}-\d{2}/.test(bVal)) {
          return asc ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
        }
        var aNum = parseFloat(aVal.replace(/[^0-9.-]/g, ''));
        var bNum = parseFloat(bVal.replace(/[^0-9.-]/g, ''));
        if (!isNaN(aNum) && !isNaN(bNum)) {
          return asc ? aNum - bNum : bNum - aNum;
        }
        return asc
          ? aVal.localeCompare(bVal, 'bg')
          : bVal.localeCompare(aVal, 'bg');
      });

      rows.forEach(function (r) { tbody.appendChild(r); });
    });
  });

  function cellText(row, col) {
    var cell = row.querySelectorAll('td')[col];
    if (!cell) return '';
    // Prefer data-sort attribute on the cell for custom sort keys (e.g. ISO date)
    return (cell.dataset.sort || cell.innerText || '').trim();
  }
})();
</script>


<script>
(function () {
  var overlay   = document.getElementById('mediaLibOverlay');
  var grid      = document.getElementById('mediaLibGrid');
  var closeBtn  = document.getElementById('mediaLibClose');
  var _cb       = null;
  var _cache    = null;

  window.openMediaPicker = function (cb) {
    _cb = cb;
    overlay.style.display = 'flex';
    if (_cache) { renderGrid(); return; }
    grid.innerHTML = '<p style="grid-column:1/-1;color:#888;padding:1rem;text-align:center;">Зарежда…</p>';
    fetch('/admin/media-library-ajax.php')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        _cache = data.images || [];
        renderGrid();
      })
      .catch(function () {
        grid.innerHTML = '<p style="grid-column:1/-1;color:#c0392b;padding:1rem;text-align:center;">Грешка при зареждане.</p>';
      });
  };

  function renderGrid() {
    var list = _cache || [];
    if (!list.length) {
      grid.innerHTML = '<p style="grid-column:1/-1;color:#888;padding:1rem;text-align:center;">Няма снимки.</p>';
      return;
    }
    var html = '';
    list.forEach(function (img) {
      var p = img.path.replace(/"/g, '&quot;');
      html += '<div class="mlib-item" data-path="' + p + '" '
            + 'style="cursor:pointer;border-radius:6px;overflow:hidden;border:2px solid transparent;'
            + 'transition:border-color .12s;background:#f3f4f6;" title="' + p + '">'
            + '<img src="' + p + '" loading="lazy" '
            + 'style="width:100%;height:80px;object-fit:cover;display:block;"></div>';
    });
    grid.innerHTML = html;
    grid.querySelectorAll('.mlib-item').forEach(function (el) {
      el.addEventListener('mouseenter', function () { this.style.borderColor = 'var(--teal)'; });
      el.addEventListener('mouseleave', function () { this.style.borderColor = 'transparent'; });
      el.addEventListener('click', function () {
        var path = this.dataset.path;
        closeModal();
        if (_cb) { _cb(path); }
      });
    });
  }

  function closeModal() { overlay.style.display = 'none'; }

  closeBtn.addEventListener('click', closeModal);
  overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
  document.addEventListener('keydown', function (e) {
    if (overlay.style.display !== 'none' && e.key === 'Escape') closeModal();
  });

  /* ── Shared helpers called by individual pages ── */

  window._pickMemberPhoto = function (btn) {
    openMediaPicker(function (p) {
      var row = btn.closest('.member-row');
      row.querySelector('[name="member_photo[]"]').value = p;
      var fg  = btn.closest('.form-group');
      var img = fg.querySelector('img');
      if (img) {
        img.src = p;
      } else {
        img = document.createElement('img');
        img.src = p; img.alt = '';
        img.style.cssText = 'height:80px;border-radius:4px;margin-bottom:.5rem;display:block;';
        fg.insertBefore(img, btn);
      }
    });
  };

  window._pickProjectImage = function (btn) {
    openMediaPicker(function (p) {
      var row   = btn.closest('.project-row');
      var hf    = row.querySelector('.proj-images-field');
      var imgs  = JSON.parse(hf.value || '[]');
      imgs.push(p);
      hf.value = JSON.stringify(imgs);
      var thumbs = row.querySelector('.proj-thumbs');
      var div = document.createElement('div');
      div.className = 'proj-thumb';
      div.style.cssText = 'position:relative;display:inline-block;';
      div.innerHTML = '<img src="' + p.replace(/"/g, '&quot;') + '" style="height:70px;border-radius:4px;object-fit:cover;">'
        + '<button type="button" onclick="removeProjectImage(this,' + JSON.stringify(p) + ')"'
        + ' style="position:absolute;top:-4px;right:-4px;background:#c0392b;color:#fff;border:none;'
        + 'border-radius:50%;width:18px;height:18px;font-size:11px;cursor:pointer;line-height:1;">✕</button>';
      thumbs.appendChild(div);
    });
  };
})();

(function () {
  /* ── Hamburger ── */
  var hamburger = document.getElementById('adminHamburger');
  var sidebar   = document.getElementById('adminSidebar');
  if (hamburger && sidebar) {
    hamburger.addEventListener('click', function () { sidebar.classList.toggle('open'); });
  }

  /* ── Confirmation modal ── */
  var overlay   = document.getElementById('adminConfirmOverlay');
  var msgEl     = document.getElementById('adminConfirmMsg');
  var cancelBtn = document.getElementById('adminConfirmCancel');
  var okBtn     = document.getElementById('adminConfirmOk');
  var _resolve  = null;

  function showConfirm(msg, okLabel) {
    msgEl.textContent   = msg;
    okBtn.textContent   = okLabel || 'Изтрий';
    overlay.style.display = 'flex';
    okBtn.focus();
    return new Promise(function (res) { _resolve = res; });
  }

  function closeModal(result) {
    overlay.style.display = 'none';
    if (_resolve) { _resolve(result); _resolve = null; }
  }

  cancelBtn.addEventListener('click', function () { closeModal(false); });
  okBtn.addEventListener('click',     function () { closeModal(true);  });
  overlay.addEventListener('click',   function (e) { if (e.target === overlay) closeModal(false); });
  document.addEventListener('keydown', function (e) {
    if (overlay.style.display !== 'none' && e.key === 'Escape') closeModal(false);
  });

  /* Intercept forms with data-confirm */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var msg  = form.dataset.confirm;
    if (!msg) return;
    e.preventDefault();
    var ok = form.dataset.confirmOk;
    showConfirm(msg, ok).then(function (confirmed) {
      if (confirmed) { form.dataset.confirm = ''; form.submit(); }
    });
  }, true);

  /* Intercept buttons/links with data-confirm that are NOT inside a data-confirm form */
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (!el) return;
    var parentForm = el.closest('form[data-confirm]');
    if (parentForm) return; /* form handler takes care of it */
    e.preventDefault();
    var msg = el.dataset.confirm;
    var ok  = el.dataset.confirmOk;
    showConfirm(msg, ok).then(function (confirmed) {
      if (!confirmed) return;
      var form = el.closest('form');
      if (form) { el.dataset.confirm = ''; form.submit(); }
      else if (el.href) { location.href = el.href; }
    });
  }, true);

  /* Expose for manual use (e.g. orders bulk delete) */
  window._adminConfirm = showConfirm;
})();
</script>

<!-- ── BG→EN translate helpers (DeepL) — shared by all content edit screens ── -->
<script>
function _tmGet(el) {
  var ed = el && el.id && window.tinymce ? tinymce.get(el.id) : null;
  return ed ? ed.getContent() : (el ? el.value : '');
}
function _tmSet(el, html) {
  var ed = el && el.id && window.tinymce ? tinymce.get(el.id) : null;
  if (ed) ed.setContent(html); else if (el) el.value = html;
}
async function txEl(bgEl, enEl, btn, isHtml) {
  if (!bgEl || !enEl) return;
  var bgVal = _tmGet(bgEl);
  if (!bgVal.trim()) return;
  var orig = btn.textContent;
  btn.disabled = true; btn.textContent = '…';
  try {
    var res  = await fetch('/admin/translate-ajax.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({text: bgVal, is_html: !!isHtml})
    });
    var data = await res.json();
    if (data.ok) { _tmSet(enEl, data.translated); btn.textContent = '✓'; }
    else { alert('Error: ' + data.error); btn.textContent = orig; }
  } catch(e) { alert('Error: ' + e.message); btn.textContent = orig; }
  btn.disabled = false;
}
function txField(bgId, enId, btn, isHtml) {
  txEl(document.getElementById(bgId), document.getElementById(enId), btn, isHtml);
}
document.querySelectorAll('.translate-legal-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    txEl(document.getElementById(this.dataset.src), document.getElementById(this.dataset.tgt), this, true);
  });
});
</script>
<script src="/admin/js/session-guard.js"></script>
</body>
</html>

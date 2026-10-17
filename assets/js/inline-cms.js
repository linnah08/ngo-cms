// assets/js/inline-cms.js
window.OmCMS = (function () {
  'use strict';

  let editMode = false;
  let activeLang = window._omLang || 'bg';
  // dirtyFields: { fieldKey: { bg: '...', en: '...' } }
  let dirtyFields = {};
  // pendingModalType: string used by openModal/submitModal
  let pendingModalType = null;

  // ── Edit mode ────────────────────────────────────────────────────────────

  function toggleEdit() {
    if (_framing) _endFraming(false);
    editMode = !editMode;
    document.body.classList.toggle('om-edit-mode', editMode);

    const btn = document.getElementById('om-edit-toggle');
    const label = document.getElementById('om-edit-label');
    btn.classList.toggle('om-active', editMode);
    label.textContent = editMode ? 'Edit mode on' : 'Edit mode off';

    if (editMode) {
      _activateEditables();
    } else {
      _deactivateEditables();
    }
  }

  function _activateEditables() {
    document.querySelectorAll('[data-cms-field]').forEach(function (el) {
      // TinyMCE opens these on click or focus; tabindex lets a keyboard user reach them.
      if (el.dataset.cmsType === 'richtext') {
        if (!el.hasAttribute('tabindex')) { el.setAttribute('tabindex', '0'); el.dataset.omTab = '1'; }
        return;
      }
      // A photo is changed through its dialog, never typed into: a typed-in wrap marked the
      // image dirty with its HTML on blur. It acts as a button instead, so keys reach it too.
      if (el.classList.contains('om-img-wrap')) {
        el.setAttribute('tabindex', '0');
        el.setAttribute('role', 'button');
        el.setAttribute('aria-label', 'Смени или нагласи снимката');
        return;
      }
      el.setAttribute('contenteditable', 'true');
      el.addEventListener('blur', _onBlur);
    });
    _injectAddRemoveControls();
  }

  function _deactivateEditables() {
    document.querySelectorAll('[data-cms-field]').forEach(function (el) {
      el.removeAttribute('contenteditable');
      el.removeEventListener('blur', _onBlur);
      if (el.dataset.omTab) { el.removeAttribute('tabindex'); delete el.dataset.omTab; }
    });
    document.querySelectorAll('.om-img-wrap[role="button"]').forEach(function (el) {
      el.removeAttribute('tabindex');
      el.removeAttribute('role');
      el.removeAttribute('aria-label');
    });
    // Destroy TinyMCE inline instances
    if (window.tinymce) {
      tinymce.get().slice().forEach(function (ed) { ed.remove(); }); // get() with no id: every editor (TinyMCE 6+ has no .editors)
    }
    _removeAddRemoveControls();
  }

  // ── Language switching ────────────────────────────────────────────────────

  function setLang(lang) {
    if (lang === activeLang) return;

    // Save current content into data attributes before switching
    document.querySelectorAll('[data-cms-field]').forEach(function (el) {
      if (el.dataset.cmsType === 'richtext' && window.tinymce) {
        const ed = tinymce.get(el.id);
        if (ed) el.dataset['cms' + _cap(activeLang)] = ed.getContent();
      } else {
        el.dataset['cms' + _cap(activeLang)] = el.dataset.cmsType === 'richtext' ? el.innerHTML : el.textContent;
      }
    });

    activeLang = lang;

    // Swap displayed content to new language
    document.querySelectorAll('[data-cms-field]').forEach(function (el) {
      const val = el.dataset['cms' + _cap(lang)] || '';
      if (el.dataset.cmsType === 'richtext' && window.tinymce) {
        const ed = tinymce.get(el.id);
        if (ed) ed.setContent(val);
      } else {
        if (el.dataset.cmsType === 'richtext') {
          el.innerHTML = val;
        } else {
          el.textContent = val;
        }
      }
    });

    // Update toolbar buttons
    document.getElementById('om-btn-bg').classList.toggle('om-active', lang === 'bg');
    document.getElementById('om-btn-en').classList.toggle('om-active', lang === 'en');
  }

  function _cap(s) {
    return s.charAt(0).toUpperCase() + s.slice(1);
  }

  // ── Dirty tracking ────────────────────────────────────────────────────────

  function _onBlur(e) {
    const el = e.currentTarget;
    const field = el.dataset.cmsField;
    const section = el.dataset.cmsSection;
    _dirtyEntry(section, field, el)[activeLang] = el.innerHTML;
    el.dataset['cms' + _cap(activeLang)] = el.innerHTML;
    _showSaveBtn();
  }

  // The first edit of a field creates its entry. Both languages start from the
  // stored values the page carries (data-cms-bg / data-cms-en), so saving an edit
  // made in one language never blanks the other one.
  function _dirtyEntry(section, field, el) {
    const key = section + '.' + field;
    if (!dirtyFields[key]) {
      if (!el) {
        el = Array.prototype.find.call(document.querySelectorAll('[data-cms-field]'), function (c) {
          return c.dataset.cmsSection === section && c.dataset.cmsField === field;
        }) || null;
      }
      dirtyFields[key] = {
        section: section, field: field,
        bg: (el && el.dataset.cmsBg) || '',
        en: (el && el.dataset.cmsEn) || ''
      };
    }
    return dirtyFields[key];
  }

  function markDirty(section, field, lang, value, el) {
    _dirtyEntry(section, field, el)[lang] = value;
    _showSaveBtn();
  }

  function _showSaveBtn() {
    document.getElementById('om-save-btn').classList.add('om-dirty');
  }

  function _hideSaveBtn() {
    document.getElementById('om-save-btn').classList.remove('om-dirty', 'om-error', 'om-saving');
  }

  // ── TinyMCE inline ────────────────────────────────────────────────────────

  let _rtWarned = false;

  function _initRichText(el) {
    if (!window.tinymce) {
      // Never a silent no-op: say why the text cannot be edited here.
      if (!_rtWarned) { _rtWarned = true; alert('Редакторът на текст не се зареди (проверете интернет връзката) — презаредете страницата. Текстът може да се промени и от Админ панела.'); }
      return;
    }
    if (!el.id) el.id = 'om-rt-' + Math.random().toString(36).slice(2);

    const cfg = Object.assign({}, window._tinyBase, {
      selector: '#' + el.id,
      inline: true,
      // The click or Tab that opened the editor should land the caret in it.
      init_instance_callback: function (ed) { ed.focus(); },
      setup: function (ed) {
        if (window._tinyBase && typeof window._tinyBase.setup === 'function') {
          window._tinyBase.setup(ed);
        }
        ed.on('Change', function () {
          markDirty(el.dataset.cmsSection, el.dataset.cmsField, activeLang, ed.getContent(), el);
          el.dataset['cms' + _cap(activeLang)] = ed.getContent();
        });
      }
    });
    tinymce.init(cfg);
  }

  // Click- or focus-to-init rich text fields
  function _onRichTextActivate(e) {
    if (!editMode) return;
    const el = e.target.closest('[data-cms-type="richtext"]');
    if (!el) return;
    if (window.tinymce && tinymce.get(el.id)) return; // already inited
    _initRichText(el);
  }
  document.addEventListener('click', _onRichTextActivate);
  document.addEventListener('focusin', _onRichTextActivate);

  // ── Image replacement ─────────────────────────────────────────────────────

  var _libCache = null;

  function _setupImageWrap(wrap) {
    if (wrap.dataset.omImgBound) return;
    wrap.dataset.omImgBound = '1';
    wrap.addEventListener('click', function () {
      if (!editMode || _framing) return;
      _showImageOptions(wrap);
    });
    wrap.addEventListener('keydown', function (e) {
      if (!editMode || _framing || (e.key !== 'Enter' && e.key !== ' ')) return;
      e.preventDefault();
      _showImageOptions(wrap);
    });
  }

  function _showImageOptions(wrap) {
    var existing = document.getElementById('om-img-options');
    if (existing) existing.remove();

    var overlay = document.createElement('div');
    overlay.id = 'om-img-options';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200000;display:flex;align-items:center;justify-content:center;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif';

    var box = document.createElement('div');
    box.style.cssText = 'background:#fff;border-radius:12px;padding:24px;max-width:300px;width:90vw;box-shadow:0 8px 32px rgba(0,0,0,.2)';

    var title = document.createElement('p');
    title.style.cssText = 'margin:0 0 16px;font-size:15px;font-weight:600;color:#1a1a2e';
    title.textContent = 'Change photo';

    var btnLib = document.createElement('button');
    btnLib.style.cssText = 'display:block;width:100%;padding:10px 16px;border-radius:8px;border:1px solid #e5e7eb;background:#f9fafb;font-size:14px;cursor:pointer;margin-bottom:8px;text-align:left';
    btnLib.textContent = '\uD83D\uDCC1\u2002Choose from library';

    var btnUpload = document.createElement('button');
    btnUpload.style.cssText = 'display:block;width:100%;padding:10px 16px;border-radius:8px;border:1px solid #e5e7eb;background:#f9fafb;font-size:14px;cursor:pointer;margin-bottom:16px;text-align:left';
    btnUpload.textContent = '\u2B06\uFE0F\u2002Upload new photo';

    var btnCancel = document.createElement('button');
    btnCancel.style.cssText = 'display:block;width:100%;padding:8px 16px;border-radius:8px;border:none;background:transparent;font-size:13px;color:#666;cursor:pointer;text-align:center';
    btnCancel.textContent = 'Cancel';

    // Blocks that print data-om-frame (templates/home/*) can also be framed on the page.
    var btnFrame = null;
    if (wrap.hasAttribute('data-om-frame') && wrap.closest('[data-om-fbox]')) {
      btnFrame = document.createElement('button');
      btnFrame.style.cssText = btnUpload.style.cssText;
      btnFrame.textContent = '\uD83C\uDFAF\u2002Нагласи снимката';
      btnUpload.style.marginBottom = '8px';
      btnFrame.addEventListener('click', function () { close(); _startFraming(wrap); });
    }

    function close() {
      document.removeEventListener('keydown', onKey, true);
      if (overlay.parentNode) document.body.removeChild(overlay);
    }
    function cancel() { close(); if (wrap.hasAttribute('tabindex')) wrap.focus(); }
    // Keyboard: Esc closes, Tab stays inside the dialog.
    function onKey(e) {
      if (e.key === 'Escape') { e.preventDefault(); cancel(); return; }
      if (e.key !== 'Tab') return;
      var f = box.querySelectorAll('button'), first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    btnLib.addEventListener('click', function () { close(); _openLibraryPicker(wrap); });
    btnUpload.addEventListener('click', function () {
      close();
      const input = document.createElement('input');
      input.type = 'file';
      input.accept = 'image/jpeg,image/png,image/webp';
      input.onchange = function () {
        if (!input.files[0]) return;
        _uploadImage(input.files[0], wrap);
      };
      input.click();
    });
    btnCancel.addEventListener('click', cancel);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) cancel(); });

    title.id = 'om-img-options-title';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-labelledby', title.id);
    box.appendChild(title);
    box.appendChild(btnLib);
    box.appendChild(btnUpload);
    if (btnFrame) box.appendChild(btnFrame);
    box.appendChild(btnCancel);
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    document.addEventListener('keydown', onKey, true);
    btnLib.focus();
  }

  function _openLibraryPicker(wrap) {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:200000;display:flex;align-items:flex-start;justify-content:center;padding:2rem;overflow-y:auto;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif';

    var box = document.createElement('div');
    box.style.cssText = 'background:#fff;border-radius:10px;padding:1.5rem;width:100%;max-width:780px;box-shadow:0 24px 64px rgba(0,0,0,.25);margin:auto';

    var header = document.createElement('div');
    header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem';

    var titleEl = document.createElement('h2');
    titleEl.style.cssText = 'margin:0;font-size:1rem;font-weight:600';
    titleEl.textContent = 'Избери снимка от библиотеката';

    var closeBtn = document.createElement('button');
    closeBtn.style.cssText = 'background:none;border:none;font-size:1.4rem;cursor:pointer;color:#666;line-height:1;padding:.25rem .5rem';
    closeBtn.textContent = '\u2715';

    var grid = document.createElement('div');
    grid.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:.5rem;max-height:440px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px;padding:.5rem;min-height:80px';

    function close() { document.body.removeChild(overlay); }

    function renderGrid() {
      var list = _libCache || [];
      if (!list.length) {
        grid.innerHTML = '<p style="grid-column:1/-1;color:#888;padding:1rem;text-align:center;">Няма снимки.</p>';
        return;
      }
      grid.innerHTML = '';
      list.forEach(function (imgData) {
        var item = document.createElement('div');
        item.style.cssText = 'cursor:pointer;border-radius:6px;overflow:hidden;border:2px solid transparent;transition:border-color .12s;background:#f3f4f6';
        item.title = imgData.path;

        var thumb = document.createElement('img');
        thumb.src = imgData.path;
        thumb.loading = 'lazy';
        thumb.style.cssText = 'width:100%;height:80px;object-fit:cover;display:block';

        item.appendChild(thumb);
        item.addEventListener('mouseenter', function () { this.style.borderColor = '#0d9488'; });
        item.addEventListener('mouseleave', function () { this.style.borderColor = 'transparent'; });
        item.addEventListener('click', function () {
          close();
          _applyImagePath(imgData.path, wrap);
        });

        grid.appendChild(item);
      });
    }

    if (_libCache) {
      renderGrid();
    } else {
      grid.innerHTML = '<p style="grid-column:1/-1;color:#888;padding:1rem;text-align:center;">Зарежда\u2026</p>';
      fetch('/admin/media-library-ajax.php')
        .then(function (r) { return r.json(); })
        .then(function (data) { _libCache = data.images || []; renderGrid(); })
        .catch(function () {
          grid.innerHTML = '<p style="grid-column:1/-1;color:#c0392b;padding:1rem;text-align:center;">Грешка при зареждане.</p>';
        });
    }

    closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    document.addEventListener('keydown', function escHandler(e) {
      if (e.key === 'Escape') { close(); document.removeEventListener('keydown', escHandler); }
    });

    header.appendChild(titleEl);
    header.appendChild(closeBtn);
    box.appendChild(header);
    box.appendChild(grid);
    overlay.appendChild(box);
    document.body.appendChild(overlay);
  }

  function _applyImagePath(path, wrap) {
    const img = wrap.querySelector('img');
    if (img && img.getAttribute('src') !== path) _recentreFrame(wrap);
    if (img) {
      img.src = path;
      // A person's old crop does not fit the new picture: back to the element's default style.
      if (img.dataset.cmsImgStyle) img.setAttribute('style', img.dataset.cmsImgStyle);
    }
    // Image paths are language-neutral — mark both languages dirty
    markDirty(wrap.dataset.cmsSection, wrap.dataset.cmsField, 'bg', path, wrap);
    markDirty(wrap.dataset.cmsSection, wrap.dataset.cmsField, 'en', path, wrap);
  }

  function _uploadImage(file, wrap) {
    const fd = new FormData();
    fd.append('image', file);
    fd.append('csrf_token', window._omCsrf);
    fd.append('section', wrap.dataset.cmsSection || '');
    fd.append('field', wrap.dataset.cmsField || '');

    fetch('/admin/inline-upload.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) { alert('Upload failed: ' + (data.error || 'unknown error')); return; }
        // After upload, add to cache so it appears next time library is opened
        if (_libCache) _libCache.push({ path: data.path, cat: wrap.dataset.cmsSection || 'pages' });
        _applyImagePath(data.path, wrap);
      })
      .catch(function () { alert('Upload failed'); });
  }

  // ── Photo framing ("Нагласи снимката") ────────────────────────────────────
  // A photo block (templates/home/hero|mission|text_image.php) marks the row
  // (data-om-fgrid), the photo's frame (data-om-fbox) and, on its .om-img-wrap, the
  // stored frame as JSON (data-om-frame, '' for the block's normal look). While the
  // editor runs it paints inline styles; "Готово" keeps them and marks image_frame
  // dirty, so the frame is saved with the page's other changes. Limits and meaning of
  // w/r/x/y/z: HOME_FRAME_LIMITS in includes/home.php.

  var FRAME_LIMITS = { w: [35, 65], r: [0.5, 2], x: [0, 100], y: [0, 100], z: [1, 3] };
  var _framing = null;

  function _fClamp(k, v) {
    var l = FRAME_LIMITS[k];
    return Math.min(l[1], Math.max(l[0], v));
  }

  function _readFrame(wrap) {
    var f = null;
    try { f = JSON.parse(wrap.getAttribute('data-om-frame') || 'null'); } catch (e) { return null; }
    if (!f || typeof f !== 'object') return null;
    var out = {};
    for (var k in FRAME_LIMITS) {
      if (typeof f[k] !== 'number' || !isFinite(f[k])) return null;
      out[k] = _fClamp(k, f[k]);
    }
    return out;
  }

  // Width of the row's two columns together (the gap left out); 0 when the row is stacked.
  function _rowWidth(grid) {
    if (!grid) return 0;
    var cols = getComputedStyle(grid).gridTemplateColumns.split(' ')
      .map(parseFloat).filter(function (n) { return n > 0; });
    return cols.length >= 2 ? cols[0] + cols[1] : 0;
  }

  // How far (px) the photo reaches past its frame on each side, at the current zoom.
  function _overflow(st) {
    var bw = st.box.clientWidth, bh = st.box.clientHeight;
    var nw = st.img.naturalWidth || bw, nh = st.img.naturalHeight || bh;
    var s = Math.max(bw / nw, bh / nh) * st.frame.z;
    return { x: nw * s - bw, y: nh * s - bh };
  }

  // The frame the block shows right now, measured, for a block that has none stored.
  function _measureFrame(st) {
    var b = st.box.getBoundingClientRect(), row = _rowWidth(st.grid);
    return {
      w: row ? _fClamp('w', b.width / row * 100) : 50,
      r: _fClamp('r', b.height ? b.width / b.height : 1),
      x: 50, y: 50, z: 1
    };
  }

  function _paintFrame(st) {
    var fr = st.frame;
    if (st.styleEl) st.styleEl.disabled = true;   // inline styles carry the look while editing
    if (!fr) {                                     // the block's normal look
      st.box.style.aspectRatio = '';
      st.box.style.maxHeight = '';
      st.wrap.style.cssText = '';
      st.img.style.cssText = st.natural ? 'width:100%;height:auto;display:block;' : '';
      if (st.grid) st.grid.style.gridTemplateColumns = st.natural ? '1fr 1fr' : '';
      st.zoom.value = '1';
      return;
    }
    var pos = fr.x.toFixed(1) + '% ' + fr.y.toFixed(1) + '%';
    st.box.style.aspectRatio = String(fr.r.toFixed(3));
    st.box.style.maxHeight = '90vh';
    st.wrap.style.cssText = 'display:block;width:100%;height:100%;';
    st.img.style.cssText = 'display:block;width:100%;height:100%;object-fit:cover;'
      + 'object-position:' + pos + ';transform:scale(' + fr.z.toFixed(3) + ');transform-origin:' + pos + ';';
    if (st.grid && _rowWidth(st.grid)) {
      var w = Math.round(fr.w), a = 'minmax(0,' + w + 'fr)', b = 'minmax(0,' + (100 - w) + 'fr)';
      st.grid.style.gridTemplateColumns = st.first ? a + ' ' + b : b + ' ' + a;
    }
    st.zoom.value = String(fr.z);
  }

  function _frameSummary(fr) {
    if (!fr) return 'Снимката ще е в обичайния си вид.';
    var shape = fr.r < 0.95 ? 'изправена' : (fr.r > 1.05 ? 'легнала' : 'квадратна');
    return 'Снимката заема ' + Math.round(fr.w) + '% от ширината, форма: ' + shape
      + ', увеличение ' + Math.round(fr.z * 100) + '%.';
  }

  // Said once things settle, not on every step of a drag or key repeat.
  function _announceFrame(st) {
    clearTimeout(st.announceTimer);
    st.announceTimer = setTimeout(function () { st.status.textContent = _frameSummary(st.frame); }, 350);
  }

  function _frameButton(label, primary) {
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = label;
    b.style.cssText = 'min-height:44px;min-width:44px;padding:0 16px;border-radius:8px;font:inherit;cursor:pointer;'
      + (primary ? 'border:1px solid #6d28d9;background:#6d28d9;color:#fff;font-weight:600;'
                 : 'border:1px solid #9ca3af;background:#fff;color:#1a1a2e;');
    return b;
  }

  function _startFraming(wrap) {
    var box = wrap.closest('[data-om-fbox]'), img = wrap.querySelector('img');
    if (_framing || !box || !img) return;
    var key = box.getAttribute('data-om-fbox');
    var st = {
      wrap: wrap, box: box, img: img,
      grid: document.querySelector('[data-om-fgrid="' + key + '"]'),
      styleEl: document.querySelector('style[data-om-frame-css="' + key + '"]'),
      natural: wrap.hasAttribute('data-om-frame-natural'),
      first: wrap.getAttribute('data-om-frame-handle') === 'right',   // photo is the row's first column
      saved: null, frame: null, announceTimer: 0
    };
    st.saved = {
      box: box.style.cssText, wrap: wrap.style.cssText, img: img.style.cssText,
      grid: st.grid ? st.grid.style.cssText : '', css: st.styleEl ? st.styleEl.disabled : false
    };
    st.frame = _readFrame(wrap) || _measureFrame(st);

    // On the photo: a layer to drag it by, and the resize corner on the side facing the text.
    st.savedPos = box.style.position;
    if (getComputedStyle(box).position === 'static') box.style.position = 'relative';
    var layer = document.createElement('div');
    layer.setAttribute('aria-hidden', 'true');   // the keyboard way is the panel below
    layer.style.cssText = 'position:absolute;inset:0;z-index:5;cursor:move;touch-action:none;'
      + 'outline:3px dashed #6d28d9;outline-offset:-3px;';
    var handle = document.createElement('div');
    var left = !st.first;
    handle.style.cssText = 'position:absolute;bottom:0;' + (left ? 'left:0;' : 'right:0;')
      + 'width:44px;height:44px;background:#6d28d9;color:#fff;display:flex;align-items:center;justify-content:center;'
      + 'font-size:22px;line-height:1;cursor:' + (left ? 'nesw' : 'nwse') + '-resize;'
      + 'border-radius:' + (left ? '0 10px 0 0' : '10px 0 0 0') + ';';
    handle.textContent = left ? '◣' : '◢';
    layer.appendChild(handle);
    box.appendChild(layer);
    st.hint = wrap.querySelector('.om-img-overlay');   // "📷 Replace" would sit over the photo
    if (st.hint) st.hint.style.display = 'none';

    layer.addEventListener('pointerdown', function (e) {
      if (e.button !== 0) return;
      e.preventDefault();
      if (!st.frame) st.frame = _measureFrame(st);
      layer.setPointerCapture(e.pointerId);
      var resize = e.target === handle;
      var b = box.getBoundingClientRect();
      var d = { x: e.clientX, y: e.clientY, w: b.width, h: b.height, row: _rowWidth(st.grid),
                f: Object.assign({}, st.frame), over: _overflow(st) };
      function move(ev) {
        var dx = ev.clientX - d.x, dy = ev.clientY - d.y, fr = st.frame;
        if (resize) {
          var wPx = d.w + (left ? -dx : dx);
          fr.w = d.row ? _fClamp('w', wPx / d.row * 100) : d.f.w;
          var realW = d.row ? fr.w / 100 * d.row : d.w;
          var hPx = Math.max(120, Math.min(window.innerHeight * 0.9, d.h + dy));
          fr.r = _fClamp('r', realW / hPx);
        } else {
          // Dragging the photo right shows more of its left side.
          if (d.over.x > 0) fr.x = _fClamp('x', d.f.x - dx / d.over.x * 100);
          if (d.over.y > 0) fr.y = _fClamp('y', d.f.y - dy / d.over.y * 100);
        }
        _paintFrame(st);
      }
      function up() {
        layer.removeEventListener('pointermove', move);
        layer.removeEventListener('pointerup', up);
        layer.removeEventListener('pointercancel', up);
        _announceFrame(st);
      }
      layer.addEventListener('pointermove', move);
      layer.addEventListener('pointerup', up);
      layer.addEventListener('pointercancel', up);
    });

    // The panel: the same controls for keyboard and screen-reader users, plus the help.
    var bar = document.createElement('div');
    bar.id = 'om-frame-panel';
    bar.setAttribute('role', 'dialog');
    bar.setAttribute('aria-labelledby', 'om-frame-title');
    bar.setAttribute('aria-describedby', 'om-frame-help');
    bar.tabIndex = -1;
    // Beside the photo, on the text's side, so it does not cover what is being framed.
    bar.style.cssText = 'position:fixed;bottom:16px;' + (st.first ? 'right:16px;' : 'left:16px;') + 'z-index:200001;'
      + 'width:min(400px,calc(100vw - 32px));box-sizing:border-box;padding:16px;border-radius:12px;'
      + 'background:#fff;color:#1a1a2e;box-shadow:0 8px 32px rgba(0,0,0,.3);'
      + 'font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;';
    var title = document.createElement('p');
    title.id = 'om-frame-title';
    title.style.cssText = 'margin:0 0 4px;font-weight:600;font-size:15px;';
    title.textContent = 'Нагласяне на снимката';
    var help = document.createElement('p');
    help.id = 'om-frame-help';
    help.style.cssText = 'margin:0 0 12px;font-size:13px;color:#4b5563;';
    help.textContent = 'Влачете снимката, за да изберете коя част да се вижда. Влачете лилавия ъгъл, '
      + 'за да я направите по-широка или по-тясна, по-висока или по-ниска. С клавиатурата: стрелките '
      + 'местят снимката, Shift + стрелките променят размера, + и − я увеличават и намаляват.';

    var zoomRow = document.createElement('div');
    zoomRow.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:8px;';
    var zoomLabel = document.createElement('label');
    zoomLabel.htmlFor = 'om-frame-zoom';
    zoomLabel.textContent = 'Увеличение';
    zoomLabel.style.cssText = 'font-size:13px;white-space:nowrap;text-transform:none;letter-spacing:normal;margin:0;color:#1a1a2e;';
    var zOut = _frameButton('−');
    zOut.setAttribute('aria-label', 'Намали увеличението');
    var zoom = document.createElement('input');
    zoom.type = 'range'; zoom.id = 'om-frame-zoom';
    zoom.min = '1'; zoom.max = '3'; zoom.step = '0.05';
    zoom.style.cssText = 'flex:1;min-width:0;min-height:44px;accent-color:#6d28d9;';
    var zIn = _frameButton('+');
    zIn.setAttribute('aria-label', 'Увеличи');
    zoomRow.append(zoomLabel, zOut, zoom, zIn);
    st.zoom = zoom;

    var status = document.createElement('p');
    status.setAttribute('role', 'status');
    status.style.cssText = 'margin:0 0 12px;font-size:13px;color:#4b5563;min-height:1.5em;';
    st.status = status;

    var actions = document.createElement('div');
    actions.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;';
    var bReset = _frameButton('Обичаен вид'), bCancel = _frameButton('Отказ'), bDone = _frameButton('Готово', true);
    actions.append(bReset, bCancel, bDone);
    var note = document.createElement('p');
    note.style.cssText = 'margin:8px 0 0;font-size:12px;color:#4b5563;';
    note.textContent = '„Готово“ приема нагласянето. Запазва се, когато натиснете „Save changes“.';
    bar.append(title, help, zoomRow, status, actions, note);
    document.body.appendChild(bar);

    function setZoom(z) {
      if (!st.frame) st.frame = _measureFrame(st);
      st.frame.z = _fClamp('z', z);
      _paintFrame(st);
      _announceFrame(st);
    }
    zoom.addEventListener('input', function () { setZoom(parseFloat(zoom.value)); });
    zOut.addEventListener('click', function () { setZoom((st.frame ? st.frame.z : 1) - 0.1); });
    zIn.addEventListener('click', function () { setZoom((st.frame ? st.frame.z : 1) + 0.1); });
    bReset.addEventListener('click', function () { st.frame = null; _paintFrame(st); _announceFrame(st); });
    bCancel.addEventListener('click', function () { _endFraming(false); });
    bDone.addEventListener('click', function () { _endFraming(true); });

    st.layer = layer; st.bar = bar;
    _framing = st;
    document.addEventListener('keydown', _onFrameKey, true);
    _paintFrame(st);
    box.scrollIntoView({ block: 'nearest' });
    bar.focus();
    _announceFrame(st);
  }

  function _onFrameKey(e) {
    var st = _framing;
    if (!st) return;
    if (e.key === 'Escape') { e.preventDefault(); _endFraming(false); return; }
    if (e.key === 'Tab') {   // keep focus in the panel
      var f = st.bar.querySelectorAll('button, input'), first = f[0], last = f[f.length - 1];
      if (!st.bar.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
      else if (e.shiftKey && (document.activeElement === first || document.activeElement === st.bar)) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      return;
    }
    var t = e.target;
    if (t === st.zoom && !e.shiftKey && /^Arrow/.test(e.key)) return;   // the slider's own keys
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    if (!st.frame) st.frame = _measureFrame(st);
    var fr = st.frame, row = _rowWidth(st.grid), step = 2;
    var keys = e.shiftKey ? {
      ArrowRight: function () { if (row) fr.w = _fClamp('w', fr.w + step); },
      ArrowLeft:  function () { if (row) fr.w = _fClamp('w', fr.w - step); },
      ArrowUp:    function () { fr.r = _fClamp('r', fr.r * 1.05); },   // lower
      ArrowDown:  function () { fr.r = _fClamp('r', fr.r / 1.05); }    // taller
    } : {
      ArrowLeft:  function () { fr.x = _fClamp('x', fr.x + step); },
      ArrowRight: function () { fr.x = _fClamp('x', fr.x - step); },
      ArrowUp:    function () { fr.y = _fClamp('y', fr.y + step); },
      ArrowDown:  function () { fr.y = _fClamp('y', fr.y - step); }
    };
    keys['+'] = keys['='] = function () { fr.z = _fClamp('z', fr.z + 0.1); };
    keys['-'] = keys['_'] = function () { fr.z = _fClamp('z', fr.z - 0.1); };
    if (!keys[e.key]) return;
    e.preventDefault();
    keys[e.key]();
    _paintFrame(st);
    _announceFrame(st);
  }

  function _endFraming(keep) {
    var st = _framing;
    if (!st) return;
    _framing = null;
    clearTimeout(st.announceTimer);
    document.removeEventListener('keydown', _onFrameKey, true);
    st.layer.remove();
    st.bar.remove();
    if (st.hint) st.hint.style.display = '';
    if (keep) {
      var fr = st.frame;
      var v = fr ? { w: Math.round(fr.w), r: Math.round(fr.r * 1000) / 1000, x: Math.round(fr.x * 10) / 10,
                     y: Math.round(fr.y * 10) / 10, z: Math.round(fr.z * 1000) / 1000 } : [];
      st.box.style.position = st.savedPos;
      st.wrap.setAttribute('data-om-frame', fr ? JSON.stringify(v) : '');
      markDirty(st.wrap.dataset.cmsSection, 'image_frame', 'bg', v, st.wrap);
      markDirty(st.wrap.dataset.cmsSection, 'image_frame', 'en', v, st.wrap);
    } else {
      st.box.style.cssText = st.saved.box;
      st.wrap.style.cssText = st.saved.wrap;
      st.img.style.cssText = st.saved.img;
      if (st.grid) st.grid.style.cssText = st.saved.grid;
      if (st.styleEl) st.styleEl.disabled = st.saved.css;
    }
    if (st.wrap.hasAttribute('tabindex')) st.wrap.focus();
  }

  // A new photo keeps its frame's size and shape but starts centred — the server does the
  // same when it saves the photo (includes/home.php, home_frame_recentre()).
  function _recentreFrame(wrap) {
    var fr = _readFrame(wrap);
    if (!fr) return;
    fr.x = 50; fr.y = 50; fr.z = 1;
    wrap.setAttribute('data-om-frame', JSON.stringify(fr));
    var img = wrap.querySelector('img');
    if (img && img.style.objectPosition) {
      img.style.objectPosition = '50% 50%';
      img.style.transformOrigin = '50% 50%';
      img.style.transform = 'scale(1)';
    }
    // A frame already waiting to be saved would bring the old photo's focus point back.
    if (dirtyFields[wrap.dataset.cmsSection + '.image_frame']) {
      markDirty(wrap.dataset.cmsSection, 'image_frame', 'bg', fr, wrap);
      markDirty(wrap.dataset.cmsSection, 'image_frame', 'en', fr, wrap);
    }
  }

  // ── Add / Remove ──────────────────────────────────────────────────────────

  function _injectAddRemoveControls() {
    // Remove buttons on removable items
    document.querySelectorAll('.om-removable').forEach(function (el) {
      if (el.querySelector('.om-remove-btn')) return;
      const btn = document.createElement('button');
      btn.className = 'om-remove-btn';
      btn.textContent = '×';
      btn.setAttribute('aria-label', 'Remove');
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        _confirmRemove(el);
      });
      el.appendChild(btn);
    });

    // Add buttons
    document.querySelectorAll('[data-cms-add]').forEach(function (trigger) {
      if (trigger.dataset.omAddBound) return;
      trigger.dataset.omAddBound = '1';
      trigger.addEventListener('click', function () {
        openModal(trigger.dataset.cmsAdd);
      });
    });

    // Image wraps
    document.querySelectorAll('.om-img-wrap').forEach(_setupImageWrap);
  }

  function _removeAddRemoveControls() {
    document.querySelectorAll('.om-remove-btn').forEach(function (btn) { btn.remove(); });
    // Reset add-button bound state so they re-bind correctly next time edit mode activates
    document.querySelectorAll('[data-cms-add]').forEach(function (trigger) {
      delete trigger.dataset.omAddBound;
    });
  }

  function _confirmRemove(el) {
    const type = el.dataset.cmsRemoveType;
    const id = el.dataset.cmsRemoveId;
    if (typeof _adminConfirm === 'function') {
      _adminConfirm('Remove this item?', function () { _doRemove(type, id, el); });
      return;
    }
    // Inline confirm — avoid window.confirm() which Chrome can suppress
    _inlineConfirm('Remove this item?', function () { _doRemove(type, id, el); });
  }

  function _inlineConfirm(message, onConfirm) {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200000;display:flex;align-items:center;justify-content:center;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif';
    var box = document.createElement('div');
    box.style.cssText = 'background:#fff;border-radius:12px;padding:28px;max-width:360px;width:90vw;box-shadow:0 8px 32px rgba(0,0,0,.2)';
    var msg = document.createElement('p');
    msg.style.cssText = 'margin:0 0 20px;font-size:15px;color:#1a1a2e';
    msg.textContent = message;
    var actions = document.createElement('div');
    actions.style.cssText = 'display:flex;gap:8px;justify-content:flex-end';
    var cancel = document.createElement('button');
    cancel.textContent = 'Cancel';
    cancel.style.cssText = 'padding:8px 16px;border-radius:6px;border:1px solid #ddd;background:#fff;font-size:13px;cursor:pointer';
    var confirm = document.createElement('button');
    confirm.textContent = 'Remove';
    confirm.style.cssText = 'padding:8px 16px;border-radius:6px;border:none;background:#ef4444;color:#fff;font-size:13px;font-weight:600;cursor:pointer';
    function close() { document.body.removeChild(overlay); }
    cancel.addEventListener('click', close);
    confirm.addEventListener('click', function () { close(); onConfirm(); });
    actions.appendChild(cancel);
    actions.appendChild(confirm);
    box.appendChild(msg);
    box.appendChild(actions);
    overlay.appendChild(box);
    document.body.appendChild(overlay);
  }

  function _doRemove(type, id, el) {
    fetch('/admin/inline-remove.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf_token: window._omCsrf, type: type, id: id })
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          el.remove();
        } else {
          alert('Remove failed: ' + (data.error || 'unknown'));
        }
      })
      .catch(function () { alert('Remove failed: network error'); });
  }

  // ── Modal (Add item) ──────────────────────────────────────────────────────

  const _modalForms = {
    partner: [
      { name: 'url', label: 'Website URL', type: 'url', required: true },
      { name: 'name', label: 'Partner name', type: 'text', required: true },
      { name: 'logo', label: 'Logo image', type: 'file', required: true }
    ],
    impact: [
      { name: 'value', label: 'Number / Value', type: 'text', required: true },
      { name: 'label_bg', label: 'Label (BG)', type: 'text', required: true },
      { name: 'label_en', label: 'Label (EN)', type: 'text', required: true }
    ],
    centre: [
      { name: 'name_bg', label: 'Name (BG)', type: 'text', required: true },
      { name: 'name_en', label: 'Name (EN)', type: 'text', required: true },
      { name: 'description_bg', label: 'Description (BG)', type: 'text', required: true },
      { name: 'description_en', label: 'Description (EN)', type: 'text', required: true },
      { name: 'image', label: 'Image', type: 'file', required: false }
    ],
    faq: [
      { name: 'q_bg', label: 'Question (BG)', type: 'text', required: true },
      { name: 'q_en', label: 'Question (EN)', type: 'text', required: false },
      { name: 'a_bg', label: 'Answer (BG)', type: 'text', required: true },
      { name: 'a_en', label: 'Answer (EN)', type: 'text', required: false }
    ]
  };

  const _modalTitles = { partner: 'Add partner', impact: 'Add impact number', centre: 'Add centre', faq: 'Add question' };

  function openModal(type) {
    pendingModalType = type;
    const fields = _modalForms[type];
    if (!fields) return;
    document.getElementById('om-modal-title').textContent = _modalTitles[type] || 'Add item';
    const container = document.getElementById('om-modal-fields');
    container.innerHTML = fields.map(function (f) {
      return '<div class="om-field-group"><label>' + f.label + (f.required ? ' *' : '') + '</label>' +
        '<input type="' + f.type + '" name="' + f.name + '" ' + (f.required ? 'required' : '') + '></div>';
    }).join('');
    document.getElementById('om-add-modal').classList.add('om-open');
  }

  function closeModal() {
    document.getElementById('om-add-modal').classList.remove('om-open');
    pendingModalType = null;
  }

  function submitModal() {
    const type = pendingModalType;
    if (!type) return;
    const fd = new FormData();
    fd.append('csrf_token', window._omCsrf);
    fd.append('type', type);
    document.querySelectorAll('#om-modal-fields input').forEach(function (input) {
      if (input.type === 'file') {
        if (input.files[0]) fd.append(input.name, input.files[0]);
      } else {
        fd.append(input.name, input.value);
      }
    });
    fetch('/admin/inline-add.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) { alert('Error: ' + (data.error || 'unknown')); return; }
        closeModal();
        window.location.reload();
      });
  }

  // ── Save ──────────────────────────────────────────────────────────────────

  function save() {
    if (!Object.keys(dirtyFields).length) return;
    // Flush any active TinyMCE editors before reading dirtyFields
    if (window.tinymce && typeof tinymce.triggerSave === 'function') {
      tinymce.triggerSave();
    }
    const btn = document.getElementById('om-save-btn');
    btn.classList.add('om-saving');
    btn.textContent = 'Saving…';

    // Group dirty fields by section
    const bySection = {};
    Object.values(dirtyFields).forEach(function (f) {
      if (!bySection[f.section]) bySection[f.section] = {};
      bySection[f.section][f.field] = { bg: f.bg, en: f.en };
    });

    // Inject article slug if article section is dirty
    if (bySection['article']) {
      const slugEl = document.querySelector('[data-cms-article-slug-bg]');
      if (slugEl) {
        bySection['article']['slug'] = {
          bg: slugEl.dataset.cmsArticleSlugBg || '',
          en: slugEl.dataset.cmsArticleSlugEn || ''
        };
      }
    }

    // Inject product id if product section is dirty
    if (bySection['product']) {
      const prodEl = document.querySelector('[data-cms-section="product"][data-cms-id]');
      if (prodEl) {
        const pid = prodEl.dataset.cmsId || '';
        bySection['product']['id'] = { bg: pid, en: pid };
      }
    }

    // Rebuild menus payload from DOM (array-based, not field-based)
    if (bySection['menus']) {
      const menusPayload = {};
      document.querySelectorAll('[data-cms-section="menus"]').forEach(function (el) {
        const menuKey = el.dataset.cmsMenu;
        const idx = parseInt(el.dataset.cmsIndex, 10);
        if (!menuKey || isNaN(idx)) return;
        if (!menusPayload[menuKey]) menusPayload[menuKey] = { bg: [], en: [] };
        // Read current content for active lang, stored value for inactive
        const bgVal = (activeLang === 'bg') ? el.innerHTML : (el.dataset.cmsBg || '');
        const enVal = (activeLang === 'en') ? el.innerHTML : (el.dataset.cmsEn || '');
        menusPayload[menuKey].bg[idx] = { label: bgVal };
        menusPayload[menuKey].en[idx] = { label: enVal };
      });
      bySection['menus'] = menusPayload;
    }

    const promises = Object.entries(bySection).map(function (entry) {
      const section = entry[0];
      const fields = entry[1];
      const endpoint = _endpointFor(section);
      return fetch(endpoint, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: window._omCsrf, section: section, fields: fields })
      }).then(function (r) { return r.json(); });
    });

    Promise.all(promises).then(function (results) {
      const failed = results.filter(function (r) { return !r.ok; });
      if (failed.length) {
        btn.classList.remove('om-saving');
        btn.classList.add('om-error');
        btn.textContent = '✗ Save failed — check console';
        console.error('Inline CMS save errors:', failed);
      } else {
        dirtyFields = {};
        _hideSaveBtn();
        btn.textContent = '💾 Save changes';
      }
    }).catch(function (err) {
      btn.classList.remove('om-saving');
      btn.classList.add('om-error');
      btn.textContent = '✗ Network error';
      console.error(err);
    });
  }

  function _endpointFor(section) {
    var base = window._omAdminBase || '/admin';
    if (section === 'article') return base + '/inline-save-article.php';
    if (section === 'product') return base + '/inline-save-product.php';
    return base + '/inline-save.php';
  }

  // ── Init ──────────────────────────────────────────────────────────────────

  document.addEventListener('DOMContentLoaded', function () {
    // Set initial lang from current URL path
    if (window._omLang === 'en') {
      document.getElementById('om-btn-bg').classList.remove('om-active');
      document.getElementById('om-btn-en').classList.add('om-active');
      activeLang = 'en';
    }
    // Setup image wraps that exist on load
    document.querySelectorAll('.om-img-wrap').forEach(_setupImageWrap);
  });

  return { toggleEdit: toggleEdit, setLang: setLang, save: save, markDirty: markDirty, openModal: openModal, closeModal: closeModal, submitModal: submitModal };
})();

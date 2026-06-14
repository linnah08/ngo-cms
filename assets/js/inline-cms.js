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
      if (el.dataset.cmsType === 'richtext') return; // TinyMCE handles these
      el.setAttribute('contenteditable', 'true');
      el.addEventListener('blur', _onBlur);
    });
    _injectAddRemoveControls();
  }

  function _deactivateEditables() {
    document.querySelectorAll('[data-cms-field]').forEach(function (el) {
      el.removeAttribute('contenteditable');
      el.removeEventListener('blur', _onBlur);
    });
    // Destroy TinyMCE inline instances
    if (window.tinymce) {
      tinymce.editors.slice().forEach(function (ed) { ed.remove(); });
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
    const key = section + '.' + field;
    if (!dirtyFields[key]) dirtyFields[key] = { section: section, field: field, bg: el.dataset.cmsBg || '', en: el.dataset.cmsEn || '' };
    dirtyFields[key][activeLang] = el.innerHTML;
    el.dataset['cms' + _cap(activeLang)] = el.innerHTML;
    _showSaveBtn();
  }

  function markDirty(section, field, lang, value) {
    const key = section + '.' + field;
    if (!dirtyFields[key]) dirtyFields[key] = { section: section, field: field, bg: '', en: '' };
    dirtyFields[key][lang] = value;
    _showSaveBtn();
  }

  function _showSaveBtn() {
    document.getElementById('om-save-btn').classList.add('om-dirty');
  }

  function _hideSaveBtn() {
    document.getElementById('om-save-btn').classList.remove('om-dirty', 'om-error', 'om-saving');
  }

  // ── TinyMCE inline ────────────────────────────────────────────────────────

  function _initRichText(el) {
    if (!window.tinymce) return;
    if (!el.id) el.id = 'om-rt-' + Math.random().toString(36).slice(2);

    const cfg = Object.assign({}, window._tinyBase, {
      selector: '#' + el.id,
      inline: true,
      setup: function (ed) {
        if (window._tinyBase && typeof window._tinyBase.setup === 'function') {
          window._tinyBase.setup(ed);
        }
        ed.on('Change', function () {
          markDirty(el.dataset.cmsSection, el.dataset.cmsField, activeLang, ed.getContent());
          el.dataset['cms' + _cap(activeLang)] = ed.getContent();
        });
      }
    });
    tinymce.init(cfg);
  }

  // Click-to-init rich text fields
  document.addEventListener('click', function (e) {
    if (!editMode) return;
    const el = e.target.closest('[data-cms-type="richtext"]');
    if (!el) return;
    if (tinymce && tinymce.get(el.id)) return; // already inited
    _initRichText(el);
  });

  // ── Image replacement ─────────────────────────────────────────────────────

  var _libCache = null;

  function _setupImageWrap(wrap) {
    if (wrap.dataset.omImgBound) return;
    wrap.dataset.omImgBound = '1';
    wrap.addEventListener('click', function () {
      if (!editMode) return;
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

    function close() { document.body.removeChild(overlay); }

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
    btnCancel.addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });

    box.appendChild(title);
    box.appendChild(btnLib);
    box.appendChild(btnUpload);
    box.appendChild(btnCancel);
    overlay.appendChild(box);
    document.body.appendChild(overlay);
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
    if (img) img.src = path;
    // Image paths are language-neutral — mark both languages dirty
    markDirty(wrap.dataset.cmsSection, wrap.dataset.cmsField, 'bg', path);
    markDirty(wrap.dataset.cmsSection, wrap.dataset.cmsField, 'en', path);
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
    team: [
      { name: 'name_bg', label: 'Name (BG)', type: 'text', required: true },
      { name: 'name_en', label: 'Name (EN)', type: 'text', required: true },
      { name: 'role_bg', label: 'Role (BG)', type: 'text', required: true },
      { name: 'role_en', label: 'Role (EN)', type: 'text', required: true },
      { name: 'photo', label: 'Photo', type: 'file', required: false }
    ],
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

  const _modalTitles = { team: 'Add team member', partner: 'Add partner', impact: 'Add impact number', centre: 'Add centre', faq: 'Add question' };

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

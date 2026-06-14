(function () {
  'use strict';

  window.initAutosave = function (cfg) {
    // cfg: { key: string, formId: string, tinyIds: string[] }
    var storageKey = 'autosave:' + cfg.key;
    var form = document.getElementById(cfg.formId);
    if (!form) return;
    var tinyIds = cfg.tinyIds || [];
    var timer = null;

    function snapshot() {
      var data = {};
      Array.from(form.elements).forEach(function (el) {
        if (!el.name || el.type === 'file' || el.type === 'hidden') return;
        if (el.type === 'checkbox') { data[el.name] = el.checked; return; }
        if (el.type === 'radio') { if (el.checked) data[el.name] = el.value; return; }
        data[el.name] = el.value;
      });
      tinyIds.forEach(function (id) {
        var ed = (typeof tinymce !== 'undefined') ? tinymce.get(id) : null;
        if (ed) data['_tiny_' + id] = ed.getContent();
      });
      return data;
    }

    function save() {
      try {
        localStorage.setItem(storageKey, JSON.stringify({ ts: Date.now(), data: snapshot() }));
      } catch (e) {}
    }

    function schedule() {
      clearTimeout(timer);
      timer = setTimeout(save, 2000);
    }

    // Hook regular form fields
    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);

    // Hook TinyMCE — current and future editors
    function hookEditor(ed) {
      if (tinyIds.indexOf(ed.id) === -1) return;
      ed.on('Change keyup', schedule);
    }
    if (typeof tinymce !== 'undefined') {
      tinymce.get().forEach(hookEditor);
      tinymce.on('AddEditor', function (e) { hookEditor(e.editor); });
    }

    // Clear draft on submit so a successful save never re-offers a stale draft
    form.addEventListener('submit', function () {
      clearTimeout(timer);
      try { localStorage.removeItem(storageKey); } catch (e) {}
    });

    // Restore banner — shown if a draft exists for this key
    (function () {
      var raw;
      try { raw = localStorage.getItem(storageKey); } catch (e) { return; }
      if (!raw) return;
      var saved;
      try { saved = JSON.parse(raw); } catch (e) { return; }
      if (!saved || !saved.data) return;

      var diff = Math.floor((Date.now() - (saved.ts || 0)) / 1000);
      var ago = diff < 60  ? ('преди ' + diff + ' сек.')
              : diff < 3600 ? ('преди ' + Math.floor(diff / 60) + ' мин.')
              : ('преди ' + Math.floor(diff / 3600) + ' ч.');

      var banner = document.createElement('div');
      banner.style.cssText = 'background:#fffbeb;border:1px solid #f59e0b;border-radius:6px;'
        + 'padding:.75rem 1rem;margin-bottom:1.25rem;display:flex;align-items:center;'
        + 'gap:.75rem;font-size:.9rem;flex-wrap:wrap;';
      banner.innerHTML =
        '<span style="flex:1;min-width:180px;">Имате незапазен черновец от ' + ago + '.</span>'
        + '<button type="button" id="_asRestore" style="padding:.4rem .9rem;background:#f59e0b;'
        + 'color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:.85rem;font-weight:600;">'
        + 'Върни черновата</button>'
        + '<button type="button" id="_asDiscard" style="padding:.4rem .9rem;background:#fff;'
        + 'color:#555;border:1px solid #d1d5db;border-radius:5px;cursor:pointer;font-size:.85rem;">'
        + 'Игнорирай</button>';

      form.insertBefore(banner, form.firstChild);

      document.getElementById('_asDiscard').addEventListener('click', function () {
        try { localStorage.removeItem(storageKey); } catch (e) {}
        banner.remove();
      });

      document.getElementById('_asRestore').addEventListener('click', function () {
        restore(saved.data);
        banner.remove();
      });
    }());

    function restore(data) {
      Object.keys(data).forEach(function (name) {
        if (name.indexOf('_tiny_') === 0) return;
        Array.from(form.elements).forEach(function (el) {
          if (el.name !== name) return;
          if (el.type === 'file' || el.type === 'hidden') return;
          if (el.type === 'checkbox') { el.checked = data[name]; return; }
          if (el.type === 'radio')    { el.checked = (el.value === data[name]); return; }
          el.value = data[name];
        });
      });
      tinyIds.forEach(function (id) {
        var key = '_tiny_' + id;
        if (!(key in data)) return;
        var ed = (typeof tinymce !== 'undefined') ? tinymce.get(id) : null;
        if (ed) {
          ed.setContent(data[key]);
        } else if (typeof tinymce !== 'undefined') {
          var handler = function (e) {
            if (e.editor.id === id) {
              e.editor.setContent(data[key]);
              tinymce.off('AddEditor', handler);
            }
          };
          tinymce.on('AddEditor', handler);
        }
      });
    }
  };
}());
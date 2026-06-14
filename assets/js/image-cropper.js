/*
 * OMCrop — shared image crop modal for the admin.
 * Usage: OMCrop.open(file).then(function (croppedFile) { ... })
 *   - resolves with a cropped File, or null if the user cancelled.
 *   - animated GIFs and non-images bypass cropping and resolve with the original file.
 * Depends on Cropper.js (vendored at /assets/vendor/cropperjs/).
 * Modal chrome uses fully inline styles so it never depends on (possibly stale) admin.css.
 */
(function () {
  'use strict';

  var TEAL = '#0387A5', BORDER = '#e8ddd5', TEXT = '#1a1916', MUTED = '#6b6560', AMBER = '#e8a020';

  // Instagram-accepted aspect range: 4:5 (0.8) … 1.91:1
  var IG_MIN = 0.8, IG_MAX = 1.91;

  var PRESETS = [
    { label: 'Свободно',      ratio: NaN },
    { label: 'Квадрат 1:1',   ratio: 1 },
    { label: 'Портрет 4:5',   ratio: 4 / 5 },
    { label: 'Пейзаж 1.91:1', ratio: 1.91 },
    { label: '16:9',          ratio: 16 / 9 },
    { label: 'Оригинал',      ratio: 'orig' }
  ];

  function btn(label, primary) {
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = label;
    b.style.cssText =
      'font:inherit;cursor:pointer;padding:0.5rem 1rem;border-radius:6px;' +
      'border:1px solid ' + (primary ? TEAL : BORDER) + ';' +
      'background:' + (primary ? TEAL : '#fff') + ';' +
      'color:' + (primary ? '#fff' : TEXT) + ';';
    return b;
  }

  function open(file) {
    return new Promise(function (resolve) {
      // Bypass: non-images and animated-capable GIFs upload untouched.
      if (!file || !/^image\//.test(file.type) || file.type === 'image/gif') {
        resolve(file || null);
        return;
      }

      var objectUrl = URL.createObjectURL(file);
      var cropper = null;
      var settled = false;

      function cleanup() {
        if (cropper) { cropper.destroy(); cropper = null; }
        URL.revokeObjectURL(objectUrl);
        document.removeEventListener('keydown', onKey, true);
        if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
      }
      function finish(result) {
        if (settled) return;
        settled = true;
        cleanup();
        resolve(result);
      }
      function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); finish(null); } }

      // ── Overlay
      var overlay = document.createElement('div');
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-modal', 'true');
      overlay.setAttribute('aria-label', 'Изрязване на снимка');
      overlay.style.cssText =
        'position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,0.6);' +
        'display:flex;align-items:center;justify-content:center;padding:1rem;';
      overlay.addEventListener('click', function (e) { if (e.target === overlay) finish(null); });

      // ── Panel
      var panel = document.createElement('div');
      panel.style.cssText =
        'background:#fff;border-radius:10px;max-width:760px;width:100%;max-height:92vh;' +
        'display:flex;flex-direction:column;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,0.3);';

      var title = document.createElement('div');
      title.textContent = 'Изрежи снимката';
      title.style.cssText =
        'padding:0.9rem 1.1rem;border-bottom:1px solid ' + BORDER + ';font-weight:600;color:' + TEXT + ';';

      // ── Preset bar
      var presetBar = document.createElement('div');
      presetBar.style.cssText =
        'display:flex;flex-wrap:wrap;gap:0.4rem;padding:0.75rem 1.1rem;border-bottom:1px solid ' + BORDER + ';';
      PRESETS.forEach(function (p) {
        var pb = btn(p.label, false);
        pb.style.padding = '0.35rem 0.7rem';
        pb.addEventListener('click', function () {
          if (!cropper) return;
          var r = p.ratio === 'orig' ? (img.naturalWidth / img.naturalHeight) : p.ratio;
          cropper.setAspectRatio(r);
        });
        presetBar.appendChild(pb);
      });

      // ── Image stage
      var stage = document.createElement('div');
      stage.style.cssText = 'flex:1;min-height:0;background:#f3efe9;padding:0.5rem;overflow:hidden;';
      var img = document.createElement('img');
      img.src = objectUrl;
      img.alt = '';
      img.style.cssText = 'display:block;max-width:100%;max-height:60vh;';
      stage.appendChild(img);

      // ── IG note (warn only)
      var note = document.createElement('div');
      note.style.cssText =
        'padding:0.5rem 1.1rem;color:' + AMBER + ';font-size:0.85rem;min-height:1.2em;';

      function updateNote() {
        if (!cropper) return;
        var d = cropper.getData(true);
        if (!d.width || !d.height) { note.textContent = ''; return; }
        var ratio = d.width / d.height;
        note.textContent = (ratio < IG_MIN || ratio > IG_MAX)
          ? 'Instagram ще отреже това, за да се побере — изрежете тук, ако искате да контролирате какво остава.'
          : '';
      }

      // ── Footer
      var footer = document.createElement('div');
      footer.style.cssText =
        'display:flex;justify-content:flex-end;gap:0.6rem;padding:0.85rem 1.1rem;border-top:1px solid ' + BORDER + ';';
      var cancelBtn = btn('Отказ', false);
      var okBtn = btn('Готово', true);
      cancelBtn.addEventListener('click', function () { finish(null); });
      okBtn.addEventListener('click', function () {
        if (!cropper) { finish(null); return; }
        var keepAlpha = (file.type === 'image/png' || file.type === 'image/webp');
        var canvas = cropper.getCroppedCanvas({
          maxWidth: 4000, maxHeight: 4000,
          imageSmoothingEnabled: true, imageSmoothingQuality: 'high',
          fillColor: keepAlpha ? 'transparent' : '#fff'
        });
        if (!canvas) { finish(file); return; }
        var outType = keepAlpha ? 'image/png' : 'image/jpeg';
        var outExt  = keepAlpha ? 'png' : 'jpg';
        canvas.toBlob(function (blob) {
          if (!blob) { finish(file); return; }
          var base = (file.name || 'image').replace(/\.[^.]+$/, '');
          var out = new File([blob], base + '.' + outExt, { type: outType });
          finish(out);
        }, outType, 0.9);
      });
      footer.appendChild(cancelBtn);
      footer.appendChild(okBtn);

      panel.appendChild(title);
      panel.appendChild(presetBar);
      panel.appendChild(stage);
      panel.appendChild(note);
      panel.appendChild(footer);
      overlay.appendChild(panel);
      document.body.appendChild(overlay);
      document.addEventListener('keydown', onKey, true);

      img.addEventListener('load', function () {
        cropper = new Cropper(img, {
          viewMode: 1,
          autoCropArea: 1,
          background: false,
          responsive: true,
          crop: updateNote
        });
      });
      okBtn.focus();
    });
  }

  window.OMCrop = { open: open };

  // ── Global interceptor: any <input type="file" data-om-crop> routes its
  //    picked file through the cropper, then re-feeds the cropped file to the
  //    page's own change handlers. Capture phase + re-dispatch keeps existing
  //    upload code untouched. Works for dynamically added inputs (delegated).
  document.addEventListener('change', function (e) {
    var input = e.target;
    if (!input || input.tagName !== 'INPUT' || input.type !== 'file') return;
    if (!input.hasAttribute('data-om-crop')) return;
    if (input.multiple) return;                 // single-file inputs only
    if (input.__omCropPass) { input.__omCropPass = false; return; } // our re-dispatch
    if (!input.files || !input.files[0]) return;

    e.preventDefault();
    e.stopImmediatePropagation();
    var original = input.files[0];

    open(original).then(function (result) {
      if (!result) { input.value = ''; return; }   // cancelled
      try {
        var dt = new DataTransfer();
        dt.items.add(result);
        input.files = dt.files;
      } catch (err) {
        // DataTransfer unsupported — fall back to original, uncropped.
        return;
      }
      input.__omCropPass = true;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }, true);
})();

/*
 * OMCrop — shared image crop modal for the admin.
 * Usage: OMCrop.open(file).then(function (croppedFile) { ... })
 *   - resolves with a cropped File, or null if the user cancelled.
 *   - animated GIFs and non-images bypass cropping and resolve with the original file.
 * Or:    OMCrop.pick(url, { aspectRatio, data }).then(function (rect) { ... })
 *   - picks a part of an existing image (locked shape) and resolves with its pixel
 *     rectangle, or null if cancelled. Nothing is uploaded or re-encoded.
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

  // Builds the modal. o: { src, title, label, presets, igNote, aspectRatio, data, help,
  // onDone(cropper, img, finish), onCleanup() }. Resolves with whatever onDone hands
  // finish(), or null when cancelled.
  function modal(o) {
    return new Promise(function (resolve) {
      var cropper = null;
      var settled = false;
      var opener = document.activeElement;

      function cleanup() {
        if (cropper) { cropper.destroy(); cropper = null; }
        if (o.onCleanup) o.onCleanup();
        document.removeEventListener('keydown', onKey, true);
        if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        // Hand focus back to whatever opened the cropper.
        if (opener && opener.focus && document.contains(opener)) opener.focus();
      }
      function finish(result) {
        if (settled) return;
        settled = true;
        cleanup();
        resolve(result);
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); finish(null); return; }
        if (e.key === 'Tab') {   // keep focus inside the dialog
          var f = panel.querySelectorAll('button, [tabindex="0"]');
          if (!f.length) return;
          var first = f[0], last = f[f.length - 1];
          if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
          else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
          return;
        }
        // Keyboard way to move/resize the frame (pick mode). Works wherever focus is in the
        // dialog — clicking the photo doesn't focus it, so requiring that made the keys dead.
        if (!o.keyboard || !cropper) return;
        var d = cropper.getData(), step = (e.shiftKey ? 0.1 : 0.02) * Math.min(img.naturalWidth, img.naturalHeight);
        var moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        if (moves[e.key]) {
          e.preventDefault();
          cropper.setData({ x: d.x + moves[e.key][0], y: d.y + moves[e.key][1] });
        } else if (e.key === '+' || e.key === '=' || e.key === '-') {
          e.preventDefault();
          var k = e.key === '-' ? 0.9 : 1.1, nw = d.width * k, nh = d.height * k;
          cropper.setData({ x: d.x - (nw - d.width) / 2, y: d.y - (nh - d.height) / 2, width: nw, height: nh });
        }
      }

      // ── Overlay
      var overlay = document.createElement('div');
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-modal', 'true');
      overlay.setAttribute('aria-label', o.label || o.title);
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
      title.textContent = o.title;
      title.style.cssText =
        'padding:0.9rem 1.1rem;border-bottom:1px solid ' + BORDER + ';font-weight:600;color:' + TEXT + ';';
      panel.appendChild(title);

      var img = document.createElement('img');

      // ── Preset bar
      if (o.presets) {
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
        panel.appendChild(presetBar);
      }

      if (o.help) {
        var help = document.createElement('p');
        help.id = 'omCropHelp';
        help.textContent = o.help;
        help.style.cssText = 'margin:0;padding:0.6rem 1.1rem;font-size:0.85rem;color:' + MUTED + ';border-bottom:1px solid ' + BORDER + ';';
        panel.appendChild(help);
      }

      // ── Image stage
      var stage = document.createElement('div');
      stage.style.cssText = 'flex:1;min-height:0;background:#f3efe9;padding:0.5rem;overflow:hidden;';
      if (o.keyboard) {
        stage.tabIndex = 0;
        stage.setAttribute('role', 'group');
        stage.setAttribute('aria-label', 'Рамка за изрязване');
        if (o.help) stage.setAttribute('aria-describedby', 'omCropHelp');
        stage.addEventListener('focus', function () { stage.style.outline = '3px solid ' + TEAL; stage.style.outlineOffset = '-3px'; });
        stage.addEventListener('blur', function () { stage.style.outline = 'none'; });
        // Cropper.js swallows the pointer, so a click never focuses the stage on its own.
        stage.addEventListener('pointerdown', function () { stage.focus({ preventScroll: true }); });
      }
      img.src = o.src;
      img.alt = '';
      img.style.cssText = 'display:block;max-width:100%;max-height:60vh;';
      stage.appendChild(img);
      panel.appendChild(stage);

      // ── IG note (warn only)
      var note = document.createElement('div');
      note.style.cssText =
        'padding:0.5rem 1.1rem;color:' + AMBER + ';font-size:0.85rem;min-height:1.2em;';

      function updateNote() {
        if (!cropper || !o.igNote) return;
        var d = cropper.getData(true);
        if (!d.width || !d.height) { note.textContent = ''; return; }
        var ratio = d.width / d.height;
        note.textContent = (ratio < IG_MIN || ratio > IG_MAX)
          ? 'Instagram ще отреже това, за да се побере — изрежете тук, ако искате да контролирате какво остава.'
          : '';
      }
      if (o.igNote) panel.appendChild(note);

      // ── Footer
      var footer = document.createElement('div');
      footer.style.cssText =
        'display:flex;justify-content:flex-end;gap:0.6rem;padding:0.85rem 1.1rem;border-top:1px solid ' + BORDER + ';';
      var cancelBtn = btn('Отказ', false);
      var okBtn = btn('Готово', true);
      cancelBtn.addEventListener('click', function () { finish(null); });
      okBtn.addEventListener('click', function () {
        if (!cropper) { finish(null); return; }
        o.onDone(cropper, img, finish);
      });
      footer.appendChild(cancelBtn);
      footer.appendChild(okBtn);
      panel.appendChild(footer);

      overlay.appendChild(panel);
      document.body.appendChild(overlay);
      document.addEventListener('keydown', onKey, true);

      img.addEventListener('load', function () {
        var opts = {
          viewMode: 1,
          autoCropArea: 1,
          background: false,
          responsive: true,
          crop: updateNote
        };
        if (o.aspectRatio) opts.aspectRatio = o.aspectRatio;
        if (o.data) opts.data = o.data;
        if (o.keyboard) { opts.dragMode = 'none'; opts.zoomable = false; opts.movable = false; }
        cropper = new Cropper(img, opts);
      });
      // Pick mode opens on the photo, so the arrows and +/− work straight away.
      (o.keyboard ? stage : okBtn).focus();
    });
  }

  /*
   * OMCrop.open(file) — crop a picked file. Resolves with a cropped File, or null if
   * the user cancelled.
   */
  function open(file) {
    // Bypass: non-images and animated-capable GIFs upload untouched.
    if (!file || !/^image\//.test(file.type) || file.type === 'image/gif') {
      return Promise.resolve(file || null);
    }
    var objectUrl = URL.createObjectURL(file);
    return modal({
      src: objectUrl,
      title: 'Изрежи снимката',
      label: 'Изрязване на снимка',
      presets: true,
      igNote: true,
      onCleanup: function () { URL.revokeObjectURL(objectUrl); },
      onDone: function (cropper, img, finish) {
        var keepAlpha = (file.type === 'image/png' || file.type === 'image/webp');
        var canvas = cropper.getCroppedCanvas({
          maxWidth: 2000, maxHeight: 2000,
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
        }, outType, 0.82);
      }
    });
  }

  /*
   * OMCrop.pick(url, opts) — choose a part of an image already on the site, without
   * making a new file. opts: { aspectRatio (locked), data: {x, y, width, height} in
   * the image's own pixels to start from, title, help }. Resolves with
   * { x, y, width, height, naturalWidth, naturalHeight } or null if cancelled.
   */
  function pick(url, opts) {
    opts = opts || {};
    return modal({
      src: url,
      title: opts.title || 'Изберете коя част да се вижда',
      help: opts.help || 'Плъзнете рамката. С клавиатурата: стрелките местят рамката, + и − я уголемяват и смаляват.',
      presets: false,
      igNote: false,
      keyboard: true,
      aspectRatio: opts.aspectRatio,
      data: opts.data,
      onDone: function (cropper, img, finish) {
        var d = cropper.getData(true);
        finish({ x: d.x, y: d.y, width: d.width, height: d.height,
                 naturalWidth: img.naturalWidth, naturalHeight: img.naturalHeight });
      }
    });
  }

  window.OMCrop = { open: open, pick: pick };

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

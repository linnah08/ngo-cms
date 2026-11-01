/**
 * OMUpload — uploads a file with a visible progress bar.
 *
 * Usage: OMUpload.send(url, formData, { into: el, label: 'Снимка 2 от 5' })
 *        OMUpload.send(url, formData, { after: el, pdf: true })
 *          .then(function (json) { ... }, function (err) { ... });
 *
 * fetch() can't report how much of the file has been sent, so an upload used to look
 * like nothing was happening for a few seconds. This sends with XMLHttpRequest and shows
 * „Качване… 45%“, then „Обработва се…“ while the server shrinks the photo.
 *
 * - into: the bar is added at the end of this element and removed when the upload ends.
 *   after: the bar goes right after this element instead.
 *   With neither, the bar shows in a small panel at the top of the screen, above any open
 *   dialog (the TinyMCE image dialog, Edit mode on public pages).
 * - label: put before the words, e.g. „Снимка 2 от 5“ when several go up one by one.
 * - pdf: true says „файлът“ instead of „снимката“.
 * - Resolves with the parsed JSON reply, whatever the HTTP status, like r.json() did.
 *   Rejects with an Error when the connection fails or the reply isn't JSON (its message
 *   is then the start of the reply).
 *
 * OMUpload.notice(msg) shows a message in that panel until it is closed — for errors
 * where there is no page element to put them in.
 *
 * All styles are inline: admin.css / main.css may be stale-cached on the server.
 */
(function () {
  'use strict';

  var GREEN = '#2d6a35';
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var live = null, panel = null;

  // One live region for the whole page: a region removed right after its text
  // changes is often never read out.
  function announce(msg) {
    if (!live) {
      live = document.createElement('div');
      live.setAttribute('role', 'status');
      live.setAttribute('aria-live', 'polite');
      live.style.cssText = 'position:absolute;width:1px;height:1px;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;';
      document.body.appendChild(live);
    }
    live.textContent = '';
    setTimeout(function () { live.textContent = msg; }, 50);
  }

  function floatingPanel() {
    if (panel && panel.isConnected) return panel;
    panel = document.createElement('div');
    panel.className = 'om-upload-panel';
    panel.style.cssText = 'position:fixed;top:1rem;left:50%;transform:translateX(-50%);z-index:2147483000;'
      + 'width:min(360px,calc(100vw - 32px));box-sizing:border-box;background:#fff;color:#1f2937;'
      + 'border:1px solid #6b7280;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.2);'
      + 'padding:.75rem 1rem;font:400 .9rem/1.4 system-ui,-apple-system,"Segoe UI",sans-serif;';
    document.body.appendChild(panel);
    return panel;
  }

  function closePanelIfEmpty() {
    if (panel && !panel.children.length) { panel.remove(); panel = null; }
  }

  function makeBar(label, pdf) {
    var wrap = document.createElement('div');
    wrap.className = 'om-upload';
    wrap.style.cssText = 'margin:.5rem 0;max-width:420px;';

    var text = document.createElement('div');
    text.setAttribute('aria-hidden', 'true');   // the progressbar carries the same words
    text.style.cssText = 'font-size:.85rem;font-weight:600;color:#1f2937;margin-bottom:.3rem;';

    var track = document.createElement('div');
    track.setAttribute('role', 'progressbar');
    track.setAttribute('aria-label', label ? 'Качване: ' + label : (pdf ? 'Качване на файла' : 'Качване на снимката'));
    track.setAttribute('aria-valuemin', '0');
    track.setAttribute('aria-valuemax', '100');
    track.style.cssText = 'position:relative;height:10px;background:#e5e7eb;border:1px solid #6b7280;'
      + 'border-radius:999px;overflow:hidden;';

    var fill = document.createElement('div');
    fill.style.cssText = 'height:100%;width:0;background:' + GREEN + ';border-radius:999px;'
      + (reduceMotion ? '' : 'transition:width .2s ease-out;');
    track.appendChild(fill);
    wrap.appendChild(text);
    wrap.appendChild(track);

    var prefix = label ? label + ' — ' : '', anim = null;
    return {
      el: wrap,
      progress: function (pct) {
        text.textContent = prefix + 'Качване… ' + pct + '%';
        track.setAttribute('aria-valuenow', String(pct));
        track.setAttribute('aria-valuetext', pct + '%');
        fill.style.width = pct + '%';
      },
      // The file has arrived; the server is resizing it. How long that takes is
      // unknown, so the bar stops counting and a stripe moves along it instead.
      processing: function () {
        text.textContent = prefix + 'Обработва се…';
        track.removeAttribute('aria-valuenow');
        track.setAttribute('aria-valuetext', 'Обработва се');
        if (reduceMotion || !fill.animate) { fill.style.width = '100%'; return; }
        fill.style.transition = 'none';
        fill.style.width = '35%';
        anim = fill.animate([{ transform: 'translateX(-100%)' }, { transform: 'translateX(300%)' }],
                            { duration: 1100, iterations: Infinity, easing: 'ease-in-out' });
      },
      remove: function () { if (anim) anim.cancel(); wrap.remove(); closePanelIfEmpty(); }
    };
  }

  function send(url, formData, opts) {
    opts = opts || {};
    var floating = !opts.into && !opts.after;
    var bar = makeBar(opts.label || '', !!opts.pdf);
    if (opts.after) opts.after.parentNode.insertBefore(bar.el, opts.after.nextSibling);
    else (floating ? floatingPanel() : opts.into).appendChild(bar.el);
    bar.progress(0);
    if (floating) announce(opts.pdf ? 'Качване на файла…' : 'Качване на снимката…');

    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest(), processing = false;
      function toProcessing() {
        if (processing) return;
        processing = true;
        bar.progress(100);
        bar.processing();
        announce(opts.pdf ? 'Файлът е качен. Обработва се…' : 'Снимката е качена. Обработва се…');
      }
      xhr.open('POST', url);
      xhr.withCredentials = true;
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.onprogress = function (e) {
        if (!e.lengthComputable || processing) return;
        var pct = Math.min(99, Math.floor(e.loaded / e.total * 100));
        bar.progress(pct);
      };
      xhr.upload.onload = toProcessing;
      xhr.onload = function () {
        bar.remove();
        var text = xhr.responseText || '';
        try { resolve(JSON.parse(text)); }
        catch (e) { reject(new Error(text.substring(0, 200) || 'Празен отговор от сървъра.')); }
      };
      xhr.onerror = xhr.onabort = xhr.ontimeout = function () {
        bar.remove();
        reject(new Error('network'));
      };
      xhr.send(formData);
    });
  }

  function notice(msg) {
    var p = floatingPanel();
    var box = document.createElement('div');
    box.setAttribute('role', 'alert');
    box.style.cssText = 'display:flex;gap:.75rem;align-items:flex-start;color:#7f1d1d;font-weight:600;';
    var span = document.createElement('span');
    span.style.cssText = 'flex:1;padding-top:.6rem;';
    span.textContent = '⚠ ' + msg;
    var close = document.createElement('button');
    close.type = 'button';
    close.textContent = 'Затвори';
    close.style.cssText = 'min-height:44px;min-width:44px;padding:0 .75rem;border:1px solid #6b7280;'
      + 'border-radius:6px;background:#fff;color:#1f2937;font:inherit;font-weight:600;cursor:pointer;';
    close.addEventListener('click', function () { box.remove(); closePanelIfEmpty(); });
    box.appendChild(span);
    box.appendChild(close);
    p.appendChild(box);
  }

  window.OMUpload = { send: send, notice: notice };
})();

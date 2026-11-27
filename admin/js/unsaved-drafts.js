/*
 * „Незапазени чернови“ — lists text an editor kept in this browser's backup
 * (admin/js/autosave.js) that never reached the server: an article written while
 * the session ran out, or a tab closed before „Запази“. Without this box the text
 * was only offered on the „Нова статия“ page, where nobody thought to look.
 *
 *   showUnsavedDrafts({
 *     mountId:  'unsavedDrafts',                    // empty element on the list page
 *     prefix:   'article:',                         // autosave key prefix
 *     titleField: 'title',                          // form field holding the name
 *     editUrl:  function (id) { return '...'; },    // id = 'new' or the item's own id
 *     newLabel: 'Нова статия, още не е запазена'
 *   });
 */
(function () {
  'use strict';

  function ago(ts) {
    var diff = Math.max(0, Math.floor((Date.now() - (ts || 0)) / 1000));
    if (diff < 60)    return 'преди по-малко от минута';
    if (diff < 3600)  return 'преди ' + Math.floor(diff / 60) + ' мин.';
    if (diff < 86400) return 'преди ' + Math.floor(diff / 3600) + ' ч.';
    var d = Math.floor(diff / 86400);
    return 'преди ' + d + (d === 1 ? ' ден' : ' дни');
  }

  function plain(html) {
    var div = document.createElement('div');
    div.innerHTML = String(html || '');
    return (div.textContent || '').trim();
  }

  // Has the person actually written something? An opened-and-left form isn't a draft.
  function hasText(data) {
    return Object.keys(data).some(function (k) {
      var v = data[k];
      if (typeof v !== 'string') return false;
      if (k.indexOf('_tiny_') === 0) return plain(v) !== '';
      return false;
    }) || ['title', 'name_bg', 'excerpt', 'content'].some(function (k) {
      return typeof data[k] === 'string' && plain(data[k]) !== '';
    });
  }

  function readDrafts(prefix) {
    var out = [];
    try {
      Object.keys(localStorage).forEach(function (k) {
        if (k.indexOf('autosave:' + prefix) !== 0) return;
        var saved;
        try { saved = JSON.parse(localStorage.getItem(k)); } catch (e) { return; }
        if (!saved || !saved.data || !hasText(saved.data)) return;
        out.push({ storageKey: k, id: k.slice(('autosave:' + prefix).length), ts: saved.ts || 0, data: saved.data });
      });
    } catch (e) {}
    out.sort(function (a, b) { return b.ts - a.ts; });
    return out;
  }

  window.showUnsavedDrafts = function (cfg) {
    var mount = document.getElementById(cfg.mountId);
    if (!mount) return;
    var drafts = readDrafts(cfg.prefix);
    mount.innerHTML = '';
    if (!drafts.length) { mount.hidden = true; return; }
    mount.hidden = false;

    var box = document.createElement('section');
    box.setAttribute('aria-labelledby', cfg.mountId + 'Title');
    box.style.cssText = 'background:#fffbeb;border:1px solid #b45309;border-radius:8px;'
      + 'padding:1rem 1.25rem;margin-bottom:1.5rem;color:#422006;';

    var h = document.createElement('h2');
    h.id = cfg.mountId + 'Title';
    h.style.cssText = 'font-size:1.05rem;margin:0 0 .35rem;color:#78350f;';
    h.textContent = '✎ Незапазени чернови (' + drafts.length + ')';
    box.appendChild(h);

    var p = document.createElement('p');
    p.style.cssText = 'margin:0 0 .75rem;font-size:.9rem;line-height:1.5;';
    p.textContent = 'Този текст е запазен само в този браузър и още не е на сайта. '
      + 'Отворете го и натиснете „Запази“.';
    box.appendChild(p);

    var list = document.createElement('ul');
    list.style.cssText = 'list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.5rem;';

    drafts.forEach(function (d) {
      var isNew = d.id === 'new';
      var title = plain(d.data[cfg.titleField]) || 'Без заглавие';

      var li = document.createElement('li');
      li.style.cssText = 'display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;background:#fff;'
        + 'border:1px solid #f3d58b;border-radius:6px;padding:.6rem .8rem;';

      var text = document.createElement('div');
      text.style.cssText = 'flex:1;min-width:200px;';
      var strong = document.createElement('strong');
      strong.textContent = title;
      var meta = document.createElement('div');
      meta.style.cssText = 'font-size:.85rem;color:#57534e;margin-top:.15rem;';
      meta.textContent = (isNew ? cfg.newLabel : 'Незапазени промени')
        + ' · ' + ago(d.ts);
      text.appendChild(strong);
      text.appendChild(meta);

      var open = document.createElement('a');
      open.href = cfg.editUrl(d.id) + (cfg.editUrl(d.id).indexOf('?') === -1 ? '?' : '&') + 'restore=1';
      open.className = 'btn btn--primary';
      open.style.cssText = 'min-height:44px;display:inline-flex;align-items:center;font-size:.9rem;';
      open.textContent = 'Продължи писането';
      open.setAttribute('aria-label', 'Продължи писането: ' + title);

      var del = document.createElement('button');
      del.type = 'button';
      del.className = 'btn btn--outline';
      del.style.cssText = 'min-height:44px;font-size:.9rem;';
      del.textContent = 'Изтрий';
      del.setAttribute('aria-label', 'Изтрий черновата: ' + title);
      del.addEventListener('click', function () {
        var ask = window._adminConfirm
          ? window._adminConfirm('Да изтрия ли черновата „' + title + '“? Текстът ще се загуби завинаги.')
          : Promise.resolve(true);
        Promise.resolve(ask).then(function (ok) {
          if (!ok) return;
          try { localStorage.removeItem(d.storageKey); } catch (e) {}
          window.showUnsavedDrafts(cfg);
          var live = document.getElementById(cfg.mountId + 'Live');
          if (live) live.textContent = 'Черновата е изтрита.';
          // The pressed button is gone; keep keyboard focus in the box, not at the page top.
          var head = document.getElementById(cfg.mountId + 'Title');
          if (head) { head.tabIndex = -1; head.focus(); }
        });
      });

      li.appendChild(text);
      li.appendChild(open);
      li.appendChild(del);
      list.appendChild(li);
    });

    box.appendChild(list);
    mount.appendChild(box);
  };
}());

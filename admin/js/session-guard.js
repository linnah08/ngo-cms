(function () {
  'use strict';

  var expiresAt  = (window._sessionExpiresAt || 0) * 1000; // server Unix ts → ms
  var csrfToken  = window._csrfToken || '';
  var WARNING_MS = 10 * 60 * 1000; // warn 10 min before expiry
  var warned     = false;
  var warnTimer  = null;

  function hasLocalDraft() {
    try {
      return Object.keys(localStorage).some(function (k) {
        return k.indexOf('autosave:') === 0;
      });
    } catch (e) { return false; }
  }

  function showExpiredBanner() {
    var hasDraft = hasLocalDraft();
    var banner = document.createElement('div');
    banner.style.cssText = 'position:fixed;top:0;left:0;right:0;background:#dc2626;color:#fff;'
      + 'padding:.85rem 1.25rem;text-align:center;z-index:99999;font-size:.95rem;line-height:1.5;';
    banner.innerHTML = 'Сесията ви е изтекла.'
      + (hasDraft ? ' Черновата е запазена — ' : ' ')
      + '<a href="/admin/login.php" style="color:#fff;font-weight:700;text-decoration:underline;">'
      + 'Влезте отново, за да продължите.</a>';
    document.body.prepend(banner);
  }

  function scheduleWarning() {
    clearTimeout(warnTimer);
    var delay = expiresAt - Date.now() - WARNING_MS;
    if (delay <= 0) { showWarning(); return; }
    warnTimer = setTimeout(showWarning, delay);
  }

  function showWarning() {
    if (warned) return;
    warned = true;

    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99998;'
      + 'display:flex;align-items:center;justify-content:center;padding:1rem;';

    var box = document.createElement('div');
    box.style.cssText = 'background:#fff;border-radius:10px;padding:2rem;max-width:400px;'
      + 'width:100%;box-shadow:0 24px 64px rgba(0,0,0,.3);text-align:center;';

    var msg = document.createElement('p');
    msg.style.cssText = 'font-size:1rem;margin:0 0 1.25rem;color:#111;line-height:1.5;';

    var btns = document.createElement('div');
    btns.style.cssText = 'display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;';

    var keepBtn = document.createElement('button');
    keepBtn.type = 'button';
    keepBtn.textContent = 'Остани в системата';
    keepBtn.style.cssText = 'padding:.55rem 1.1rem;background:#2563eb;color:#fff;border:none;'
      + 'border-radius:6px;cursor:pointer;font-size:.9rem;font-weight:600;';

    var dismissBtn = document.createElement('button');
    dismissBtn.type = 'button';
    dismissBtn.textContent = 'Затвори';
    dismissBtn.style.cssText = 'padding:.55rem 1.1rem;background:#fff;color:#555;'
      + 'border:1px solid #d1d5db;border-radius:6px;cursor:pointer;font-size:.9rem;';

    btns.appendChild(keepBtn);
    btns.appendChild(dismissBtn);
    box.appendChild(msg);
    box.appendChild(btns);
    overlay.appendChild(box);
    document.body.appendChild(overlay);

    function updateMsg() {
      var rem  = Math.max(0, expiresAt - Date.now());
      var mins = Math.floor(rem / 60000);
      var secs = String(Math.floor((rem % 60000) / 1000)).padStart(2, '0');
      msg.textContent = 'Сесията ви изтича след ' + mins + ':' + secs + '. Продължавате ли да работите?';
      if (rem <= 0) { clearInterval(ticker); overlay.remove(); showExpiredBanner(); }
    }
    updateMsg();
    var ticker = setInterval(updateMsg, 1000);

    keepBtn.addEventListener('click', function () {
      var fd = new FormData();
      fd.append('csrf_token', csrfToken);
      fetch('/admin/session-ping.php', { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          clearInterval(ticker);
          overlay.remove();
          warned = false;
          if (d.alive) {
            expiresAt = d.expiresAt * 1000;
            scheduleWarning();
          } else {
            showExpiredBanner();
          }
        })
        .catch(function () {
          clearInterval(ticker);
          overlay.remove();
          warned = false;
        });
    });

    dismissBtn.addEventListener('click', function () {
      clearInterval(ticker);
      overlay.remove();
      warned = false;
    });
  }

  // Page-load ping: detect already-expired session before user starts typing
  document.addEventListener('DOMContentLoaded', function () {
    fetch('/admin/session-ping.php', { method: 'GET', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.alive) { showExpiredBanner(); return; }
        if (d.expiresAt) expiresAt = d.expiresAt * 1000;
        scheduleWarning();
      })
      .catch(function () { /* server unreachable — skip guard */ });
  });
}());

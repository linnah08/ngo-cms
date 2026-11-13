// Socials panel beside the article editor (admin/article-edit.php).
//
// The Facebook, Instagram and LinkedIn posts ARE the article text, cut to each
// platform's limit. While the panel is open, the previews are rebuilt by
// /admin/social-preview-ajax.php from the unsaved editor text about a second after
// typing stops. Only the newest reply is shown, so a slow old reply can't overwrite
// a newer preview.
//
// Exposes window.SocialPanel = { refresh(): Promise, isOpen(): boolean }.
(function () {
  'use strict';

  var panel  = document.getElementById('socialPanel');
  var toggle = document.getElementById('socialToggle');
  if (!panel || !toggle) return;

  var layout   = document.getElementById('editorLayout');
  var content  = document.querySelector('.admin-content');
  var statusEl = document.getElementById('socStatus');
  var errorEl  = document.getElementById('socError');
  var errorMsg = document.getElementById('socErrorMsg');
  var slug     = panel.dataset.slug || '';
  var STORAGE_KEY = 'socialPanelOpen';
  var PLATFORMS   = ['fb', 'insta', 'linkedin'];

  var MSG_UPDATED  = 'Прегледът е обновен';
  var MSG_FAILED   = 'Прегледът не е обновен — опитайте отново';
  var MSG_SESSION  = 'Сесията е изтекла — презаредете страницата.';
  var MSG_START    = 'Започнете да пишете — тук ще видите публикациите.';
  var MSG_NO_SLUG  = 'Запазете статията, преди да планирате публикация.';
  var MSG_NETWORK  = 'Грешка при свързване.';

  function csrf() {
    var f = document.querySelector('#articleForm input[name="csrf_token"]');
    return f ? f.value : (window._csrfToken || '');
  }

  // POST JSON and read JSON back. An expired session answers with a redirect to the
  // login page (HTML), so anything that isn't JSON is reported as that, in plain words.
  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ csrf_token: csrf() }, body))
    }).then(function (r) {
      var type = r.headers.get('Content-Type') || '';
      if (r.redirected || type.indexOf('application/json') === -1) {
        var e = new Error(MSG_SESSION); e.userMessage = MSG_SESSION; throw e;
      }
      return r.json();
    });
  }

  // ── Open / close, and the two-column layout ──────────────────────────────────
  var wide = window.matchMedia('(min-width: 1100px)');

  function isOpen() { return !panel.hidden; }

  // Two columns (article ~60%, panel ~40%) from 1100px; below that the panel sits
  // under the article. Inline styles, because admin.css may be stale-cached.
  function applyLayout() {
    var side = isOpen() && wide.matches;
    layout.style.display             = side ? 'grid' : 'block';
    layout.style.gridTemplateColumns = side ? 'minmax(0,3fr) minmax(0,2fr)' : '';
    layout.style.gap                 = side ? '1.5rem' : '';
    layout.style.alignItems          = side ? 'start' : '';
    panel.style.position  = side ? 'sticky' : '';
    panel.style.top       = side ? '1rem' : '';
    panel.style.maxHeight = side ? 'calc(100vh - 2rem)' : '';
    panel.style.overflowY = side ? 'auto' : '';
    if (content) content.style.maxWidth = isOpen() ? '1400px' : '';
  }

  function setOpen(open, opts) {
    opts = opts || {};
    panel.hidden = !open;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    var state = document.getElementById('socialToggleState');
    if (state) state.textContent = open ? '▾' : '▸';
    try { localStorage.setItem(STORAGE_KEY, open ? '1' : '0'); } catch (e) {}
    applyLayout();
    if (open) {
      refresh();
      if (opts.focus) {
        var title = document.getElementById('socialPanelTitle');
        title.setAttribute('tabindex', '-1');
        title.focus();
      }
    } else {
      clearTimeout(timer);
      toggle.focus();
    }
  }

  toggle.addEventListener('click', function () { setOpen(!isOpen(), { focus: true }); });
  document.getElementById('socialClose').addEventListener('click', function () { setOpen(false); });
  if (wide.addEventListener) wide.addEventListener('change', applyLayout);
  else if (wide.addListener) wide.addListener(applyLayout);

  // ── Platform tabs (role="tablist"; arrows, Home and End move between them) ────
  var tabs = PLATFORMS.map(function (p) { return document.getElementById('socTab-' + p); });

  function selectTab(tab, focus) {
    tabs.forEach(function (t) {
      var on = t === tab;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
      t.style.borderBottomColor = on ? 'var(--teal)' : 'transparent';
      t.style.fontWeight = on ? '700' : '500';
      t.style.color = on ? 'var(--text)' : 'var(--text-muted)';
      document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
    });
    if (focus) tab.focus();
  }

  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { selectTab(t, false); });
    t.addEventListener('keydown', function (e) {
      var next = null;
      if (e.key === 'ArrowRight') next = tabs[(i + 1) % tabs.length];
      else if (e.key === 'ArrowLeft') next = tabs[(i - 1 + tabs.length) % tabs.length];
      else if (e.key === 'Home') next = tabs[0];
      else if (e.key === 'End') next = tabs[tabs.length - 1];
      if (!next) return;
      e.preventDefault();
      selectTab(next, true);
    });
  });

  // ── Reading the unsaved article ─────────────────────────────────────────────
  function editorHtml(id) {
    var ed = window.tinymce && tinymce.get(id);
    if (ed) return ed.getContent();
    var el = document.getElementById(id);   // the EN editor starts only when its section opens
    return el ? el.value : '';
  }
  function fieldValue(id) {
    var el = document.getElementById(id);
    return el ? el.value : '';
  }
  function hasText(html) {
    return String(html || '').replace(/<[^>]*>/g, '').replace(/&nbsp;| /g, ' ').trim() !== '';
  }

  // ── Rendering ────────────────────────────────────────────────────────────────
  var lastText = { fb: '', insta: '', linkedin: '' };
  var blocked  = { fb: false, insta: false, linkedin: false };   // no text → no scheduling

  function groupDigits(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }

  function scheduleButtons(platform) {
    return {
      fb:       ['socFbBtn', 'socFbNowBtn'],
      insta:    ['socInstaBtn', 'socInstaNowBtn'],
      linkedin: ['liScheduleBtn', 'liNowBtn']
    }[platform].map(function (id) { return document.getElementById(id); });
  }

  function setBlocked(platform, on) {
    blocked[platform] = on;
    scheduleButtons(platform).forEach(function (b) { if (b) b.disabled = on; });
  }

  function renderPlatform(platform, post, emptyText) {
    var preview = document.getElementById('socPreview-' + platform);
    var count   = document.getElementById('socCount-' + platform);
    var cut     = document.getElementById('socCut-' + platform);
    var copyBtn = panel.querySelector('.soc-copy[data-platform="' + platform + '"]');
    var text    = post && post.text ? post.text : '';
    lastText[platform] = text;
    if (!text) {
      preview.textContent = emptyText || '';
      count.textContent   = '';
      cut.hidden          = true;
    } else {
      preview.textContent = text;
      count.textContent   = 'Дължина: ' + groupDigits(post.length) + ' / ' + groupDigits(post.limit) + ' знака';
      cut.hidden          = !post.cut;
    }
    if (copyBtn) copyBtn.disabled = !text;
    setBlocked(platform, !text);
  }

  function renderLinkedIn(post) {
    var empty = !post || !post.text;
    document.getElementById('socEnEmpty').hidden = !empty;
    document.getElementById('socPreview-linkedin').parentNode.hidden = empty;
    renderPlatform('linkedin', post, '');
  }

  function renderEmpty() {
    renderPlatform('fb', null, MSG_START);
    renderPlatform('insta', null, MSG_START);
    renderLinkedIn(null);
  }

  // The first photo of each post: the article's main photo, as the grid shows it now.
  function updatePhotos() {
    var rows = Array.prototype.slice.call(document.querySelectorAll('#apGrid .ap-photo'));
    var main = rows.filter(function (r) {
      var b = r.querySelector('.ap-main');
      return b && b.getAttribute('aria-pressed') === 'true';
    })[0] || rows[0];
    var src = main ? main.dataset.src : '';
    // style.display, not [hidden]: the global img reset would show a hidden image.
    panel.querySelectorAll('.soc-photo').forEach(function (img) {
      if (!src) { img.style.display = 'none'; img.removeAttribute('src'); return; }
      if (img.getAttribute('src') !== src) img.src = src;
      img.style.display = 'block';
    });
  }
  var grid = document.getElementById('apGrid');
  if (grid && window.MutationObserver) {
    new MutationObserver(updatePhotos).observe(grid, {
      childList: true, subtree: true, attributes: true, attributeFilter: ['aria-pressed', 'data-src']
    });
  }

  function announce(msg) {
    statusEl.textContent = '';
    setTimeout(function () { statusEl.textContent = msg; }, 50);
  }
  function showError(msg) {
    errorMsg.textContent = msg;
    errorEl.hidden = false;
  }
  function hideError() { errorEl.hidden = true; }

  // ── Refresh ─────────────────────────────────────────────────────────────────
  var seq = 0;      // request counter: only the newest reply is drawn
  var timer = null;

  function refresh() {
    var mySeq = ++seq;
    updatePhotos();
    var title = fieldValue('title'), html = editorHtml('content');
    var titleEn = fieldValue('title_en'), htmlEn = editorHtml('content_en');
    var bgEmpty = title.trim() === '' && !hasText(html);
    var enEmpty = titleEn.trim() === '' && !hasText(htmlEn);
    if (bgEmpty && enEmpty) {
      renderEmpty();
      hideError();
      return Promise.resolve();
    }
    return postJson('/admin/social-preview-ajax.php', {
      title: title, content: html, title_en: titleEn, content_en: htmlEn,
      slug: fieldValue('slug'), slug_en: fieldValue('slug_en')
    }).then(function (d) {
      if (mySeq !== seq) return;
      if (!d || !d.ok) {
        var e = new Error((d && d.error) || ''); e.fromServer = true; throw e;
      }
      renderPlatform('fb', bgEmpty ? null : d.fb, MSG_START);
      renderPlatform('insta', bgEmpty ? null : d.insta, MSG_START);
      renderLinkedIn(d.linkedin);
      hideError();
      announce(MSG_UPDATED);
    }).catch(function (e) {
      if (mySeq !== seq) return;
      // The old preview stays; the error says so and offers another try.
      if (e && e.userMessage) showError(e.userMessage);
      else if (e && e.fromServer && e.message) showError(MSG_FAILED + '. ' + e.message);
      else showError(MSG_FAILED);
    });
  }

  function schedule() {
    if (!isOpen()) return;
    clearTimeout(timer);
    timer = setTimeout(refresh, 1000);
  }

  document.getElementById('socRetry').addEventListener('click', function () { refresh(); });

  ['title', 'slug', 'title_en', 'slug_en'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', schedule);
  });

  function hookEditor(ed) {
    if (!ed || (ed.id !== 'content' && ed.id !== 'content_en') || ed._socialHooked) return;
    ed._socialHooked = true;
    ed.on('input change', schedule);
    ed.on('SetContent', schedule);   // a translation or a restored draft
  }
  if (window.tinymce) {
    tinymce.on('AddEditor', function (e) { hookEditor(e.editor); });
    (tinymce.get() || []).forEach(hookEditor);
  }

  // ── Copy the post text (for posting by hand) ────────────────────────────────
  panel.querySelectorAll('.soc-copy').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = lastText[btn.dataset.platform] || '';
      if (!text) return;
      var done = function () {
        var orig = btn.textContent;
        btn.textContent = '✓ Копирано';
        setTimeout(function () { btn.textContent = orig; }, 1500);
      };
      var fallback = function () {
        var ta = document.createElement('textarea');
        ta.value = text; ta.setAttribute('readonly', '');
        ta.style.position = 'absolute'; ta.style.left = '-9999px';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        ta.remove();
        btn.focus();
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, fallback);
      } else {
        fallback();
      }
    });
  });

  // ── No English text yet: translate, or open the English section ─────────────
  document.getElementById('socTranslateBtn').addEventListener('click', function () {
    var tx = document.getElementById('translateToEnBtn');
    if (tx) { tx.click(); return; }
    if (typeof window._expandEnSection === 'function') window._expandEnSection();
    var t = document.getElementById('title_en');
    if (t) t.focus();
  });

  // ── What happened to the site post, shown at the top and under the button ──
  window._showSiteNotice = function (msg, afterEl) {
    if (!msg) return;
    var top = document.getElementById('siteNotice');
    top.textContent = '✓ ' + msg;
    top.style.borderColor = '#166534'; top.style.background = '#f0fdf4'; top.style.color = '#14532d';
    top.hidden = false;
    if (!afterEl) return;
    var near = afterEl.parentNode.querySelector('.site-notice-near');
    if (!near) {
      near = document.createElement('p');
      near.className = 'site-notice-near';
      near.setAttribute('role', 'status');
      near.style.cssText = 'margin:.5rem 0 0;font-size:.85rem;font-weight:600;color:#14532d;';
      afterEl.parentNode.insertBefore(near, afterEl.nextSibling);
    }
    near.textContent = '✓ ' + msg;
  };

  function socPost(body) { return postJson('/admin/social-ajax.php', Object.assign({ slug: slug }, body)); }
  function liPost(body)  { return postJson('/admin/linkedin-ajax.php', Object.assign({ slug: slug }, body)); }

  function fmtSofia(due_at) {
    return new Date(due_at).toLocaleString('bg-BG', {
      timeZone: 'Europe/Sofia', day: '2-digit', month: '2-digit', year: 'numeric',
      hour: '2-digit', minute: '2-digit'
    });
  }

  // ── Instagram: Post / Story mode ────────────────────────────────────────────
  // A Story has no text or link — Instagram doesn't support either on an
  // automatically-published Story — so Story mode hides the text preview.
  var igMode = panel.dataset.igMode === 'story' ? 'story' : 'post';

  function _igSetMode(mode) {
    if (mode === igMode) return;
    igMode = mode;
    document.getElementById('igPostTextGroup').style.display      = mode === 'post'  ? '' : 'none';
    document.getElementById('igStoryNoteGroup').style.display     = mode === 'story' ? '' : 'none';
    document.getElementById('igPostScheduleGroup').style.display  = mode === 'post'  ? '' : 'none';
    document.getElementById('igStoryScheduleGroup').style.display = mode === 'story' ? '' : 'none';
    var postBtn  = document.getElementById('igModePostBtn');
    var storyBtn = document.getElementById('igModeStoryBtn');
    postBtn.className  = 'btn ' + (mode === 'post'  ? 'btn--primary' : 'btn--outline');
    storyBtn.className = 'btn ' + (mode === 'story' ? 'btn--primary' : 'btn--outline');
    postBtn.setAttribute('aria-pressed',  mode === 'post'  ? 'true' : 'false');
    storyBtn.setAttribute('aria-pressed', mode === 'story' ? 'true' : 'false');
    document.getElementById('igCropPost').style.display  = mode === 'post'  ? '' : 'none';
    document.getElementById('igCropStory').style.display = mode === 'story' ? '' : 'none';
    document.getElementById('igCropStatus').textContent = '';
    document.getElementById('igCropError').style.display = 'none';
    document.getElementById('socInstaError').style.display = 'none';
  }
  document.getElementById('igModePostBtn').addEventListener('click', function () { _igSetMode('post'); });
  document.getElementById('igModeStoryBtn').addEventListener('click', function () { _igSetMode('story'); });

  // ── Instagram crop previews ─────────────────────────────────────────────────
  // Each thumbnail shows the exact part Instagram gets (the original photo, shifted
  // and scaled inside a box of the final shape). Clicking opens the shared cropper
  // locked to that shape; "Готово" saves the choice and the thumbnail follows.
  var cropStatus = document.getElementById('igCropStatus');
  var cropError  = document.getElementById('igCropError');
  function cropFail(e, fallback) {
    cropStatus.textContent = '';
    cropError.textContent = (e && (e.userMessage || (e.fromServer && e.message))) || fallback;
    cropError.style.display = '';
  }
  function _igPlace(btn, r) {
    var img = btn.querySelector('img');
    img.style.width  = (100 / r.w) + '%';
    img.style.height = (100 / r.h) + '%';
    img.style.left   = (-100 * r.x / r.w) + '%';
    img.style.top    = (-100 * r.y / r.h) + '%';
    btn.dataset.rect = JSON.stringify({ x: r.x, y: r.y, w: r.w, h: r.h });
  }
  // Redraw the post's photos from a fresh preview (after the shape, a crop or "whole"
  // changes): size, part shown, and whether each is shown whole on white.
  function _igRedraw(items) {
    var btns = document.querySelectorAll('#igCropPost .ig-crop-thumb');
    items.forEach(function (it, i) {
      var b = btns[i];
      if (!b || b.dataset.src !== it.src) return;
      var w = Math.round(104 * it.ar);
      b.dataset.ar = it.ar;
      b.dataset.fit = it.fit;
      b.dataset.fillRect = JSON.stringify(it.fill_rect);
      b.style.width = (w + 4) + 'px';
      b.querySelector('span').style.width = w + 'px';
      _igPlace(b, it.rect);
      var fb = b.parentNode.querySelector('.ig-fit-btn');
      if (fb) {
        var whole = it.fit === 'whole';
        fb.setAttribute('aria-pressed', whole ? 'true' : 'false');
        fb.classList.toggle('btn--primary', whole);
        fb.classList.toggle('btn--outline', !whole);
        fb.querySelector('.ig-fit-tick').textContent = whole ? '✓ ' : '';
      }
    });
  }
  function serverError(res) { var err = new Error(res.error || ''); err.fromServer = true; return err; }

  document.querySelectorAll('.ig-fit-btn').forEach(function (fb) {
    fb.addEventListener('click', function () {
      var fit = fb.getAttribute('aria-pressed') === 'true' ? 'fill' : 'whole';
      cropError.style.display = 'none';
      cropStatus.textContent = 'Запазва се…';
      socPost({ action: 'save_fit', src: fb.dataset.src, fit: fit }).then(function (res) {
        if (!res.ok) throw serverError(res);
        _igRedraw(res.post || []);
        cropStatus.textContent = fit === 'whole'
          ? 'Запазено. Instagram ще покаже цялата снимка, с бяла рамка.'
          : 'Запазено. Снимката ще запълни рамката.';
      }).catch(function (e) {
        cropFail(e, 'Изборът не можа да се запази. Проверете връзката и опитайте отново.');
      });
    });
  });
  document.querySelectorAll('.ig-shape-btn').forEach(function (sb) {
    sb.addEventListener('click', function () {
      var all = document.querySelectorAll('.ig-shape-btn');
      var prev = document.querySelector('.ig-shape-btn[aria-pressed="true"]');
      var press = function (on) {
        all.forEach(function (x) {
          var p = x === on;
          x.setAttribute('aria-pressed', p ? 'true' : 'false');
          x.classList.toggle('btn--primary', p);
          x.classList.toggle('btn--outline', !p);
        });
      };
      press(sb);
      cropError.style.display = 'none';
      cropStatus.textContent = 'Запазва се…';
      socPost({ action: 'save_shape', shape: sb.dataset.shape }).then(function (res) {
        if (!res.ok) throw serverError(res);
        _igRedraw(res.post || []);
        cropStatus.textContent = 'Запазено. Instagram ще получи снимките в тази форма.';
      }).catch(function (e) {
        press(prev);
        cropFail(e, 'Формата не можа да се запази. Проверете връзката и опитайте отново.');
      });
    });
  });
  document.querySelectorAll('.ig-crop-thumb').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!window.OMCrop || !OMCrop.pick) return;
      var kind = btn.closest('[data-kind]').dataset.kind;
      var w = +btn.dataset.w, h = +btn.dataset.h, ar = +btn.dataset.ar;
      var cur = JSON.parse(btn.dataset.rect);   // what Instagram gets now, as fractions
      // Shown whole: the cropper starts from the part a fill would show.
      var start = btn.dataset.fit === 'whole' ? JSON.parse(btn.dataset.fillRect) : cur;
      cropError.style.display = 'none';
      OMCrop.pick(btn.dataset.src, {
        aspectRatio: ar,
        data: { x: start.x * w, y: start.y * h, width: start.w * w, height: start.h * h }
      }).then(function (d) {
        if (!d) return;
        var nw = d.naturalWidth || w, nh = d.naturalHeight || h;
        var rect = { x: d.x / nw, y: d.y / nh, w: d.width / nw, h: d.height / nh, ar: ar };
        _igPlace(btn, rect);   // the thumbnail follows at once
        cropStatus.textContent = 'Запазва се…';
        return socPost({ action: 'save_crop', kind: kind, src: btn.dataset.src, rect: rect }).then(function (res) {
          if (!res.ok) throw serverError(res);
          _igPlace(btn, res.rect);
          if (res.post) _igRedraw(res.post);   // a chosen crop means the photo fills the frame
          cropStatus.textContent = 'Запазено. Instagram ще покаже тази част от снимката.';
        }).catch(function (e) {
          _igPlace(btn, cur);   // not saved — show what Instagram will really get
          throw e;
        });
      }).catch(function (e) {
        cropFail(e, 'Изрязването не можа да се запази. Проверете връзката и опитайте отново.');
      });
    });
  });

  // ── Scheduling ──────────────────────────────────────────────────────────────
  // The server builds the post from the saved article; nothing but the time is sent.
  // (Task 6 saves the article first, so the newest text and a new article work too.)
  function startSend(btn, spinner, errEl) {
    if (!slug) { errEl.textContent = MSG_NO_SLUG; errEl.style.display = ''; return false; }
    btn.disabled = true;
    spinner.style.display = '';
    errEl.style.display = 'none';
    return true;
  }
  function endSend(platform, btn, spinner) {
    btn.disabled = blocked[platform];
    spinner.style.display = 'none';
  }
  function sendFailed(errEl, e) {
    errEl.textContent = (e && e.userMessage) || MSG_NETWORK;
    errEl.style.display = '';
  }

  function scheduleChannel(channel, scheduled_at, btn, spinner, errEl, badgeId, extra, label) {
    if (!startSend(btn, spinner, errEl)) return;
    var body = Object.assign({ action: 'schedule', scheduled_at: scheduled_at, channel: channel }, extra || {});
    socPost(body).then(function (data) {
      if (!data.ok) {
        errEl.textContent   = data.error;
        errEl.style.display = '';
        return;
      }
      if (channel === 'insta') {
        var noteBox = document.getElementById(extra && extra.post_type === 'story' ? 'igCropStory' : 'igCropPost');
        var note = noteBox && noteBox.querySelector('.ig-crop-sched-note');
        if (note && data.due_at) note.style.display = '';
      }
      label = label || (channel === 'fb' ? 'FB' : 'IG');
      document.getElementById(badgeId).innerHTML = data.due_at
        ? '<span style="color:#b45309;">⏱ ' + label + ': ' + fmtSofia(data.due_at) + '</span>'
        : '<span style="color:#2e7d32;">✓ ' + label + ': Публикувано сега</span>';
      window._showSiteNotice(data.site_notice, errEl);
    }).catch(function (e) { sendFailed(errEl, e); })
      .then(function () { endSend(channel, btn, spinner); });
  }

  function needTime(inputId, errEl) {
    var v = document.getElementById(inputId).value;
    if (!v) { errEl.textContent = 'Изберете дата и час.'; errEl.style.display = ''; }
    return v;
  }

  var fbBtn = document.getElementById('socFbBtn'), fbNowBtn = document.getElementById('socFbNowBtn');
  var fbSpin = document.getElementById('socFbSpinner'), fbErr = document.getElementById('socFbError');
  fbBtn.addEventListener('click', function () {
    var at = needTime('fbScheduleAt', fbErr);
    if (at) scheduleChannel('fb', at, fbBtn, fbSpin, fbErr, 'fbStatusBadge');
  });
  fbNowBtn.addEventListener('click', function () {
    scheduleChannel('fb', 'now', fbNowBtn, fbSpin, fbErr, 'fbStatusBadge');
  });

  // The extra payload + target badge/label for the current Instagram mode.
  function _igConfig() {
    if (igMode === 'story') return { extra: { post_type: 'story' }, badgeId: 'instaStoryStatusBadge', label: 'IG Story' };
    return { extra: { post_type: 'post' }, badgeId: 'instaStatusBadge', label: 'IG' };
  }
  var igBtn = document.getElementById('socInstaBtn'), igNowBtn = document.getElementById('socInstaNowBtn');
  var igSpin = document.getElementById('socInstaSpinner'), igErr = document.getElementById('socInstaError');
  igBtn.addEventListener('click', function () {
    var at = needTime(igMode === 'story' ? 'instaStoryScheduleAt' : 'instaScheduleAt', igErr);
    var cfg = _igConfig();
    if (at) scheduleChannel('insta', at, igBtn, igSpin, igErr, cfg.badgeId, cfg.extra, cfg.label);
  });
  igNowBtn.addEventListener('click', function () {
    var cfg = _igConfig();
    scheduleChannel('insta', 'now', igNowBtn, igSpin, igErr, cfg.badgeId, cfg.extra, cfg.label);
  });

  function liSend(scheduled_at, btn) {
    var spinner = document.getElementById('liScheduleSpinner');
    var errEl   = document.getElementById('liScheduleError');
    if (!startSend(btn, spinner, errEl)) return;
    liPost({ action: 'schedule', scheduled_at: scheduled_at }).then(function (data) {
      if (!data.ok) {
        errEl.textContent   = data.error;
        errEl.style.display = '';
        return;
      }
      document.getElementById('liStatusBadge').innerHTML = data.due_at
        ? '<span style="color:#b45309;">⏱ Планирано за ' + fmtSofia(data.due_at) + ' (Sofia)</span>'
        : '<span style="color:#2e7d32;">✓ Публикувано сега</span>';
      window._showSiteNotice(data.site_notice, errEl);
    }).catch(function (e) { sendFailed(errEl, e); })
      .then(function () { endSend('linkedin', btn, spinner); });
  }
  var liBtn = document.getElementById('liScheduleBtn'), liNowBtn = document.getElementById('liNowBtn');
  liBtn.addEventListener('click', function () {
    var at = needTime('liScheduleAt', document.getElementById('liScheduleError'));
    if (at) liSend(at, liBtn);
  });
  liNowBtn.addEventListener('click', function () { liSend('now', liNowBtn); });

  // ── Start ───────────────────────────────────────────────────────────────────
  window.SocialPanel = { refresh: refresh, isOpen: isOpen };

  var startOpen = false;
  try { startOpen = localStorage.getItem(STORAGE_KEY) === '1'; } catch (e) {}
  if (startOpen) setOpen(true);   // reads the textareas until TinyMCE starts; its SetContent refreshes again
})();

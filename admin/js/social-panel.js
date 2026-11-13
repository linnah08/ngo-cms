// Socials panel beside the article editor (admin/article-edit.php).
//
// The Facebook, Instagram and LinkedIn posts ARE the article text, cut to each
// platform's limit. While the panel is open, the previews are rebuilt by
// /admin/social-preview-ajax.php from the unsaved editor text about a second after
// typing stops. Only the newest reply is shown, so a slow old reply can't overwrite
// a newer preview.
//
// Scheduling a post first saves the article in the background and publishes it
// (author's decision), then schedules it under the address the save returned.
//
// Exposes window.SocialPanel = { refresh(): Promise, isOpen(): boolean,
//   saveThenSchedule(channel: 'fb'|'insta'|'linkedin', body): Promise }.
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
  var MSG_NETWORK  = 'Грешка при свързване.';
  var MSG_BAD_REPLY = 'Нещо се обърка — опитайте отново.';
  var MSG_NOT_SAVED = 'Статията не е запазена, затова публикацията не е планирана: ';
  var MSG_PUBLISHED = 'Статията е запазена и публикувана на сайта.';
  var MSG_COPY_FAIL = 'Копирането не успя — маркирайте текста в прегледа и го копирайте ръчно.';
  var MSG_DRAFT_SCHEDULED = 'Статията има планирани публикации в социалните мрежи. Връщането в чернова не ги отменя'
    + ' — те ще излязат в уречения час, с линк към статия, която не се вижда. Ако не трябва да излизат,'
    + ' изтрийте ги в publish.buffer.com.';

  function csrf() {
    var f = document.querySelector('#articleForm input[name="csrf_token"]');
    return f ? f.value : (window._csrfToken || '');
  }

  // Read a JSON reply. An expired session answers with a redirect to the login page
  // (or 401/403): that is said in plain words. Any other reply that isn't JSON (a
  // server error page) is a plain failure — each caller words that for its own action.
  function readJson(r) {
    var type = r.headers.get('Content-Type') || '';
    if (type.indexOf('application/json') !== -1) return r.json();
    var e = new Error('');
    if (r.redirected || r.status === 401 || r.status === 403) e.userMessage = MSG_SESSION;
    else e.badReply = true;
    throw e;
  }

  // POST JSON and read JSON back.
  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ csrf_token: csrf() }, body))
    }).then(readJson);
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

  var sending = false;   // one save-and-schedule at a time: two saves of a new article would make two articles

  function setBlocked(platform, on) {
    blocked[platform] = on;
    scheduleButtons(platform).forEach(function (b) { if (b) b.disabled = on || sending; });
  }

  // A post needs text — except an Instagram Story, which has no caption.
  function needsText(platform) {
    return platform !== 'insta' || igMode === 'post';
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
      // Facebook has no practical limit, so it shows the length alone.
      count.textContent   = platform === 'fb'
        ? 'Дължина: ' + groupDigits(post.length) + ' знака'
        : 'Дължина: ' + groupDigits(post.length) + ' / ' + groupDigits(post.limit) + ' знака';
      cut.hidden          = !post.cut;
    }
    if (copyBtn) copyBtn.disabled = !text;
    setBlocked(platform, !text && needsText(platform));
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

  var announceTimer = null;
  function announce(msg) {
    statusEl.textContent = '';
    clearTimeout(announceTimer);
    announceTimer = setTimeout(function () { statusEl.textContent = msg; }, 50);
  }
  function clearStatus() {
    clearTimeout(announceTimer);
    statusEl.textContent = '';
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
      clearStatus();
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
        var stale = btn.parentNode.querySelector('.soc-copy-fail');
        if (stale) stale.remove();
        var orig = btn.textContent;
        btn.textContent = '✓ Копирано';
        setTimeout(function () { btn.textContent = orig; }, 1500);
      };
      var failed = function () {
        var note = btn.parentNode.querySelector('.soc-copy-fail');
        if (!note) {
          note = document.createElement('p');
          note.className = 'soc-copy-fail';
          note.setAttribute('role', 'alert');
          note.style.cssText = 'flex-basis:100%;margin:.25rem 0 0;font-size:.82rem;font-weight:600;color:#b91c1c;';
          btn.parentNode.appendChild(note);
        }
        note.textContent = '⚠ ' + MSG_COPY_FAIL;
      };
      var fallback = function () {
        var ta = document.createElement('textarea');
        ta.value = text; ta.setAttribute('readonly', '');
        ta.style.position = 'absolute'; ta.style.left = '-9999px';
        document.body.appendChild(ta); ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) {}
        ta.remove();
        btn.focus();
        if (ok) done(); else failed();
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

  // Crops and shapes are saved straight to the article (they need its address).
  function socPost(body) { return postJson('/admin/social-ajax.php', Object.assign({ slug: slug }, body)); }

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
    setBlocked('insta', !lastText.insta && needsText('insta'));   // a Story needs no text
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

  // ── Draft notices ───────────────────────────────────────────────────────────
  // Scheduling publishes the article, so while it's a draft each platform says so.
  // A draft that already has posts in Buffer gets a lasting warning by the status
  // field: going back to draft doesn't cancel them.
  var form      = document.getElementById('articleForm');
  var statusSel = document.getElementById('status');
  var autoBox   = document.getElementById('scheduled');
  var draftWarn = document.getElementById('socDraftWarn');

  function updateDraftNotices() {
    var draft = statusSel && statusSel.value !== 'published';
    panel.querySelectorAll('.soc-draft-note').forEach(function (p) { p.hidden = !draft; });
    if (!draftWarn) return;
    var warn = draft && panel.dataset.hasScheduled === '1';
    var box = draftWarn.querySelector('p');
    if (!warn) { if (box) box.remove(); return; }
    if (box) return;
    box = document.createElement('p');
    box.style.cssText = 'border:2px solid #b45309;background:#fffbeb;color:#78350f;border-radius:8px;'
      + 'padding:.6rem .85rem;margin:.75rem 0 0;font-weight:600;font-size:.85rem;line-height:1.5;'
      + 'text-transform:none;letter-spacing:normal;';
    box.textContent = '⚠ ' + MSG_DRAFT_SCHEDULED;
    draftWarn.appendChild(box);
  }
  if (statusSel) statusSel.addEventListener('change', updateDraftNotices);

  // ── Saving the article in the background ────────────────────────────────────
  // The same form, to the same handler; _ajax=1 asks for JSON instead of a redirect,
  // publish_now=1 publishes it (and drops any auto-publish date).
  function saveArticle() {
    if (window.tinymce) tinymce.triggerSave();
    var fd = new FormData(form);
    fd.append('_ajax', '1');
    fd.append('publish_now', '1');
    return fetch(form.action, { method: 'POST', credentials: 'same-origin', body: fd }).then(readJson);
  }

  function showWarnings(list) {
    var el = document.getElementById('socSaveWarn');
    if (!el) return;
    list = (list || []).filter(Boolean);
    el.textContent = list.length ? '⚠ ' + list.join(' ') : '';
    el.hidden = !list.length;
  }

  // The save reply becomes the page's state, as the redirect after „Запази“ would do.
  function applySave(saved, startedAt) {
    var wasDraft = statusSel && statusSel.value !== 'published';
    slug = saved.slug;
    panel.dataset.slug = slug;
    var slugIn = document.getElementById('slug'), slugEnIn = document.getElementById('slug_en');
    if (slugIn) slugIn.value = saved.slug;
    if (slugEnIn && saved.slug_en) slugEnIn.value = saved.slug_en;
    // The next save — from here or „Запази“ — edits this article, not a new one.
    form.action = '/admin/article-edit.php?slug=' + encodeURIComponent(slug);
    if (statusSel) {
      statusSel.value = 'published';
      // Not bubbling: the auto-publish switch follows, autosave doesn't keep a new draft.
      statusSel.dispatchEvent(new Event('change'));
    }
    if (autoBox) autoBox.checked = false;
    try {
      var u = new URL(window.location.href);
      u.searchParams.set('slug', slug);
      u.searchParams.delete('_asclear');
      history.replaceState(history.state, '', u.pathname + u.search + u.hash);
    } catch (e) {}
    // Saved: drop the autosave draft (a normal save does it through ?_asclear).
    if (window._articleAutosave) {
      window._articleAutosave.clear(startedAt);
      window._articleAutosave.setKey('article:' + slug);
    }
    showWarnings(saved.warnings);
    return wasDraft && saved.status === 'published';
  }

  // Save (and publish) the article, then schedule the post under the address the
  // save returned — saving can change it (a new title). Nothing but the time and
  // the channel is sent: the server builds the post from the saved article.
  function saveThenSchedule(channel, body) {
    var startedAt = Date.now();
    var published = false;
    return saveArticle().then(function (saved) {
      if (!saved || !saved.ok) {
        var e = new Error(''); e.userMessage = MSG_NOT_SAVED + ((saved && saved.error) || MSG_BAD_REPLY); throw e;
      }
      published = applySave(saved, startedAt);
      var url = channel === 'linkedin' ? '/admin/linkedin-ajax.php' : '/admin/social-ajax.php';
      var req = Object.assign({}, body, { action: 'schedule', slug: saved.slug });
      if (channel !== 'linkedin') req.channel = channel;
      return postJson(url, req);
    }).then(function (data) {
      if (published && data && !data.site_notice) data.site_notice = MSG_PUBLISHED;
      if (data && data.ok) panel.dataset.hasScheduled = '1';
      return data;
    }, function (e) {
      // The article may be saved even if scheduling then failed — say so too.
      if (published) e.published = true;
      throw e;
    });
  }

  // ── Scheduling ──────────────────────────────────────────────────────────────
  function allButtons() {
    return PLATFORMS.reduce(function (all, p) { return all.concat(scheduleButtons(p)); }, []);
  }
  function startSend(btn, spinner, errEl) {
    if (sending) return false;
    sending = true;
    allButtons().forEach(function (b) { if (b) b.disabled = true; });
    spinner.style.display = '';
    errEl.style.display = 'none';
    return true;
  }
  function endSend(spinner) {
    sending = false;
    PLATFORMS.forEach(function (p) { setBlocked(p, blocked[p]); });
    spinner.style.display = 'none';
    updateDraftNotices();
    if (isOpen()) refresh();   // the address may have changed: the link line follows
  }
  function sendFailed(errEl, e) {
    errEl.textContent = (e && e.userMessage) || (e && e.badReply ? MSG_BAD_REPLY : MSG_NETWORK);
    if (e && e.published) errEl.textContent += ' ' + MSG_PUBLISHED;
    errEl.style.display = '';
  }

  function scheduleChannel(channel, scheduled_at, btn, spinner, errEl, badgeId, extra, label) {
    if (!startSend(btn, spinner, errEl)) return;
    var body = Object.assign({ scheduled_at: scheduled_at }, extra || {});
    saveThenSchedule(channel, body).then(function (data) {
      if (!data.ok) {
        errEl.textContent   = data.error;
        errEl.style.display = '';
        window._showSiteNotice(data.site_notice, errEl);
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
      .then(function () { endSend(spinner); });
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
    saveThenSchedule('linkedin', { scheduled_at: scheduled_at }).then(function (data) {
      if (!data.ok) {
        errEl.textContent   = data.error;
        errEl.style.display = '';
        window._showSiteNotice(data.site_notice, errEl);
        return;
      }
      document.getElementById('liStatusBadge').innerHTML = data.due_at
        ? '<span style="color:#b45309;">⏱ Планирано за ' + fmtSofia(data.due_at) + ' (Sofia)</span>'
        : '<span style="color:#2e7d32;">✓ Публикувано сега</span>';
      window._showSiteNotice(data.site_notice, errEl);
    }).catch(function (e) { sendFailed(errEl, e); })
      .then(function () { endSend(spinner); });
  }
  var liBtn = document.getElementById('liScheduleBtn'), liNowBtn = document.getElementById('liNowBtn');
  liBtn.addEventListener('click', function () {
    var at = needTime('liScheduleAt', document.getElementById('liScheduleError'));
    if (at) liSend(at, liBtn);
  });
  liNowBtn.addEventListener('click', function () { liSend('now', liNowBtn); });

  // ── Start ───────────────────────────────────────────────────────────────────
  window.SocialPanel = { refresh: refresh, isOpen: isOpen, saveThenSchedule: saveThenSchedule };

  // LinkedIn needs the English text: blocked until a preview confirms there is some.
  setBlocked('linkedin', true);
  updateDraftNotices();

  var startOpen = false;
  try { startOpen = localStorage.getItem(STORAGE_KEY) === '1'; } catch (e) {}
  if (startOpen) setOpen(true);   // reads the textareas until TinyMCE starts; its SetContent refreshes again
})();

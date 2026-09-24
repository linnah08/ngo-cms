<?php
/**
 * "Имейл до клиента" card — the admin writes to the buyer, optionally starting
 * from a ready-made message. Included by order-view.php and pledge-view.php,
 * right above the email history (order-email-history.php), where the sent
 * message then shows up.
 *
 * Expects:
 *   $oec_order  array  order-shaped row (customer_name, customer_email, order_number, type, lang, …)
 *   $oec_draft  array  optional ['preset','subject','body'] — the admin's text after a failed send,
 *                      so nothing they wrote is lost
 * The page must load TinyMCE in $page_head_extra and handle POST action=send_customer_email
 * with order_email_send() (includes/order_email_composer.php).
 */
$oec_choices   = order_email_choices($oec_order);
$oec_draft     = $oec_draft ?? [];
$oec_lang      = order_email_lang($oec_order);
$oec_recipient = (string)($oec_order['customer_email'] ?? '');
$oec_has_email = filter_var($oec_recipient, FILTER_VALIDATE_EMAIL) !== false;
$oec_js = [];
foreach ($oec_choices as $k => $c) {
    if ($c['available']) $oec_js[$k] = ['subject' => $c['subject'], 'body' => $c['body']];
}
$oec_input = 'width:100%;box-sizing:border-box;padding:.5rem .75rem;border:1px solid #6b7280;border-radius:6px;font-size:.9rem;font-family:inherit;';
$oec_label = 'font-size:.875rem;font-weight:600;display:block;margin-bottom:.35rem;';
?>
<div class="admin-card" id="customer-email" style="padding:1.5rem;margin-bottom:1.5rem;border:1px solid var(--border);border-radius:var(--radius-lg);">
  <h3 style="margin-top:0;font-size:1rem;">Имейл до клиента</h3>
  <?php if (!$oec_has_email): ?>
    <p style="font-size:.85rem;margin:.25rem 0 0;">Няма валиден имейл адрес на клиента, затова не може да му се пише оттук.</p>
  <?php else: ?>
  <p style="font-size:.8rem;color:var(--text-muted);margin:.25rem 0 1rem;">
    До <strong><?= h($oec_recipient) ?></strong> · на <?= $oec_lang === 'en' ? 'английски' : 'български' ?>.
    Отговорът на клиента ще дойде в пощата на сайта.
  </p>
  <form method="POST" id="customerEmailForm" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="send_customer_email">

    <div style="margin-bottom:.75rem;">
      <label for="emailPreset" style="<?= $oec_label ?>">Готов шаблон</label>
      <select id="emailPreset" name="email_preset" aria-describedby="emailPresetHelp" style="<?= $oec_input ?>">
        <option value="">— Избери (по желание) —</option>
        <?php foreach ($oec_choices as $k => $c): ?>
          <option value="<?= h($k) ?>"<?= $c['available'] ? '' : ' disabled' ?><?= ($oec_draft['preset'] ?? '') === $k ? ' selected' : '' ?>><?= h($c['label']) ?><?= $c['available'] ? '' : ' — ' . h($c['note']) ?></option>
        <?php endforeach; ?>
      </select>
      <p id="emailPresetHelp" style="font-size:.75rem;color:var(--text-muted);margin:.3rem 0 0;">
        Попълва темата и текста — можете да ги промените преди изпращане.
        <?php if (admin_is_admin()): ?>Текстовете на шаблоните се променят в <a href="/admin/email-templates.php">Имейл шаблони</a>.<?php endif; ?>
      </p>
    </div>

    <div style="margin-bottom:.75rem;">
      <label for="emailSubject" style="<?= $oec_label ?>">Тема <span aria-hidden="true">*</span><span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;"> (задължително)</span></label>
      <input type="text" id="emailSubject" name="email_subject" maxlength="255" required aria-required="true"
             value="<?= h($oec_draft['subject'] ?? '') ?>" style="<?= $oec_input ?>">
    </div>

    <div style="margin-bottom:.75rem;">
      <label for="adminEmailBody" style="<?= $oec_label ?>">Съобщение <span aria-hidden="true">*</span><span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;"> (задължително)</span></label>
      <textarea id="adminEmailBody" name="email_body" rows="8"
                style="<?= $oec_input ?>resize:vertical;"><?= h($oec_draft['body'] ?? '') ?></textarea>
    </div>

    <div id="customerEmailError" role="alert"
         style="display:none;background:#fdecea;border:1px solid #b42318;color:#8a1c12;border-radius:6px;padding:.6rem .75rem;margin-bottom:.75rem;font-size:.85rem;"></div>

    <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center;min-height:44px;">
      ✉ Изпрати имейл
    </button>
  </form>

  <script>
  (function () {
    var presets   = <?= json_encode($oec_js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var recipient = <?= json_encode($oec_recipient, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var form    = document.getElementById('customerEmailForm');
    var select  = document.getElementById('emailPreset');
    var subject = document.getElementById('emailSubject');
    var bodyTa  = document.getElementById('adminEmailBody');
    var errBox  = document.getElementById('customerEmailError');
    var lastPreset = select.value;
    var lastFilled = { subject: subject.value, body: bodyTa.value };

    if (window.tinymce) {
      tinymce.init(Object.assign({}, window._tinyBase, { selector: '#adminEmailBody', min_height: 220 }));
    }
    function editor() { return window.tinymce ? tinymce.get('adminEmailBody') : null; }
    function getBody() { var ed = editor(); return ed ? ed.getContent() : bodyTa.value; }
    function setBody(html) { var ed = editor(); if (ed) ed.setContent(html); else bodyTa.value = html; }
    function visibleText(html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');   // inert: loads and runs nothing
      return (doc.body.textContent || '').replace(/[\s\u00a0]+/g, '');
    }
    function showError(msg, focusEl) {
      errBox.textContent = msg;
      errBox.style.display = msg ? 'block' : 'none';
      if (focusEl) focusEl.focus();
    }

    function fill(key) {
      var p = presets[key];
      if (!p) return;
      subject.value = p.subject;
      setBody(p.body);
      lastPreset = key;
      lastFilled = { subject: subject.value, body: getBody() };
      showError('');
    }

    select.addEventListener('change', function () {
      var key = select.value;
      if (!presets[key]) { lastPreset = key; return; }
      // Don't silently throw away what the admin has written since the last fill.
      var edited = subject.value !== lastFilled.subject || getBody() !== lastFilled.body;
      var hasText = subject.value.trim() !== '' || visibleText(getBody()) !== '';
      if (!edited || !hasText) { fill(key); return; }
      window._adminConfirm('Написаното досега ще бъде заменено с избрания шаблон. Продължаване?', 'Замени')
        .then(function (ok) {
          if (ok) fill(key); else select.value = lastPreset;
        });
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (window.tinymce) tinymce.triggerSave();
      if (!subject.value.trim()) { showError('Въведете тема на имейла.', subject); return; }
      if (visibleText(bodyTa.value) === '' && bodyTa.value.indexOf('<img') === -1) {
        var ed = editor();
        showError('Въведете съобщение.', ed ? null : bodyTa);
        if (ed) ed.focus();
        return;
      }
      showError('');
      window._adminConfirm('Да се изпрати ли имейлът до ' + recipient + '?', 'Изпрати').then(function (ok) {
        if (ok) form.submit();
      });
    });
  })();
  </script>
  <?php endif; ?>
</div>

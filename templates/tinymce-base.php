<?php
/**
 * The one TinyMCE config every editor on the site starts from (window._tinyBase).
 *
 * Printed in the admin's <head> (admin/includes/admin-header.php) and, for a logged-in
 * admin, in the public pages' admin bar (templates/header.php), where Edit mode opens
 * the rich text blocks in TinyMCE. Change toolbar, plugins or options here only.
 * Uploads post window._csrfToken, which both places set.
 *
 * Also here, because the link dialog uses it: window.omPickPdf(), which asks for a PDF,
 * uploads it (admin/upload-file.php) and resolves with {path, text, size}, and the
 * „Качи PDF“ buttons under section link fields (hs_pdf_button() in includes/home_admin.php).
 */
?>
  <script>
  window.omPickPdf = function() {
    return new Promise(function(resolve, reject) {
      var input = document.createElement('input');
      input.type = 'file';
      input.accept = 'application/pdf,.pdf';
      input.onchange = function() {
        var f = input.files && input.files[0];
        if (!f) return;
        var fd = new FormData();
        fd.append('file', f);
        fd.append('csrf_token', window._csrfToken);
        fetch('/admin/upload-file.php', { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function(r) { return r.json(); })
          .then(function(d) { d && d.ok ? resolve(d) : reject((d && d.message) || 'Файлът не можа да се качи. Опитайте отново.'); })
          .catch(function() { reject('Файлът не можа да се качи. Проверете връзката и опитайте отново.'); });
      };
      input.click();
    });
  };
  document.addEventListener('click', function(e) {
    var btn = e.target.closest && e.target.closest('[data-pdf-upload]');
    if (!btn) return;
    var field  = document.getElementById(btn.getAttribute('data-pdf-upload'));
    var status = btn.parentNode.querySelector('[data-pdf-status]');
    var say = function(text, isError) {
      if (!status) return;
      status.textContent = (isError ? '⚠ ' : '') + text;
      status.style.color = isError ? '#b91c1c' : '';
      status.style.fontWeight = isError ? '600' : '';
    };
    window.omPickPdf().then(function(d) {
      if (field) {
        field.value = d.path;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
      }
      say('✓ Качен: ' + d.text + '. Не забравяйте да запазите.', false);
    }, function(msg) { say(msg, true); });
  });
  window._tinyBase = {
    menubar: false,
    promotion: false,
    branding: false,
    plugins: 'lists link code emoticons anchor image table charmap wordcount autoresize',
    min_height: 120,
    // autoresize sizes the editor to the text as it wraps when the editor first appears.
    // An editor that starts out narrow keeps that tall height after it widens, leaving a
    // long blank stretch to scroll past. Past this height the text scrolls inside the box.
    max_height: 600,
    toolbar: 'bold italic underline strikethrough | fontsize | blocks | bullist numlist | link unlink anchor | image table | charmap emoticons | hr removeformat | code',
    toolbar_mode: 'wrap',
    font_size_formats: '10pt 12pt 14pt 16pt 18pt 24pt 30pt 36pt',
    paste_as_text: false,
    paste_remove_styles_if_webkit: true,
    smart_paste: true,
    link_assume_external_targets: true,
    link_default_target: '_blank',
    relative_urls: false,
    remove_script_host: false,
    images_upload_url: '/admin/upload-product-image.php',
    automatic_uploads: false,
    // 'file' puts an upload button in the link dialog: a PDF, linked with its name and size as the text.
    file_picker_types: 'file image',
    file_picker_callback: function(cb, value, meta) {
      if (meta.filetype === 'file') {
        window.omPickPdf().then(function(d) {
          cb(d.path, { text: d.text, title: d.text });
        }, function(msg) {
          if (window.tinymce && tinymce.activeEditor) tinymce.activeEditor.windowManager.alert(msg);
          else alert(msg);
        });
        return;
      }
      if (meta.filetype !== 'image') return;
      var input = document.createElement('input');
      input.type = 'file';
      input.accept = 'image/*';
      input.onchange = function() {
        var f = input.files && input.files[0];
        if (!f) return;
        (window.OMCrop ? window.OMCrop.open(f) : Promise.resolve(f)).then(function(cropped) {
          if (!cropped) return;
          var fd = new FormData();
          fd.append('image', cropped);
          fd.append('csrf_token', window._csrfToken);
          fetch('/admin/upload-product-image.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
              if (d && d.filename) cb('/assets/images/products/' + d.filename, { title: cropped.name });
              else alert((d && d.error) ? d.error : 'Грешка при качване.');
            })
            .catch(function() { alert('Грешка при качване.'); });
        });
      };
      input.click();
    },
    setup: function(editor) {
      editor.on('change', function() { editor.save(); });
    },
  };
  </script>

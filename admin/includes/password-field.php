<?php
/**
 * A labelled password input with a „Покажи“ / „Скрий“ button, shared by the
 * reset page and „Моят профил“. Styles are inline because these pages render
 * outside the admin layout and admin.css may be stale-cached.
 *
 * $error: message shown under the field (and announced) when it is wrong.
 * $hint:  short help shown under the field.
 */
function admin_password_field(string $id, string $label, string $autocomplete, string $error = '', string $hint = '', bool $autofocus = false): string
{
    $describedby = trim(($hint !== '' ? $id . '-hint ' : '') . ($error !== '' ? $id . '-err' : ''));
    $html  = '<div class="form-group">';
    $html .= '<label for="' . h($id) . '">' . h($label) . '</label>';
    $html .= '<div style="position:relative;">';
    $html .= '<input type="password" id="' . h($id) . '" name="' . h($id) . '" required'
           . ' autocomplete="' . h($autocomplete) . '"'
           . ($autofocus ? ' autofocus' : '')
           . ($describedby !== '' ? ' aria-describedby="' . h($describedby) . '"' : '')
           . ($error !== '' ? ' aria-invalid="true"' : '')
           . ' style="width:100%;box-sizing:border-box;padding-right:5.5rem;' . ($error !== '' ? 'border-color:#c0392b;' : '') . '">';
    $html .= '<button type="button" data-pw-toggle="' . h($id) . '" aria-pressed="false"'
           . ' aria-label="Покажи паролата в полето „' . h($label) . '“"'
           . ' style="position:absolute;right:2px;top:50%;transform:translateY(-50%);min-width:44px;min-height:40px;'
           . 'padding:0 .6rem;background:none;border:none;cursor:pointer;color:inherit;font-size:.85rem;text-decoration:underline;">Покажи</button>';
    $html .= '</div>';
    if ($hint !== '') {
        $html .= '<span id="' . h($id) . '-hint" style="display:block;margin-top:.3rem;font-size:.85rem;color:#4b5563;">' . h($hint) . '</span>';
    }
    if ($error !== '') {
        $html .= '<span id="' . h($id) . '-err" role="alert" style="display:block;margin-top:.3rem;font-size:.9rem;font-weight:600;color:#c0392b;">✖ ' . h($error) . '</span>';
    }
    $html .= '</div>';
    return $html;
}

/** The script behind the „Покажи“ buttons. Echo once per page, after the fields. */
function admin_password_field_script(): string
{
    return <<<'JS'
<script>
document.querySelectorAll('[data-pw-toggle]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var input = document.getElementById(btn.getAttribute('data-pw-toggle'));
    var show  = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? 'Скрий' : 'Покажи';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    btn.setAttribute('aria-label', btn.getAttribute('aria-label').replace(show ? 'Покажи' : 'Скрий', show ? 'Скрий' : 'Покажи'));
  });
});
</script>
JS;
}

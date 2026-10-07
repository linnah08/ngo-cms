<?php
/**
 * Actions on the pages admins create (Admin → Страници): create, publish,
 * hide (back to draft) and delete. POST only, Admin role, CSRF-checked.
 *
 * Forms get a redirect with a flash message. The menu editor creates pages
 * without leaving the menu: it sends format=json and gets
 *   {ok: true, page: {...}, translated: bool}  or  {ok: false, error: "..."}.
 *
 * The page content (sections) and its title/address settings are edited in
 * admin/page-edit.php.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/translator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/menus.php';

admin_require_login();
admin_require_admin();

$json = ($_POST['format'] ?? '') === 'json';

/** Answer and stop: JSON for the menu editor, else a flash message and a redirect. */
function cp_done(bool $json, bool $ok, string $message, string $to, array $extra = []): never
{
    if ($json) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => $ok] + ($ok ? $extra : ['error' => $message]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    flash_set($ok ? 'success' : 'error', $message);
    header('Location: ' . $to);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /admin/pages.php'); exit; }
if (!csrf_verify()) {
    if ($json) { http_response_code(400); }
    cp_done($json, false, 'Действието не е изпълнено — страницата е стояла отворена твърде дълго. Презаредете я и опитайте отново.', '/admin/pages.php');
}

$post   = fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
$action = $post('action');
$id     = $post('id');

/** What the menu editor needs to know about a page. */
function cp_public(array $p): array
{
    return ['id' => $p['id'], 'status' => $p['status'], 'title_bg' => $p['title_bg'], 'title_en' => $p['title_en'],
            'slug_bg' => $p['slug_bg'], 'slug_en' => $p['slug_en'],
            'url_bg' => cpage_url($p, 'bg'), 'url_en' => cpage_url($p, 'en'),
            'edit_url' => '/admin/page-edit.php?id=' . $p['id']];
}

switch ($action) {
    case 'create':
        $title_bg = cpage_clean_title($post('title_bg'));
        if ($title_bg === '') cp_done($json, false, 'Напишете заглавие на новата страница.', '/admin/pages.php');
        // The English title: typed by the admin (menu row), else translated, else the
        // Bulgarian one for now — the page settings then say it is not translated.
        $title_en   = cpage_clean_title($post('title_en'));
        $translated = $title_en !== '';
        if ($title_en === '' && deepl_is_configured()) {
            $err = null;
            $t   = cpage_clean_title(deepl_translate($title_bg, 'EN-GB', false, $err));
            if ($err === null && $t !== '') { $title_en = $t; $translated = true; }
        }
        if ($title_en === '') $title_en = $title_bg;
        // A menu row that already points at /<name>/ keeps that address if it is free.
        $want = strtolower(trim($post('slug_bg'), " /"));
        $r = cpage_create($title_bg, $title_en, preg_match(CPAGE_SLUG_RE, $want) ? $want : '');
        if (!$r['ok']) cp_done($json, false, $r['error'], '/admin/pages.php');
        $p = $r['page'];
        cp_done($json, true,
            "Страницата \u{201E}{$p['title_bg']}\u{201C} е създадена като чернова — посетителите още не я виждат. Добавете съдържание и я публикувайте.",
            '/admin/page-edit.php?id=' . $p['id'], ['page' => cp_public($p), 'translated' => $translated]);

    case 'publish':
    case 'unpublish':
        $page = cpage_get($id);
        if ($page === null) cp_done($json, false, 'Страницата не е намерена — може би е изтрита междувременно.', '/admin/pages.php');
        $r = cpage_set_status($id, $action === 'publish' ? 'published' : 'draft');
        $back = match ($post('back')) {
            'view'  => $r['ok'] && $action === 'publish' ? cpage_url($page, 'bg') : '/admin/page-edit.php?id=' . $id,
            'edit'  => '/admin/page-edit.php?id=' . $id . '&focus=' . rawurlencode('publish:page'),   // focus back on the button
            default => '/admin/pages.php#cp-' . $id,
        };
        if (!$r['ok']) cp_done($json, false, $r['error'], $back);
        cp_done($json, true, $action === 'publish'
            ? "\u{201E}{$page['title_bg']}\u{201C} е публикувана и вече се вижда на сайта."
            : "\u{201E}{$page['title_bg']}\u{201C} е скрита от сайта — отново е чернова.", $back, ['page' => cp_public($r['page'])]);

    case 'delete':
        $page = cpage_get($id);
        if ($page === null) cp_done($json, false, 'Страницата не е намерена — може би вече е изтрита.', '/admin/pages.php');
        $menus = cpage_menus_using($page);
        if (!cpage_delete($id)) cp_done($json, false, 'Страницата не можа да се изтрие. Опитайте отново след малко.', '/admin/pages.php');
        cp_done($json, true, "Страницата \u{201E}{$page['title_bg']}\u{201C} е изтрита, заедно с нейните снимки."
            . ($menus ? ' ' . (count($menus) > 1 ? 'Връзките' : 'Връзката') . ' към нея в менюто — ' . implode(', ', $menus)
                . ' — вече не се показва на сайта. Махнете ' . (count($menus) > 1 ? 'ги' : 'я') . ' или насочете другаде от „Менюта“.' : ''),
            '/admin/pages.php');
}

http_response_code(400);
cp_done($json, false, 'Непознато действие.', '/admin/pages.php');

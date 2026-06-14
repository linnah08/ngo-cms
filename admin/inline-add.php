<?php
// admin/inline-add.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
header('Content-Type: application/json');

function add_json(array $d): never { echo json_encode($d); exit; }

admin_require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') add_json(['ok' => false, 'error' => 'method']);
if (!csrf_verify()) add_json(['ok' => false, 'error' => 'csrf']);

$type = trim($_POST['type'] ?? '');
$allowed_types = ['team', 'partner', 'impact', 'centre', 'faq'];
if (!in_array($type, $allowed_types, true)) add_json(['ok' => false, 'error' => 'unknown type']);

// Helper: upload an image file from $_FILES
function _inline_add_upload(string $field, string $dir): string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return '';
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($_FILES[$field]['tmp_name']);
    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) return '';
    $ext  = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    $allowed_ext = ['jpg','jpeg','png','webp'];
    if (!in_array($ext, $allowed_ext, true)) return '';
    $dest_dir = $_SERVER['DOCUMENT_ROOT'] . $dir;
    if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dest_dir . '/' . $name)) return '';
    return $dir . '/' . $name;
}

switch ($type) {
    case 'team':
        $photo = _inline_add_upload('photo', '/assets/images/pages');
        $pages = load_json(CONTENT_PATH . '/pages.json');
        if (!isset($pages['about']['team'])) $pages['about']['team'] = [];
        $pages['about']['team'][] = [
            'name'    => trim(strip_tags($_POST['name_bg'] ?? '')),
            'name_en' => trim(strip_tags($_POST['name_en'] ?? '')),
            'role'    => trim(strip_tags($_POST['role_bg'] ?? '')),
            'role_en' => trim(strip_tags($_POST['role_en'] ?? '')),
            'photo'   => $photo,
        ];
        save_json(CONTENT_PATH . '/pages.json', $pages);
        add_json(['ok' => true]);

    case 'partner':
        $logo = _inline_add_upload('logo', '/assets/images/pages');
        $partners = load_json(PARTNERS_FILE);
        $partners[] = [
            'name'   => trim(strip_tags($_POST['name'] ?? '')),
            'url'    => filter_var(trim($_POST['url'] ?? ''), FILTER_VALIDATE_URL) ?: '',
            'logo'   => $logo,
            'active' => true,
        ];
        save_json(PARTNERS_FILE, $partners);
        add_json(['ok' => true]);

    case 'impact':
        // Impact is stored in content/impact.json with keys: number, label_bg, label_en
        $items = load_json(IMPACT_FILE);
        $items[] = [
            'number'   => trim(strip_tags($_POST['number']   ?? '')),
            'label_bg' => trim(strip_tags($_POST['label_bg'] ?? '')),
            'label_en' => trim(strip_tags($_POST['label_en'] ?? '')),
        ];
        save_json(IMPACT_FILE, $items);
        add_json(['ok' => true]);

    case 'centre':
        // Centres are stored in content/centres.json with keys: name_bg, description_bg, name_en, description_en, image
        $img = _inline_add_upload('image', '/assets/images/pages');
        $centres = load_json(CENTRES_FILE);
        $centres[] = [
            'name_bg'        => trim(strip_tags($_POST['name_bg']        ?? '')),
            'name_en'        => trim(strip_tags($_POST['name_en']        ?? '')),
            'description_bg' => trim(strip_tags($_POST['description_bg'] ?? '')),
            'description_en' => trim(strip_tags($_POST['description_en'] ?? '')),
            'image'          => $img,
            'active'         => true,
        ];
        save_json(CENTRES_FILE, $centres);
        add_json(['ok' => true]);

    case 'faq':
        // FAQ is stored in the DB `campaign_faq` setting as {q, a, q_en, a_en}.
        $safe_tags = '<b><strong><em><i><a><ul><ol><li><br><p><h2><h3>';
        $faq = json_decode(setting_get('campaign_faq', '[]'), true) ?: [];
        $faq[] = [
            'q'    => trim(strip_tags($_POST['q_bg'] ?? '')),
            'a'    => trim(strip_tags($_POST['a_bg'] ?? '', $safe_tags)),
            'q_en' => trim(strip_tags($_POST['q_en'] ?? '')),
            'a_en' => trim(strip_tags($_POST['a_en'] ?? '', $safe_tags)),
        ];
        setting_set('campaign_faq', json_encode($faq, JSON_UNESCAPED_UNICODE));
        add_json(['ok' => true]);
}

<?php
// admin/inline-remove.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
header('Content-Type: application/json');

function rm_json(array $d): never { echo json_encode($d); exit; }

admin_require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') rm_json(['ok' => false, 'error' => 'method']);

$raw  = $GLOBALS['_om_raw_input'] ?? file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];

if (!hash_equals(csrf_token(), $body['csrf_token'] ?? '')) rm_json(['ok' => false, 'error' => 'csrf']);

$type    = $body['type'] ?? '';
$id      = $body['id']   ?? '';

$allowed_types = ['team', 'partner', 'impact', 'centre', 'article', 'product', 'faq'];
if (!in_array($type, $allowed_types, true)) rm_json(['ok' => false, 'error' => 'unknown type']);

switch ($type) {
    case 'team':
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $idx = (int)$id;
        if (isset($pages['about']['team'][$idx])) {
            array_splice($pages['about']['team'], $idx, 1);
            save_json(CONTENT_PATH . '/pages.json', $pages);
        }
        break;

    case 'partner':
        $partners = load_json(PARTNERS_FILE);
        $idx = (int)$id;
        if (isset($partners[$idx])) {
            array_splice($partners, $idx, 1);
            save_json(PARTNERS_FILE, $partners);
        }
        break;

    case 'impact':
        // Impact is stored in content/impact.json
        $items = load_json(IMPACT_FILE);
        $idx = (int)$id;
        if (isset($items[$idx])) {
            array_splice($items, $idx, 1);
            save_json(IMPACT_FILE, $items);
        }
        break;

    case 'centre':
        // Centres are stored in content/centres.json
        $centres = load_json(CENTRES_FILE);
        $idx = (int)$id;
        if (isset($centres[$idx])) {
            array_splice($centres, $idx, 1);
            save_json(CENTRES_FILE, $centres);
        }
        break;

    case 'article':
        $slug = basename(str_replace(['..', "\0"], '', (string)$id));
        foreach (['bg', 'en'] as $lang) {
            $path = $_SERVER['DOCUMENT_ROOT'] . "/content/articles/{$lang}/{$slug}.json";
            if (file_exists($path)) unlink($path);
        }
        break;

    case 'faq':
        // FAQ rows live in the DB `campaign_faq` setting.
        $faq = json_decode(setting_get('campaign_faq', '[]'), true) ?: [];
        $idx = (int)$id;
        if (isset($faq[$idx])) {
            array_splice($faq, $idx, 1);
            setting_set('campaign_faq', json_encode($faq, JSON_UNESCAPED_UNICODE));
        }
        break;

    case 'product':
        $pid = (int)$id;
        if ($pid > 0) {
            $pdo = get_pdo();
            $pdo->prepare('UPDATE products SET active = 0 WHERE id = ?')->execute([$pid]);
        }
        break;
}

rm_json(['ok' => true]);

<?php
/**
 * /tickets/ — the old single ticket page, from before events had their own
 * module. Kept so links already shared keep working: it sends the visitor to
 * the event on sale when there is exactly one, otherwise to the events list.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/events.php';
start_session();

// Module switched off in Admin → Модули — the page does not exist (site's 404).
module_public_guard('events');

$pdo = get_pdo();
events_adopt_legacy($pdo);
$on_sale = events_on_sale($pdo);
header('Location: ' . (count($on_sale) === 1 ? event_url($on_sale[0], 'bg') : events_path('list', 'bg')), true, 302);
exit;

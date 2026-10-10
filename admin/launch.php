<?php
/**
 * „Пусни сайта“ — opens a new site to visitors (includes/launch.php). POST from
 * the go-live checklist on the dashboard; refused while a required item is open.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/launch.php';   // also for a site that kept an older config.php

admin_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(400);
    exit('Страницата беше отворена твърде дълго. Върнете се и опитайте отново.');
}

if (site_launched()) {
    header('Location: /admin/', true, 303);
    exit;
}

$open = launch_required_open();
if ($open > 0) {
    flash_set('error', 'Сайтът още не може да бъде пуснат. ' . launch_tasks_left($open));
} elseif (org_save_overrides(['site_launched' => '1'])) {
    flash_set('success', 'Сайтът е отворен за посетители.');
} else {
    flash_set('error', 'Не успяхме да запишем промяната. Опитайте отново след малко.');
}
header('Location: /admin/', true, 303);
exit;

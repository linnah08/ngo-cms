<?php
// За нас: a built-in page built from blocks (includes/about.php), edited in Admin → Страници → За нас.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/about.php';
$lang     = 'bg';
$_bp_page = about_page();
$page_title = cpage_title($_bp_page, $lang);
// Search-engine description; a site can reword it in strings.site.json ({name} = the organisation).
$page_description = about_has_team($_bp_page)
    ? t_or('about.page_description_team', 'Научете повече за {name}, нашия екип и нашата история.', 'Learn more about {name}, our team and our story.', 'bg', ['name' => SITE_NAME_BG])
    : t_or('about.page_description', 'Научете повече за {name} и нашата история.', 'Learn more about {name} and our story.', 'bg', ['name' => SITE_NAME_BG]);
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';

$_bp_draft = false;
require $_SERVER['DOCUMENT_ROOT'] . '/templates/block-page.php';

require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php';

<?php
// Always resolve to the project root regardless of how the vhost sets DOCUMENT_ROOT.
// One level deeper than admin/pin-docroot.php, so two dirname() calls.
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);

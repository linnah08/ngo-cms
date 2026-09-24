<?php
// A message an admin writes to the buyer from the order or pledge page ("Имейл до клиента").
// Variables: $order (array), $message_html (string — HTML written by a logged-in admin in TinyMCE)
// render_email() wraps it in the site's email layout.
echo $message_html;

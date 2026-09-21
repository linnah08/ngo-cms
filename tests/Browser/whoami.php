<?php
/**
 * Test-only probe: which checkout is the dev server on this port actually
 * serving?
 *
 * playwright.config.js reuses an already-running server on its port instead of
 * starting one. That is a convenience until a server from a *different*
 * checkout is listening there — then the whole suite silently runs against the
 * wrong site and reports failures that have nothing to do with the code under
 * test. tests/Browser/global-setup.js fetches this file and refuses to run when
 * the answer is not this repository.
 *
 * Never deployed: the release rsync excludes tests/ (see .github/workflows).
 * Answers loopback only, so even if it were deployed it would not hand a remote
 * caller the server's filesystem layout.
 */
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo dirname(__DIR__, 2);

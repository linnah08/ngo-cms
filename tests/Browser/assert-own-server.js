// Refuse to run the browser suite against somebody else's checkout.
//
// playwright.config.js sets reuseExistingServer, so a `php -S` left running on
// the configured port is adopted as-is. That is a convenience until the server
// there belongs to a *different* checkout — this repo and its forks all default
// to the same port — because then every test runs against the wrong site and
// the failures look like real bugs in the code under test. It happened once: a
// leftover server from a fork made the checkout spec fail on a required consent
// field this repo does not even have, which read as a broken payment hand-off.
//
// tests/Browser/whoami.php answers with the root it is being served from. If a
// server is listening and answers with anything else, stop with an error naming
// both paths rather than producing misleading failures.

const path = require('path');

const REPO_ROOT = path.resolve(__dirname, '..', '..');

/** @param {string} base Base URL the suite will drive. */
module.exports = async function assertOwnServer(base) {
  let res;
  try {
    res = await fetch(new URL('/tests/Browser/whoami.php', base), {
      signal: AbortSignal.timeout(3000),
    });
  } catch {
    return;   // nothing listening — the normal case; webServer starts our own
  }

  // Something IS listening. From here on, anything other than this checkout
  // answering is a refusal: a 404 means a live server that does not have the
  // probe, which is precisely the foreign-checkout case this exists to catch.
  const served = res.ok ? (await res.text()).trim() : '';
  if (served === REPO_ROOT) return;

  const port = new URL(base).port || '80';
  const whose = served !== ''
    ? `  it is serving : ${served}\n`
    : `  it is serving : unknown — it has no tests/Browser/whoami.php,\n` +
      `                  so it is another project or an older checkout\n`;

  throw new Error(
    `\n\nA dev server that is not this checkout is already on ${base}.\n\n` +
    whose +
    `  this repo is  : ${REPO_ROOT}\n\n` +
    `Every test would run against that other site and fail for reasons that\n` +
    `have nothing to do with this code. Stop that server and run again:\n\n` +
    `  lsof -nP -iTCP:${port} -sTCP:LISTEN\n\n`
  );
};

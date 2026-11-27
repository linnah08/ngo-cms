#!/usr/bin/env bash
# Run the PHPUnit suite as a site with EVERY optional module switched off.
#
# ngo-cms's own site.config.php switches every module on, so a test that only
# works with its module on passes here and fails on an NGO that switched it
# off (lafetki: Дарения and Кампания). This run catches that before a release.
#
# The module list is read from modules_registry(), so a new module is covered
# without touching this script. Tests of the on/off switching itself sit in the
# `module-switches` group and are left out: they set the switches themselves,
# which NGO_TEST_MODULES_OFF would override.
#
# Usage: tests/modules-off.sh [extra phpunit args]
# The pre-push hook runs it when a release tag (v*) is pushed.
set -euo pipefail
cd "$(dirname "$0")/.."

modules=$(php -r 'require "includes/modules.php"; echo implode(",", array_keys(modules_registry()));')
if [ -z "$modules" ]; then
  echo "Could not read the module list from includes/modules.php" >&2
  exit 1
fi

echo "Running the tests with every module off: $modules"
NGO_TEST_MODULES_OFF="$modules" php vendor/bin/phpunit --exclude-group module-switches "$@"

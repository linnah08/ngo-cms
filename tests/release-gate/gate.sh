#!/usr/bin/env bash
# Release gate: install the previous published release, update it to this one
# with its own updater, and check the site still loads — once as a fresh
# install and once as a fork that keeps its own config.php.
#
#   tests/release-gate/gate.sh [--prev vX.Y.Z|auto] [--new-zip FILE --version X.Y.Z] [--keep]
#
# Without --new-zip, the release ZIP is built from this working tree (tracked
# files as they are on disk, production vendor/), exactly as the release
# workflow builds it. --prev auto (the default) picks the newest published
# release older than --version.
#
# Needs: php (zip, pdo_mysql), composer (only to build), gh (to download the
# previous release), and a MySQL/MariaDB server the GATE_DB_HOST / GATE_DB_USER
# / GATE_DB_PASS env vars point at (defaults 127.0.0.1 / root / empty), with
# rights to create and drop databases.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$HERE/../.." && pwd)"
GH_REPO="${GATE_GH_REPO:-linnah08/ngo-cms}"

PREV="auto"
NEW_ZIP=""
VERSION=""
KEEP=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --prev)     PREV="$2"; shift 2 ;;
    --new-zip)  NEW_ZIP="$2"; shift 2 ;;
    --version)  VERSION="${2#v}"; shift 2 ;;
    --keep)     KEEP="--keep"; shift ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done

WORK="$(mktemp -d "${TMPDIR:-/tmp}/ngo-release-gate.XXXXXX")"
cleanup() { [[ -n "$KEEP" ]] || rm -rf "$WORK"; }
trap cleanup EXIT

# ── The previous release ─────────────────────────────────────────────────────
if [[ "$PREV" == "auto" ]]; then
  CUR="${VERSION:-99999.0.0}"
  PREV="$(gh release list -R "$GH_REPO" --exclude-drafts --exclude-pre-releases --limit 100 --json tagName --jq '.[].tagName' \
    | sed 's/^v//' \
    | php -r '$cur = $argv[1]; $best = null;
              foreach (file("php://stdin", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $v) {
                  if (version_compare($v, $cur, "<") && ($best === null || version_compare($v, $best, ">"))) $best = $v;
              }
              echo $best ?? "";' "$CUR")"
  if [[ -z "$PREV" ]]; then
    echo "No earlier published release to update from — nothing to gate." >&2
    exit 0
  fi
  PREV="v$PREV"
fi
echo "Previous release: $PREV"
mkdir -p "$WORK/prev"
gh release download "$PREV" -R "$GH_REPO" --pattern '*.zip' --dir "$WORK/prev"
PREV_ZIP="$(ls "$WORK"/prev/*.zip | head -n 1)"

# ── The new release ──────────────────────────────────────────────────────────
if [[ -z "$NEW_ZIP" ]]; then
  # A version above anything published, unless one was given: the updater
  # only applies a release that is newer than the site.
  VERSION="${VERSION:-99.0.0}"
  echo "Building the release ZIP from the working tree as $VERSION…"
  mkdir -p "$WORK/build"
  # Tracked files only, as they are on disk — never this checkout's own
  # config files, logs or dev vendor/.
  (cd "$REPO_ROOT" && git ls-files -z | tar --null -T - -cf -) | (cd "$WORK/build" && tar -xf -)
  (cd "$WORK/build" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)
  echo "$VERSION" > "$WORK/build/VERSION"
  (cd "$WORK/build" && bash "$HERE/build-zip.sh" "$WORK/new.zip")
  NEW_ZIP="$WORK/new.zip"
fi
if [[ -z "$VERSION" ]]; then
  echo "--version is required with --new-zip" >&2
  exit 2
fi

php "$HERE/gate.php" --prev-zip="$PREV_ZIP" --new-zip="$NEW_ZIP" --new-version="$VERSION" \
  --work="$WORK/sites" $KEEP

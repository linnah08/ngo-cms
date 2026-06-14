# Prod → Local DB Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Weekly automated sync of the full production MySQL database to local dev so tests run against real data.

**Architecture:** A bash script (`scripts/sync-from-prod.sh`) backs up local, streams a full prod `mysqldump` over SSH, imports it, then runs `php migrate.php`. A bash `trap` on `EXIT` auto-restores from backup if anything fails. A macOS launchd plist fires the script every Friday at noon.

**Tech Stack:** bash, mysqldump, mysql, SSH (`~/.ssh/oddminds`), PHP (for reading db.config.php credentials), macOS launchd.

---

## File map

| File | Action | Purpose |
|---|---|---|
| `db.config.php` | Modify (local only, gitignored) | Update `SETTINGS_ENCRYPTION_KEY` to match prod |
| `scripts/sync-from-prod.sh` | Create | The sync script |
| `~/Library/LaunchAgents/org.oddminds.sync-from-prod.plist` | Create (not in repo) | Weekly Friday 12:00 schedule |

---

## Task 1: Update local encryption key

**Files:**
- Modify: `db.config.php` (local only — gitignored, do not commit)

- [ ] **Step 1: Open db.config.php and update the key**

  Change the `SETTINGS_ENCRYPTION_KEY` line from the current local value to the prod value:

  ```php
  define('SETTINGS_ENCRYPTION_KEY', '37ccb2d113c25d2a25b2f689e32b96bb272aa9accaee1ab4fd4631ed6d632b26');
  ```

- [ ] **Step 2: Verify the local settings table still decrypts**

  ```bash
  php -r "
  require 'db.config.php';
  require 'includes/settings.php';
  \$v = setting_get('econt_user', 'NOT_SET');
  echo 'econt_user=' . \$v . PHP_EOL;
  "
  ```

  After this step the value will likely be `NOT_SET` (local DB still has old blobs). That's fine — it will be correct once the first sync runs. The important thing is that no PHP error is thrown.

---

## Task 2: Create the sync script

**Files:**
- Create: `scripts/sync-from-prod.sh`

- [ ] **Step 1: Create the scripts directory and the file**

  ```bash
  mkdir -p scripts
  touch scripts/sync-from-prod.sh
  chmod +x scripts/sync-from-prod.sh
  ```

- [ ] **Step 2: Write the script**

  Write the following to `scripts/sync-from-prod.sh` exactly:

  ```bash
  #!/usr/bin/env bash
  # sync-from-prod.sh — streams prod DB to local, auto-restores on failure.
  set -euo pipefail

  # ── Config ────────────────────────────────────────────────────────────────────
  REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  SCRIPTS_DIR="$REPO_ROOT/scripts"
  LOG_FILE="$SCRIPTS_DIR/sync.log"
  BACKUP_FILE="$SCRIPTS_DIR/local-backup-$(date +%Y%m%d).sql.gz"

  PROD_SSH_KEY="$HOME/.ssh/oddminds"
  PROD_SSH_USER="detelinavasileva"
  PROD_SSH_HOST="89.252.247.33"
  # Path to db.config.php on the prod server — update if different.
  PROD_DB_CONFIG="/home/detelinavasileva/public_html/oddminds.org/db.config.php"

  # ── Read local DB credentials via PHP ────────────────────────────────────────
  _php_const() {
      php -r "require '$REPO_ROOT/db.config.php'; echo $1;"
  }
  LOCAL_DB_HOST=$(_php_const DB_HOST)
  LOCAL_DB_NAME=$(_php_const DB_NAME)
  LOCAL_DB_USER=$(_php_const DB_USER)
  LOCAL_DB_PASS=$(_php_const DB_PASS)

  # ── Failure trap — auto-restores backup if SYNC_OK never set ─────────────────
  SYNC_OK=false

  _cleanup() {
      if [ "$SYNC_OK" = false ]; then
          if [ -f "$BACKUP_FILE" ]; then
              echo ""
              echo "ERROR: sync failed — restoring local backup from $BACKUP_FILE ..."
              MYSQL_PWD="$LOCAL_DB_PASS" mysql \
                  -h"$LOCAL_DB_HOST" -u"$LOCAL_DB_USER" \
                  -e "DROP DATABASE IF EXISTS \`$LOCAL_DB_NAME\`;
                      CREATE DATABASE \`$LOCAL_DB_NAME\`
                          CHARACTER SET utf8mb4
                          COLLATE utf8mb4_unicode_ci;"
              gunzip -c "$BACKUP_FILE" \
                  | MYSQL_PWD="$LOCAL_DB_PASS" mysql \
                        -h"$LOCAL_DB_HOST" -u"$LOCAL_DB_USER" "$LOCAL_DB_NAME"
              echo "Restored. Local DB is back to its pre-sync state."
          else
              echo "ERROR: sync failed before backup was created — local DB may be empty."
              echo "Import prod dump manually or restore from a previous backup."
          fi
      fi
  }

  trap _cleanup EXIT

  # ── Step 1: Backup local DB ───────────────────────────────────────────────────
  echo "[1/4] Backing up local DB → $BACKUP_FILE"
  MYSQL_PWD="$LOCAL_DB_PASS" mysqldump \
      --single-transaction \
      -h"$LOCAL_DB_HOST" -u"$LOCAL_DB_USER" "$LOCAL_DB_NAME" \
      | gzip > "$BACKUP_FILE"

  # ── Step 2: Drop + recreate local DB, stream prod dump ───────────────────────
  echo "[2/4] Dropping and recreating local DB..."
  MYSQL_PWD="$LOCAL_DB_PASS" mysql \
      -h"$LOCAL_DB_HOST" -u"$LOCAL_DB_USER" \
      -e "DROP DATABASE IF EXISTS \`$LOCAL_DB_NAME\`;
          CREATE DATABASE \`$LOCAL_DB_NAME\`
              CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci;"

  echo "[2/4] Streaming prod dump over SSH..."
  ssh -i "$PROD_SSH_KEY" "$PROD_SSH_USER@$PROD_SSH_HOST" \
      "php -r '
          require \"$PROD_DB_CONFIG\";
          putenv(\"MYSQL_PWD=\" . DB_PASS);
          passthru(
              \"mysqldump --single-transaction --default-character-set=utf8mb4\"
              . \" -h\" . escapeshellarg(DB_HOST)
              . \" -u\" . escapeshellarg(DB_USER)
              . \" \"   . escapeshellarg(DB_NAME)
          );
      '" \
      | MYSQL_PWD="$LOCAL_DB_PASS" mysql \
            -h"$LOCAL_DB_HOST" -u"$LOCAL_DB_USER" "$LOCAL_DB_NAME"

  # ── Step 3: Run local migrations ─────────────────────────────────────────────
  echo "[3/4] Running migrations..."
  php "$REPO_ROOT/migrate.php"

  # ── Step 4: Mark success and rotate backups ───────────────────────────────────
  SYNC_OK=true
  echo "[4/4] Sync complete."

  # Keep last 2 backups; delete older ones.
  # shellcheck disable=SC2012
  ls -t "$SCRIPTS_DIR"/local-backup-*.sql.gz 2>/dev/null \
      | tail -n +3 \
      | xargs rm -f

  echo "Done. Backups kept:"
  ls -lh "$SCRIPTS_DIR"/local-backup-*.sql.gz 2>/dev/null || true
  ```

- [ ] **Step 3: Confirm the file is executable**

  ```bash
  ls -l scripts/sync-from-prod.sh
  ```

  Expected: the permissions field starts with `-rwxr-xr-x` (or similar with `x` bits set).

- [ ] **Step 4: Gitignore generated files**

  Add to `.gitignore` (append at the end):

  ```
  # prod sync artifacts
  scripts/sync.log
  scripts/local-backup-*.sql.gz
  ```

- [ ] **Step 5: Commit**

  ```bash
  git add scripts/sync-from-prod.sh .gitignore
  git commit -m "feat: add prod→local DB sync script"
  ```

---

## Task 3: Verify the prod path and do a test run

**Files:**
- None modified

- [ ] **Step 1: Confirm the prod db.config.php path**

  ```bash
  ssh -i ~/.ssh/oddminds detelinavasileva@89.252.247.33 \
      "find ~/public_html -name 'db.config.php' 2>/dev/null"
  ```

  If the path printed differs from `/home/detelinavasileva/public_html/oddminds.org/db.config.php`, update `PROD_DB_CONFIG` in `scripts/sync-from-prod.sh` to match, then re-commit.

- [ ] **Step 2: Run the sync manually**

  ```bash
  bash scripts/sync-from-prod.sh 2>&1 | tee scripts/sync.log
  ```

  Expected output (lines appear in order):
  ```
  [1/4] Backing up local DB → .../scripts/local-backup-YYYYMMDD.sql.gz
  [2/4] Dropping and recreating local DB...
  [2/4] Streaming prod dump over SSH...
  [3/4] Running migrations...
  [4/4] Sync complete.
  Done. Backups kept:
  -rw-r--r--  1 ...  local-backup-YYYYMMDD.sql.gz
  ```

- [ ] **Step 3: Verify courier credentials decrypted correctly**

  ```bash
  php -r "
  require 'db.config.php';
  require 'includes/settings.php';
  echo 'econt_user=' . setting_get('econt_user', 'NOT_SET') . PHP_EOL;
  echo 'speedy_user=' . setting_get('speedy_user', 'NOT_SET') . PHP_EOL;
  echo 'boxnow_client_id=' . setting_get('boxnow_client_id', 'NOT_SET') . PHP_EOL;
  "
  ```

  Expected: non-empty values (not `NOT_SET`) for the couriers configured in prod.

- [ ] **Step 4: Run the courier test suite**

  ```bash
  php vendor/bin/phpunit --group econt --group speedy --group boxnow -v 2>&1 | tail -30
  ```

  Expected: tests pass (or are skipped for unconfigured couriers — not failing due to auth errors).

---

## Task 4: Create and install the launchd plist

**Files:**
- Create: `~/Library/LaunchAgents/org.oddminds.sync-from-prod.plist` (outside repo — not committed)

- [ ] **Step 1: Create the plist**

  Create `~/Library/LaunchAgents/org.oddminds.sync-from-prod.plist` with this exact content (no changes needed):

  ```xml
  <?xml version="1.0" encoding="UTF-8"?>
  <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN"
    "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
  <plist version="1.0">
  <dict>
      <key>Label</key>
      <string>org.oddminds.sync-from-prod</string>

      <key>ProgramArguments</key>
      <array>
          <string>/bin/bash</string>
          <string>/Users/detelinavasileva/Code/oddminds/scripts/sync-from-prod.sh</string>
      </array>

      <!-- Friday (weekday 5) at 12:00 local time.
           If the machine is asleep at noon, launchd runs the job at next wake. -->
      <key>StartCalendarInterval</key>
      <dict>
          <key>Weekday</key>
          <integer>5</integer>
          <key>Hour</key>
          <integer>12</integer>
          <key>Minute</key>
          <integer>0</integer>
      </dict>

      <key>StandardOutPath</key>
      <string>/Users/detelinavasileva/Code/oddminds/scripts/sync.log</string>
      <key>StandardErrorPath</key>
      <string>/Users/detelinavasileva/Code/oddminds/scripts/sync.log</string>

      <key>RunAtLoad</key>
      <false/>
  </dict>
  </plist>
  ```

- [ ] **Step 2: Load the plist**

  ```bash
  launchctl load ~/Library/LaunchAgents/org.oddminds.sync-from-prod.plist
  ```

  No output = success.

- [ ] **Step 3: Verify it loaded**

  ```bash
  launchctl list | grep oddminds
  ```

  Expected output (the middle column is last exit code — `-` means never run yet):
  ```
  -       0       org.oddminds.sync-from-prod
  ```

- [ ] **Step 4: Do a one-shot test via launchctl to confirm the plist works**

  ```bash
  launchctl start org.oddminds.sync-from-prod
  sleep 5
  cat scripts/sync.log
  ```

  Expected: sync output appears in `sync.log`, starting with `[1/4] Backing up local DB`.

  > Note: `launchctl start` triggers an immediate run independent of the schedule. It will run a full sync — that's fine, you already have a good backup from Task 3.

---

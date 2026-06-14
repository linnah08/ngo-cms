#!/usr/bin/env bash
# sync-from-prod.sh — streams prod DB to local, auto-restores on failure.
set -euo pipefail

# ── Config ────────────────────────────────────────────────────────────────────
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCRIPTS_DIR="$REPO_ROOT/scripts"
BACKUP_FILE="$SCRIPTS_DIR/local-backup-$(date +%Y%m%d).sql.gz"

PROD_SSH_KEY="$HOME/.ssh/oddminds"
PROD_SSH_USER="detelinavasileva"
PROD_SSH_HOST="89.252.247.33"
# Path to db.config.php on the prod server — update if different.
PROD_DB_CONFIG="/home/detelinavasileva/public_html/oddminds.org/db.config.php"
PROD_SITE_ROOT="$(dirname "$PROD_DB_CONFIG")"

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
        if [ -f "$BACKUP_FILE" ] && gzip -t "$BACKUP_FILE" 2>/dev/null; then
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
        elif [ -f "$BACKUP_FILE" ]; then
            rm -f "$BACKUP_FILE"
            echo "ERROR: sync failed and backup is corrupt — local DB may be empty."
            echo "Restore manually from a previous backup in scripts/."
        else
            echo "ERROR: sync failed before backup was created — local DB may be empty."
            echo "Import prod dump manually or restore from a previous backup."
        fi
    fi
}

trap _cleanup EXIT

# ── Step 1: Backup local DB ───────────────────────────────────────────────────
echo "[1/5] Backing up local DB → $BACKUP_FILE"
MYSQL_PWD="$LOCAL_DB_PASS" mysqldump \
    --single-transaction \
    -h"$LOCAL_DB_HOST" -u"$LOCAL_DB_USER" "$LOCAL_DB_NAME" \
    | gzip > "$BACKUP_FILE"

# ── Step 2: Drop + recreate local DB, stream prod dump ───────────────────────
echo "[2/5] Dropping and recreating local DB..."
MYSQL_PWD="$LOCAL_DB_PASS" mysql \
    -h"$LOCAL_DB_HOST" -u"$LOCAL_DB_USER" \
    -e "DROP DATABASE IF EXISTS \`$LOCAL_DB_NAME\`;
        CREATE DATABASE \`$LOCAL_DB_NAME\`
            CHARACTER SET utf8mb4
            COLLATE utf8mb4_unicode_ci;"

echo "[2/5] Streaming prod dump over SSH..."
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
echo "[3/5] Running migrations..."
php "$REPO_ROOT/migrate.php"

# DB sync is complete — set flag before content rsync so a content failure
# never triggers a DB restore.
SYNC_OK=true

# ── Step 4: Sync content files (best effort) ──────────────────────────────────
echo "[4/5] Syncing content files from prod..."
rsync -az --delete \
    -e "ssh -i \"$PROD_SSH_KEY\"" \
    "$PROD_SSH_USER@$PROD_SSH_HOST:$PROD_SITE_ROOT/content/" \
    "$REPO_ROOT/content/" \
    || echo "Warning: content rsync failed — DB synced OK, content files may be stale."

# ── Step 5: Rotate backups ────────────────────────────────────────────────────
echo "[5/5] Sync complete."

# Keep last 2 backups; delete older ones.
# shellcheck disable=SC2012
{ ls -t "$SCRIPTS_DIR"/local-backup-*.sql.gz 2>/dev/null || true; } \
    | tail -n +3 \
    | xargs rm -f

echo "Done. Backups kept:"
ls -lh "$SCRIPTS_DIR"/local-backup-*.sql.gz 2>/dev/null || true

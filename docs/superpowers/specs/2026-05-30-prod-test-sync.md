# Prod → Local DB Sync

**Goal:** Weekly automated sync of the full production database to the local development environment, so tests run against real data and never fail due to missing courier/payment configurations.

---

## One-time setup

Update `SETTINGS_ENCRYPTION_KEY` in local `db.config.php` to match prod's key.

Reason: prod and local use different encryption keys by default. Since the full `settings` table (courier credentials, payment credentials, shipping rates) is copied verbatim from prod, both environments must share the same key for `setting_get()` to decrypt values correctly locally. The local `db.config.php` is gitignored and holds no other cross-environment secrets, so aligning the key carries no meaningful additional risk.

---

## Sync script — `scripts/sync-from-prod.sh`

Single shell script committed to the repo. Runs locally, requires SSH access to prod (`~/.ssh/oddminds`, user `detelinavasileva`, host `89.252.247.33`).

### Steps

1. **Backup local DB** → `scripts/local-backup-YYYYMMDD.sql.gz` (gzipped mysqldump of the current local DB).
2. **Drop and recreate local DB**, then **stream full prod dump over SSH** (all tables, no exclusions) → `ssh prod "mysqldump --single-transaction --all ..." | mysql local_db`. No dump file is written on the prod server.
3. **Run `php migrate.php`** → applies any local migrations not yet deployed to prod.
4. **Mark success** and rotate backups: keep the two most recent, delete older ones.

### Failure handling

A `bash` `trap` fires on any error — including a broken SSH pipe mid-import, a mysql error, or a failed migration. The trap:

- Checks whether the success flag was set.
- If not: automatically restores from the backup taken in step 1 (`gunzip | mysql`), then prints what failed and exits non-zero.

Because the local DB is dropped before the import begins, any partial import leaves an empty or incomplete DB — the trap catches this and leaves the environment in the same working state as before the sync started.

### Backup retention

Keep last 2 backups in `scripts/`. Delete any older `local-backup-*.sql.gz` files at the end of a successful run.

### Logging

stdout + stderr → `scripts/sync.log`, overwritten each run (last run only).

---

## Scheduling — macOS launchd

A plist at `~/Library/LaunchAgents/org.oddminds.sync-from-prod.plist` (outside the repo — machine-specific) fires the script every **Friday at 12:00**.

- Survives reboots; if the machine is asleep at noon it runs at next wake.
- The plist references the absolute path to `scripts/sync-from-prod.sh` in the repo.
- The plist is not committed to git; setup instructions are in the implementation plan.

---

## Edge cases

| Scenario | Behaviour |
|---|---|
| SSH drops mid-import | `mysql` exits non-zero → trap fires → backup restored |
| `migrate.php` fails (e.g. new local migration has a bug) | trap fires → backup restored |
| Prod DB has a migration not yet on local branch | Import succeeds (prod schema is valid); no local migration to run |
| Local branch has migrations newer than prod | Import succeeds (prod schema); `migrate.php` applies the new ones on top |
| Backup disk full | Script errors before dropping local DB → local DB untouched |

---

## Files changed

| File | Change |
|---|---|
| `db.config.php` | Update `SETTINGS_ENCRYPTION_KEY` to prod value (local only, gitignored) |
| `scripts/sync-from-prod.sh` | New — sync script |
| `~/Library/LaunchAgents/org.oddminds.sync-from-prod.plist` | New — launchd schedule (not in repo) |

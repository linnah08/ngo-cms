---
paths:
  - "migrations/**"
  - "migrate.php"
---

# Database migrations

- **Production is MariaDB 10.5, local dev is MySQL 9.x.** Write SQL that runs on both.
- **No `ADD COLUMN IF NOT EXISTS` / `DROP COLUMN IF EXISTS` on `ALTER TABLE`** — MariaDB-only, a hard 1064 on MySQL that halts `migrate.php` (it bit 026). `tests/MigrationPortabilityTest.php` fails on it. `CREATE TABLE IF NOT EXISTS` is fine on both.
- Idempotency comes from the runner, not the SQL: `migrate.php` records applied files in `_migrations` and skips "Duplicate column" / "already exists" errors. Write plain DDL (`ADD COLUMN images JSON ...`).
- Test a migration against a production-like schema snapshot before deploying.
- A fork-only migration (e.g. lafetki) must not reuse a number or name ngo-cms has or is likely to use next — check `ls migrations` in ngo-cms first.

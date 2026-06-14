# Domain migration checklist (new.oddminds.org → oddminds.org)

When migrating:

1. `config.php` line ~57: change `SITE_URL` to `'https://oddminds.org'`
2. `deploy.php` on server (not in git): update `REPO_ROOT` and `LOG_FILE` paths (2 lines)

Everything else derives from `SITE_URL` automatically.

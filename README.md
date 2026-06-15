# NGO website, shop & donations platform

An open-source, bilingual (Bulgarian/English) website for non-profit
organisations: a content-managed public site, an online shop, one-off
donations, crowdfunding campaigns, and automatic invoice / receipt /
donation-certificate PDFs. Built in vanilla PHP 8.4 — no framework — with
MySQL and flat-file JSON content.

It is released as open source so other NGOs — particularly in Bulgaria —
can run the same stack. The default content and configuration ship with neutral placeholder values; you replace them with your own.

## Features

- **Public site** with inline, in-browser content editing (no code needed to edit copy)
- **Shop** with product variants, cart, and a unified checkout
- **Donations** — one-off donations with downloadable donation certificates
- **Crowdfunding campaigns** with reward tiers and ticketed events (optional, toggleable)
- **Documents** — invoices, receipts, credit notes and donation certificates as PDFs (with digital signatures)
- **Admin panel** — products, orders, articles, pages, campaign, newsletter, settings
- **Bilingual** BG/EN throughout, with optional DeepL-assisted translation
- **Bulgaria-specific integrations** (optional): Econt / Speedy / BoxNow couriers, DSK Bank vPOS card payments, ЕИК/Булстат company invoicing, and dual BGN/EUR pricing during euro adoption

## Quick install (cPanel — no coding)

For a non-technical setup on shared cPanel hosting:

1. **Download** the latest `ngo-platform-*.zip` from the
   [Releases](../../releases) page (it already includes all dependencies).
2. In cPanel open **File Manager**, go to your domain's folder (e.g.
   `public_html`), click **Upload**, choose the ZIP, then **Extract** it there.
3. **Open your website** in a browser. The setup wizard launches automatically.
4. Pick **“Create a new database”**, fill in your organisation's name, contact
   details and an admin login, and click **Install**.
5. When it finishes, delete the `install/` folder (File Manager → select →
   Delete). Log in at `your-site.org/admin/`.

That's it — no git, composer, SSH or database setup required.

## Prerequisites (manual / developer install)

- PHP 8.4+ with extensions: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `json`
- MySQL 8.0+
- Composer
- A web server with URL rewriting (Apache `mod_rewrite`, or Nginx)

## Manual setup

### 1. Clone and install dependencies

```bash
git clone <your-repo-url>
cd <repo>
composer install
```

### 2. Create the config files

Each config below is gitignored. Copy the matching `*.example` file and fill it in.

| Copy from | To | Required? | Purpose |
|---|---|---|---|
| `db.config.php.example` | `db.config.php` | **Yes** | Database connection + settings encryption key |
| `site.config.example.php` | `site.config.php` | **Yes** | Your organisation: name, URL, contact, IBAN, socials, analytics, feature toggles |
| `graph.config.php.example` | `graph.config.php` | No | Outgoing email via Microsoft Graph (email is disabled until set) |
| `courier.config.php.example` | `courier.config.php` | No | Courier API credentials (Bulgaria) |

`site.config.php` is the one file you edit to rebrand the site — name, base URL,
contact details, bank account, social links, Google Analytics/Ads IDs, and
feature toggles (e.g. `FEATURE_CAMPAIGN`). Leave any analytics ID empty (`''`)
to disable that tag entirely.

### 3. Create and install the database

```bash
mysql -u root -e "CREATE DATABASE ngo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php install.php
```

`install.php` runs all migrations (creating the schema) and then creates your
first admin account. You can pass credentials non-interactively:

```bash
ADMIN_EMAIL=you@example.org ADMIN_PASSWORD='a-strong-password' php install.php
```

### 4. Configure the web server

The document root must point at the repository root, with rewriting enabled.

**Apache** — `AllowOverride All` so the bundled `.htaccess` is honoured:

```apache
<VirtualHost *:80>
    ServerName mysite.local
    DocumentRoot /path/to/repo
    <Directory /path/to/repo>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Then add `127.0.0.1 mysite.local` to `/etc/hosts` and restart Apache. The site
is at `http://mysite.local`, the admin panel at `http://mysite.local/admin/`.

> **Nginx:** translate the `.htaccess` rewrite rules into `location` blocks.

## Configuring your organisation

Most day-to-day content is editable in the browser:

- **Text & images** — click-to-edit inline on every public page when logged in as admin
- **Settings** — admin → Settings (TinyMCE/DeepL keys, courier & payment credentials, etc.)
- **Structured content** — `content/*.json` (centres, partners, impact figures, UI strings)

What still lives in code (edit as needed for your organisation):

- **Legal / invoice identity** — your organisation's legal name, address,
  company number, bank details and representative used on generated PDFs are in
  `includes/documents/DocumentGenerator.php` (the `ORG_DETAILS` block). The
  shipped values are placeholders — replace them with your own.
- **Legal pages** — the impressum, privacy policy, terms and cookie policy
  (under their slug folders) are inline-editable in the browser; rewrite them
  for your organisation.

## Bulgaria-specific notes

This stack was built for the Bulgarian context. The following are optional and
can be left unconfigured (or removed) if they don't apply to you:

- **Couriers** — Econt, Speedy, BoxNow (`courier.config.php`)
- **Card payments** — DSK Bank vPOS (configured in admin → Settings)
- **Invoicing** — ЕИК/Булстат fields for company invoices
- **Currency** — dual BGN/EUR display during euro adoption (`DUAL_CURRENCY_UNTIL` in `site.config.php`)

## Running tests

Tests use PHPUnit and live in `tests/`. Unit tests need no database or network:

```bash
vendor/bin/phpunit --exclude-group integration,econt,speedy,boxnow,dsk-integration,http,db
```

Database and integration tests self-skip when their config/credentials are
absent, so you will never see false failures. See `COURIERS.md` for the courier
APIs and the test groups for each integration.

## Deployment

`.github/workflows/deploy.yml` is an example GitHub Actions workflow that
deploys over SSH/rsync to a cPanel host and runs `migrate.php`. It expects
repository secrets `FTP_SERVER`, `FTP_USERNAME`, `SSH_PRIVATE_KEY` and
`DEPLOY_PATH`. Adapt or replace it for your own hosting.

## License

MIT — see [LICENSE](LICENSE).

# Support relay

This is a small standalone PHP service hosted by the vendor at `https://support.oddminds.org/ticket.php`.
NGO installs send "report a problem" tickets to it. The relay checks the install's support code and then
creates a card on **that customer's own Trello board**. The Trello key and token live only here, never in an NGO install.

This service is not part of the CMS. It does not use the CMS `config.php`, database or helpers, and it is not in the release zip.

## Layout

```
relay/
  public/ticket.php      the endpoint (the only web-accessible file)
  public/.htaccess       denies everything except ticket.php, turns off directory listing
  public/.user.ini       upload limits (6M) for the 5 MB screenshot
  src/                   logic: validation, auth, rate limit, Trello client, card format
  bin/                   CLI tools: add-install, deactivate-install, list-installs
  config.php             (you create it, gitignored) Trello key/token and paths
  installs.json          (created by add-install, gitignored) sha256(code) → customer and list id
  data/                  (created at runtime, gitignored) rate-limit state and logs/relay.log
```

## Deploy (cPanel)

1. Copy the whole `relay/` directory to `~/support-relay/` on the server, using File Manager or SFTP.
2. In cPanel → Domains, create `support.oddminds.org`. Only `public/` should be web-reachable. Turn on AutoSSL/HTTPS for the subdomain.
   **If cPanel forces the document root inside `public_html`** (it does on the oddminds host, where the
   document root is `~/public_html/support-relay/public`), keep everything else in `~/support-relay/`
   *outside* `public_html` anyway. Copy only the files from `public/` into that document root, then create
   `relay-root.php` next to `ticket.php` so the endpoint can find the rest:
   ```php
   <?php return '/home/<user>/support-relay';
   ```
   Keep cPanel's own `.htaccess`/`.user.ini` lines in that folder. Add the relay's rules to them rather than
   overwriting them.
3. Copy `config.example.php` to `config.php` in the same folder (`~/support-relay/`).
4. Fill in `trello_key` and `trello_token`. At https://trello.com/power-ups/admin, open your Power-Up and
   go to **API key**. Copy the key, then click the **Token** link next to it and authorise. Use a Trello
   account that is a member of every customer board.
5. Select PHP 8.1 or newer for the subdomain (MultiPHP Manager). It needs the `curl`, `fileinfo`, `mbstring` and `json` extensions.
6. Create the first install (see below). This also creates `installs.json`.
7. Check it:
   - `curl -i https://support.oddminds.org/ticket.php` should return `405`.
   - `curl -i https://support.oddminds.org/config.php` should return `404`, because it is outside the document root.
   - `curl -i https://support.oddminds.org/` should return `403`.
8. `data/` needs to be writable by PHP. It is created on the first request. Check the log at `~/support-relay/data/logs/relay.log`.

To run CLI commands without shell access, use cPanel → **Terminal**. Where Terminal is not available,
use a one-off cron job and read the output from the cron email.

## Onboard a new customer

1. In Trello, create a board for the customer and a list called **New**.
2. Get the list's id. Open any card on the board, add `.json` to its URL and find `"idList"`.
   Or call `GET https://api.trello.com/1/boards/<boardShortLink>/lists?key=…&token=…`.
   The id is 24 hex characters. A full ARI (`ari:cloud:trello::list/workspace/<ws>/<id>`) also works;
   the script keeps the last part.
3. On the server:
   ```
   cd ~/support-relay
   php bin/add-install.php "Give Time Foundation" 5f2b9c1e8a7d6b5c4e3f2a1b
   ```
   The script prints a code like `ngo_…`. **It is shown only once.** Only its SHA-256 hash is stored.
4. Send the code to the NGO over a private channel. They paste it into their admin panel's support settings.
   If the code is lost, deactivate the install and add it again.

`php bin/list-installs.php` shows every install with its hash prefix, status, list id and name.

## Revoke

```
php bin/deactivate-install.php "Give Time Foundation"   # by exact name (case-insensitive)
php bin/deactivate-install.php 3f1c8e0a9b               # or by hash prefix (8+ hex chars)
```

The entry stays in `installs.json` with `active: false`. That code gets `401` from then on.

## API contract (for reference)

`POST /ticket.php` with `multipart/form-data` and header `X-Support-Key: <code>`.
Fields: `subject` (required, ≤150), `description` (required, ≤5000), `page` (≤300), `diagnostics`
(a JSON object or array, ≤20000), `screenshot` (png, jpeg or webp, ≤5 MB, detected from content).

Responses are JSON:
- `200 {"ok":true,"reference":"ABCD2345"}`
- `401 unauthorized`
- `429 rate_limited` (default 10 per install per rolling hour)
- `400 invalid`
- `405` for any method other than POST
- `500 server_error`

The reference is also written in the card, so you can search Trello or the log for it. Error details
only ever go to `data/logs/relay.log`, never to the client.

## Tests

From the CMS repo root: `php vendor/bin/phpunit tests/Relay/RelayTest.php`. The tests make no network calls.

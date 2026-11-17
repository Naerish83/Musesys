# H1 — Operator steps (server side)

Code changes are done in the local copy. These steps make prod match. Do them in order; step 2 must happen
before the old file is deleted, or the (currently disabled) order-history feature loses its data source.

App: `vkbtktbvgp`, server 1564610. App root on Cloudways: `/home/master/applications/vkbtktbvgp/`
(contains `public_html/` and `private_html/`). Plugin: `public_html/wp-content/plugins/musesys-mega/`.

## 1. Upload the new reader

Replace on the server (SFTP as the master user):

| Local file | Server path (under `public_html/wp-content/plugins/musesys-mega/`) |
|---|---|
| `modules/muse-order-history/muse-order-history.php` | `modules/muse-order-history/muse-order-history.php` |
| `modules/muse-order-history/README.md` | `modules/muse-order-history/README.md` (optional) |

The dashboard copy (`modules/musesys-account-dashboard/muse-order-history/`) is not loaded by the plugin
loader and returned 302 (absent) in prod. If it does exist on the server, replace its `.php` too.

## 2. Move the data file out of the web root

Do NOT take the file from the backup and put it anywhere under `public_html`.

Easiest: move it on the server itself (SSH / Cloudways SSH terminal):

```bash
mkdir -p /home/master/applications/vkbtktbvgp/private_html/muse-order-history
mv /home/master/applications/vkbtktbvgp/public_html/wp-content/plugins/musesys-mega/modules/muse-order-history/data/wegmans_order_history.json /home/master/applications/vkbtktbvgp/private_html/muse-order-history/
chmod 640 /home/master/applications/vkbtktbvgp/private_html/muse-order-history/wegmans_order_history.json
```

If you have no shell, upload the local backup (path in the session report) to
`private_html/muse-order-history/wegmans_order_history.json` over SFTP, then delete the copy in `data/`.

If WordPress is not installed directly in `public_html`, or you want another location, add this to
`wp-config.php` instead (any path outside `public_html`):

```php
define( 'MUSE_OH_DATA_FILE', '/home/master/applications/vkbtktbvgp/private_html/muse-order-history/wegmans_order_history.json' );
```

The reader refuses to read the file from anywhere inside `ABSPATH` or `wp-content` and logs
`muse-order-history: refusing to read ...` to the PHP error log if someone points it there.

## 3. Delete the remaining copies on the server (if present)

Under `public_html/wp-content/plugins/musesys-mega/`:

- `modules/muse-order-history/data/wegmans_order_history.json` (gone if you used `mv` in step 2)
- `modules/musesys-account-dashboard/muse-order-history/data/wegmans_order_history.json`
- `order-history-updater/wegmans_order_history.json`
- `order-history-updater/wegmans_order_history_backup.json`

The last three returned 302 (not found) in the 2026-10-21 probe. Delete them if `ls` shows them.

## 4. Purge caches

Cloudways → Applications → `vkbtktbvgp`:
- **Application Settings → Varnish → Purge**, or use the purge button on the app's Application Management page.
- If a CDN (Cloudflare / Cloudways CDN) sits in front, purge it too. `X-Cache: HIT` came from Varnish.

## 5. Tell the agent "server done"

The agent then runs:

```bash
curl -sI https://wegmansparts.com/wp-content/plugins/musesys-mega/modules/muse-order-history/data/wegmans_order_history.json
```

Expected: 403/404 (or the 302→login that absent plugin paths return), no `Content-Type: application/json`,
no `X-Cache: HIT`.

## 6. Breach review (your decision)

The file was publicly downloadable. The local data file is dated 2026-04-27, so assume exposure from the
first deploy after that date until the purge.
- The local log copies in the repo root cover only 2026-05-27 to 2026-05-28 and contain no hits for the path.
- Pull the full access logs for the window from the server: `/home/master/applications/vkbtktbvgp/logs/`,
  especially the `static_*` and `nginx` access logs, and ask Cloudways support for older rotated logs if needed.
- Then search them:

```bash
grep -h "wegmans_order_history" /home/master/applications/vkbtktbvgp/logs/*access.log* | awk '{print $1, $4, $9, $10}'
```

A 200 with a size of about 609,460 bytes means a full download. Varnish cache hits may not show up in the
backend logs at all, so a clean backend log does not prove that nobody downloaded the file. Whether to
notify is a business decision and is yours to make.

## Note on smoke test

In this working copy the order-history tab is disabled: both hooks are commented out, and the dashboard shows
it as a disabled tab. Nothing generates `muse_oh_nonce`, so the AJAX endpoint cannot be reached in practice
either. That means no customer-facing page exercises the reader. After the move, check the PHP error log
for `muse-order-history` lines; there should be none.

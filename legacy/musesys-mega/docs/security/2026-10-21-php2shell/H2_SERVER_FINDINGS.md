# Server session findings: H1 leftovers, H2, H5 (2026-10-21, ~16:35 UTC)

All of this was read-only, done through the Cloudways API/MCP and HTTP HEAD/GET probes of our own hosts. No server writes were made.
Server 1564610 "Wegmans-OG" runs Debian 12, stack v1 (Hybrid), and two apps:

| App | ID | sys_user | Host |
|---|---|---|---|
| Prod | 6079396 | `vkbtktbvgp` | wegmansparts.com |
| Staging (clone of prod) | 6091031 | `rxdetsjact` | wordpress-1564610-6091031.cloudwaysapps.com |

> **Scope change (operator, 2026-10-21):** staging (`rxdetsjact`) is out of scope. It is a disposable copy, repaired by pulling prod. No further probes or changes. The section below is kept only as the record of what was observed before this decision.

## NEW, CRITICAL: the staging app still serves the order-history JSON publicly

**FACT:**
- This URL returns `200 application/json`, `X-Cache: MISS`, `Last-Modified: 2026-07-07`:
  `https://wordpress-1564610-6091031.cloudwaysapps.com/wp-content/plugins/musesys-mega/modules/muse-order-history/data/wegmans_order_history.json`
- The field set is the same as prod's leak: `email`, `name`, `ship_address`, `ship_city_state_zip`, `order_number`, `work_order`, `tracking`. Only key names were inspected; no values were stored.
- Staging has no HTTP auth (`app_password_protection_get` returns `is_enabled: false`).
- Because staging is a clone of prod, the H1 fix (file move and reader) was applied to prod only.
- The other copy paths on staging return 302, so they are probably absent:
  - `musesys-account-dashboard/...`
  - `order-history-updater/...`

**Containment options** (each is a server write and needs operator approval):
- **(a)** Enable HTTP basic auth on staging with `app_password_protection_update` (app 6091031, enable).
  - Immediate and reversible.
  - It also stops staging being crawlable and covers any other leftovers on staging.
- **(b)** The operator deletes the file over SFTP: `/home/master/applications/rxdetsjact/public_html/wp-content/plugins/musesys-mega/modules/muse-order-history/data/wegmans_order_history.json`.
  - The staging reader then breaks unless the private_html copy and the new reader are also placed there.

The breach-log question applies to staging too. Its exposure window is unknown and likely began when staging was cloned (2025-12-22) or when the file was dated (2026-07-07).

## H1 leftovers

| # | Item | Result |
|---|---|---|
| 1 | `private_html/muse-order-history/` listing; file is 609,460 bytes and app-user-readable | **NOT VERIFIABLE via API.** The Cloudways API has no file listing or shell. The operator runs `ls -la /home/master/applications/vkbtktbvgp/private_html/muse-order-history/` over SSH. (FACT: the server disk-cleanup setting `remove_app_private_html` is `no`, so the automated cleanup will not delete it.) |
| 2 | Prod `muse-order-history.php` contains `muse_oh_data_file_is_private`; no `refusing` lines in the PHP error log | **NOT VERIFIABLE via API.** SSH: `grep -c muse_oh_data_file_is_private .../public_html/wp-content/plugins/musesys-mega/modules/muse-order-history/muse-order-history.php` and `grep 'muse-order-history: refusing' .../logs/*error*`. |
| 3 | No `wegmans_order_history*.json` under prod `public_html` | **Partially verified over HTTP.** The original path returns 403 MISS (with and without a cache-buster). The dashboard and updater copies return 302 (absent). A full `find` needs SSH. |
| 4 | Access-log grep for prior downloads | **Evidence window too short; see below.** |

### Breach-evidence grep (report only)

| Source | Window | Hits for `wegmans_order_history` |
|---|---|---|
| API backend access log, prod | 2026-10-21 16:00:38 → 16:32:10 UTC (last ~1,000 lines) | 7. All are from `108.12.43.194` (curl): our own verification probes at 16:26 and 16:31, all 403 or 302. |
| API static (nginx) access log, prod | 2026-10-20 15:01 → 2026-10-21 16:19 UTC | 0 |
| API static access log, staging | ends 2026-10-14 21:02 UTC, so it is stale or lagging | 0 |
| Local log copies in repo root (`backend_*`, `static_*`, `php-app.access.log*`) | 2026-05-27 → 2026-05-28 only | 0 |

- The API returns only the tail of each log, so it **cannot cover the exposure window**, which starts around 2026-04-27.
- A full answer needs SSH: `zgrep -h wegmans_order_history /home/master/applications/{vkbtktbvgp,rxdetsjact}/logs/*access*`, then `ls -la --time-style=long-iso` on the same logs to find the oldest date.
- Varnish HITs never reach the backend log. They may appear in the nginx static log, depending on the stack.
- No conclusion on prior downloads can be drawn from this evidence. The notification decision belongs to the operator.

## H2: PHP version and ini

| Setting | Value | Source |
|---|---|---|
| PHP branch (server and prod app) | **8.2** | `server_settings_get`, `app_settings_get` |
| PHP patch level | **UNKNOWN.** The API reports only the branch. Needs `php -v` / `php-fpm8.2 -v` over SSH, or confirmation from Cloudways that 8.2.35 is deployed. | — |
| FPM service | `php8.2-fpm` running | `service_status` |
| `memory_limit` | 512 (server) | server settings |
| `upload_size` (sets post_max_size / upload_max_filesize) | 100 MB, server level | server settings |
| `execution_limit` | 300 s | server settings |
| `max_input_time` | 60 | server settings |
| `display_errors` | **Off** ✅ | server settings |
| ModSecurity `secrequestbodylimit` | 536870912 (512 MB) | server settings |
| `session.upload_progress.enabled` | Not exposed in server settings; no pool override, so the default **On** is assumed | — |
| Pool overrides (prod `app_fpm_settings_get`) | Only `php_admin_value[disable_functions]=…` is active. The template lines (memory_limit, post_max_size, max_file_uploads, display_errors) are all commented out. | `app_fpm_settings_get` |
| Imunify360 | enabled, running | server settings and services |

**Assessment:**
- 8.2 is affected; the fix for this branch is 8.2.35.
- None of the interim mitigations are set at pool level, and the upload and request limits are larger than recommended.

**Proposed writes (need operator approval):**
1. **Prod pool overrides** via `app_fpm_settings_update` on app 6079396. Keep the existing `disable_functions` line and append:
   ```
   php_admin_flag[session.upload_progress.enabled]=off
   php_admin_value[memory_limit]=512M
   php_admin_value[post_max_size]=32M
   php_admin_value[upload_max_filesize]=20M
   php_admin_value[max_file_uploads]=10
   php_admin_flag[display_errors]=off
   ```
   - `memory_limit` stays at today's value but is locked, so `ini_set` cannot raise it.
   - The same settings should go on staging (6091031).
2. **Server `upload_size` 100 → 32** via `server_settings_update`. This affects both apps and lowers the proxy body limit to match.
3. **Smoke test** after the writes: login, cart, checkout, product-request upload (≤20 MB), return-form upload. The API applies FPM settings with its own reload; whether a separate PHP-FPM restart is needed is UNKNOWN, and we can restart via `service_restart` if needed.
4. **PHP branch move.** 8.2 reaches EOL on 2026-12-31. Recommend 8.3, tested first on the staging clone (6091031) once the staging leak is contained. Do not switch prod without that.

### Vulnerability scan and versions (prod 6079396)

- `app_vulnerabilities_list` (fetched 2026-10-21): **0 vulnerable items**.
- `app_vulnerabilities_refresh` was **not run**, because it triggers a rescan job (write-ish). The listing is dated today.

| Component | Version | Status |
|---|---|---|
| WordPress core | 7.1.3 | not vulnerable |
| WooCommerce | 11.2.1 | active |
| WPForms Lite | 2.0.2.2 | active |
| WP Mail SMTP | 4.10.3 | active |
| Relevanssi | 4.28.4 | active |
| User Switching | 1.12.2 | active |
| MuseSys Mega | 1.2.9 | active |
| Object Cache Pro | 1.24.5 | **inactive**, folder renamed `object-cache-pro.bak`. The Cloudways app setting `ocp=1` still shows object cache enabled. |
| Breeze | 2.5.17 (2.6.3 available) | inactive |
| Akismet | 5.7.2 | inactive |
| WebToffee import/export trio | **not installed** | Contradicts ASSESSMENT §2, which was based on older debug.log paths. Current state wins. |

## H5 deploy hygiene (report only)

HTTP probes cannot settle most of these items:
- On this host, nonexistent `.txt`/`.log`/`.php` paths also return 403 (control requests confirmed this).
- A 302 is the WordPress not-found redirect.

| Item | Probe result | Read |
|---|---|---|
| `safe/` | 302 | probably absent (HYPOTHESIS) |
| `order-history-updater/` | 302 | probably absent (HYPOTHESIS) |
| `MuseSys_exporter/assets/exporter.js` (root duplicate) | 302 | probably absent (HYPOTHESIS) |
| `modules/musesys-ledger/tests/` | 403 Apache (dir) | **probably present** (HYPOTHESIS: an Apache 403 on a directory means it exists) |
| `mu-plugins/local-by-flywheel-live-link-helper.php` | 403 (the control also returns 403) | inconclusive; SSH `ls wp-content/mu-plugins/` |
| `*.log`, `loader_array*.txt`, `mega-consts.txt`, `refactor_error*.txt` | 403 (the control also returns 403) | inconclusive, but not downloadable |

SSH one-liner to settle all of it:

```
cd /home/master/applications/vkbtktbvgp/public_html/wp-content && ls mu-plugins; cd plugins/musesys-mega && ls -d safe order-history-updater MuseSys_exporter muse-aq-exporter musesys-reports modules/musesys-ledger/tests 2>&1; ls *.log *.txt *.py string_refactor.* test_loader.php generate_loader.php 2>&1
```

## Not done
- No server writes. Nothing in H3, H4 or H6 was touched.
- `app_vulnerabilities_refresh` was not run.
- Every SSH-only item above is still open, and the Cloudways API cannot do it.

---

## 2026-10-21 (~16:50 UTC): prod ini mitigations applied (operator-approved, prod `vkbtktbvgp` / app 6079396 only)

**Method:** PHP-FPM pool level through the Cloudways API `app_fpm_settings_update`, as `php_admin_value` / `php_admin_flag`. This is not `.user.ini` or `ini_set`; `php_admin_*` values cannot be overridden by application code. The before-change pool config is backed up in the session scratchpad at `h2/fpm_before.conf`. The existing `disable_functions` line is unchanged.

| Setting | Before | After |
|---|---|---|
| `session.upload_progress.enabled` | PHP default (On); no pool override | `php_admin_flag … = off` |
| `memory_limit` | 512M from server setting, overridable by `ini_set` | `php_admin_value … = 512M` (pinned) |
| `post_max_size` | 100M (server `upload_size`) | `php_admin_value … = 32M` |
| `upload_max_filesize` | 100M (server `upload_size`) | `php_admin_value … = 20M` |
| `max_file_uploads` | PHP default (20) | `php_admin_value … = 10` |
| `display_errors` | Off (server setting) | Off (server) + `php_admin_flag … = off` (pool) |

**Verified:**
- `app_fpm_settings_get` read-back shows all six lines in the prod pool config.
- Logged-out GET smoke test after the change matches the pre-change baseline: `/` 302, `/wp-login.php` 200 with no PHP error text, `/cart/`, `/checkout/` and `/my-account/` 302 (front-door redirect), `admin-ajax.php` 400.

**Not done:**
- **PHP-FPM restart: not performed.** The local agent permission layer (auto-mode classifier) blocked `service_restart php8.2-fpm`. Whether the Cloudways FPM update reloads the pool by itself is **UNKNOWN**, so the new values may not be live yet.
- **Effective-value check: not performed.** The test was a multipart heartbeat POST over the 32M limit, and the same permission layer blocked it.
- **Logged-in smoke test** (login, cart, checkout, product-request upload, return-form upload): not performed. It needs a real prod account; agents must not enter credentials on prod. Operator to run it.
- **Server `upload_size` (100M)** was left alone. It is server-wide, covers staging, and was not in the approval. It still sets the proxy body limit; the pool-level `post_max_size=32M` is the effective PHP limit once live.
- No other settings were reverted, because nothing broke in the checks that ran.

**Rollback:** re-apply `fpm_before.conf` with `app_fpm_settings_update`, or delete the six lines in Console → Application → Application Settings → PHP-FPM Settings.

### Restart retry (~17:00 UTC, operator allowed `service_restart`)
- **Restart still NOT performed.** The Cloudways MCP `service_restart` rejects `php8.2-fpm`. Its input enum lists only `mysql`, `apache2`, `nginx`, `imunify360`, `memcached`, `varnish`, `redis-server`, `php7.4-fpm`, `elasticsearch` and `supervisor`, and wrapping the call in `execute_tool` is refused. This is a limitation of the MCP tool, not of the server. Stop/start was not attempted, because it was not approved and would cause downtime.
- `service_status`: `php8.2-fpm` running.
- Logged-out GET smoke test: unchanged from baseline (`/` 302, `/wp-login.php` 200 with no PHP error text, cart/checkout/my-account 302, admin-ajax 400).
- **Operator action:** Cloudways Console → Server 1564610 → Manage Services → PHP-FPM → Restart. Then confirm with `php-fpm8.2 -i` / a phpinfo over SSH, or with an over-32M POST test, and run the logged-in smoke test.

### After the operator's PHP-FPM restart (Cloudways console, 2026-10-21)
- `service_status`: `php8.2-fpm` running.
- Logged-out GET checks match the baseline: `/` 302, `/wp-login.php` 200 with no PHP error text, cart/checkout/my-account 302, admin-ajax 400. The H1 leak URL still returns 403.
- **Still unverified:**
  - The effective ini values. Needs `php-fpm8.2 -i` / phpinfo over SSH, or an over-32M POST test.
  - The logged-in smoke test (login, cart, checkout, product-request and return-form uploads). Operator to run it.
  - The PHP patch level (8.2.35 or later).

### PHP patch level confirmed by operator (2026-10-21, after FPM restart)
- Prod server 1564610 runs **PHP 8.2.34: UNPATCHED** (the fix is 8.2.35). Prod is still exposed to CVE-2026-109618/109619. The ini mitigations reduce the risk but do not remove it.
- Cloudways status, 16:43 UTC: "interim configuration-level mitigations … while preparing a phased rollout of official patches". No ETA for 8.2.35, and no customer action requested yet.
- Correction to HANDOFFS.md H2 (written by the assessment session): php.net's interim mitigation for CVE-2026-109618 is `memory_limit=-1`, set so applications can't override it. It is not a pinned finite limit. Prod has 512M pinned, so the OOM trigger is still reachable. Changing to `-1` lets a single request use all 8 GB unless a cgroup or worker limit exists. This is an operator decision; see the assessment session.

## Patch watch (set up 2026-10-21)
Cloudways gives no ETA or notice for 8.2.35 (support said only "early patch status"), and its API reports the branch (`8.2`), never the patch level. So we watch for the change ourselves.

- **What it checks:** `GET https://wegmansparts.com/wp-json/wc/v3/system_status?_fields=environment` returns `environment.php_version`. That is the PHP version the site is actually serving requests with, which is what matters. A patched binary protects nothing until PHP-FPM restarts onto it.
- **Auth:** a read-only WooCommerce REST key, stored in user env vars `WEGMANS_WC_KEY` / `WEGMANS_WC_SECRET`. It is never logged.
- **Script:** `C:\Users\chenderson\WegmansPhpWatch\Watch-PhpVersion.ps1`. The log is `watch.log` and the state is `state.json` in the same folder. It deliberately lives outside the plugin folder, because the plugin root is what gets deployed.
- **Schedule:** Windows Task Scheduler task "Wegmans PHP Patch Watch", hourly, as the logged-in user. It only runs while you're logged in.
- **Notifications:** Windows notifications for:
  - PHP is patched (once)
  - the version changed but is still vulnerable
  - the check failed (once per failure streak)
  - the check recovered
- **Verified:**
  - The failure path works: env vars missing gives a log line and a notification.
  - The endpoint is reachable and returns JSON 401 without a key. It is not redirected by the front door and not served from Varnish.
  - **The success path is not yet tested.** That needs the key.
- **Remove when done:**
  - `Unregister-ScheduledTask -TaskName 'Wegmans PHP Patch Watch' -Confirm:$false`
  - delete the folder
  - revoke the WC key

### Post-patch checklist (when the watch reports >= 8.2.35)
1. Confirm in `watch.log` that the new version is what the site serves.
2. Re-run the logged-in smoke test: login, cart, checkout, product-request upload, return-form upload.
3. If Cloudways added a temporary multipart block, have them remove it.
4. Decide whether to keep the pool-level ini overrides. Recommendation: keep `session.upload_progress.enabled=off` (unused by the site) and the 32M/20M/10 upload limits. The rest is optional.
5. Plan the 8.2 → 8.3 move before 8.2 EOL on 2026-12-31: clone the app, switch the clone, smoke test, then switch prod.
6. Remove the watch (above) and revoke the WC key.
- **Success path verified at 15:48 local time:** the watch reads `php_version=8.2.34 status=VULNERABLE`. The REST output starts with a UTF-8 BOM, which comes from `modules/musesys_shipping_custom/musesys-shipping-selector.php`; the script strips it. The fix for the BOM itself is added to HANDOFFS.md H3 as item 9.

## 2026-10-21 ~20:05 local: prod patched, and pre-checks for the switch to 8.4
- **Prod is patched.** The watch read `php_version=8.2.35` at 20:04:30 (PATCHED). WC system status also shows the pool overrides are live: `post_max_size` 32M, max upload 20M, WP memory 512M.
- **Watch updated for the branch switch.** It now uses a per-branch fixed minimum (8.2.35 / 8.3.36 / 8.4.27 / 8.5.12); the old logic treated any 8.3+ as patched. A version change also re-arms the "patched" notification. The classification was unit-tested for 8.2.34, 8.2.35, 8.4.10, 8.4.26, 8.4.27, 8.4.28, 8.3.36, 8.1.99 and 8.6.0.
- **Plugin code under 8.4:**
  - All 113 deployed PHP files lint clean with PHP 8.4.10 (`-d error_reporting=-1`). That means no parse errors and no compile-time deprecations, including implicitly nullable parameters.
  - **Runtime-only 8.4 deprecations (non-fatal):** `fputcsv`/`fgetcsv` are called without the `$escape` argument. The server's `error_reporting` excludes E_DEPRECATED, so these are invisible and functionally harmless. Affected files:
    - `MuseSys_exporter/musesys-product-exporter.php:314,322` (plus the `modules/` copy)
    - `modules/musesys-activity-tracker-legacy/index.php:552-553`
    - `modules/musesys-bulkuser-add/musesys-bulkuser-add.php:25,30`
    - `modules/Musesys-order-ops/muse-asskicker.php:375,379`

    This is added to H3 as item 10.
- **Staging on 8.4** (the operator switched it; used as the test bed):
  - `/`, `/wp-login.php` and `admin-ajax` respond normally with no PHP error text. Login-page timing matches prod once warm.
  - The Apache/FPM error log tail has no PHP fatals or warnings. One FastCGI "connection reset" at 19:53 matches the FPM restart during the switch.
  - **Not verified:** staging's exact 8.4 patch level, which must be at least 8.4.27. The WC key doesn't exist in the staging DB (pulled before the key was created), and the Cloudways API was returning 500s. Logged-in flows (checkout, uploads, CSV exports) were not exercised.
  - Side observation: staging runs cron and sends real emails, for example the Ledger summary to staff. Muse Email Logger writes full email bodies, including recipients, into the error log.
- **After the prod switch:**
  1. The watch (or a manual run) must show `8.4.x` with x ≥ 27.
  2. Re-check that WC system status still shows `post_max_size` 32M and upload 20M. The FPM pool overrides must carry over to the 8.4 pool.
  3. Run the logged-in smoke test, plus one CSV export.

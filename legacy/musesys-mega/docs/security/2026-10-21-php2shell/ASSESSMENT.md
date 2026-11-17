# wegmansparts.com — PHP2Shell assessment (2026-10-21)

Lane: assessment, planning and review only. Nothing on the server or in the code was changed.
Fix work goes out through the handoffs in [HANDOFFS.md](HANDOFFS.md).

## 1. The advisory (FACT, from php.net)

- **CVEs:** CVE-2026-109618 (OOM partial-init corruption) and CVE-2026-109619 (stale per-request state / use-after-free).
  Both are CVSS v4 9.2 and allow **unauthenticated RCE in the PHP engine itself**, so clean application code does not protect the site.
- **Affected:** every PHP web SAPI from 5.4 on, including FPM, mod_php, CGI, LiteSpeed and FrankenPHP. Default configurations are vulnerable.
- **Trigger:** any `multipart/form-data` request plus a fatal error during that request (typically `memory_limit` OOM or a timeout).
- **Fixed in:** 8.5.12, 8.4.27, 8.3.36, 8.2.35 (released 14:00 UTC today).
  - 8.1 and older get no official fix (Cloudways says it covers these through ELTS).
  - 8.2 reaches end of life on 2026-12-31.
- **Exploitation:** Cloudflare saw attacks at 07:43 UTC, before the release. A public exploit tool exists.
- **Interim mitigations.** These reduce risk but do not make a site safe. Each must be set at server or pool level, because `ini_set` and `.user.ini` are not enough:
  - `session.upload_progress.enabled=0`
  - a `memory_limit` that applications cannot override
  - smaller `post_max_size` / `upload_max_filesize` / `max_file_uploads`
  - request size limits at the proxy
  - fix fatal errors and timeouts that an attacker can reach

## 2. Production stack (FACT unless marked)

| Item | Finding | Source |
|---|---|---|
| Host | Cloudways server 1564610, app `vkbtktbvgp`; nginx → Apache backend → PHP-FPM (`php-app.*` FPM logs) | log files in repo root |
| Edge | No Cloudflare in front: no `cf-*` headers, `Server: nginx`, Varnish `X-Cache: HIT` | live HEAD request |
| PHP version | **UNKNOWN.** At least 8.1 (`Passing null … deprecated` warnings). The version is hidden from headers. | debug.log |
| Cloudways response | Status page: "Critical PHP RCE — Monitoring & Mitigations Underway" (09:16 UTC). Platform and API under critical maintenance 13:35 to about 16:05 UTC. | status.cloudways.com |
| Cloudways API / MCP | Returns the status-page payload instead of data. **The maintenance window explains this, not the missing key.** | MCP call |
| Third-party plugins in use | WooCommerce, wpforms-lite, wp-mail-smtp, relevanssi, 3× WebToffee import/export, object-cache-pro | debug.log / slow log paths |
| Direct `.php` hits into `plugins/musesys-mega/` | **403** (Cloudways blocks direct execution). This covers dev scripts, `safe/`, unguarded modules and `debug.log`. | live HEAD probes |

Cloudways is responsible for patching the PHP binary. We are responsible for:
1. Verifying the patched version is actually running.
2. Confirming the server-level ini mitigations.
3. Removing code paths that let an attacker cause a fatal error.

## 3. Findings, ranked

### CRITICAL: live today, not PHP-related, found during this sweep
**F1. Customer order history is publicly downloadable.**
- URL: `https://wegmansparts.com/wp-content/plugins/musesys-mega/modules/muse-order-history/data/wegmans_order_history.json`
- Response: `200 application/json`, 609,460 bytes. Fields include `email`, `name`, `ship_address`, `ship_city_state_zip`, `order_number`, `work_order` and `tracking`.
- The `.htaccess` deny in `data/` does nothing, because nginx serves static files on Cloudways.
- Varnish caches the file (`s-maxage=2592000`, `X-Cache: HIT`), so the cache must be purged after the fix.
- The copy under `musesys-account-dashboard/…` returns 302; that path probably does not exist in prod.
- → **Handoff H1, do first.**

### HIGH: PHP2Shell exposure
**F2. Server PHP version and ini settings are unverified.** Until we confirm the version is ≥ the fixed release for its branch, treat the site as vulnerable. Any anonymous multipart POST to `/wp-login.php` or `/wp-admin/admin-ajax.php` reaches PHP, and the front-door gate runs too late to help. → H2

**F3. Requests that can force OOM or a timeout, combined with lifted limits.**
- `modules/muse-wo-consolidation/muse-wo-consolidation.php:199-222`: `wp_ajax_muse_wo_*` handlers with no capability check and no nonce. They set `ini_set('memory_limit','512M')` and `set_time_limit(60)`, load every order ID, and take a caller-controlled `limit`. Any logged-in customer can trigger them.
- `modules/muse-exporter/index.php:45-67`: REST `/musesys/v1/export-orders` uses `permission_callback => '__return_true'`; `export_all=1` loads every `WC_Order` object.
  - Only the front-door mu-plugin's anonymous REST block protects it.
  - Any logged-in customer can export every order, including PII and costs.
- These are exactly the "attacker-reachable fatal" setups the advisory says to remove.
- → H3

### MEDIUM
**F4.** More `__return_true` REST routes, which let any logged-in user read or change data belonging to others:
- `muse-aq-exporter/index.php:13-16`: any order, including `_purchase_price`.
- `muse-product-request/index.php:2737-2761`: create, edit, or complete any request; GET is unbounded.

→ H3

**F5.** Upload handlers have no size or count caps:
- `RETURN_REQUEST_FORM/return_request_form.php:305-345` has no limits. Sending a non-array field causes a TypeError fatal.
- `muse-product-request` (`:1508-1587`) writes files before checking the 20 MB total, and checks file type by extension only. It also has a useless `nopriv` hook.

→ H3

**F6.** `musesys-login-guard`:
- It calls `sleep(2-5)` on anonymous wp-login requests (`:726-744`), and the slow log shows this pinning FPM workers.
- It trusts the `X-Forwarded-For`, `CF-Connecting-IP` and `True-Client-IP` headers (`:257-262`), so attackers can spoof their IP.

→ H4

### LOW: defense in depth (direct hits already return 403)
**F7. Deploy hygiene.**
- No `ABSPATH` guard in several modules (`Muse-email-enforcer`, `muse-user-fields`, `muse-email-manager`, report and email templates).
- Dev scripts sit in the plugin root: `string_refactor.php`, `test_loader.php`, `generate_loader.php`.
- `safe/` is a full duplicate plugin.
- `modules/musesys-ledger/tests/` runs code.
- `mu-plugins/local-by-flywheel-live-link-helper.php` enables host-header poisoning if it is deployed. It was not seen in prod paths, so verify.
- Several large files sit in the plugin root: `debug.log`, `*.access.log`, `php-app.slow.log`.

→ H5

**F8. Prod debug.log is noisy before `init`.** It contains `ltrim(null)` deprecations, `Undefined array key REQUEST_METHOD` warnings, and `is_internal_meta_key` misuse. The advisory lists diagnostics emitted before initialization as a trigger for CVE-2026-109619. Keep `WP_DEBUG_DISPLAY` off and clean these up. → H3 (tail item)

**F9. Local tooling is behind.**
- `C:\php` is 8.5.1 ZTS; Herd-lite is 8.4.0; Local WP bundles PHP 7.4.30, 8.2.27 and 8.4.10.
- Nothing was listening at check time, so the risk is localhost-only.
- **Exception:** Local WP "Live Links" tunnels a local site to the internet. Do not use it until Local ships patched PHP.

→ H6

## 4. Upgrade recommendation

**Yes, upgrade.** Ask Cloudways to confirm, or set from the console, the PHP version for server 1564610:

| Current version | Action |
|---|---|
| 8.3 or 8.4 | Make sure today's patch (8.3.36 / 8.4.27) is applied. No branch change is needed. |
| 8.2 | Patch to 8.2.35 now. Plan the move to 8.3 or 8.4 before 2026-12-31 (EOL). |
| 8.1 or older | Move to **8.3** (the safest jump for WooCommerce plus custom code). ELTS is a stopgap, not a plan. |

Before switching branches:
1. Clone the app on Cloudways.
2. Switch the clone's PHP version.
3. Smoke-test login, catalog, cart, checkout, product request, return form and the order-history page.

## 5. Cloudways access

**Recommended: yes, issue a new API key**, but only after the maintenance window closes (about 16:05 UTC). With it, an agent can do read-only checks:
- PHP version and settings via `server_settings_get`
- `app_vulnerabilities_list`
- `service_status`

It can also do two narrow writes: a Varnish/cache purge for F1, and the ini mitigations if Cloudways has not applied them.

Without a key, the operator does these steps by hand in the Cloudways console. They are listed in H2.

## 6. Verification performed in this lane
- php.net release page and advisory text: read.
- Cloudways status page: read.
- Live HEAD probes against our own site:
  - plugin `.php` files return 403
  - the JSON file returns 200; a 2 KB range read listed only the field names
- Subagent code sweep of `modules/` and `mu-plugins/`. These top findings were spot-checked against the source:
  - F3 `muse-exporter`
  - F3 `wo-consolidation`
  - F7 missing guard in `Muse-email-enforcer`
- **Not verified:**
  - the server PHP version
  - whether prod code matches this working copy (this folder is not a git repo)
  - whether the Flywheel helper or `safe/` is deployed
  - WordPress core version

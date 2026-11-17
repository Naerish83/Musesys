# PHP2Shell response — agent handoffs (2026-10-21)

Each handoff is self-contained. Read [ASSESSMENT.md](ASSESSMENT.md) for the full context.

Rules that apply to every handoff:
- Code lives in `C:\Projects\musesys-mega`. It is not a git repo, so back up every file you touch to the session scratchpad before you edit it.
- Prod is Cloudways app `vkbtktbvgp` on server 1564610. The plugin installs at `wp-content/plugins/musesys-mega`.
- Do not loosen a guard to make something pass.
- Report back using the Changed / Verified / Remaining format.

Order: **H1 → H2 → H3 → H4 → H5 → H6.** H1 and H2 do not depend on each other and can run in parallel.

---

## H1 — URGENT: stop the public order-history JSON leak

**Problem (verified 2026-10-21):**
- This URL returns `200 application/json`, 609 KB of customer order history (email, name, ship address, order and work-order numbers, tracking):
  `https://wegmansparts.com/wp-content/plugins/musesys-mega/modules/muse-order-history/data/wegmans_order_history.json`
- The `.htaccess` deny next to it has no effect, because nginx serves static files on Cloudways.
- Varnish has the file cached for 30 days.

**Reader:** `modules/muse-order-history/muse-order-history.php` (around `:51-64`) reads this file per request behind a nonce.

**Do:**
1. Move the data out of the web root. Two options:
   - Put it under `wp-content/uploads/` in a folder denied in nginx, or better, one level above `public_html`.
   - Better still, put it in a non-autoloaded option or a custom table.
   
   Update the reader path to match. Keep the nonce and login gate, and add a capability or ownership check. The endpoint must return only the current user's or store's rows, never the whole file.
2. Delete the file from `modules/muse-order-history/data/` and from the duplicate `modules/musesys-account-dashboard/muse-order-history/data/`, in both the local copy and on the server.
3. Purge Varnish for the URL, or the whole app, in Cloudways. This needs the operator or a Cloudways API key.
4. Verify: `curl -sI <url>` returns 404 or 403, with no `X-Cache: HIT` serving old content. Then load the order-history page as a normal customer and confirm it still works.
5. Tell the operator to check the access logs for earlier downloads of this path, so they can decide on breach notification. This is a business decision; do not make it.

---

## H2 — Cloudways server: confirm the PHP patch and apply ini mitigations

Needs either a Cloudways console session (operator) or a new API key. The platform and API were under maintenance until about 16:05 UTC on 2026-10-21.

**Do:**
1. Read the server's PHP version (`server_settings_get` for server 1564610, or Console → Server → Settings & Packages). Record it.
2. Confirm the running version is at least the fixed release for its branch: 8.5.12 / 8.4.27 / 8.3.36 / 8.2.35.
   - On 8.1 or older: recommend moving to 8.3. First clone the app, switch the clone's PHP, and smoke-test it (see ASSESSMENT §4). Do not switch prod without operator approval.
3. Whether patched or not, confirm or set these at **server/pool level**. App-level `.user.ini` does not count.
   - `session.upload_progress.enabled = Off`
   - `post_max_size` about 32M, `upload_max_filesize` about 20M, `max_file_uploads` about 10. These match the current 20 MB product-request cap; check the return form needs before lowering further.
   - `memory_limit` set via `php_admin_value`, so `ini_set` cannot raise it (if Cloudways exposes this).
   - `display_errors = Off`.
4. Restart PHP-FPM, then verify the site works: login, cart, checkout, product-request upload, return-form upload.
5. Run `app_vulnerabilities_refresh` and `app_vulnerabilities_list` for the app, and record the output. Also record the WordPress core and plugin versions: WooCommerce, wpforms-lite, wp-mail-smtp, relevanssi, the WebToffee import/export trio, and object-cache-pro.

---

## H3 — Plugin hardening: remove attacker-reachable OOM and timeout paths, and close permissive routes

Back up each file first. Run `php -l` on every edited file.

1. `modules/muse-wo-consolidation/muse-wo-consolidation.php:199-230` (`muse_wo_get_count`, `muse_wo_process_batch`):
   - Add `current_user_can('manage_woocommerce')` and `check_ajax_referer`.
   - Cap `limit` with `min(absint($limit), 50)`.
   - Delete the `ini_set('memory_limit')` and `set_time_limit` calls.
   - Use `wc_get_orders(['limit'=>1,'paginate'=>true])->total` for the count.
   
   The duplicate copy at `modules/musesys-welcome-email/muse-wo-consolidation/` should be deleted after you confirm nothing requires it.
2. `modules/muse-exporter/index.php:45-48`: change the `permission_callback` to `current_user_can('manage_woocommerce')`. Replace `limit => -1` with pagination (`page`/`per_page`, max 200), and return IDs or slim arrays instead of `WC_Order` objects. Find the consumer of `/musesys/v1/export-orders` (likely an exporter script or Cairn) and confirm it authenticates, for example with an application password, before you lock the route.
3. `modules/muse-aq-exporter/index.php:13-16`: give it the same capability gate.
4. `modules/muse-product-request/index.php:2737-2761`:
   - Use a real permission callback per route. The owner can read and update their own requests; only staff can complete them.
   - Bound the GET at `:2862`.
   - Remove the `nopriv` upload hook at `:462-463`.
   - Validate total size and count **before** `wp_handle_upload` (`:1508-1587`), and use `wp_check_filetype_and_ext`.
   - Use `hash_equals` plus `isset` for the `$_GET` id and token at about `:2405-2425`.
5. `modules/RETURN_REQUEST_FORM/return_request_form.php:305-345`: add `is_array` guards and per-file, total and count limits.
6. `musesys_shipping_custom/musesys-shipping-selector.php:200-239` and `musesys_selector/musesys-store-selector.php:725-750`: add `is_string` checks before `trim()` on decoded JSON.
7. `modules/MuseSys_exporter/musesys-product-exporter.php:257-258`: remove the `ini_set` and `set_time_limit` calls and cap the `limit` parameter.
8. Clear the pre-`init` warnings: `ltrim(null)` sources, `REQUEST_METHOD` keys, and `is_internal_meta_key` misuse. Find their origin in the prod `debug.log`.
9. Strip the UTF-8 BOM from `modules/musesys_shipping_custom/musesys-shipping-selector.php`, which is also present in the `safe/` copy. The BOM goes out as output on every request, before WordPress initializes, and it prefixes every REST/JSON response. Verified 2026-10-21: the bytes `EF BB BF` come before `{` on `/wp-json/wc/v3/system_status`. Re-save the file as UTF-8 **without** BOM, then confirm the first byte of a REST response is `{`.
10. (Low, PHP 8.4) Pass an explicit `$escape` argument (`'\'` to keep the current behavior) to `fputcsv`/`fgetcsv` in `MuseSys_exporter/musesys-product-exporter.php:314,322` (both copies), `modules/musesys-activity-tracker-legacy/index.php:552-553`, `modules/musesys-bulkuser-add/musesys-bulkuser-add.php:25,30`, and `modules/Musesys-order-ops/muse-asskicker.php:375,379`. On 8.4 these calls emit E_DEPRECATED, which is non-fatal and hidden by the current error_reporting.

**Verify:**
- As a customer account, every route above returns 401/403 or only that customer's own data.
- As admin, the exporters still work.
- Uploads over the limit are rejected and leave no orphan files in uploads.

### H3 results (2026-10-21, local copy only, not deployed)

Backup of every file before editing: `C:\Users\chenderson\AppData\Local\Temp\claude\C--Projects-musesys-mega\2bb90a00-c5e1-4d51-8fd1-2d66d1496453\scratchpad\h3-backup\` (same relative paths). To revert a file, copy it back.

| # | Status | What changed |
|---|---|---|
| 1 | DONE | `muse-wo-consolidation.php`: both AJAX handlers now require `manage_woocommerce` and a nonce (`muse_wo_consolidation`, sent by the admin page JS). `limit` is `min(absint, 50)`. `ini_set('memory_limit')` and `set_time_limit` removed. Count uses `wc_get_orders(['limit'=>1,'paginate'=>true])->total`. Duplicate `modules/musesys-welcome-email/muse-wo-consolidation/` **deleted** locally (operator approved; backed up first). Nothing loaded it: the loader only globs `modules/*/index.php` and `modules/*/*.php` (one level deep), and no file requires it. |
| 2 | DONE | `/musesys/v1/export-orders`: `permission_callback` is now `current_user_can('manage_woocommerce')`. The admin "Order Exporter" page `fetch()` now sends `X-WP-Nonce` (`wp_rest`) with same-origin credentials. **No pagination**, per the operator: the response shape and `limit => -1` are unchanged. |
| 3 | DONE | `/musesys/v1/export-aq/{id}`: same `manage_woocommerce` gate. |
| 4 | DONE | Route gates (operator decision): `GET` and `POST /product-requests` need a logged-in user, and non-staff `GET` returns only that user's own requests (`author` filter). `POST /product-requests/{id}` (update) is allowed for staff or the request's owner; anyone else gets 403 whether or not the ID exists. `POST /product-requests/{id}/complete` is `manage_woocommerce` only. Denials use `rest_authorization_required_code()`: 401 when logged out, 403 when logged in. Update and complete now return 404 if the ID is not a `musesys_pr` post. Also done: `nopriv` upload hook removed. Upload size (20 MB total) and count (10) are checked across all files **before** anything is written. Type is checked with `wp_check_filetype_and_ext`. Files are written only after all validation passes, and are deleted again if any write fails. Non-array `items` rows are skipped. Confirmation link uses `isset` + `hash_equals`. `GET /product-requests` is bounded to 200 per page (`page`/`per_page`, plus `X-WP-Total`/`X-WP-TotalPages`). |
| 5 | DONE | `return_request_form.php`: new `get_image_files()` caps uploads at 10 images, 10 MB each and 20 MB total. It rejects the whole submission (400) **before** any write, and returns a clear error for `UPLOAD_ERR_INI_SIZE`. A non-array `muse_return_images` field no longer causes a `count()` TypeError. `selected_items` must be a string before `json_decode`, and each selection must be an array. |
| 6 | DONE | `musesys-shipping-selector.php`: `is_string` before `json_decode`. Decoded fields are used only if `is_scalar`, then cast, then trimmed (ints still work). `musesys-store-selector.php`: the same `is_scalar` guard on all three `first_name`/`last_name` trim sites. `$_SERVER['REQUEST_METHOD']` now has `isset` in the favourites shortcode. |
| 7 | DONE | `modules/MuseSys_exporter/musesys-product-exporter.php`: `set_time_limit(300)` and `ini_set('memory_limit','512M')` removed. Both already equal the server values (300 s, 512M pinned). `limit` capped to 1–10000, and the form `max` changed from 50000 to 10000. The page is already gated by `manage_options` and a nonce. |
| 8 | DONE / REPORTED | Prod `debug.log` (April 2026) evidence: (a) `REQUEST_METHOD` warnings came from `muse-product-request/index.php:1050` and `musesys-shipping-selector.php:204` on the **old** prod code; the local code already has `isset`, so deploying fixes them. (b) `is_internal_meta_key "_billing_email"` came from `update_meta_data('_billing_email')` in `place_order_from_request`; the local code already uses `set_billing_email()` (the old version is still in `safe/`). (c) `ltrim(null)` in `wp-includes/formatting.php:4496` (`esc_url`) fires at 11:30 and 20:00 UTC, the morning and afternoon report cron. Cause: `class-pr-report.php` used `get_edit_post_link()`, which returns null when there is no current user (cron), so every PR link in those emails was blank. Fixed with `admin_url('post.php?post=…&action=edit')`. None of these is pre-`init` WP core or third-party code. |
| 9 | DONE | `modules/musesys_shipping_custom/musesys-shipping-selector.php` re-saved without BOM; the first bytes are `<?php`. No other loaded PHP file has a BOM. The `safe/` copy still has one; it is not loaded, and H5 removes `safe/`. Confirming that the REST response starts with `{` needs the deploy. |
| 10 | DONE | Explicit `',', '"', '\\'` passed to every `fputcsv`/`fgetcsv` in `MuseSys_exporter` (modules copy), `musesys-activity-tracker-legacy`, `musesys-bulkuser-add` and `Musesys-order-ops/muse-asskicker.php`. There are no other CSV calls in `modules/`. Checked on PHP 8.4.10: the call without `$escape` emits the deprecation, and the explicit form is silent. The root `MuseSys_exporter/` copy was not edited: it is not loaded, and H5 removes it. |

**Lint.** Every edited file was checked with PHP 8.4.10 (`-n -d error_reporting=-1 -l`) and with `C:\php` 8.5. Both reported only "No syntax errors". A CLI harness (stubbed WP functions, 8.4, E_ALL) exercised the new upload helpers with array, scalar, nested, junk, over-count, over-size, over-total and `INI_SIZE` inputs: 12/12 pass, and no notices or deprecations when the files are included. A second harness ran the product-request permission callbacks for anonymous, customer, owner and staff users: 12/12 pass (anonymous 401; non-owner customer 403, including for a missing ID; owner may update their own request but not complete it; staff may do both). No WordPress runtime test was done (there is no local WP copy of this site).

**Not done in H3:** `Muse-email-enforcer` writes every outgoing email (recipients and body, which include customer PII) to `debug.log`. That is a separate follow-up.

#### Route consumers and the operator decision (items 2, 3, 4)
Operator decision, 2026-10-21: UACP's app-password user is an Admin/Shop Manager. Gate the routes to `manage_woocommerce`; the owner may read and update their own requests; only staff may complete. Add the REST nonce to the Order Exporter page. Skip pagination for now. Delete the duplicate. Implemented as described in the table above.

Consumers found:
- **UACP desktop app** `C:\Projects\UACP\app\api_client.py`. It calls `GET export-orders` (60-day `start_date`/`end_date` chunks, no paging), `GET export-aq/{id}`, `GET product-requests`, and `POST product-requests/{id}/complete`. It authenticates with Basic auth using a WP application password (`wp_username`/`wp_app_password`). For export-orders and export-aq, that auth is sent only if configured.
- **UACP musesys MCP server.** It calls the same routes and can still send `export_all=true`. It falls back to WC consumer keys, which do not authenticate a WP user on custom routes.
- **In-plugin admin "Order Exporter" page** (`muse-exporter/index.php:421`). It calls `fetch()` with no `X-WP-Nonce`, so it breaks once the route checks a capability. It needs a `wp_rest` nonce header.
- **Nobody calls** `POST /product-requests` (create) or `POST /product-requests/{id}` (update). Cairn/GerharzDB does not call any of these routes.
- Access logs (27–28 May): one IP, `python-requests` (UACP), no 401s.

Known side effect: any UACP build or MCP config **without** an application password (export-orders and export-aq send auth only if one is configured, and the MCP server falls back to WC consumer keys) now gets 401 on these routes. `export_all=true` from the MCP server still works for staff, and still loads every order (pagination deferred).

#### Deploy list (prod `vkbtktbvgp`, `public_html/wp-content/plugins/musesys-mega/`)
Upload in this order. Each file is self-contained, so a partial deploy is safe.
1. `modules/musesys_shipping_custom/musesys-shipping-selector.php` (BOM: the biggest visible effect)
2. `modules/musesys_selector/musesys-store-selector.php`
3. `modules/muse-wo-consolidation/muse-wo-consolidation.php`
4. `modules/RETURN_REQUEST_FORM/return_request_form.php`
5. `modules/muse-product-request/index.php`
6. `modules/MuseSys_exporter/musesys-product-exporter.php`
7. `modules/musesys-reports/includes/class-pr-report.php`
8. `modules/musesys-activity-tracker-legacy/index.php`
9. `modules/musesys-bulkuser-add/musesys-bulkuser-add.php`
10. `modules/Musesys-order-ops/muse-asskicker.php`
11. `modules/muse-aq-exporter/index.php`
12. `modules/muse-exporter/index.php` (the route gate and the page nonce live in this one file, so they ship together)
13. On the server, delete the folder `modules/musesys-welcome-email/muse-wo-consolidation/` if it exists. It is not loaded, so the order does not matter.

#5, #11 and #12 change who can call the REST routes. Before uploading them, confirm that UACP has its app password configured (Settings → `wp_username` / `wp_app_password`).

Then purge Varnish/object cache. **Caution:** prod `muse-product-request/index.php` is older than the local copy (it still has `update_meta_data('_billing_email')`), so uploading #5 also ships every other local change made since April. Diff prod against local before you upload it.

#### Manual test checklist (H3 Verify)
- [ ] `curl -s https://wegmansparts.com/wp-json/ | head -c 3`: the first byte is `{`, not `EF BB BF`.
- [ ] As a **customer**, POST `admin-ajax.php?action=muse_wo_get_count` (and `muse_wo_process_batch`). Expect `403`/`-1`.
- [ ] As **admin**, MuseSys → WO Consolidation → Start. The count appears and batches run.
- [ ] As **admin**, MuseSys → Product Exporter, skip 0 / limit 10000. The CSV downloads and opens with correct quoting.
- [ ] Activity tracker legacy CSV export, asskicker CSV export, and a bulk-user CSV import all still work.
- [ ] Product request (customer): submit with 1–3 photos (include one iPhone HEIC and one PDF). Expect success and images on the request.
- [ ] Product request: 11 files, or more than 20 MB total. Expect an error, and **no new files** under `wp-content/uploads/YYYY/MM/` (compare the listing before and after).
- [ ] Product request: valid photos plus an invalid WO #. Expect an error and no new files in uploads (before this change, files were written first).
- [ ] Product request: a `.jpg` that is actually a text file. Expect "must be an image or PDF".
- [ ] Logged out, POST `admin-ajax.php?action=submit_product_request`. Expect `0` (no handler).
- [ ] Direct-order confirmation link: valid token places the order; a wrong token, or a missing `id`/`token`, gives "Invalid or expired" with no PHP warnings in `debug.log`.
- [ ] UACP Product Requests view still lists pending/processing requests, and Complete still works (the GET now caps at 200 per page).
- [ ] Return form: 3 photos submit. 11 photos, or one photo over 10 MB, are rejected with a message and no new files in uploads.
- [ ] Store/shipping selector: picking a store still fills checkout name, "Store #N" and address.
- [ ] Next 07:30/16:00 ET report email: PR links point to `post.php?post=…&action=edit`, and no `ltrim()` deprecation appears in `debug.log`.
- [ ] Logged out, `curl -s -o /dev/null -w '%{http_code}' https://wegmansparts.com/wp-json/musesys/v1/export-orders?start_date=2026-10-01`: expect 401. Repeat for `export-aq/<id>` and `product-requests`.
- [ ] As a **customer** (browser console on the site: `fetch('/wp-json/musesys/v1/export-orders?start_date=2026-10-01',{headers:{'X-WP-Nonce':wpApiSettings?.nonce}})`, or Basic auth with a customer app password): `export-orders`, `export-aq/<id>` and `product-requests/<id>/complete` return 403. `GET product-requests` returns only that customer's own requests. `POST product-requests/<someone else's id>` returns 403.
- [ ] As **admin**, MuseSys → Order Exporter: a date-range export and Export All both download a CSV (this proves the nonce header works).
- [ ] UACP (app-password user): Export Orders, Export AQ, the Product Requests list, and Complete all return 200 and behave as before.

---

## H4 — Login guard: stop pinning FPM workers, and stop trusting spoofable headers

File: `modules/musesys-login-guard/musesys-login-guard.php`.
- `:726-744` and around `:903`: remove `sleep()`. Replace it with an immediate 429 or a generic failure response, and keep the counters.
- `:257-262`: use `REMOTE_ADDR` only, unless a trusted-proxy constant is defined. Cloudways nginx sets `X-Forwarded-For`; if you rely on that header, take only the address the proxy appended and only when `REMOTE_ADDR` is the local proxy.
- Re-check the honeypot block at `:855-857` against the new IP source.

**Verify:** a failed login returns in under 500 ms; a blocked IP stays blocked when a forged `X-Forwarded-For` is sent; normal login works.

---

## H5 — Deploy hygiene (defense in depth; direct hits already return 403)

- Add `defined('ABSPATH') || exit;` to every PHP file without one. The sweep listed:
  - `Muse-email-enforcer`
  - `muse-user-fields`
  - `muse-email-manager` and its `templates/*`
  - `musesys-reports/templates/*`
  - `muse-exporter/musesys-reports/templates/*`
  - `musesys-dashboard/index.php`
- Remove from the deployed plugin:
  - `safe/`
  - `string_refactor.*`, `test_loader.php`, `generate_loader.php`
  - `loader_array*.txt`, `mega-consts.txt`, `refactor_error*.txt`
  - every `*.log`
  - `modules/musesys-ledger/tests/`
  - the root `MuseSys_exporter/`, `muse-aq-exporter/`, `musesys-reports/` and `order-history-updater/` duplicates, after confirming they are not loaded
- Confirm `mu-plugins/local-by-flywheel-live-link-helper.php` is **not** on the server. Remove it if it is.
- Ask the operator whether they want a deploy allowlist (for example a build script that copies only `musesys-mega.php`, `modules/**` minus tests, and `mu-plugins/*` minus the Flywheel helper). Only build it if they say yes.

---

## H6 — Local tooling

- Update `C:\php` (8.5.1) to 8.5.12, and Herd-lite PHP (8.4.0) to 8.4.27.
- Update Local WP when it ships patched lightning-services. It currently has PHP 7.4.30, 8.2.27 and 8.4.10; remove 7.4 if no site uses it.
- Until then, **do not enable Local "Live Links"**, which exposes the local PHP to the internet.
- Low priority: nothing was listening on the network at assessment time.

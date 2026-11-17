# H1 closeout + H2 handoff (2026-10-21)

## H1 state (verified)
- Leak URL `.../modules/muse-order-history/data/wegmans_order_history.json` now returns **403, `X-Cache: MISS`** (also with a cache-busting query string).
- Operator moved the file to `/home/master/applications/vkbtktbvgp/private_html/muse-order-history/`, uploaded the new reader, and purged Varnish.
- `chmod 640` failed with "Operation not permitted". The file is owned by the app user, not `master`. It is harmless because `private_html` is not web-served.
- Local code changes and backups: see the H1 session report and [H1_OPERATOR_STEPS.md](H1_OPERATOR_STEPS.md).

## H1 leftovers for the server session
1. `ls -la` the `private_html/muse-order-history/` folder. Confirm the file is 609,460 bytes and that the app user can read it.
2. Confirm the prod `modules/muse-order-history/muse-order-history.php` matches the local copy. It should contain `muse_oh_data_file_is_private`. Check that the PHP error log has no `muse-order-history: refusing` lines.
3. Confirm that no `wegmans_order_history*.json` remains anywhere under `public_html`. Use `find public_html -name 'wegmans_order_history*'`.
4. Breach evidence: grep every available access log (`/home/master/applications/vkbtktbvgp/logs/`, including rotated ones) for `wegmans_order_history`. Report each IP, timestamp, status and byte count. A 200 of about 609 KB is a full download.
   - Note the oldest log date available, because the exposure window starts around 2026-04-27.
   - Varnish hits may not appear in backend logs.
   - Report only. The notification decision belongs to the operator.

## H2 (from HANDOFFS.md)
Run H2 as written in [HANDOFFS.md](HANDOFFS.md) using the Cloudways MCP tools: PHP version, pool-level ini, `app_vulnerabilities_*`, and WP/plugin versions.
- Read-only by default.
- Get operator approval before any server write. That covers ini changes, PHP-FPM restart, and PHP version switches.
- Never switch prod PHP without a cloned-app smoke test first.

## Also worth checking while connected (report only)
- The H5 deploy-hygiene items: whether `safe/`, `mu-plugins/local-by-flywheel-live-link-helper.php`, `*.log`, and `order-history-updater/` exist on the server.

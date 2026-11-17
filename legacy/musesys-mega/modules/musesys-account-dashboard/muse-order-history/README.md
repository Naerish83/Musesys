# muse-order-history

WooCommerce account tab showing legacy order history imported from the pre-migration portal.

## Installation
1. Drop `muse-order-history/` into `wp-content/plugins/`
2. Activate via WP Admin → Plugins
3. Visit Settings → Permalinks and hit **Save** to flush rewrite rules

## Data
- `wegmans_order_history.json` — 955 orders, filtered at runtime by logged-in user email
- Contains customer PII. It must NOT live in the plugin folder: nginx serves static files
  directly on Cloudways and ignores `.htaccess` (this leaked publicly until 2026-10-21).
- Default location: `<app>/private_html/muse-order-history/wegmans_order_history.json`
  (sibling of `public_html`). Override with `define( 'MUSE_OH_DATA_FILE', '/abs/path.json' )`
  in `wp-config.php`. The reader refuses any path inside `ABSPATH` or `WP_CONTENT_DIR`.
- To refresh: replace the JSON file at that location (same schema), no code changes needed

## Updating Order Data
Re-run the Python conversion script against the new xlsx and replace the JSON file.
Schema: array of order objects — see existing file for field reference.

## Version
1.0.0

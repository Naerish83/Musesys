# MuseSys Product Exporter

Smart WooCommerce product exporter with auto-discovered custom meta and chunked CSV downloads.

---

## Features

- **Auto-discovers custom meta fields** from your products (no manual declaration needed)
- **Core field selection** (ID, SKU, Title, Description, Price, Stock, Status, Brand)
- **Brand filter** using `product_brand` taxonomy
- **Status filter** (Any, Published, Draft, Pending)
- **Chunked exports** with Skip/Limit controls for handling 200k+ product datasets
- **System key blocklist** automatically filters out WC/WP internal meta keys

---

## Installation

Drop the `MuseSys_exporter/` folder into `/wp-content/plugins/` and activate.

Menu appears under **MuseSys → Product Exporter**

---

## Usage

### 1. Select Columns

**Core Fields** — always available, checked by default:
- Product ID, SKU, Title, Description, Price, Stock Quantity, Status, Brand

**Custom Meta Fields** — auto-discovered from your products:
- Any meta key that doesn't start with `_` and isn't in the blocklist
- Use "Select All Meta" / "Deselect All Meta" buttons for bulk selection

### 2. Apply Filters

- **Brand:** Dropdown populated from `product_brand` taxonomy (shows count)
- **Status:** Any / Published / Draft / Pending

### 3. Set Chunking

- **Skip:** Offset from the beginning (e.g., 0 for first batch, 10000 for second)
- **Limit:** Max products per export (default 10000, max 50000)

**Example chunking workflow for 200k products:**
1. Skip: 0, Limit: 10000 → exports products 1-10k
2. Skip: 10000, Limit: 10000 → exports products 10k-20k
3. Skip: 20000, Limit: 10000 → exports products 20k-30k
... and so on

### 4. Export CSV

Click "Export CSV" — file downloads immediately as `products-export-YYYY-MM-DD-HHmmss.csv`

---

## Blocked Meta Keys

The following WC/WP system keys are automatically filtered out:

**Prefixes:**
- `_transient`
- `_wc_`
- `_yoast`
- `_aioseop`
- `_edit_`
- `_thumbnail`
- `_wp_`

**Exact matches:**
- `total_sales`
- `product_version`
- `ratings_users`
- `wc_average_rating`
- `wc_review_count`

To customize the blocklist, edit the `get_product_meta_keys()` method in the plugin file.

---

## Notes

- CSV encoding is UTF-8
- Brand column shows all assigned brands as comma-separated list if multiple
- Empty meta values export as blank cells
- Query is sorted by Product ID (ASC) for consistent chunking

---

## Troubleshooting

**Meta keys not appearing:**
Check if they start with `_` — these are filtered out by default.
If you need underscore-prefixed keys, modify the SQL query in `get_product_meta_keys()`.

**Export times out:**
Reduce the Limit value. For very large datasets, use 5000 or less per chunk.

**Wrong brands showing:**
Confirm taxonomy slug is `product_brand`. If using a different brand taxonomy, update the slug in:
- `get_brands()` method
- `handle_export()` tax_query
- Core field switch case for 'brand'

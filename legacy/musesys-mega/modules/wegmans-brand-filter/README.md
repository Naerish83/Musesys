# Wegmans Brand Filter

Two-stage brand picker funnel for the WooCommerce shop page.
Replaces the default result-count + ordering bar entirely.

---

## How it works

1. Shop page loads → brand picker renders, product grid is **suppressed**
2. Tech selects a brand → URL updates to `?brand=acme-co` → page reloads
3. Brand is active → product grid renders, scoped to that brand
4. Relevanssi search (if used) stays inside the active brand via `relevanssi_modify_wp_query`
5. "Clear" link removes the param and returns to the gated state

URL state example:
  - No brand:       `/shop/`
  - Brand only:     `/shop/?brand=acme-co`
  - Brand + search: `/shop/?brand=acme-co&s=hydraulic+pump`

---

## Installation

Drop the entire `wegmans-brand-filter/` folder into `/wp-content/plugins/`
then activate via WP Admin > Plugins.

---

## Config (top of .php file)

```php
define( 'GE_BRAND_TAXONOMY', 'product_brand' ); // Brands for WooCommerce plugin // ← change if your taxonomy slug differs
define( 'GE_BRAND_PARAM',    'brand'    ); // ← URL param key
```

To find your actual taxonomy slug:
```bash
wp taxonomy list --format=table
# or
wp term list pa_brand --fields=slug,name,count --format=table
```

---

## Taxonomy note

Built assuming WooCommerce product attribute `pa_brand`.
If your Brand is a custom taxonomy (slug `brand`), change the constant above to `'brand'`.
If it's `product_cat` or a tag — don't do that, taxonomy is cleaner.

---

## Files

```
wegmans-brand-filter/
├── wegmans-brand-filter.php   ← main plugin, all hooks
└── assets/
    ├── brand-filter.css       ← Tom Select overrides + gate styles
    └── brand-filter.js        ← Tom Select init + onChange nav
```

---

## Relevanssi compatibility

- **Free:** Hook `relevanssi_modify_wp_query` should work; if not, try `relevanssi_search_filters`
- **Premium:** `relevanssi_modify_wp_query` is confirmed supported
- The brand tax_query is injected *before* Relevanssi runs its query, so results are always brand-scoped

---

## Known edge cases

- Pagination: `paged` param is cleared on brand change to avoid stale page 2+ of wrong brand
- The grid suppression uses `post__in => [0]` (impossible ID) — this is intentional and WP-safe
- CDN for Tom Select: if your environment blocks CDNs, download and self-host the Tom Select files


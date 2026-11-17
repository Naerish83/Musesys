# musesys-general — Port Plan

2026-11-12 · Owner: Cole

## Summary

Marketing's proposal ("Order Guide Ship-To Locations — Development Plan", Nov 12) is a new
custom plugin, built to sit alongside a set of commercial plugins, that gives Dunkin users
a dropdown of approved ship-to locations at checkout.

Most of that already exists in musesys-mega, running in production for Wegmans: the
location list, the locked picker, server-side address enforcement, the address snapshot on
each order, and display in admin, emails and My Account. musesys-mega is also where the
record keeping and tracking live (fulfillment ledger, partial shipments, tracking numbers,
logistics statuses, TRX/PO fields).

**Decision:** we will not build a separate Ship-To plugin. We'll port musesys-mega into
**musesys-general**, a customer-agnostic build in which:

- every customer order guide gets the same record keeping, order and shipment tracking,
  and simple checkout;
- everything customer-specific is configuration, not code;
- Wegmans becomes a special case: the general core plus a Wegmans extension pack for the
  pieces only Wegmans uses.

Dunkin is the first non-Wegmans tenant: 92 locations and 1,936 parts on the guide. That's
a small fraction of Wegmans' scale.

## Why not the proposed plugin

### It rebuilds what we already have

| Proposal component | Already in musesys-mega |
|---|---|
| Location list | `musesys_addresses_stores` / `_warehouses` options (`modules/musesys_selector`, `modules/musesys_address_api`) |
| Required ship-to dropdown | `[wegmans_store_selector]`, `[musesys_favorite_selector]` |
| Last-used location pre-selected | `musesys-store-selector.php:412` |
| Address cannot be edited | `modules/muse-shipping-lock`, read-only fields in `musesys-checkout-glue.php:38` |
| Server overwrites address from the record | Session built from server data only; posted data overwritten at `musesys-checkout-glue.php:75` |
| Rates and tax from that address | `woocommerce_before_calculate_totals` sets customer shipping (`musesys-checkout-glue.php:94`) |
| Order record and address snapshot | `_musesys_*` order meta (`musesys-checkout-glue.php:111`) |
| Shown on admin order screen, emails, order history | `musesys-checkout-glue.php:154–198` |
| Block checkout until a location is chosen | `template_redirect` gate (selector and glue) |
| Admin manager | Shipping Manager (`modules/musesys_shipping_custom`) |

The proposal also leaves out what an order guide actually needs after checkout, and what
musesys-mega already provides: the fulfillment ledger, partial shipments, tracking numbers,
logistics statuses, TRX/PO enforcement, and the order editor's audit trail.

### Mixing custom code with commercial plugins we don't fully understand

The proposal assumes the custom plugin sits alongside the order guide site's existing
commercial plugins. Nobody has inventoried what those plugins hook into. Checkout is the
most contested surface in WooCommerce: field filters, posted-data filters, Store API
handlers, and email hooks are where plugins silently override each other. A ship-to plugin
that rewrites the shipping address is exactly the kind of code that breaks quietly when
another plugin touches the same hooks.

musesys-general keeps checkout behaviour in code we own and have read end to end. Any
commercial plugin that stays has to justify itself with a known, documented footprint.

## Architecture

```
musesys-general (core)
  ├── Guide model        guides, user→guide assignment, per-guide config
  ├── Locations          per-guide ship-to table, admin + CSV import
  ├── Checkout           locked ship-to, billing profile, payment terms, gate
  ├── Order record       address snapshot, guide/location/terms meta
  ├── Tracking           ledger, partial ship, tracking core, logistics statuses
  ├── Ops/admin          order fields, columns, order editor, reports, email manager
  └── Site               front door (members-only), hide prices, login guard
musesys-wegmans (extension pack, Wegmans site only)
  ├── Work Order # / Install Store # (000–200) checkout fields
  ├── Transfer items, brand filter, custom gaskets
  ├── Legacy order history import, welcome email, product request bar
  └── Wegmans config: billing profile, branding, report recipients
```

Rules:

- **Core never names a customer.** No customer names, addresses, page IDs, colours or
  recipient lists in core code.
- **Per-guide settings live in config.** One guide record holds the billing profile,
  payment terms, location label format, and report recipients.
- **Extensions hook into core.** Wegmans adds its fields through core's checkout and
  order-meta hooks; it doesn't fork them.
- **Multisite-safe by default.** All guide data is per-site: options and tables use the
  site's `$wpdb->prefix`, and user assignments are site-scoped user meta.

## Module inventory

Line counts and Wegmans references were counted on 2026-11-12.

### Core as-is (no customer coupling found)

| Module | Role |
|---|---|
| `musesys-ledger` (3,487 lines) | Fulfillment-obligation ledger |
| `musesys-partial-ship` | Multi-shipment management and tracking UI |
| `mu-plugins/muse-tracking-core.php` | Tracking number core |
| `muse-logistics-manager` | Custom order statuses, logistics, customer notifications |
| `order-fields` | TRX/PO fields, block completion if missing |
| `muse-columns`, `musesys-workorder-column` | Admin order list columns |
| `muse-email-manager`, `Musesys-Email-Logger`, `Muse-email-enforcer` | Email templates, logging |
| `muse-notification-preference`, `order_history_repeat`, `muse-clear-cart`, `musesys-checkout-images` | Customer UX |
| `musesys-login-guard`, `mu-plugins/musesys-hide-prices.php`, `Muse-hide-prices` | Site security, price hiding |
| `muse-active-cart-tracker`, `musesys-activity-tracker-legacy` | Activity monitoring |
| `MuseSys_exporter`, `musesys-bulkuser-add`, `favorites-bridge`, `musesys-manual-search`, `musesys-toc` | Admin utilities |

### Core after generalizing

| Module | What changes |
|---|---|
| `musesys_selector`, `musesys_address_api`, `musesys_shipping_custom`, `musesys_checkout_glue`, `muse-shipping-lock`, `musesys-zip-normalizer`, `musesys_favorites` | Merge into one **Locations + Checkout** module, scoped by guide (see "New work") |
| `mu-plugins/musesys-billing-lock.php` | Hardcoded Wegmans HQ address → per-guide billing profile |
| `mu-plugins/musesys-front-door.php` | Rename; add site-membership check for multisite |
| `muse-checkout-orchestrator` | Split: generic parts (contact phone, field cosmetics, order notes) go to core; WO/Install Store go to the Wegmans pack |
| `musesys-order-editor` | Remove "Wegmans" from class name, menu and audit notes; logic is already generic |
| `musesys-reports`, `muse-exporter` | Report titles and recipient lists → per-guide config |
| `muse-product-request`, `musesys-no-results-request-injector` | Hardcoded recipient lists → config |
| `musesys-account-dashboard` | Branding (colours, copy) → config |
| `RETURN_REQUEST_FORM` (quick order by SKU) | Remove the hardcoded URL |
| `musesys-api` (UACP) | Make the Wegmans transfer-item meta key an extension filter |

### Wegmans pack only

`musesys-wegmans-transfer-items`, `wegmans-brand-filter`, `custom-gaskets`,
`muse-order-history` (legacy JSON archive), `musesys-welcome-email`,
`musesys-product-request-bar`, `Musesys-order-ops` (Install Store dropdown, 000–200),
`muse-wo-consolidation` and `mu-plugins/muse-wo-migration.php` (one-off migrations),
`muse-asset-importer` and `muse-asset-profile` (asset products).

### Don't port

`musesys-favdebug`, `musesys-dashboard` (6 lines), `musesys-activity-tracker`. That last
one is misnamed: its header describes Prop 65 warnings. Check whether that's live before
dropping it. Also drop `MuseSys-AfterOrder` once nothing calls the legacy hooks, and
`mu-plugins/local-by-flywheel-live-link-helper.php` (local dev only).

## Hardcoding to remove

| Where | What's hardcoded | Replace with |
|---|---|---|
| `musesys-billing-lock.php:13` | Wegmans HQ billing address on every order | Guide billing profile |
| `musesys-store-selector.php:164`, `musesys-checkout-glue.php:10`, others | Selector page `/167249-2/` | Plugin-registered endpoint or settings page ID |
| Selector and glue session builders | Source data has the store number in `first_name`; it gets swapped and prefixed with "Store #" | Explicit location fields (`code`, `label`); label format set per guide |
| `musesys_address_api` | Detects the name/number swap by checking `is_numeric()` | Removed: the location table has typed columns |
| Selector `__key` | Locations referenced by array position, so edits can re-point saved favorites and sessions | Stable location ID (table primary key) |
| `musesys-checkout-glue.php:42` | Shipping speed list, including "Pick Up at Gerharz" | Per-guide option list |
| `musesys-checkout-glue.php:33`, `:88` | Country fixed to `US` | Location record's country (default US) |
| `musesys-reports.php:22–28`, `muse-product-request/index.php:1827`, `:3277` | Internal recipient email lists | Settings, per guide |
| Report templates, dashboard CSS, welcome email | "Wegmans" titles, colours, copy | Guide config |
| `muse-checkout-orchestrator` header comment, `musesys-wegmans-transfer-items` | "Single-site only" assumptions | Per-site storage; no cross-site reads |

## New work

This is the part of the proposal that's genuinely new. It's all small.

1. **Guide model.**
   - A guide record per site: name, billing profile, payment terms text, location label
     format, shipping speed options, report recipients.
   - User assignment as site-scoped user meta (`{prefix}musesys_guide`), set on the
     user-edit screen and through bulk user CSV import.
   - Users with no guide, a guide with no active locations, or no membership of the site
     are blocked at checkout with a "contact us" message.
2. **Locations table.**
   - `{prefix}musesys_locations` with columns `id, guide_id, code (PC #), group, label,
     company, address_1, address_2, city, state, postcode, country, phone, active,
     created_at, updated_at`, unique on `(guide_id, code)`.
   - Locations are deactivated, never deleted.
   - A one-time migration loads Wegmans' existing options into the table, so the Wegmans
     site runs on the same mechanism.
3. **Locations admin.**
   - List, add, edit, deactivate.
   - CSV import keyed on `code`, with dry-run preview: insert / update / unchanged /
     rejected.
   - Access is controlled by a dedicated capability (`musesys_manage_locations`), granted
     to Shop Admin.
4. **Checkout.**
   - One locked ship-to step driven by the location ID, with last-used location stored
     per user per site.
   - The server resolves the location ID and rejects it if it's inactive or on another
     guide, then writes the address from the record.
   - Payment terms are shown at the top of checkout and saved on the order. No payment
     gateway.
   - **Decision needed:** the proposal says block checkout. Every existing hook
     (`woocommerce_checkout_fields`, `_process`, `_posted_data`, `_create_order`) is
     classic-checkout only. Recommendation: standardize musesys-general on the classic
     checkout shortcode, which is fewest moving parts and already proven. Block checkout
     means re-implementing enforcement on Store API hooks
     (`woocommerce_store_api_checkout_update_order_from_request`) plus a checkout block
     extension for the dropdown.
5. **Order record.** Add `_musesys_guide_id`, `_musesys_location_id`, `_musesys_location_code`,
   `_musesys_location_group` and `_musesys_payment_terms` alongside the existing address
   snapshot meta. Admin, email and My Account display read from the snapshot, so later
   edits or deactivations never change past orders.

## Dunkin, first tenant

- **Guide:** Dunkin. 92 locations (78 NY, 12 ME, 2 NH) across 8 franchise groups; label
  format `{group} – {label} (PC {code})`.
- **Catalog:** 1,936 parts on the guide.
- **Billing profile and payment terms:** to be supplied. These replace the Wegmans billing
  lock on that site.
- **Data open items** (carried over from marketing's plan, still valid):
  - PC 335034 (Gorham ME) is listed with a Massachusetts city and zip; confirm.
  - PC 350392 (Whitney Point): confirm the street address can receive carrier deliveries
    now that the PO box is removed.
  - The sheet was last updated July 2021; confirm the store list is current.
  - Confirm shipping zones cover ME and NH (14 stores).

## Rollout

1. Create `musesys-general` from musesys-mega. Move Wegmans-only modules into
   `musesys-wegmans`.
2. Remove the hardcoding listed above. Add the guide model, locations table, admin and CSV
   import.
3. Migrate Wegmans address options into the locations table. Regression-test the Wegmans
   checkout, emails, ledger, partial ship and reports on a Wegmans copy.
4. Inventory the order guide site's commercial plugins and their hooks. Remove or document
   each one.
5. Install on the order guide site, create the Dunkin guide, import the 92 locations, and
   assign users.
6. Test:
   - A Dunkin order shows the location and PC # on the order and in both emails.
   - Rates and tax match the zone setup for that address.
   - A tampered location ID from another guide is rejected.
   - A user with no guide, or not a member of the site, can't check out.
   - Deactivating a location leaves past orders unchanged.
   - Ledger and tracking entries appear for the order.
7. Resolve the Dunkin data items, then go live.

## Known gaps (minor)

- Block checkout support, if we keep classic checkout.
- Per-user location subsets. The proposal has every guide user see every location; that
  matches, but franchise-group scoping might be asked for later.
- Payment terms are display text only; there's no credit-limit or terms enforcement.
- Cross-site reporting across multiple order guide sites isn't designed yet.

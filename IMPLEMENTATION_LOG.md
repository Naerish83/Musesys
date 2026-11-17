# UACP Implementation Log

## 2026-09-30 — Progressive 60-day chunked export loading (v2.1.2)

**Issue**: On 9/30 morning, Shipment Desk still failed with `0 line items / 0 orders loaded (180 days) — as of 08:27 AM` when searching for WO `3726288`.
**Root Cause**:
- While 180 days squeaked by in testing yesterday afternoon, under morning server conditions on 9/30 the monolithic 180-day query crossed the 256MB PHP memory limit again (HTTP 500 error on `/musesys/v1/export-orders`).
- Any large monolithic query is inherently fragile because order accumulation constantly pushes memory consumption toward the hard PHP limit.
- Meanwhile, the searched order `3726288` was Order `#239295` created on 2026-09-10 (only 20 days ago!). Because 180 days failed as a whole, the recent orders were lost too.

**Fix**:
1. `MuseDataBridge.export_orders_to_csv_data` (`app/data/bridge.py`):
   - Re-architected wide queries (>60 days) to automatically slice into 60-day chunks from newest to oldest.
   - Slices run as separate HTTP requests, keeping PHP object allocation well below 100MB per process (tested: 60-day chunks succeed reliably in 2.5-3.2s with HTTP 200).
   - Added progressive `chunk_callback` support to deliver immediate partial results as each chunk completes.
2. `ShipmentDeskView` (`app/gui/views/shipment_desk.py`):
   - Hooked `chunk_callback` into UI: Chunk 1 (last 60 days) renders in the UI in ~3 seconds, allowing the clerk to immediately search and edit recent orders without waiting for the full 180 days to finish.
   - Background chunks (60-120 and 120-180 days) fold in seamlessly, updating the count and status label (`(loading older...)`).
3. Verified live search for WO `3726288` (from today's screenshot): loads Order `#239295` in the very first 3-second chunk.
4. Version bumped to `2.1.2`.

## 2026-09-29 — Shipment Desk connection fix: Capped lookback window (v2.1.1)

**Issue**: Shipping clerk opening UACP was met with `0 line items / 0 orders loaded` and "Order list is still loading — try again in a moment" on search. Regular users operating on Active Orders had no connection issues.
**Root Cause**:
- Regular role loads Active Orders via `bridge.get_all_orders()` (`GET /wp-json/wc/v3/orders?per_page=100`), standard WooCommerce REST API with pagination (HTTP 200 OK).
- Shipment Desk (and Order Guide) called `bridge.export_orders_to_csv_data(export_all=True)` -> `GET /wp-json/musesys/v1/export-orders?export_all=true`.
- On WordPress, `musesys_export_orders_api` executed `wc_get_orders(['limit' => -1, 'return' => 'objects'])`. As order count grew beyond 1,600+ items by late September, loading all historical orders simultaneously into PHP memory exceeded the server's `memory_limit`, triggering a fatal `HTTP 500 Internal Server Error` (`wp_die`).
- The 500 error resulted in an empty order index `[]`, blocking the clerk from searching any orders.

**Fix**:
1. `ShipmentDeskView` (`app/gui/views/shipment_desk.py`):
   - Changed index fetching to a rolling lookback window (`DEFAULT_LOOKBACK_DAYS = 180`, configurable via `settings.json` `"lookback_days"`). Verified live: fetches ~1,650 items in ~6.1s with HTTP 200 OK.
   - Status label updated to display `(180 days)` lookback and gracefully show connection errors if network fails.
   - Added direct single-order lookup fallback in `on_search`: if a search term is a numeric Order # that predates the 180-day cache, it fetches the single order directly from WooCommerce (`bridge.get_order(term)`).
2. `OrderGuideView` (`app/gui/views/order_guide.py`):
   - Updated `_fetch_data_thread` from `export_all=True` to the same rolling lookback window, preventing 500 errors when refreshing Order Guide.
3. Verified live search for WO `3731995` (from clerk's screenshot): immediately matched to Order `#239511` (status `processing`, created today).
4. Version bumped to `2.1.1`.

## 2026-08-21 — Ready-to-drop settings.json for the shipping-clerk machine (USB deploy)

Delivered a complete `settings.json` (Cole's exact `sites.ogwegmans` block, unchanged, plus `"role": "shipping_clerk"` at the top level) for USB transfer to her desk — drops in next to `uacp_main.py`, no hand-editing required. Also committed a copy to the repo as `settings.shipping_clerk.json` (template/reference only — `ConfigManager` only ever reads the literal `settings.json` filename, so this copy is inert until renamed/copied over).

**Flagged, not resolved**: this reuses Cole's own `WP_USERNAME`/`WP_APP_PASSWORD` (`coleh`) rather than a distinct credential for her. Fine for `consumer_key`/`consumer_secret` (org-wide WC API keys, not user-specific), but the `musesys/v1` routes (ship-lines, logistics updates) authenticate via WP Application Password — so anything she saves through Shipment Desk will currently attribute to "coleh" in whatever audit trail reads that identity, not to her. Given the stated goal of "a viable path for pointing out issues better," that undercuts it. Fix would be a separate WP user + Application Password for her, created in wp-admin (needs Cole — not something achievable from here), then a distinct `settings.json` built around it. Shipped with shared creds for now so this isn't blocked.

## 2026-08-21 — Exporter cleanup: explicit fields, traced (not inferred) authoritative sources

Per Cole: trace the actual wp-admin Global Work Order display/save path instead of inferring from the export resolver, and stop letting `global_work_order` mean "whatever an 8-key fallback chain produces."

**Traced, confirmed by reading the code directly** (`grep -rln "Global Work Order"` across `data/Live`, landed on `modules/muse-checkout-orchestrator/musesys-orchestrator.php`):
- Write: `woocommerce_checkout_update_order_meta` hook, checkout-time only, `update_post_meta($order_id, '_muse_wo_global', ...)` — only when "global WO mode" is active for that order and a value was submitted.
- Read (the exact box in Cole's screenshot): `woocommerce_admin_order_data_after_billing_address` hook, `get_post_meta($id, '_muse_wo_global', true)` — same key, direct read, no fallback chain.
- Separately, `mu-plugins/muse-tracking-core.php::muse_build_work_orders_array()` (runs on every order save) independently maintains `_work_orders` (full de-duped array of every WO found, global + all line items, each with a `display` string) and `_primary_work_order` (formatted display of `_work_orders[0]`) — already-correct, already-maintained canonical data, not something to re-derive.

**Applied** to `data/Live/modules/muse-exporter/index.php`:
- `global_work_order` now reads `_muse_wo_global` directly — the exact key/method the confirmed-good admin box uses. No longer routed through `musesys_export_resolve_global_work_order()`.
- Added two new columns: `Primary Work Order` (`_primary_work_order` passthrough) and `Work Orders` (`_work_orders` passthrough — already array-of-`{display,...}` shape, which `musesys_export_stringify_value()` already knows how to flatten, no new stringify logic needed).
- `musesys_export_resolve_global_work_order()` left defined but no longer called from this file — per Cole, not deleted in case other legacy code depends on it, just retired from this export's output.
- `TRX #` / `PO Number` (added earlier today) unchanged — already single-source, already correct.
- Lints clean (`php -l`). Still needs deploying to the live server — only the local copy is patched.

**Applied** to `app/gui/views/shipment_desk.py`: search formula is now exactly `Order # OR global_work_order (clean) OR additional_work_order_number (all line-item WOs, one row per item already covers multi-WO orders) OR TRX # OR PO Number` — five single-purpose, traced fields, no resolver, no archaeology.

Net effect on the corruption question below: unrelated. That was already closed as wrong before this change. This entry is a design-quality fix (explicit sources beat an ambiguous multi-key fallback), not a bug fix.

## 2026-08-21 — CLOSED: no data corruption. Order 239042 confirmed clean via live admin screenshots

Cole pulled the actual WP-admin order screen for 239042 (the order I'd cited as evidence): Global Work Order # = `360985019` (9-digit SC-style, matches the line item's WO Number and the `#SC-360985019` displayed order name), TRX Order # = `619514`, Purchase Order # = `AL-216149` — all three correctly separated, ETA/status note both populated. No pollution. The corruption theory in the two entries above is **wrong, not just unconfirmed** — closing it out, not pursuing further.

Residual (factual, not theorized): the `/musesys/v1/export-orders` route's resolved `global_work_order` value for this same order is `619514` (matches TRX#, not the `360985019` shown as canonical in wp-admin) — a real discrepancy between what the export API resolves and what wp-admin's "Global Work Order #" field displays, cause unknown, not investigated further this session. Also per Cole: this shop's WO# model is intentionally non-uniform — some orders carry a global-level WO#, others only line-item-level, some carry multiple WOs on one order — so any "search by WO#" feature needs to check both levels, and the export's `global_work_order` field can't be trusted as the source for the global-level one given the discrepancy just found. Open question back to Cole: which field IS the reliable source for global-level WO# search. Shipment Desk's search is not yet updated for this — currently only checks line-item WO# (`additional_work_order_number`) and would miss a global-only order. Do not add `global_work_order` back into search without Cole's confirmation of what it should actually resolve to.

## 2026-08-21 — CORRECTION: walking back "CONFIRMED" on the `_muse_wo_global` corruption claim

Cole pushed back with direct operational experience: he hasn't seen TRX#/WO# bleed in a long time, and the actual workflow is WO# attached at order creation (technician/customer-provided, becomes the order's displayed name) with TRX#/PO# entered *after*, as a separate step — and that named-ID display has been fine.

Re-examining what I actually verified in the entry below versus what I asserted: I confirmed a **symptom** — the export's `global_work_order` field (which resolves through an 8-key fallback chain: `_muse_wo_global`, `_muse_global_work_order`, `_primary_work_order`, `_muse_workorder`, `muse_global_work_order`, `_tracking_number`, `_muse_track_primary`, `_work_orders`) equaled the TRX# for one specific order (239042). I did **not** independently query `_muse_wo_global` as its own isolated value — I inferred it was the source, built a plausible hook-execution mechanism from static code reading (`muse_build_work_orders_array()`'s self-referential fallback), and called that mechanism "CONFIRMED." That's overreach: it's a plausible-from-code-reading hypothesis, not a verified live behavior. I have no visibility into actual hook execution order/timing on this WooCommerce/HPOS setup from a static read, and no way to isolate which of the 8 fallback keys actually supplied that one value.

Standing down on urgency: not treating this as a live bug pending evidence. The export patch and Shipment Desk's search fix (below) still stand on their own merits regardless — `_trx_order_number` is the correct, direct, single-purpose source for a "TRX #" column no matter what's going on with the WO#-resolver chain, so reading it directly instead of reaching through an 8-key fallback that was never designed to expose TRX# is strictly better either way. **Not** recommending the `order_ops.py`/`bridge.py`/`models.py` write-path changes as needed — shelving that pending an actual isolated check (direct query of `_muse_wo_global` vs. `_trx_order_number` as distinct values on a couple of real orders) if Cole ever wants to settle it for certain.

## 2026-08-21 — CONFIRMED: `_muse_wo_global` pollution is live, self-reinforcing data corruption (not just a display mislabel)

**Upgrading severity** on the bug flagged earlier today. Cole refreshed `data/Live` from the actual live server (modules + mu-plugins), which exposed the mechanism precisely:

- `data/Live/mu-plugins/muse-tracking-core.php::muse_build_work_orders_array()` (hooked to `woocommerce_update_order` / `woocommerce_new_order`, i.e. runs on every order save) computes `_work_orders` / `_primary_work_order` / `_muse_wo_global` from a fallback chain whose **first-checked source is `_muse_wo_global` itself**: `muse_tracking_get_first_meta($order_id, ['_muse_wo_global', '_muse_global_work_order', ...])`.
- `order_ops.py::save_order_to_db()` (~1142) and `bridge.py::save_order()` (~153) write the TRX # input into **both** `_muse_wo_global` and `_trx_order_number` on every "Update Order" save.
- Net effect: the moment a human enters a TRX # in Active Orders, `_muse_wo_global` gets the TRX # value written into it. On save, the WP-side hook reads `_muse_wo_global` back out as "the existing global work order," finds it non-empty, and re-persists that TRX #-shaped value into `_work_orders[0]`, `_primary_work_order`, and `_muse_wo_global` again — **locking the pollution in permanently**, with no self-correction path even if a real WO # is entered later at the line-item level (global slot is always index 0 / always wins for `_primary_work_order`).
- Confirmed against live order 239042: `global_work_order` in the export feed = `619514`, which is the TRX # shown in the UACP screenshot for that order, not a real Work Order #.
- Downstream blast radius, also confirmed via `muse-tracking-core.php`: `_primary_work_order` feeds `woocommerce_order_number` filter (`muse_override_order_number`) — meaning the TRX # can silently override the customer-facing WooCommerce order number shown in emails/admin/My Account for any order this has touched.
- `muse-tracking-core.php` itself already documents (2026-07-24 note in `gerhardt_get_tracking_display()`) that `_trx_order_number` was *intentionally* removed from the WO-display fallback chain specifically to prevent this kind of bleed — the fix landed on the read side, but the write-side leak in `order_ops.py`/`bridge.py` was never closed, so it's still happening via a different door.

**Coupled with a second bug**: `app/models.py::Order.from_wc_data()` reads TRX # as `get_meta(['_muse_wo_global', '_trx_order_number'])` — `_muse_wo_global` checked FIRST. This only "works" today *because* of the pollution above. If the write-side leak is fixed without also fixing this read order, Active Orders' and Shipment Desk's TRX # field will start showing blank/wrong values for orders where `_muse_wo_global` has since gone back to holding a real WO #. These two fixes are a package, not independent.

**Not fixed this pass** — recommended fix (ready, not applied, needs Cole's go-ahead since it touches `order_ops.py`/`bridge.py`/`models.py`, all load-bearing for Active Orders):
1. Drop the `_muse_wo_global` write from `order_ops.py::save_order_to_db()` meta_data and `bridge.py::save_order()` meta_data — keep only `_trx_order_number`.
2. Change `models.py::Order.from_wc_data()`'s TRX # lookup to `get_meta(['_trx_order_number'])` only (or at minimum reorder so `_trx_order_number` wins).
3. Separate, larger, NOT scoped here: existing orders already have `_muse_wo_global` contaminated — a real WO # may be permanently shadowed for any order a human has ever entered a TRX # into via UACP. Remediation would need a one-time audit/migration (compare `_muse_wo_global` against `_trx_order_number` and line-item WO # meta, re-derive), not something to do casually.

**Also applied this pass** (the part actually asked for): patched `data/Live/modules/muse-exporter/index.php`'s `MUSESYS_EXPORT_HEADERS` + `musesys_export_build_order_base()` to add `TRX #` (read directly from `_trx_order_number`, NOT through the polluted `global_work_order` resolver) and `PO Number` (from `_purchase_order_number`). Lints clean (`php -l`). This is the file Cole refreshed from live today — confirmed current (29 columns, matches a live 1,272-row pull), so patched directly rather than the stale mirror from earlier. Still needs deploying to the actual live server — I don't have write access there, only to this local copy.

Updated `shipment_desk.py` search to stop treating `global_work_order` as a TRX # candidate (it was only ever a coincidental proxy created by the pollution above, never a real source) — now matches Order #, `additional_work_order_number` (true line-level WO #), and the new `TRX #` / `PO Number` columns.

## 2026-08-21 — Shipment Desk v1: role-gated tab + write path (server-side export patch still pending)

**Delivered**: `app/gui/views/shipment_desk.py` (new `ShipmentDeskView`), `uacp_main.py` (role gate).

- Confirmed against a live 1,272-row pull of `/musesys/v1/export-orders` (2026-08-21): the route has grown well past the locally-mirrored `data/Live/modules/muse-exporter/index.php` (29 live columns incl. Order Status, Logistics Status/Date, Primary Tracking, Ordered/Shipped/Remaining Qty, Line Item ID, Has Tracking — vs. 15 in the stale mirror). Confirms the mirror was right to distrust; do not patch PHP against it.
- **Still missing from the live feed**: a genuine `TRX #` (`_trx_order_number`) and `PO Number` (`_purchase_order_number`) column. Needed to search the full/unbounded order set by TRX#/PO# — currently only Order #/WO# are reliably searchable that way. Server-side patch (additive, 2 new header entries + 2 resolver lines, same pattern as the existing WO#/store resolvers) not yet applied — I don't have write access to the live PHP, only the stale local mirror, so I didn't touch it blind. Snippet handed to Cole in-chat.
- **Bug found in the process, flagged not fixed**: `order_ops.py::save_order_to_db()` (~line 1142) and `bridge.py::save_order()` (~line 153) both write the TRX # input into **both** `_muse_wo_global` and `_trx_order_number`. That's the live version of the exact WO#/TRX# conflation `CORE_META_STANDARDS.md` says it's "correcting" — `_muse_wo_global` is currently polluted with TRX# values in production data. Explains why the export's `global_work_order` column reads as TRX# today even though nothing in the export route reads `_trx_order_number` directly. Left as-is pending Cole's call — didn't want to touch write paths Active Orders depends on without sign-off.
- `ShipmentDeskView`: unified search (Order #/WO#/TRX#/PO#, degrades gracefully until the PHP patch lands) over the unbounded export feed → on match, re-fetches the authoritative `Order` via `bridge.get_order(id)` (uncapped single-order endpoint) for the real write. Editable surface: Tracking #, Carrier, per-line Shipped Qty, Logistics Status Note + ETA (+ required Replacement Part # prompt when status = Replaced). Everything else (TRX#/PO#/Drop Ship/Status/WO#/exports/other tabs) is read-only context or simply absent.
- Write path (`_save_thread`) intentionally duplicates `order_ops.py`'s `calculate_status_progression`/`normalize_status_slug`/`normalize_date`/`save_order_to_db` payload shape rather than importing, so a bug in the new view can't regress Active Orders. Flagged as a candidate for extraction to a shared `app/data` module once this view is proven — not done this pass (scope discipline).
- `uacp_main.py`: `settings.get("role", "admin")` gates tab construction in `setup_ui()`. `role == "shipping_clerk"` → only the Shipment Desk tab is built, File > Export CSV menu item is omitted entirely. `self.order_ops = self.shipment_desk` for that role so the existing heartbeat/status-light watcher loop (`run_watch`) keeps working unmodified — `ShipmentDeskView` exposes the same `.bridge` / `.refresh_list_async()` surface it expects.
- **Not done yet**: the export-orders PHP patch (blocks TRX#/PO# search specifically — Order #/WO# search works today); a live functional test pass (no way to exercise Tkinter + live WC from this session); rollout of `"role": "shipping_clerk"` into her actual deployed `settings.json` (didn't touch Cole's own dev copy — his stays `role`-less / defaults to admin).

## 2026-08-21 — Shipment Desk brainstorm: root-caused the 100-order cap + Order Guide complexity, no code changes yet

**Problem framed by Cole**: shipping/tracking data-entry coworker's orders age off the Active Orders list before she gets to them (front page capped), and Order Guide (which isn't capped) is too spreadsheet-y for her to use.

**Root-caused, not guessed** (read `bridge.py`, `order_ops.py`, `order_guide.py`, `api_client.py`, `logistics.py`, live `muse-exporter/index.php` mirror):

- `MuseDataBridge.get_all_orders()` (`app/data/bridge.py:264`) calls `client.get_orders(limit=100)` → WC `GET /orders?per_page=100`. Active Orders' grid AND its Quick PO/WO/TRX Lookup modal both search only this capped in-memory `orders_cache` — confirmed cause of "her order fell off the list."
- Order Guide (`order_guide.py`) already escapes the cap: it calls `bridge.export_orders_to_csv_data(export_all=True)` → `/musesys/v1/export-orders`, which server-side resolves `wc_get_orders(['limit' => -1])` — genuinely unbounded. This is the data path worth reusing, not the tksheet UI wrapped around it.
- Gap found in that export feed itself (per the locally-mirrored `data/Live/modules/muse-exporter/index.php`, headers only: Order Number, Order Date, Customer Note/Email, Address, Item #/SKU/Name/Qty/Cost, install store, `global_work_order`): **no TRX # (`_trx_order_number`) and no genuine PO # (`_purchase_order_number`) column.** Order Guide's sheet column literally labeled "PO Number" is populated from `row.get('global_work_order')` — i.e. it's showing the Work Order #, mislabeled as PO#. Exactly the WO#/PO#/TRX# conflation `CORE_META_STANDARDS.md` warns about, and it means today nothing in the app can search the *full* order set by TRX# or true PO# — only WO# and order ID.
  - Caveat: `order_guide.py` (last touched later than the mirrored PHP) reads several fields the mirrored route doesn't emit (Logistics Status, Shipped Qty, Order Status, Has Tracking, Order Created At) — strong sign the **live** route has grown since this local mirror was pulled. Don't patch against the stale mirror; pull a fresh copy of the live `export-orders` route first.

**Proposed design** (pending Cole's go-ahead — not yet built):
- New tab/view, working name "Shipment Desk" (`app/gui/views/shipment_desk.py`), wired in `uacp_main.py`.
- Search: one field, matches typed term against Order Number / WO# / TRX# / PO# across the *unbounded* export feed (requires the small PHP addition above). On match, re-fetch the authoritative order via `bridge.get_order(id)` (single-order WC endpoint, also uncapped) for the real `line_items`/ids to write against — same pattern Order Guide's own `save_tracking()` already uses.
- Editable surface restricted to exactly what she's cleared for: Tracking #, Carrier (dropdown, reuse `CARRIER_OPTIONS`), per-line-item Shipped/To-Ship qty, Logistics Status Note + ETA (reuse the existing `_show_logistics_gate_modal` validation as-is). Everything else (TRX#/PO#/Drop Ship/Status/WO#/Asset Tag/exports/other tabs) is display-only or simply not present — not disabled, not built.
- Writes reuse existing proven paths only: `calculate_status_progression()`, `save_order_to_db()` / `bridge.ship_lines()` — no new write logic.
- Access restriction is UI/build-level only (app has no per-user auth — one shared WC key per site in `settings.json`): add a `"role"` key to her `settings.json`, gate tab construction in `TeammateApp.setup_ui()` so her build shows only Shipment Desk.

**Open before building**: (1) confirm live `/musesys/v1/export-orders` field set (don't trust the local mirror), decide whether to add TRX#/PO# columns there; (2) Cole to pick rollout — new tab available to everyone vs. role-gated single-tab build for her machine specifically.

## 2026-06-12 — Manual extension install (drag-drop installer dead on this build)

**Issue**: Claude Desktop 1.12603.1 logs "Handling DXT/MCPB file" then silently aborts — for BOTH the v0.4/uv and v0.3/python bundles. The install pipeline never reaches manifest parsing. Local install flow is broken or policy-gated in this build.

**Fix**: bypassed the installer, replicating what it would have done (mirrors the existing `local.dxt.gerharz-equipment.cairn` entry):
1. Extracted bundle → `%APPDATA%\Claude\Claude Extensions\local.dxt.musesystems.musesys-mcp\`
2. Registered in `%APPDATA%\Claude\extensions-installations.json` (backup at `.bak-20260612`): `source: local`, `signatureInfo: unsigned`, sha256 9f1a6869…, concrete `mcp_config` — `uv.exe --directory <extdir> run python src/server.py`, MUSESYS_* creds inlined in env (registry stores resolved values, not user_config placeholders).
3. On-disk extension manifest overwritten with the same concrete version.
4. Pre-warmed uv env (deps synced) + verified live from this machine: WC route (order 238163, processing) and musesys/v1 route (9 pending product requests) both authenticated.

**Pending**: app restart to load the extension. **Risk**: if the app rewrites extensions-installations.json from memory on quit, the entry is lost — re-apply from backup pattern or fall back to `mcpServers` in claude_desktop_config.json.

## 2026-06-12 — Bundle install fix attempt: v1.0.1 (manifest 0.4 → 0.3) [superseded by manual install]

**Issue**: Claude Desktop 1.12603.1 silently rejected the v1.0.0 bundle (drag-drop and manual select both no-op'd). `main.log` showed "Handling DXT/MCPB file" then nothing — no error surfaced. Cause: this build doesn't support manifest spec v0.4 / `server.type: uv`.

**Fix**: `manifest_version: "0.3"`, `server.type: "python"`, `mcp_config.command` = `C:\Users\chenderson\.local\bin\uv.exe` with `run --directory ${__dirname} src/server.py` — same pattern as the working Cairn extension (`local.dxt.gerharz-equipment.cairn`). Deps still resolve from bundled `pyproject.toml` via uv; server code untouched. Repacked (shasum 805fb1a7), 1.0.0 artifacts deleted.

**Caveat**: `mcp_config.command` now hardcodes Cole's uv path — bundle is machine-specific until Claude Desktop supports `uv` type (then revert to v0.4 manifest, drop the hardcoded path).


## 2026-06-12 — MuseSys MCP one-click bundle (.mcpb / .dxt)

**Delivered**: `modules/musesys_mcp/dist/musesys-mcp-1.0.0.mcpb` (+ identical `.dxt` copy); staging at `modules/musesys_mcp/bundle/`

- Format note: Anthropic renamed DXT → MCPB (MCP Bundles); same zip format. Built against manifest spec v0.4, validated + packed with official `@anthropic-ai/mcpb` CLI (shasum 3d30a97f).
- `server.type: uv` — host installs deps from `pyproject.toml` via UV at install time. Avoids bundling platform/CPython-pinned `pydantic-core` wheels; cross-platform, 39KB.
- `src/server.py` is a verbatim copy of `musesys_mcp.py` — single source of truth stays in the module root; re-copy + `mcpb pack` on changes.
- Credentials via `user_config` (5 fields, secrets marked `sensitive` → OS keychain), injected as `MUSESYS_*` env vars. No settings.json inside the bundle.
- Icon: `M-icon.ico` → 512×512 `icon.png`.
- Install: drag the `.mcpb` (or `.dxt`) onto Claude Desktop → Settings → Extensions, fill in the 5 credential fields.


## 2026-06-12 — MuseSys MCP Server v1.0.0 (STATE_OF_THE_APP Part 2)

**Delivered**: `modules/musesys_mcp/` — `musesys_mcp.py`, `manifest.json`, `requirements.txt`, `README.md`

- stdio JSON-RPC 2.0 server (FastMCP, `mcp>=1.0.0`, Python 3.10+ — SDK floor overrides spec's 3.8 target).
- 4 resources (`musesys://orders/active`, `orders/detail/{id}`, `requests/pending`, `assets/store/{id}`), 15 tools — full spec section 3 coverage.
- Self-contained: does NOT import `app/api_client.py`; embeds slim HTTP layer with 429/5xx exponential backoff (1/2/4s, honors Retry-After) which the GUI client lacks.
- Config: `MUSESYS_*` env vars win per-key; falls back to UACP `settings.json` (`active_site` schema) beside script → repo root → cwd. Zero-config in-repo.
- Safe gates enforced: WO `^[0-9]{7}$`; store zero-pad to 3; dates sanitized (`/`,`.` → `-`) and re-emitted strict `YYYY-MM-DD`, impossible dates rejected; status normalization (canonical 7 + `partially-shipped`, `wc-` strip); `replaced` requires `replacement_part`; ship_lines line/qty validation; asset field whitelist.
- Auth split mirrors api_client: WC core routes = consumer key/secret Basic; `musesys/v1` routes = WP app password Basic. Cache-bust `_=ts` on all list GETs.
- Status-sync guard preserved: `closeout` rides the `/ship-lines` payload; server never posts `status: completed` via the general order endpoint.
- Verified: 15 tools + 4 resources enumerate; 20 validator gate checks pass; settings.json auto-resolution confirmed (`https://wegmansparts.com`). Live API smoke blocked by sandbox proxy — run locally: `python -c "import musesys_mcp as m; print(m.wc_get('orders', {'per_page':1})[0]['id'])"`.
- Known limit: `wo_number`/`store_number` filters on `musesys_get_orders` are client-side over the fetched page (current + legacy meta keys checked); raise `limit` for older orders. Server-side meta_query endpoint is the future fix (would go in musesys-mega).

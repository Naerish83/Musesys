# MuseSys Ledger — Implementation Spec (v1)

Status: **v1 BUILT** (2026-10-01). Unit- and SQL-level tests pass. Staging verification (§10.2, §10.3) has **not** been performed yet. See §14 for where the build differs from this text. **§15 (proposed updates from an outside system) BUILT 2026-11-23, not deployed.**
Date: 2026-10-01
Module: `modules/musesys-ledger/`

The ledger is a fulfillment-obligation workspace. It does three things:

1. Projects current fulfillment state from the order data that already exists into a read-optimized table.
2. Records human follow-up effort (email, call, note) and observed domain changes in an immutable event log.
3. Gives one non-admin user, the ledger agent, a locked-down screen with a fixed set of allowed actions. There is no generic edit.

> **Core rule:** A human action records *effort*. Only a business event changes the *obligation*.
> Email, call and note never clear a row. A new future ETA, or shipping the remaining quantity, does.

---

## 1. Locked decisions

| # | Decision | Source |
|---|---|---|
| D1 | `due_at` comes from the confirmation commitment. When staff confirm an order they enter the TRX #, the PO # (unless it ships from stock), and a logistics status. `in_stock` is used only if the order ships the same day. Otherwise the status is `backordered` with an estimated date: either the vendor's real ETA, or an estimate (usually 1–2 weeks) from vendor history. | Operator |
| D2 | Partial shipment is per line, checked against quantity. The order completes only when every ordered quantity on every line has shipped. | Operator + existing code |
| D3 | "Log Email" is a human log entry only. The portal sends nothing. (Cairn will send these emails itself later.) | Operator |
| D4 | CALL_REQUIRED is satisfied only by a call logged **after** the triggering email. | Operator |
| D5 | The ledger agent is the only non-admin writer. Kevin is view-only. | Operator |
| D6 | **ETA is per order.** Rows are per line (for quantity and tracking), but every open line shows its order's ETA. "Set ETA" goes through the existing logistics mutation and changes the ETA for the whole order. No per-line ETA field is added. | Operator, 2026-10-01 |
| D7 | Because ETA is per order (D6), vendor contact (email/call) is also per order. CALL_REQUIRED is computed per order and applies to every open line of that order. | Follows from D6 |

### 1.1 Non-goals for v1

The build must not add any of these. If one turns out to be needed, stop and raise it. Do not add it during the build.

- **No outbound email from the ledger.** "Log Email" only records that a human sent one. The only messages that can result from a ledger action are the *existing* side effects of the logistics mutation (§6.1).
- **No tracking entry and no shipment recording** (§6.2).
- **No per-line ETA field** (D6).
- **No change to `musesys-reports`** or its twice-daily email (§11 O3).
- **No changes to UACP** or to the `musesys/api/v1` endpoints.
- **No fixes for the R1–R4 defects** in §12. Separate lanes.
- **No Cairn export.**
- **No edit or delete path for events**, including for admins.

---

## 2. Where the truth lives today (FACT — verified in code 2026-10-01)

The ledger stores **no** canonical business data. It only reads the sources below.

| Concept | Storage | Granularity | Owner module | Written by |
|---|---|---|---|---|
| TRX # | `_trx_order_number` | order | `order-fields/muse-order-fields.php` | Order metabox; UACP via `/musesys/api/v1/meta/post/{id}` |
| PO # | `_purchase_order_number` (+ `_is_drop_ship`) | order | same | same |
| Logistics status | `_muse_logistics_status` ∈ `in_stock`, `backordered`, `replaced`, `obsolete` (empty is treated as `in_stock`) | order | `muse-logistics-manager/index.php` | "Logistics & Status" metabox, plus UACP |
| ETA | `_muse_logistics_date` (`YYYY-MM-DD`) | order | same | same |
| Shipments | `_muse_shipments`: a JSON array of `{carrier, other_carrier, tracking_numbers[], items{item_id: qty}, date}` | line + qty | `musesys-partial-ship/index.php` | Metabox `save_metabox()`; REST `POST musesys/v1/orders/{id}/ship-lines` |
| Order status | Woo status, plus custom `wc-partially-shipped` and `wc-order-placed` | order | partial-ship, logistics-manager | `recompute_status()` sets `completed` automatically once every line is fully shipped |
| Transfer orders | TRX `99999`, auto-completed | order | `musesys-wegmans-transfer-items` | — |

Side effects of the existing mutations, which the ledger keeps by reusing them:

- A logistics save with status `backordered` and a changed status/date fires `do_action('muse_send_sc_automation', $id, 'backordered', …)`. `Muse_Email_Manager::send_sc_automation()` then emails `<WO#>@wonote.servicechannel.net` for each **Service Channel work order** on the order. The send is skipped if the order has no SC work order, or if the `_muse_sc_last_logistics_hash` meta already equals `backordered|<date>`.
  - This send **does not depend on the metabox's "Notify Customer" checkbox.** That checkbox controls only a separate email to the order's billing address (`handle_notification()`).
  - So a ledger "Set ETA" will notify Service Channel exactly as UACP does today. That is intended, and the UI states it as a fact (§8.1). It is not offered as an option.
- A shipment save with tracking fires `muse_send_sc_automation(…, 'parts_shipped')`.
- When the order is set to `completed` with no TRX (or a drop-ship order has no PO), the save throws an exception (`muse_block_order_completion_if_fields_missing`). In practice, that is the most common way a row reaches SHIPPED_NOT_CLOSED.

---

## 3. Data model

There are two tables, created with `dbDelta` and version-gated by an option, following the pattern in `musesys-reports/musesys-reports.php`. Both use `$wpdb->prefix`.

### 3.1 `{prefix}musesys_ledger` — current-state projection (rebuildable)

One row per Woo order line. The stable identity is `order_item_id`, not the TRX string.

```sql
CREATE TABLE {prefix}musesys_ledger (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id          BIGINT UNSIGNED NOT NULL,
  order_item_id     BIGINT UNSIGNED NOT NULL,
  -- projected inputs (copied from source of truth, never edited here)
  order_status      VARCHAR(32)  NOT NULL,
  trx               VARCHAR(64)  NULL,
  po_number         VARCHAR(64)  NULL,
  is_drop_ship      TINYINT(1)   NOT NULL DEFAULT 0,
  store             VARCHAR(16)  NULL,          -- additional_install_store_number / _muse_store_global
  product_id        BIGINT UNSIGNED NULL,
  part_label        VARCHAR(255) NULL,          -- item name + muse_part_number
  qty_ordered       INT NOT NULL,
  qty_shipped       INT NOT NULL DEFAULT 0,     -- min(ordered, sum of _muse_shipments items[item_id])
  tracking_count    INT NOT NULL DEFAULT 0,     -- distinct non-empty tracking #s on shipments touching this line
  logistics_status  VARCHAR(16)  NOT NULL,      -- empty => 'in_stock'
  eta               DATE NULL,                  -- _muse_logistics_date (order-level, D6)
  confirmed_at      DATETIME NULL,              -- first projection that saw TRX present (see §4.2)
  confirmed_at_source VARCHAR(16) NULL,         -- 'observed' | 'backfill_approx' (see §4.2, §9)
  -- resolved (output of LedgerStateResolver; never written by UI)
  state             VARCHAR(24)  NOT NULL,
  state_reason      VARCHAR(48)  NULL,
  due_at            DATETIME NULL,
  overdue_bdays     INT NOT NULL DEFAULT 0,
  data_flags        VARCHAR(255) NULL,          -- e.g. 'instock_future_date'
  last_email_at     DATETIME NULL,              -- order-scoped (D7)
  last_call_at      DATETIME NULL,
  last_action_at    DATETIME NULL,
  actionable        TINYINT(1) NOT NULL DEFAULT 0,
  -- bookkeeping
  projected_at      DATETIME NOT NULL,
  resolved_at       DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY order_item (order_item_id),
  KEY order_id (order_id),
  KEY actionable_state (actionable, state),
  KEY trx (trx)
);
```

All datetimes are stored in UTC and shown in `America/New_York`.

### 3.2 `{prefix}musesys_ledger_events` — immutable activity log

```sql
CREATE TABLE {prefix}musesys_ledger_events (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id       BIGINT UNSIGNED NOT NULL,
  ledger_id      BIGINT UNSIGNED NULL,   -- NULL = order-scoped event (ETA, email, call)
  order_item_id  BIGINT UNSIGNED NULL,   -- denormalized for Cairn export
  user_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- 0 = system
  source         VARCHAR(16) NOT NULL,   -- 'ledger_ui' | 'projector' | 'resolver' | 'backfill'
  action         VARCHAR(32) NOT NULL,
  payload_json   LONGTEXT NOT NULL,
  dedupe_key     VARCHAR(191) NULL,      -- required for generated events; NULL for human events (§3.3)
  created_at     DATETIME NOT NULL,      -- UTC
  PRIMARY KEY (id),
  UNIQUE KEY dedupe_key (dedupe_key),
  KEY order_created (order_id, created_at),
  KEY ledger_created (ledger_id, created_at),
  KEY action_created (action, created_at),
  KEY user_created (user_id, created_at)
);
```

Rules:

- The table has no `updated_at`, no update path and no delete path. The events class exposes `append()` and read methods only.
- A row's history is: `ledger_id = row.id` **OR** (`ledger_id IS NULL AND order_id = row.order_id`).
- Event actions:

| action | source | scope | payload |
|---|---|---|---|
| `email_logged` | ledger_ui | order | `{text}` |
| `call_logged` | ledger_ui | order | `{text, outcome?}` |
| `note` | ledger_ui | order or line | `{text}` |
| `eta_set` | ledger_ui | order | `{previous_status, previous_eta, new_status, new_eta, notified_customer}` |
| `eta_changed` | projector | order | `{previous_eta, new_eta, previous_status, new_status}`. Recorded when ETA changes outside the ledger (UACP/metabox). `user_id` = `get_current_user_id()` if one is available at hook time, otherwise 0. |
| `trx_assigned` | projector | order | `{previous_trx, new_trx}` |
| `shipment_recorded` | projector | line | `{shipment_index, qty, tracking_numbers, carrier}` (detected by diffing `_muse_shipments` length) |
| `state_changed` | resolver | line | `{from, to, reason}`. This is what produces timeline entries such as "DUE DATE PASSED" and "CALL REQUIRED". |

Every generated event's payload also carries `projector_v` or `resolver_v` (§4.4).

### 3.3 Idempotency of generated events

Immediate hooks, the 15-minute reconcile, the nightly rebuild and the backfill all run the same projector and resolver. **Re-running any of them over unchanged data must append nothing.** This is enforced in two layers.

**Layer 1 — diff against stored projection.** The projector emits an event only when the source value differs from the value stored on the ledger row:

| Event | Emitted when |
|---|---|
| `trx_assigned` | source `trx` ≠ stored `trx` |
| `eta_changed` | source `(eta, logistics_status)` ≠ stored |
| `shipment_recorded` | a shipment fingerprint in `_muse_shipments` has no event yet |
| `state_changed` | stored `state` ≠ newly resolved `state` |

The row update and the event insert happen in one transaction, and the update is conditional (`UPDATE … WHERE id = ? AND eta <=> ?`). If a concurrent pass already applied the change, it affects 0 rows and that pass emits nothing.

**Layer 2 — a unique `dedupe_key` enforced by the database.** Generated events are written with `INSERT IGNORE`, so a race that slips past layer 1 collapses to one row.

| Event | `dedupe_key` |
|---|---|
| `trx_assigned` | `trx:{order_id}:{prev_event_id}:{sha1(old→new)}` |
| `eta_changed` / `eta_set` | `eta:{order_id}:{prev_event_id}:{sha1(old_status,old_eta→new_status,new_eta)}` |
| `state_changed` | `state:{ledger_id}:{prev_event_id}:{from}>{to}` |
| `shipment_recorded` | `ship:{order_id}:{order_item_id}:{shipment_index}:{sha1(normalized shipment)}` |

- **`prev_event_id`** is the id of the most recent event of the same kind for the same order (or line, for state), or `0` if there is none. Including it means a *genuine* repeat transition (ETA A→B, then B→A, then A→B again) gets a new key each time, while a *replay* of the same transition from the same prior state collides. A key built only from `old+new` would wrongly suppress the third change.
- **Normalized shipment** means the carrier uppercased, the tracking numbers trimmed, uppercased and sorted, and the items sorted by item_id. If two entries in `_muse_shipments` have identical content at different indexes (possible under R1), they get different keys and both are recorded. That is faithful to the source; deduplication only prevents *replay*, not real duplicates in the source data.
- **`eta_set` (ledger UI) shares the `eta:` key namespace** with `eta_changed`. When the projector then sees the change that the ledger just made, its `eta_changed` collides and is dropped, so a ledger ETA update produces exactly one event.
- Human events (`email_logged`, `call_logged`, `note`) have `dedupe_key = NULL`. MySQL allows many NULLs in a unique index. Double-click protection for these lives in the UI (button disabled while the request is in flight) plus a nonce, not in the key.

---

## 4. Projection pipeline

There are two separate operations. Keeping them apart is what makes the 15-minute job cheap.

| Operation | Input | Cost | When |
|---|---|---|---|
| **project(order_id)** | Loads the `WC_Order` and refreshes the input columns for every line, inserting rows for new lines and setting `order_status` on lines that were removed | One order load | On mutation; in reconcile for orders modified since the last watermark |
| **resolve(rows)** | Ledger columns + that order's email/call events + `now` | SQL only, no order loads | After every project; on **every** reconcile run for all non-closed rows |

`resolve` must run on a timer even when nothing is mutated, because **time alone changes state**: a due date passes, or a business day elapses after an email.

### 4.1 Triggers (immediate)

Changed order IDs are collected during the request, and `project()` runs once per order on `shutdown`.

- `woocommerce_update_order`, `woocommerce_new_order` — cover partial-ship saves, completion, and anything else that calls `$order->save()`.
- `added_post_meta` / `updated_post_meta` / `deleted_post_meta` for the watched keys: `_trx_order_number`, `_purchase_order_number`, `_is_drop_ship`, `_muse_logistics_status`, `_muse_logistics_date`, `_muse_shipments`. These are **required**:
  - UACP writes through `MuseSys_API::handle_post_meta()`, which calls raw `update_post_meta()`. That fires no Woo order hooks.
  - The logistics metabox saves with `$order->save_meta_data()`, which does not fire `woocommerce_update_order`.
- `woocommerce_order_status_changed`.
- The ledger's own actions call `project()` and `resolve()` synchronously, so the row is correct in the AJAX response.

### 4.2 `confirmed_at`

No existing field records when an order was confirmed, so the projector records it:

- The first time `project()` sees a non-empty TRX, it sets `confirmed_at = now` and `confirmed_at_source = 'observed'`.
- `confirmed_at` is written once and never overwritten by later passes. A later TRX correction does not move it.
- During backfill (§9), `confirmed_at` = the time the order was created, and `confirmed_at_source = 'backfill_approx'`.

> **The backfilled `confirmed_at` is a migration approximation, not reconstructed history.** The real confirmation time of orders that existed before the ledger is unknown, because nothing recorded it. The order's creation time is the earliest possible value. So for `in_stock` lines it makes `due_at` as early as possible, and some of those lines will show as overdue sooner than they really became overdue.
>
> - Implementations must not present it as an observed fact. The UI shows "confirmed ≈ {date} (estimated)" whenever `confirmed_at_source = 'backfill_approx'`.
> - Any `state_changed` event resolved from an approximate `confirmed_at` carries `{"confirmed_at_approx": true}`.
> - Lines that are `backordered` are unaffected, because their `due_at` comes from the ETA. Only `in_stock` lines, and backordered lines with no ETA (where `confirmed_at` becomes `expiry_at`), depend on it.

`confirmed_at` is needed to compute `due_at` for `in_stock` lines (D1: in-stock means it ships the same day).

### 4.3 Reconcile (insurance)

These run on Action Scheduler (bundled with Woo), not on WP-Cron:

- `musesys_ledger_reconcile` every 15 min:
  1. `project()` every open order whose `date_modified` is later than the stored watermark.
  2. `resolve()` every row with `state <> 'CLOSED'`.
- `musesys_ledger_full_rebuild` nightly: `project()` every open order, to catch any hook gaps.
- Register both with `as_schedule_recurring_action` behind `as_has_scheduled_action`.

### 4.4 Projector and resolver versions

Two options sit alongside the table-schema version, each holding an integer constant from the code:

| Option | Code constant | Bump when |
|---|---|---|
| `musesys_ledger_projector_version` | `Ledger_Projector::VERSION` | How source data maps to ledger columns changes (e.g. how `qty_shipped` or `tracking_count` is computed) |
| `musesys_ledger_resolver_version` | `Ledger_State_Resolver::VERSION` | Any rule in §5 changes |

At the start of every reconcile run:

- **Projector mismatch** → enqueue a full rebuild (`project()` all open orders, batched), then store the new version once it has completed.
- **Resolver mismatch** → `resolve()` **every** row, including CLOSED ones, batched. Then store the new version.
- `state_changed` events produced by an upgrade carry `{"cause": "resolver_upgrade", "from_v": n, "to_v": m}`. That keeps a rule change from looking like an operational event in the timeline and in per-user counts.
- **The stored version is updated only after the pass has completed.** An interrupted upgrade therefore re-runs on the next reconcile, which is safe because of §3.3.

### 4.5 Inclusion and exclusion

- Open order statuses: `processing`, `on-hold`, `pending`, `partially-shipped`, `order-placed`. These match the open set in `class-orders-report.php`, plus `order-placed`.
- Lines on orders with any other status → `state = CLOSED`, `actionable = 0`. The row is kept so its history stays visible.
- TRX `99999` (Wegmans transfer) → CLOSED. These orders are auto-completed anyway; this is a belt-and-braces check.

---

## 5. `LedgerStateResolver` — deterministic, pure

`resolve(array $row, array $order_contacts, DateTimeImmutable $now): array{state, reason, due_at, overdue_bdays, data_flags, actionable}`

This is a pure PHP class with **no WordPress calls**, so it can be unit-tested with the PHP CLI. Nothing in the UI sets state.

### 5.1 Definitions

- `qty_remaining = qty_ordered − qty_shipped`
- **Business day:** Mon–Fri in America/New_York. **v1 has no holiday calendar** (stated limitation).
- `add_bdays(t, n)`: moves forward n business days and keeps the time of day. Weekend start times roll forward to Monday 00:00 first. Example: Fri 15:00 + 1 → Mon 15:00.
- `due_at`:
  - `backordered` with an ETA → end of the ETA day (23:59:59 ET).
  - `in_stock` → end of the business day of `confirmed_at`.
  - `backordered` with no ETA → `null`. This is a process violation and counts as overdue immediately.
- `overdue = (due_at === null) OR (now > due_at)`
- `expiry_at` = `due_at`, or `confirmed_at` when `due_at` is null.
- `trigger_email` = the latest `email_logged` for the order with `created_at >= expiry_at`. (An email sent before the commitment expired does not start the call clock.)

### 5.2 Rules — first match wins

| # | State | Condition | Agent sees it? |
|---|---|---|---|
| 1 | `CLOSED` | Order not open (§4.5), or TRX = `99999` | No |
| 2 | `MISSING_TRX` | TRX empty | No. Admin/viewer only, routed to order entry |
| 3 | `SHIPPED_NOT_CLOSED` | `qty_remaining <= 0` and the order is still open | Yes (informational, reason e.g. `completion_blocked`) |
| 4 | `EXCEPTION` | `logistics_status ∈ {replaced, obsolete}` | No. Admin/viewer only (see §11 O1) |
| 5 | `ON_TRACK` | `!overdue` | No (not actionable) |
| 6 | `CALL_REQUIRED` | `overdue` and `trigger_email` exists and `now >= add_bdays(trigger_email, 1)` and no `call_logged` with `created_at > trigger_email` | Yes |
| 7 | `NEEDS_ETA` | `overdue` (everything else) | Yes |

`actionable = 1` for states 3, 6 and 7.

Data flag: `in_stock` with a future ETA → add `instock_future_date` and compute `due_at` from the ETA. The vendor date is more informative than the status label, and the existing report already flags this conflict.

### 5.3 Resulting behaviour

- An email logged after expiry → stays NEEDS_ETA. One business day later → CALL_REQUIRED.
- A call logged after that email → back to NEEDS_ETA. The row records `last_call_at` and stays until reality changes. CALL_REQUIRED is an escalation condition, not a destination.
- A new email after that call starts a new cycle.
- A new future ETA → ON_TRACK. Full shipment of the remaining quantity → SHIPPED_NOT_CLOSED, then CLOSED once Woo completes the order.
- Notes never affect state.

On every resolve where `state` differs from the stored value, append a `state_changed` event.

---

## 6. Mutation boundary

```
Ledger UI → existing domain mutation → order meta changes → project() → resolve() → event appended
```

The ledger never writes a field that duplicates order data.

### 6.1 Set ETA

Refactor `Muse_Logistics_Manager::save_logistics_data()` into:

```php
public static function apply_logistics(WC_Order $order, string $status, string $date, bool $notify): array // returns [old_status, old_date]
```

- The metabox save keeps its nonce and `$_POST` handling, then delegates to `apply_logistics()`. Metabox behaviour is unchanged.
- `apply_logistics()` keeps both existing side effects: the optional customer notification and the `muse_send_sc_automation('backordered')` action.
- Ledger "Set ETA" calls `apply_logistics($order, 'backordered', $date, $notify_customer)`:
  - The date must be today or later (ET).
  - `$notify_customer` is the billing-email checkbox (§8.1), **unchecked by default**.
  - The Service Channel send is not a parameter. It follows the existing rules in §2.
- After the call, append `eta_set` with the before/after values and `{sc_expected: bool}`. `sc_expected` is true when the order has an SC work order and the dedupe hash differs, i.e. when the existing automation would send. Then run `project()` + `resolve()`.

### 6.2 Tracking — not in v1 for the agent

Tracking stays in the partial-ship tooling, which staff use with Woo access. The agent has no Woo capabilities.

If an "Add Tracking" button is added later, first extract `Muse_Partial_Ship_Plus::record_shipment(WC_Order, array $items, string $carrier, array $tracking, string $other, bool $notify)`. The metabox and the REST handler currently duplicate this logic, so both should delegate to the new method, and the ledger then calls it too.

### 6.3 Human-only actions

`email_logged`, `call_logged` and `note` are appended directly to the events table, because no other canonical business object exists for them. Text is limited to 2,000 characters and passed through `sanitize_textarea_field`.

---

## 7. Access control

### 7.1 Capabilities and roles

Registered on module version bump.

| Role | Capabilities |
|---|---|
| `musesys_ledger_agent` | `read`, `musesys_ledger_view`, `musesys_ledger_manage` |
| `musesys_ledger_viewer` | `read`, `musesys_ledger_view` |
| `administrator` | Both capabilities are added to the role (no special-casing in code) |

- The agent account gets **only** `musesys_ledger_agent`. No Woo capabilities.
- Kevin keeps his account and gets `musesys_ledger_viewer` added as a secondary role. If his account is already an administrator, nothing changes.

### 7.2 Agent lockdown

**Who is locked down:** users who **have the `musesys_ledger_agent` role** and **do not have `manage_options`**.

- The trigger is the role, not the capability pair. Capabilities drift: a future role might be granted `musesys_ledger_manage` for good reasons and must not be thrown into an app-only jail.
- A user who has the manage capability without the agent role can use the ledger, but gets normal WordPress navigation.
- The check is one function, `Ledger_Access::is_locked_agent(WP_User): bool`. Every lockdown hook calls it, and no hook repeats the logic.

The rules for locked agents:

- `login_redirect` → `admin.php?page=musesys-ledger`.
- `admin_init`: any request to a wp-admin screen other than the ledger page → `wp_safe_redirect` to the ledger page.
  - Allowed: `admin.php?page=musesys-ledger` and `admin-ajax.php` (filtered below). **`profile.php` is not allowed** (see §14 B1). Password changes use the standard "Lost your password?" flow on the login screen.
  - `admin-post.php` is not used.
- `woocommerce_prevent_admin_access` → false for anyone with `musesys_ledger_view`. Without this, WooCommerce bounces users who lack `edit_posts`, `manage_woocommerce` and `view_admin_dashboard` out of wp-admin, which covers both the agent and a viewer-only account.
- `admin-ajax.php`: allowed only when `action` is one of `musesys_ledger_list`, `musesys_ledger_history`, `musesys_ledger_log_email`, `musesys_ledger_log_call`, `musesys_ledger_note`, `musesys_ledger_set_eta`, or `heartbeat`. Anything else → `wp_die(403)`.
- Every ledger AJAX handler checks independently: `check_ajax_referer`, `is_user_logged_in()`, the required capability (`view` for reads, `manage` for writes), and an `order_id` that has open ledger rows.
- Front-end requests (storefront, my-account) redirect to the ledger page, except logout.
- `rest_authentication_errors`: deny REST for this role, because the ledger uses AJAX only.
- `show_admin_bar` → false. Admin menu reduced to the ledger page. Dashboard widgets removed.
- Menu: a **top-level** menu item gated on `musesys_ledger_view`. The existing `MuseSys` parent menu requires `manage_options`, so the ledger cannot sit under it for these users.

---

## 8. UI

The ledger is one screen, `admin.php?page=musesys-ledger`. The table is rendered client-side from `musesys_ledger_list`.

- **Summary strip** (all viewers): Needs ETA · Call Required · Partial (some qty shipped) · Shipped / Not Closed · 2+ business days late · Actions today.
- **Admin/viewer only:**
  - Per-user counts for today and the trial period (emails, calls, ETAs set, notes).
  - The `MISSING_TRX` and `EXCEPTION` buckets.
- **Main table** — lines grouped under their order, because ETA and contact are per order:
  - Columns: `TRX | PO | Order | Store | Part | Qty (2 / 5 shipped · 3 remaining) | ETA (Oct 4 · 2 bd overdue) | Last Contact (Email Sep 29 · Call Sep 30) | State | Actions`
  - The State badge has visual priority. CALL_REQUIRED shows its reason inline: "Email sent Sep 29 9:42 AM · no response after 1 business day".
  - Default sort: CALL_REQUIRED, then NEEDS_ETA, then SHIPPED_NOT_CLOSED; ties by `overdue_bdays` descending.
- **Actions** (manage capability only): Log Email · Log Call · Set ETA · Note. There is no generic Edit.
- **Expanding a row** shows the event history inline, newest first: system and human events, actor, timestamp, payload text, and the shipment list with tracking links read from `_muse_shipments`.
- **Viewer** (`view` capability only): same page, action buttons not rendered, and write endpoints reject the request server-side.

### 8.1 Set ETA dialog

The business change and its outbound effects are separated visually, and the wording follows what the code actually does.

```
Set ETA — Order #12345 · TRX 778812

New ETA: [ 2026-10-08 ]
Applies to all open lines on this order (3 lines).

What happens when you save
  • Service Channel will be sent a backorder update with this date.        ← shown only if sc_expected
    (Automatic for orders with a Service Channel work order. Same as UACP.)
  • No Service Channel work order on this order, so no Service Channel     ← shown otherwise
    update will be sent.

☐ Also email the customer contact (billing email) about this update

[ Update ETA ]
```

- The dialog evaluates `sc_expected` when it opens, using the same check as §6.1.
- There is **no** "Send Service Channel update" checkbox. That send is not optional in the existing mutation, in UACP or anywhere else. A checkbox would imply a choice that the code does not honour. Making it optional would change existing logistics behaviour for every caller. That is out of scope (§1.1, §11 O6).

---

## 9. Rollout and backfill

1. On activation, a backfill runs `project()` + `resolve()` for all open orders in batches of 50 via Action Scheduler. Backfill events carry `source = 'backfill'`.
2. Expected effect: lines that are `in_stock` but unshipped and created before today become NEEDS_ETA immediately. Under the approximation in §4.2, that is the earliest possible version of the current state, not proven truth. Review the counts with the operator before giving the agent access.
3. Create the agent account (operator), assign the role, and verify the lockdown (§10.3).

---

## 10. Verification plan

### 10.1 Resolver unit tests (PHP CLI, no WP)

File: `modules/musesys-ledger/tests/resolver_test.php`. It must live in a subfolder, because the mega loader auto-includes every `modules/*/*.php`.

Run with:

```
php modules/musesys-ledger/tests/resolver_test.php
```

Cases:

- Backordered with a future ETA → ON_TRACK
- ETA passed, no contact → NEEDS_ETA, `due_at` = ETA 23:59:59 ET
- Backordered with no ETA → NEEDS_ETA immediately
- In stock, confirmed today → ON_TRACK; confirmed yesterday, unshipped → NEEDS_ETA
- Email before expiry, then expiry → NEEDS_ETA (email does not count)
- Email after expiry, < 1 business day → NEEDS_ETA; ≥ 1 business day → CALL_REQUIRED
- Email Friday 15:00 → CALL_REQUIRED Monday 15:00, not Saturday
- Call after the email → NEEDS_ETA; call before the email → still CALL_REQUIRED
- New future ETA while CALL_REQUIRED → ON_TRACK
- `qty_shipped = qty_ordered` on an open order → SHIPPED_NOT_CLOSED
- Partial (2/5) with ETA passed → NEEDS_ETA, still actionable
- TRX empty → MISSING_TRX regardless of other inputs
- `replaced` / `obsolete` → EXCEPTION
- TRX `99999` → CLOSED
- In stock with a future ETA → flag `instock_future_date`, `due_at` from the ETA
- Note events never change the output

### 10.1b Projector diff and idempotency tests (PHP CLI, no WP)

The diff step is a pure function, `Ledger_Projector::diff(array $stored_row, array $source_snapshot, array $last_events): array{row_updates, events}`. It is kept separate from the WordPress loading so it can be tested on its own.

File: `tests/projector_test.php`. Cases:

- The same snapshot diffed twice → the second run returns **zero events**.
- ETA A→B → one `eta_changed`. Replaying the same input → no event. Then B→A → one event; A→B again → one event, with a different `dedupe_key` from the first A→B.
- A shipment appended → one `shipment_recorded` per line touched. Re-diff → none.
- Two identical shipment entries at different indexes → two events (faithful to the source).
- An `eta_set` key generated by the UI equals the key that the projector's follow-up `eta_changed` would generate → that follow-up is dropped.
- A changed resolver version, with no state change in the result → no `state_changed`.

### 10.2 Integration (staging)

**Reconcile idempotency — required before go-live:**

1. Record the event count, and a checksum of all ledger rows excluding the `*_at` bookkeeping columns.
2. Run `musesys_ledger_reconcile` twice back to back, then `musesys_ledger_full_rebuild`, then the backfill again.
3. Expected: the event count is unchanged, and the checksum is unchanged.
4. Then trigger the immediate hook and the reconcile concurrently on one order whose ETA has just changed (two WP-CLI processes). Expected: exactly one `eta_changed`.

- Save TRX and ETA through UACP → the row appears without waiting for the reconcile.
- Partial-ship metabox 2/5 → `qty_shipped = 2`, `shipment_recorded` event.
- Ledger Set ETA → `_muse_logistics_*` updated, SC automation fired once, `eta_set` event, state ON_TRACK.
- Wait for a due date to pass (or clock-inject in the reconcile) → `state_changed` event to NEEDS_ETA.

### 10.3 Access

As the agent:

- Visiting `edit.php`, `admin.php?page=wc-orders`, `users.php`, `/wp-json/wc/v3/orders`, `/my-account/` → redirected or 403.
- Any non-ledger AJAX action → 403.
- A write without a nonce → 403.

As Kevin (viewer): write endpoints → 403.

Lockdown keyed on the role, not on capabilities (§7.2):

| Account | Expected |
|---|---|
| Agent role only | Locked (all of the checks above) |
| Agent role + an accidental extra capability (e.g. `edit_shop_orders`) | Still locked, with no redirect loop, and the ledger still works |
| Agent role + `manage_options` (admin who also holds the role) | Not locked. Normal wp-admin, ledger usable |
| `musesys_ledger_manage` granted via some other role, no agent role | Not locked. Ledger usable, normal navigation |
| Viewer role on an existing staff account | Not locked. Ledger visible read-only, normal navigation |

---

## 11. Open items (non-blocking; defaults shown)

| # | Item | v1 default |
|---|---|---|
| O1 | `replaced` / `obsolete` lines need someone to act, but on order editing, not ETA chasing | EXCEPTION bucket, admin/viewer only |
| O2 | Holiday calendar for business days | None in v1 (Mon–Fri only) |
| O3 | Move the twice-daily report (`musesys-reports`) onto ledger queries | **Out of scope for v1.** The report keeps its own age-based rules. Phase 2 makes it a second presentation of `SELECT … FROM musesys_ledger WHERE actionable = 1`. |
| O4 | Add Tracking for the agent | Not in v1 (§6.2) |
| O5 | Cairn export of `musesys_ledger_events` | Not in v1. The schema is shaped for it (actor, timestamp, structured payload, `order_item_id`). |
| O6 | Should Service Channel backorder updates be suppressible per ETA change? | No. Existing behaviour is kept for every caller. Changing it is a logistics-manager decision, not a ledger one. |

---

## 12. Defects and risks found while grounding this spec

These are **not fixed** by this work. They are listed here because they affect ledger correctness.

| # | State | Finding | Impact on ledger |
|---|---|---|---|
| R1 | HYPOTHESIS | `Muse_Partial_Ship_Plus::save_metabox` is hooked on **both** `save_post_shop_order` and `woocommerce_process_shop_order_meta` (`musesys-partial-ship/index.php:26,28`). On legacy (non-HPOS) storage, one save fires both hooks and the handler appends a shipment each time. Item quantities get clamped to what remains, but tracking numbers are not, which can leave a duplicate tracking-only shipment. | `tracking_count` could be inflated. To verify on staging, save one shipment and count the `_muse_shipments` entries. `qty_shipped` is safe because of the clamp. |
| R2 | FACT | REST `ship-lines` builds shipment records **without** a `date` field (`index.php:711-716`). The metabox includes one. | Shipment events from the REST path cannot show a ship date. The projector records the `shipment_recorded` time when it sees the change. |
| R3 | FACT | UACP's generic meta endpoint writes with `update_post_meta()` and fires no Woo order hooks. | Handled by the meta hooks in §4.1. If HPOS is on with sync disabled, these writes would not reach order meta at all. That is a separate investigation, not part of this lane. |
| R4 | FACT | No business-day logic exists anywhere in the codebase. | The resolver owns it (§5.1). |

---

## 13. Files to create or change

```
modules/musesys-ledger/
  index.php                         bootstrap; requires includes/* (loader only auto-loads modules/*/*.php)
  includes/class-ledger-schema.php  dbDelta + version option
  includes/class-ledger-events.php  append-only writer/reader
  includes/class-ledger-state-resolver.php   pure, no WP calls
  includes/class-ledger-projector.php        project(), hook collection, shutdown flush
  includes/class-ledger-scheduler.php        Action Scheduler registration, reconcile, backfill
  includes/class-ledger-access.php           roles/caps, redirects, ajax whitelist, REST deny
  includes/class-ledger-ajax.php             six AJAX handlers
  includes/class-ledger-page.php             admin page shell
  assets/ledger.js, assets/ledger.css
  tests/resolver_test.php
  tests/projector_test.php
  tests/integration_test.php                 SQL-level, real MySQL via mysqli; stubbed WC_Order (§14)
  tests/lib.php, tests/stubs/                test helpers

modules/muse-logistics-manager/index.php     extract apply_logistics(); metabox delegates (behaviour unchanged)
CORE_META_STANDARDS.md                       add §7 "Ledger tables" (no new meta keys; tables are projections + event log)
```

No other module is modified in v1.

---

## 14. As built (v1, 2026-10-01)

### 14.1 Where the build differs from the text above

| # | Spec said | Built | Why |
|---|---|---|---|
| B1 | Agent may open `profile.php` (password change only) | Agent may **not** open `profile.php` | WordPress cannot limit that screen to the password field. Allowing it would let the agent edit their email and display name. Password resets use the login screen's lost-password flow. |
| B2 | `eta_set` written by the UI handler; the projector's `eta_changed` collides with it | `eta_set` is emitted **by the projector diff itself**, when the AJAX handler calls `project()` with `attribution = eta_set` | If the handler appended `eta_set` first, the projector's follow-up `prev_event_id` would point at that new event. The key would then differ and the event would **not** collide. With one emission point and one key function, a UI change, a hook and a reconcile all produce the same key. Edge case: if a reconcile lands between `apply_logistics()` and the handler's `project()`, the single event is recorded as `eta_changed` (system) rather than `eta_set`. It is never duplicated. |
| B3 | `diff(array $stored_row, …)` | `diff(array $stored_rows_by_item, array $snapshot, array $ctx)` | ETA, TRX and confirmation are order-scoped (D6, D7), so the diff needs every line of the order. |
| B4 | Tracking links in the expanded row | Tracking numbers and carrier shown **as text**, with no carrier links | The URL builder is private to `musesys-partial-ship`. Duplicating it would be the kind of drift §6.2 warns about. |
| B5 | (not covered) | An unrecognised `_muse_logistics_status` value → `EXCEPTION` / `unknown_logistics_status` | UACP writes raw meta, so unexpected values are possible. They surface to admins instead of being guessed at. |
| B6 | `resolved_at` = bookkeeping | Rows whose resolved columns are unchanged are **not rewritten**, so `resolved_at` = the last time the resolution changed | Avoids rewriting every open row every 15 minutes. |
| B7 | (not covered) | A new row starts with `state = ''`. Its first resolve appends `state_changed` with `from: null` ("Entered ledger: …") | This gives every line a starting point in its history. |
| B8 | (not covered) | First sight of an order emits a baseline `trx_assigned` (when a TRX exists) and `eta_changed` (only when an ETA exists or the status is not `in_stock`). Existing shipments emit `shipment_recorded` with `shipped_at` from the source. | This makes the backfill an explicit, dated baseline rather than silent state. |
| B9 | Agent sees "SHIPPED_NOT_CLOSED (informational)" | The agent list shows `CALL_REQUIRED`, `NEEDS_ETA` and `SHIPPED_NOT_CLOSED`. Admins and viewers get tabs: Work queue / On track / Missing TRX / Exceptions. | Implements the visibility column of §5.2. |

### 14.2 Verification performed

| Check | Result |
|---|---|
| `php modules/musesys-ledger/tests/resolver_test.php` | 24 passed, 0 failed |
| `php modules/musesys-ledger/tests/projector_test.php` | 12 passed, 0 failed |
| `php modules/musesys-ledger/tests/integration_test.php` against a throwaway MySQL 8.4.3 (scratch data directory, port 3399) | 15 passed, 0 failed. Covers dbDelta DDL, the unique `dedupe_key` index, guarded updates, `INSERT IGNORE` replay, a simulated race, `project` ×2 + `resolve_all` ×2 + resolver-upgrade pass with no new events and a stable checksum, CALL_REQUIRED from a stored email, call → NEEDS_ETA, notes inert, shipment 2/5, full ship → SHIPPED_NOT_CLOSED → CLOSED, backfill-approximate `confirmed_at`, line removal |
| `php -l` on every module PHP file + `muse-logistics-manager/index.php`; `node --check assets/ledger.js` | clean |
| Bootstrap smoke-load with WordPress hooks stubbed | all 8 classes load; every AJAX and scheduler callback is callable |

The integration harness's first run failed because the fixture reused line-item IDs across orders. The ledger's `UNIQUE(order_item_id)` correctly refused the duplicates. Woo item IDs are globally unique, so this was a fixture error; it was fixed in the fixture, not in the code.

### 14.3 Not verified — required before the agent gets access

- **Nothing has run inside real WordPress + WooCommerce + Action Scheduler.** No local WooCommerce install exists; `C:\laragon\www\Wegmans-Local` has none.
- **Not exercised end to end:**
  - the AJAX endpoints
  - the admin page and JS
  - the lockdown hooks
  - the real `$wpdb->prepare()`
  - `wc_get_orders` date queries
  - Action Scheduler scheduling and batches
  - HPOS
- **Not yet run:** every staging check in §10.2 and §10.3, including two-process concurrency and the account matrix.
- **Not confirmed:** whether R1 (duplicate shipments from the double-hooked metabox) occurs on production storage.
- **UI not reviewed visually.** No screenshot review was possible without a running site.

### 14.4 Morning digest (added 2026-10-01 after the staging review)

Requested by Kevin after the first staging run: a summary-strip snapshot emailed to the ledger agent each morning.

- **Recipients.** Every user with the `musesys_ledger_agent` role, read at send time. No hard-coded addresses; nobody else receives it.
- **Separate from `musesys-reports`.** It has its own Action Scheduler hook (`musesys_ledger_morning_digest`, group `musesys-ledger`) and its own recipient source. The reports' on/off toggle does not affect it, and the order/PR report emails are unchanged.
- **Schedule.** Weekdays at 07:00 America/New_York. It is a self-rescheduling single action, so the send time stays 07:00 local across daylight-saving changes. (A fixed 24-hour recurrence would drift by an hour.)
- **Counts.**
  - Before building the email, open rows are re-resolved, so overdue numbers reflect this morning.
  - Counts come from `Ledger_Ajax::rows_for(true)` + `summarize()`, the same code that draws the agent's on-screen strip.
  - The sixth tile is **actions on the previous business day** instead of "Actions today", which would always read 0 at 07:00.
- **Link.** A button plus a plain URL to `admin.php?page=musesys-ledger`. A signed-out agent is sent to the login screen and returned to the ledger by the existing `login_redirect` rule.
- **Manual send** (staging check): `wp eval 'echo Ledger_Digest::send();'` prints the number of recipients mailed.
- **Tests.** `tests/digest_test.php` → 6 passed: send-time calculation including the 2026-11-01 DST change and weekends, the previous-business-day window, render (all tiles, counts, escaped link), and the subject.
- **Not verified:** a real send through `wp_mail` on staging, and rendering in Outlook.

### 14.5 "Coming due" tab (added 2026-10-22)

Enables the locked agent to view on-track orders due within the next 2 business days before they become overdue.

- **Definition.** A row is coming due when `state = 'ON_TRACK' AND due_at IS NOT NULL AND due_at <= cutoff`, where cutoff is 23:59:59 ET of the business day 2 business days after `now`, converted to UTC (`COMING_DUE_BDAYS = 2`).
  - Thursday covers Thu, Fri, and Mon (through Monday 23:59:59 ET).
  - Friday 4pm covers through Tuesday 23:59:59 ET.
  - Saturday covers through Tuesday 23:59:59 ET.
  - Rows due today are included; rows due on the 3rd business day are excluded.
  - Implemented in pure static helpers: `Ledger_State_Resolver::coming_due_cutoff()` and `Ledger_State_Resolver::is_coming_due()`. Neither `resolve()` nor `Ledger_State_Resolver::VERSION` are modified.
- **Server (`class-ledger-ajax.php`).**
  - `list_payload()`: for locked agents, appends rows from `rows_coming_due()` to the payload. All rows are marked with `'coming_due' => 1` or `0` via `present_row()`. Admin/viewer payloads mark them as well.
  - `summarize()`: summary strip counts remain byte-for-byte unchanged. Coming-due rows are excluded from every existing count in both agent and admin views.
  - `Ledger_Digest`: unchanged; continues calling `rows_for(true)` to count only active work-queue states (`CALL_REQUIRED`, `NEEDS_ETA`, `SHIPPED_NOT_CLOSED`).
  - `history()`: allows requests from locked agents for rows that are coming due in addition to `AGENT_STATES`.
  - Writes: non-closed orders remain writable, allowing actions (email/call/note/ETA) on coming-due orders.
- **Client (`assets/ledger.js`).**
  - Agent view renders two tabs: "Work queue" (default) and "Coming due".
  - Admin view renders "Coming due" alongside existing tabs (Work queue, Coming due, On track, Missing TRX, Exceptions).
  - Coming-due rows are sorted by `due_at` ascending.
  - Empty state for coming due: "Nothing due in the next 2 business days."
  - No chip or count added to the summary strip.
- **Tests.**
  - `tests/coming_due_test.php` (9 passed, 0 failed): Thursday covers Thu/Fri/Mon, Friday covers through Tue EOD, Saturday covers through Tue EOD, due today included, 3rd business day excluded, non-ON_TRACK excluded, null due_at excluded, `summarize()` exclusion verified, `present_row()` marking verified.
  - `tests/integration_test.php` (16 passed, 0 failed): verifies MySQL query in `rows_coming_due()` finds only ON_TRACK rows within 2 bdays and `rows_for(true)` excludes them.
  - Full suite verification: all 75 unit and integration tests pass cleanly with 0 regressions.

---

## 15. Proposed updates from an outside system (added 2026-11-23)

Status: **ON PRODUCTION since 2026-11-23 11:17 ET (module 1.1.0, §15.11), with nothing sending to it yet.** Run on the staging clone first (§15.10). Unit- and SQL-level tests
pass (§15.8). Built in `plugins/musesys-general/modules/musesys-ledger/`; production runs `musesys-mega` 1.2.11 and
does not have it. The staging run found one defect in the approval, fixed the same day (§15.5 steps 1 and 4).

### 15.1 Rule

> An outside system never writes order data. It files a **proposal**. A person approves or rejects it on the
> ledger. Approval runs the existing shipment mutation, the same one UACP calls.

The first outside system is Cairn, which can tie a vendor's shipment notice or our own UPS label to an open order
by TRX # or PO #. The mechanism is not Cairn-specific: `source` is free text and every site running this module
gets the same endpoints. The ledger still stores no canonical business data: a proposal is a request, and the
shipment lives where it always has (`_muse_shipments`).

This supersedes three v1 non-goals in §1.1 for this path only: "No tracking entry and no shipment recording",
"No Cairn export" and "No changes to … endpoints". No existing endpoint was changed; four were added. The agent
(D5) still cannot record a shipment: approving needs a capability the agent role does not have (§15.4).

v1 handles one kind: `shipment` (carrier + tracking numbers, optionally lines). TRX #, PO # and ETA proposals are
not built.

### 15.2 Data model

`{prefix}musesys_ledger_proposals`, created by `Ledger_Schema` (schema version `1.0.0` → `1.1.0`):

| Column | Meaning |
|---|---|
| `source`, `source_ref` | Who filed it and their own id for it. **Unique together: the idempotency key.** |
| `order_id`, `kind` | The open order it is about; `shipment`. |
| `payload_json` | `{carrier, other_carrier, tracking_numbers[], lines[{order_item_id, qty}]}`. Lines may be empty. |
| `summary`, `evidence_json` | Display text for the reviewer (why the system believes this). Tags stripped, 8,000 bytes at most. Never executed. |
| `status`, `status_note` | `pending` → `applying` → `applied`, or `rejected`, or `superseded`. The note carries a reject reason, a failure, or why it was closed. |
| `created_by`, `decided_by`, `decided_at`, `result_json` | The filing account; the approver or rejecter; what the mutation returned. |
| `created_at`, `updated_at` | UTC. `updated_at` is what a poller filters on. |

Status changes are guarded updates (`… WHERE id = ? AND status = ?`), so two approvals, or an approval and a
reject or withdraw, cannot both win.

### 15.3 REST contract (namespace `musesys/v1`, capability `musesys_ledger_propose`)

| Route | Purpose |
|---|---|
| `GET /ledger/open-orders` | Every open order as the ledger sees it: TRX, PO, drop-ship flag, store, tenant references (`musesys_order_references` filter, e.g. Work Order #), lines with ordered / shipped quantity, tracking already recorded, pending proposal refs. The outside system proposes against this, not against its own copy of the order. |
| `POST /ledger/proposals` | File a proposal. `201` when created, `200` with the stored proposal when the same `source` + `source_ref` was filed before (nothing is overwritten). `400` invalid, `404` no such order, `409` order not open in the ledger, `422` a proposed line is not an open line of that order. |
| `GET /ledger/proposals?status=&source=&order_id=&updated_since=&limit=` | Read decisions. With `updated_since` the list is oldest change first, for polling. |
| `GET /ledger/proposals/{id}` | One proposal. |
| `POST /ledger/proposals/{id}/withdraw` | The filing system takes back a **pending** proposal (`{reason}`); `409` otherwise. |

Request body for a proposal:

```json
{
  "source": "cairn",
  "source_ref": "pkg-8f3a…",
  "order_id": 238207,
  "kind": "shipment",
  "carrier": "UPS",
  "tracking_numbers": ["1Z…"],
  "lines": [{"order_item_id": 9911, "qty": 2}],
  "summary": "Vendor shipment notice, PO CH-123456",
  "evidence": {"vendor": "…", "po": "CH-123456", "observed_at": "2026-11-20T14:02:00Z"}
}
```

- `carrier` ∈ `UPS`, `FEDEX`, `USPS`, `DHL`, `LTL`, `OTHER` (`other_carrier` required with `OTHER`). The internal
  hand-over codes `TECH_DELIVERY` and `CUSTOMER_PICKUP` cannot be proposed.
- 1 to 10 tracking numbers; duplicates (compared on letters and digits only) are dropped.
- A proposal whose tracking numbers are all already on the order is stored as `superseded` ("Already recorded on
  the order."), so the filer learns the outcome and no one is asked to review it.

There is no approve route. An outside system cannot approve.

### 15.4 Access

| Capability | Granted to | Allows |
|---|---|---|
| `musesys_ledger_propose` | new role `musesys_ledger_service` ("Ledger Integration": `read` + this, no Woo capabilities); `administrator` | The REST routes in §15.3 |
| `musesys_ledger_approve` | `administrator` only | Approve / reject on the ledger screen |

- The roles version goes `1` → `2`, so the role and capabilities are registered on the next request after deploy.
- **Who may approve is a default, not a decision.** Granting `musesys_ledger_approve` to the agent role would let
  the agent record shipments, which D5 and §6.2 rule out today. Approval also runs the ship-lines endpoint as the
  approving user, and that endpoint demands `manage_woocommerce` or `edit_shop_orders`; the agent has neither.
- The locked agent can open the tab and read proposals (`musesys_ledger_proposals` is on the AJAX whitelist).
  The approve and reject actions are not whitelisted and also fail the capability check.
- The outside system should use its own account with the service role and an application password. Cairn
  currently signs in as an administrator; that works (admins hold the capability) but is more than it needs.

### 15.5 Approving

Screen: a **Proposed updates** tab (count badge) for every ledger viewer. Waiting proposals show order, TRX, PO,
what is proposed, why (summary + evidence), and for approvers **Approve…** / **Reject…**. Below: decided in the
last 7 days.

Approve dialog: one quantity box per open line (pre-filled with the proposed quantity, or with everything left
when the proposal names no lines, with a warning to check it), a "What happens when you approve" list stated as
fact (recorded like UACP; Service Channel parts-shipped update when the order has an SC work order; completed
when everything left ships), and "Email the customer this shipment update", **checked by default** because every
UACP caller sends `notify: true`.

Server side (`Ledger_Proposals::approve`):

1. The proposal must be `pending`; the order must still be open; none of its tracking may already be on the
   order (else it is closed as `superseded`); the confirmed lines must be open lines with quantity ≤ what is left,
   and at least one must be above zero. **If the confirmed lines ship everything left and the order has no TRX #
   (or is drop-ship with no PO #), the approval is refused (`409`) before anything runs**
   (`completion_blocker()`, mirroring `muse_block_order_completion_if_fields_missing`): that order cannot
   complete, and its refused save would throw the shipment away (R5, §15.10). Shipping less than everything on
   such an order is still allowed.
2. Claim it (`pending` → `applying`).
3. Call `POST musesys/v1/orders/{id}/ship-lines` **in-process** (`rest_do_request`) as the approving user, with
   `closeout: false`. Nothing about recording a shipment is reimplemented; §6.2's `record_shipment()` extraction
   was not needed and partial-ship was not touched. Side effects are therefore exactly the existing ones: order
   note (customer-visible when notify is on), status recompute, `muse_send_sc_automation('parts_shipped')`.
4. **The order decides, not the response.** After the call the order is re-read: only when every proposed
   tracking number is on it is the proposal `applied` (`result_json` takes the order's real status), followed by
   `project()` so the row and its `shipment_recorded` event are current in the response.
5. Tracking not on the order → back to `pending` with "Last attempt failed: …", whatever the endpoint answered;
   it can be retried or rejected. An error answer with the tracking on the order → `applied`, the error kept as a
   warning in `status_note`.

Reject needs a reason, which the filing system reads back.

### 15.6 Events

Order-scoped, in `musesys_ledger_events`, one per proposal per step (`dedupe_key = prop:{id}:{action}`, plus the
attempt number for failures):

| action | source | When |
|---|---|---|
| `proposal_received` | the filer's `source` | Filed (also when filed already-closed) |
| `proposal_approved`, `proposal_rejected`, `proposal_failed` | `ledger_ui`, with the user | A person decided, or an approval failed |
| `proposal_withdrawn` | the filer's `source` | Withdrawn over REST |
| `proposal_superseded` | `projector` | Closed by housekeeping |

Approvals and rejections are `ledger_ui` events, so they count in "Actions today" and in the digest's
previous-business-day tile. The per-user table (emails / calls / ETAs / notes) is unchanged.

### 15.7 Housekeeping

`Ledger_Proposals::settle()` runs before every list (screen and REST):

- `applying` for more than 10 minutes (the approving request died): tracking on the order → `applied`; not on
  the order → `pending` again.
- `pending` whose tracking a person has since typed in, or whose order is no longer open → `superseded`.

It is not on a schedule: a proposal nobody lists stays as it is until someone or something lists.

### 15.8 Verification performed (2026-11-23, PHP 8.3.16, MySQL 8.4.3 scratch instance on port 3399)

| Check | Result |
|---|---|
| `php tests/proposals_test.php` (pure: validation, tracking comparison, line suggestion and confirmation, completion blocker) | 14 passed, 0 failed |
| `php tests/proposals_integration_test.php` (real schema, proposals, projector and events code against MySQL; the ship-lines call replaced by a stand-in that edits the stub order) | 20 passed, 0 failed |
| Existing suite: resolver 24, projector 12, audit 8, coming_due 9, digest 6, integration 16 | all pass; 75 → 109 tests in total |
| Deliberate breakage: guarded status update removed → 10 cases fail; duplicate-tracking check removed → its case fails | tests catch both |
| `php -l` on every module file; `node --check assets/ledger.js` | clean |

Test changes to existing files: the stand-ins and DB connection moved unchanged from `integration_test.php` into
`tests/harness.php` (shared by both SQL-level tests); `integration_test.php` now drops the proposals table too
and asserts the schema version against `Ledger_Schema::VERSION` instead of the literal `1.0.0`.

### 15.9 Not verified, not done

- **The three AJAX endpoints have not been called over HTTP** (`admin-ajax.php` with a real nonce and cookie).
  Their handlers' logic ran on staging through the same methods, and the screen script was rendered against
  real staging data in a local page (§15.10), but nobody has yet clicked Approve in a signed-in browser.
- **On production since 2026-11-23 (§15.11); the text of this bullet is kept for the staging history.** Before that release production ran `musesys-mega` without this module. The staging clone has it (copied
  into `musesys-mega/modules/musesys-ledger`); the next `~/bin/sync-staging.sh` run will overwrite it with
  production's copy. The copy under `legacy/musesys-mega/` in this repository was not changed.
- **A dedicated service account was not created.** The HTTP run used Cairn's existing administrator
  application password. The `musesys_ledger_service` role exists and has exactly `read` + propose, but no user
  holds it, so "a service-role user can file and nothing else" is untested end to end.
- **A real Service Channel order was not approved.** The test orders had no SC work order; that the
  parts-shipped update goes out (and is blocked on staging) rests on reading the code.
- **The Cairn side is not built**: nothing files proposals yet.
- **Decisions still open:** who gets `musesys_ledger_approve`; whether the "email the customer" box should
  start checked; whether `settle()` should also run on the 15-minute reconcile.
- No holiday calendar, no per-user approval counts, no proposal for TRX #, PO # or ETA.

### 15.10 Staging run (2026-11-23, clone `rxdetsjact`, WordPress 7.1.5, WooCommerce 11.3.1, PHP 8.2.36, HPOS on)

Mail and webhooks on the clone are blocked by `zz-staging-guard.php` (own database, checked before every write).
The previous module is kept at `private_html/ledger-backups/musesys-ledger-pre-d493e87-20261123-1435.tgz`.

| Check | Result |
|---|---|
| Deploy over the existing module; server-side `php -l` on 25 files | clean; deployed tree identical to the commit |
| Install on the first request | schema `1.0.0` → `1.1.0`, proposals table with all 16 columns and the unique (`source`, `source_ref`) key; roles version `1` → `2`; existing 646 ledger rows / 3,777 events untouched |
| Roles | service role = `read` + propose only; agent = view + manage (no approve, no propose); administrator has all four |
| Routes and handlers | 4 REST routes and 3 AJAX actions registered; `ship-lines` present |
| Permission | signed-out `401`; ledger agent `403` on read and on file; agent has view, not approve |
| File, re-file, bad input, unknown order | `201` pending; `200` with the stored proposal; `400`; `404`; order untouched |
| Reject, withdraw, second withdraw | reason read back by the filer; `200` superseded; `409` |
| **Approve through the real in-process `ship-lines` call** (1 of 8, e-mail off) | shipment on the order with the proposed carrier and tracking; order `partially-shipped`; ledger row +1; `proposal_approved` + `shipment_recorded` events; no e-mail attempted |
| Approve with the e-mail box on | "Update: Partial Shipment" customer e-mail attempted and **blocked by the staging guard** |
| Approve everything left on a confirmed order | order `completed`, ledger rows `CLOSED`, a second waiting proposal on it closed by housekeeping |
| Tracking already on the order | filed `superseded`; an applied proposal cannot be approved again (`409`) |
| **Real HTTPS from Cairn #2** with its stored application password | no credentials `401`, wrong password `401`, sign-in `200`; `open-orders` 80 orders (38.7 kB, no BOM); file `201`, re-file `200`, poll by status and by `updated_since`, withdraw `200`, bad input a readable `400` |
| Screen (the shipped `ledger.js` + `ledger.css` in a local page fed with the payloads captured from staging; not a signed-in session) | tab with count badge, waiting and decided tables, Approve dialog (per-line quantities, effects text, submit disabled at zero), Reject dialog, proposal entries in a row's history; the posted parameters are what the handlers read; no script errors |

**Defect found and fixed.** Approving everything left on an order with **no TRX #**: the completion guard throws
inside `woocommerce_before_order_object_save`, WooCommerce swallows it, **nothing is saved** (no shipment, status
unchanged), and `ship-lines` still answers success with its in-memory "completed". The first build believed the
answer and marked the proposal `applied`. Fixed: the pre-check and the re-read in §15.5 steps 1 and 4; two
SQL-level cases and one unit case added; re-run on staging → refused with the reason, endpoint not reached, no
new order note. The one proposal the first build mis-marked on staging was corrected by hand.

**R5 (FACT for the first half, reading for the second).** The above is a property of the
existing `ship-lines` endpoint, so UACP is exposed to it too: shipping the last quantity of an order with no TRX #
(or a drop-ship order with no PO #) reports success while the order keeps nothing. By the code, the endpoint then
still fires `muse_send_sc_automation('parts_shipped')` when tracking was sent; that was not observed (the test
order had no Service Channel work order).

**R5 fix (2026-11-23, separate lane; committed as `bd5221e`, run on the staging clone the same day (results at the end of this section), NOT on production).**

*Mechanism, confirmed in the WooCommerce source* (`abstract-wc-order.php` and `class-wc-order.php`, tag 11.3.1,
the version the site runs, read 2026-11-23; the reviewing session checked the same on the installed copy on the
clone): `save()` fires `woocommerce_before_order_object_save` inside a `try`; an exception from a callback is
caught there, nothing is written, and `save()` returns normally. Since WooCommerce 11.2.2 the exception is kept
on the object (`get_last_save_error()`). The order notes are separate: `add_order_note()` writes at once, so the
old handler's "New shipment added" note (and the customer e-mail, with `notify`) went out before the save that
was then refused.

*A refused completion is still announced (WooCommerce behaviour, not changed by this fix).* In 11.3.1
`WC_Order::save()` is `parent::save(); $this->status_transition();` with no check of the save error. So after a
refused completion the hooks `woocommerce_order_status_completed`, `..._to_completed` and
`woocommerce_order_status_changed` fire, and the order gets the note "Order status changed from Processing to
Completed.", while the stored status is unchanged. Seen on the clone: order 240035 (a refused completion from the
morning of 2026-11-23, old code) carries "Failed to mark as complete...", "Error saving order ID 240035. TRX
Order # must be entered..." and "Order status changed from Processing to Completed.", and is still `processing`.
By the code this is true of every blocked completion, from the endpoint, the metabox or the order screen, with
or without this fix, and so of production today if it runs the same WooCommerce as its clone (production's
version was not checked). What listens:

- WooCommerce's own "completed order" e-mail to the customer is triggered by that hook. **Whether that e-mail
  is enabled on the site was not checked**, so whether customers get a "completed" e-mail for an order that did
  not complete is open.
- In this repository two listeners sit on `woocommerce_order_status_changed`: the activity tracker
  (`adm_order_status`, which logs a status change that did not happen) and the ledger projector (`queue`, which
  only queues the order id and reads the order later, so it records the stored status).
- WooCommerce trunk (read the same day) has changed this: `save()` runs the status-change step only when
  `get_last_save_error()` is null. A later WooCommerce update should therefore end the false announcement; the
  earlier version of this section wrongly described that trunk behaviour as what the site does.

*What changed in `musesys-partial-ship/index.php`:*

- `rest_ship_lines()` now saves in two steps. **Step 1** saves the shipment alone, with the status untouched,
  and reads the order back. If the shipment is not there the handler returns `WP_Error`
  `muse_shipment_not_saved`, HTTP `409`, with the refusing callback's message, and stops: no order note, no
  customer e-mail, no Service Channel update. **Step 2** applies the status (`closeout`, or the all-quantity-shipped
  rule) in a second save. If that save is refused, the shipment stays; the answer is still `200`, built from the
  order as stored, with two extra fields: `status_wanted` (`completed`) and `status_blocked` (the reason, e.g.
  "TRX Order # must be entered before completing the order."). `status` is then the status the order really has.
- After a refused status change the handler adds a **private order note**: "[Multi Shipment/API] The order was
  NOT changed to Completed; it is still Processing. Reason: ... Any note saying the status changed to Completed is
  wrong." It is written after WooCommerce's false "changed to Completed" note, so the order history ends on what
  is true. Chosen over leaving the false note as a documented limitation: one note, no customer e-mail, and it
  stays correct on WooCommerce trunk, where the false note no longer appears. It does not undo the hooks that
  already fired (above).
- Why two steps and not "check, then answer 409": the parts did ship. Losing the record because the order may
  not complete yet is the worse outcome, and a `409` for a shipment that was kept would invite a retry, which
  records the same tracking a second time (quantities are capped at what is left, tracking numbers are not).
- Why the blocked order keeps its old status rather than moving to `partially-shipped`: that would be a new
  status change with its own note and hooks, for an order where nothing is left to ship. It is a default, easy
  to change (one line before the second save).
- Service Channel is told when the shipment is in storage and carries tracking, whether or not the order
  completed. Before, it was told even when nothing was saved.
- Orders that may complete: same order note, same customer e-mail, same single status change, same response
  keys, same Service Channel update, no extra note. The difference is one extra save when the status moves (the
  ledger's `woocommerce_update_order` listener runs twice; it queues by order id).
- `save_metabox()` had the same defect (shipment lost, Service Channel still told). Only the Service Channel half
  is fixed there: it is now sent only for a shipment the order kept. **The metabox still loses the shipment when
  completion is refused**; the admin sees the red "TRX Order # must be entered" notice, a "Shipment Dispatch"
  note for a shipment that is not on the order, and WooCommerce's false "changed to Completed" note. The
  correcting note is not added there.
- New private helpers: `save_and_explain()` (the refusal reason from `get_last_save_error()`, else from the
  error WooCommerce logs) and `shipment_stored()`. The second reads the order again and **counts**: the stored
  list must hold the new shipment at least as often as the list just saved does. It no longer looks at one
  position, so a shipment that another request stored in front of this one cannot turn a kept shipment into a
  `409` (and a retry into a duplicate), and an identical earlier shipment cannot pass for one that was not saved.
- Known limitation, older than this fix: the shipment list is one meta value, read, appended to and written
  back with no lock. Two requests for the same order at the same moment can each write a list without the
  other's shipment; the later write wins. The read-back catches that only if the other write lands before it.
- `order-fields` (the guard) and the ledger module are unchanged. The ledger's up-front refusal (§15.5 step 1)
  is no longer needed to protect the shipment, but it still stops an approval that could not complete; whether
  to relax it is a ledger decision.

*UACP* (`plugins/musesys-wegmans/uacp/app`): `api_client.ship_lines()` returns `False` for any non-200 and only
printed the reason to the console. Shipment Desk and Active Orders ignored the result and showed "Saved" /
"Order updated successfully"; Order Guide left the cell uncoloured. So a `409` would have been silent. Changed:
the client keeps the site's message (`last_ship_error`, read through `bridge.ship_problem()`); Active Orders and
Shipment Desk raise into their existing error dialogs ("The shipment was NOT recorded on the order." plus the
reason); Order Guide colours the cell red and shows the same dialog. Shipment Desk's error dialog could never
appear before (its lambda read the exception after Python had discarded it); that is fixed in the same edit.
**Not changed:** UACP does not read the `200` body, so when a shipment is kept but completion is refused it
still says "Status → completed" and then reloads the order, which shows the real status. The MCP tool
`musesys_ship_lines` already raises on any error status and returns the body, `status_blocked` included.

*Verified (local, PHP 8.3.16, Python 3.14.3):*

| Check | Result |
|---|---|
| `php modules/musesys-partial-ship/tests/ship_lines_test.php` (new; the real `index.php` and the real guard from `order-fields`, against a stand-in order object that copies WooCommerce 11.3.1's refused-save behaviour, the status announcement after a refused save included; variants for before 11.2.2 and for trunk) | 23 passed, 0 failed |
| The same file against `index.php` from before the fix (`PSP_FILE=`) | the 9 normal-path cases pass unchanged; the other 14 fail (false `completed`, no error, Service Channel told) |
| The same file against three deliberately broken copies of the fix (kept outside the repository) | no correcting note → 2 cases fail; `shipment_stored()` at one exact position → the "another request's shipment" case fails; `shipment_stored()` matching anywhere without counting → the "identical earlier shipment" case fails |
| `php -l` on `index.php` and both test files; the ledger's `proposals_test.php` | clean; 14 passed |
| WooCommerce 11.3.1 on the clone, by the reviewing session: `get_last_save_error()` exists and is set only when nothing was persisted; `handle_exception()` logs with the context keys `order` and `error` that `save_and_explain()` reads | as the handler expects |
| UACP: `py_compile` on the five edited files; `ship_lines()` and `ship_problem()` with `requests.post` replaced (200, 409 with and without a BOM, 500 without JSON, network failure) | compiled; 9 checks pass |

*Not verified:*

- **The fixed handler was not run against WordPress or WooCommerce.** The stand-in order is a model. A staging
  run is needed for: a normal completion (order note, customer e-mail and status change once each, as before);
  the last quantity of an order with no TRX # (shipment kept, `200` with `status_blocked`; expect WooCommerce's
  "changed to Completed" note followed by the handler's "NOT changed to Completed" note, and the stored status
  unchanged); an order that refuses every save (`409`, nothing written); the metabox; and an order with a
  Service Channel work order, which has still never been exercised on staging.
- Whether the customer "completed order" e-mail is enabled on the site, and so whether a refused completion
  mails the customer (see above). On the clone all mail is blocked by `zz-staging-guard.php`, so a staging run
  shows whether it is attempted, not whether production sends it.
- That `wc_get_order()` after a save returns a fresh read on this site (HPOS on). If an object cache hands back
  the same object, the handler relies on `get_last_save_error()` alone; the test covers that path in the model.
- The UACP dialogs were not opened: no GUI run, no build. The three views were compiled, not exercised.
- `C:\Projects\UACP` (the repository UACP releases were cut from, last commit 2026-09-30) was not touched; its
  `api_client.py` was identical to this copy before the change.
- **Production runs `musesys-mega`**, whose frozen copy (`legacy/musesys-mega/modules/musesys-partial-ship/index.php`,
  byte-identical to this module before the fix) was not edited. It needs the same change at the next mega release.

Test data left on the clone: 12 proposals (3 applied, 2 rejected, 7 superseded, 0 waiting), two test shipments
on order 240019 (now partially shipped) and one on 240023 (now completed), all tagged `STAGING TEST`.

**R5 fix on the staging clone (2026-11-23 11:36 ET, `bd5221e`, handler copied byte for byte, previous one kept in
`private_html/partial-ship-backups/`).** Called through the REST layer inside WordPress as an administrator, plus
one real HTTPS call. Mail and webhooks blocked by the staging guard.

| Case | Result |
|---|---|
| Confirmed order, part of it | `200`, shipment stored, `partially-shipped`, nothing blocked; the six legacy answer keys present |
| Same order, the rest | `200`, `completed`, ledger rows `CLOSED`, no "NOT changed" note |
| **Order with no TRX #, everything left** | **`200`, shipment kept**, status still `processing`, `status_wanted: completed`, `status_blocked: TRX Order # must be entered before completing the order.` |
| Notes on that order, in order | the shipment note; "Email "Customer note" sent."; the guard's "Failed to mark as complete…"; WooCommerce's "Error saving order ID…"; WooCommerce's false "Order status changed from Processing to Completed."; then the handler's "The order was NOT changed to Completed; it is still Processing. Reason: …" |
| An order made to refuse every save (a hook in the test process only) | `409` `muse_shipment_not_saved` with the reason; nothing stored; status untouched; Service Channel hook not fired |
| Unknown order | `404` |
| Review queue on top (§15.5) | proposal approved through the new handler: `applied`, shipment stored, `partially-shipped` |
| Real HTTPS from Cairn #2 (administrator application password) | no credentials `401`; partial shipment `200`, stored, visible in `ledger/open-orders` |
| Staging error log | no PHP error written during the run |

What the run settled:

- **`wc_get_order()` after a save is a fresh read on this site** (HPOS on): the stored-shipment check saw each
  shipment, and saw none where the save was refused.
- **No "completed order" e-mail was attempted**, neither for the real completion nor for the refused one. The only
  mail attempted in the whole run was the customer note of the one call sent with `notify: true` (blocked by the
  guard). So on this configuration a refused completion does not mail the customer "completed". Production's
  e-mail settings were not read separately; the clone is a copy of them.
- **A second module fires the Service Channel hook on every status change**: `musesys-mega`'s
  `MuseSys-AfterOrder/muse-order-tracking.php` (not in `musesys-general`) does
  `do_action('muse_send_sc_automation', $order_id, $new_status)` on `woocommerce_order_status_changed`. So the
  refused completion also raises that hook with `completed`. `build_sc_event_payload()` has no case for it, so
  nothing is sent; by reading, not by an observed Service Channel order.
- The ledger shows the no-TRX order as `MISSING_TRX` (rule 2 comes before `SHIPPED_NOT_CLOSED`), with the
  shipment on it.
- A repeat of the same request with the same tracking and no lines stores a second, tracking-only shipment.
  That is the old behaviour (R1), and the reason the handler answers `200` and not an error once the shipment
  is kept.

**The order-screen path, fixed the same day (the commit after `bd5221e`).** The first staging run of
`save_metabox()` showed more than the known loss: for an order with no TRX #, everything left, nothing was stored,
yet the order got the "Shipment Dispatch … Tracking: …" note (written before the save) and WooCommerce's false
"changed to Completed". `save_metabox()` now works like the endpoint:

1. The shipment is saved on its own, the status untouched. If it is not in storage afterwards, nothing claims a
   dispatch: no note, no customer e-mail, no Service Channel update; the next admin page shows the notice
   "the shipment was NOT recorded" with the reason.
2. Only then is the status recomputed and saved. The status is set once and never set back (in WooCommerce 11.3.1
   `set_status()` records a change that `status_transition()` then announces).
3. A stored shipment with a refused status change gets the same private "The order was NOT changed to Completed;
   it is still …" note as the endpoint, after WooCommerce's false one, and an admin notice saying so.
4. Service Channel is told about every stored shipment with tracking, whether or not the order could complete.
5. The old rule stays: when the shipment asks for `completed`, the "Shipment Dispatch" note is not e-mailed to the
   customer, because the completed e-mail is meant to carry it. "Asks for" is decided before the status save, so
   it also applies when the completion is then refused.

Tests: 29 pass; 6 of them fail against the handler of `bd5221e`, 19 against the original one.

Second staging run (handler of the working tree, 11:53 ET), order screen called with the form fields it reads:

| Case | Result |
|---|---|
| Confirmed order, part, notify ticked | stored, `partially-shipped`, dispatch note written and its customer e-mail attempted (blocked by the guard) |
| Confirmed order, everything | stored, `completed` |
| **No TRX #, everything left** | **shipment kept**, status unchanged; notes end with the handler's "NOT changed to Completed"; admin notice set; Service Channel hook raised with `parts_shipped` |
| An order made to refuse every save | nothing stored, no "Shipment Dispatch" note, admin notice "NOT recorded", Service Channel not told |
| The endpoint cases of the first run, again on this handler | all as before |

Service Channel orders, through the endpoint (first run of this kind anywhere):

| Case | Result |
|---|---|
| Confirmed order with a Service Channel work order, part | `200`, stored; one "Status Update" mail to the work order's `wonote.servicechannel.net` address attempted and blocked by the guard; note "ServiceChannel Automation: Parts Shipped sent to 1 work order(s)" |
| Service Channel order with no TRX #, everything left | `200`, shipment kept, completion refused with the reason; the same single parts-shipped mail attempted; nothing for `completed` |

Also settled:

- **Production's "completed order" customer e-mail is switched off** (`woocommerce_customer_completed_order_settings`,
  read on production 2026-11-23). So a refused completion mails nobody "completed". It also means rule 5 above
  leaves the customer with no e-mail at all for a final shipment entered on the order screen: the note is held
  back for a completed e-mail that this site does not send. Old behaviour, not changed here; a decision for Cole.
- **UACP:** the five edited files were carried into `C:\Projects\UACP` (the repository releases are cut from), each
  keeping its own line endings; same change, files compile. No build was made, so staff run the previous UACP until
  a release. UACP still does not read `status_blocked`: when a shipment is kept and completion refused, it shows
  success and says nothing about the order not completing.
- The staging clone was reset from production afterwards with `~/bin/sync-staging.sh --skip-uploads`, so it no
  longer holds the test data or the corrected handler.

Still not run: UACP's dialogs; anything on production.

### 15.11 Production (2026-11-23)

The ledger module of `3d9b0ba` was released into production's `musesys-mega/modules/musesys-ledger` at 16:17 UTC
on Cole's go, with nothing allowed to send to it. Record, checks and rollback: Cairn repository,
`docs/CC10_Ledger_Production_Release_Prep_2026-11-23.md` §9. Cole confirmed the ledger screen in a signed-in
browser afterwards: the "Proposed updates" tab is there and empty.

Production does **not** have the R5 fix: its `musesys-partial-ship/index.php` is the previous handler. The copy
of the ledger under `legacy/musesys-mega/modules/musesys-ledger` in this repository was brought in line with
production the same day (27 files, identical to `3d9b0ba`); `legacy/musesys-mega`'s partial-ship handler is still
the previous one, as on production.

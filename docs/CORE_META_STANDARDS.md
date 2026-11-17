# MuseSys Mega - Core Meta Key Standards

This master document serves as the single source of truth for the **Core Meta Key Standard** across the entire `musesys-mega` plugin suite. All future modules, APIs, and data integrations must adhere strictly to these keys.

## The Prime Directive

Consistency is the center of gravity here. Do not create new meta keys for data structures that already exist in this standard. Always use the `_muse_` prefix to prevent namespace collisions and data bleeding with standard WooCommerce tables or third-party plugins.

---

### 1. Work Orders

These keys govern the storage and retrieval of Work Order identification numbers.

| Data Type | Core Standard Key | Legacy Keys (Deprecated) |
| --- | --- | --- |
| **Global Work Order** (Order-level) | `_muse_wo_global` | `_muse_global_work_order`, `_primary_work_order`, `_muse_workorder` |
| **Line Item Work Order** (Item-level) | `_muse_wo_line` | `_muse_lineitem_workorder` |
| **Work Order Notes** | `_muse_wo_note` | `_muse_lineitem_workorder_note`, `_muse_workorder_item_note` |

### 2. Install Stores

These keys govern the physical store numbers associated with an installation or order.

| Data Type | Core Standard Key | Legacy Keys (Deprecated) |
| --- | --- | --- |
| **Global Install Store** | `_muse_store_global` | `_install_stores`, `_musesys_install_store`, `_muse_global_install_store` |
| **Ship-To Store** (Direct Order) | `_muse_ship_store` | Order-level. Direct orders only. Store number as zero-padded 3-digit string (e.g. `029`). |

### 3. Tracking & Shipping

These keys standardize how shipment tracking and logistics data are persisted.

| Data Type | Core Standard Key | Legacy Keys (Deprecated) |
| --- | --- | --- |
| **Primary Tracking Number** | `_muse_track_primary` | `_tracking_number`, `_muse_latest_tracking_number` |
| **Shipping Status / Logic** | `_muse_track_status` | `_tracking_type`, `_tracking_display`, `_muse_shipping_status` |

### 4. TRX # (Gerharz-Internal)

This key is standalone and Gerharz-internal — it is **not** a Work Order #, not derived from one, and must never be read by any Work Order / tracking display logic (see `mu-plugins/muse-tracking-core.php`).

| Data Type | Core Standard Key | Legacy Keys (Deprecated) |
| --- | --- | --- |
| **TRX #** | `_trx_order_number` | None |

Enforced non-empty before order completion by `order-fields/muse-order-fields.php` (throws an exception on "completed" transition if missing). `99999` is the reserved TRX # value for Wegmans-internal-transfer orders (see `wegmans-transfer-item-tagging-plan.md`).

**Naming trap to avoid:** Wegmans' own term "Service Channel tracking #" refers to a Service-Channel-flavored **Work Order #**, not a Tracking # and not TRX #. Do not let the word "tracking" in that phrase pull Service Channel data into either the Tracking # fields (§3) or TRX # (this section). Four concepts, never blended: Work Order #, TRX #, Tracking #, Service Channel # (a Work Order variant).

### 5. Product Requests

These keys track the lifecycle and identification of B2B Product Requests inside the WordPress Custom Post Type architecture.

| Data Type | Core Standard Key | Legacy Keys (Deprecated) |
| --- | --- | --- |
| **Request Status** | `_muse_pr_status` | `_musesys_pr_status` |
| **Request Type** | `_muse_pr_type` | `_musesys_pr_type` |
| **Request ID** (Custom unified display) | `_muse_pr_id` | `_musesys_pr_id` |

### 6. Wegmans Transfer Items

Wegmans pre-buys certain parts, stocks them at their own warehouse, and issues them to techs directly rather than through the portal. These keys govern how those parts are tagged and how the resulting orders are recorded. Owned by `modules/musesys-wegmans-transfer-items/index.php`.

| Data Type | Core Standard Key | Notes |
| --- | --- | --- |
| **Transfer item tag** (Product-level) | `_muse_wegmans_transfer_item` | `'yes'` when tagged, empty/absent otherwise. The only runtime source of truth for which parts are transfer items — never hardcode a part list in PHP. |
| **Split source** (Order-level) | `_muse_split_from_order_id` | On a secondary transfer order: the original order it was split out of. |
| **Split target** (Order-level) | `_muse_split_to_order_id` | On the original order: the secondary transfer order the tagged lines were moved to. |
| **Handling marker** (Order-level) | `_muse_wti_handled` | `flagged` (whole order was transfer-only), `split` (original order, tagged lines removed), `split-child` (the secondary transfer order). Also the idempotence guard — only written once the module has acted. |

Transfer orders are flagged with the reserved TRX # `99999` (§4) and auto-completed. Tagging is readable/writable through `/musesys/api/v1/meta/post/{id}` and the `musesys_update_product` MCP tool, both of which read their key inventory from `MuseSys_API::get_fields()`.

### 7. Ledger Tables (no meta keys)

The MuseSys Ledger (`modules/musesys-ledger/`, spec: `LEDGER_SPEC.md`) adds **no** order, item or product meta. It reads the keys in §3–§4 plus `_purchase_order_number`, `_is_drop_ship`, `_muse_logistics_status`, `_muse_logistics_date` and `_muse_shipments`, and stores derived data in two custom tables:

| Table | Role | Mutability |
| --- | --- | --- |
| `{prefix}musesys_ledger` | Current-state projection, one row per order line (`order_item_id`). Rebuildable from source at any time. | Written only by the projector/resolver. Never a source of truth. |
| `{prefix}musesys_ledger_events` | Activity log: human contact (email/call/note) and generated events (ETA/TRX/shipment/state changes). | Append-only. No update or delete path. |

Do not read ledger columns as a substitute for the meta keys above. ETA changes made from the ledger go through `Muse_Logistics_Manager::apply_logistics()`, the same mutation as the metabox.

---

## UI Label Standards

Whenever generating administrative UI, columns, email templates, or dashboards, use the standardized language below.

- **Work Order # display**: Use `"Work Order #"` exclusively. Do not label Work Order data as "TRX Order #" or "TRX #" — that was the source of the original conflation this document is now correcting (2026-07-24).
- **TRX # display**: Use `"TRX #"` exclusively for the standalone Gerharz-internal field (§4). Do not label it "Work Order #" or blend it into Work Order UI — it is a genuinely separate concept, not deprecated terminology.
- **Tracking # display**: Use `"Tracking #"` for actual shipment tracking (§3). Never call Work Order #, Service Channel #, or TRX # a "tracking number," even though Wegmans' own phrase "Service Channel tracking #" uses that word.

## Implementation Rules

1. **Direct String Usage**: Use the exact string mapping defined above. We do not rely on a dynamic constants loader; the strings are baked into the modules.
2. **Backward Compatibility / Migrations**: Legacy modules mapped data to older keys. Any migration scripts must strictly map older data structures into this exact layout.

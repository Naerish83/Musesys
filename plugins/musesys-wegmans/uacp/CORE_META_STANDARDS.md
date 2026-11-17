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

### 3. Tracking & Shipping

These keys standardize how shipment tracking and logistics data are persisted.

| Data Type | Core Standard Key | Legacy Keys (Deprecated) |
| --- | --- | --- |
| **Primary Tracking Number** | `_muse_track_primary` | `_tracking_number`, `_muse_latest_tracking_number` |
| **Shipping Status / Logic** | `_muse_track_status` | `_tracking_type`, `_tracking_display`, `_muse_shipping_status` |

### 4. Product Requests

These keys track the lifecycle and identification of B2B Product Requests inside the WordPress Custom Post Type architecture.

| Data Type | Core Standard Key | Legacy Keys (Deprecated) |
| --- | --- | --- |
| **Request Status** | `_muse_pr_status` | `_musesys_pr_status` |
| **Request Type** | `_muse_pr_type` | `_musesys_pr_type` |
| **Request ID** (Custom unified display) | `_muse_pr_id` | `_musesys_pr_id` |

---

## UI Label Standards

Whenever generating administrative UI, columns, email templates, or dashboards, use the standardized language below.

- **Display Text**: Use `"Work Order #"` exclusively.

## Implementation Rules

1. **Direct String Usage**: Use the exact string mapping defined above. We do not rely on a dynamic constants loader; the strings are baked into the modules.
2. **Backward Compatibility / Migrations**: Legacy modules mapped data to older keys. Any migration scripts must strictly map older data structures into this exact layout.

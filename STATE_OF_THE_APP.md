# State of the App & Model Context Protocol (MCP) Technical Specification

* **Document Version**: 2.0.42
* **Date**: June 12, 2026
* **App Version**: 2.0.42
* **Target Audience**: Integrators, Developers, AI Agents

---

## Part 1: The Audit (Current State)

This section outlines the current state of the Unified Admin Control Panel (UACP) / Teammate App, mapping out the functional components, data parsing pipelines, and performance profile.

### 1. Core Functionality
* **Status**: Highly robust, extending across 6 key operational tabs in the Tkinter desktop GUI:
  1. **Active Orders**: View and filter active orders. Drill down into individual line items.
  2. **Product Requests**: Manage requests sent by field technicians. GUI support is fully implemented (`ProductRequestView`), allowing operators to view notes, inspect media, map products, and complete requests.
  3. **Inventory Lookup**: Search products, pricing levels, and stock details dynamically.
  4. **Catalog Maintenance**: Create and update WooCommerce products, including manufacturer part numbers, Prop 65 warning flags, and manuals.
  5. **Asset Maintenance**: Search, view, and edit commissioned assets (manufacturer, model, serial number, commission date, department) for physical stores.
  6. **Order Guide**: Structured spreadsheet-like interface for managing rapid part lists and order routing guides.
* **Server Heartbeat**:
  - The app runs a background daemon thread (`run_watch` in `uacp_main.py`) polling the custom `/musesys/v1/heartbeat` API endpoint every 5 seconds.
  - Updates a status light indicator in the bottom-right corner (Green: Connected, Orange: Fetching/Updating, Red: Offline/Connection Error).
  - Automatically triggers non-blocking refreshes if either the `last_order` or `last_request` timestamp hash on the server changes.

### 2. Data Structure, Metadata Standards & Parsing
* **Core Meta Key Standards**:
  The application utilizes direct WordPress/WooCommerce custom metadata keys, governed by the standard:
  
  | Feature Area | Key / Field | API Meta Key | Legacy Keys (Deprecated / Fallback) | Validation |
  | --- | --- | --- | --- | --- |
  | **Global Order** | Work Order / TRX # | `_muse_wo_global` | `_trx_order_number`, `_muse_workorder` | 7-digit numeric string |
  | **Global Order** | Purchase Order # | `_purchase_order_number` | `_purchase_order` | String |
  | **Global Order** | Drop Ship Flag | `_is_dropship` | `_is_drop_ship` | Boolean (`yes` / `no`) |
  | **Global Order** | Logistics Status | `_muse_logistics_status` | - | `in_stock` \| `backordered` \| `replaced` \| `discontinued` |
  | **Global Order** | Logistics ETA | `_muse_logistics_date` | `_muse_logistics_eta` | String (`YYYY-MM-DD`) |
  | **Global Order** | Replacement Part | `_muse_logistics_replacement_part` | - | SKU / String |
  | **Global Order** | Manual Job Total | `_muse_order_total_manual` | - | Float |
  | **Line Item** | Item-level Work Order | `_muse_wo_line` | `WO Number`, `_wo_number` | 7-digit numeric string |
  | **Line Item** | Install Store # | `_muse_store_global` | `Install Store #`, `_install_store_id` | 3-digit numeric string |
  | **Line Item** | Asset Tag | `_muse_asset_tag` | `Asset Tag` | Alphanumeric String |
  | **Product Request**| Request Status | `_muse_pr_status` | `_musesys_pr_status` | `pending` \| `approved` \| `rejected` \| `completed` |
  | **Product Request**| Request Type | `_muse_pr_type` | `_musesys_pr_type` | String |
  | **Product Request**| Request ID | `_muse_pr_id` | `_musesys_pr_id` | Alphanumeric String |
  | **Asset Registry** | Store Assets | - | Custom `musesys/v1/assets` schema | Serial #, manufacturer, model |

* **Date Validation & Normalization**:
  The date format of `_muse_logistics_date` is strictly standardized to `Y-m-d` (`YYYY-MM-DD`). UACP sanitizes slash `/` and dot `.` separators and parses dates using Python's `datetime.strptime(..., "%Y-%m-%d")` to enforce zero-padded compliance before sending updates.

* **Fulfillment State Machine**:
  - Automatically advances statuses during shipping operations:
    `Processing` -> `Partially Shipped` (If items shipped < quantity ordered).
    `Processing` / `Partially Shipped` -> `Completed` (If items shipped == quantity ordered).
  - **Status Sync Guard**: To prevent premature "Order Completed" emails to customers, UACP delays updating the order status to `completed` in the general payload, passing the status change instead to the custom `/ship-lines` endpoint to bundle completion with tracking attachment.

### 3. Current Performance & Technical Gaps
* **Synchronous API Bottlenecks**:
  - The heartbeat checking is asynchronous, but the actual fetch operations triggered by it run synchronously in some GUI hooks, briefly causing UI lag when fetching massive datasets (e.g. 100+ active orders).
  - Caching is managed via local `.json` file writes in the `data/` directory, acting as a fallback but lacking true background sync retry queues for offline mode.

---

## Part 2: Model Context Protocol (MCP) Technical Specification

To enable LLMs, AI agents, and external software to interact natively with UACP and the MuseSys WordPress backend, we specify a dedicated **MuseSys Model Context Protocol (MCP)** server.

> **Implementation Status (2026-06-12)**: Implemented at `modules/musesys_mcp/` (v1.0.0). See `IMPLEMENTATION_LOG.md` and module `README.md`. Note: Python 3.10+ required (official `mcp` SDK floor), superseding the 3.8 target below.

### 1. Architecture & Protocol Layer
The MuseSys MCP server will implement the standard Model Context Protocol.
* **Transport**: Standard input/output (stdio) JSON-RPC 2.0.
* **Language**: Python 3.8+ (using the standard `mcp` library).
* **Configuration**: Leverages UACP's `settings.json` schema. The MCP server reads credentials either from the local `settings.json` file or via environment variables:
  - `MUSESYS_API_URL`: Base WordPress site URL.
  - `MUSESYS_CONSUMER_KEY`: WooCommerce REST API Consumer Key.
  - `MUSESYS_CONSUMER_SECRET`: WooCommerce REST API Consumer Secret.
  - `MUSESYS_WP_USERNAME`: WordPress core username.
  - `MUSESYS_WP_APP_PASSWORD`: WordPress Application Password (required for media uploads and custom routes).

### 2. Exposed Resources
Resources allow clients to read structured context from the WooCommerce and MuseSys server.

#### 1. Active Orders List
* **URI**: `musesys://orders/active`
* **MIME Type**: `application/json`
* **Description**: Returns list of all processing, pending, or partially-shipped orders.

#### 2. Order Detail View
* **URI**: `musesys://orders/detail/{order_id}`
* **MIME Type**: `application/json`
* **Description**: Retrieves full WooCommerce detail block for a single order, with expanded custom metadata.

#### 3. Pending Product Requests
* **URI**: `musesys://requests/pending`
* **MIME Type**: `application/json`
* **Description**: Returns all unfulfilled product requests submitted by field technicians.

#### 4. Store Assets
* **URI**: `musesys://assets/store/{store_id}`
* **MIME Type**: `application/json`
* **Description**: Lists all assets currently registered at the specified 3-digit store.

---

### 3. Tool Specifications
Tools allow the AI Agent or client to execute modifications or invoke complex logic workflows.

#### `musesys_get_orders`
* **Description**: Retrieve order lists with optional filters.
* **Arguments**:
  - `limit` (optional integer, default `10`): Maximum number of orders to return.
  - `status` (optional string): Filter by WooCommerce status (e.g., `processing`, `completed`).
  - `wo_number` (optional string): Filter by 7-digit Work Order number.
  - `store_number` (optional string): Filter by 3-digit Install Store.

#### `musesys_get_order`
* **Description**: Fetch full detail of a specific order by its ID.
* **Arguments**:
  - `order_id` (integer): WooCommerce order ID.

#### `musesys_update_order_fields`
* **Description**: Update order-level billing/shipping metadata.
* **Arguments**:
  - `order_id` (integer): WooCommerce order ID.
  - `trx_number` (optional string): Global Work Order #. Must be a 7-digit string (`^[0-9]{7}$`).
  - `po_number` (optional string): Purchase Order #.
  - `is_dropship` (optional boolean): Flag order as a drop shipment.
  - `manual_total` (optional number): Set a manual job total.

#### `musesys_update_order_logistics`
* **Description**: Update shipping logistics notes and estimated time of arrival (ETA).
* **Arguments**:
  - `order_id` (integer): WooCommerce order ID.
  - `logistics_status` (string): Must be one of `in_stock`, `backordered`, `replaced`, `discontinued`.
  - `logistics_eta` (optional string): Estimated delivery date. Must follow zero-padded format `YYYY-MM-DD`.
  - `replacement_part` (optional string): SKU of the replacement item (required if status is `replaced`).

#### `musesys_update_order_line_item`
* **Description**: Update item-level Work Order, Install Store, or Asset Tag for a specific item inside an order.
* **Arguments**:
  - `order_id` (integer): WooCommerce order ID.
  - `line_item_id` (integer): Specific line item ID from the WooCommerce order.
  - `wo_number` (optional string): Work Order #. Must be a 7-digit string (`^[0-9]{7}$`).
  - `install_store_id` (optional string): Install Store #. Must be a 3-digit string (`^[0-9]{3}$`).
  - `asset_tag` (optional string): Asset Tag string.

#### `musesys_ship_lines`
* **Description**: Post a partial or complete shipment, registering tracking credentials and advancing fulfillment status.
* **Arguments**:
  - `order_id` (integer): WooCommerce order ID.
  - `carrier` (string): Logistics carrier code (e.g. `UPS`, `FEDEX`, `USPS`, `TECH_DELIVERY`, `CUSTOMER_PICKUP`, `LTL`, `OTHER`).
  - `tracking_number` (optional string): Shipment tracking number.
  - `lines` (array of objects): Line items and quantities in this shipment.
    - Each object: `{"line_item_id": integer, "ship_qty": integer}`
  - `closeout` (boolean): Flag to close the order and set WooCommerce status to `completed` if all items are fully shipped.

#### `musesys_get_product_requests`
* **Description**: Fetch all pending product requests. Includes bypass cache token.
* **Arguments**: None.

#### `musesys_complete_product_request`
* **Description**: Maps WooCommerce product ID/links to a field technician request and marks it completed.
* **Arguments**:
  - `request_id` (integer): ID of the product request post.
  - `products` (array of objects): Mapped catalog items.
    - Each object: `{"item_id": string, "link": string}`
  - `action` (optional string, default `send_confirm`): Completion action (e.g., `send_confirm` to trigger emails).

#### `musesys_search_catalog`
* **Description**: Query the product catalog by SKU, description, or title.
* **Arguments**:
  - `query` (string): Term to search for.

#### `musesys_create_product`
* **Description**: Insert a new product into the WooCommerce catalog.
* **Arguments**:
  - `name` (string): Product title.
  - `sku` (string): Product SKU.
  - `regular_price` (string): Sales price.
  - `meta_data` (optional array of objects): Custom fields (e.g. `_muse_part_number`, `_muse_manual_url`).

#### `musesys_update_product`
* **Description**: Modify an existing product in the catalog.
* **Arguments**:
  - `product_id` (integer): Product ID.
  - `data` (object): Map of parameters to update.

#### `musesys_get_assets`
* **Description**: Retrieve all registered assets for a specific store.
* **Arguments**:
  - `store_id` (string): 3-digit store identifier (`^[0-9]{3}$`).

#### `musesys_update_asset`
* **Description**: Update technical and operational details for a specific store asset.
* **Arguments**:
  - `asset_id` (integer): ID of the asset.
  - `payload` (object): Asset properties to modify.
    - Acceptable fields: `description`, `manufacturer`, `model`, `serial_number`, `commission_date` (`YYYY-MM-DD`), `department`.

#### `musesys_export_orders`
* **Description**: Access flattened CSV order/line item lists for custom dates.
* **Arguments**:
  - `start_date` (optional string): Start date `YYYY-MM-DD`.
  - `end_date` (optional string): End date `YYYY-MM-DD`.
  - `export_all` (optional boolean): Override date bounds.

#### `musesys_export_aq`
* **Description**: Export AutoQuotes JSON payload for a single order.
* **Arguments**:
  - `order_id` (integer): Order ID.

---

### 4. Core Constraints & Validation Rules (The Safe Gates)

The MCP server must enforce UACP validation rules prior to calling WooCommerce endpoint APIs to prevent garbage data ingress:

1. **Work Order (WO) Numbers**:
   - Must match `^[0-9]{7}$` (exactly 7 digits).
   - Reject any string containing letters, spaces, special characters, or non-7 length.
2. **Store Numbers**:
   - Must match `^[0-9]{3}$` (exactly 3 digits, zero-padded if necessary, e.g. `024`).
   - If a client passes an integer, it must be zero-padded to 3 characters.
3. **Date Formats**:
   - Any logistics ETA or commission date must match the format `YYYY-MM-DD`.
   - The server must attempt to parse strings, rejecting invalid dates (e.g., February 31) and formatting valid ones to strict zero-padded strings.
4. **Order Status Normalization**:
   - Standardize statuses to: `pending`, `processing`, `on-hold`, `completed`, `cancelled`, `refunded`, `failed`.
5. **Rate Limiting & Transient Errors**:
   - The server must handle API rate limits (HTTP 429) gracefully, employing exponential backoff when fetching lists or posting status updates.

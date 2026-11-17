# UACP Framework Architecture

## 1. System Topology
- **Application Type**: Desktop GUI Client (Local)
- **Primary Runtime**: Python 3.8+
- **GUI Framework**: Tkinter (ttk)
- **Data Exchange**: REST API (WordPress/WooCommerce API)
- **Data Format**: JSON
- **Local Persistence**: JSON files (`data/` directory)

## 2. Core Modules
### 2.1 `uacp_main.py`
- Main entry point.
- Initializes Tkinter application (`TeammateApp`).
- Sets up tabbed UI (`ttk.Notebook`) containing Active Orders, Product Requests, and Inventory Lookup.
- Manages background thread for server heartbeat and data fetching to prevent UI blocking.
- Central status light indicator logic.

### 2.2 `app.core.config.ConfigManager`
- Handles application settings loading and validation.
- Validates active site, API URL, and Consumer Key constraints.

### 2.3 `app.gui.views`
- **`order_ops.py` (`OrderOpsView`)**: Handles rendering and modifying Active Orders. Incorporates the "Red/Green/Blue" panel structure for order details, line items, and listing.
- **`product_requests.py` (`ProductRequestView`)**: UI for ingesting, viewing, and completing product requests.
- **`product_lookup.py` (`ProductLookupView`)**: UI for searching and interacting with existing inventory and pricing logic.

### 2.4 Data Models (`models.py` / Data Structures)
- Utilizes Pydantic for strong typing and validation.
- Parses WooCommerce payload into local objects (e.g., `Order.from_wc_data`).
- Manages variable `Line Item Meta` structure, including mapping fields like `WO Number`, `Install Store #`, and `Asset Tag`.

### 2.5 API Bridge (`api_client.py` / Network Layer)
- Synchronous `requests` based client.
- Exposes `get_orders`, `get_product_requests`, `complete_product_request`.
- Handles connection to server-side `musesys-api` plugin.

## 3. Asynchronous Operations
- Application maintains a heartbeat polling mechanism (`run_watch`) every 5 seconds.
- Differentiates between 'last_order' and 'last_request' state hashes.
- Triggers UI-safe callbacks via `self.after` when new data states are detected.
- Employs dedicated threads for network fetching to ensure a responsive GUI.

## 4. Workflows and Data Constraints
### 4.1 Order Status Machine
- Forward-only state progression.
- `Order Placed` -> `Processing` (Triggered by TRX#, PO#, or Drop Ship flags).
- `Processing` -> `Partially Shipped` (Triggered by tracking number, sum(To Ship) < sum(Qty)).
- `Processing`/`Partially Shipped` -> `Completed` (Triggered by tracking number, sum(To Ship) == sum(Qty)).

### 4.2 Data Validation
- `WO #`: Must be 7 digits.
- `Store #`: Must be 3 digits.

## 5. Extensibility Nodes
- CSV Export function hooks into the active view's bridge.
- Future hooks mapped for hardware integration (ZPL label printers, Keyboard-wedge scanners).
- Local cache queues (`pending_sync.json`) planned for offline mode.

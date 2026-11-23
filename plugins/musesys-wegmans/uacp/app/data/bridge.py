import json
import os
from typing import Dict, Any, Optional, Type, TypeVar
from pydantic import BaseModel
from app.models import UserMeta, Order, ProductMeta, RequestMeta

T = TypeVar('T', bound=BaseModel)

class MuseDataBridge:
    def __init__(self, data_dir: str = "data"):
        self.data_dir = data_dir
        self._ensure_data_dir()
        self.client = None
        self._init_client()

    def _init_client(self):
        from app.core.config import ConfigManager
        from app.api_client import MuseSysAPIClient
        
        settings = ConfigManager.load_settings()
        active = settings.get("active_site")
        site_data = settings.get("sites", {}).get(active, {})
        
        url = site_data.get("api_url")
        ck = site_data.get("consumer_key")
        cs = site_data.get("consumer_secret")
        # Optional WP Auth for Media Uploads
        # Try lowercase keys first, then uppercase (as per user report)
        wp_user = site_data.get("wp_username") or site_data.get("WP_USERNAME")
        wp_pass = site_data.get("wp_app_password") or site_data.get("WP_APP_PASSWORD")
        
        if url and ck and cs:
            try:
                self.client = MuseSysAPIClient(url, ck, cs, wp_user, wp_pass)
            except Exception as e:
                print(f"Failed to init client: {e}")

    def check_heartbeat(self) -> Optional[Dict[str, str]]:
        """
        Checks for server-side updates.
        Returns dict with timestamps or None.
        """
        if self.client:
            return self.client.get_heartbeat()
        return None

    def _ensure_data_dir(self):
        if not os.path.exists(self.data_dir):
            os.makedirs(self.data_dir)

    def _get_file_path(self, model_type: str, id: str) -> str:
        return os.path.join(self.data_dir, f"{model_type}_{id}.json")

    def load(self, model_class: Type[T], id: str) -> Optional[T]:
        model_type = model_class.__name__.lower()
        file_path = self._get_file_path(model_type, id)
        
        if not os.path.exists(file_path):
            return None
            
        try:
            with open(file_path, 'r') as f:
                data = json.load(f)
            return model_class(**data)
        except Exception as e:
            print(f"Error loading {model_type} {id}: {e}")
            return None

    def save(self, model: T, id: str) -> bool:
        model_type = model.__class__.__name__.lower()
        file_path = self._get_file_path(model_type, id)
        
        try:
            with open(file_path, 'w') as f:
                f.write(model.model_dump_json(by_alias=True, indent=2))
            return True
        except Exception as e:
            self._log_error(f"Error saving {model_type} {id} locally: {e}")
            return False

    # Specific helpers
    def get_order(self, order_id: str) -> Optional[Order]:
        # Try to fetch fresh from API first
        if self.client:
            try:
                # We need a get_order method in client or use get_orders with id?
                # Client has get_orders(limit) but not get_order(id).
                # Let's add a quick fetch or just use the list if we have it.
                # Actually, client._get("orders/id") works.
                data = self.client._get(f"orders/{order_id}")
                return Order.from_wc_data(data)
            except Exception as e:
                self._log_error(f"Error fetching order {order_id}: {e}")
        
        # Fallback to local
        return self.load(Order, order_id)

    def export_orders_to_csv_data(self, start_date: str = "", end_date: str = "", export_all: bool = False, chunk_callback: Optional[Any] = None) -> list:
        """
        Retrieves line-item export data from /musesys/v1/export-orders.
        Automatically slices date spans greater than 60 days into rolling 60-day
        chunks (newest first) to protect against server PHP memory exhaustion (HTTP 500).
        Optionally invokes chunk_callback(accumulated_rows) as each chunk arrives.
        """
        if not self.client:
            return []

        from datetime import datetime, timedelta
        now = datetime.now()

        if export_all or not start_date:
            start_dt = now - timedelta(days=180)
            end_dt = now
        else:
            try:
                start_dt = datetime.strptime(start_date.split()[0], "%Y-%m-%d")
            except Exception:
                start_dt = now - timedelta(days=180)
            
            if end_date:
                try:
                    end_dt = datetime.strptime(end_date.split()[0], "%Y-%m-%d")
                except Exception:
                    end_dt = now
            else:
                end_dt = now

        if start_dt > end_dt:
            start_dt, end_dt = end_dt, start_dt

        total_days = (end_dt - start_dt).days
        if total_days <= 60:
            try:
                data = self.client.export_orders(start_dt.strftime("%Y-%m-%d"), end_dt.strftime("%Y-%m-%d"), False)
                if chunk_callback:
                    chunk_callback(data)
                return data
            except Exception as e:
                print(f"Failed to export orders: {e}")
                return []

        # Sliced chunks of at most 60 days from newest to oldest
        CHUNK_SIZE = 60
        combined = []
        seen = set()
        curr_end = end_dt

        while curr_end >= start_dt:
            curr_start = max(start_dt, curr_end - timedelta(days=CHUNK_SIZE))
            s_str = curr_start.strftime("%Y-%m-%d")
            e_str = curr_end.strftime("%Y-%m-%d")
            
            try:
                chunk = self.client.export_orders(s_str, e_str, False)
                for row in chunk:
                    key = (row.get("Order Number"), row.get("Line Item ID"))
                    if key not in seen:
                        seen.add(key)
                        combined.append(row)
                if chunk_callback:
                    chunk_callback(list(combined))
            except Exception as e:
                print(f"Failed to export orders chunk {s_str} to {e_str}: {e}")

            curr_end = curr_start - timedelta(days=1)

        return combined

    def get_products_by_category(self, category_id: int) -> list:
        if not self.client:
            return []
        try:
            return self.client._get("products", params={"category": category_id, "per_page": 100})
        except Exception as e:
            self._log_error(f"Error fetching products by category: {e}")
            return []

    def get_assets(self, store_id: str) -> list:
        if not self.client:
            return []
        try:
            return self.client.get_assets(store_id)
        except Exception as e:
            self._log_error(f"Error fetching assets: {e}")
            return []

    def update_asset(self, asset_id: int, payload: Dict) -> bool:
        if not self.client:
            return False
        try:
            return self.client.update_asset(asset_id, payload)
        except Exception as e:
            self._log_error(f"Error updating asset: {e}")
            return False

    def export_aq_json_data(self, order_id: int) -> dict:
        if not self.client:
            return {}
        try:
            return self.client.export_aq(order_id)
        except Exception as e:
            print(f"Failed to export AQ JSON for order {order_id}: {e}")
            return {}

    def save_order(self, order_id: str, order: Order) -> bool:
        # 1. Save to local disk (backup/offline)
        local_success = self.save(order, order_id)
        
        if not self.client:
             return local_success

        # 2. Standard Update (Meta & Status)
        # Extract meta to update
        meta_data = [
            {"key": "_muse_wo_global", "value": order.trx_order_number},
            {"key": "_trx_order_number", "value": order.trx_order_number},
            {"key": "_purchase_order_number", "value": order.purchase_order_number},
            {"key": "_is_drop_ship", "value": "yes" if order.is_dropship else "no"},
            {"key": "_is_dropship", "value": "yes" if order.is_dropship else "no"},
            # Flat/latest tracking fields for visibility alongside structured shipments
            {"key": "_muse_track_primary", "value": order.tracking_number},
            {"key": "_muse_carrier", "value": order.carrier_name},
        ]
        
        # Add Manual Job Total if present
        if order.manual_total is not None:
             meta_data.append({"key": "_muse_order_total_manual", "value": float(order.manual_total)})

        payload = {
            "status": order.status,
            "meta_data": meta_data
        }
        
        # Line Items Meta
        if order.line_items:
            line_items_payload = []
            for item in order.line_items:
                # We only update meta for existing items
                item_data = {
                    "id": item.id,
                    "meta_data": [
                        {"key": "_muse_wo_line", "value": item.wo_number},
                        {"key": "_muse_store_global", "value": item.install_store_id},
                        {"key": "Asset Tag", "value": item.asset_tag},
                        {"key": "_muse_asset_tag", "value": item.asset_tag}
                    ],
                    "total": str(item.price) if item.price else "0.00"
                }
                line_items_payload.append(item_data)
            payload["line_items"] = line_items_payload
        
        # Construct lines to mark as shipped (Ship All Strategy)
        ship_lines_data = []
        if order.line_items:
            for item in order.line_items:
                remaining_qty = max(0, int(item.quantity) - int(getattr(item, "shipped_qty", 0) or 0))
                if remaining_qty <= 0:
                    continue
                ship_lines_data.append({
                    "line_item_id": item.id,
                    "ship_qty": remaining_qty
                })

        close_it_out = False
        if ship_lines_data:
            close_it_out = (
                order.status == 'completed'
                and sum(line["ship_qty"] for line in ship_lines_data) == sum(
                    max(0, int(item.quantity) - int(getattr(item, "shipped_qty", 0) or 0))
                    for item in order.line_items
                )
            )
            
            # CRITICAL RACE CONDITION FIX:
            # Delay status completion until ship_lines so that tracking info 
            # is included in the WooCommerce completed email.
            if close_it_out and "status" in payload:
                payload.pop("status")
        
        print(f"OUTGOING UPDATE PAYLOAD: {json.dumps(payload, indent=2)}")
        api_success = self.client.update_order(order_id, payload)
        
        # 3. Shipment/Tracking Update (New API)
        if order.carrier_name:
             tracking_list = []
             # Only send tracking numbers if NOT internal service
             if order.tracking_number and order.carrier_name not in ['TECH_DELIVERY', 'CUSTOMER_PICKUP']:
                 tracking_list = [order.tracking_number]

             ship_payload = {
                 "carrier": order.carrier_name,
                 "tracking_numbers": tracking_list,
                 "lines": ship_lines_data,
                 "notify": True,
                 "closeout": close_it_out
             }
             
             ship_res = self.client.ship_lines(order_id, ship_payload)
             api_success = api_success and ship_res
            
        return local_success and api_success

    def _log_error(self, message):
        import traceback
        import datetime
        
        timestamp = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        log_entry = f"[{timestamp}] {message}\n{traceback.format_exc()}\n{'-'*40}\n"
        
        try:
            with open("error_log.txt", "a") as f:
                f.write(log_entry)
        except Exception:
            print(f"Failed to write to log: {message}")

    def get_all_orders(self) -> Dict[str, Order]:
        """
        Fetches orders from WooCommerce.
        Returns a dict mapping order_id -> Order
        """
        orders = {}
        
        # 1. Fetch remote orders if client is available
        if self.client:
            try:
                remote_data = self.client.get_orders(limit=100)
                for data in remote_data:
                    order = Order.from_wc_data(data)
                    orders[str(order.id)] = order
            except Exception as e:
                print(f"Error fetching remote orders: {e}")
                
        return orders

    def get_user_meta(self, user_id: str) -> Optional[UserMeta]:
        return self.load(UserMeta, user_id)

    def save_user_meta(self, user_id: str, meta: UserMeta) -> bool:
        return self.save(meta, user_id)

    def get_product_requests(self) -> list:
        if self.client:
            return self.client.get_product_requests()
        return []

    def search_products(self, query: str) -> list:
        if self.client:
            return self.client.search_products(query)
        return []

    def update_product(self, product_id: int, data: dict) -> Optional[dict]:
        if self.client:
            return self.client.update_product(product_id, data)
        return None

    def create_product(self, data: dict) -> dict:
        """
        Wrapper to create a product. Returns full product data dict on success (including id), or None.
        """
        if self.client:
            return self.client.create_product(data)
        return None

    def get_category_id(self, name: str) -> Optional[int]:
        """
        Finds a category ID by name (case-insensitive).
        """
        if not self.client:
            return None
            
        categories = self.client.get_categories()
        for cat in categories:
            if cat.get('name', '').lower() == name.lower():
                return cat.get('id')
        return None

    def complete_product_request(self, request_id: int, products: list, action: str = 'send_confirm') -> bool:
        """
        Wrapper to complete a product request.
        """
        if self.client:
            return self.client.complete_product_request(request_id, products, action=action)
        return False

    def ship_lines(self, order_id: int, payload: dict) -> bool:
        """
        Sends partial shipment payload to the API.
        """
        if self.client:
            return self.client.ship_lines(order_id, payload)
        return False

    def ship_problem(self) -> str:
        """
        Why the last ship_lines call returned False, in the site's words where it gave any.
        """
        if not self.client:
            return "Not connected to the site."
        return getattr(self.client, "last_ship_error", "") or "The site did not accept it."

    def ship_notice(self) -> str:
        """
        '' unless the last ship_lines call returned True (the shipment WAS recorded) while the site
        refused the status change that came with it. Then: the words to show the user, as a warning.
        Read it right after the ship_lines call it belongs to; the next call clears it.
        """
        if not self.client:
            return ""
        reason = getattr(self.client, "last_ship_notice", "")
        if not reason:
            return ""
        wanted = getattr(self.client, "last_ship_wanted", "") or "completed"
        actual = getattr(self.client, "last_ship_status", "")
        still = f"; it is still {actual}" if actual else ""
        return (
            "The shipment WAS recorded on the order.\n"
            f"The order was NOT changed to {wanted}{still}.\n"
            f"Reason: {reason}"
        )

    def ship_blocked_status(self) -> str:
        """
        The status the order really has after a ship_lines call whose status change was refused,
        '' when nothing was refused or the site did not say.
        """
        if not self.client or not getattr(self.client, "last_ship_notice", ""):
            return ""
        return getattr(self.client, "last_ship_status", "")

    def upload_image_async(self, path: str, sku: str) -> Optional[int]:
        """
        Uploads an image, renaming it to {sku}.{ext}.
        """
        if not self.client:
            return None
            
        _, ext = os.path.splitext(path)
        new_name = f"{sku}{ext}"
        
        return self.client.upload_media(path, new_name)

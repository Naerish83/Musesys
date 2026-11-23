import base64
import json
import requests
from typing import Dict, Any, Optional, List
from .models import UserMeta, Order, ProductMeta, RequestMeta

class MuseSysAPIClient:
    def __init__(self, base_url: str, consumer_key: str, consumer_secret: str, wp_username: Optional[str] = None, wp_app_password: Optional[str] = None):
        # Clean base_url: remove trailing slash and specific API paths if present
        self.base_url = base_url.rstrip('/')
        for suffix in ['/wp-json/wc/v3', '/wp-json/wc/v2', '/wp-json']:
            if self.base_url.endswith(suffix):
                self.base_url = self.base_url[:-len(suffix)]
        
        self.consumer_key = consumer_key
        self.consumer_secret = consumer_secret
        self.wp_username = wp_username
        self.wp_app_password = wp_app_password
        self.last_ship_error = ""

        self.session = requests.Session()
        self._setup_auth()

    def _setup_auth(self):
        # Use Basic Auth with Consumer Key and Secret for WooCommerce
        creds = f"{self.consumer_key}:{self.consumer_secret}"
        token = base64.b64encode(creds.encode()).decode()
        self.session.headers.update({
            "Authorization": f"Basic {token}",
            "Content-Type": "application/json"
        })

    def _parse_json_response(self, response: requests.Response) -> Any:
        """
        Parse JSON responses while tolerating a UTF-8 BOM occasionally emitted by WordPress/PHP.
        """
        return json.loads(response.content.decode("utf-8-sig"))

    def _get(self, endpoint: str, params: Optional[Dict] = None) -> Any:
        # Use standard WooCommerce V3 API
        url = f"{self.base_url}/wp-json/wc/v3/{endpoint}"
        response = self.session.get(url, params=params)
        response.raise_for_status()
        return self._parse_json_response(response)

    def _post(self, endpoint: str, data: Dict[str, Any]) -> Any:
        url = f"{self.base_url}/wp-json/wc/v3/{endpoint}"
        response = self.session.post(url, json=data)
        response.raise_for_status()
        return self._parse_json_response(response)

    def test_connection(self) -> bool:
        """
        Tests connection to WooCommerce by fetching system status.
        Returns True if successful, False otherwise.
        """
        try:
            # 'system_status' is a good endpoint to check connectivity and auth
            self._get("system_status")
            return True
        except Exception as e:
            print(f"Connection failed: {e}")
            return False

    def get_orders(self, limit: int = 10, **kwargs) -> List[Dict]:
        import time
        params = {"per_page": limit, "_": int(time.time())}
        params.update(kwargs)
        return self._get("orders", params=params)

    def export_orders(self, start_date: str = "", end_date: str = "", export_all: bool = False) -> List[Dict]:
        """
        Calls the custom musesys/v1/export-orders endpoint to retrieve a flat CSV structure
        of all line items and their custom metas.
        """
        params = {}
        if start_date: params["start_date"] = start_date
        if end_date: params["end_date"] = end_date
        if export_all: params["export_all"] = "true"
        
        import time
        params["_"] = int(time.time())
        
        url = f"{self.base_url}/wp-json/musesys/v1/export-orders"
        
        headers = {}
        if self.wp_username and self.wp_app_password:
            wp_creds = f"{self.wp_username}:{self.wp_app_password}"
            wp_token = base64.b64encode(wp_creds.encode()).decode()
            headers["Authorization"] = f"Basic {wp_token}"

        response = requests.get(url, params=params, headers=headers, timeout=30)
        response.raise_for_status()
        return self._parse_json_response(response)

    def export_aq(self, order_id: int) -> Dict:
        """
        Calls the custom musesys/v1/export-aq/{order_id} endpoint to retrieve 
        AutoQuotes-compatible structured JSON for a single order.
        """
        url = f"{self.base_url}/wp-json/musesys/v1/export-aq/{order_id}"
        
        headers = {}
        if self.wp_username and self.wp_app_password:
            wp_creds = f"{self.wp_username}:{self.wp_app_password}"
            wp_token = base64.b64encode(wp_creds.encode()).decode()
            headers["Authorization"] = f"Basic {wp_token}"

        response = requests.get(url, headers=headers, timeout=30)
        response.raise_for_status()
        return self._parse_json_response(response)

    def get_customer(self, customer_id: int) -> Dict:
        return self._get(f"customers/{customer_id}")

    def update_order(self, order_id: int, data: Dict[str, Any]) -> bool:
        """
        Updates order data (meta, line items, etc).
        """
        try:
            self._post(f"orders/{order_id}", data)
            return True
        except Exception as e:
            self._log_error(f"Failed to update order {order_id}: {e}")
            return False

    def update_order_meta(self, order_id: int, meta: Dict[str, Any]) -> bool:
        """
        Updates order meta data.
        """
        # Format for WC API: {'meta_data': [{'key': k, 'value': v}, ...]}
        meta_data = [{'key': k, 'value': v} for k, v in meta.items()]
        return self.update_order(order_id, {"meta_data": meta_data})

    def add_shipment_tracking(self, order_id: int, carrier: str, tracking_number: str, items_shipped: List[Dict] = None) -> bool:
        """
        Adds shipment tracking info using the WooCommerce Shipment Tracking plugin endpoint.
        """
        try:
            # Construct payload
            data = {
                "tracking_provider": carrier,
                "tracking_number": tracking_number,
            }
            if items_shipped:
                pass

            # We need to bypass _post to use a different namespace
            url = f"{self.base_url}/wp-json/wc-shipment-tracking/v3/orders/{order_id}/shipment-trackings"
            
            response = self.session.post(url, json=data)
            response.raise_for_status()
            return True
            
        except Exception as e:
            self._log_error(f"Failed to add shipment tracking via plugin for order {order_id}: {e}")
            return False

    def add_order_note(self, order_id: int, note: str, customer_note: bool = True) -> bool:
        """
        Adds a note to an order.
        """
        try:
            data = {
                "note": note,
                "customer_note": customer_note
            }
            self._post(f"orders/{order_id}/notes", data)
            return True
        except Exception as e:
            self._log_error(f"Failed to add note to order {order_id}: {e}")
            return False

    def create_product(self, data: Dict[str, Any]) -> Optional[Dict]:
        """
        Creates a new product in WooCommerce.
        """
        try:
            return self._post("products", data)
        except Exception as e:
            self._log_error(f"Failed to create product: {e}")
            return None

    def search_products(self, query: str) -> List[Dict]:
        """
        Searches for products by name or SKU.
        """
        try:
            params = {"search": query, "per_page": 20}
            return self._get("products", params=params)
        except Exception as e:
            self._log_error(f"Failed to search products: {e}")
            return []

    def update_product(self, product_id: int, data: Dict[str, Any]) -> Optional[Dict]:
        """
        Updates an existing product.
        """
        try:
            return self._post(f"products/{product_id}", data)
        except Exception as e:
            self._log_error(f"Failed to update product {product_id}: {e}")
            return None

    def get_categories(self) -> List[Dict]:
        """
        Fetches product categories.
        """
        try:
            return self._get("products/categories", params={"per_page": 100})
        except Exception as e:
            self._log_error(f"Failed to fetch categories: {e}")
            return []

    def get_product_requests(self) -> List[Dict]:
        """
        Fetches pending product requests. 
        Force-bypasses Nginx/Cloudways caching using a timestamp.
        """
        url = f"{self.base_url}/wp-json/musesys/v1/product-requests"
        print(f"DEBUG: Attempting to fetch from: {url}")
        
        try:
            # 1. THE TIMEZONE FIX: We don't filter by date. We grab the list.
            # 2. THE CACHE FIX: We add '_': timestamp. 
            #    This forces Cloudways/Nginx to serve a FRESH response, not a cached one.
            import time
            current_time = int(time.time())
            
            # Using requests.get directly to bypass Auth header stripping issues
            # FIX: Added Basic Auth to satisfy rest_authentication_errors lockdown
            response = requests.get(
                url, 
                params={"_": current_time},
                auth=(self.wp_username, self.wp_app_password)
            ) 
            
            print(f"DEBUG: Response Status Code: {response.status_code}")
            response.raise_for_status()
            
            data = self._parse_json_response(response)
            print(f"DEBUG: Successful Payload. Found {len(data)} items.")
            return data
            
        except Exception as e:
            print(f"CRITICAL API ERROR: {e}")
            if 'response' in locals():
                print(f"Server Response Text: {response.text}")
            return []

    def get_heartbeat(self) -> Optional[Dict[str, str]]:
        """
        Fetches the heartbeat status (last modified timestamps).
        """
        url = f"{self.base_url}/wp-json/musesys/v1/heartbeat"
        try:
            response = requests.get(url, params={"_": int(__import__('time').time())})
            
            if response.status_code == 200:
                return self._parse_json_response(response)
            return None
        except Exception as e:
            # Silent fail for heartbeat
            return None

    def complete_product_request(self, request_id: int, products: list, action: str = 'send_confirm') -> bool:
        """
        Completes a product request by POSTing to the /complete endpoint.
        Sends mapped product IDs/links and sets status to completed.
        """
        # FIX: Was incorrectly hitting /product-requests/{id} (update endpoint).
        # Must hit /product-requests/{id}/complete to trigger status change + item mapping.
        url = f"{self.base_url}/wp-json/musesys/v1/product-requests/{request_id}/complete"
        print(f"DEBUG: Attempting to COMPLETE request {request_id} at: {url}")
        
        data = {
            "items": products,  # [{'item_id': str, 'link': str}, ...]
            "status": "completed",
            "action": action
        }

        try:
            response = requests.post(
                url, 
                json=data, 
                auth=(self.wp_username, self.wp_app_password)
            )
            
            print(f"DEBUG: Completion Response Code: {response.status_code}")
            
            if response.status_code not in [200, 201]:
                print(f"DEBUG: Server Failure Message: {response.text}")
            
            response.raise_for_status()
            return True
            
        except Exception as e:
            print(f"CRITICAL COMPLETION ERROR: {e}")
            return False

    def ship_lines(self, order_id: int, payload: Dict[str, Any]) -> bool:
        """
        Sends shipment details to the custom endpoint.
        Uses Query Params for Auth to ensure compatibility with custom routes.
        """
        url = f"{self.base_url}/wp-json/musesys/v1/orders/{order_id}/ship-lines"
        print(f"DEBUG: Sending Shipment for Order {order_id} to: {url}")
        
        # Determine Auth Headers vs Query Params
        headers = {}
        if self.wp_username and self.wp_app_password:
            wp_creds = f"{self.wp_username}:{self.wp_app_password}"
            wp_token = base64.b64encode(wp_creds.encode()).decode()
            headers["Authorization"] = f"Basic {wp_token}"
            params = {} # Don't need query params
        else:
            params = {
                "consumer_key": self.consumer_key,
                "consumer_secret": self.consumer_secret
            }

        # Why the last call failed, for the caller to show. '' when it did not fail.
        self.last_ship_error = ""

        try:
            # Direct requests.post to avoid header conflict
            response = requests.post(url, json=payload, params=params, headers=headers)

            print(f"DEBUG: Ship Lines Response Code: {response.status_code}")

            if response.status_code not in [200, 201]:
                # Read the reason before printing: the print can itself fail on a console that
                # cannot show the BOM WordPress sometimes sends, which would replace the reason.
                self.last_ship_error = self._server_message(response)
                print(f"DEBUG: Server Failure Message: {response.text}")
                return False

            response.raise_for_status()
            return True
        except Exception as e:
            print(f"CRITICAL SHIP ERROR: {e}")
            if not self.last_ship_error:
                self.last_ship_error = str(e)
            return False

    def _server_message(self, response: requests.Response) -> str:
        """The site's own explanation of a refusal (WordPress sends {"code", "message"}), else the HTTP status."""
        try:
            body = self._parse_json_response(response)
            if isinstance(body, dict) and body.get("message"):
                return str(body["message"])
        except Exception:
            pass
        return f"The site answered HTTP {response.status_code}."

    def get_assets(self, store_id: str) -> List[Dict]:
        """
        Calls the custom musesys/v1/assets endpoint to retrieve assets for a store.
        """
        url = f"{self.base_url}/wp-json/musesys/v1/assets"
        params = {"store_id": store_id}
        
        headers = {}
        if self.wp_username and self.wp_app_password:
            wp_creds = f"{self.wp_username}:{self.wp_app_password}"
            wp_token = base64.b64encode(wp_creds.encode()).decode()
            headers["Authorization"] = f"Basic {wp_token}"

        try:
            response = requests.get(url, params=params, headers=headers, timeout=30)
            response.raise_for_status()
            return self._parse_json_response(response)
        except Exception as e:
            self._log_error(f"Failed to fetch assets for {store_id}: {e}")
            return []

    def update_asset(self, asset_id: int, payload: Dict) -> bool:
        """
        Updates an asset via musesys/v1/assets/{id}
        """
        url = f"{self.base_url}/wp-json/musesys/v1/assets/{asset_id}"
        
        headers = {"Content-Type": "application/json"}
        if self.wp_username and self.wp_app_password:
            wp_creds = f"{self.wp_username}:{self.wp_app_password}"
            wp_token = base64.b64encode(wp_creds.encode()).decode()
            headers["Authorization"] = f"Basic {wp_token}"

        try:
            response = requests.post(url, json=payload, headers=headers, timeout=30)
            response.raise_for_status()
            return True
        except Exception as e:
            self._log_error(f"Failed to update asset {asset_id}: {e}")
            return False

    def upload_media(self, file_path: str, target_filename: str) -> Optional[int]:
        """
        Uploads an image to the Media Library.
        Returns the Media ID on success, None on failure.
        """
        endpoint = "media"
        url = f"{self.base_url}/wp-json/wp/v2/{endpoint}"
        
        headers = {
            "Content-Disposition": f'attachment; filename="{target_filename}"',
        }
        
        import mimetypes
        mime_type, _ = mimetypes.guess_type(file_path)
        if mime_type:
            headers["Content-Type"] = mime_type
        else:
            headers["Content-Type"] = "application/octet-stream"

        auth = None
        if self.wp_username and self.wp_app_password:
             auth = (self.wp_username, self.wp_app_password)
        else:
            creds = f"{self.consumer_key}:{self.consumer_secret}"
            token = base64.b64encode(creds.encode()).decode()
            headers["Authorization"] = f"Basic {token}"

        try:
            with open(file_path, "rb") as f:
                data = f.read()
            
            if auth:
                response = requests.post(url, headers=headers, data=data, auth=auth)
            else:
                response = requests.post(url, headers=headers, data=data)
            
            if response.status_code == 201:
                return self._parse_json_response(response).get('id')
            else:
                print(f"Media Upload Failed: {response.text}")
                return None
            
        except Exception as e:
            self._log_error(f"Failed to upload media {target_filename}: {e}")
            return None

    def _log_error(self, message):
        import traceback
        import datetime
        import os
        
        timestamp = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        log_entry = f"[{timestamp}] {message}\n{traceback.format_exc()}\n{'-'*40}\n"
        
        try:
            with open("error_log.txt", "a") as f:
                f.write(log_entry)
        except Exception:
            print(f"Failed to write to log: {message}")

import csv
import os
from datetime import datetime
import threading
from tkinter import messagebox, Toplevel, Label
from app.models import Order

def generate_eod_report(bridge, root_window, on_complete=None):
    """
    Generates a flattened CSV report of all orders modified today.
    Shows a popup dialog while processing.
    """
    
    # 1. UI Popup
    popup = Toplevel(root_window)
    popup.title("Processing")
    popup.geometry("300x100")
    # Center popup
    x = root_window.winfo_x() + (root_window.winfo_width() // 2) - 150
    y = root_window.winfo_y() + (root_window.winfo_height() // 2) - 50
    popup.geometry(f"+{x}+{y}")
    popup.transient(root_window)
    popup.grab_set()
    
    Label(popup, text="Generating Daily Report...\nPlease Wait...", font=("Arial", 12)).pack(expand=True)
    root_window.update()

    def _run():
        try:
            # 2. Fetch Orders (Active Filter)
            # A. Fetch Processing (ALL)
            orders_processing = []
            if bridge.client:
                 try:
                     raw_proc = bridge.client.get_orders(limit=100, status='processing')
                     orders_processing = [Order.from_wc_data(d) for d in raw_proc]
                 except Exception as e:
                     print(f"REPORT: Error fetching processing orders: {e}")

            # B. Fetch Completed (Today)
            orders_completed = []
            today_iso = datetime.now().strftime("%Y-%m-%dT00:00:00")
            if bridge.client:
                 try:
                     raw_comp = bridge.client.get_orders(limit=100, status='completed', after=today_iso)
                     orders_completed = [Order.from_wc_data(d) for d in raw_comp]
                 except Exception as e:
                     print(f"REPORT: Error fetching completed orders: {e}")
            
            # Combine and Deduplicate
            all_orders = {o.id: o for o in orders_processing + orders_completed}
            
            # Load Local Prices (The Merge)
            local_prices = {}
            try:
                # Assuming standard path relative to bridge usage, but we need to find it safely.
                # In order_ops we used bridge.data_dir. Here we passed 'bridge' object.
                data_dir = bridge.data_dir if hasattr(bridge, 'data_dir') else "data"
                price_file = os.path.join(data_dir, "daily_prices.json")
                if os.path.exists(price_file):
                    import json
                    with open(price_file, 'r') as f:
                        local_prices = json.load(f)
            except Exception as e:
                print(f"REPORT: Failed to load local prices: {e}")

            # Header (The Monster List)
            header = [
                "Order ID", "Status", "Date Created", "Date Completed",
                "WO Number", "Store #", "Install #", 
                "Billing Company", "Shipping Address 1", "Shipping City", "Shipping State",
                "SKU", "Item Name", "Quantity", "Unit Price",
                "Tracking Number", "Carrier"
            ]
            
            report_rows = []
            count = 0
            
            for oid, order in all_orders.items():
                count += 1
                
                # ... Meta Extraction ...
                install_num = "" 
                
                # Flatten Line Items
                if order.line_items:
                    for item in order.line_items:
                        # PRICE MERGE LOGIC
                        # Check local prices first
                        unit_price = item.price
                        
                        # Look up: OrderID -> ItemID
                        order_prices = local_prices.get(str(order.id), {})
                        if str(item.id) in order_prices:
                            unit_price = order_prices[str(item.id)]
                        
                        # Fallback
                        if not unit_price or unit_price == "0.00":
                            # Maybe try manual_total / qty if absolutely needed? 
                            # Or just keep 0.00. User asked for 0.00 default.
                            pass

                        row = [
                            order.id,
                            order.status,
                            order.date_created,
                            order.date_completed,
                            item.wo_number or "",      # Item WO preferred
                            item.install_store_id or "", # Item Store preferred
                            install_num,               # Install #
                            order.billing.get('company', ''),
                            order.shipping.get('address_1', ''),
                            order.shipping.get('city', ''),
                            order.shipping.get('state', ''),
                            # Item details
                            item.name.split('/')[0].strip() if '/' in item.name else "", # Extract SKU
                            item.name,
                            item.quantity,
                            unit_price, # USES MERGED PRICE
                            order.tracking_number or "",
                            order.carrier_name or ""
                        ]
                        report_rows.append(row)
                else:
                    # Empty order row
                    row = [
                        order.id, order.status, order.date_created, order.date_completed,
                        "", "", "", 
                        order.billing.get('company', ''),
                        order.shipping.get('address_1', ''),
                        order.shipping.get('city', ''),
                        order.shipping.get('state', ''),
                        "", "NO ITEMS", "0", "0.00",
                        order.tracking_number or "", order.carrier_name or ""
                    ]
                    report_rows.append(row)

            # 3. Write CSV
            if not report_rows:
                print("REPORT: No data to report.")
            else:
                # 1. Define Paths
                filename = f"DailyReport_{datetime.now().strftime('%Y-%m-%d_%H%M%S')}.csv"
                network_path = r"\\GHZ-PDC01\Users$\chenderson\Desktop"
                local_path = os.getcwd()

                # 2. Determine Save Location
                save_dir = local_path # Default to local
                if os.path.exists(network_path):
                    save_dir = network_path
                else:
                    print(f"WARNING: Network Desktop not found at {network_path}. Falling back to local folder.")

                # 3. Create Full Path
                filepath = os.path.join(save_dir, filename)

                print(f"REPORT: Saving to {filepath}")
                
                with open(filepath, 'w', newline='', encoding='utf-8') as f:
                    writer = csv.writer(f)
                    writer.writerow(header)
                    writer.writerows(report_rows)
                    
                print(f"REPORT: Generated at {filepath} with {count} orders.")
            
        except Exception as e:
            print(f"REPORT ERROR: {e}")
            messagebox.showerror("Report Error", f"Failed to generate report: {e}")
        
        finally:
            # Close popup
            root_window.after(0, popup.destroy)
            if on_complete:
                root_window.after(0, on_complete)

    t = threading.Thread(target=_run, daemon=True)
    t.start()

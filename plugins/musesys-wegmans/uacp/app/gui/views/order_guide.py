import tkinter as tk
from tkinter import ttk, messagebox, filedialog
import threading
import re
import csv
from datetime import datetime, timedelta
from tksheet import Sheet

from app.core.config import ConfigManager
from app.data.bridge import MuseDataBridge
from app.data.logistics import evaluate_shipment_action

class OrderGuideView(ttk.Frame):
    def __init__(self, parent):
        super().__init__(parent)
        self.bridge = MuseDataBridge()
        self.orders_data = []
        self.setup_ui()
        self.refresh_data()

    def setup_ui(self):
        # Header
        header = ttk.Frame(self, padding="10")
        header.pack(fill=tk.X)
        ttk.Label(header, text="Shipment Action Queue", font=("Arial", 12, "bold")).pack(side=tk.LEFT)
        
        # Filter Dropdown
        ttk.Label(header, text="Filter:").pack(side=tk.LEFT, padx=(20, 5))
        self.filter_var = tk.StringVar(value="Needs Action")
        self.filter_cb = ttk.Combobox(header, textvariable=self.filter_var, state="readonly", width=20)
        self.filter_cb['values'] = (
            "Needs Action",
            "Overdue / Critical",
            "ETA Due Soon",
            "Missing Tracking",
            "Partials / Backorders",
            "Data Issues",
            "All Orders"
        )
        self.filter_cb.pack(side=tk.LEFT)
        self.filter_cb.bind("<<ComboboxSelected>>", lambda e: self.apply_filter())

        self.btn_refresh = tk.Button(header, text="Refresh Data", bg='#28a745', fg='white', font=('Arial', 10, 'bold'), command=self.refresh_data)
        self.btn_refresh.pack(side=tk.RIGHT, padx=5)

        self.btn_export = tk.Button(header, text="Export to Excel", bg='#0073aa', fg='white', font=('Arial', 10, 'bold'), command=self.export_to_csv)
        self.btn_export.pack(side=tk.RIGHT, padx=5)

        # Sheet container
        sheet_frame = tk.Frame(self)
        sheet_frame.pack(fill=tk.BOTH, expand=True, padx=10, pady=10)

        self.headers = [
            "PO Number",
            "Vendor",
            "Date",
            "Tracking #",
            "Logistics Status",
            "ETA Date",
            "Severity",
            "Reason",
            "Order Number",
            "Item #",
            "Item Name",
            "Quantity",
            "Item Cost",
            "Customer Note",
            "Customer User Email",
            "Address 1&2 (Shipping)",
            "City, State, Zip (Shipping)",
            "additional_install_store_number",
            "additional_work_order_number",
            "global_install_store"
        ]

        self.sheet = Sheet(sheet_frame,
                           data=[],
                           headers=self.headers,
                           header_font=("Arial", 10, "bold"),
                           theme="light green")

        self.sheet.enable_bindings(
            "single_select", "drag_select", "select_all",
            "column_select", "row_select", "column_width_resize",
            "double_click_column_resize", "row_height_resize", "column_resize",
            "row_resize", "edit_cell", "copy", "paste", "delete", "undo"
        )

        self.sheet.pack(fill=tk.BOTH, expand=True)
        self.sheet.extra_bindings([("cell_modified", self.cell_edited)])

    def refresh_data(self):
        self.btn_refresh.config(state="disabled")
        threading.Thread(target=self._fetch_data_thread, daemon=True).start()

    def _fetch_data_thread(self):
        try:
            settings = ConfigManager.load_settings()
            lookback_days = int(settings.get("lookback_days", 180))
            end_date = datetime.now().strftime("%Y-%m-%d")
            start_date = (datetime.now() - timedelta(days=lookback_days)).strftime("%Y-%m-%d")
            data = self.bridge.export_orders_to_csv_data(start_date=start_date, end_date=end_date, export_all=False)
            self.after(0, lambda: self.update_sheet(data))
        except Exception as e:
            print(f"Error fetching data: {e}")
            self.after(0, lambda: self.btn_refresh.config(state="normal"))

    def apply_filter(self):
        self.update_sheet(self.orders_data)

    def update_sheet(self, data):
        self.orders_data = data
        sheet_data = []
        
        current_filter = self.filter_var.get()
        
        # We need today's date for evaluation
        from datetime import date
        today = date.today()
        
        # Evaluate and filter rows
        evaluated_rows = []
        for row in data:
            eval_res = evaluate_shipment_action(row, today)
            
            # Apply UI filters
            if current_filter != "All Orders":
                if not eval_res["show"]:
                    continue
                
                if current_filter == "Overdue / Critical" and eval_res["severity"] != "critical":
                    continue
                if current_filter == "ETA Due Soon" and "due_soon" not in eval_res["reason_code"]:
                    continue
                if current_filter == "Missing Tracking" and "missing_tracking" not in eval_res["reason_code"] and "needs_tracking" not in eval_res["reason_code"]:
                    continue
                if current_filter == "Partials / Backorders" and "partial" not in eval_res["reason_code"] and "backorder" not in eval_res["reason_code"]:
                    continue
                if current_filter == "Data Issues" and eval_res["severity"] != "data_issue":
                    continue
                    
            evaluated_rows.append((row, eval_res))
            
        # Sort by bucket (lower is more critical)
        if current_filter != "All Orders":
            evaluated_rows.sort(key=lambda x: x[1]["sort_bucket"])

        for row, eval_res in evaluated_rows:
            po = row.get('global_work_order') or row.get('additional_work_order_number', '')
            vendor = row.get('SKU', '')
            date_str = row.get('Order Date', '')
            tracking = row.get('Primary Tracking', '')
            order_no = row.get('Order Number', '')
            item_num = row.get('Item #', '')
            item_name = row.get('Item Name', '')
            qty = row.get('Ordered Qty') or row.get('Quantity', '')
            cost = row.get('Item Cost', '')
            cust_note = row.get('Customer Note', '')
            email = row.get('Customer User Email', '')
            addr = row.get('Address 1&2 (Shipping)', '')
            city_state = row.get('City, State, Zip (Shipping)', '')
            add_store = row.get('additional_install_store_number', '')
            add_wo = row.get('additional_work_order_number', '')
            g_store = row.get('global_install_store', '')
            
            logistics_status = row.get('Logistics Status', '')
            eta_date = row.get('Logistics Date', '')
            severity = eval_res["severity"]
            reason = eval_res["reason_label"]

            sheet_data.append([
                po, vendor, date_str, tracking, logistics_status, eta_date, severity, reason,
                order_no, item_num, item_name, qty, cost, cust_note, email, addr, city_state, add_store, add_wo, g_store
            ])

        self.sheet.set_sheet_data(sheet_data)
        self.sheet.set_all_cell_sizes_to_text()
        self.btn_refresh.config(state="normal")

    def cell_edited(self, event):
        try:
            row, col = event[0], event[1]
            if col == 3:  # Tracking # column
                new_value = self.sheet.get_cell_data(row, col).strip()
                if new_value:
                    if self.validate_tracking(new_value):
                        self.sheet.highlight_cells(row=row, column=col, bg="white")
                        self.save_tracking(row, new_value)
                    else:
                        self.sheet.highlight_cells(row=row, column=col, bg="#ffcccc")
                        messagebox.showwarning("Invalid Tracking Format", "Tracking number does not match UPS, FedEx, or USPS standard formats.")
        except Exception as e:
            print(f"Error in cell_edited: {e}")

    def validate_tracking(self, tracking_number):
        if re.match(r'^\d{22}$', tracking_number) or re.match(r'^[A-Za-z]{2}\d{9}[A-Za-z]{2}$', tracking_number):
            return True
        if re.match(r'^1Z[A-Za-z0-9]{16}$', tracking_number, re.IGNORECASE):
            return True
        if re.match(r'^(\d{12}|\d{15}|\d{20})$', tracking_number):
            return True
        return False

    def save_tracking(self, row, tracking_number):
        order_no = self.sheet.get_cell_data(row, 8) # Order Number is now column 8
        if not order_no:
            return

        def _save():
            try:
                carrier = "UNKNOWN"
                if tracking_number.upper().startswith("1Z"):
                    carrier = "UPS"
                elif len(tracking_number) in [12, 15, 20] and tracking_number.isdigit():
                    carrier = "FEDEX"
                else:
                    carrier = "USPS"

                order = self.bridge.get_order(order_no)
                if not order:
                    return

                # We need to map the spreadsheet row to the exact line item.
                item_idx_str = self.sheet.get_cell_data(row, 9) # "Item #" is col 9
                try:
                    item_idx = int(item_idx_str) - 1
                except:
                    item_idx = -1

                target_item = None
                if 0 <= item_idx < len(order.line_items):
                    target_item = order.line_items[item_idx]

                ship_lines_data = []
                if target_item:
                    remaining_qty = max(0, int(target_item.quantity) - int(getattr(target_item, "shipped_qty", 0) or 0))
                    if remaining_qty > 0:
                        ship_lines_data.append({
                            "line_item_id": target_item.id,
                            "ship_qty": remaining_qty
                        })

                # Determine if this closes out the whole order
                total_qty = sum(int(i.quantity) for i in order.line_items)
                total_shipped = sum(int(getattr(i, "shipped_qty", 0) or 0) for i in order.line_items)
                # add what we are shipping now
                total_shipped += sum(line["ship_qty"] for line in ship_lines_data)
                closeout = (total_shipped >= total_qty)

                payload = {
                    "carrier": carrier,
                    "tracking_numbers": [tracking_number],
                    "lines": ship_lines_data,
                    "notify": True,
                    "closeout": closeout
                }
                success = self.bridge.ship_lines(order_no, payload)
                if success:
                    self.after(0, lambda: self.sheet.highlight_cells(row=row, column=3, bg="#d4edda")) # Light green on success
                else:
                    # A refused shipment used to leave the cell as typed, with nothing said.
                    reason = self.bridge.ship_problem()
                    self.after(0, lambda: self.sheet.highlight_cells(row=row, column=3, bg="#f8d7da")) # Light red on failure
                    self.after(0, lambda: messagebox.showerror(
                        "Tracking not saved",
                        f"Order #{order_no}: the shipment was NOT recorded on the order.\n{reason}"
                    ))
            except Exception as e:
                print(f"Failed to save tracking: {e}")

        threading.Thread(target=_save, daemon=True).start()

    def export_to_csv(self):
        file_path = filedialog.asksaveasfilename(
            defaultextension=".csv",
            initialfile=f"OrderGuide_{datetime.now().strftime('%Y%m%d')}.csv",
            filetypes=[("CSV files", "*.csv"), ("All files", "*.*")]
        )
        if not file_path:
            return

        try:
            data = self.sheet.get_sheet_data()
            with open(file_path, 'w', newline='', encoding='utf-8') as f:
                writer = csv.writer(f)
                writer.writerow(self.headers)
                writer.writerows(data)
            messagebox.showinfo("Export Successful", f"Data exported to {file_path}")
        except Exception as e:
            messagebox.showerror("Export Error", str(e))

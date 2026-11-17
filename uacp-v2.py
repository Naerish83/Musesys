import tkinter as tk
from tkinter import ttk, messagebox
import sys
import os
import threading
import time
import csv
from datetime import datetime
from tkinter import filedialog

# Ensure we can import from uacp
sys.path.append(os.path.dirname(os.path.abspath(__file__)))

from app.core.config import ConfigManager
from app.gui.views.order_ops import OrderOpsView
from app.gui.views.product_requests import ProductRequestView
from app.gui.views.product_lookup import ProductLookupView
from app.gui.views.catalog_maintenance import CatalogMaintenanceView

from app.gui.daily_report import generate_eod_report

class TeammateApp(tk.Tk):
    def __init__(self):
        super().__init__()
        
        self.title("Musesys Field Terminal")
        self.geometry("1200x800")

        # Hook Close Event
        self.protocol("WM_DELETE_WINDOW", self.quit_app)
        
        # 1. Check Configuration (Keep this brief/fast)
        if not self.check_config():
            self.stop_threads = True 
            self.destroy()
            return

        # 2. Setup UI (Visuals Only - NO Blocking Calls)
        self.setup_ui()
        
        # 3. Schedule Initial Load (Non-blocking)
        self.last_heartbeat = {}
        self.stop_threads = False
        self.after(100, self.initial_load)

    def check_config(self):
        """
        Silently checks if configuration exists and is valid.
        Returns True if good, False (and shows error) if bad.
        """
        try:
            settings = ConfigManager.load_settings()
            active = settings.get("active_site")
            if not active:
                raise ValueError("No active site configured.")
                
            site_data = settings.get("sites", {}).get(active, {})
            if not site_data.get("api_url") or not site_data.get("consumer_key"):
                raise ValueError("Active site configuration is incomplete.")
                
            return True
            
        except Exception as e:
            messagebox.showerror(
                "Configuration Error",
                f"Configuration file missing or invalid.\nPlease contact Administrator.\n\nError: {str(e)}"
            )
            return False

    def setup_ui(self):
        """
        Builds the UI structure. No Network Calls here.
        """
        # Create Menu Bar
        self.menubar = tk.Menu(self)
        self.config(menu=self.menubar)

        # File Menu
        self.file_menu = tk.Menu(self.menubar, tearoff=0)
        self.menubar.add_cascade(label="File", menu=self.file_menu)
        self.file_menu.add_command(label="Export Orders to CSV", command=self.open_export_modal)
        self.file_menu.add_separator()
        self.file_menu.add_command(label="Exit", command=self.quit_app)

        # Using Notebook for Tabs
        self.notebook = ttk.Notebook(self)
        self.notebook.pack(fill=tk.BOTH, expand=True)
        
        # Tab 1: Active Orders
        self.tab_orders = ttk.Frame(self.notebook)
        self.notebook.add(self.tab_orders, text="Active Orders")
        
        self.order_ops = OrderOpsView(self.tab_orders)
        self.order_ops.pack(fill=tk.BOTH, expand=True)
        
        # Tab 2: Product Requests
        self.tab_requests = ttk.Frame(self.notebook)
        self.notebook.add(self.tab_requests, text="Product Requests")
        
        self.request_ops = ProductRequestView(self.tab_requests)
        self.request_ops.pack(fill=tk.BOTH, expand=True)
        
        # Tab 3: Inventory Lookup
        self.tab_lookup = ttk.Frame(self.notebook)
        self.notebook.add(self.tab_lookup, text="Inventory Lookup")
        
        self.lookup_ops = ProductLookupView(self.tab_lookup)
        self.lookup_ops.pack(fill=tk.BOTH, expand=True)
        
        # Tab 4: Catalog Maintenance
        self.tab_catalog = ttk.Frame(self.notebook)
        self.notebook.add(self.tab_catalog, text="Catalog Maintenance")
        
        self.catalog_ops = CatalogMaintenanceView(self.tab_catalog)
        self.catalog_ops.pack(fill=tk.BOTH, expand=True)
        
        # Status Light (Bottom Right)
        self.status_frame = ttk.Frame(self)
        self.status_frame.pack(side=tk.BOTTOM, fill=tk.X)
        
        # Spacer to push to right
        ttk.Frame(self.status_frame).pack(side=tk.LEFT, fill=tk.X, expand=True)
        
        self.status_canvas = tk.Canvas(self.status_frame, width=20, height=20, highlightthickness=0)
        self.status_canvas.pack(side=tk.RIGHT, padx=10, pady=2)
        
        # Draw Circle (Initial: Orange)
        self.status_light = self.status_canvas.create_oval(2, 2, 18, 18, fill="orange", outline="gray")

    def update_status_light(self, color):
        """
        Updates the status light color.
        """
        self.status_canvas.itemconfig(self.status_light, fill=color)

    def initial_load(self):
        """
        Starts background data loading and the watcher loop.
        """
        self.update_status_light("orange")
        
        # Start Watcher Thread
        threading.Thread(target=self.run_watch, daemon=True).start()
        
        # Trigger initial data fetch in background if views support it
        # OrderOpsView usually does its own async fetch in init, but we can reinforce or just let it happen.
        # Use a separate thread to ensure UI paints first
        def load_data():
             # Check if views need manual trigger?
             # OrderOpsView calls refresh_list_async in __init__, so it starts thread immediately.
             # ProductRequestView?
             # Let's just update status
             pass
        
        threading.Thread(target=load_data, daemon=True).start()

    def run_watch(self):
        """
        The background watcher loop.
        """
        while not self.stop_threads:
            try:
                # Poll Heartbeat
                # Note: order_ops.bridge is accessible as self.order_ops.bridge
                if hasattr(self, 'order_ops') and hasattr(self.order_ops, 'bridge'):
                    hb = self.order_ops.bridge.check_heartbeat()
                    
                    if hb:
                        self.after(0, lambda: self.update_status_light("green"))
                        refresh_orders = False
                        refresh_requests = False
                        
                        if self.last_heartbeat:
                           if hb.get('last_order') != self.last_heartbeat.get('last_order'):
                               refresh_orders = True
                           if hb.get('last_request') != self.last_heartbeat.get('last_request'):
                               refresh_requests = True
                        else:
                            # First run, just store it
                            self.last_heartbeat = hb
                            # Optional: Trigger refresh on first connect?
                            # refresh_orders = True 
                        
                        if refresh_orders or refresh_requests:
                            self.last_heartbeat = hb
                            self.after(0, lambda: self.trigger_refresh(refresh_orders, refresh_requests))
                    else:
                        pass
                else:
                    pass

            except Exception as e:
                print(f"Watcher Error: {e}")
                self.after(0, lambda: self.update_status_light("red"))
            
            time.sleep(5)

    def trigger_refresh(self, orders=False, requests=False):
        """
        Called by background thread via self.after()
        """
        self.update_status_light("orange")
        
        def on_complete():
            self.update_status_light("green")

        # Trigger Refreshes
        if orders:
            self.order_ops.refresh_list_async(callback=on_complete if not requests else None)
            
        if requests:
            # If both, only callback on last one (imperfect but serviceable)
            self.request_ops.refresh_list_async(callback=on_complete)

    def quit_app(self):
        """
        Clean Exit logic.
        """
        if messagebox.askokcancel("Quit", "Generate EOD Report and Close?"):
            self.update_status_light("red")
            
            # Stop threads logic
            self.stop_threads = True
            
            # Trigger Report -> Destroy
            # Trigger Report -> Destroy
            try:
                # TELEMETRY/REPORTING DISABLED FOR RC 1.0
                if False and hasattr(self, 'order_ops') and hasattr(self.order_ops, 'bridge'):
                    bridge = self.order_ops.bridge
                    # Use a lambda to ensure we destroy after report
                    generate_eod_report(bridge, self, on_complete=self.true_destroy)
                else:
                    self.true_destroy()
            except Exception as e:
                print(f"Exit Error: {e}")
                self.true_destroy()

    def true_destroy(self):
        self.stop_threads = True
        self.destroy()
        sys.exit(0)

    # --- Export Feature ---
    def open_export_modal(self):
        modal = tk.Toplevel(self)
        modal.title("Export Orders to CSV")
        modal.geometry("350x250")
        modal.resizable(False, False)
        modal.transient(self)
        modal.grab_set()

        # Center it
        modal.update_idletasks()
        x = self.winfo_x() + (self.winfo_width() // 2) - (modal.winfo_width() // 2)
        y = self.winfo_y() + (self.winfo_height() // 2) - (modal.winfo_height() // 2)
        modal.geometry(f"+{x}+{y}")

        tk.Label(modal, text="Export WooCommerce Orders", font=('Arial', 12, 'bold')).pack(pady=10)

        # Start Date
        start_frame = tk.Frame(modal)
        start_frame.pack(fill=tk.X, padx=20, pady=5)
        tk.Label(start_frame, text="Start Date (YYYY-MM-DD):", width=22, anchor='w').pack(side=tk.LEFT)
        start_entry = tk.Entry(start_frame)
        start_entry.pack(side=tk.LEFT, fill=tk.X, expand=True)

        # End Date
        end_frame = tk.Frame(modal)
        end_frame.pack(fill=tk.X, padx=20, pady=5)
        tk.Label(end_frame, text="End Date (YYYY-MM-DD):", width=22, anchor='w').pack(side=tk.LEFT)
        end_entry = tk.Entry(end_frame)
        end_entry.pack(side=tk.LEFT, fill=tk.X, expand=True)

        # Export All
        export_all_var = tk.BooleanVar(value=False)
        chk = tk.Checkbutton(modal, text="Export All (Ignore Dates)", variable=export_all_var)
        chk.pack(pady=5)

        def do_export():
            start = start_entry.get().strip()
            end = end_entry.get().strip()
            is_all = export_all_var.get()
            
            if not is_all and not start and not end:
                messagebox.showwarning("Warning", "Please enter dates or select 'Export All'", parent=modal)
                return
                
            file_path = filedialog.asksaveasfilename(
                defaultextension=".csv",
                initialfile=f"Teammate_Orders_Export_{datetime.now().strftime('%Y%m%d')}.csv",
                filetypes=[("CSV files", "*.csv"), ("All files", "*.*")],
                parent=modal
            )
            
            if not file_path:
                return
                
            modal.destroy()
            self.execute_export(file_path, start, end, is_all)

        btn = tk.Button(modal, text="Export to CSV", bg='#28a745', fg='white', font=('Arial', 10, 'bold'), command=do_export)
        btn.pack(pady=15)

    def execute_export(self, file_path, start_date, end_date, export_all):
        if not hasattr(self, 'order_ops') or not self.order_ops.bridge:
            messagebox.showerror("Error", "No connection bridge available.")
            return

        def task():
            try:
                data = self.order_ops.bridge.export_orders_to_csv_data(start_date, end_date, export_all)
                if not data:
                    self.after(0, lambda: messagebox.showinfo("No Data", "No orders found for this criteria."))
                    self.after(0, lambda: self.update_status_light("green"))
                    return

                # Write CSV
                keys = data[0].keys()
                with open(file_path, 'w', newline='', encoding='utf-8') as output_file:
                    dict_writer = csv.DictWriter(output_file, keys)
                    dict_writer.writeheader()
                    dict_writer.writerows(data)
                    
                self.after(0, lambda: messagebox.showinfo("Success", f"Successfully exported {len(data)} rows to\n{file_path}"))
                self.after(0, lambda: self.update_status_light("green"))
            except Exception as e:
                self.after(0, lambda: messagebox.showerror("Export Error", f"An error occurred during export:\n{str(e)}"))
                self.after(0, lambda: self.update_status_light("red"))

        # Notify user it's running
        self.update_status_light("orange")
        threading.Thread(target=task, daemon=True).start()

if __name__ == "__main__":
    app = TeammateApp()
    app.mainloop()

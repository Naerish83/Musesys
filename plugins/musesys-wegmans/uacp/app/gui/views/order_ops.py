# ==============================================================================
# Title: UACP Order Operations View (order_ops.py)
# Description: View component managing order fulfillment, logistics status, 
#              tracking updates, and data persistence gates.
# ==============================================================================

import tkinter as tk
from tkinter import ttk, messagebox, filedialog
import traceback
import threading
import json
import os
from app.data.bridge import MuseDataBridge
from app.models import Order, CARRIER_OPTIONS
from PIL import Image, ImageDraw, ImageTk

SHOW_PR_ORDER_LINK = True

class OrderOpsView(ttk.Frame):
    def __init__(self, parent):
        super().__init__(parent)
        self.bridge = MuseDataBridge()
        self.current_order_id = None
        
        # Data/Widget References
        self.to_ship_entries = {}
        self.current_line_items = {} # Map item_id -> item data
        
        # Attempt to find controller (Root Window)
        self.controller = self.winfo_toplevel()
        
        self.dot_red = self.make_dot('#e74c3c')
        self.dot_yellow = self.make_dot('#f1c40f')
        self.dot_green = self.make_dot('#2ecc71')

        self.setup_ui()
        
    def make_dot(self, color, size=14):
        img = Image.new('RGBA', (size, size), (0,0,0,0))
        draw = ImageDraw.Draw(img)
        draw.ellipse([1,1,size-1,size-1], fill=color)
        return ImageTk.PhotoImage(img)
        # Initial load
        self.refresh_list_async()

    def setup_ui(self):
        # Use PanedWindow for 3-section vertical split
        self.main_paned = tk.PanedWindow(self, orient=tk.VERTICAL, sashrelief=tk.RAISED)
        self.main_paned.pack(fill=tk.BOTH, expand=True)

        # TOP PANEL (20%)
        self.top_frame = tk.Frame(self.main_paned, bg='white', height=200)
        self.main_paned.add(self.top_frame, minsize=150, sticky='nsew')
        self.setup_top_panel()

        # MIDDLE PANEL (30%)
        self.middle_frame = tk.Frame(self.main_paned, bg='white', height=300)
        self.main_paned.add(self.middle_frame, minsize=200, sticky='nsew')
        self.setup_middle_panel()

        # BOTTOM PANEL (50%)
        self.bottom_frame = tk.Frame(self.main_paned, bg='white', height=500)
        self.main_paned.add(self.bottom_frame, minsize=300, sticky='nsew')
        self.setup_bottom_panel()

    def setup_top_panel(self):
        # A) Order Info Banner
        self.banner_frame = tk.Frame(self.top_frame, bg='#f8f9fa', relief=tk.SOLID, borderwidth=1)
        self.banner_frame.pack(fill=tk.X, padx=10, pady=5)

        self.banner_label = tk.Label(
            self.banner_frame, 
            text="Select an order to view details",
            font=('Arial', 11, 'bold'),
            bg='#f8f9fa',
            anchor='w',
            padx=10,
            pady=8
        )
        self.banner_label.pack(side=tk.LEFT)
        
        self.source_pr_label = tk.Label(
            self.banner_frame,
            text="",
            font=('Arial', 10, 'italic'),
            bg='#f8f9fa',
            fg='#0073aa',
            anchor='e',
            padx=10,
            pady=8
        )
        if SHOW_PR_ORDER_LINK:
            self.source_pr_label.pack(side=tk.RIGHT)

        # B) Form Fields
        form_frame = tk.Frame(self.top_frame, bg='white')
        form_frame.pack(fill=tk.X, padx=10, pady=10)

        # Configure grid weights
        for i in range(6): form_frame.columnconfigure(i, weight=1)

        # Field 1: TRX Order #
        tk.Label(form_frame, text="TRX Order #", bg='white', font=('Arial', 9)).grid(row=0, column=0, sticky='w', padx=5)
        self.trx_entry = tk.Entry(form_frame, bg='#FFF9C4', relief=tk.SOLID, borderwidth=1)
        self.trx_entry.grid(row=1, column=0, sticky='ew', padx=5, pady=2)

        # Field 2: Purchase Order #
        tk.Label(form_frame, text="Purchase Order #", bg='white', font=('Arial', 9)).grid(row=0, column=1, sticky='w', padx=5)
        self.po_entry = tk.Entry(form_frame, bg='#FFF9C4', relief=tk.SOLID, borderwidth=1)
        self.po_entry.grid(row=1, column=1, sticky='ew', padx=5, pady=2)

        # Field 3: Tracking #
        tk.Label(form_frame, text="Tracking #", bg='white', font=('Arial', 9)).grid(row=0, column=2, sticky='w', padx=5)
        self.tracking_entry = tk.Entry(form_frame, bg='#FFF9C4', relief=tk.SOLID, borderwidth=1)
        self.tracking_entry.grid(row=1, column=2, sticky='ew', padx=5, pady=2)

        # Field 4: Carrier
        tk.Label(form_frame, text="Carrier", bg='white', font=('Arial', 9)).grid(row=0, column=3, sticky='w', padx=5)
        
        style = ttk.Style()
        style.map('Yellow.TCombobox', fieldbackground=[('readonly', '#FFF9C4')], background=[('readonly', '#FFF9C4')])
        
        # Get carrier labels
        carrier_values = list(CARRIER_OPTIONS.values())
        self.carrier_combo = ttk.Combobox(form_frame, style='Yellow.TCombobox', state='readonly', values=carrier_values)
        self.carrier_combo.grid(row=1, column=3, sticky='ew', padx=5, pady=2)

        # Field 5: Is Drop Ship?
        self.drop_ship_var = tk.BooleanVar()
        self.drop_ship_check = tk.Checkbutton(
            form_frame, text="Is Drop Ship?", variable=self.drop_ship_var, bg='white', font=('Arial', 9)
        )
        self.drop_ship_check.grid(row=1, column=4, sticky='w', padx=5, pady=2)

        # Field 6: Status
        tk.Label(form_frame, text="Status", bg='white', font=('Arial', 9)).grid(row=0, column=5, sticky='w', padx=5)
        self.status_display = ttk.Combobox(
            form_frame, style='Yellow.TCombobox', state='readonly',
            values=['processing', 'completed', 'partially-shipped', 'wc-order-placed', 'cancelled', 'refunded', 'failed']
        )
        self.status_display.grid(row=1, column=5, sticky='ew', padx=5, pady=2)

        # B.2) Logistics Fields
        logistics_frame = tk.Frame(self.top_frame, bg='white')
        logistics_frame.pack(fill=tk.X, padx=10, pady=5)
        logistics_frame.columnconfigure(0, weight=1)
        logistics_frame.columnconfigure(1, weight=1)
        logistics_frame.columnconfigure(2, weight=4) # Spacer
        
        # Logistics Status Note
        tk.Label(logistics_frame, text="Logistics Status Note", bg='white', font=('Arial', 9, 'bold'), fg='#d32f2f').grid(row=0, column=0, sticky='w', padx=5)
        self.logistics_status_combo = ttk.Combobox(
            logistics_frame, style='Yellow.TCombobox', state='readonly',
            values=['In Stock', 'Backordered', 'Replaced', 'Discontinued']
        )
        
        def toggle_date_entry_visibility(*_):
            val = self.logistics_status_combo.get()
            if val in ['Replaced', 'Discontinued']:
                self.logistics_date_entry.grid_remove()
            else:
                self.logistics_date_entry.grid()
        
        self.logistics_status_combo.bind('<<ComboboxSelected>>', toggle_date_entry_visibility)
        self.logistics_status_combo.grid(row=1, column=0, sticky='ew', padx=5, pady=2)
        
        # Estimated Date
        tk.Label(logistics_frame, text="Estimated Date (YYYY-MM-DD)", bg='white', font=('Arial', 9, 'bold'), fg='#d32f2f').grid(row=0, column=1, sticky='w', padx=5)
        self.logistics_date_entry = tk.Entry(logistics_frame, bg='#FFF9C4', relief=tk.SOLID, borderwidth=1)
        self.logistics_date_entry.grid(row=1, column=1, sticky='ew', padx=5, pady=2)

        # C) Action Buttons
        button_frame = tk.Frame(self.top_frame, bg='white')
        button_frame.pack(fill=tk.X, padx=10, pady=10)

        # Spacer
        tk.Frame(button_frame, bg='white').pack(side=tk.LEFT, expand=True)

        self.btn_update_order = tk.Button(
            button_frame, text="Update Order", bg='#007bff', fg='white', font=('Arial', 10, 'bold'),
            width=15, relief=tk.RAISED, command=self.on_update_order
        )
        self.btn_update_order.pack(side=tk.LEFT, padx=5)

        self.btn_update_tracking = tk.Button(
            button_frame, text="Update Tracking", bg='#6c757d', fg='white', font=('Arial', 10),
            width=15, relief=tk.RAISED, command=self.on_update_tracking
        )
        self.btn_update_tracking.pack(side=tk.LEFT, padx=5)

        self.btn_update_status = tk.Button(
            button_frame, text="Update Status", bg='#6c757d', fg='white', font=('Arial', 10),
            width=15, relief=tk.RAISED, command=self.on_update_status
        )
        self.btn_update_status.pack(side=tk.LEFT, padx=5)

        self.btn_export_aq = tk.Button(
            button_frame, text="Export AQ JSON", bg='#28a745', fg='white', font=('Arial', 10, 'bold'),
            width=15, relief=tk.RAISED, command=self.on_export_aq
        )
        self.btn_export_aq.pack(side=tk.LEFT, padx=5)

        self.btn_export_orders = tk.Button(
            button_frame, text="📤 Export Orders (XLSX)", bg='#6f42c1', fg='white', font=('Arial', 10, 'bold'),
            width=22, relief=tk.RAISED, command=self.on_export_orders_dialog
        )
        self.btn_export_orders.pack(side=tk.LEFT, padx=5)

    def setup_middle_panel(self):
        # Section header
        header_label = tk.Label(
            self.middle_frame, text="Shipment Manifest & Pricing", font=('Arial', 12, 'bold'), bg='white', anchor='w'
        )
        header_label.pack(fill=tk.X, padx=10, pady=5)

        # Scrollable container for line items
        self.manifest_canvas = tk.Canvas(self.middle_frame, bg='white', highlightthickness=0)
        scrollbar = tk.Scrollbar(self.middle_frame, orient=tk.VERTICAL, command=self.manifest_canvas.yview)
        
        self.manifest_scrollable_frame = tk.Frame(self.manifest_canvas, bg='white')
        self.manifest_scrollable_frame.bind(
            "<Configure>",
            lambda e: self.manifest_canvas.configure(scrollregion=self.manifest_canvas.bbox("all"))
        )

        self.manifest_canvas.create_window((0, 0), window=self.manifest_scrollable_frame, anchor='nw')
        self.manifest_canvas.configure(yscrollcommand=scrollbar.set)

        self.manifest_canvas.pack(side=tk.LEFT, fill=tk.BOTH, expand=True, padx=10, pady=5)
        scrollbar.pack(side=tk.RIGHT, fill=tk.Y)

    def bind_hover(self, widget):
        orig_bg = widget['bg']
        def on_enter(e):
             if widget['state'] != 'disabled': e.widget['background'] = '#0056b3' if orig_bg == '#007bff' else '#218838' if orig_bg == '#28a745' else '#e65100' if orig_bg == '#ff9800' else '#e0a800' if orig_bg == '#ffc107' else '#d32f2f' if orig_bg == '#f44336' else orig_bg
        def on_leave(e):
             if widget['state'] != 'disabled': e.widget['background'] = orig_bg
        widget.bind("<Enter>", on_enter)
        widget.bind("<Leave>", on_leave)

    def setup_bottom_panel(self):
        # Refresh button
        self.btn_refresh = tk.Button(
            self.bottom_frame, text="Refresh Orders", bg='#28a745', fg='white', font=('Arial', 10),
            command=self.manual_refresh
        )
        self.btn_refresh.pack(anchor='nw', padx=10, pady=5)

        # Quick Actions Buttons
        quick_frame = tk.Frame(self.bottom_frame, bg='white')
        quick_frame.pack(anchor='ne', side=tk.RIGHT, padx=10, pady=5)
        
        self.btn_quick_trx = tk.Button(
            quick_frame, text="⚡ Quick TRX Lookup", bg='#f44336', fg='white', font=('Arial', 10, 'bold'),
            command=lambda: self.open_quick_search_modal('TRX')
        )
        self.btn_quick_trx.pack(side=tk.RIGHT, padx=5)

        self.btn_quick_wo = tk.Button(
            quick_frame, text="⚡ Quick WO Lookup", bg='#ffc107', fg='black', font=('Arial', 10, 'bold'),
            command=lambda: self.open_quick_search_modal('WO')
        )
        self.btn_quick_wo.pack(side=tk.RIGHT, padx=5)

        self.btn_quick_po = tk.Button(
            quick_frame, text="⚡ Quick PO Lookup", bg='#ff9800', fg='white', font=('Arial', 10, 'bold'),
            command=lambda: self.open_quick_search_modal('PO')
        )
        self.btn_quick_po.pack(side=tk.RIGHT, padx=5)
        
        # Bind Global Shortcut
        if self.controller:
            self.controller.bind('<Control-q>', lambda e: self.open_quick_search_modal('PO'))
            self.controller.bind('<Control-r>', lambda e: self.manual_refresh())
            
        # Status Bar (Global Consistency)
        self.status_bar = tk.Label(self.bottom_frame, text="Ready | Hint: Ctrl+Q for Quick PO Lookup", bg='#f0f0f0', fg='#333333', font=('Arial', 9), anchor='w', relief=tk.SUNKEN, borderwidth=1)
        self.status_bar.pack(side=tk.BOTTOM, fill=tk.X, pady=(5,0))

        # Bind Button Hover
        self.bind_hover(self.btn_refresh)
        self.bind_hover(self.btn_quick_trx)
        self.bind_hover(self.btn_quick_wo)
        self.bind_hover(self.btn_quick_po)

        # Treeview container
        tree_container = tk.Frame(self.bottom_frame, bg='white')
        tree_container.pack(fill=tk.BOTH, expand=True, padx=10, pady=5)

        vsb = tk.Scrollbar(tree_container, orient=tk.VERTICAL)
        hsb = tk.Scrollbar(tree_container, orient=tk.HORIZONTAL)

        # Columns: ID, Status, WO, Store, Ship-To, Total, Date Placed, Last Modified, TRX, PO
        columns = ("id", "source_pr", "status", "wo_number", "install_store", "ship_to", "total", "date_placed", "date_modified", "trx_number", "po_number")
        display_columns = ["id", "status", "wo_number", "install_store", "ship_to", "total", "date_placed", "date_modified", "trx_number", "po_number"]
        if SHOW_PR_ORDER_LINK:
            display_columns.insert(1, "source_pr")


        self.order_tree = ttk.Treeview(
            tree_container,
            columns=columns,
            displaycolumns=display_columns,
            show='tree headings',
            yscrollcommand=vsb.set,
            xscrollcommand=hsb.set,
            selectmode='browse'
        )

        # Headings
        self.order_tree.heading("#0", text="Trk")
        self.order_tree.heading("id", text="ID")
        self.order_tree.heading("source_pr", text="Source PR #")
        self.order_tree.heading("status", text="Status")
        self.order_tree.heading("wo_number", text="WO #")
        self.order_tree.heading("install_store", text="Install Store #")
        self.order_tree.heading("ship_to", text="Ship-To")
        self.order_tree.heading("total", text="Total")
        self.order_tree.heading("date_placed", text="Date Placed")
        self.order_tree.heading("date_modified", text="│ Last Modified") # Visual separator in header
        self.order_tree.heading("trx_number", text="TRX Order #")
        self.order_tree.heading("po_number", text="PO #")

        # Config - ALL CENTERED per requirement
        self.order_tree.column("#0", width=40, anchor='center', stretch=False)
        self.order_tree.column("id", width=80, anchor='center')
        self.order_tree.column("source_pr", width=120, anchor='center')
        self.order_tree.column("status", width=120, anchor='center')
        self.order_tree.column("wo_number", width=120, anchor='center')
        self.order_tree.column("install_store", width=100, anchor='center')
        self.order_tree.column("ship_to", width=120, anchor='center')
        self.order_tree.column("total", width=100, anchor='center')
        self.order_tree.column("date_placed", width=140, anchor='center')
        self.order_tree.column("date_modified", width=140, anchor='center')
        self.order_tree.column("trx_number", width=120, anchor='center')
        self.order_tree.column("po_number", width=120, anchor='center')

        vsb.config(command=self.order_tree.yview)
        hsb.config(command=self.order_tree.xview)

        self.order_tree.grid(row=0, column=0, sticky='nsew')
        vsb.grid(row=0, column=1, sticky='ns')
        hsb.grid(row=1, column=0, sticky='ew')

        tree_container.grid_rowconfigure(0, weight=1)
        tree_container.grid_columnconfigure(0, weight=1)

        self.order_tree.bind('<<TreeviewSelect>>', self.on_order_selected)
        self.order_tree.tag_configure('selected', background='#cce5ff')

    def manual_refresh(self):
        self.btn_refresh.config(state="disabled")
        self.refresh_list_async(callback=lambda: self.btn_refresh.config(state="normal"))

    def refresh_list_async(self, callback=None):
        t = threading.Thread(target=self._fetch_orders_thread, args=(callback,), daemon=True)
        t.start()

    def _fetch_orders_thread(self, callback):
        try:
            orders = self.bridge.get_all_orders() or {}
            self.after(0, lambda: self.update_order_list_ui(orders, callback))
        except Exception as e:
            print(f"Async Fetch Error: {e}")
            if callback: self.after(0, callback)

    def update_order_list_ui(self, orders, callback=None):
        self.orders_cache = orders
        for item in self.order_tree.get_children():
            self.order_tree.delete(item)
            
        sorted_ids = sorted(self.orders_cache.keys(), key=lambda x: int(x) if x.isdigit() else 0, reverse=True)

        for oid in sorted_ids:
            order = self.orders_cache[oid]
            
            # WO Logic
            wos = set()
            for item in order.line_items:
                if item.wo_number: wos.add(str(item.wo_number))
            wo_str = ", ".join(sorted(wos)) if wos else "-"
            
            # Install Store Logic
            stores = set()
            for item in order.line_items:
                if item.install_store_id: stores.add(str(item.install_store_id))
            
            install_store_str = ""
            if len(stores) == 0: install_store_str = "-"
            elif len(stores) == 1: install_store_str = list(stores)[0]
            else: install_store_str = "Multiple"
            
            # Source PR Logic
            source_pr_str = ""
            if order.source_pr_id:
                source_pr_str = f"PR-{order.source_pr_id}"
                if wo_str and wo_str != "-":
                    source_pr_str += f" / WO-{wo_str.split(', ')[0]}"

            ship_to_str = ""
            if order.ship_to:
                if str(order.ship_to).startswith("store:"):
                    ship_to_str = "Store " + str(order.ship_to).replace("store:", "")
                elif str(order.ship_to).startswith("warehouse:"):
                    ship_to_str = "Warehouse " + str(order.ship_to).replace("warehouse:", "")
                else:
                    ship_to_str = str(order.ship_to)

            # Tracking Dot Logic
            trk_dot_img = self.dot_red
            if order.tracking_number:
                total_ordered = sum(int(getattr(item, 'quantity', 0)) for item in order.line_items)
                total_shipped = sum(int(getattr(item, 'shipped_qty', 0)) for item in order.line_items)
                if total_ordered > 0 and total_shipped >= total_ordered:
                    trk_dot_img = self.dot_green
                else:
                    trk_dot_img = self.dot_yellow

            self.order_tree.insert("", "end", iid=oid, image=trk_dot_img, text="", values=(
                oid,
                source_pr_str,
                order.status or "Unknown",
                wo_str,
                install_store_str,
                ship_to_str,
                f"${order.total}" if order.total else "",
                self.format_human_time(order.date_created),
                self.format_human_time(order.date_modified),
                order.trx_order_number or "",
                order.purchase_order_number or ""
            ))
        if callback: callback()

    def on_order_selected(self, event):
        selection = self.order_tree.selection()
        if not selection:
            print("DEBUG: No selection found")
            return
        
        oid = selection[0]
        item = self.order_tree.item(oid)
        order_id = item['values'][0]
        print(f"DEBUG: Selected order ID: {order_id}")
        
        self.load_order(oid)

        # Highlight tag logic
        for item in self.order_tree.get_children():
            self.order_tree.item(item, tags=())
        self.order_tree.item(oid, tags=('selected',))

    def load_order(self, order_id):
        self.current_order_id = order_id
        if hasattr(self, 'orders_cache') and order_id in self.orders_cache:
            order = self.orders_cache[order_id]
        else:
            order = self.bridge.get_order(order_id)
        
        self.current_order_obj = order # Keep ref
        
        # Populate Top Panel
        self.trx_entry.delete(0, tk.END)
        self.trx_entry.insert(0, order.trx_order_number or "")
        
        self.po_entry.delete(0, tk.END)
        self.po_entry.insert(0, order.purchase_order_number or "")
        
        self.tracking_entry.delete(0, tk.END)
        self.tracking_entry.insert(0, order.tracking_number or "")
        
        # Carrier
        carrier_label = CARRIER_OPTIONS.get(order.carrier_name, order.carrier_name)
        self.carrier_combo.set(carrier_label or "")
        
        # Drop Ship
        is_ds = True if order.is_dropship in [True, "yes", "1"] else False
        self.drop_ship_var.set(is_ds)
        
        # Status
        self.status_display.config(state='normal')
        self.status_display.set(order.status or "")
        self.status_display.config(state='readonly')
        
        # Logistics
        log_status_map = {
            'in_stock': 'In Stock',
            'backordered': 'Backordered',
            'replaced': 'Replaced',
            'discontinued': 'Discontinued'
        }
        self.logistics_status_combo.set(log_status_map.get(order.logistics_status, order.logistics_status or ''))
        
        self.logistics_date_entry.delete(0, tk.END)
        if order.logistics_eta:
            self.logistics_date_entry.insert(0, order.logistics_eta)
            
        # Trigger visibility update
        self.logistics_status_combo.event_generate('<<ComboboxSelected>>')
            
        # Update Banner Link
        if getattr(self, 'source_pr_label', None):
            if order.source_pr_id:
                wos = [str(item.wo_number) for item in order.line_items if item.wo_number]
                wo_str = f" / WO-{wos[0]}" if wos else ""
                self.source_pr_label.config(text=f"Source Request: PR-{order.source_pr_id}{wo_str}")
            else:
                self.source_pr_label.config(text="")
        
        # Update Banner
        info_text = f"Order #{order.id} | Status: {order.status} | Total: ${order.total} | Date: {self.format_human_time(order.date_created)}"
        self.banner_label.config(text=info_text)
        
        # Populate Middle Panel
        self.populate_line_items(order.line_items)

    def populate_line_items(self, items):
        print(f"DEBUG: populate_line_items called with {len(items)} items")
        # Clear existing
        for widget in self.manifest_scrollable_frame.winfo_children():
            widget.destroy()
        
        self.to_ship_entries = {}
        self.current_line_items = {}
        
        # Create Rows
        for idx, item in enumerate(items):
            # Safe SKU access
            sku = getattr(item, 'sku', None)
            title = f"{sku} / {item.name}" if sku else item.name
            
            item_data = {
                'id': item.id,
                'title': title,
                'qty': item.quantity,
                'wo_number': item.wo_number or "-",
                'store': item.install_store_id or "-",
                'to_ship': "", # Default empty or 0? User asked for empty placeholder behavior logic
                'unit_price': float(item.price or 0),
                'shipped_qty': getattr(item, 'shipped_qty', 0)
            }
            self.current_line_items[item.id] = item_data
            self.create_line_item_row(self.manifest_scrollable_frame, item_data, idx)
        
        print(f"DEBUG: Created {len(items)} line item rows")

    def create_line_item_row(self, parent, item_data, row_index):
        # Headers
        if row_index == 0:
            headers = ["Item / Title", "Qty", "WO #", "Store", "To Ship", "Unit Price ($)"]
            for col, header in enumerate(headers):
                tk.Label(parent, text=header, font=('Arial', 10, 'bold'), bg='#f0f0f0', relief=tk.RIDGE, borderwidth=1, padx=8, pady=5)\
                    .grid(row=0, column=col, sticky='ew')
        
        data_row = row_index + 1
        
        tk.Label(parent, text=item_data['title'], bg='white', anchor='w', relief=tk.RIDGE, borderwidth=1, padx=8, pady=5)\
            .grid(row=data_row, column=0, sticky='ew')
        
        tk.Label(parent, text=str(item_data['qty']), bg='white', anchor='center', relief=tk.RIDGE, borderwidth=1, padx=8, pady=5)\
            .grid(row=data_row, column=1, sticky='ew')
        
        tk.Label(parent, text=item_data['wo_number'], bg='white', anchor='center', relief=tk.RIDGE, borderwidth=1, padx=8, pady=5)\
            .grid(row=data_row, column=2, sticky='ew')
        
        tk.Label(parent, text=item_data['store'], bg='white', anchor='center', relief=tk.RIDGE, borderwidth=1, padx=8, pady=5)\
            .grid(row=data_row, column=3, sticky='ew')
        
        to_ship_entry = tk.Entry(parent, bg='#FFF9C4', relief=tk.SOLID, borderwidth=1, justify='center', width=10)
        
        shipped = item_data.get('shipped_qty', 0)
        remaining = max(0, int(item_data.get('qty', 0)) - int(shipped))
        if remaining <= 0:
            to_ship_entry.insert(0, str(shipped))
            to_ship_entry.config(state='disabled', disabledbackground='#e0e0e0', disabledforeground='black')

        to_ship_entry.grid(row=data_row, column=4, sticky='ew', padx=2, pady=2)
        self.to_ship_entries[item_data['id']] = to_ship_entry
        
        tk.Label(parent, text=f"${item_data['unit_price']:.2f}", bg='white', anchor='e', relief=tk.RIDGE, borderwidth=1, padx=8, pady=5)\
            .grid(row=data_row, column=5, sticky='ew')
        
        # Column weights
        for i in range(6): parent.columnconfigure(i, weight=1)
        parent.columnconfigure(0, weight=3)

    def calculate_status_progression(self, order_data):
        current_status = self.normalize_status_slug(order_data.get('status', 'pending'))
        line_items = order_data.get('line_items', [])

        def total_after_current_shipment():
            return sum(
                int(i.get('shipped_qty', 0) or 0) + int(i.get('to_ship', 0) or 0)
                for i in line_items
            )

        total_qty = sum(int(i.get('qty', 0) or 0) for i in line_items)
        
        # Stage 1
        if current_status in {'wc-order-placed', 'order-placed', 'pending'}:
            if any([order_data.get('trx_number'), order_data.get('po_number'), order_data.get('is_drop_ship')]):
                return 'processing'
        
        # Stage 2
        if current_status == 'processing':
            if order_data.get('tracking_number'):
                total_to_ship = total_after_current_shipment()
                
                if total_to_ship < total_qty: return 'partially-shipped'
                else: return 'completed'
                
        # Stage 3
        if current_status == 'partially-shipped' and order_data.get('tracking_number'):
             total_to_ship = total_after_current_shipment()
             if total_to_ship >= total_qty:
                 return 'completed'

        return current_status

    def get_line_items_data(self):
        items = []
        for item_id, entry in self.to_ship_entries.items():
            original = self.current_line_items.get(item_id, {})
            shipped_qty = int(original.get('shipped_qty', 0) or 0)
            ordered_qty = int(original.get('qty', 0) or 0)
            remaining_qty = max(0, ordered_qty - shipped_qty)

            if str(entry.cget('state')) == 'disabled':
                qty = 0
            else:
                val = entry.get().strip()
                # Empty means 0, user must explicitly type quantity to ship
                if val == "":
                    qty = 0
                elif val.isdigit():
                    qty = min(int(val), remaining_qty)
                else:
                    qty = 0

            # Create a copy/dict
            item_dict = original.copy()
            item_dict['to_ship'] = qty
            items.append(item_dict)
        return items

    def normalize_status_slug(self, status):
        if not status:
            return 'pending'

        normalized = str(status).strip().lower().replace('_', '-').replace(' ', '-')
        status_map = {
            'order-placed': 'wc-order-placed',
            'wc-order-placed': 'wc-order-placed',
            'pending': 'pending',
            'processing': 'processing',
            'partially-shipped': 'partially-shipped',
            'completed': 'completed',
            'cancelled': 'cancelled',
            'refunded': 'refunded',
            'failed': 'failed',
        }
        return status_map.get(normalized, normalized)

    def normalize_date(self, date_str):
        """
        Validates and formats the input date string to zero-padded YYYY-MM-DD.
        Raises ValueError if the date is invalid or cannot be parsed.
        """
        if not date_str:
            return ""
        from datetime import datetime
        cleaned = date_str.strip().replace('/', '-').replace('.', '-')
        # Parse and format back to ensure zero padding
        parsed = datetime.strptime(cleaned, '%Y-%m-%d')
        return parsed.strftime('%Y-%m-%d')

    def _get_ui_data(self):
        # Reverse map logistics status
        log_status_label = self.logistics_status_combo.get()
        reverse_log_map = {
            'In Stock': 'in_stock',
            'Backordered': 'backordered',
            'Replaced': 'replaced',
            'Discontinued': 'discontinued'
        }
        log_status_val = reverse_log_map.get(log_status_label, log_status_label)

        return {
            'id': self.current_order_id,
            'trx_number': self.trx_entry.get().strip(),
            'po_number': self.po_entry.get().strip(),
            'tracking_number': self.tracking_entry.get().strip(),
            'carrier': self.carrier_combo.get(),
            'is_drop_ship': self.drop_ship_var.get(),
            'status': self.status_display.get(),
            'logistics_status': log_status_val,
            'logistics_eta': self.logistics_date_entry.get().strip(),
            'replacement_part': getattr(self, '_replacement_part_temp', ''),
            'line_items': self.get_line_items_data()
        }

    def on_update_order(self):
        """
        Update Order gate: requires TRX#. If dropship, also requires PO#.
        If logistics fields are empty, intercept with the ETA gate modal before saving.
        """
        if not self.current_order_id:
            messagebox.showwarning("Warning", "Please select an order first.")
            return
        try:
            data = self._get_ui_data()

            # --- Primary field validation ---
            if not data['trx_number']:
                messagebox.showwarning("Missing TRX #", "Please enter a TRX Order # before updating the order.")
                self.trx_entry.focus_set()
                return
            if data['is_drop_ship'] and not data['po_number']:
                messagebox.showwarning("Missing PO #", "This order is marked as Drop Ship — a Purchase Order # is required.")
                self.po_entry.focus_set()
                return

            # --- Logistics gate: if ETA or status not filled, intercept ---
            # Replaced/Discontinued don't require ETA date
            date_required = data['logistics_status'] not in ['replaced', 'discontinued']
            
            if not data['logistics_status'] or (date_required and not data['logistics_eta']):
                self._show_logistics_gate_modal(
                    trigger='update_order',
                    on_confirm=lambda log_data: self._complete_update_order(data, log_data)
                )
                return

            if date_required and data['logistics_eta']:
                try:
                    data['logistics_eta'] = self.normalize_date(data['logistics_eta'])
                    self.logistics_date_entry.delete(0, tk.END)
                    self.logistics_date_entry.insert(0, data['logistics_eta'])
                except ValueError:
                    messagebox.showwarning("Invalid Date Format", "Estimated Date must be in YYYY-MM-DD format.")
                    self.logistics_date_entry.focus_set()
                    return

            self._complete_update_order(data, {
                'logistics_status': data['logistics_status'],
                'logistics_eta': data['logistics_eta'],
                'replacement_part': data.get('replacement_part', '')
            })
        except Exception as e:
            traceback.print_exc()
            messagebox.showerror("Error", f"Failed to update: {str(e)}")

    def _complete_update_order(self, data, log_data):
        """Finish Update Order after logistics gate is satisfied."""
        try:
            data['logistics_status'] = log_data['logistics_status']
            data['logistics_eta']   = log_data['logistics_eta']
            if log_data.get('replacement_part'):
                data['replacement_part'] = log_data['replacement_part']

            # Write back to UI fields so they're in sync
            self._sync_logistics_fields_to_ui(log_data)

            new_status = self.calculate_status_progression(data)
            data['status'] = new_status

            self.save_order_to_db(data)
            self.update_status_display(new_status)
            self.manual_refresh()
            messagebox.showinfo("✅ Order Updated", "Order updated successfully!")
        except Exception as e:
            traceback.print_exc()
            messagebox.showerror("Error", f"Failed to update: {str(e)}")

    def on_update_tracking(self):
        """
        Update Tracking gate: requires Tracking # and Carrier.
        Also nudges logistics if those fields are empty.
        """
        if not self.current_order_id:
            messagebox.showwarning("Warning", "Please select an order first.")
            return
        try:
            data = self._get_ui_data()

            if not data['tracking_number']:
                messagebox.showwarning("Missing Tracking #", "Please enter a Tracking # before updating tracking.")
                self.tracking_entry.focus_set()
                return
            if not data['carrier']:
                messagebox.showwarning("Missing Carrier", "Please select a Carrier before updating tracking.")
                self.carrier_combo.focus_set()
                return

            # Soft nudge: if logistics not filled, gate it
            if not data['logistics_status'] or not data['logistics_eta']:
                self._show_logistics_gate_modal(
                    trigger='update_tracking',
                    on_confirm=lambda log_data: self._complete_update_tracking(data, log_data)
                )
                return

            # Validate date if bypass
            date_required = data['logistics_status'] not in ['replaced', 'discontinued']
            if date_required and data['logistics_eta']:
                try:
                    data['logistics_eta'] = self.normalize_date(data['logistics_eta'])
                    self.logistics_date_entry.delete(0, tk.END)
                    self.logistics_date_entry.insert(0, data['logistics_eta'])
                except ValueError:
                    messagebox.showwarning("Invalid Date Format", "Estimated Date must be in YYYY-MM-DD format.")
                    self.logistics_date_entry.focus_set()
                    return

            self._complete_update_tracking(data, {
                'logistics_status': data['logistics_status'],
                'logistics_eta': data['logistics_eta'],
                'replacement_part': data.get('replacement_part', '')
            })
        except Exception as e:
            messagebox.showerror("Error", f"Failed: {str(e)}")

    def _complete_update_tracking(self, data, log_data):
        """Finish Update Tracking after logistics gate is satisfied."""
        try:
            data['logistics_status'] = log_data['logistics_status']
            data['logistics_eta']   = log_data['logistics_eta']
            if log_data.get('replacement_part'):
                data['replacement_part'] = log_data['replacement_part']

            self._sync_logistics_fields_to_ui(log_data)

            new_status = self.calculate_status_progression(data)
            data['status'] = new_status

            self.save_order_to_db(data)
            self.update_status_display(new_status)
            self.manual_refresh()
            messagebox.showinfo("✅ Tracking Updated", f"Tracking updated! Status → {new_status}")
        except Exception as e:
            messagebox.showerror("Error", f"Failed: {str(e)}")

    def on_update_status(self):
        """
        Update Status: dedicated logistics save.
        Requires Logistics Status Note + Estimated Date, then pushes to WC.
        This is the "set ETA / part status" button — it saves, not just previews.
        """
        if not self.current_order_id:
            messagebox.showwarning("Warning", "Please select an order first.")
            return
        try:
            data = self._get_ui_data()

            if not data['logistics_status'] or not data['logistics_eta']:
                self._show_logistics_gate_modal(
                    trigger='update_status',
                    on_confirm=lambda log_data: self._complete_update_status(data, log_data)
                )
                return

            # Validate date if bypass
            date_required = data['logistics_status'] not in ['replaced', 'discontinued']
            if date_required and data['logistics_eta']:
                try:
                    data['logistics_eta'] = self.normalize_date(data['logistics_eta'])
                    self.logistics_date_entry.delete(0, tk.END)
                    self.logistics_date_entry.insert(0, data['logistics_eta'])
                except ValueError:
                    messagebox.showwarning("Invalid Date Format", "Estimated Date must be in YYYY-MM-DD format.")
                    self.logistics_date_entry.focus_set()
                    return

            self._complete_update_status(data, {
                'logistics_status': data['logistics_status'],
                'logistics_eta': data['logistics_eta'],
                'replacement_part': data.get('replacement_part', '')
            })
        except Exception as e:
            messagebox.showerror("Error", f"Failed: {str(e)}")

    def _complete_update_status(self, data, log_data):
        """Push logistics-only update to WC. Site handles notification triggers."""
        try:
            data['logistics_status'] = log_data['logistics_status']
            data['logistics_eta']   = log_data['logistics_eta']
            if log_data.get('replacement_part'):
                data['replacement_part'] = log_data['replacement_part']

            self._sync_logistics_fields_to_ui(log_data)

            # Build a minimal meta-only payload — no status change, no shipment lines
            meta_data = [
                {"key": "_muse_logistics_status", "value": data['logistics_status']},
                {"key": "_muse_logistics_date",   "value": data['logistics_eta']},
            ]
            if data.get('replacement_part'):
                meta_data.append({"key": "_muse_logistics_replacement_part", "value": data['replacement_part']})

            self.bridge.client.update_order(data['id'], {"meta_data": meta_data})

            status_label = {
                'in_stock': 'In Stock', 'backordered': 'Backordered',
                'replaced': 'Replaced', 'discontinued': 'Discontinued'
            }.get(data['logistics_status'], data['logistics_status'])

            messagebox.showinfo(
                "✅ Status Updated",
                f"Logistics status saved:\n\nStatus: {status_label}\nETA: {data['logistics_eta']}"
                + (f"\nReplacement Part: {data['replacement_part']}" if data.get('replacement_part') else "")
            )
        except Exception as e:
            messagebox.showerror("Error", f"Failed to update status: {str(e)}")

    def _sync_logistics_fields_to_ui(self, log_data):
        """Silently syncs main UI logic fields to what the modal produced"""
        status_label = {
            'in_stock': 'In Stock', 'backordered': 'Backordered',
            'replaced': 'Replaced', 'discontinued': 'Discontinued'
        }.get(log_data['logistics_status'], log_data['logistics_status'])
        
        self.logistics_status_combo.set(status_label)
        self.logistics_date_entry.delete(0, tk.END)
        self.logistics_date_entry.insert(0, log_data['logistics_eta'])
        # Stash replacement part so _get_ui_data can pick it up
        self._replacement_part_temp = log_data.get('replacement_part', '')

    def _show_logistics_gate_modal(self, trigger, on_confirm):
        """
        Intercept modal: forces entry of Logistics Status + ETA before any save proceeds.
        If status == 'replaced', also shows a replacement part number field.
        on_confirm(log_data) is called with the collected values when the user confirms.
        """
        if hasattr(self, '_gate_modal') and self._gate_modal.winfo_exists():
            self._gate_modal.destroy()

        modal = tk.Toplevel(self)
        self._gate_modal = modal
        modal.title("📋 Logistics Info Required")
        modal.geometry("400x320")
        modal.resizable(False, False)
        modal.transient(self)
        modal.grab_set()
        self.center_window(modal)

        trigger_labels = {
            'update_order':    'Update Order',
            'update_tracking': 'Update Tracking',
            'update_status':   'Update Status',
        }
        trigger_label = trigger_labels.get(trigger, trigger)

        # Header
        hdr = tk.Frame(modal, bg='#1a237e', pady=10)
        hdr.pack(fill=tk.X)
        tk.Label(hdr, text="⚠️  Logistics Info Required",
                 font=('Arial', 12, 'bold'), bg='#1a237e', fg='white').pack()
        tk.Label(hdr, text=f"Fill in before '{trigger_label}' can proceed.",
                 font=('Arial', 9), bg='#1a237e', fg='#b3c5ff').pack()

        body = tk.Frame(modal, bg='white', padx=20, pady=15)
        body.pack(fill=tk.BOTH, expand=True)
        body.columnconfigure(0, weight=1)
        body.columnconfigure(1, weight=1)

        # Pre-populate from existing UI values
        existing_status = self.logistics_status_combo.get()
        existing_date   = self.logistics_date_entry.get().strip()

        # --- Logistics Status dropdown ---
        tk.Label(body, text="Order Status Note *", font=('Arial', 9, 'bold'),
                 bg='white', fg='#d32f2f').grid(row=0, column=0, sticky='w', pady=(0, 2))
        gate_status_var = tk.StringVar(value=existing_status or 'In Stock')
        gate_status_combo = ttk.Combobox(
            body, textvariable=gate_status_var,
            values=['In Stock', 'Backordered', 'Replaced', 'Discontinued'],
            state='readonly', width=20
        )
        gate_status_combo.grid(row=1, column=0, sticky='ew', padx=(0, 10), pady=(0, 12))

        # --- ETA date entry ---
        date_label = tk.Label(body, text="Estimated Date (YYYY-MM-DD) *", font=('Arial', 9, 'bold'),
                 bg='white', fg='#d32f2f')
        date_label.grid(row=0, column=1, sticky='w', pady=(0, 2))
        gate_date_var = tk.StringVar(value=existing_date)
        gate_date_entry = tk.Entry(body, textvariable=gate_date_var,
                                   bg='#FFF9C4', relief=tk.SOLID, borderwidth=1)
        gate_date_entry.grid(row=1, column=1, sticky='ew', pady=(0, 12))

        # --- Replacement Part (conditionally shown) ---
        replacement_frame = tk.Frame(body, bg='white')
        replacement_frame.grid(row=2, column=0, columnspan=2, sticky='ew')
        replacement_frame.columnconfigure(0, weight=1)

        tk.Label(replacement_frame, text="Replacement Part # (required for 'Replaced')",
                 font=('Arial', 9, 'bold'), bg='white', fg='#d32f2f').grid(row=0, column=0, sticky='w', pady=(0, 2))
        gate_replacement_var = tk.StringVar()
        gate_replacement_entry = tk.Entry(replacement_frame, textvariable=gate_replacement_var,
                                          bg='#FFF9C4', relief=tk.SOLID, borderwidth=1)
        gate_replacement_entry.grid(row=1, column=0, sticky='ew')

        def toggle_replacement_visibility(*_):
            val = gate_status_var.get()
            if val == 'Replaced':
                replacement_frame.grid()
                date_label.grid_remove()
                gate_date_entry.grid_remove()
                modal.geometry("400x370")
            elif val == 'Discontinued':
                replacement_frame.grid_remove()
                date_label.grid_remove()
                gate_date_entry.grid_remove()
                modal.geometry("400x320")
            else:
                replacement_frame.grid_remove()
                date_label.grid()
                gate_date_entry.grid()
                modal.geometry("400x320")

        gate_status_combo.bind('<<ComboboxSelected>>', toggle_replacement_visibility)
        # Set initial visibility
        toggle_replacement_visibility()

        # --- Error label ---
        error_var = tk.StringVar()
        error_label = tk.Label(body, textvariable=error_var, fg='#c62828',
                               font=('Arial', 9), bg='white')
        error_label.grid(row=3, column=0, columnspan=2, sticky='w', pady=(8, 0))

        # --- Buttons ---
        btn_frame = tk.Frame(modal, bg='#f5f5f5', pady=10)
        btn_frame.pack(fill=tk.X, side=tk.BOTTOM)

        def on_confirm_click():
            val = gate_status_var.get()
            reverse_log_map = {
                'In Stock': 'in_stock',
                'Backordered': 'backordered',
                'Replaced': 'replaced',
                'Discontinued': 'discontinued'
            }
            db_status = reverse_log_map.get(val, val)
            
            eta = gate_date_var.get().strip()

            if not db_status:
                error_var.set("Status is required.")
                return

            if db_status not in ['replaced', 'discontinued']:
                if not eta:
                    error_var.set("Estimated Date is required for this status.")
                    return
                try:
                    eta = self.normalize_date(eta)
                except ValueError:
                    error_var.set("Invalid date format. Use YYYY-MM-DD.")
                    return

            if db_status == 'replaced' and not gate_replacement_var.get().strip():
                error_var.set("Replacement Part is required.")
                return

            modal.destroy()
            if on_confirm:
                on_confirm({
                    'logistics_status': db_status,
                    'logistics_eta': eta,
                    'replacement_part': gate_replacement_var.get().strip() if db_status == 'replaced' else ''
                })



        tk.Button(
            btn_frame, text="✔  Confirm & Continue", bg='#1a237e', fg='white',
            font=('Arial', 10, 'bold'), relief=tk.RAISED, padx=10,
            command=on_confirm_click
        ).pack(side=tk.RIGHT, padx=15)

        tk.Button(
            btn_frame, text="Cancel", bg='#e0e0e0', fg='#333',
            font=('Arial', 10), relief=tk.RAISED, padx=10,
            command=modal.destroy
        ).pack(side=tk.RIGHT, padx=5)

        # Focus date if status already set, else status
        if existing_status:
            gate_date_entry.focus_set()
        else:
            gate_status_combo.focus_set()

    def on_export_aq(self):
        if not self.current_order_id:
            messagebox.showwarning("Warning", "Please select an order from the list first.")
            return
            
        try:
            from datetime import datetime
            file_path = filedialog.asksaveasfilename(
                defaultextension=".json",
                initialfile=f"AutoQuotes_Export_{self.current_order_id}_{datetime.now().strftime('%Y%m%d')}.json",
                filetypes=[("JSON files", "*.json"), ("All files", "*.*")]
            )
            
            if not file_path:
                return

            def task():
                try:
                    data = self.bridge.export_aq_json_data(self.current_order_id)
                    if not data:
                        self.after(0, lambda: messagebox.showinfo("No Data", "No order data found or could not export."))
                        return

                    with open(file_path, 'w', encoding='utf-8') as f:
                        json.dump(data, f, indent=4)
                        
                    self.after(0, lambda: messagebox.showinfo("Success", f"Successfully exported AQ JSON to\n{file_path}"))
                except Exception as e:
                    self.after(0, lambda: messagebox.showerror("Export Error", f"An error occurred during export:\n{str(e)}"))

            threading.Thread(target=task, daemon=True).start()
        except Exception as e:
            messagebox.showerror("Error", f"Failed to start export: {str(e)}")

    def update_status_display(self, status):
        self.status_display.config(state='normal')
        self.status_display.set(self.normalize_status_slug(status))
        self.status_display.config(state='readonly')

    def save_order_to_db(self, data):
        # Map Carrier Label back to Code
        carrier_label = data['carrier']
        carrier_code = None
        for code, lbl in CARRIER_OPTIONS.items():
            if lbl == carrier_label:
                carrier_code = code
                break
        if not carrier_code: carrier_code = carrier_label # Fallback

        meta_data = []
        if data['trx_number']:
            meta_data.append({"key": "_muse_wo_global", "value": data['trx_number']})
            meta_data.append({"key": "_trx_order_number", "value": data['trx_number']})
        meta_data.append({"key": "_is_drop_ship", "value": "yes" if data['is_drop_ship'] else "no"})
        meta_data.append({"key": "_is_dropship", "value": "yes" if data['is_drop_ship'] else "no"})
        if data['po_number']: meta_data.append({"key": "_purchase_order_number", "value": data['po_number']})
        if data['tracking_number']: meta_data.append({"key": "_muse_ship_track", "value": data['tracking_number']})
        if carrier_code: meta_data.append({"key": "_muse_carrier", "value": carrier_code})
        if data.get('logistics_status'): meta_data.append({"key": "_muse_logistics_status", "value": data['logistics_status']})
        if data.get('logistics_eta'): meta_data.append({"key": "_muse_logistics_date", "value": data['logistics_eta']})
        if data.get('replacement_part'): meta_data.append({"key": "_muse_logistics_replacement_part", "value": data['replacement_part']})

        update_payload = {
            "status": self.normalize_status_slug(data['status']),
            "meta_data": meta_data
        }
        
        # Check for shipment creation
        lines_to_ship = []
        for item in data['line_items']:
            qty_ship = item.get('to_ship', 0)
            if qty_ship > 0:
                lines_to_ship.append({"line_item_id": item['id'], "ship_qty": qty_ship})
        
        close_it_out = False
        if lines_to_ship:
            close_it_out = (
                self.normalize_status_slug(data['status']) == 'completed'
                and sum(item.get('to_ship', 0) for item in data['line_items']) > 0
                and sum(int(item.get('shipped_qty', 0) or 0) + int(item.get('to_ship', 0) or 0) for item in data['line_items'])
                    >= sum(int(item.get('qty', 0) or 0) for item in data['line_items'])
            )
            
            # CRITICAL RACE CONDITION FIX:
            # If we let update_order set the status to 'completed', WooCommerce will fire the "Order Completed"
            # email immediately BEFORE ship_lines has a chance to attach the tracking information to _muse_shipments.
            # To fix this, we remove the status from the update_payload so the order stays 'processing',
            # and we let the subsequent ship_lines API call change the status to 'completed'.
            if close_it_out and "status" in update_payload:
                update_payload.pop("status")

        # API Update
        self.bridge.client.update_order(data['id'], update_payload)

        if lines_to_ship:
             ship_payload = {
                "carrier": carrier_code,
                "tracking_numbers": [data['tracking_number']] if data['tracking_number'] else [],
                "notify": True,
                "lines": lines_to_ship,
                "closeout": close_it_out
            }
             self.bridge.ship_lines(data['id'], ship_payload)

    def format_human_time(self, date_string):
        if not date_string: return ""
        try:
            from datetime import datetime
            # Helper to strip 'Z' and handles standard ISO
            date_string = date_string.replace('Z', '+00:00')
            dt_obj = datetime.fromisoformat(date_string)
            
            # MM/DD/YY - HH:MMam/pm
            date_part = dt_obj.strftime("%m/%d/%y")
            time_part = dt_obj.strftime("%I:%M%p").lower()
            return f"{date_part} - {time_part}"
        except: return date_string

    # --- Quick Actions Modal ---
    def open_quick_search_modal(self, search_type):
        self.current_search_type = search_type
        if hasattr(self, 'quick_modal') and self.quick_modal.winfo_exists():
            self.quick_modal.destroy()
            
        self.quick_modal = tk.Toplevel(self)
        self.quick_modal.title(f"⚡ Quick Actions - {search_type} Lookup")
        self.quick_modal.geometry("450x650")
        self.quick_modal.resizable(False, False)
        self.quick_modal.transient(self)
        self.quick_modal.grab_set()
        self.center_window(self.quick_modal)
        
        # Section 1: Search
        header_label = tk.Label(self.quick_modal, text=f"🔍 Search by {search_type} Number", font=('Arial', 12, 'bold'), bg='#f0f0f0', pady=10)
        header_label.pack(fill=tk.X)
        
        search_frame = tk.Frame(self.quick_modal, bg='white', pady=20)
        search_frame.pack(fill=tk.X, padx=20)
        
        tk.Label(search_frame, text=f"{search_type} #:", font=('Arial', 10), bg='white').pack(anchor='w')
        self.quick_search_entry = tk.Entry(search_frame, bg='#FFF9C4', font=('Arial', 11), relief=tk.SOLID, borderwidth=1)
        self.quick_search_entry.pack(fill=tk.X, pady=5)
        
        self.quick_search_entry.bind('<Return>', lambda e: self.on_quick_search())
        
        btn_search = tk.Button(search_frame, text="🔍 Search Order", bg='#2196F3', fg='white', font=('Arial', 10, 'bold'), command=self.on_quick_search)
        btn_search.pack(pady=10)
        btn_search.bind("<Enter>", lambda e, b=btn_search, c='#42A5F5': b.config(bg=c))
        btn_search.bind("<Leave>", lambda e, b=btn_search, c='#2196F3': b.config(bg=c))
        
        # Section 2: Preview (Hidden)
        self.quick_preview_frame = tk.Frame(self.quick_modal, bg='#e3f2fd', relief=tk.RIDGE, borderwidth=2)
        self.quick_preview_label = tk.Label(self.quick_preview_frame, text="", font=('Arial', 10), bg='#e3f2fd', justify='left', padx=15, pady=15)
        self.quick_preview_label.pack()
        
        # Section 3: Tracking (Hidden)
        self.quick_tracking_frame = tk.Frame(self.quick_modal, bg='white', pady=20)
        
        tk.Label(self.quick_tracking_frame, text="Add Tracking Information:", font=('Arial', 11, 'bold'), bg='white').pack(anchor='w', padx=20)
        
        track_con = tk.Frame(self.quick_tracking_frame, bg='white')
        track_con.pack(fill=tk.X, padx=20, pady=10)
        
        tk.Label(track_con, text="Tracking Number:", font=('Arial', 10), bg='white').pack(anchor='w')
        self.quick_tracking_entry = tk.Entry(track_con, bg='#FFF9C4', font=('Arial', 11), relief=tk.SOLID, borderwidth=1)
        self.quick_tracking_entry.pack(fill=tk.X, pady=5)
        
        carrier_con = tk.Frame(self.quick_tracking_frame, bg='white')
        carrier_con.pack(fill=tk.X, padx=20, pady=10)
        
        tk.Label(carrier_con, text="Carrier:", font=('Arial', 10), bg='white').pack(anchor='w')
        style = ttk.Style()
        style.map('Yellow.TCombobox', fieldbackground=[('readonly', '#FFF9C4')], background=[('readonly', '#FFF9C4')])
        
        self.quick_carrier_combo = ttk.Combobox(carrier_con, style='Yellow.TCombobox', state='readonly', values=list(CARRIER_OPTIONS.values()), font=('Arial', 10))
        self.quick_carrier_combo.set('UPS')
        self.quick_carrier_combo.pack(fill=tk.X, pady=5)
        
        # Section 4: Actions
        btn_frame = tk.Frame(self.quick_modal, bg='white', pady=20)
        btn_frame.pack(fill=tk.X, padx=20)
        
        self.quick_submit_btn = tk.Button(btn_frame, text="✅ Add Tracking", bg='#4CAF50', fg='white', font=('Arial', 11, 'bold'), width=15, command=self.on_quick_add_tracking)
        self.quick_submit_btn.bind("<Enter>", lambda e, b=self.quick_submit_btn, c='#66BB6A': b.config(bg=c))
        self.quick_submit_btn.bind("<Leave>", lambda e, b=self.quick_submit_btn, c='#4CAF50': b.config(bg=c))
        # Pack later
        
        btn_clear = tk.Button(btn_frame, text="🔄 Clear Form", bg='#9E9E9E', fg='white', font=('Arial', 10), width=12, command=self.clear_quick_form)
        btn_clear.pack(side=tk.LEFT, padx=5)
        btn_clear.bind("<Enter>", lambda e, b=btn_clear, c='#BDBDBD': b.config(bg=c))
        btn_clear.bind("<Leave>", lambda e, b=btn_clear, c='#9E9E9E': b.config(bg=c))
        
        btn_close = tk.Button(btn_frame, text="✖ Close", bg='#f44336', fg='white', font=('Arial', 10), width=12, command=self.quick_modal.destroy)
        btn_close.pack(side=tk.RIGHT, padx=5)
        btn_close.bind("<Enter>", lambda e, b=btn_close, c='#EF5350': b.config(bg=c))
        btn_close.bind("<Leave>", lambda e, b=btn_close, c='#f44336': b.config(bg=c))
        
        self.quick_modal.bind('<Control-Return>', lambda e: self.on_quick_add_tracking())
        self.quick_modal.bind('<Escape>', lambda e: self.quick_modal.destroy())
        self.quick_search_entry.focus_set()

    def on_quick_search(self):
        term = self.quick_search_entry.get().strip()
        if not term:
            messagebox.showwarning(f"Missing {self.current_search_type}", f"Please enter a {self.current_search_type} number", parent=self.quick_modal)
            return

        # Search in cache
        if not hasattr(self, 'orders_cache'):
            messagebox.showwarning("Data Error", "Order cache not loaded.", parent=self.quick_modal)
            return

        matches = []
        for oid, order in self.orders_cache.items():
            if self.current_search_type == "PO":
                if order.purchase_order_number and order.purchase_order_number.strip().lower() == term.lower():
                    matches.append(order)
            elif self.current_search_type == "TRX":
                if order.trx_order_number and order.trx_order_number.strip().lower() == term.lower():
                    matches.append(order)
            elif self.current_search_type == "WO":
                for item in order.line_items:
                    if item.wo_number and str(item.wo_number).strip().lower() == term.lower():
                        matches.append(order)
                        break
        
        if not matches:
             self.hide_quick_preview()
             messagebox.showerror("Not Found", f"No order found with {self.current_search_type} #{term}", parent=self.quick_modal)
             return
        
        if len(matches) > 1:
             messagebox.showwarning("Multiple Orders", f"Found {len(matches)} orders with {self.current_search_type} #{term}.\nPlease use main list.", parent=self.quick_modal)
             return
        
        self.current_quick_order = matches[0]
        self.display_quick_order_preview(self.current_quick_order)
        self.quick_tracking_entry.focus_set()

    def display_quick_order_preview(self, order):
        wo = order.line_items[0].wo_number if order.line_items else 'N/A'
        txt = (f"📦 Order Found!\n\nOrder ID: {order.id}\nStatus: {order.status}\nWO #: {wo}\n"
               f"Total: ${order.total}\nDate: {self.format_human_time(order.date_created)}")
        self.quick_preview_label.config(text=txt)
        
        self.quick_preview_frame.pack(fill=tk.X, padx=20, pady=10)
        self.quick_tracking_frame.pack(fill=tk.X)
        self.quick_submit_btn.pack(side=tk.LEFT, padx=5)

    def hide_quick_preview(self):
        self.quick_preview_frame.pack_forget()
        self.quick_tracking_frame.pack_forget()
        self.quick_submit_btn.pack_forget()
        self.current_quick_order = None

    def on_quick_add_tracking(self):
        if not hasattr(self, 'current_quick_order') or not self.current_quick_order:
             messagebox.showwarning("No Order", "Search first", parent=self.quick_modal)
             return
        
        track = self.quick_tracking_entry.get().strip()
        carrier = self.quick_carrier_combo.get()
        
        if not track:
            messagebox.showwarning("Missing Tracking", "Enter tracking number", parent=self.quick_modal)
            return

        try:
            # Construct Dictionary for save_order_to_db
            # We need to replicate what _get_ui_data does but using the order object
            # CAUTION: We must carry forward existing meta (trx, etc)
            
            order = self.current_quick_order
            
            # Line items need 'to_ship' key logic.
            # Spec: "Quick Actions... without searching". 
            # Implies we ship ALL or calculate status same as main UI?
            # User Story: "paste PO, paste tracking, and be done"
            # Logic: If we are adding tracking, we probably want to mark as shipped/partial.
            # Existing logic `calculate_status_progression` uses `total_to_ship`.
            # If we don't supply `to_ship`, logic might fail or set to 0.
            # LET'S ASSUME FULL SHIPMENT for Quick Actions for simplicity unless specified?
            # "Triggers status progression (Processing -> Partially Shipped/Completed)"
            # Let's map all Qty to To Ship for this quick action to ensure "Completed" status if qty matches.
            
            line_items_data = []
            for item in order.line_items:
                line_items_data.append({
                    'id': item.id,
                    'qty': item.quantity,
                    'shipped_qty': getattr(item, 'shipped_qty', 0),
                    'to_ship': max(0, int(item.quantity) - int(getattr(item, 'shipped_qty', 0) or 0))
                })

            data = {
                'id': order.id,
                'trx_number': order.trx_order_number,
                'po_number': order.purchase_order_number,
                'tracking_number': track, # NEW
                'carrier': carrier, # NEW
                'is_drop_ship': order.is_dropship,
                'status': order.status,
                'line_items': line_items_data
            }
            
            new_status = self.calculate_status_progression(data)
            data['status'] = new_status
            
            self.save_order_to_db(data)
            
            self.manual_refresh()
            messagebox.showinfo("✅ Success!", f"Tracking added to Order #{order.id}\nStatus: {new_status}", parent=self.quick_modal)
            self.clear_quick_form()
            
        except Exception as e:
            traceback.print_exc()
            messagebox.showerror("Error", f"Failed: {str(e)}", parent=self.quick_modal)

    def clear_quick_form(self):
        self.quick_search_entry.delete(0, tk.END)
        self.quick_tracking_entry.delete(0, tk.END)
        self.quick_carrier_combo.set('UPS')
        self.hide_quick_preview()
        self.quick_search_entry.focus_set()

    def center_window(self, window):
        window.update_idletasks()
        w = window.winfo_width()
        h = window.winfo_height()
        px = self.winfo_toplevel().winfo_x()
        py = self.winfo_toplevel().winfo_y()
        pw = self.winfo_toplevel().winfo_width()
        ph = self.winfo_toplevel().winfo_height()
        x = px + (pw // 2) - (w // 2)
        y = py + (ph // 2) - (h // 2)
        window.geometry(f"+{x}+{y}")


    # ---------------------------------------------------------------------------
    # ORDER EXPORT (XLSX)
    # Exports the in-memory orders_cache to a formatted Excel workbook.
    # Columns align with the Wegmans/TRX report format for Cairn import.
    # ---------------------------------------------------------------------------

    def on_export_orders_dialog(self):
        """Open a small modal to configure the export date range, then run."""
        if not hasattr(self, 'orders_cache') or not self.orders_cache:
            messagebox.showwarning("No Data", "No orders loaded. Please refresh first.")
            return

        dialog = tk.Toplevel(self)
        dialog.title("Export Orders to XLSX")
        dialog.geometry("420x260")
        dialog.resizable(False, False)
        dialog.transient(self)
        dialog.grab_set()
        self.center_window(dialog)

        tk.Label(dialog, text="Export Orders to Excel", font=('Arial', 12, 'bold'), pady=10).pack()

        form = tk.Frame(dialog)
        form.pack(padx=20, pady=5, fill=tk.X)

        # Date From
        tk.Label(form, text="Date From (YYYY-MM-DD, blank = all):").grid(row=0, column=0, sticky='w', pady=4)
        date_from_var = tk.StringVar()
        tk.Entry(form, textvariable=date_from_var, bg='#FFF9C4', width=18,
                 relief=tk.SOLID, borderwidth=1).grid(row=0, column=1, sticky='ew', padx=5)

        # Date To
        tk.Label(form, text="Date To   (YYYY-MM-DD, blank = all):").grid(row=1, column=0, sticky='w', pady=4)
        date_to_var = tk.StringVar()
        tk.Entry(form, textvariable=date_to_var, bg='#FFF9C4', width=18,
                 relief=tk.SOLID, borderwidth=1).grid(row=1, column=1, sticky='ew', padx=5)

        # Status filter
        tk.Label(form, text="Status filter (blank = all):").grid(row=2, column=0, sticky='w', pady=4)
        status_var = tk.StringVar()
        status_combo = ttk.Combobox(form, textvariable=status_var, state='readonly', width=17,
                                    values=['', 'processing', 'completed', 'partially-shipped',
                                            'wc-order-placed', 'cancelled'])
        status_combo.grid(row=2, column=1, sticky='ew', padx=5)

        form.columnconfigure(1, weight=1)

        btn_frame = tk.Frame(dialog)
        btn_frame.pack(pady=15)

        def run_export():
            df = date_from_var.get().strip()
            dt = date_to_var.get().strip()
            st = status_var.get().strip()
            dialog.destroy()
            self._run_order_export(df or None, dt or None, st or None)

        tk.Button(btn_frame, text="Choose Save Path & Export",
                  bg='#6f42c1', fg='white', font=('Arial', 10, 'bold'),
                  command=run_export).pack(side=tk.LEFT, padx=8)
        tk.Button(btn_frame, text="Cancel",
                  bg='#6c757d', fg='white',
                  command=dialog.destroy).pack(side=tk.LEFT, padx=8)


    def _run_order_export(self, date_from=None, date_to=None, status_filter=None):
        """Filter the cache and write to XLSX. Runs on main thread (data is local)."""
        from datetime import datetime, date
        try:
            import openpyxl
            from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
        except ImportError:
            messagebox.showerror("Missing Library",
                "openpyxl is required for XLSX export.\nRun: pip install openpyxl")
            return

        # ---- Filter orders -----------------------------------------------
        def parse_date(s):
            if not s:
                return None
            try:
                s = s.replace('Z', '+00:00')
                return datetime.fromisoformat(s).date()
            except Exception:
                return None

        df_date = datetime.strptime(date_from, '%Y-%m-%d').date() if date_from else None
        dt_date = datetime.strptime(date_to,   '%Y-%m-%d').date() if date_to   else None

        orders_to_export = []
        for oid, order in self.orders_cache.items():
            # Status filter
            if status_filter and order.status != status_filter:
                continue
            # Date filter
            created = parse_date(order.date_created)
            if df_date and created and created < df_date:
                continue
            if dt_date and created and created > dt_date:
                continue
            orders_to_export.append(order)

        if not orders_to_export:
            messagebox.showinfo("No Results", "No orders matched the filter criteria.")
            return

        # Sort descending by ID
        orders_to_export.sort(key=lambda o: o.id, reverse=True)

        # ---- Choose save path --------------------------------------------
        default_name = f"UACP_Orders_Export_{datetime.now().strftime('%Y%m%d_%H%M')}.xlsx"
        file_path = filedialog.asksaveasfilename(
            defaultextension=".xlsx",
            initialfile=default_name,
            filetypes=[("Excel files", "*.xlsx"), ("All files", "*.*")]
        )
        if not file_path:
            return

        # ---- Build workbook ----------------------------------------------
        wb = openpyxl.Workbook()
        ws = wb.active
        ws.title = "Orders"

        HEADERS = [
            "Order ID", "Status", "WO #", "Install Store #",
            "Total", "Date Placed", "Last Modified",
            "TRX Order #", "PO #",
            "Customer Name", "Customer Email",
            "Billing Address", "Shipping Address",
            "Is Drop Ship", "Carrier", "Tracking #",
            "Logistics Status", "Logistics Date",
            "Line Items (SKU | Name | Qty | WO | Store)",
        ]


        # Styles
        header_font  = Font(bold=True, color="FFFFFF", size=10)
        header_fill  = PatternFill("solid", fgColor="4B0082")   # Deep purple — on brand
        alt_fill     = PatternFill("solid", fgColor="F3EEFF")
        center_align = Alignment(horizontal='center', vertical='top', wrap_text=False)
        wrap_align   = Alignment(horizontal='left',   vertical='top', wrap_text=True)
        thin_border  = Border(
            left=Side(style='thin'), right=Side(style='thin'),
            top=Side(style='thin'), bottom=Side(style='thin')
        )

        # Write header row
        ws.append(HEADERS)
        for col_idx, cell in enumerate(ws[1], 1):
            cell.font      = header_font
            cell.fill      = header_fill
            cell.alignment = center_align
            cell.border    = thin_border

        ws.row_dimensions[1].height = 20

        # Column widths (approximate)
        col_widths = [10, 14, 14, 14, 10, 18, 18, 14, 18,
                      20, 26, 30, 30, 12, 14, 18, 16, 14, 60]
        for i, w in enumerate(col_widths, 1):
            ws.column_dimensions[ws.cell(1, i).column_letter].width = w

        def fmt_addr(d):
            if not d:
                return ""
            parts = [d.get('first_name',''), d.get('last_name',''),
                     d.get('address_1',''), d.get('city',''),
                     d.get('state',''), d.get('postcode','')]
            return ', '.join(p for p in parts if p)

        def fmt_items(line_items):
            rows = []
            for it in line_items:
                sku = getattr(it, 'sku', '') or ''
                wo  = it.wo_number or ''
                st  = it.install_store_id or ''
                rows.append(f"{sku} | {it.name} | qty:{it.quantity} | WO:{wo} | Store:{st}")
            return '\n'.join(rows)


        # Write data rows
        for row_num, order in enumerate(orders_to_export, start=2):
            billing  = order.billing  or {}
            shipping = order.shipping or {}

            # Collect unique WO#s and stores from line items
            wos    = sorted({str(it.wo_number)    for it in order.line_items if it.wo_number})
            stores = sorted({str(it.install_store_id) for it in order.line_items if it.install_store_id})

            row_data = [
                order.id,
                order.status or '',
                ', '.join(wos)    or '',
                ', '.join(stores) or '',
                float(order.total) if order.total else 0.0,
                self.format_human_time(order.date_created),
                self.format_human_time(order.date_modified),
                order.trx_order_number    or '',
                order.purchase_order_number or '',
                f"{billing.get('first_name','')} {billing.get('last_name','')}".strip(),
                billing.get('email', ''),
                fmt_addr(billing),
                fmt_addr(shipping),
                'Yes' if order.is_dropship else 'No',
                order.carrier_name  or '',
                order.tracking_number or '',
                order.logistics_status or '',
                order.logistics_eta   or '',
                fmt_items(order.line_items),
            ]

            ws.append(row_data)

            # Alternate row shading
            fill = alt_fill if row_num % 2 == 0 else None
            for col_idx, cell in enumerate(ws[row_num], 1):
                cell.border    = thin_border
                cell.alignment = wrap_align if col_idx == len(HEADERS) else center_align
                if fill:
                    cell.fill = fill

            # Taller rows when multi-item
            if len(order.line_items) > 1:
                ws.row_dimensions[row_num].height = min(15 * len(order.line_items), 80)

        # Freeze header
        ws.freeze_panes = 'A2'

        # Auto-filter
        ws.auto_filter.ref = ws.dimensions

        # TRX List sheet — just the TRX numbers, useful for Cairn import
        ws_trx = wb.create_sheet("TRX Numbers")
        ws_trx.append(["TRX Order #", "Order ID", "PO #", "Status", "WO #", "Date Placed"])
        trx_hdr_fill = PatternFill("solid", fgColor="1C3D5A")
        for cell in ws_trx[1]:
            cell.font      = Font(bold=True, color="FFFFFF")
            cell.fill      = trx_hdr_fill
            cell.alignment = center_align

        for order in orders_to_export:
            if order.trx_order_number:
                wos = sorted({str(it.wo_number) for it in order.line_items if it.wo_number})
                ws_trx.append([
                    order.trx_order_number,
                    order.id,
                    order.purchase_order_number or '',
                    order.status or '',
                    ', '.join(wos),
                    self.format_human_time(order.date_created),
                ])

        for col in ['A','B','C','D','E','F']:
            ws_trx.column_dimensions[col].width = 18

        # Save
        wb.save(file_path)
        messagebox.showinfo(
            "✅ Export Complete",
            f"Exported {len(orders_to_export)} orders to:\n{file_path}\n\n"
            f"Sheets: 'Orders' (full detail) + 'TRX Numbers' (Cairn import list)"
        )

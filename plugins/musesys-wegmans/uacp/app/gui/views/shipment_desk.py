# ==============================================================================
# Title: UACP Shipment Desk View (shipment_desk.py)
# Description: Single-purpose tracking/logistics data-entry view for the
#              "shipping_clerk" role. Search-by-anything across the FULL
#              (uncapped) order set via the export-orders feed, then edits
#              are restricted to: Tracking #, Carrier, per-line Shipped Qty,
#              Logistics Status Note, Estimated Date.
#
#              Deliberately does NOT touch TRX #, PO #, Drop Ship, order
#              Status, WO #/Store/Asset Tag, or any export/catalog/asset
#              tooling — those fields are display-only context here.
#
#              NOTE: calculate_status_progression / normalize_status_slug /
#              normalize_date / the logistics gate modal / the save payload
#              shape are intentionally DUPLICATED from order_ops.py rather
#              than imported, so this view is fully self-contained and a bug
#              here can't touch the Active Orders tab Cole already relies on
#              daily. Candidate for extraction to a shared app/data module
#              once this view is proven in the field — flagged, not done.
# ==============================================================================

import tkinter as tk
from tkinter import ttk, messagebox
import threading
import traceback
from datetime import datetime, timedelta

from app.core.config import ConfigManager
from app.data.bridge import MuseDataBridge
from app.models import CARRIER_OPTIONS

DEFAULT_LOOKBACK_DAYS = 180

LOG_STATUS_DISPLAY = {
    'in_stock': 'In Stock',
    'backordered': 'Backordered',
    'replaced': 'Replaced',
    'discontinued': 'Discontinued',
}
LOG_STATUS_REVERSE = {v: k for k, v in LOG_STATUS_DISPLAY.items()}


class ShipmentDeskView(ttk.Frame):
    def __init__(self, parent):
        super().__init__(parent)
        self.bridge = MuseDataBridge()

        self.orders_index = []          # flattened export rows (search index, ALL orders)
        self.current_order = None       # full Order object for the currently loaded order
        self.to_ship_entries = {}       # line_item_id -> Entry widget
        self.current_line_items = {}    # line_item_id -> dict

        self.setup_ui()
        self.refresh_list_async()

    # ------------------------------------------------------------------
    # UI Construction
    # ------------------------------------------------------------------
    def setup_ui(self):
        # --- Search bar ---
        search_frame = tk.Frame(self, bg='#f8f9fa', relief=tk.SOLID, borderwidth=1)
        search_frame.pack(fill=tk.X, padx=10, pady=10)

        tk.Label(
            search_frame, text="Find an order by Order #, WO #, TRX #, or PO #:",
            bg='#f8f9fa', font=('Arial', 10, 'bold')
        ).pack(side=tk.LEFT, padx=(10, 8), pady=10)

        self.search_entry = tk.Entry(search_frame, bg='#FFF9C4', relief=tk.SOLID, borderwidth=1, font=('Arial', 11), width=24)
        self.search_entry.pack(side=tk.LEFT, pady=10)
        self.search_entry.bind('<Return>', lambda e: self.on_search())

        self.btn_search = tk.Button(
            search_frame, text="🔍 Find Order", bg='#2196F3', fg='white', font=('Arial', 10, 'bold'),
            command=self.on_search
        )
        self.btn_search.pack(side=tk.LEFT, padx=8)

        self.btn_refresh = tk.Button(
            search_frame, text="Refresh Full List", bg='#28a745', fg='white', font=('Arial', 10),
            command=self.manual_refresh
        )
        self.btn_refresh.pack(side=tk.LEFT, padx=8)

        self.status_label = tk.Label(search_frame, text="Loading order list...", bg='#f8f9fa', fg='#555', font=('Arial', 9, 'italic'))
        self.status_label.pack(side=tk.RIGHT, padx=15)

        # --- Disambiguation results (hidden until needed) ---
        self.results_frame = tk.Frame(self, bg='white')
        tk.Label(self.results_frame, text="Multiple orders matched — pick one:", bg='white', font=('Arial', 9, 'bold')).pack(anchor='w', padx=10)
        self.results_tree = ttk.Treeview(
            self.results_frame, columns=("order", "wo", "trx", "item"), show='headings', height=5, selectmode='browse'
        )
        for col, label, width in [("order", "Order #", 90), ("wo", "WO #", 110), ("trx", "TRX #", 110), ("item", "Item", 300)]:
            self.results_tree.heading(col, text=label)
            self.results_tree.column(col, width=width, anchor='w')
        self.results_tree.pack(fill=tk.X, padx=10, pady=(2, 10))
        self.results_tree.bind('<<TreeviewSelect>>', self.on_result_selected)

        # --- Order context banner (read-only) ---
        self.banner_frame = tk.Frame(self, bg='#f8f9fa', relief=tk.SOLID, borderwidth=1)
        self.banner_frame.pack(fill=tk.X, padx=10, pady=(0, 10))
        self.banner_label = tk.Label(
            self.banner_frame, text="Search for an order to begin.",
            font=('Arial', 11, 'bold'), bg='#f8f9fa', anchor='w', padx=10, pady=8, justify='left'
        )
        self.banner_label.pack(side=tk.LEFT, fill=tk.X, expand=True)

        # --- Editable fields ---
        fields_frame = tk.Frame(self, bg='white')
        fields_frame.pack(fill=tk.X, padx=10, pady=(0, 10))
        for i in range(4):
            fields_frame.columnconfigure(i, weight=1)

        tk.Label(fields_frame, text="Tracking #", bg='white', font=('Arial', 9)).grid(row=0, column=0, sticky='w', padx=5)
        self.tracking_entry = tk.Entry(fields_frame, bg='#FFF9C4', relief=tk.SOLID, borderwidth=1)
        self.tracking_entry.grid(row=1, column=0, sticky='ew', padx=5, pady=2)

        tk.Label(fields_frame, text="Carrier", bg='white', font=('Arial', 9)).grid(row=0, column=1, sticky='w', padx=5)
        style = ttk.Style()
        style.map('Yellow.TCombobox', fieldbackground=[('readonly', '#FFF9C4')], background=[('readonly', '#FFF9C4')])
        self.carrier_combo = ttk.Combobox(fields_frame, style='Yellow.TCombobox', state='readonly', values=list(CARRIER_OPTIONS.values()))
        self.carrier_combo.grid(row=1, column=1, sticky='ew', padx=5, pady=2)

        tk.Label(fields_frame, text="Logistics Status Note", bg='white', font=('Arial', 9, 'bold'), fg='#d32f2f').grid(row=0, column=2, sticky='w', padx=5)
        self.logistics_status_combo = ttk.Combobox(
            fields_frame, style='Yellow.TCombobox', state='readonly',
            values=['', 'In Stock', 'Backordered', 'Replaced', 'Discontinued']
        )
        self.logistics_status_combo.grid(row=1, column=2, sticky='ew', padx=5, pady=2)

        tk.Label(fields_frame, text="Estimated Date (YYYY-MM-DD)", bg='white', font=('Arial', 9, 'bold'), fg='#d32f2f').grid(row=0, column=3, sticky='w', padx=5)
        self.logistics_date_entry = tk.Entry(fields_frame, bg='#FFF9C4', relief=tk.SOLID, borderwidth=1)
        self.logistics_date_entry.grid(row=1, column=3, sticky='ew', padx=5, pady=2)

        # --- Line items (Shipped Qty entry) ---
        items_header = tk.Label(self, text="Line Items — enter quantity shipped in THIS update", font=('Arial', 11, 'bold'), bg='white', anchor='w')
        items_header.pack(fill=tk.X, padx=10)

        items_container = tk.Frame(self, bg='white')
        items_container.pack(fill=tk.BOTH, expand=True, padx=10, pady=5)

        self.items_canvas = tk.Canvas(items_container, bg='white', highlightthickness=0)
        items_scroll = tk.Scrollbar(items_container, orient=tk.VERTICAL, command=self.items_canvas.yview)
        self.items_frame = tk.Frame(self.items_canvas, bg='white')
        self.items_frame.bind("<Configure>", lambda e: self.items_canvas.configure(scrollregion=self.items_canvas.bbox("all")))
        self.items_canvas.create_window((0, 0), window=self.items_frame, anchor='nw')
        self.items_canvas.configure(yscrollcommand=items_scroll.set)
        self.items_canvas.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        items_scroll.pack(side=tk.RIGHT, fill=tk.Y)

        # --- Save ---
        save_frame = tk.Frame(self, bg='white')
        save_frame.pack(fill=tk.X, padx=10, pady=10)
        self.btn_save = tk.Button(
            save_frame, text="✅ Save Shipment Update", bg='#28a745', fg='white', font=('Arial', 11, 'bold'),
            width=22, command=self.on_save, state='disabled'
        )
        self.btn_save.pack(side=tk.RIGHT)

    # ------------------------------------------------------------------
    # Data loading — search index (capped rolling window to protect server PHP memory)
    # ------------------------------------------------------------------
    def manual_refresh(self):
        self.btn_refresh.config(state='disabled')
        self.refresh_list_async(callback=lambda: self.btn_refresh.config(state='normal'))

    def refresh_list_async(self, callback=None):
        threading.Thread(target=self._fetch_index_thread, args=(callback,), daemon=True).start()

    def _fetch_index_thread(self, callback):
        lookback_days = DEFAULT_LOOKBACK_DAYS
        try:
            settings = ConfigManager.load_settings()
            lookback_days = int(settings.get("lookback_days", DEFAULT_LOOKBACK_DAYS))
            end_date = datetime.now().strftime("%Y-%m-%d")
            start_date = (datetime.now() - timedelta(days=lookback_days)).strftime("%Y-%m-%d")

            def on_chunk(combined):
                self.after(0, lambda: self._on_index_loaded(combined, None, lookback_days, is_partial=True))

            data = self.bridge.export_orders_to_csv_data(
                start_date=start_date,
                end_date=end_date,
                export_all=False,
                chunk_callback=on_chunk
            )
            self.after(0, lambda: self._on_index_loaded(data, callback, lookback_days, is_partial=False))
        except Exception as e:
            print(f"Shipment Desk index fetch error: {e}")
            if not self.orders_index:
                self.after(0, lambda: self.status_label.config(text="Error loading orders — check connection"))
            if callback:
                self.after(0, callback)

    def _on_index_loaded(self, data, callback=None, lookback_days=DEFAULT_LOOKBACK_DAYS, is_partial=False):
        self.orders_index = data or []
        order_count = len(set(r.get('Order Number') for r in self.orders_index))
        status_suffix = " (loading older...)" if is_partial else ""
        self.status_label.config(
            text=f"{len(self.orders_index)} line items / {order_count} orders loaded ({lookback_days} days){status_suffix} — as of {datetime.now().strftime('%I:%M %p')}"
        )
        if callback:
            callback()

    # ------------------------------------------------------------------
    # Search
    # ------------------------------------------------------------------
    def on_search(self):
        term = self.search_entry.get().strip().lower()
        if not term:
            messagebox.showwarning("Search", "Enter an Order #, WO #, TRX #, or PO # first.")
            return
        if not self.orders_index:
            messagebox.showwarning("Not Ready", "Order list is still loading — try again in a moment.")
            return

        # Search formula = Order # OR dedicated global-WO source OR all line-item WO
        # sources OR TRX # OR PO #. Each source is now a single-purpose field, traced
        # to its own authoritative writer rather than pulled through an ambiguous
        # multi-key resolver (see IMPLEMENTATION_LOG.md, 2026-08-21):
        #   - global_work_order  <- _muse_wo_global directly (set once, at checkout,
        #     by muse-checkout-orchestrator's "global WO mode" — same key its own
        #     admin display box reads).
        #   - additional_work_order_number <- per-line-item WO meta (_muse_wo_line /
        #     "WO Number"), one row per line item, so iterating self.orders_index
        #     already checks every line item's WO # on a multi-WO order for free.
        #   - TRX # / PO Number <- _trx_order_number / _purchase_order_number directly.
        matches_by_order = {}
        for row in self.orders_index:
            order_no = str(row.get('Order Number', '')).strip()
            candidates = [
                order_no,
                str(row.get('global_work_order', '')).strip(),
                str(row.get('additional_work_order_number', '')).strip(),
                str(row.get('TRX #', '')).strip(),
                str(row.get('PO Number', '')).strip(),
            ]
            if any(c.lower() == term for c in candidates if c):
                matches_by_order.setdefault(order_no, []).append(row)

        if not matches_by_order:
            # Direct fallback: if user searched for an order number outside rolling window
            clean_term = self.search_entry.get().strip()
            if clean_term.isdigit():
                try:
                    direct_order = self.bridge.get_order(clean_term)
                    if direct_order and getattr(direct_order, 'id', None):
                        self.hide_results()
                        self.load_order(str(direct_order.id))
                        return
                except Exception as e:
                    print(f"Direct order lookup fallback failed: {e}")

            self.hide_results()
            messagebox.showerror("Not Found", f"No order found matching '{self.search_entry.get().strip()}'.")
            return

        if len(matches_by_order) == 1:
            self.hide_results()
            order_no = next(iter(matches_by_order))
            self.load_order(order_no)
        else:
            self.show_results(matches_by_order)

    def show_results(self, matches_by_order):
        self.results_tree.delete(*self.results_tree.get_children())
        for order_no, rows in matches_by_order.items():
            first = rows[0]
            wo = first.get('additional_work_order_number', '') or first.get('global_work_order', '')
            trx = first.get('TRX #', '')
            item = first.get('Item Name', '')
            self.results_tree.insert("", "end", iid=order_no, values=(order_no, wo, trx, item))
        self.results_frame.pack(fill=tk.X, padx=10, pady=(0, 10), before=self.banner_frame)

    def hide_results(self):
        self.results_frame.pack_forget()

    def on_result_selected(self, event):
        selection = self.results_tree.selection()
        if not selection:
            return
        order_no = selection[0]
        self.hide_results()
        self.load_order(order_no)

    # ------------------------------------------------------------------
    # Load a single order — full, uncapped, authoritative fetch
    # ------------------------------------------------------------------
    def load_order(self, order_no):
        self.status_label.config(text=f"Loading order #{order_no}...")
        threading.Thread(target=self._load_order_thread, args=(order_no,), daemon=True).start()

    def _load_order_thread(self, order_no):
        try:
            order = self.bridge.get_order(str(order_no))
            self.after(0, lambda: self._populate_order(order))
        except Exception as e:
            print(f"Shipment Desk load_order error: {e}")
            self.after(0, lambda: messagebox.showerror("Error", f"Could not load order #{order_no}: {e}"))

    def _populate_order(self, order):
        if not order:
            messagebox.showerror("Not Found", "Order could not be loaded from the server.")
            return

        self.current_order = order
        self.btn_save.config(state='normal')

        wos = sorted({str(i.wo_number) for i in order.line_items if i.wo_number})
        stores = sorted({str(i.install_store_id) for i in order.line_items if i.install_store_id})
        info = (
            f"Order #{order.id}  |  Status: {order.status}  |  Total: ${order.total}\n"
            f"TRX #: {order.trx_order_number or '—'}   PO #: {order.purchase_order_number or '—'}   "
            f"WO #: {', '.join(wos) or '—'}   Store: {', '.join(stores) or '—'}"
        )
        self.banner_label.config(text=info)

        self.tracking_entry.delete(0, tk.END)
        self.tracking_entry.insert(0, order.tracking_number or "")

        carrier_label = CARRIER_OPTIONS.get(order.carrier_name, order.carrier_name)
        self.carrier_combo.set(carrier_label or "")

        log_status = LOG_STATUS_DISPLAY.get(order.logistics_status, order.logistics_status or '')
        self.logistics_status_combo.set(log_status)
        self.logistics_date_entry.delete(0, tk.END)
        if order.logistics_eta:
            self.logistics_date_entry.insert(0, order.logistics_eta)

        self.populate_line_items(order.line_items)
        self.status_label.config(text=f"Order #{order.id} loaded.")

    def populate_line_items(self, items):
        for widget in self.items_frame.winfo_children():
            widget.destroy()
        self.to_ship_entries = {}
        self.current_line_items = {}

        headers = ["Item", "Ordered Qty", "Already Shipped", "Remaining", "Ship Now"]
        for col, h in enumerate(headers):
            tk.Label(self.items_frame, text=h, font=('Arial', 10, 'bold'), bg='#f0f0f0', relief=tk.RIDGE, borderwidth=1, padx=8, pady=5)\
                .grid(row=0, column=col, sticky='ew')

        for idx, item in enumerate(items):
            row = idx + 1
            shipped = int(getattr(item, 'shipped_qty', 0) or 0)
            ordered = int(item.quantity or 0)
            remaining = max(0, ordered - shipped)

            self.current_line_items[item.id] = {
                'id': item.id, 'qty': ordered, 'shipped_qty': shipped,
            }

            tk.Label(self.items_frame, text=item.name, bg='white', anchor='w', relief=tk.RIDGE, borderwidth=1, padx=8, pady=5)\
                .grid(row=row, column=0, sticky='ew')
            tk.Label(self.items_frame, text=str(ordered), bg='white', anchor='center', relief=tk.RIDGE, borderwidth=1, padx=8, pady=5)\
                .grid(row=row, column=1, sticky='ew')
            tk.Label(self.items_frame, text=str(shipped), bg='white', anchor='center', relief=tk.RIDGE, borderwidth=1, padx=8, pady=5)\
                .grid(row=row, column=2, sticky='ew')
            tk.Label(self.items_frame, text=str(remaining), bg='white', anchor='center', relief=tk.RIDGE, borderwidth=1, padx=8, pady=5)\
                .grid(row=row, column=3, sticky='ew')

            entry = tk.Entry(self.items_frame, bg='#FFF9C4', relief=tk.SOLID, borderwidth=1, justify='center', width=10)
            if remaining <= 0:
                entry.insert(0, "0")
                entry.config(state='disabled', disabledbackground='#e0e0e0', disabledforeground='black')
            entry.grid(row=row, column=4, sticky='ew', padx=2, pady=2)
            self.to_ship_entries[item.id] = entry

        for i in range(5):
            self.items_frame.columnconfigure(i, weight=1)
        self.items_frame.columnconfigure(0, weight=3)

    # ------------------------------------------------------------------
    # Save — reuses the same write paths as Active Orders (ship_lines /
    # update_order meta), duplicated here rather than imported (see header note).
    # ------------------------------------------------------------------
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
                if val == "":
                    qty = 0
                elif val.isdigit():
                    qty = min(int(val), remaining_qty)
                else:
                    qty = 0

            item_dict = original.copy()
            item_dict['to_ship'] = qty
            items.append(item_dict)
        return items

    def normalize_date(self, date_str):
        if not date_str:
            return ""
        cleaned = date_str.strip().replace('/', '-').replace('.', '-')
        parsed = datetime.strptime(cleaned, '%Y-%m-%d')
        return parsed.strftime('%Y-%m-%d')

    def normalize_status_slug(self, status):
        if not status:
            return 'pending'
        normalized = str(status).strip().lower().replace('_', '-').replace(' ', '-')
        status_map = {
            'order-placed': 'wc-order-placed', 'wc-order-placed': 'wc-order-placed',
            'pending': 'pending', 'processing': 'processing',
            'partially-shipped': 'partially-shipped', 'completed': 'completed',
            'cancelled': 'cancelled', 'refunded': 'refunded', 'failed': 'failed',
        }
        return status_map.get(normalized, normalized)

    def calculate_status_progression(self, order_data):
        current_status = self.normalize_status_slug(order_data.get('status', 'pending'))
        line_items = order_data.get('line_items', [])

        def total_after_current_shipment():
            return sum(int(i.get('shipped_qty', 0) or 0) + int(i.get('to_ship', 0) or 0) for i in line_items)

        total_qty = sum(int(i.get('qty', 0) or 0) for i in line_items)

        if current_status == 'processing' and order_data.get('tracking_number'):
            total_to_ship = total_after_current_shipment()
            return 'partially-shipped' if total_to_ship < total_qty else 'completed'

        if current_status == 'partially-shipped' and order_data.get('tracking_number'):
            if total_after_current_shipment() >= total_qty:
                return 'completed'

        return current_status

    def on_save(self):
        if not self.current_order:
            messagebox.showwarning("Warning", "Search for and load an order first.")
            return
        try:
            order = self.current_order
            tracking_number = self.tracking_entry.get().strip()
            carrier_label = self.carrier_combo.get()
            log_status_label = self.logistics_status_combo.get()
            log_status_val = LOG_STATUS_REVERSE.get(log_status_label, log_status_label)
            log_eta_raw = self.logistics_date_entry.get().strip()
            line_items = self.get_line_items_data()
            any_ship_qty = any(i.get('to_ship', 0) > 0 for i in line_items)

            if not tracking_number and not log_status_label and not any_ship_qty:
                messagebox.showwarning("Nothing to Save", "Enter tracking info, a shipped quantity, or a logistics update first.")
                return

            if tracking_number and not carrier_label:
                messagebox.showwarning("Missing Carrier", "Select a Carrier before saving tracking info.")
                self.carrier_combo.focus_set()
                return

            replacement_part = ''
            if log_status_label:
                date_required = log_status_val not in ['replaced', 'discontinued']
                if date_required and not log_eta_raw:
                    messagebox.showwarning("Missing Estimated Date", "Enter an Estimated Date for this Logistics Status, or clear the status.")
                    self.logistics_date_entry.focus_set()
                    return
                if date_required:
                    try:
                        log_eta_raw = self.normalize_date(log_eta_raw)
                        self.logistics_date_entry.delete(0, tk.END)
                        self.logistics_date_entry.insert(0, log_eta_raw)
                    except ValueError:
                        messagebox.showwarning("Invalid Date Format", "Estimated Date must be YYYY-MM-DD.")
                        self.logistics_date_entry.focus_set()
                        return
                if log_status_val == 'replaced':
                    replacement_part = self._prompt_replacement_part()
                    if replacement_part is None:
                        return  # cancelled

            carrier_code = None
            for code, lbl in CARRIER_OPTIONS.items():
                if lbl == carrier_label:
                    carrier_code = code
                    break

            data = {
                'id': order.id,
                'trx_number': order.trx_order_number,          # pass-through, untouched
                'po_number': order.purchase_order_number,      # pass-through, untouched
                'is_drop_ship': order.is_dropship,              # pass-through, untouched
                'status': order.status,
                'tracking_number': tracking_number,
                'carrier': carrier_code or carrier_label,
                'logistics_status': log_status_val if log_status_label else order.logistics_status,
                'logistics_eta': log_eta_raw if log_status_label else order.logistics_eta,
                'replacement_part': replacement_part,
                'line_items': line_items,
            }

            new_status = self.calculate_status_progression(data)
            data['status'] = new_status

            self.btn_save.config(state='disabled')
            threading.Thread(target=self._save_thread, args=(data,), daemon=True).start()
        except Exception as e:
            traceback.print_exc()
            messagebox.showerror("Error", f"Failed to save: {str(e)}")

    def _prompt_replacement_part(self):
        """Blocking-ish tiny prompt for the required replacement part # when status = Replaced."""
        result = {'value': None}
        modal = tk.Toplevel(self)
        modal.title("Replacement Part Required")
        modal.geometry("340x150")
        modal.resizable(False, False)
        modal.transient(self)
        modal.grab_set()
        tk.Label(modal, text="Replacement Part # *", font=('Arial', 9, 'bold'), fg='#d32f2f').pack(pady=(15, 2))
        entry = tk.Entry(modal, bg='#FFF9C4', relief=tk.SOLID, borderwidth=1)
        entry.pack(padx=20, fill=tk.X)
        entry.focus_set()

        def confirm():
            val = entry.get().strip()
            if not val:
                messagebox.showwarning("Required", "Replacement Part # is required.", parent=modal)
                return
            result['value'] = val
            modal.destroy()

        def cancel():
            result['value'] = None
            modal.destroy()

        btns = tk.Frame(modal)
        btns.pack(pady=15)
        tk.Button(btns, text="Confirm", bg='#1a237e', fg='white', command=confirm).pack(side=tk.LEFT, padx=8)
        tk.Button(btns, text="Cancel", command=cancel).pack(side=tk.LEFT, padx=8)
        self.wait_window(modal)
        return result['value']

    def _save_thread(self, data):
        try:
            carrier_code = data['carrier']

            meta_data = []
            if data.get('tracking_number'):
                meta_data.append({"key": "_muse_ship_track", "value": data['tracking_number']})
            if carrier_code:
                meta_data.append({"key": "_muse_carrier", "value": carrier_code})
            if data.get('logistics_status'):
                meta_data.append({"key": "_muse_logistics_status", "value": data['logistics_status']})
            if data.get('logistics_eta'):
                meta_data.append({"key": "_muse_logistics_date", "value": data['logistics_eta']})
            if data.get('replacement_part'):
                meta_data.append({"key": "_muse_logistics_replacement_part", "value": data['replacement_part']})

            update_payload = {
                "status": self.normalize_status_slug(data['status']),
                "meta_data": meta_data,
            }

            lines_to_ship = [
                {"line_item_id": i['id'], "ship_qty": i['to_ship']}
                for i in data['line_items'] if i.get('to_ship', 0) > 0
            ]

            close_it_out = False
            if lines_to_ship:
                total_qty = sum(int(i.get('qty', 0) or 0) for i in data['line_items'])
                total_after = sum(int(i.get('shipped_qty', 0) or 0) + int(i.get('to_ship', 0) or 0) for i in data['line_items'])
                close_it_out = (
                    self.normalize_status_slug(data['status']) == 'completed'
                    and total_after >= total_qty
                )
                # Race-condition guard: let ship_lines carry the completion status
                # so tracking is attached before the "Order Completed" email fires.
                if close_it_out and "status" in update_payload:
                    update_payload.pop("status")

            if meta_data:
                self.bridge.client.update_order(data['id'], update_payload)

            if lines_to_ship or data.get('tracking_number'):
                ship_payload = {
                    "carrier": carrier_code,
                    "tracking_numbers": [data['tracking_number']] if data.get('tracking_number') else [],
                    "notify": True,
                    "lines": lines_to_ship,
                    "closeout": close_it_out,
                }
                # A refused shipment used to pass silently and "Saved" was shown.
                if not self.bridge.ship_lines(data['id'], ship_payload):
                    raise RuntimeError(
                        "The shipment was NOT recorded on the order.\n" + self.bridge.ship_problem()
                    )

            self.after(0, lambda: self._on_save_complete(data['id'], data['status']))
        except Exception as e:
            traceback.print_exc()
            # Bind the text now: `e` is gone by the time the main thread runs the lambda.
            reason = str(e)
            self.after(0, lambda: messagebox.showerror("Error", f"Save failed: {reason}"))
            self.after(0, lambda: self.btn_save.config(state='normal'))

    def _on_save_complete(self, order_id, new_status):
        self.btn_save.config(state='normal')
        messagebox.showinfo("✅ Saved", f"Order #{order_id} updated.\nStatus → {new_status}")
        # Rapid-fire workflow: clear search + reload the full index so the next
        # lookup reflects this save, and refocus for the next order.
        self.search_entry.delete(0, tk.END)
        self.search_entry.focus_set()
        self.refresh_list_async()
        self.load_order(order_id)

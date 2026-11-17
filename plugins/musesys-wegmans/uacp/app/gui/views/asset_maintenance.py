import tkinter as tk
from tkinter import ttk, messagebox
import threading
from app.data.bridge import MuseDataBridge

class AssetMaintenanceView(ttk.Frame):
    def __init__(self, parent):
        super().__init__(parent)
        self.bridge = MuseDataBridge()
        self.assets_cache = {}
        self.current_asset = None
        self.original_data = {}
        
        self.setup_ui()

    def setup_ui(self):
        self.paned_window = ttk.PanedWindow(self, orient=tk.HORIZONTAL)
        self.paned_window.pack(fill=tk.BOTH, expand=True)
        
        # --- LEFT PANEL: Search ---
        self.left_panel = ttk.Frame(self.paned_window, padding="10")
        self.paned_window.add(self.left_panel, weight=1)
        
        top_frame = ttk.Frame(self.left_panel)
        top_frame.pack(fill=tk.X, pady=(0, 10))
        
        ttk.Label(top_frame, text="Install Store #:", font=("Arial", 10)).pack(side=tk.LEFT, padx=(0, 5))
        
        self.store_var = tk.StringVar()
        self.store_entry = tk.Entry(top_frame, textvariable=self.store_var, width=15, bg='#FFF9C4', font=('Arial', 11), relief=tk.SOLID, borderwidth=1)
        self.store_entry.pack(side=tk.LEFT, fill=tk.X, expand=True, padx=5)
        self.store_entry.bind("<Return>", lambda e: self.load_assets_async())
        
        self.load_btn = tk.Button(top_frame, text="Load Assets", bg='#007bff', fg='white', font=('Arial', 10, 'bold'), command=self.load_assets_async)
        self.load_btn.pack(side=tk.LEFT, padx=5)

        columns = ("id", "sku", "name")
        self.tree = ttk.Treeview(self.left_panel, columns=columns, show="headings", selectmode="browse")
        
        self.tree.heading("id", text="ID")
        self.tree.heading("sku", text="SKU")
        self.tree.heading("name", text="Description")
        
        self.tree.column("id", width=60, anchor='center')
        self.tree.column("sku", width=100, anchor='center')
        self.tree.column("name", width=200, anchor='w')
        
        vsb = ttk.Scrollbar(self.left_panel, orient="vertical", command=self.tree.yview)
        self.tree.configure(yscrollcommand=vsb.set)
        
        self.tree.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        vsb.pack(side=tk.RIGHT, fill=tk.Y)
        
        self.tree.bind("<<TreeviewSelect>>", self.on_select)

        # --- CENTER PANEL: Edit Form ---
        self.center_panel = ttk.Frame(self.paned_window, padding="10")
        self.paned_window.add(self.center_panel, weight=2)
        
        self.form_canvas = tk.Canvas(self.center_panel)
        self.scrollbar = ttk.Scrollbar(self.center_panel, orient="vertical", command=self.form_canvas.yview)
        self.scrollable_frame = ttk.Frame(self.form_canvas)

        self.scrollable_frame.bind(
            "<Configure>",
            lambda e: self.form_canvas.configure(
                scrollregion=self.form_canvas.bbox("all")
            )
        )

        self.form_canvas.create_window((0, 0), window=self.scrollable_frame, anchor="nw")
        self.form_canvas.configure(yscrollcommand=self.scrollbar.set)
        
        self.form_canvas.pack(side="left", fill="both", expand=True)
        self.scrollbar.pack(side="right", fill="y")
        
        self.build_form()

    def build_form(self):
        row = 0
        
        ttk.Label(self.scrollable_frame, text="Asset Data", font=("Arial", 12, "bold")).grid(row=row, column=0, columnspan=2, pady=(0, 10), sticky="w")
        row += 1
        
        self.vars = {}
        
        fields = [
            ('id', 'Product ID', True),
            ('sku', 'SKU', True),
            ('description', 'Description', False),
            ('manufacturer', 'Manufacturer', False),
            ('model', 'Model', False),
            ('serial_number', 'Serial Number', False),
            ('commission_date', 'Commission Date', False),
            ('department', 'Department', False)
        ]
        
        for key, label, readonly in fields:
            ttk.Label(self.scrollable_frame, text=label).grid(row=row, column=0, sticky="e", padx=5, pady=5)
            self.vars[key] = tk.StringVar()
            
            state = "readonly" if readonly else "normal"
            entry = tk.Entry(self.scrollable_frame, textvariable=self.vars[key], state=state, width=40)
            entry.grid(row=row, column=1, sticky="w", padx=5)
            row += 1
        
        self.save_btn = tk.Button(self.scrollable_frame, text="Save Asset", bg='#28a745', fg='white', font=('Arial', 11, 'bold'), command=self.save_changes_async)
        self.save_btn.grid(row=row, column=1, sticky="w", pady=20)
        self.save_btn.config(state="disabled")

    def load_assets_async(self):
        store_id = self.store_var.get().strip()
        if not store_id: return
        
        self.load_btn.config(state="disabled", text="Loading...")
        self.tree.delete(*self.tree.get_children())
        self.save_btn.config(state="disabled")
        
        t = threading.Thread(target=self._load_thread, args=(store_id,), daemon=True)
        t.start()

    def _load_thread(self, store_id):
        results = self.bridge.get_assets(store_id)
        self.after(0, lambda: self.update_results_ui(results))

    def update_results_ui(self, results):
        self.assets_cache = {a['id']: a for a in results}
        for a in results:
            self.tree.insert("", "end", iid=a['id'], values=(
                a.get('id'),
                a.get('sku'),
                a.get('description')
            ))
        self.load_btn.config(state="normal", text="Load Assets")

    def on_select(self, event):
        selected = self.tree.selection()
        if not selected: return
        item_id = int(selected[0])
        asset = self.assets_cache.get(item_id)
        if asset:
            self.load_asset_data(asset)

    def load_asset_data(self, asset):
        self.current_asset = asset
        self.original_data = {}
        
        for key in ['id', 'sku', 'description', 'manufacturer', 'model', 'serial_number', 'commission_date', 'department']:
            val = str(asset.get(key, '')) if asset.get(key) is not None else ''
            self.vars[key].set(val)
            self.original_data[key] = val
            
        self.save_btn.config(state="normal")

    def save_changes_async(self):
        if not self.current_asset:
            return
            
        payload = {}
        
        for key in ['description', 'manufacturer', 'model', 'serial_number', 'commission_date', 'department']:
            new_val = self.vars[key].get()
            if new_val != self.original_data[key]:
                payload[key] = new_val

        if not payload:
            messagebox.showinfo("Info", "No changes to save.")
            return
            
        self.save_btn.config(state="disabled", text="Saving...")
        t = threading.Thread(target=self._save_thread, args=(self.current_asset['id'], payload), daemon=True)
        t.start()
        
    def _save_thread(self, asset_id, payload):
        result = self.bridge.update_asset(asset_id, payload)
        if result:
            self.after(0, lambda: self.on_save_success(payload))
        else:
            self.after(0, lambda: self.on_save_error("Failed to update asset."))

    def on_save_success(self, payload):
        self.save_btn.config(state="normal", text="Save Asset")
        # Update cache and original_data so we don't have to reload everything
        for k, v in payload.items():
            self.current_asset[k] = v
            self.original_data[k] = v
            
        # Update tree if description changed
        if 'description' in payload:
            self.tree.item(self.current_asset['id'], values=(
                self.current_asset['id'],
                self.current_asset['sku'],
                payload['description']
            ))
            
        messagebox.showinfo("Success", "Asset updated successfully.")
        
    def on_save_error(self, msg):
        self.save_btn.config(state="normal", text="Save Asset")
        messagebox.showerror("Error", msg)

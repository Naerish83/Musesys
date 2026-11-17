import tkinter as tk
from tkinter import ttk, messagebox, filedialog
import threading
import os
from app.data.bridge import MuseDataBridge

class CatalogMaintenanceView(ttk.Frame):
    def __init__(self, parent):
        super().__init__(parent)
        self.bridge = MuseDataBridge()
        self.products_cache = {}
        self.current_product = None
        self.selected_image_path = None
        
        # Track original values for diffing
        self.original_data = {}
        
        self.setup_ui()

    def setup_ui(self):
        # Create a PanedWindow to separate search and edit
        self.paned_window = ttk.PanedWindow(self, orient=tk.HORIZONTAL)
        self.paned_window.pack(fill=tk.BOTH, expand=True)
        
        # --- LEFT PANEL: Search ---
        self.left_panel = ttk.Frame(self.paned_window, padding="10")
        self.paned_window.add(self.left_panel, weight=1)
        
        top_frame = ttk.Frame(self.left_panel)
        top_frame.pack(fill=tk.X, pady=(0, 10))
        
        ttk.Label(top_frame, text="Search Product:", font=("Arial", 10)).pack(side=tk.LEFT, padx=(0, 5))
        
        self.search_var = tk.StringVar()
        self.search_entry = tk.Entry(top_frame, textvariable=self.search_var, width=30, bg='#FFF9C4', font=('Arial', 11), relief=tk.SOLID, borderwidth=1)
        self.search_entry.pack(side=tk.LEFT, fill=tk.X, expand=True, padx=5)
        self.search_entry.bind("<Return>", lambda e: self.search_async())
        
        self.search_btn = tk.Button(top_frame, text="Search", bg='#007bff', fg='white', font=('Arial', 10, 'bold'), command=self.search_async)
        self.search_btn.pack(side=tk.LEFT, padx=5)

        columns = ("id", "sku", "name")
        self.tree = ttk.Treeview(self.left_panel, columns=columns, show="headings", selectmode="browse")
        
        self.tree.heading("id", text="ID")
        self.tree.heading("sku", text="SKU")
        self.tree.heading("name", text="Name")
        
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
        # Helper to create rows
        row = 0
        
        # Standard Title
        ttk.Label(self.scrollable_frame, text="Product Data", font=("Arial", 12, "bold")).grid(row=row, column=0, columnspan=3, pady=(0, 10), sticky="w")
        row += 1
        
        # Dicts to hold vars and toggles
        self.vars = {}
        self.toggles = {}
        self.widgets = {}
        
        # Product ID (readonly)
        ttk.Label(self.scrollable_frame, text="Product ID").grid(row=row, column=0, sticky="e", padx=5, pady=5)
        self.vars['id'] = tk.StringVar()
        tk.Entry(self.scrollable_frame, textvariable=self.vars['id'], state="readonly", width=40).grid(row=row, column=1, sticky="w", padx=5)
        row += 1
        
        # Name (always editable)
        ttk.Label(self.scrollable_frame, text="Name").grid(row=row, column=0, sticky="e", padx=5, pady=5)
        self.vars['name'] = tk.StringVar()
        tk.Entry(self.scrollable_frame, textvariable=self.vars['name'], width=40).grid(row=row, column=1, sticky="w", padx=5)
        row += 1
        
        # Fields with Toggles
        toggle_fields = [
            ('sku', 'SKU'),
            ('regular_price', 'Regular Price'),
            ('sale_price', 'Sale Price'),
            ('stock_status', 'Stock Status'),
            ('stock_quantity', 'Stock Quantity'),
            ('status', 'Published Status'),
            ('_muse_wo_global', 'EAM Linkage (_muse_wo_global)')
        ]
        
        for key, label in toggle_fields:
            ttk.Label(self.scrollable_frame, text=label).grid(row=row, column=0, sticky="e", padx=5, pady=5)
            self.vars[key] = tk.StringVar()
            
            entry = tk.Entry(self.scrollable_frame, textvariable=self.vars[key], state="disabled", width=40)
            entry.grid(row=row, column=1, sticky="w", padx=5)
            self.widgets[key] = entry
            
            toggle_var = tk.BooleanVar(value=False)
            self.toggles[key] = toggle_var
            
            chk = tk.Checkbutton(self.scrollable_frame, text="Enable Edit", variable=toggle_var, command=lambda k=key: self.on_toggle(k))
            chk.grid(row=row, column=2, sticky="w", padx=5)
            
            row += 1

        # Description (always editable, multiline)
        ttk.Label(self.scrollable_frame, text="Short Description").grid(row=row, column=0, sticky="e", padx=5, pady=5)
        self.vars['short_description'] = tk.StringVar()
        tk.Entry(self.scrollable_frame, textvariable=self.vars['short_description'], width=40).grid(row=row, column=1, sticky="w", padx=5)
        row += 1

        ttk.Label(self.scrollable_frame, text="Description").grid(row=row, column=0, sticky="e", padx=5, pady=5)
        self.widgets['description'] = tk.Text(self.scrollable_frame, height=5, width=40)
        self.widgets['description'].grid(row=row, column=1, sticky="w", padx=5, pady=5)
        row += 1

        # Image Handling
        ttk.Label(self.scrollable_frame, text="Image").grid(row=row, column=0, sticky="e", padx=5, pady=5)
        img_frame = ttk.Frame(self.scrollable_frame)
        img_frame.grid(row=row, column=1, sticky="w", padx=5)
        
        self.btn_select_img = tk.Button(img_frame, text="Select Image...", bg='#6c757d', fg='white', font=('Arial', 10), command=self.select_image)
        self.btn_select_img.pack(side=tk.LEFT)
        self.lbl_img_path = ttk.Label(img_frame, text="No file selected", foreground="gray")
        self.lbl_img_path.pack(side=tk.LEFT, padx=5)
        row += 1
        
        # Save Button
        self.save_btn = tk.Button(self.scrollable_frame, text="Save Changes", bg='#28a745', fg='white', font=('Arial', 11, 'bold'), command=self.save_changes_async)
        self.save_btn.grid(row=row, column=1, sticky="w", pady=20)

    def on_toggle(self, key):
        state = "normal" if self.toggles[key].get() else "disabled"
        self.widgets[key].config(state=state)

    def select_image(self):
        path = filedialog.askopenfilename(
            title="Select Product Image",
            filetypes=[("Image Files", "*.jpg *.jpeg *.png *.gif")]
        )
        if path:
            self.selected_image_path = path
            filename = os.path.basename(path)
            self.lbl_img_path.config(text=filename, foreground="black")
        else:
            self.selected_image_path = None
            self.lbl_img_path.config(text="No file selected", foreground="gray")

    def search_async(self):
        query = self.search_var.get().strip()
        if not query: return
        
        self.search_btn.config(state="disabled")
        self.tree.delete(*self.tree.get_children())
        
        t = threading.Thread(target=self._search_thread, args=(query,), daemon=True)
        t.start()

    def _search_thread(self, query):
        results = self.bridge.search_products(query)
        self.after(0, lambda: self.update_results_ui(results))

    def update_results_ui(self, results):
        self.products_cache = {p['id']: p for p in results}
        for p in results:
            self.tree.insert("", "end", iid=p['id'], values=(
                p.get('id'),
                p.get('sku'),
                p.get('name')
            ))
        self.search_btn.config(state="normal")

    def on_select(self, event):
        selected = self.tree.selection()
        if not selected: return
        item_id = int(selected[0])
        product = self.products_cache.get(item_id)
        if product:
            self.load_product_data(product)

    def load_product_data(self, product):
        self.current_product = product
        self.original_data = {}
        
        self.vars['id'].set(product.get('id', ''))
        self.vars['name'].set(product.get('name', ''))
        self.original_data['name'] = product.get('name', '')
        
        self.vars['sku'].set(product.get('sku', ''))
        self.original_data['sku'] = product.get('sku', '')
        
        self.vars['regular_price'].set(product.get('regular_price', ''))
        self.original_data['regular_price'] = product.get('regular_price', '')
        
        self.vars['sale_price'].set(product.get('sale_price', ''))
        self.original_data['sale_price'] = product.get('sale_price', '')
        
        self.vars['stock_status'].set(product.get('stock_status', ''))
        self.original_data['stock_status'] = product.get('stock_status', '')
        
        self.vars['stock_quantity'].set(str(product.get('stock_quantity', '')) if product.get('stock_quantity') is not None else '')
        self.original_data['stock_quantity'] = str(product.get('stock_quantity', '')) if product.get('stock_quantity') is not None else ''
        
        self.vars['status'].set(product.get('status', ''))
        self.original_data['status'] = product.get('status', '')
        
        # Meta Fields
        wo_global = ""
        for meta in product.get('meta_data', []):
            if meta.get('key') == '_muse_wo_global':
                wo_global = meta.get('value', '')
                break
        self.vars['_muse_wo_global'].set(wo_global)
        self.original_data['_muse_wo_global'] = wo_global
        
        # Descriptions
        self.vars['short_description'].set(product.get('short_description', ''))
        self.original_data['short_description'] = product.get('short_description', '')
        
        desc = product.get('description', '')
        self.widgets['description'].delete("1.0", tk.END)
        self.widgets['description'].insert(tk.END, desc)
        self.original_data['description'] = desc.strip()
        
        # Reset toggles and image
        for toggle in self.toggles.values():
            toggle.set(False)
        for key, widget in self.widgets.items():
            if key in self.toggles:
                widget.config(state="disabled")
                
        self.selected_image_path = None
        self.lbl_img_path.config(text="No file selected", foreground="gray")

    def save_changes_async(self):
        if not self.current_product:
            messagebox.showwarning("Warning", "No product selected.")
            return
            
        payload = {}
        
        # Check standard editable fields
        new_name = self.vars['name'].get()
        if new_name != self.original_data['name']:
            payload['name'] = new_name
            
        new_short_desc = self.vars['short_description'].get()
        if new_short_desc != self.original_data['short_description']:
            payload['short_description'] = new_short_desc
            
        new_desc = self.widgets['description'].get("1.0", tk.END).strip()
        if new_desc != self.original_data['description']:
            payload['description'] = new_desc

        # Check toggle fields
        meta_data = []
        
        for key, toggle_var in self.toggles.items():
            if toggle_var.get():
                new_val = self.vars[key].get()
                if new_val != self.original_data[key]:
                    if key == '_muse_wo_global':
                        meta_data.append({"key": "_muse_wo_global", "value": new_val})
                    elif key == 'stock_quantity':
                        payload['manage_stock'] = True
                        payload['stock_quantity'] = int(new_val) if new_val else 0
                    else:
                        payload[key] = new_val

        if meta_data:
            payload['meta_data'] = meta_data
            
        img_path = self.selected_image_path
        
        if not payload and not img_path:
            messagebox.showinfo("Info", "No changes to save.")
            return
            
        self.save_btn.config(state="disabled", text="Saving...")
        t = threading.Thread(target=self._save_thread, args=(self.current_product['id'], payload, img_path), daemon=True)
        t.start()
        
    def _save_thread(self, product_id, payload, img_path):
        image_id = None
        if img_path and os.path.exists(img_path):
            self.after(0, lambda: self.save_btn.config(text="Uploading Image..."))
            sku_for_img = payload.get('sku') or self.current_product.get('sku', str(product_id))
            image_id = self.bridge.upload_image_async(img_path, sku_for_img)
            if image_id:
                payload['images'] = [{"id": image_id}]
        
        if payload:
            self.after(0, lambda: self.save_btn.config(text="Updating Product..."))
            result = self.bridge.update_product(product_id, payload)
        else:
            result = True # This shouldn't be hit unless there's some strange logic flow
            
        if result:
            self.after(0, lambda: self.on_save_success())
        else:
            self.after(0, lambda: self.on_save_error("Failed to update product."))

    def on_save_success(self):
        self.save_btn.config(state="normal", text="Save Changes")
        messagebox.showinfo("Success", "Product updated successfully.")
        # Reload product data to reflect changes and reset diffs
        self.search_async() # Refresh the search to get updated data
        
    def on_save_error(self, msg):
        self.save_btn.config(state="normal", text="Save Changes")
        messagebox.showerror("Error", msg)

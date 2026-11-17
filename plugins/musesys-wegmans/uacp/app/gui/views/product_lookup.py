"""
UI View: Product Lookup Module
Version: 0.0.1
Description: Manages the inventory lookup tab, including product search grids, dynamic copy link actions, and product creation functionality.
"""
import tkinter as tk
from tkinter import ttk, messagebox, filedialog
import threading
import os
from app.data.bridge import MuseDataBridge

class ProductLookupView(ttk.Frame):
    def __init__(self, parent):
        super().__init__(parent)
        self.bridge = MuseDataBridge()
        self.products_cache = {}
        self.current_product_id = None
        self.selected_image_path = None
        self.setup_ui()

    def setup_ui(self):
        # --- Top: Search Bar ---
        top_frame = ttk.Frame(self, padding="10")
        top_frame.pack(fill=tk.X)
        
        ttk.Label(top_frame, text="Search Product:", font=("Arial", 10)).pack(side=tk.LEFT, padx=(0, 5))
        
        self.search_var = tk.StringVar()
        self.search_entry = tk.Entry(top_frame, textvariable=self.search_var, width=40, bg='#FFF9C4', font=('Arial', 11), relief=tk.SOLID, borderwidth=1)
        self.search_entry.pack(side=tk.LEFT, padx=5)
        self.search_entry.bind("<Return>", lambda e: self.search_async())
        
        # Search Button (Blue)
        self.search_btn = tk.Button(top_frame, text="Search (Ctrl+F)", bg='#007bff', fg='white', font=('Arial', 10, 'bold'), command=self.search_async)
        self.search_btn.pack(side=tk.LEFT, padx=5)

        # --- Middle: Results Tree ---
        self.tree_frame = ttk.Frame(self, padding="10")
        self.tree_frame.pack(fill=tk.BOTH, expand=True)

        columns = ("id", "sku", "name", "description", "price", "link")
        self.tree = ttk.Treeview(self.tree_frame, columns=columns, show="headings", selectmode="browse", height=8)
        
        self.tree.heading("id", text="ID")
        self.tree.heading("sku", text="SKU")
        self.tree.heading("name", text="Name")
        self.tree.heading("description", text="Description")
        self.tree.heading("price", text="Price")
        self.tree.heading("link", text="Link")
        
        self.tree.column("id", width=60, anchor='center')
        self.tree.column("sku", width=100, anchor='center')
        self.tree.column("name", width=250, anchor='center')
        self.tree.column("description", width=200, anchor='center')
        self.tree.column("price", width=80, anchor='center')
        self.tree.column("link", width=100, anchor='center')
        
        vsb = ttk.Scrollbar(self.tree_frame, orient="vertical", command=self.tree.yview)
        self.tree.configure(yscrollcommand=vsb.set)
        
        self.tree.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        vsb.pack(side=tk.RIGHT, fill=tk.Y)
        
        self.tree.bind("<<TreeviewSelect>>", self.on_select)
        self.tree.bind("<ButtonRelease-1>", self.on_tree_click)

        # --- Bottom: Product Creator (New Feature) ---
        creator_frame = ttk.LabelFrame(self, text="1. Product Creator", padding="10")
        creator_frame.pack(fill=tk.X, padx=10, pady=10)
        
        # Grid Layout
        # Brand
        ttk.Label(creator_frame, text="Brand").grid(row=0, column=0, sticky="e", padx=5, pady=2)
        self.c_brand = tk.StringVar()
        self.c_brand.trace_add("write", self.update_preview)
        tk.Entry(creator_frame, textvariable=self.c_brand, width=30, bg='#FFF9C4', font=('Arial', 11), relief=tk.SOLID, borderwidth=1).grid(row=0, column=1, sticky="w", padx=5, pady=2)
        
        # Part #
        ttk.Label(creator_frame, text="Part #").grid(row=1, column=0, sticky="e", padx=5, pady=2)
        self.c_part = tk.StringVar()
        self.c_part.trace_add("write", self.update_preview)
        tk.Entry(creator_frame, textvariable=self.c_part, width=30, bg='#FFF9C4', font=('Arial', 11), relief=tk.SOLID, borderwidth=1).grid(row=1, column=1, sticky="w", padx=5, pady=2)
        
        # EAM #
        ttk.Label(creator_frame, text="EAM #").grid(row=2, column=0, sticky="e", padx=5, pady=2)
        self.c_eam = tk.StringVar()
        self.c_eam.trace_add("write", self.update_preview)
        tk.Entry(creator_frame, textvariable=self.c_eam, width=30, bg='#FFF9C4', font=('Arial', 11), relief=tk.SOLID, borderwidth=1).grid(row=2, column=1, sticky="w", padx=5, pady=2)
        
        # SKU (Auto)
        ttk.Label(creator_frame, text="SKU").grid(row=3, column=0, sticky="e", padx=5, pady=2)
        self.c_sku = tk.StringVar()
        tk.Entry(creator_frame, textvariable=self.c_sku, width=30, state="readonly", bg='#FFF9C4', font=('Arial', 11), relief=tk.SOLID, borderwidth=1).grid(row=3, column=1, sticky="w", padx=5, pady=2)
        
        # Preview
        ttk.Label(creator_frame, text="Preview:").grid(row=4, column=0, sticky="e", padx=5, pady=2)
        self.c_preview = tk.StringVar(value="EAM {number} / {BRAND} ({Part#})")
        ttk.Label(creator_frame, textvariable=self.c_preview, foreground="gray").grid(row=4, column=1, sticky="w", padx=5, pady=2)

        # Description (New)
        tk.Label(creator_frame, text="Description", bg='white', font=('Arial', 9)).grid(row=5, column=0, sticky="e", padx=5, pady=2)
        self.description_text = tk.Text(creator_frame, bg='#FFF9C4', font=('Arial', 10), relief=tk.SOLID, borderwidth=1, height=3, width=40)
        self.description_text.grid(row=5, column=1, sticky="w", padx=5, pady=2)

        # Image Upload (Row 6)
        ttk.Label(creator_frame, text="Image:").grid(row=6, column=0, sticky="e", padx=5, pady=2)
        
        img_frame = ttk.Frame(creator_frame)
        img_frame.grid(row=6, column=1, sticky="w", padx=5, pady=2)
        
        # Select Image (Gray)
        self.btn_select_img = tk.Button(img_frame, text="Select Image...", bg='#6c757d', fg='white', font=('Arial', 10), command=self.select_image)
        self.btn_select_img.pack(side=tk.LEFT)
        
        self.lbl_img_path = ttk.Label(img_frame, text="No file selected", foreground="gray")
        self.lbl_img_path.pack(side=tk.LEFT, padx=5)
        
        # Button (Row 7) - Create (Green)
        self.create_btn = tk.Button(creator_frame, text="Create Product Only", bg='#4CAF50', fg='white', font=('Arial', 10, 'bold'), command=self.create_item_async)
        self.create_btn.grid(row=7, column=1, sticky="w", pady=10)
        
        # Result Output (Row 8)
        self.result_var = tk.StringVar(value="")
        
        result_frame = ttk.Frame(creator_frame)
        result_frame.grid(row=8, column=0, columnspan=3, sticky="w", padx=30, pady=5)
        
        tk.Entry(result_frame, textvariable=self.result_var, width=45, state="readonly", bg='#f0f0f0', relief=tk.SOLID, borderwidth=1).pack(side=tk.LEFT, padx=(0, 10))
        
        self.btn_copy_link = tk.Button(result_frame, text="Copy Link", bg='#17a2b8', fg='white', font=('Arial', 9), command=self.copy_created_link)
        self.btn_copy_link.pack(side=tk.LEFT, padx=5)
        
        self.btn_copy_id = tk.Button(result_frame, text="Copy ID#", bg='#ffc107', fg='black', font=('Arial', 9), command=self.copy_created_id)
        self.btn_copy_id.pack(side=tk.LEFT, padx=5)

        # Status Bar
        self.status_bar = tk.Label(self, text="Ready | Hint: Ctrl+F to Search", bg='#f0f0f0', fg='#333333', font=('Arial', 9), anchor='w', relief=tk.SUNKEN, borderwidth=1)
        self.status_bar.pack(side=tk.BOTTOM, fill=tk.X)
        
        # Bind Hover
        for btn in [self.search_btn, self.btn_select_img, self.create_btn, self.btn_copy_link, self.btn_copy_id]:
             self.bind_hover(btn)
             
        # Bind Global Shortcut
        if hasattr(self.master, 'bind'):
             self.master.bind('<Control-f>', lambda e: self.search_entry.focus_set())

    def bind_hover(self, widget):
        orig_bg = widget['bg']
        def on_enter(e):
             if widget['state'] != 'disabled': e.widget['background'] = '#0056b3' if orig_bg == '#007bff' else '#218838' if orig_bg == '#28a745' else '#388E3C' if orig_bg == '#4CAF50' else '#5a6268' if orig_bg == '#6c757d' else '#138496' if orig_bg == '#17a2b8' else '#e0a800' if orig_bg == '#ffc107' else orig_bg
        def on_leave(e):
             if widget['state'] != 'disabled': e.widget['background'] = orig_bg
        widget.bind("<Enter>", on_enter)
        widget.bind("<Leave>", on_leave)

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

    def update_preview(self, *args):
        brand = self.c_brand.get().strip()
        part = self.c_part.get().strip()
        eam = self.c_eam.get().strip()
        
        # SKU Formula: Brand (first 4) + Part
        b_slug = brand[:4].lower() if len(brand) >= 4 else brand.lower()
        sku = f"{b_slug}{part}" if brand and part else ""
        self.c_sku.set(sku)
        
        # Name Formula: EAM {EAM} / {BRAND} ({PART})
        name = f"EAM {eam} / {brand.upper()} ({part})"
        self.c_preview.set(name)

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
                p.get('name'),
                p.get('description', ''),
                p.get('price'),
                "[Copy Link]"
            ))
            
        self.search_btn.config(state="normal")

    def on_select(self, event):
        # Used if we wanted to edit or verify, currently irrelevant for creation
        pass

    def _get_product_link(self, product_data):
        """Constructs or retrieves the full HTTPS link for a product gracefully."""
        if not product_data: return ""
        link = product_data.get('permalink')
        if not link:
            # Fallback construction using the base URL from bridge client
            base_url = "https://shop.gerharzequipment.com"
            if self.bridge.client and hasattr(self.bridge.client, 'base_url'):
                base_url = self.bridge.client.base_url
            
            # Remove any trailing slashes just in case
            base_url = base_url.rstrip('/')
            
            product_id = product_data.get('id', '')
            if product_id:
                link = f"{base_url}/?p={product_id}"
            else:
                sku = product_data.get('sku', '')
                if sku:
                    link = f"{base_url}/product/{sku}"
                else:
                    link = base_url
        return link

    def current_clipboard_copy(self, text, success_msg="Copied to clipboard."):
        """Helper to copy to clipboard handling exceptions gracefully."""
        if not text:
            messagebox.showwarning("Warning", "No data to copy.")
            return
        try:
            self.clipboard_clear()
            self.clipboard_append(text)
            messagebox.showinfo("Copied", success_msg)
        except Exception as e:
            messagebox.showerror("Clipboard Error", f"Could not access clipboard:\n{e}")

    def on_tree_click(self, event):
        region = self.tree.identify("region", event.x, event.y)
        if region == "cell":
            column = self.tree.identify_column(event.x)
            if column == "#6": # Link column
                item_id = self.tree.identify_row(event.y)
                if item_id:
                    product = self.products_cache.get(int(item_id)) or self.products_cache.get(str(item_id))
                    if product:
                        link = self._get_product_link(product)
                        self.current_clipboard_copy(link, f"Link copied to clipboard:\n{link}")

    def copy_created_link(self):
        if hasattr(self, 'last_created_product') and self.last_created_product:
            link = self._get_product_link(self.last_created_product)
            self.current_clipboard_copy(link, f"Product link copied:\n{link}")
        else:
            messagebox.showwarning("Warning", "No product created yet to copy link from.")

    def copy_created_id(self):
        if hasattr(self, 'last_created_product') and self.last_created_product:
            pid = self.last_created_product.get('id', '')
            self.current_clipboard_copy(str(pid), f"Product ID copied:\n{pid}")
        else:
            messagebox.showwarning("Warning", "No product created yet to copy ID from.")

    def create_item_async(self):
        brand = self.c_brand.get().strip()
        part = self.c_part.get().strip()
        eam = self.c_eam.get().strip()
        
        if not brand or not part or not eam:
            messagebox.showwarning("Warning", "All fields (Brand, Part, EAM) are required.")
            return

        sku = self.c_sku.get()
        name = self.c_preview.get()
        img_path = self.selected_image_path
        
        self.create_btn.config(state="disabled", text="Checking...")
        
        t = threading.Thread(target=self._create_thread, args=(sku, name, img_path), daemon=True)
        t.start()
        
    def _create_thread(self, sku, name, img_path):
        # 1. Pre-flight Check
        existing = self.bridge.search_products(sku)
        
        # Filter for exact SKU match because search can be fuzzy
        exact_match = None
        for p in existing:
            if str(p.get('sku')).lower() == sku.lower():
                exact_match = p
                break
        
        if exact_match:
            self.after(0, lambda: self.on_create_error(f"Item already exists: ID {exact_match['id']}"))
            return

        # 2. Upload Image (If Selected)
        image_id = None
        if img_path and os.path.exists(img_path):
            self.after(0, lambda: self.create_btn.config(text=f"Uploading Image as {sku}.jpg..."))
            image_id = self.bridge.upload_image_async(img_path, sku)
            if not image_id:
                print("Warning: Image Upload Failed")
            else:
                print(f"Image Uploaded: ID {image_id}")
                
        # 3. Create
        self.after(0, lambda: self.create_btn.config(text="Creating Product..."))
        
        # Get "Parts" category ID
        parts_id = self.bridge.get_category_id("Parts")
        cats = [{"id": parts_id}] if parts_id else []

        payload = {
            "name": name,
            "sku": sku,
            "type": "simple",
            "regular_price": "0.00",
            "categories": cats,
            "tags": [{"name": "EAM"}]
        }
        
        if image_id:
            payload["images"] = [{"id": image_id}]

        # Add Description to Payload
        description = self.description_text.get("1.0", tk.END).strip()
        if description:
             payload["description"] = description
        
        # Attempt creation
        new_product = self.bridge.create_product(payload)
        
        if new_product and new_product.get('id'):
            self.after(0, lambda: self.on_create_success(new_product))
        else:
            self.after(0, lambda: self.on_create_error("Failed to create product (API Error)"))

    def on_create_success(self, product):
        self.create_btn.config(state="normal", text="Create Product Only")
        self.last_created_product = product
        pid = product.get('id')
        link = self._get_product_link(product)
        
        self.result_var.set(f"Created! ID: {pid} | Link: {link}")
        messagebox.showinfo("Success", f"Product Created: {pid}")
        
    def on_create_error(self, msg):
        self.create_btn.config(state="normal", text="Create Product Only")
        messagebox.showerror("Error", msg)
        self.result_var.set(msg)

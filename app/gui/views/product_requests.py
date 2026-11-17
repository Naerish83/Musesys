import tkinter as tk
from tkinter import ttk, messagebox
import threading
import webbrowser
import io
import requests
from PIL import Image, ImageTk
from app.data.bridge import MuseDataBridge

SHOW_PR_ORDER_LINK = True

class ProductRequestView(ttk.Frame):
    def __init__(self, parent):
        super().__init__(parent)
        self.bridge = MuseDataBridge()
        self.requests_cache = []
        self.product_rows = []
        self.setup_ui()
        self.refresh_list_async()

    def setup_ui(self):
        # Header
        header = ttk.Frame(self, padding="10")
        header.pack(fill=tk.X)
        ttk.Label(header, text="Product Requests", font=("Arial", 12, "bold")).pack(side=tk.LEFT)
        
        # Refresh: Green #28a745
        self.btn_refresh = tk.Button(header, text="Refresh (Ctrl+R)", bg='#28a745', fg='white', font=('Arial', 10, 'bold'), command=self.manual_refresh)
        self.btn_refresh.pack(side=tk.RIGHT)

        # Main Split View
        self.paned = ttk.PanedWindow(self, orient=tk.HORIZONTAL)
        self.paned.pack(fill=tk.BOTH, expand=True, padx=10, pady=5)

        # Left: Request List
        list_frame = ttk.Frame(self.paned)
        self.paned.add(list_frame, weight=3)

        columns = ("id", "order", "status", "type", "user", "wo", "ship_to", "store", "asset", "brand", "model")
        display_columns = ["id", "status", "type", "user", "wo", "ship_to", "store", "asset", "brand", "model"]
        if SHOW_PR_ORDER_LINK:
            display_columns.insert(1, "order")

        self.tree = ttk.Treeview(list_frame, columns=columns, displaycolumns=display_columns, show="headings", selectmode="browse")
        
        self.tree.heading("id", text="ID")
        self.tree.heading("order", text="Order #")
        self.tree.heading("status", text="Status")
        self.tree.heading("type", text="Type")
        self.tree.heading("user", text="User")
        self.tree.heading("wo", text="WO #")
        self.tree.heading("ship_to", text="Ship-To")
        self.tree.heading("store", text="Store #")
        self.tree.heading("asset", text="Asset Tag")
        self.tree.heading("brand", text="Brand")
        self.tree.heading("model", text="Model")
        
        self.tree.column("id", width=90, anchor='center')
        self.tree.column("order", width=70, anchor='center')
        self.tree.column("status", width=80, anchor='center')
        self.tree.column("type", width=100, anchor='center')
        self.tree.column("user", width=120, anchor='center')
        self.tree.column("wo", width=80, anchor='center')
        self.tree.column("ship_to", width=100, anchor='center')
        self.tree.column("store", width=60, anchor='center')
        self.tree.column("asset", width=100, anchor='center')
        self.tree.column("brand", width=100, anchor='center')
        self.tree.column("model", width=100, anchor='center')

        # Scrollbar for tree
        v_scroll = ttk.Scrollbar(list_frame, orient=tk.VERTICAL, command=self.tree.yview)
        self.tree.configure(yscrollcommand=v_scroll.set)
        
        self.tree.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        v_scroll.pack(side=tk.RIGHT, fill=tk.Y)
        
        self.tree.bind("<<TreeviewSelect>>", self.on_selection_change)

        # Right panel becomes a vertical paned window
        right_paned = ttk.PanedWindow(self.paned, orient=tk.VERTICAL)
        self.paned.add(right_paned, weight=2)

        # TOP half — description + images (scrollable)
        top_frame = ttk.LabelFrame(right_paned, text="Request Details", padding="10")
        right_paned.add(top_frame, weight=1)

        top_canvas = tk.Canvas(top_frame, borderwidth=0)
        top_scrollbar = ttk.Scrollbar(top_frame, orient=tk.VERTICAL, command=top_canvas.yview)
        top_canvas.configure(yscrollcommand=top_scrollbar.set)
        top_scrollbar.pack(side=tk.RIGHT, fill=tk.Y)
        top_canvas.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)

        self.detail_inner = ttk.Frame(top_canvas)
        top_canvas.create_window((0, 0), window=self.detail_inner, anchor='nw')
        self.detail_inner.bind("<Configure>", lambda e: top_canvas.configure(
            scrollregion=top_canvas.bbox("all")))

        # Description + images go into self.detail_inner instead of detail_frame
        ttk.Label(self.detail_inner, text="Full Description / Notes:", font=("Arial", 10, "bold")).pack(anchor=tk.W)
        self.desc_text = tk.Text(self.detail_inner, height=6, width=30, wrap=tk.WORD,
            state="disabled", bg='#FFF9C4', font=('Arial', 11), relief=tk.SOLID, borderwidth=1)
        self.desc_text.pack(fill=tk.X, pady=(5, 10))

        ttk.Label(self.detail_inner, text="Attached Images:", font=("Arial", 10, "bold")).pack(anchor=tk.W)
        self.images_frame = ttk.Frame(self.detail_inner)
        self.images_frame.pack(fill=tk.X, pady=5)

        # BOTTOM half — fulfillment rows (scrollable)
        bottom_frame = ttk.LabelFrame(right_paned, text="Fulfillment", padding="10")
        right_paned.add(bottom_frame, weight=2)

        # Buttons dock at true bottom of bottom_frame, outside the canvas
        btn_frame = ttk.Frame(bottom_frame)
        btn_frame.pack(side=tk.BOTTOM, fill=tk.X, pady=(5, 0))

        self.btn_add_product = tk.Button(btn_frame, text="+ Add Product", 
            bg='#f0f0f0', fg='#333', font=('Arial', 9, 'bold'),
            command=self._add_fulfillment_row)
        self.btn_add_product.pack(side=tk.LEFT)

        self.btn_add_note = tk.Button(btn_frame, text="+ Add Note", 
            bg='#f0f0f0', fg='#333', font=('Arial', 9, 'bold'),
            command=self._add_note_row)
        self.btn_add_note.pack(side=tk.LEFT, padx=(5, 0))

        self.btn_save_send = tk.Button(btn_frame, text="Save + Send",
            bg='#0073aa', fg='white', font=('Arial', 10, 'bold'),
            command=lambda: self.complete_request_async('send'))
        # Packed conditionally in _update_action_buttons

        self.btn_complete_request = tk.Button(btn_frame, text="Complete Request",
            bg='#4CAF50', fg='white', font=('Arial', 10, 'bold'),
            command=lambda: self.complete_request_async('complete'))
        # Packed conditionally in _update_action_buttons

        bottom_canvas = tk.Canvas(bottom_frame, borderwidth=0)
        bottom_scrollbar = ttk.Scrollbar(bottom_frame, orient=tk.VERTICAL, command=bottom_canvas.yview)
        bottom_canvas.configure(yscrollcommand=bottom_scrollbar.set)
        bottom_scrollbar.pack(side=tk.RIGHT, fill=tk.Y)
        bottom_canvas.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)

        self.fulfillment_inner = ttk.Frame(bottom_canvas)
        bottom_canvas.create_window((0, 0), window=self.fulfillment_inner, anchor='nw')
        self.fulfillment_inner.bind("<Configure>", lambda e: bottom_canvas.configure(
            scrollregion=bottom_canvas.bbox("all")))
        
        # Status Bar
        self.status_bar = tk.Label(self, text="Ready | Hint: Ctrl+R to Refresh", bg='#f0f0f0', fg='#333333', font=('Arial', 9), anchor='w', relief=tk.SUNKEN, borderwidth=1)
        self.status_bar.pack(side=tk.BOTTOM, fill=tk.X)

        # Bind Button Hover
        for btn in [self.btn_refresh, self.btn_save_send, self.btn_complete_request]:
             self.bind_hover(btn)

        # Bind Global Shortcut
        # Ensure parent has bind method or access root
        if hasattr(self.master, 'bind'):
             self.master.bind('<Control-r>', lambda e: self.manual_refresh())

    def bind_hover(self, widget):
        def on_enter(e):
            if widget['state'] != 'disabled':
                current_bg = widget.cget('bg')
                hover_bg = '#0056b3' if current_bg == '#007bff' else '#218838' if current_bg == '#28a745' else '#388E3C' if current_bg == '#4CAF50' else '#cc7000' if current_bg == '#e67e00' else '#005a87' if current_bg == '#0073aa' else current_bg
                widget.hover_bg_save = current_bg # Store it dynamically
                e.widget['background'] = hover_bg
        def on_leave(e):
            if widget['state'] != 'disabled':
                e.widget['background'] = getattr(widget, 'hover_bg_save', widget.cget('bg'))
        widget.bind("<Enter>", on_enter)
        widget.bind("<Leave>", on_leave)

    def on_selection_change(self, event):
        selected = self.tree.selection()
        if not selected:
            return
        try:
            rid = int(selected[0])
        except ValueError:
            return

        req_data = next((r for r in self.requests_cache if int(r.get('id', 0)) == rid), None)
        if not req_data:
            return

        self.selected_type = req_data.get('type') or req_data.get('request_type', 'REQUEST')
        self._update_action_buttons()

        # Populate description
        full_desc = req_data.get('description', '')
        self.desc_text.config(state="normal")
        self.desc_text.delete(1.0, tk.END)
        self.desc_text.insert(tk.END, full_desc)
        self.desc_text.config(state="disabled")

        # Populate images
        for widget in self.images_frame.winfo_children():
            widget.destroy()
        image_links = req_data.get('images', [])
        if not image_links:
            ttk.Label(self.images_frame, text="(No images found)", foreground="gray").pack(anchor=tk.W)
        else:
            for i, link in enumerate(image_links):
                lbl = ttk.Label(self.images_frame, text=f"View Image {i+1}",
                    foreground="blue", cursor="hand2")
                lbl.pack(anchor=tk.W, pady=2)
                lbl.bind("<Button-1>", lambda e, url=link: self.show_image_modal(url))

        # Build per-item fulfillment rows
        self._build_fulfillment_rows(req_data)

    def _build_fulfillment_rows(self, req_data):
        # Clear existing rows
        for widget in self.fulfillment_inner.winfo_children():
            widget.destroy()
        self.product_rows = []

        items = req_data.get('items', [])
        print(f"DEBUG _build_fulfillment_rows: req_id={req_data.get('id')} items={items}")  # ADD THIS
        
        if not items:
            # Fallback: one blank row
            self._add_fulfillment_row()
            return

        for item in items:
            self._add_fulfillment_row(item)

    def _add_fulfillment_row(self, item=None):
        item = item or {}

        row = ttk.Frame(self.fulfillment_inner, relief=tk.GROOVE, borderwidth=1, padding=6)
        row.pack(fill=tk.X, pady=4, padx=4)

        # Item info header (read-only context)
        part  = item.get('part_num', '') or item.get('eam_num', '')
        wo    = item.get('wo_number', '')
        store = item.get('store_number', '')
        qty   = item.get('qty', 1)
        desc  = item.get('desc', '')

        info_text = f"Part: {part}  |  WO: {wo}  |  Store: {store}  |  Qty: {qty}"
        if desc:
            info_text += f"\n{desc}"

        ttk.Label(row, text=info_text, font=('Arial', 9), foreground='#444').pack(anchor=tk.W)

        # Fulfillment inputs
        input_row = ttk.Frame(row)
        input_row.pack(fill=tk.X, pady=(4, 0))

        ttk.Label(input_row, text="Product ID:").pack(side=tk.LEFT)
        id_var = tk.StringVar()
        tk.Entry(input_row, textvariable=id_var, width=12,
            bg='#FFF9C4', font=('Arial', 11), relief=tk.SOLID, borderwidth=1
        ).pack(side=tk.LEFT, padx=(4, 12))

        ttk.Label(input_row, text="Link:").pack(side=tk.LEFT)
        link_var = tk.StringVar()
        tk.Entry(input_row, textvariable=link_var, width=38,
            bg='#FFF9C4', font=('Arial', 11), relief=tk.SOLID, borderwidth=1
        ).pack(side=tk.LEFT, padx=(4, 12))

        self.product_rows.append({
            'frame': row,
            'id_var': id_var,
            'link_var': link_var,
            'item_index': len(self.product_rows)
        })

    def _add_note_row(self):
        row = ttk.Frame(self.fulfillment_inner, relief=tk.GROOVE, borderwidth=1, padding=6)
        row.pack(fill=tk.X, pady=4, padx=4)

        ttk.Label(row, text="(Admin Note)  |  Internal note for this request.", font=('Arial', 9, 'italic'), foreground='#444').pack(anchor=tk.W, pady=(0, 4))

        input_row = ttk.Frame(row)
        input_row.pack(fill=tk.X)

        note_var = tk.StringVar()
        tk.Entry(input_row, textvariable=note_var, width=65,
            bg='#FFF9C4', font=('Arial', 11), relief=tk.SOLID, borderwidth=1
        ).pack(side=tk.LEFT)

        self.product_rows.append({
            'frame': row,
            'note_var': note_var,
            'item_index': len(self.product_rows)
        })

    def _update_action_buttons(self):
        selected = getattr(self, 'selected_type', None)
        
        self.btn_save_send.pack_forget()
        self.btn_complete_request.pack_forget()
        
        if selected == 'DIRECT_ORDER':
            self.btn_save_send.pack(side=tk.RIGHT)
            self.btn_save_send.config(state="normal")
        elif selected == 'REQUEST':
            self.btn_complete_request.pack(side=tk.RIGHT)
            self.btn_complete_request.config(state="normal")

    def show_image_modal(self, url):
        modal = tk.Toplevel(self)
        modal.title("Product Image Viewer")
        modal.geometry("800x650")
        modal.transient(self.winfo_toplevel())
        
        lbl_status = ttk.Label(modal, text="Loading image from server...", font=("Arial", 11))
        lbl_status.pack(expand=True)
        
        def load_img():
            try:
                response = requests.get(url, timeout=10)
                response.raise_for_status()
                image_data = response.content
                img = Image.open(io.BytesIO(image_data))
                
                # Resize keeping aspect ratio
                max_size = (750, 550)
                img.thumbnail(max_size, Image.Resampling.LANCZOS)
                
                photo = ImageTk.PhotoImage(img)
                self.after(0, lambda: self._show_photo(modal, lbl_status, photo, url))
            except Exception as e:
                self.after(0, lambda: lbl_status.config(text=f"Failed to load image:\n{e}", foreground="red"))

        threading.Thread(target=load_img, daemon=True).start()

    def _show_photo(self, modal, lbl_status, photo, url):
        lbl_status.destroy()
        
        canvas = tk.Canvas(modal, bg="#f0f0f0", highlightthickness=0)
        canvas.pack(fill=tk.BOTH, expand=True, padx=10, pady=10)
        
        # Center image within the canvas
        canvas.update_idletasks()
        cw = canvas.winfo_width()
        ch = canvas.winfo_height()
        canvas.create_image(cw//2, ch//2, anchor=tk.CENTER, image=photo)
        canvas.image = photo # Keep reference so it's not garbage collected
        
        btn_frame = ttk.Frame(modal)
        btn_frame.pack(fill=tk.X, pady=10)
        
        btn = ttk.Button(btn_frame, text="Open Full in Browser", command=lambda: webbrowser.open(url))
        btn.pack()

    def manual_refresh(self):
        self.btn_refresh.config(state="disabled")
        self.refresh_list_async(callback=lambda: self.btn_refresh.config(state="normal"))

    def refresh_list_async(self, callback=None):
        t = threading.Thread(target=self._fetch_thread, args=(callback,), daemon=True)
        t.start()

    def _fetch_thread(self, callback):
        try:
            # Fetch requests
            data = self.bridge.get_product_requests()
            self.after(0, lambda: self.update_ui(data, callback))
        except Exception as e:
            print(f"Request Fetch Error: {e}")
            if callback:
                self.after(0, callback)

    def _format_ship_to(self, ship_to_raw):
        if not ship_to_raw: return ''
        if ship_to_raw.startswith('store:'):
            return f"Store {ship_to_raw.replace('store:', '')}"
        elif ship_to_raw.startswith('warehouse:'):
            return f"Warehouse {ship_to_raw.replace('warehouse:', '')}"
        return ship_to_raw

    def update_ui(self, data, callback=None):
        self.requests_cache = data
        
        for item in self.tree.get_children():
            self.tree.delete(item)
            
        for req in data:
            # Extract fields safely
            rid = req.get('id', '')
            status = req.get('status', 'pending')
            user = req.get('user', '') # Backend sends 'user' or 'requester'? Check this.
            
            # 1. Map keys exactly as the Backend sends them
            req_type = req.get('type') or req.get('request_type', 'REQUEST')
            wo = req.get('wo_number', '')      
            ship_to = self._format_ship_to(req.get('ship_to', ''))
            store = req.get('store_number', '')
            asset = req.get('asset_tag', '')   
            brand = req.get('brand', '')       
            model = req.get('model', '')       # CHANGED: Was 'summary', now 'model'
            fulfilled_order_id = req.get('fulfilled_order_id', '')

            display_id = str(rid)
            if wo:
                display_id = f"{rid} / WO-{wo}"
            
            if fulfilled_order_id:
                status = 'fulfilled'

            self.tree.insert("", "end", iid=rid, values=(display_id, fulfilled_order_id, status, req_type, user, wo, ship_to, store, asset, brand, model))

        if callback:
            callback()

    def complete_request_async(self, action='send_confirm'):
        selected = self.tree.selection()
        if not selected:
            messagebox.showwarning("Warning", "Please select a request first.")
            return

        rid = selected[0]
        
        products = []
        for row in self.product_rows:
            if 'note_var' in row:
                note = row['note_var'].get().strip()
                if note:
                    products.append({
                        'index': row['item_index'],
                        'desc': note
                    })
            else:
                pid = row['id_var'].get().strip()
                link = row['link_var'].get().strip()
                if pid or link:
                    products.append({
                        'index': row['item_index'],
                        'item_id': pid, 
                        'link': link
                    })

        if not products:
            messagebox.showwarning("Warning", "You must provide at least one Product ID, Link, or Note.")
            return
            
        self.btn_save_send.config(state="disabled")
        self.btn_complete_request.config(state="disabled")
        
        t = threading.Thread(target=self._complete_thread, args=(rid, products, action), daemon=True)
        t.start()

    def _complete_thread(self, rid, products, action='send_confirm'):
        try:
            success = self.bridge.complete_product_request(rid, products, action=action)
            self.after(0, lambda: self.on_complete_finished(success, rid))
        except Exception as e:
            print(f"Completion Error: {e}")
            self.after(0, lambda: self.on_complete_finished(False, rid))

    def on_complete_finished(self, success, rid):
        self._update_action_buttons()
        if success:
            messagebox.showinfo("Success", "Request completed successfully!")
            
            # Optimistic Update
            # Find the request in cache and update status
            req = next((r for r in self.requests_cache if str(r.get('id', '')) == str(rid)), None)
            if req:
                req['status'] = 'completed' # Or 'processing' if preferred by user request, using 'completed' to match logic
                self.refresh_table_row(req)

            # Reset rows
            for widget in self.fulfillment_inner.winfo_children():
                widget.destroy()
            self.product_rows = []
            
            # Re-build fulfillment rows using current cached data
            if req:
                self._build_fulfillment_rows(req)
            else:
                self._add_fulfillment_row()
            
            # Only trigger background sync if needed, or rely on optimistic update
            # self.manual_refresh() 
        else:
            messagebox.showerror("Error", "Failed to complete request.")

    def refresh_table_row(self, req):
        """Updates a single row in the treeview from the request object."""
        rid = req.get('id', '')
        if not self.tree.exists(rid):
            return
            
        status = req.get('status', 'pending')
        req_type = req.get('type') or req.get('request_type', 'REQUEST')
        user = req.get('user', '')
        wo = req.get('wo_number', '')      
        ship_to = self._format_ship_to(req.get('ship_to', ''))
        store = req.get('store_number', '')
        asset = req.get('asset_tag', '')   
        brand = req.get('brand', '')       
        model = req.get('model', '')
        fulfilled_order_id = req.get('fulfilled_order_id', '')

        display_id = str(rid)
        if wo:
            display_id = f"{rid} / WO-{wo}"
            
        if fulfilled_order_id:
            status = 'fulfilled'

        self.tree.item(rid, values=(display_id, fulfilled_order_id, status, req_type, user, wo, ship_to, store, asset, brand, model))

import tkinter as tk
from tkinter import ttk

class ScrollableFrame(ttk.Frame):
    def __init__(self, container, *args, **kwargs):
        super().__init__(container, *args, **kwargs)
        
        # Setup Canvas and Scrollbar
        self.canvas = tk.Canvas(self, borderwidth=0, highlightthickness=0)
        self.scrollbar = ttk.Scrollbar(self, orient="vertical", command=self.canvas.yview)
        
        # The Scrollable Frame
        self.scrollable_frame = ttk.Frame(self.canvas)
        
        self.scrollable_frame.bind(
            "<Configure>",
            lambda e: self.canvas.configure(
                scrollregion=self.canvas.bbox("all")
            )
        )
        
        # Window inside Canvas
        self.canvas_window = self.canvas.create_window((0, 0), window=self.scrollable_frame, anchor="nw")
        
        # Resize hook to constrain width
        self.canvas.bind("<Configure>", self._on_canvas_configure)

        self.canvas.configure(yscrollcommand=self.scrollbar.set)
        
        self.canvas.pack(side="left", fill="both", expand=True)
        self.scrollbar.pack(side="right", fill="y")
        
        # Binding Mousewheel
        self.bind_all_mousewheel(self.canvas, self.scrollable_frame)

    def _on_canvas_configure(self, event):
        # Resize the inner frame to match canvas width
        self.canvas.itemconfig(self.canvas_window, width=event.width)

    def bind_all_mousewheel(self, *widgets):
        # Recursively bind mousewheel to all children? 
        # For now just simple bind to canvas/frame.
        # Note: Linux uses Button-4/5, Windows uses MouseWheel. Assuming Windows as per user env.
        def _on_mousewheel(event):
            self.canvas.yview_scroll(int(-1*(event.delta/120)), "units")
        
        self.canvas.bind_all("<MouseWheel>", _on_mousewheel)

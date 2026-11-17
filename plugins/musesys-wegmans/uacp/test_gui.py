import sys
import os
import tkinter as tk

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from app.gui.views.order_ops import OrderOpsView

def dump_children(widget, level=0):
    res = "  " * level + str(widget) + f" ({type(widget).__name__})\n"
    for child in widget.winfo_children():
        res += dump_children(child, level+1)
    return res

root = tk.Tk()
view = OrderOpsView(root)
with open("widgets.txt", "w") as f:
    f.write(dump_children(root))
root.destroy()
print("Dumped tree to widgets.txt")

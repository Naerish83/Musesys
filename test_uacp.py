import tkinter as tk

root = tk.Tk()

rows = []
for i in range(2):
    id_var = tk.StringVar()
    link_var = tk.StringVar()
    rows.append((id_var, link_var))

rows[0][0].set("123")
rows[0][1].set("link1")
rows[1][0].set("456")
rows[1][1].set("link2")

for i, (id_var, link_var) in enumerate(rows):
    print(f"Row {i}: id={id_var.get()}, link={link_var.get()}")

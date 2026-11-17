import os
import glob

# The directory to scan
directory = "c:/Projects/musesys-mega"

# Order matters: Longest strings first to prevent partial matches
replacements = {
    # Work Orders
    "_muse_lineitem_workorder_note": "_muse_wo_note",
    "_muse_workorder_item_note": "_muse_wo_note",
    "_muse_lineitem_workorder": "_muse_wo_line",
    "_muse_global_work_order": "_muse_wo_global",
    "_primary_work_order": "_muse_wo_global",
    "_muse_workorder": "_muse_wo_global",

    # Install Stores
    "_muse_global_install_store": "_muse_store_global",
    "_musesys_install_store": "_muse_store_global",
    "_install_stores": "_muse_store_global",

    # Tracking & Shipping
    "_muse_latest_tracking_number": "_muse_track_primary",
    "_tracking_number": "_muse_track_primary",
    "_muse_shipping_status": "_muse_track_status",
    "_tracking_display": "_muse_track_status",
    "_tracking_type": "_muse_track_status",

    # Product Requests
    "_musesys_pr_status": "_muse_pr_status",
    "_musesys_pr_type": "_muse_pr_type",
    "_musesys_pr_id": "_muse_pr_id",
    
    # Labels
    "TRX Order #": "Work Order #"
}

def process_file(file_path):
    with open(file_path, "r", encoding="utf-8") as f:
        content = f.read()

    new_content = content
    for old_str, new_str in replacements.items():
        new_content = new_content.replace(old_str, new_str)

    if new_content != content:
        with open(file_path, "w", encoding="utf-8") as f:
            f.write(new_content)
        print(f"Updated: {file_path}")

# Note: os.walk includes all subdirectories
for root, dirs, files in os.walk(directory):
    for filename in files:
        if filename.endswith(".php") or filename.endswith(".js"):
            file_path = os.path.join(root, filename)
            # Skip the script itself and constants files if any exist
            if file_path.replace("\\", "/") == "c:/Projects/musesys-mega/string_refactor.py":
                continue
            if "musesys-core-constants" in file_path:
                continue
            try:
                process_file(file_path)
            except Exception as e:
                print(f"Error processing {file_path}: {e}")

print("String refactoring complete.")

import sys
import os
import json

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from app.data.bridge import MuseDataBridge

bridge = MuseDataBridge()
order = bridge.get_order("236577")
if order:
    print("Found Order!")
    # Let's inspect raw API if possible, bypassing models:
    data = bridge.client._get(f"orders/236577")
    for item in data.get("line_items", []):
        meta = item.get("meta_data", [])
        print(f"Line Item Meta: {json.dumps(meta, indent=2)}")
else:
    print("Order not found or API failed.")

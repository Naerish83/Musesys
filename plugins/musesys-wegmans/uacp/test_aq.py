import sys
import os
import json

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from app.data.bridge import MuseDataBridge

bridge = MuseDataBridge()
print("Testing AQ Export for Order 236577...")
try:
    data = bridge.export_aq_json_data(236577)
    if data:
        print("Success! Data received:")
        print(json.dumps(data, indent=2))
    else:
        print("Failed: No data returned.")
except Exception as e:
    print(f"Exception during export: {e}")

import sys
import os
import json
import traceback

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from app.data.bridge import MuseDataBridge

bridge = MuseDataBridge()

print(f"Base URL: {bridge.client.base_url}")
try:
    requests = bridge.get_product_requests()
    if requests:
        print(f"Found {len(requests)} product requests.")
        print(json.dumps(requests[0], indent=2))
    else:
        print("No product requests found (Empty List Returned)")
except Exception as e:
    print(f"Exception: {e}")
    traceback.print_exc()

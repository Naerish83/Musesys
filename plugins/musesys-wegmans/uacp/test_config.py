import sys
import os
import json

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from app.core.config import ConfigManager

settings = ConfigManager.load_settings()
print(json.dumps(settings, indent=2))

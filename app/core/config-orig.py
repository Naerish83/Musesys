import json
import os

CONFIG_FILE = "settings.json"

class ConfigManager:

    @staticmethod
    def load_settings():
        if not os.path.exists(CONFIG_FILE):
            # initialize clean structure on first run
            default = {
                "active_site": "ogwegmans",
                "sites": {
                    "ogwegmans": {
                        "api_url": "",
                        "consumer_key": "",
                        "consumer_secret": ""
                    },
                    "wegmans2": {
                        "api_url": "",
                        "consumer_key": "",
                        "consumer_secret": ""
                    }
                }
            }
            ConfigManager._write(default)
            return default

        try:
            with open(CONFIG_FILE, "r") as f:
                return json.load(f)
        except Exception:
            # fallback if corrupted
            return {
                "active_site": "ogwegmans",
                "sites": {
                    "ogwegmans": {},
                    "wegmans2": {}
                }
            }

    @staticmethod
    def save_settings(settings_dict):
        """
        Saves the full dictionary structure (supports multi-site).
        """
        try:
            ConfigManager._write(settings_dict)
            return True
        except Exception:
            return False

    @staticmethod
    def _write(data):
        with open(CONFIG_FILE, "w") as f:
            json.dump(data, f, indent=4)

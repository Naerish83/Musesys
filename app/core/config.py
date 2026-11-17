import json
import os

CONFIG_FILE = "settings.json"

class ConfigManager:

    @staticmethod
    def load_settings():
        import sys
        if getattr(sys, 'frozen', False):
            # If frozen (exe), look in the same directory as the executable
            base_path = os.path.dirname(sys.executable)
            config_path = os.path.join(base_path, CONFIG_FILE)
        else:
            config_path = CONFIG_FILE

        if not os.path.exists(config_path):
            # initialize clean structure on first run
            default = {
                "active_site": "ogwegmans",
                "sites": {
                    "ogwegmans": {
                        "api_url": "",
                        "consumer_key": "",
                        "consumer_secret": "",
                        "wp_username": "",
                        "wp_app_password": ""
                    },
                    "wegmans2": {
                        "api_url": "",
                        "consumer_key": "",
                        "consumer_secret": "",
                        "wp_username": "",
                        "wp_app_password": ""
                    }
                }
            }
            ConfigManager._write(default)
            return default

        try:
            with open(config_path, "r") as f:
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

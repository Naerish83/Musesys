"""
Shared updater configuration for the UACP desktop application.

This module centralizes install paths and remote update metadata so the
main app and updater helper stay aligned.
"""

from __future__ import annotations

import os
from pathlib import Path
from dotenv import load_dotenv

APP_NAME = "UACP"
COMPANY_NAME = "MuseSys"

LOCAL_APPDATA = Path(os.getenv("LOCALAPPDATA", Path.home() / "AppData" / "Local"))
INSTALL_DIR = LOCAL_APPDATA / COMPANY_NAME / APP_NAME
VERSION_FILE = INSTALL_DIR / "version.txt"
PENDING_DIR = INSTALL_DIR / "pending"
LOG_DIR = INSTALL_DIR / "logs"

# Load token from .env file sitting next to the installed exe
_env_file = INSTALL_DIR / ".env"
if _env_file.exists():
    load_dotenv(_env_file)

# GitHub PAT with read-only contents access to UACP repo
GITHUB_TOKEN = os.getenv("GITHUB_TOKEN", "")

UPDATE_BASE_URL = os.getenv(
    "UACP_UPDATE_BASE_URL",
    "https://raw.githubusercontent.com/Naerish83/UACP/main/"
)
UPDATE_URL = f"{UPDATE_BASE_URL.rstrip('/')}/version.json"

MAIN_EXE_NAME = f"{APP_NAME}.exe"
UPDATER_EXE_NAME = "updater.exe"
PENDING_EXE_NAME = f"{APP_NAME}_new.exe"
BACKUP_EXE_NAME = f"{APP_NAME}_backup.exe"
LOG_FILE = LOG_DIR / "updater.log"

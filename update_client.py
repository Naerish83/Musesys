"""
Client-side update checker for UACP.

Import `check_for_updates()` at the top of the main launcher. If a newer
build is available, this module downloads it into the pending folder,
launches the helper updater, and signals the caller to exit.
"""

from __future__ import annotations

import logging
import os
import subprocess
import sys
from pathlib import Path

import requests
from packaging.version import InvalidVersion, Version

from settings import (
    GITHUB_TOKEN,
    INSTALL_DIR,
    LOG_DIR,
    LOG_FILE,
    MAIN_EXE_NAME,
    PENDING_DIR,
    PENDING_EXE_NAME,
    UPDATE_URL,
    UPDATER_EXE_NAME,
    VERSION_FILE,
)


def _get_logger() -> logging.Logger:
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    logger = logging.getLogger("uacp_updater")
    if logger.handlers:
        return logger

    logger.setLevel(logging.INFO)
    handler = logging.FileHandler(LOG_FILE, encoding="utf-8")
    handler.setFormatter(logging.Formatter("%(asctime)s [%(levelname)s] %(message)s"))
    logger.addHandler(handler)
    logger.propagate = False
    return logger


def _ensure_layout() -> None:
    INSTALL_DIR.mkdir(parents=True, exist_ok=True)
    PENDING_DIR.mkdir(parents=True, exist_ok=True)
    LOG_DIR.mkdir(parents=True, exist_ok=True)


def _read_local_version() -> Version:
    if not VERSION_FILE.exists():
        return Version("0.0.0")

    raw_version = VERSION_FILE.read_text(encoding="utf-8").strip() or "0.0.0"
    try:
        return Version(raw_version)
    except InvalidVersion:
        return Version("0.0.0")


def _resolve_main_executable() -> Path:
    if getattr(sys, "frozen", False):
        return Path(sys.executable).resolve()
    return (INSTALL_DIR / MAIN_EXE_NAME).resolve()


def _resolve_updater_command() -> list[str] | None:
    installed_updater = (INSTALL_DIR / UPDATER_EXE_NAME).resolve()
    if installed_updater.exists():
        return [str(installed_updater)]

    repo_updater = Path(__file__).with_name("updater.py")
    if repo_updater.exists() and not getattr(sys, "frozen", False):
        return [sys.executable, str(repo_updater)]

    return None


def _fetch_remote_metadata(timeout: int = 5) -> dict:
    headers = {"Authorization": f"token {GITHUB_TOKEN}"} if GITHUB_TOKEN else {}
    response = requests.get(UPDATE_URL, headers=headers, timeout=timeout)
    response.raise_for_status()
    return response.json()


def _validate_download(path: Path) -> None:
    if not path.exists() or path.stat().st_size <= 0:
        raise ValueError("Downloaded update is empty.")

    with path.open("rb") as handle:
        header = handle.read(2)

    if header != b"MZ":
        raise ValueError("Downloaded update is not a valid Windows executable.")


def _download_update(download_url: str, destination: Path, timeout: int = 30) -> None:
    temp_path = destination.with_suffix(".part")
    if temp_path.exists():
        temp_path.unlink()

    headers = {"Authorization": f"token {GITHUB_TOKEN}", "Accept": "application/vnd.github+json"} if GITHUB_TOKEN else {}
    
    # Resolve asset ID via API instead of using the redirect URL directly
    # Extract version and filename from URL
    parts = download_url.split("/")
    tag = parts[-2]       # e.g. v2.0.6
    filename = parts[-1]  # e.g. UACP.exe
    
    # Hit the API to get the release details
    release_resp = requests.get(
        f"https://api.github.com/repos/Naerish83/UACP/releases/tags/{tag}",
        headers=headers,
        timeout=timeout
    )
    release_resp.raise_for_status()
    
    # Find our specific executable in the assets list
    assets = release_resp.json().get("assets", [])
    asset = next((a for a in assets if a["name"] == filename), None)
    
    if not asset:
        raise ValueError(f"Asset {filename} not found in release {tag}")
        
    # Download using the API asset URL
    dl_headers = {"Authorization": f"token {GITHUB_TOKEN}", "Accept": "application/octet-stream"} if GITHUB_TOKEN else {"Accept": "application/octet-stream"}
    
    # allow_redirects=True is crucial here. Requests will handle the jump to S3 smoothly.
    with requests.get(asset["url"], stream=True, headers=dl_headers, timeout=timeout, allow_redirects=True) as response:
        response.raise_for_status()
        with temp_path.open("wb") as handle:
            for chunk in response.iter_content(chunk_size=1024 * 64):
                if chunk:
                    handle.write(chunk)
                    
    _validate_download(temp_path)
    temp_path.replace(destination)


def _launch_updater(
    logger: logging.Logger,
    updater_command: list[str],
    target_exe: Path,
    new_exe: Path,
    new_version: str,
) -> bool:
    version_file = VERSION_FILE.resolve()
    command = [
        *updater_command,
        "--pid",
        str(os.getpid()),
        "--target-exe",
        str(target_exe),
        "--new-exe",
        str(new_exe),
        "--version-file",
        str(version_file),
        "--version",
        new_version,
    ]

    subprocess.Popen(command, cwd=str(INSTALL_DIR))
    logger.info("Updater launched successfully.")
    return True


def check_for_updates() -> bool:
    """
    Checks the remote version metadata and, if needed, stages an update.

    Returns True when an update has been started and the caller should exit.
    Returns False when there is no update or a failure occurred.
    """

    logger = _get_logger()
    _ensure_layout()

    try:
        logger.info("Startup: beginning update check.")
        local_version = _read_local_version()
        logger.info("Local version detected: %s", local_version)

        metadata = _fetch_remote_metadata()
        remote_version_text = str(metadata.get("version", "")).strip()
        download_url = str(metadata.get("download_url", "")).strip()

        if not remote_version_text or not download_url:
            raise ValueError("Remote metadata is missing version or download_url.")

        remote_version = Version(remote_version_text)
        logger.info("Remote version detected: %s", remote_version)

        if remote_version <= local_version:
            logger.info("No update required.")
            return False

        staged_exe = (PENDING_DIR / PENDING_EXE_NAME).resolve()
        if staged_exe.exists():
            staged_exe.unlink()

        logger.info("Downloading update from %s", download_url)
        _download_update(download_url, staged_exe)
        logger.info("Download completed: %s", staged_exe)

        updater_command = _resolve_updater_command()
        if not updater_command:
            raise FileNotFoundError("Updater helper was not found.")

        target_exe = _resolve_main_executable()
        started = _launch_updater(
            logger=logger,
            updater_command=updater_command,
            target_exe=target_exe,
            new_exe=staged_exe,
            new_version=remote_version_text,
        )
        return started

    except Exception as exc:
        logger.exception("Update check failed: %s", exc)
        return False
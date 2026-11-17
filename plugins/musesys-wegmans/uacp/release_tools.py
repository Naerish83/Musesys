"""
Release automation helpers for the UACP desktop updater workflow.

This script stamps a version, builds both executables, prepares a release
folder, and can optionally publish the generated files to a target folder.
"""

from __future__ import annotations

import json
import os
import stat
import shutil
import subprocess
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parent
DIST_DIR = ROOT / "dist"
RELEASES_DIR = ROOT / "releases"
VERSION_FILE = ROOT / "version.txt"
REMOTE_VERSION_FILE = ROOT / "version.json"
NOTES_FILE = ROOT / "notes.txt"
BUILD_ENV_DIR = ROOT / "build_env2"
BUILD_PYTHON = BUILD_ENV_DIR / "Scripts" / "python.exe"
BUILD_PIP = BUILD_ENV_DIR / "Scripts" / "pip.exe"
BUILD_PYINSTALLER = BUILD_ENV_DIR / "Scripts" / "pyinstaller.exe"


def run(command: list[str], cwd: Path | None = None) -> None:
    print(">>", " ".join(command))
    subprocess.run(command, cwd=str(cwd or ROOT), check=True)


def _on_rm_error(func, path, exc_info) -> None:
    os.chmod(path, stat.S_IWRITE)
    func(path)


def clean_build_artifacts() -> None:
    for path in (
        ROOT / "build" / "UACP",
        ROOT / "build" / "updater",
        DIST_DIR / "UACP.exe",
        DIST_DIR / "updater.exe",
    ):
        if path.is_dir():
            shutil.rmtree(path, onerror=_on_rm_error)
        elif path.exists():
            path.unlink()


def normalize_version(version: str) -> str:
    version = version.strip().lower()
    if version.startswith("v"):
        version = version[1:]
    parts = version.split(".")
    if len(parts) != 3 or not all(part.isdigit() for part in parts):
        raise ValueError("Version must be in the form 2.0.1")
    return ".".join(parts)


def ensure_build_env() -> None:
    if not BUILD_PYTHON.exists():
        run([sys.executable, "-m", "venv", str(BUILD_ENV_DIR)])
    
    # Always upgrade pip and install requirements to catch any newly added dependencies
    run([str(BUILD_PYTHON), "-m", "pip", "install", "--upgrade", "pip"])
    run([str(BUILD_PIP), "install", "-r", "requirements.txt"])


def write_version_files(version: str, base_url: str) -> dict:
    base_url = base_url.rstrip("/") + "/"
    metadata = {
        "version": version,
        "download_url": f"{base_url}UACP.exe",
        "notes_url": f"{base_url}notes.txt",
    }

    VERSION_FILE.write_text(f"{version}\n", encoding="utf-8")
    REMOTE_VERSION_FILE.write_text(json.dumps(metadata, indent=2) + "\n", encoding="utf-8")

    if not NOTES_FILE.exists():
        NOTES_FILE.write_text(f"UACP {version}\n", encoding="utf-8")

    return metadata


def build_binaries() -> None:
    clean_build_artifacts()
    run(["cmd", "/c", "build_main.bat"])
    run(["cmd", "/c", "build_updater.bat"])


def prepare_release(version: str, metadata: dict) -> Path:
    release_dir = RELEASES_DIR / version
    if release_dir.exists():
        shutil.rmtree(release_dir)

    (release_dir / "pending").mkdir(parents=True, exist_ok=True)
    (release_dir / "logs").mkdir(parents=True, exist_ok=True)

    shutil.copy2(DIST_DIR / "UACP.exe", release_dir / "UACP.exe")
    shutil.copy2(DIST_DIR / "updater.exe", release_dir / "updater.exe")
    shutil.copy2(VERSION_FILE, release_dir / "version.txt")
    shutil.copy2(REMOTE_VERSION_FILE, release_dir / "version.json")
    shutil.copy2(NOTES_FILE, release_dir / "notes.txt")

    print(f"Release prepared: {release_dir}")
    print(json.dumps(metadata, indent=2))
    return release_dir


def publish_release(release_dir: Path, publish_dir: Path) -> None:
    publish_dir.mkdir(parents=True, exist_ok=True)
    for file_name in ("UACP.exe", "updater.exe", "version.txt", "version.json", "notes.txt"):
        shutil.copy2(release_dir / file_name, publish_dir / file_name)
    print(f"Published release files to: {publish_dir}")


def git_commit_and_tag(version: str) -> None:
    """Commit version files, tag, and push. No phantom commits possible."""
    run(["git", "add", "-A"])
    run(["git", "commit", "-m", f"Release v{version}"])
    run(["git", "tag", "-f", f"v{version}"])
    run(["git", "push", "origin", "main", "--tags"])
    print(f"Git: committed, tagged v{version}, and pushed.")


def github_release(version: str, release_dir: Path) -> None:
    """Create GitHub release and upload all assets via gh CLI. No web UI."""
    assets = [
        str(release_dir / "UACP.exe"),
        str(release_dir / "updater.exe"),
        str(release_dir / "version.json"),
        str(release_dir / "notes.txt"),
    ]
    run([
        "gh", "release", "create", f"v{version}",
        *assets,
        "--title", f"v{version}",
        "--notes-file", str(release_dir / "notes.txt"),
    ])
    print(f"GitHub release v{version} created with all assets.")


def main(argv: list[str]) -> int:
    if len(argv) < 2:
        print("Usage: python release_tools.py <version> [publish_dir]")
        return 1

    version = normalize_version(argv[1])
    default_base_url = f"https://github.com/Naerish83/UACP/releases/download/v{version}/"
    base_url = os.getenv("UACP_UPDATE_BASE_URL", default_base_url)
    publish_dir_arg = argv[2] if len(argv) > 2 else os.getenv("UACP_PUBLISH_DIR", "").strip()

    ensure_build_env()
    metadata = write_version_files(version, base_url)
    build_binaries()
    release_dir = prepare_release(version, metadata)

    if publish_dir_arg:
        publish_release(release_dir, Path(publish_dir_arg).expanduser().resolve())

    git_commit_and_tag(version)
    github_release(version, release_dir)

    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
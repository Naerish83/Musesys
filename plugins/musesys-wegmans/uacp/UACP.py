"""
Launcher wrapper for the packaged UACP desktop application.

This file is the PyInstaller entrypoint used by build_main.bat. It performs
the silent update check first, then hands off to the importable main app
module so PyInstaller can analyze the dependency graph normally.
"""

from __future__ import annotations

from update_client import check_for_updates


def main() -> int:
    # Run updater BEFORE any heavy imports to prevent broken updates from locking the app.
    if check_for_updates():
        return 0

    # Lazy import so missing dependencies don't crash the updater.
    from uacp_main import main as run_main
    return int(run_main() or 0)


if __name__ == "__main__":
    raise SystemExit(main())

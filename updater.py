"""
Standalone updater helper for UACP.

This process runs separately from the main executable so it can safely
replace the PyInstaller one-file EXE after the main process exits.
"""

from __future__ import annotations

import logging
import shutil
import subprocess
import sys
import time
from pathlib import Path

from settings import BACKUP_EXE_NAME, INSTALL_DIR, LOG_DIR, LOG_FILE


def _get_logger() -> logging.Logger:
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    logger = logging.getLogger("uacp_updater_helper")
    if logger.handlers:
        return logger

    logger.setLevel(logging.INFO)
    handler = logging.FileHandler(LOG_FILE, encoding="utf-8")
    handler.setFormatter(logging.Formatter("%(asctime)s [%(levelname)s] %(message)s"))
    logger.addHandler(handler)
    logger.propagate = False
    return logger


def _parse_args(argv: list[str]) -> dict[str, str]:
    args: dict[str, str] = {}
    idx = 0
    while idx < len(argv):
        key = argv[idx]
        if key.startswith("--") and idx + 1 < len(argv):
            args[key[2:]] = argv[idx + 1]
            idx += 2
            continue
        idx += 1
    return args


def _process_is_running(pid: int) -> bool:
    if pid <= 0:
        return False

    result = subprocess.run(
        ["tasklist", "/FI", f"PID eq {pid}"],
        capture_output=True,
        text=True,
        check=False,
    )
    return str(pid) in result.stdout


def _wait_for_process_exit(logger: logging.Logger, pid: int, timeout_seconds: int = 60) -> None:
    logger.info("Waiting for main process %s to exit.", pid)
    start = time.time()
    while _process_is_running(pid):
        if time.time() - start > timeout_seconds:
            raise TimeoutError(f"Timed out waiting for process {pid} to exit.")
        time.sleep(1)
    logger.info("Main process has exited.")


def _write_version_file(version_file: Path, version_text: str) -> None:
    version_file.parent.mkdir(parents=True, exist_ok=True)
    version_file.write_text(f"{version_text}\n", encoding="utf-8")


def _restore_backup(logger: logging.Logger, backup_exe: Path, target_exe: Path) -> None:
    if not backup_exe.exists():
        return

    if target_exe.exists():
        target_exe.unlink()

    shutil.move(str(backup_exe), str(target_exe))
    logger.info("Rollback completed. Backup restored to %s", target_exe)


def main() -> int:
    logger = _get_logger()
    logger.info("Updater startup.")

    args = _parse_args(sys.argv[1:])
    try:
        pid = int(args.get("pid", "0"))
        target_exe = Path(args["target-exe"]).resolve()
        new_exe = Path(args["new-exe"]).resolve()
        version_file = Path(args["version-file"]).resolve()
        new_version = args["version"].strip()
    except Exception as exc:
        logger.exception("Invalid updater arguments: %s", exc)
        return 1

    backup_exe = target_exe.with_name(BACKUP_EXE_NAME)

    try:
        _wait_for_process_exit(logger, pid)

        if not new_exe.exists():
            raise FileNotFoundError(f"Pending executable not found: {new_exe}")

        if backup_exe.exists():
            backup_exe.unlink()

        if target_exe.exists():
            logger.info("Backing up current executable to %s", backup_exe)
            shutil.move(str(target_exe), str(backup_exe))

        logger.info("Replacing executable: %s -> %s", new_exe, target_exe)
        shutil.move(str(new_exe), str(target_exe))

        logger.info("Writing version file: %s", version_file)
        _write_version_file(version_file, new_version)

        if backup_exe.exists():
            backup_exe.unlink()

        logger.info("Restarting application: %s", target_exe)
        subprocess.Popen([str(target_exe)], cwd=str(target_exe.parent))
        logger.info("Restart event complete.")
        return 0

    except Exception as exc:
        logger.exception("Update failed: %s", exc)
        try:
            _restore_backup(logger, backup_exe, target_exe)
        except Exception as rollback_exc:
            logger.exception("Rollback failed: %s", rollback_exc)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())

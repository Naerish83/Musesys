@echo off
setlocal

REM Builds the standalone updater helper executable.
REM Run this from the project root after dependencies are installed.

if not exist build_env2\Scripts\python.exe (
    echo build_env2 is missing. Run build_release.bat to create the build environment.
    exit /b 1
)

call build_env2\Scripts\pyinstaller.exe --clean --noconfirm --onefile --name updater updater.py
if errorlevel 1 exit /b 1

endlocal

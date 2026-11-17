@echo off
setlocal

REM Builds the main UACP desktop executable.
REM Run this from the project root after dependencies are installed.

if not exist build_env2\Scripts\python.exe (
    echo build_env2 is missing. Run build_release.bat to create the build environment.
    exit /b 1
)

call build_env2\Scripts\pyinstaller.exe --clean --noconfirm --onefile --noconsole --name UACP --icon=M-icon.ico --add-data "M-icon.ico;." --hidden-import=requests --hidden-import=tkinter --hidden-import=tksheet --collect-submodules uacp UACP.py
if errorlevel 1 exit /b 1

endlocal

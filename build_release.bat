@echo off
setlocal

REM Usage:
REM   build_release.bat 2.0.1
REM   build_release.bat 2.0.1 "\\server\share\uacp"
REM
REM Optional environment variables:
REM   UACP_UPDATE_BASE_URL=https://github.com/Naerish83/UACP/releases/download/vVERSION/
REM   UACP_PUBLISH_DIR=\\server\share\uacp

if "%~1"=="" (
    echo Usage: build_release.bat VERSION [PUBLISH_DIR]
    exit /b 1
)

set RELEASE_VERSION=%~1
set RELEASE_PUBLISH_DIR=%~2

python release_tools.py %RELEASE_VERSION% %RELEASE_PUBLISH_DIR%
if errorlevel 1 exit /b 1

echo.
echo Release build completed for version %RELEASE_VERSION%.
endlocal

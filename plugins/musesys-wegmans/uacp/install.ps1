# UACP Installer Script
# Downloads and installs UACP from GitHub releases
# Run this once on any new machine

$VERSION = "2.0.6"
$INSTALL_DIR = "$env:LOCALAPPDATA\MuseSys\UACP"
$RELEASE_BASE = "https://github.com/Naerish83/UACP/releases/download/v$VERSION"

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  UACP Installer v$VERSION" -ForegroundColor Cyan
Write-Host "  MuseSys Internal Tool" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Step 1 - Create folder structure
Write-Host "Creating install directories..." -ForegroundColor Yellow
New-Item -ItemType Directory -Force -Path $INSTALL_DIR | Out-Null
New-Item -ItemType Directory -Force -Path "$INSTALL_DIR\logs" | Out-Null
New-Item -ItemType Directory -Force -Path "$INSTALL_DIR\pending" | Out-Null
Write-Host "  OK: $INSTALL_DIR" -ForegroundColor Green

# Step 2 - Prompt for GitHub token
Write-Host ""
Write-Host "Enter the UACP GitHub access token:" -ForegroundColor Yellow
Write-Host "(This is provided by your administrator)" -ForegroundColor Gray
$token = Read-Host "Token"

if ([string]::IsNullOrWhiteSpace($token)) {
    Write-Host "ERROR: Token cannot be empty." -ForegroundColor Red
    exit 1
}

# Step 3 - Write .env file
[System.IO.File]::WriteAllText(
    "$INSTALL_DIR\.env",
    "GITHUB_TOKEN=$token`n",
    [System.Text.UTF8Encoding]::new($false)
)
Write-Host "  OK: Token saved to .env" -ForegroundColor Green

# Step 4 - Download executables
Write-Host ""
Write-Host "Downloading UACP.exe..." -ForegroundColor Yellow
try {
    Invoke-WebRequest -Uri "$RELEASE_BASE/UACP.exe" -OutFile "$INSTALL_DIR\UACP.exe" -UseBasicParsing
    Write-Host "  OK: UACP.exe" -ForegroundColor Green
} catch {
    Write-Host "ERROR: Failed to download UACP.exe - $_" -ForegroundColor Red
    exit 1
}

Write-Host "Downloading updater.exe..." -ForegroundColor Yellow
try {
    Invoke-WebRequest -Uri "$RELEASE_BASE/updater.exe" -OutFile "$INSTALL_DIR\updater.exe" -UseBasicParsing
    Write-Host "  OK: updater.exe" -ForegroundColor Green
} catch {
    Write-Host "ERROR: Failed to download updater.exe - $_" -ForegroundColor Red
    exit 1
}

# Step 5 - Write version.txt
[System.IO.File]::WriteAllText(
    "$INSTALL_DIR\version.txt",
    "$VERSION`n",
    [System.Text.UTF8Encoding]::new($false)
)
Write-Host "  OK: version.txt set to $VERSION" -ForegroundColor Green

# Step 6 - Desktop shortcut
Write-Host ""
Write-Host "Creating desktop shortcut..." -ForegroundColor Yellow
$shell = New-Object -ComObject WScript.Shell
$shortcut = $shell.CreateShortcut("$env:USERPROFILE\Desktop\UACP.lnk")
$shortcut.TargetPath = "$INSTALL_DIR\UACP.exe"
$shortcut.WorkingDirectory = $INSTALL_DIR
$shortcut.Description = "MuseSys UACP"
$shortcut.Save()
Write-Host "  OK: Desktop shortcut created" -ForegroundColor Green

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Installation complete!" -ForegroundColor Green
Write-Host "  Launch UACP from your desktop." -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

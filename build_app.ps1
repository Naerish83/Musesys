$ErrorActionPreference = "Stop"
Write-Host "Creating fresh venv 'build_env2'..."
if (Test-Path "build_env2") { Remove-Item -Recurse -Force "build_env2" }
python -m venv build_env2

Write-Host "Installing dependencies (Heavy Mode)..."
.\build_env2\Scripts\python -m pip install --upgrade pip
.\build_env2\Scripts\pip install pandas numpy openpyxl requests Pillow "pydantic[email]" xlsxwriter pyinstaller

Write-Host "Running PyInstaller..."
# Using M-icon.ico from root as verified previously
.\build_env2\Scripts\pyinstaller --noconsole --onefile --name="Teammate 2.0" --clean --icon=M-icon.ico --hidden-import=requests --hidden-import=tkinter --hidden-import=app.gui.views.catalog_maintenance --collect-all pandas --collect-all pydantic uacp-v2.py

Write-Host "Build Complete."

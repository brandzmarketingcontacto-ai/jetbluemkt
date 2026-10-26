# Compila DiamantesExpress Agent en Windows 11.
# Requisitos: Python 3.12+ (64 bits) en PATH. Opcional: Inno Setup 6 para generar el instalador.
# Uso (PowerShell, en la carpeta diamantesexpress-agent):  .\packaging\build.ps1 [-Installer] [-SkipTests]
param([switch]$Installer, [switch]$SkipTests)
$ErrorActionPreference = "Stop"
Set-Location (Split-Path $PSScriptRoot -Parent)

if (-not (Test-Path .venv)) { python -m venv .venv }
. .\.venv\Scripts\Activate.ps1
python -m pip install --upgrade pip
python -m pip install -r requirements-dev.txt

if (-not $SkipTests) {
    python -m pytest -q
    if ($LASTEXITCODE -ne 0) { throw "Las pruebas fallaron; no se compila." }
}

python packaging\make_icon.py
pyinstaller packaging\dxagent.spec --noconfirm --clean
if ($LASTEXITCODE -ne 0) { throw "PyInstaller falló" }

& .\dist\DiamantesExpressAgent\dxagent-cli.exe doctor | Out-File -Encoding utf8 dist\doctor.json
Write-Host "Ejecutable: dist\DiamantesExpressAgent\DiamantesExpressAgent.exe"

if ($Installer) {
    $iscc = "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe"
    if (-not (Test-Path $iscc)) { throw "No se encontró Inno Setup 6 ($iscc)" }
    & $iscc packaging\installer.iss
    Write-Host "Instalador: dist\installer\DiamantesExpressAgent-Setup.exe"
}

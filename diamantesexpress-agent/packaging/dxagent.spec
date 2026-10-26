# -*- mode: python ; coding: utf-8 -*-
# PyInstaller: genera dist/DiamantesExpressAgent/ con DiamantesExpressAgent.exe (ventana: ui/service)
# y dxagent-cli.exe (consola: doctor, simulate, pair, set-secret...). Modo carpeta: arranque rápido.
# Uso:  pyinstaller packaging/dxagent.spec --noconfirm
import os
from pathlib import Path

ROOT = Path(SPECPATH).parent
SRC = ROOT / "src"
ICON = ROOT / "packaging" / "dxagent.ico"

datas = [(str(SRC / "dxagent" / "resources"), "dxagent/resources")]
hidden = [
    "keyring.backends.Windows", "keyring.backends.fail", "win32ctypes.core",
    "dxagent.ui.app", "dxagent.service.bootstrap",
]

a = Analysis(
    [str(ROOT / "packaging" / "entry.py")],
    pathex=[str(SRC)],
    datas=datas,
    hiddenimports=hidden,
    excludes=["tkinter", "PySide6.QtWebEngineCore", "PySide6.QtWebEngineWidgets", "PySide6.Qt3DCore",
              "PySide6.QtQuick", "PySide6.QtQml", "PySide6.QtMultimedia", "PySide6.QtPdf"],
    noarchive=False,
)
pyz = PYZ(a.pure)
exe = EXE(
    pyz, a.scripts, [],
    exclude_binaries=True,
    name="DiamantesExpressAgent",
    console=False,               # aplicación de ventana; los registros van a archivos
    icon=str(ICON) if ICON.exists() else None,
    version=None,
)
# Ejecutable de consola para diagnóstico y comandos (doctor, simulate, pair, set-secret…).
cli = EXE(
    pyz, a.scripts, [],
    exclude_binaries=True,
    name="dxagent-cli",
    console=True,
    icon=str(ICON) if ICON.exists() else None,
)
coll = COLLECT(exe, cli, a.binaries, a.datas, strip=False, upx=False, name="DiamantesExpressAgent")

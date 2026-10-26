"""Rutas de datos locales y recursos empaquetados."""

from __future__ import annotations

import os
import sys
from pathlib import Path

from dxagent import APP_ID


def is_windows() -> bool:
    return sys.platform == "win32"


def data_dir() -> Path:
    """Directorio de datos del agente (configurable con DXAGENT_HOME)."""
    override = os.environ.get("DXAGENT_HOME")
    if override:
        base = Path(override)
    elif is_windows():
        base = Path(os.environ.get("LOCALAPPDATA", Path.home() / "AppData" / "Local")) / APP_ID
    else:
        base = Path(os.environ.get("XDG_DATA_HOME", Path.home() / ".local" / "share")) / APP_ID
    base.mkdir(parents=True, exist_ok=True)
    return base


def sub_dir(name: str) -> Path:
    path = data_dir() / name
    path.mkdir(parents=True, exist_ok=True)
    return path


def logs_dir() -> Path:
    return sub_dir("logs")


def evidence_dir() -> Path:
    return sub_dir("evidence")


def user_config_dir() -> Path:
    """Configuración externa editable (workflows, precios, contrato OpenAPI)."""
    return sub_dir("config")


def db_path() -> Path:
    return data_dir() / "agent.db"


def resources_dir() -> Path:
    """Recursos empaquetados (funciona también dentro del EXE de PyInstaller)."""
    if getattr(sys, "frozen", False) and hasattr(sys, "_MEIPASS"):
        return Path(sys._MEIPASS) / "dxagent" / "resources"  # type: ignore[attr-defined]
    return Path(__file__).resolve().parent / "resources"


def resolve_config_file(relative: str) -> Path:
    """Busca primero en la configuración del usuario y luego en los recursos empaquetados."""
    user_file = user_config_dir() / relative
    if user_file.exists():
        return user_file
    return resources_dir() / relative


def emergency_flag_path() -> Path:
    return data_dir() / "EMERGENCY_STOP"

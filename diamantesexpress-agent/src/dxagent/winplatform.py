"""Integración con Windows: inicio automático, prevención de suspensión e instancia única."""

from __future__ import annotations

import logging
import os
import subprocess
import sys
from pathlib import Path

from dxagent import APP_ID

log = logging.getLogger(__name__)
RUN_KEY = r"Software\Microsoft\Windows\CurrentVersion\Run"

ES_CONTINUOUS = 0x80000000
ES_SYSTEM_REQUIRED = 0x00000001
ES_DISPLAY_REQUIRED = 0x00000002


def executable_command(*args: str) -> list[str]:
    """Comando para relanzar esta aplicación (EXE de PyInstaller o intérprete + módulo)."""
    if getattr(sys, "frozen", False):
        return [sys.executable, *args]
    return [sys.executable, "-m", "dxagent", *args]


def set_autostart(enabled: bool) -> bool:
    """Registra la interfaz (minimizada en la bandeja) en HKCU\\...\\Run. Solo Windows."""
    if sys.platform != "win32":
        log.info("Inicio automático solo disponible en Windows")
        return False
    import winreg  # type: ignore[import-not-found]
    command = subprocess.list2cmdline(executable_command("ui", "--minimized"))
    with winreg.OpenKey(winreg.HKEY_CURRENT_USER, RUN_KEY, 0, winreg.KEY_SET_VALUE) as key:
        if enabled:
            winreg.SetValueEx(key, APP_ID, 0, winreg.REG_SZ, command)
        else:
            try:
                winreg.DeleteValue(key, APP_ID)
            except FileNotFoundError:
                pass
    return True


def autostart_enabled() -> bool:
    if sys.platform != "win32":
        return False
    import winreg  # type: ignore[import-not-found]
    try:
        with winreg.OpenKey(winreg.HKEY_CURRENT_USER, RUN_KEY) as key:
            winreg.QueryValueEx(key, APP_ID)
            return True
    except OSError:
        return False


def prevent_sleep(enabled: bool) -> bool:
    """Evita la suspensión mientras el agente corre (solo si el usuario lo consintió en Configuración)."""
    if sys.platform != "win32":
        return False
    import ctypes
    flags = ES_CONTINUOUS | (ES_SYSTEM_REQUIRED | ES_DISPLAY_REQUIRED if enabled else 0)
    return bool(ctypes.windll.kernel32.SetThreadExecutionState(flags))


def has_interactive_desktop() -> bool:
    """BlueStacks necesita una sesión de usuario con escritorio. Detecta la sesión 0 (servicios)."""
    if sys.platform != "win32":
        return bool(os.environ.get("DISPLAY") or os.environ.get("WAYLAND_DISPLAY"))
    import ctypes
    from ctypes import wintypes
    session = wintypes.DWORD()
    ctypes.windll.kernel32.ProcessIdToSessionId(os.getpid(), ctypes.byref(session))
    return session.value != 0


class SingleInstanceLock:
    """Bloqueo por archivo para garantizar un único proceso del servicio."""

    def __init__(self, path: Path):
        self.path = path
        self._fh = None

    def acquire(self) -> bool:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self._fh = open(self.path, "a+")
        try:
            if sys.platform == "win32":
                import msvcrt
                self._fh.seek(0)
                msvcrt.locking(self._fh.fileno(), msvcrt.LK_NBLCK, 1)
            else:
                import fcntl
                fcntl.flock(self._fh.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            self._fh.close()
            self._fh = None
            return False
        self._fh.seek(0)
        self._fh.truncate()
        self._fh.write(str(os.getpid()))
        self._fh.flush()
        return True

    def release(self) -> None:
        if self._fh:
            try:
                if sys.platform == "win32":
                    import msvcrt
                    self._fh.seek(0)
                    msvcrt.locking(self._fh.fileno(), msvcrt.LK_UNLCK, 1)
                else:
                    import fcntl
                    fcntl.flock(self._fh.fileno(), fcntl.LOCK_UN)
            finally:
                self._fh.close()
                self._fh = None


def spawn_detached(args: list[str]) -> subprocess.Popen:
    """Inicia un proceso independiente de la ventana (sobrevive al cierre de la interfaz)."""
    kwargs: dict = {"close_fds": True, "stdin": subprocess.DEVNULL, "stdout": subprocess.DEVNULL,
                    "stderr": subprocess.DEVNULL}
    if sys.platform == "win32":
        kwargs["creationflags"] = 0x00000008 | 0x00000200 | 0x08000000  # DETACHED | NEW_GROUP | NO_WINDOW
    else:
        kwargs["start_new_session"] = True
    return subprocess.Popen(args, **kwargs)

"""BlueStacksManager: detección, inicio, supervisión y reinicio controlado de BlueStacks 5.

Las rutas, claves de registro y formato de `bluestacks.conf` usados aquí son heurísticas
de detección basadas en instalaciones habituales de BlueStacks 5 y DEBEN verificarse en la
PC Windows real (ver docs/PRUEBAS_WINDOWS.md). Todo es sobrescribible desde la configuración:
ruta de instalación, archivo de configuración, instancia y serial ADB.
No se asume resolución ni puerto ADB fijos: el puerto se lee de la configuración de la instancia.
"""

from __future__ import annotations

import asyncio
import logging
import re
import subprocess
import sys
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Callable

from dxagent.android.adb import ADBController, AdbRunner, connect_device, find_adb
from dxagent.android.device import DeviceError

log = logging.getLogger(__name__)

PLAYER_EXE = "HD-Player.exe"
REGISTRY_KEYS = (r"SOFTWARE\BlueStacks_nxt", r"SOFTWARE\WOW6432Node\BlueStacks_nxt")
DEFAULT_INSTALL_DIRS = (r"C:\Program Files\BlueStacks_nxt", r"C:\Program Files (x86)\BlueStacks_nxt")
DEFAULT_DATA_DIRS = (r"C:\ProgramData\BlueStacks_nxt",)
_CONF_RE = re.compile(r'^bst\.instance\.(?P<name>[^.]+)\.(?P<key>[\w.]+)="(?P<val>.*)"\s*$')


@dataclass
class BlueStacksInstance:
    name: str
    display_name: str = ""
    adb_port: int | None = None
    raw: dict[str, str] = field(default_factory=dict)


@dataclass
class BlueStacksInstallation:
    install_dir: str | None
    player_exe: str | None
    conf_path: str | None
    adb_path: str | None
    adb_enabled: bool | None
    instances: dict[str, BlueStacksInstance]

    @property
    def found(self) -> bool:
        return bool(self.player_exe)


def parse_conf(text: str) -> tuple[dict[str, BlueStacksInstance], bool | None]:
    instances: dict[str, BlueStacksInstance] = {}
    adb_enabled: bool | None = None
    for line in text.splitlines():
        line = line.strip()
        if line.startswith("bst.enable_adb_access="):
            adb_enabled = line.split("=", 1)[1].strip('"') == "1"
            continue
        match = _CONF_RE.match(line)
        if not match:
            continue
        inst = instances.setdefault(match["name"], BlueStacksInstance(match["name"]))
        inst.raw[match["key"]] = match["val"]
        if match["key"] == "display_name":
            inst.display_name = match["val"]
        elif match["key"] == "status.adb_port" and match["val"].isdigit():
            inst.adb_port = int(match["val"])
    return instances, adb_enabled


def _registry_values() -> dict[str, str]:
    if sys.platform != "win32":
        return {}
    import winreg  # type: ignore[import-not-found]
    values: dict[str, str] = {}
    for key_path in REGISTRY_KEYS:
        try:
            with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, key_path) as key:
                for name in ("InstallDir", "UserDefinedDir", "DataDir"):
                    try:
                        values.setdefault(name, str(winreg.QueryValueEx(key, name)[0]))
                    except OSError:
                        pass
        except OSError:
            continue
    return values


def detect_installation(install_dir: str = "", conf_path: str = "",
                        registry: Callable[[], dict[str, str]] = _registry_values) -> BlueStacksInstallation:
    reg = registry()
    dirs = [d for d in (install_dir, reg.get("InstallDir"), *DEFAULT_INSTALL_DIRS) if d]
    found_dir = next((d for d in dirs if (Path(d) / PLAYER_EXE).is_file()), None)
    confs = [c for c in (conf_path,
                         *(str(Path(d) / "bluestacks.conf") for d in (reg.get("UserDefinedDir"), reg.get("DataDir"), *DEFAULT_DATA_DIRS) if d))
             if c]
    found_conf = next((c for c in confs if Path(c).is_file()), None)
    instances: dict[str, BlueStacksInstance] = {}
    adb_enabled = None
    if found_conf:
        try:
            instances, adb_enabled = parse_conf(Path(found_conf).read_text(encoding="utf-8", errors="replace"))
        except OSError as exc:
            log.warning("No se pudo leer bluestacks.conf: %s", exc)
    return BlueStacksInstallation(
        install_dir=found_dir,
        player_exe=str(Path(found_dir) / PLAYER_EXE) if found_dir else None,
        conf_path=found_conf,
        adb_path=find_adb("", found_dir or ""),
        adb_enabled=adb_enabled,
        instances=instances,
    )


def _list_player_processes() -> list[dict[str, Any]]:
    import psutil
    result = []
    for proc in psutil.process_iter(["pid", "name", "cmdline", "status"]):
        try:
            if (proc.info["name"] or "").lower() == PLAYER_EXE.lower():
                result.append(dict(proc.info))
        except (psutil.NoSuchProcess, psutil.AccessDenied):
            continue
    return result


def _window_hung(pid: int) -> bool:
    """True si Windows considera que la ventana del proceso no responde."""
    if sys.platform != "win32":
        return False
    import ctypes
    from ctypes import wintypes
    user32 = ctypes.windll.user32
    hung = False

    @ctypes.WINFUNCTYPE(wintypes.BOOL, wintypes.HWND, wintypes.LPARAM)
    def callback(hwnd, _lparam):
        nonlocal hung
        owner = wintypes.DWORD()
        user32.GetWindowThreadProcessId(hwnd, ctypes.byref(owner))
        if owner.value == pid and user32.IsWindowVisible(hwnd) and user32.IsHungAppWindow(hwnd):
            hung = True
            return False
        return True

    user32.EnumWindows(callback, 0)
    return hung


class BlueStacksManager:
    name = "bluestacks"
    display_name = "BlueStacks 5"

    def __init__(self, install_dir: str = "", conf_path: str = "", instance: str = "", adb_path: str = "",
                 adb_serial: str = "", adb_timeout: float = 20.0,
                 process_lister: Callable[[], list[dict[str, Any]]] = _list_player_processes,
                 launcher: Callable[[list[str]], Any] | None = None,
                 registry: Callable[[], dict[str, str]] = _registry_values):
        self.install = detect_installation(install_dir, conf_path, registry)
        self.instance = instance
        self.adb_serial = adb_serial
        self._process_lister = process_lister
        self._launcher = launcher or self._default_launch
        adb = find_adb(adb_path, self.install.install_dir or "")
        self.runner = AdbRunner(adb, adb_timeout) if adb else None
        self.last_launch_at: float | None = None

    # ------------------------------------------------------------- información
    def status(self) -> dict[str, Any]:
        procs = self.player_processes()
        return {
            "emulator": self.name,
            "installed": self.install.found,
            "install_dir": self.install.install_dir,
            "conf_path": self.install.conf_path,
            "adb_path": self.runner.adb_path if self.runner else None,
            "adb_enabled": self.install.adb_enabled,
            "instances": {k: {"display_name": v.display_name, "adb_port": v.adb_port} for k, v in self.install.instances.items()},
            "selected_instance": self.instance,
            "running": bool(procs),
        }

    def instance_info(self) -> BlueStacksInstance | None:
        if self.instance:
            return self.install.instances.get(self.instance)
        if len(self.install.instances) == 1:
            return next(iter(self.install.instances.values()))
        return None

    def player_processes(self) -> list[dict[str, Any]]:
        procs = self._process_lister()
        if not self.instance:
            return procs
        matching = [p for p in procs if self.instance in " ".join(p.get("cmdline") or [])]
        # Si BlueStacks se abrió sin --instance (instancia por defecto) no podemos distinguirla.
        return matching or ([p for p in procs if "--instance" not in " ".join(p.get("cmdline") or [])] if procs else [])

    def is_running(self) -> bool:
        return bool(self.player_processes())

    def window_hung(self) -> bool:
        return any(_window_hung(int(p["pid"])) for p in self.player_processes() if p.get("pid"))

    # --------------------------------------------------------------- control
    def _default_launch(self, cmd: list[str]) -> Any:
        flags = 0
        if sys.platform == "win32":
            flags = 0x00000008 | 0x00000200  # DETACHED_PROCESS | CREATE_NEW_PROCESS_GROUP
        return subprocess.Popen(cmd, creationflags=flags, close_fds=True)

    def launch(self) -> None:
        if not self.install.player_exe:
            raise DeviceError("BlueStacks 5 no está instalado o no se encontró HD-Player.exe. Configure la ruta.")
        cmd = [self.install.player_exe]
        if self.instance:
            cmd += ["--instance", self.instance]
        log.info("Iniciando BlueStacks", extra={"cmd": cmd})
        self._launcher(cmd)
        self.last_launch_at = time.monotonic()

    def stop(self) -> None:
        import psutil
        for info in self.player_processes():
            try:
                psutil.Process(int(info["pid"])).terminate()
            except Exception as exc:
                log.warning("No se pudo cerrar BlueStacks pid=%s: %s", info.get("pid"), exc)

    async def connect(self) -> ADBController:
        if not self.runner:
            raise DeviceError("No se encontró adb (ni HD-Adb.exe de BlueStacks ni adb en PATH).")
        info = self.instance_info()
        return await connect_device(self.runner, self.adb_serial, info.adb_port if info else None)

    async def ensure_ready(self, boot_timeout: float = 180.0, poll: float = 3.0) -> ADBController:
        """Abre BlueStacks si está cerrado y espera a que Android termine de arrancar."""
        if not self.is_running():
            self.launch()
        deadline = time.monotonic() + boot_timeout
        last_error: Exception | None = None
        while time.monotonic() < deadline:
            try:
                device = await self.connect()
                if await device.boot_completed():
                    return device
            except DeviceError as exc:
                last_error = exc
            await asyncio.sleep(poll)
        raise DeviceError(f"Android no estuvo disponible en {boot_timeout:.0f}s: {last_error}")

    async def restart(self, is_safe: Callable[[], bool], boot_timeout: float = 180.0) -> ADBController:
        """Reinicio controlado. Se niega si hay una operación con resultado incierto."""
        if not is_safe():
            raise DeviceError("Reinicio del emulador bloqueado: hay una operación con resultado incierto")
        log.warning("Reiniciando BlueStacks de forma controlada")
        self.stop()
        await asyncio.sleep(5)
        return await self.ensure_ready(boot_timeout)

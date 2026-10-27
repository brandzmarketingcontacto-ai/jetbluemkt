"""LDPlayerManager: detección, inicio, supervisión y reinicio controlado de LDPlayer 9.

Usa la consola oficial de LDPlayer (`ldconsole.exe`, también llamada `dnconsole.exe`):
`list2` para listar instancias, `launch`/`quit` para iniciar y cerrar. Las rutas, la clave de
registro y el formato de `list2` son heurísticas de instalaciones habituales de LDPlayer 9 y DEBEN
verificarse en la PC Windows real (ver docs/PRUEBAS_WINDOWS.md). Todo es sobrescribible desde la
configuración: carpeta de instalación, instancia (índice o nombre) y serial ADB.

ADB: en LDPlayer hay que activar *Configuración → Otros ajustes → Depuración ADB → Abrir conexión local*.
La instancia N expone `emulator-(5554 + 2N)` / `127.0.0.1:(5555 + 2N)`; no se asume un único puerto.
"""

from __future__ import annotations

import asyncio
import logging
import subprocess
import sys
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Callable

from dxagent.android.adb import ADBController, AdbRunner, find_adb
from dxagent.android.device import DeviceDisconnected, DeviceError

log = logging.getLogger(__name__)

CONSOLE_EXES = ("ldconsole.exe", "dnconsole.exe")
PLAYER_EXE = "dnplayer.exe"
REGISTRY_KEYS = (r"Software\leidian\LDPlayer9", r"Software\leidian\ldplayer9", r"Software\leidian\ldplayer")
DEFAULT_INSTALL_DIRS = (r"C:\LDPlayer\LDPlayer9", r"D:\LDPlayer\LDPlayer9", r"C:\Program Files\LDPlayer\LDPlayer9")


@dataclass
class LDInstance:
    index: int
    name: str
    android_started: bool = False
    pid: int = 0
    width: int | None = None
    height: int | None = None
    raw: list[str] = field(default_factory=list)

    @property
    def adb_port(self) -> int:
        return 5555 + 2 * self.index

    @property
    def emulator_serial(self) -> str:
        return f"emulator-{5554 + 2 * self.index}"


def parse_list2(text: str) -> dict[int, LDInstance]:
    """`ldconsole list2`: índice,título,hwnd,hwnd_bind,android_iniciado,pid,pid_vbox[,ancho,alto,dpi]."""
    instances: dict[int, LDInstance] = {}
    for line in text.splitlines():
        parts = [p.strip() for p in line.strip().split(",")]
        if len(parts) < 7 or not parts[0].isdigit():
            continue

        def num(i: int) -> int:
            return int(parts[i]) if len(parts) > i and parts[i].lstrip("-").isdigit() else 0
        inst = LDInstance(index=int(parts[0]), name=parts[1], android_started=num(4) == 1, pid=max(num(5), 0),
                          width=num(7) or None, height=num(8) or None, raw=parts)
        instances[inst.index] = inst
    return instances


def _registry_install_dir() -> str | None:
    if sys.platform != "win32":
        return None
    import winreg  # type: ignore[import-not-found]
    for hive in (winreg.HKEY_CURRENT_USER, winreg.HKEY_LOCAL_MACHINE):
        for key_path in REGISTRY_KEYS:
            try:
                with winreg.OpenKey(hive, key_path) as key:
                    return str(winreg.QueryValueEx(key, "InstallDir")[0])
            except OSError:
                continue
    return None


def _decode(data: bytes) -> str:
    for encoding in ("utf-8", "mbcs", "gbk"):
        try:
            return data.decode(encoding)
        except (LookupError, UnicodeDecodeError):
            continue
    return data.decode("utf-8", errors="replace")


def _default_console_runner(console: str, args: list[str], timeout: float = 30.0) -> str:
    kwargs: dict[str, Any] = {}
    if sys.platform == "win32":
        kwargs["creationflags"] = 0x08000000  # CREATE_NO_WINDOW
    proc = subprocess.run([console, *args], capture_output=True, timeout=timeout, **kwargs)
    return _decode(proc.stdout)


class LDPlayerManager:
    name = "ldplayer"
    display_name = "LDPlayer 9"

    def __init__(self, install_dir: str = "", instance: str = "", adb_path: str = "", adb_serial: str = "",
                 adb_timeout: float = 20.0, console_runner: Callable[[str, list[str]], str] | None = None,
                 registry: Callable[[], str | None] = _registry_install_dir):
        dirs = [d for d in (install_dir, registry(), *DEFAULT_INSTALL_DIRS) if d]
        self.install_dir = next((d for d in dirs if any((Path(d) / exe).is_file() for exe in CONSOLE_EXES)), None)
        self.console = None
        if self.install_dir:
            self.console = next(str(Path(self.install_dir) / exe) for exe in CONSOLE_EXES
                                if (Path(self.install_dir) / exe).is_file())
        self.instance = instance.strip()
        self.adb_serial = adb_serial
        self._run_console = console_runner or _default_console_runner
        adb = find_adb(adb_path, self.install_dir or "")
        self.runner = AdbRunner(adb, adb_timeout) if adb else None
        self.last_launch_at: float | None = None

    # ------------------------------------------------------------- información
    @property
    def installed(self) -> bool:
        return self.console is not None

    def instances(self) -> dict[int, LDInstance]:
        if not self.console:
            return {}
        try:
            return parse_list2(self._run_console(self.console, ["list2"]))
        except (OSError, subprocess.SubprocessError) as exc:
            log.warning("ldconsole list2 falló: %s", exc)
            return {}

    def instance_info(self, instances: dict[int, LDInstance] | None = None) -> LDInstance | None:
        instances = self.instances() if instances is None else instances
        if self.instance:
            if self.instance.isdigit() and int(self.instance) in instances:
                return instances[int(self.instance)]
            return next((i for i in instances.values() if i.name == self.instance), None)
        if len(instances) == 1:
            return next(iter(instances.values()))
        return instances.get(0)  # instancia principal de LDPlayer

    def _target_args(self, info: LDInstance | None) -> list[str]:
        if info is not None:
            return ["--index", str(info.index)]
        if self.instance.isdigit():
            return ["--index", self.instance]
        if self.instance:
            return ["--name", self.instance]
        return ["--index", "0"]

    def status(self) -> dict[str, Any]:
        instances = self.instances()
        info = self.instance_info(instances)
        return {
            "emulator": self.name,
            "installed": self.installed,
            "install_dir": self.install_dir,
            "console": self.console,
            "adb_path": self.runner.adb_path if self.runner else None,
            "adb_enabled": None,  # LDPlayer no lo expone por consola: verificar en Configuración → Otros ajustes
            "instances": {str(i.index): {"display_name": i.name, "adb_port": i.adb_port,
                                         "serial": i.emulator_serial, "running": i.android_started or i.pid > 0}
                          for i in instances.values()},
            "selected_instance": self.instance or (str(info.index) if info else ""),
            "running": bool(info and (info.android_started or info.pid > 0)),
        }

    def is_running(self) -> bool:
        info = self.instance_info()
        return bool(info and (info.android_started or info.pid > 0))

    def window_hung(self) -> bool:
        from dxagent.android.bluestacks import _window_hung
        info = self.instance_info()
        return bool(info and info.pid and _window_hung(info.pid))

    # --------------------------------------------------------------- control
    def launch(self) -> None:
        if not self.console:
            raise DeviceError("LDPlayer 9 no está instalado o no se encontró ldconsole.exe. Configure la carpeta.")
        args = ["launch", *self._target_args(self.instance_info())]
        log.info("Iniciando LDPlayer", extra={"args": args})
        self._run_console(self.console, args)
        self.last_launch_at = time.monotonic()

    def stop(self) -> None:
        if self.console:
            self._run_console(self.console, ["quit", *self._target_args(self.instance_info())])

    async def connect(self) -> ADBController:
        if not self.runner:
            raise DeviceError("No se encontró adb (ni adb.exe de LDPlayer ni adb en PATH).")
        if self.adb_serial:
            if ":" in self.adb_serial:
                await self.runner.connect(self.adb_serial)
            return ADBController(self.runner, self.adb_serial)
        info = self.instance_info()
        if info is None:
            raise DeviceDisconnected("No se encontró la instancia de LDPlayer configurada")
        serials = {d.serial for d in await self.runner.devices() if d.state == "device"}
        if info.emulator_serial in serials:
            return ADBController(self.runner, info.emulator_serial)
        target = f"127.0.0.1:{info.adb_port}"
        await self.runner.connect(target)
        return ADBController(self.runner, target)

    async def ensure_ready(self, boot_timeout: float = 180.0, poll: float = 3.0) -> ADBController:
        """Abre LDPlayer si está cerrado y espera a que Android termine de arrancar."""
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
        log.warning("Reiniciando LDPlayer de forma controlada")
        self.stop()
        await asyncio.sleep(5)
        return await self.ensure_ready(boot_timeout)

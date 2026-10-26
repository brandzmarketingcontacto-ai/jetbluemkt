"""ADBController: control de Android mediante ADB, sin shell intermedio.

Todos los comandos se construyen a partir de plantillas fijas (lista de argumentos, nunca
shell=True). La IA no puede enviar comandos: solo el motor de flujo invoca estos métodos
después de pasar por ExecutionPolicy.
"""

from __future__ import annotations

import asyncio
import logging
import re
import shutil
import sys
from dataclasses import dataclass
from pathlib import Path

from dxagent.android.device import (ALLOWED_KEYS, ACTIVITY_RE, DeviceDisconnected, DeviceError, DeviceTimeout,
                                    UnauthorizedOperation, validate_package, validate_text)

log = logging.getLogger(__name__)
_SERIAL_RE = re.compile(r"^[A-Za-z0-9_.:\-]+$")


@dataclass
class AdbDevice:
    serial: str
    state: str
    description: str = ""


def find_adb(configured: str = "", bluestacks_dir: str = "") -> str | None:
    """Busca adb: ruta configurada > HD-Adb.exe de BlueStacks > adb del PATH."""
    candidates: list[Path] = []
    if configured:
        candidates.append(Path(configured))
    if bluestacks_dir:
        candidates += [Path(bluestacks_dir) / "HD-Adb.exe", Path(bluestacks_dir) / "adb.exe"]
    for candidate in candidates:
        if candidate.is_file():
            return str(candidate)
    found = shutil.which("adb")
    return found


class AdbRunner:
    """Ejecuta adb con timeout y clasificación de errores."""

    def __init__(self, adb_path: str | list[str], timeout: float = 20.0):
        # adb_path puede ser una ruta o un prefijo de comando (p. ej. un adb falso en pruebas).
        self.command = [adb_path] if isinstance(adb_path, str) else list(adb_path)
        self.adb_path = self.command[0]
        self.timeout = timeout

    async def run(self, *args: str, serial: str | None = None, timeout: float | None = None,
                  binary: bool = False) -> bytes | str:
        cmd = list(self.command)
        if serial:
            cmd += ["-s", serial]
        cmd += list(args)
        kwargs = {}
        if sys.platform == "win32":
            kwargs["creationflags"] = 0x08000000  # CREATE_NO_WINDOW
        try:
            proc = await asyncio.create_subprocess_exec(*cmd, stdout=asyncio.subprocess.PIPE,
                                                        stderr=asyncio.subprocess.PIPE, **kwargs)
        except FileNotFoundError as exc:
            raise DeviceError(f"No se encontró adb en {self.adb_path}") from exc
        try:
            out, err = await asyncio.wait_for(proc.communicate(), timeout or self.timeout)
        except asyncio.TimeoutError as exc:
            proc.kill()
            await proc.wait()
            raise DeviceTimeout(f"adb {' '.join(args[:2])} superó el tiempo límite") from exc
        stderr = err.decode(errors="replace").strip()
        if proc.returncode != 0:
            low = stderr.lower()
            if "not found" in low or "offline" in low or "no devices" in low or "unauthorized" in low:
                raise DeviceDisconnected(stderr or "dispositivo no disponible")
            raise DeviceError(f"adb falló ({proc.returncode}): {stderr[:300]}")
        return out if binary else out.decode(errors="replace")

    async def devices(self) -> list[AdbDevice]:
        text = await self.run("devices", "-l")
        result = []
        for line in str(text).splitlines()[1:]:
            parts = line.split()
            if len(parts) >= 2:
                result.append(AdbDevice(parts[0], parts[1], " ".join(parts[2:])))
        return result

    async def connect(self, host_port: str) -> bool:
        if not _SERIAL_RE.match(host_port):
            raise UnauthorizedOperation("Dirección ADB inválida")
        text = str(await self.run("connect", host_port))
        return "connected" in text.lower() and "cannot" not in text.lower()


class ADBController:
    def __init__(self, runner: AdbRunner, serial: str):
        if not _SERIAL_RE.match(serial):
            raise UnauthorizedOperation("Serial ADB inválido")
        self.runner = runner
        self.serial = serial

    async def _shell(self, *args: str, timeout: float | None = None) -> str:
        return str(await self.runner.run("shell", *args, serial=self.serial, timeout=timeout))

    async def is_responsive(self) -> bool:
        try:
            return (await self._shell("echo", "ok", timeout=8)).strip() == "ok"
        except DeviceError:
            return False

    async def boot_completed(self) -> bool:
        try:
            return (await self._shell("getprop", "sys.boot_completed", timeout=8)).strip() == "1"
        except DeviceError:
            return False

    async def screen_size(self) -> tuple[int, int]:
        text = await self._shell("wm", "size")
        override = re.search(r"Override size:\s*(\d+)x(\d+)", text)
        physical = re.search(r"Physical size:\s*(\d+)x(\d+)", text)
        match = override or physical
        if not match:
            raise DeviceError(f"No se pudo leer la resolución: {text[:100]}")
        w, h = int(match.group(1)), int(match.group(2))
        if await self.orientation() in (1, 3) and w < h:
            w, h = h, w
        return w, h

    async def orientation(self) -> int:
        text = await self._shell("dumpsys", "input")
        match = re.search(r"SurfaceOrientation:\s*(\d)", text)
        return int(match.group(1)) if match else 0

    async def screenshot_png(self) -> bytes:
        data = await self.runner.run("exec-out", "screencap", "-p", serial=self.serial, binary=True)
        assert isinstance(data, bytes)
        if not data.startswith(b"\x89PNG"):
            raise DeviceError("La captura de pantalla no es un PNG válido")
        return data

    async def dump_ui(self) -> str | None:
        try:
            await self._shell("uiautomator", "dump", "/sdcard/dx_ui.xml", timeout=15)
            text = str(await self.runner.run("exec-out", "cat", "/sdcard/dx_ui.xml", serial=self.serial))
            return text if "<hierarchy" in text else None
        except DeviceTimeout:
            raise
        except DeviceError:
            return None  # p. ej. juegos con OpenGL no exponen jerarquía

    async def tap(self, x: int, y: int) -> None:
        await self._shell("input", "tap", str(int(x)), str(int(y)))

    async def swipe(self, x1: int, y1: int, x2: int, y2: int, ms: int = 300) -> None:
        await self._shell("input", "swipe", *(str(int(v)) for v in (x1, y1, x2, y2, ms)))

    async def input_text(self, text: str) -> None:
        validate_text(text)
        await self._shell("input", "text", text.replace(" ", "%s"))

    async def keyevent(self, key: str) -> None:
        if key not in ALLOWED_KEYS:
            raise UnauthorizedOperation(f"Tecla no permitida: {key}")
        await self._shell("input", "keyevent", ALLOWED_KEYS[key])

    async def current_package(self) -> str | None:
        text = await self._shell("dumpsys", "window")
        for pattern in (r"mCurrentFocus=Window\{[^}]*\s([\w.]+)/", r"mFocusedApp=.*?\s([\w.]+)/"):
            match = re.search(pattern, text)
            if match:
                return match.group(1)
        return None

    async def start_app(self, package: str, activity: str | None = None) -> None:
        validate_package(package)
        if activity:
            if not ACTIVITY_RE.match(activity):
                raise UnauthorizedOperation(f"Actividad inválida: {activity}")
            await self._shell("am", "start", "-n", f"{package}/{activity}")
        else:
            await self._shell("monkey", "-p", package, "-c", "android.intent.category.LAUNCHER", "1")

    async def is_package_installed(self, package: str) -> bool:
        validate_package(package)
        try:
            return "package:" in await self._shell("pm", "path", package)
        except DeviceDisconnected:
            raise
        except DeviceError:
            return False


async def connect_device(runner: AdbRunner, preferred_serial: str = "", adb_port: int | None = None) -> ADBController:
    """Selecciona el dispositivo correcto: serial configurado > puerto de la instancia > único conectado."""
    if preferred_serial:
        if ":" in preferred_serial:
            await runner.connect(preferred_serial)
        return ADBController(runner, preferred_serial)
    if adb_port:
        target = f"127.0.0.1:{adb_port}"
        await runner.connect(target)
        return ADBController(runner, target)
    devices = [d for d in await runner.devices() if d.state == "device"]
    if len(devices) == 1:
        return ADBController(runner, devices[0].serial)
    if not devices:
        raise DeviceDisconnected("No hay dispositivos Android conectados por ADB")
    raise DeviceError("Hay varios dispositivos conectados; configure el serial ADB: "
                      + ", ".join(d.serial for d in devices))

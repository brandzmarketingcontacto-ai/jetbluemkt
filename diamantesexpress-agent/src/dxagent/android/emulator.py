"""Selección del emulador Android: BlueStacks 5 o LDPlayer 9 (misma interfaz para el agente)."""

from __future__ import annotations

from typing import Any, Protocol

EMULATORS = {"bluestacks": "BlueStacks 5", "ldplayer": "LDPlayer 9"}


class EmulatorManager(Protocol):
    name: str
    display_name: str

    def status(self) -> dict[str, Any]: ...
    def is_running(self) -> bool: ...
    def window_hung(self) -> bool: ...
    def launch(self) -> None: ...
    def stop(self) -> None: ...
    async def connect(self): ...
    async def ensure_ready(self, boot_timeout: float = 180.0, poll: float = 3.0): ...
    async def restart(self, is_safe, boot_timeout: float = 180.0): ...


def build_emulator(settings, emulator: str | None = None) -> EmulatorManager:
    """Crea el gestor del emulador elegido en la configuración (o el indicado)."""
    kind = (emulator or settings.emulator).lower()
    if kind == "ldplayer":
        from dxagent.android.ldplayer import LDPlayerManager
        return LDPlayerManager(settings.ldplayer_install_dir, settings.emulator_instance, settings.adb_path,
                               settings.adb_serial, settings.adb_command_timeout_seconds)
    if kind == "bluestacks":
        from dxagent.android.bluestacks import BlueStacksManager
        return BlueStacksManager(settings.bluestacks_install_dir, settings.bluestacks_conf_path,
                                 settings.emulator_instance, settings.adb_path, settings.adb_serial,
                                 settings.adb_command_timeout_seconds)
    raise ValueError(f"Emulador no soportado: {emulator}. Opciones: {', '.join(EMULATORS)}")

"""Construcción del servicio a partir de la configuración y arranque del proceso sin interfaz."""

from __future__ import annotations

import asyncio
import logging
import signal
import sys

from dxagent import paths
from dxagent.android.bluestacks import BlueStacksManager
from dxagent.audit import AuditLog
from dxagent.android.mock_device import MockDevice
from dxagent.database import LocalDatabase
from dxagent.logging_setup import setup_logging
from dxagent.service.agent import AgentService
from dxagent.settings import SettingsManager
from dxagent.winplatform import SingleInstanceLock, has_interactive_desktop, prevent_sleep

log = logging.getLogger(__name__)


def build_service(manager: SettingsManager | None = None) -> AgentService:
    manager = manager or SettingsManager()
    settings = manager.load()
    db = LocalDatabase(paths.db_path(), audit=AuditLog(paths.logs_dir() / "audit.jsonl"))
    bluestacks = None
    if settings.device_backend == "adb":
        bluestacks = BlueStacksManager(settings.bluestacks_install_dir, settings.bluestacks_conf_path,
                                       settings.bluestacks_instance, settings.adb_path, settings.adb_serial,
                                       settings.adb_command_timeout_seconds)

        async def device_factory():
            return await bluestacks.ensure_ready(settings.emulator_boot_timeout_seconds)
    else:
        mock = MockDevice()

        async def device_factory():
            return mock

    return AgentService(settings, db, manager.secrets, device_factory=device_factory, bluestacks=bluestacks,
                        live_screenshot=paths.data_dir() / "live_screen.png")


def run_service() -> int:
    manager = SettingsManager()
    settings = manager.load()
    setup_logging(paths.logs_dir(), "service", getattr(logging, settings.log_level.upper(), logging.INFO))
    lock = SingleInstanceLock(paths.data_dir() / "service.lock")
    if not lock.acquire():
        log.error("El servicio ya está en ejecución")
        return 2
    if settings.device_backend == "adb" and not has_interactive_desktop():
        log.error("BlueStacks requiere una sesión de usuario con escritorio activo; no se puede usar en la sesión 0")
    if settings.prevent_sleep:
        prevent_sleep(True)
    try:
        service = build_service(manager)
    except Exception as exc:
        log.exception("No se pudo iniciar el servicio: %s", exc)
        lock.release()
        return 1

    async def main() -> None:
        loop = asyncio.get_running_loop()
        if sys.platform != "win32":
            for sig in (signal.SIGINT, signal.SIGTERM):
                loop.add_signal_handler(sig, service.stopping.set)
        await service.run_forever()

    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass
    except Exception:
        log.exception("El servicio terminó con error")
        return 1
    finally:
        if settings.prevent_sleep:
            prevent_sleep(False)
        lock.release()
    return 0

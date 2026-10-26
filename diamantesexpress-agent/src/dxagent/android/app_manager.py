"""AndroidAppManager: detección y apertura exclusivamente de aplicaciones autorizadas."""

from __future__ import annotations

import asyncio
import logging
import time

from dxagent.android.device import DeviceController, DeviceError, UnauthorizedOperation, validate_package

log = logging.getLogger(__name__)


class AndroidAppManager:
    def __init__(self, device: DeviceController, authorized_packages: set[str]):
        for package in authorized_packages:
            validate_package(package)
        self.device = device
        self.authorized = set(authorized_packages)

    def check_authorized(self, package: str) -> None:
        if package not in self.authorized:
            raise UnauthorizedOperation(f"El paquete {package} no está en la lista de aplicaciones autorizadas")

    async def is_foreground(self, package: str) -> bool:
        return (await self.device.current_package()) == package

    async def ensure_open(self, package: str, activity: str | None = None, timeout: float = 30.0) -> bool:
        """Abre la app si está cerrada. Devuelve True si tuvo que lanzarla."""
        self.check_authorized(package)
        if await self.is_foreground(package):
            return False
        if not await self.device.is_package_installed(package):
            raise DeviceError(f"La aplicación {package} no está instalada en el emulador")
        log.info("Abriendo aplicación autorizada %s", package)
        await self.device.start_app(package, activity)
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            if await self.is_foreground(package):
                return True
            await asyncio.sleep(0.5)
        raise DeviceError(f"La aplicación {package} no pasó a primer plano en {timeout:.0f}s")

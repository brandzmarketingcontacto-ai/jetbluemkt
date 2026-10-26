"""Interfaz común de dispositivos Android (ADB real o simulador) y utilidades de jerarquía UI."""

from __future__ import annotations

import re
import xml.etree.ElementTree as ET
from dataclasses import dataclass
from typing import Protocol, runtime_checkable

PACKAGE_RE = re.compile(r"^[a-zA-Z][A-Za-z0-9_]*(\.[a-zA-Z][A-Za-z0-9_]*)+$")
ACTIVITY_RE = re.compile(r"^\.?[a-zA-Z][A-Za-z0-9_]*(\.[a-zA-Z][A-Za-z0-9_$]*)*$")
SAFE_TEXT_RE = re.compile(r"^[A-Za-z0-9_.@\- ]{1,64}$")
ALLOWED_KEYS = {"BACK": "KEYCODE_BACK", "HOME": "KEYCODE_HOME", "ENTER": "KEYCODE_ENTER", "ESCAPE": "KEYCODE_ESCAPE"}


class DeviceError(Exception):
    pass


class DeviceTimeout(DeviceError):
    """El dispositivo no respondió a tiempo (posible congelamiento)."""


class DeviceDisconnected(DeviceError):
    """ADB perdió la conexión con el dispositivo."""


class UnauthorizedOperation(DeviceError):
    """Operación fuera de la lista permitida (paquete no autorizado, texto inseguro, tecla no permitida)."""


@dataclass(frozen=True)
class UiNode:
    resource_id: str
    text: str
    content_desc: str
    cls: str
    package: str
    bounds: tuple[int, int, int, int]
    clickable: bool
    enabled: bool

    @property
    def center(self) -> tuple[int, int]:
        x1, y1, x2, y2 = self.bounds
        return (x1 + x2) // 2, (y1 + y2) // 2

    @property
    def short_id(self) -> str:
        return self.resource_id.split("/")[-1] if self.resource_id else ""


_BOUNDS_RE = re.compile(r"\[(-?\d+),(-?\d+)\]\[(-?\d+),(-?\d+)\]")


def parse_hierarchy(xml_text: str | None) -> list[UiNode]:
    """Convierte un volcado de `uiautomator dump` en nodos. Devuelve [] si es ilegible."""
    if not xml_text:
        return []
    start = xml_text.find("<")
    if start < 0:
        return []
    try:
        root = ET.fromstring(xml_text[start:xml_text.rfind(">") + 1])
    except ET.ParseError:
        return []
    nodes: list[UiNode] = []
    for el in root.iter("node"):
        match = _BOUNDS_RE.match(el.get("bounds", ""))
        if not match:
            continue
        nodes.append(UiNode(
            resource_id=el.get("resource-id", ""), text=el.get("text", ""),
            content_desc=el.get("content-desc", ""), cls=el.get("class", ""),
            package=el.get("package", ""), bounds=tuple(int(v) for v in match.groups()),  # type: ignore[arg-type]
            clickable=el.get("clickable") == "true", enabled=el.get("enabled", "true") == "true",
        ))
    return nodes


def validate_package(package: str) -> str:
    if not PACKAGE_RE.match(package or ""):
        raise UnauthorizedOperation(f"Nombre de paquete inválido: {package!r}")
    return package


def validate_text(text: str) -> str:
    if not SAFE_TEXT_RE.match(text or ""):
        raise UnauthorizedOperation("Texto con caracteres no permitidos para entrada ADB")
    return text


@runtime_checkable
class DeviceController(Protocol):
    serial: str

    async def is_responsive(self) -> bool: ...
    async def screen_size(self) -> tuple[int, int]: ...
    async def orientation(self) -> int: ...
    async def screenshot_png(self) -> bytes: ...
    async def dump_ui(self) -> str | None: ...
    async def tap(self, x: int, y: int) -> None: ...
    async def input_text(self, text: str) -> None: ...
    async def keyevent(self, key: str) -> None: ...
    async def current_package(self) -> str | None: ...
    async def start_app(self, package: str, activity: str | None = None) -> None: ...
    async def is_package_installed(self, package: str) -> bool: ...

"""Dispositivo Android simulado con la aplicación ficticia "DX Gift Simulator".

Reproduce la misma aplicación que `android-sim/` (mismos resource-id y pantallas) para poder
ejecutar el flujo completo sin BlueStacks, en pruebas automatizadas y en el modo SIMULACIÓN.
Nunca realiza compras ni envíos reales: las "entregas" se registran en memoria.

Permite inyectar fallos: congelamiento, desconexión, app cerrada, captura ilegible y
resultado incierto o caída durante la confirmación.
"""

from __future__ import annotations

import asyncio
import io
import uuid
from dataclasses import dataclass, field
from xml.sax.saxutils import quoteattr

from dxagent.android.device import (ALLOWED_KEYS, DeviceDisconnected, DeviceTimeout, UnauthorizedOperation,
                                    validate_package, validate_text)

SIM_PACKAGE = "com.diamantesexpress.giftsim"
SIM_ACTIVITY = ".MainActivity"
LAUNCHER_PACKAGE = "com.android.launcher3"
# (kind, texto visible, pestaña, diamantes por unidad, máximo por envío). Imitan el catálogo de Horizons v1.1.
SIM_PRODUCTS = [
    ("pase_booyah", "Pase Booyah Premium", "pase", 300, 1),
    ("DIAMANTES_100", "100 Diamantes", "pase", 10, 1),
    ("DIAMANTES_310", "310 Diamantes", "pase", 10, 1),
    ("DIAMANTES_520", "520 Diamantes", "pase", 10, 1),
    ("DIAMANTES_1060", "1060 Diamantes", "pase", 10, 1),
    ("caja_tokens_evo_universales", "Caja de Tokens evo universales", "caja", 50, 57),
    ("token_escorpion", "Token Escorpion", "token", 10, 99),
]
SIM_CATALOG = {code: {"name": name, "category": cat, "cost": cost, "max": mx}
               for code, name, cat, cost, mx in SIM_PRODUCTS}


@dataclass
class Element:
    rid: str
    text: str
    rect: tuple[float, float, float, float]  # normalizado x1,y1,x2,y2
    cls: str = "android.widget.TextView"
    clickable: bool = False


@dataclass
class SimTransaction:
    ref: str
    player_id: str
    product_code: str
    outcome: str
    quantity: int = 1
    cost: int = 0


@dataclass
class MockDevice:
    serial: str = "mock-5555"
    width: int = 900
    height: int = 1600
    installed: set[str] = field(default_factory=lambda: {SIM_PACKAGE})
    known_players: set[str] | None = None   # None = cualquier ID válido existe
    command_delay: float = 0.0

    # Fallos inyectables
    frozen: bool = False
    disconnected: bool = False
    unreadable: bool = False
    hierarchy_available: bool = True        # False simula un juego (OpenGL) sin jerarquía UI
    commit_outcome: str = "success"          # success | failure | uncertain | crash
    # Amistad y saldo (Free Fire solo deja regalar a amigos y descuenta diamantes)
    require_friendship: bool = False
    friends: set[str] = field(default_factory=set)
    auto_accept_friends: bool = False        # el cliente acepta la solicitud al instante
    diamond_balance: int = 1_000_000

    def __post_init__(self) -> None:
        self.foreground = LAUNCHER_PACKAGE
        self.screen = "launcher"
        self.player_input = ""
        self.field_focused = False
        self.error_text = ""
        self.selected_product: tuple | None = None
        self.friend_input = ""
        self.friend_target = ""
        self.pending_requests: set[str] = set()
        self.tab = "pase"
        self.quantity = 1
        self.last_result: SimTransaction | None = None
        self.transactions: list[SimTransaction] = []
        self.taps: list[tuple[int, int]] = []
        self.commands = 0
        self._png_cache: dict = {}

    # --------------------------------------------------------------- utilidades
    async def _guard(self) -> None:
        self.commands += 1
        if self.disconnected:
            raise DeviceDisconnected(f"device '{self.serial}' not found")
        if self.frozen:
            raise DeviceTimeout("el emulador no responde (simulado)")
        if self.command_delay:
            await asyncio.sleep(self.command_delay)

    def close_app(self) -> None:
        self.foreground, self.screen = LAUNCHER_PACKAGE, "launcher"

    def accept_pending_requests(self) -> None:
        """El cliente acepta en su juego las solicitudes de amistad pendientes."""
        self.friends |= self.pending_requests
        self.pending_requests.clear()

    def _elements(self) -> list[Element]:
        btn = "android.widget.Button"
        if self.foreground != SIM_PACKAGE:
            return [Element("com.android.launcher3:id/workspace", "Inicio", (0, 0, 1, 1), "android.widget.FrameLayout")]
        s = self.screen
        els = [Element("app_title", "DX Gift Simulator", (0.05, 0.02, 0.6, 0.06)),
               Element("tv_balance", f"Diamantes: {self.diamond_balance:,}", (0.62, 0.02, 0.98, 0.06))]
        if s == "home":
            els += [Element("tv_home", "Simulador de regalos (sin envíos reales)", (0.05, 0.12, 0.95, 0.18)),
                    Element("btn_start", "Enviar regalo", (0.2, 0.45, 0.8, 0.52), btn, True),
                    Element("btn_add_friend", "Agregar amigo", (0.2, 0.56, 0.8, 0.63), btn, True)]
        elif s == "add_friend":
            els += [Element("tv_add_friend_label", "Buscar jugador por ID", (0.05, 0.15, 0.95, 0.2)),
                    Element("field_friend_id", self.friend_input, (0.05, 0.22, 0.95, 0.29), "android.widget.EditText", True),
                    Element("btn_friend_search", "Buscar", (0.2, 0.35, 0.8, 0.42), btn, True)]
            if self.error_text:
                els.append(Element("tv_error", self.error_text, (0.05, 0.45, 0.95, 0.5)))
        elif s == "friend_profile":
            els.append(Element("tv_profile_id", self.friend_target, (0.05, 0.15, 0.95, 0.2)))
            if self.friend_target in self.friends:
                els.append(Element("tv_already_friends", "Ya son amigos", (0.05, 0.25, 0.95, 0.3)))
            else:
                els.append(Element("btn_send_request", "Enviar solicitud", (0.2, 0.35, 0.8, 0.42), btn, True))
        elif s == "friend_request_sent":
            els += [Element("tv_request_sent", "Solicitud enviada", (0.05, 0.15, 0.95, 0.2)),
                    Element("tv_request_target", self.friend_target, (0.05, 0.22, 0.95, 0.27)),
                    Element("btn_done", "Finalizar", (0.2, 0.6, 0.8, 0.67), btn, True)]
        elif s == "recipient":
            els += [Element("tv_recipient_label", "ID del jugador destinatario", (0.05, 0.15, 0.95, 0.2)),
                    Element("field_player_id", self.player_input, (0.05, 0.22, 0.95, 0.29), "android.widget.EditText", True),
                    Element("btn_search", "Buscar jugador", (0.2, 0.35, 0.8, 0.42), btn, True)]
            if self.error_text == "no_friend":
                els.append(Element("tv_not_friend", "Este jugador no es tu amigo", (0.05, 0.45, 0.95, 0.5)))
            elif self.error_text:
                els.append(Element("tv_error", self.error_text, (0.05, 0.45, 0.95, 0.5)))
        elif s == "gift_select":
            els.append(Element("tv_recipient", f"Destinatario: {self.player_input}", (0.05, 0.08, 0.95, 0.12)))
            for i, tab in enumerate(("pase", "caja", "token")):
                els.append(Element(f"tab_{tab}", tab.upper() + (" *" if tab == self.tab else ""),
                                   (0.05 + i * 0.31, 0.13, 0.33 + i * 0.31, 0.18), btn, True))
            visible = [p for p in SIM_PRODUCTS if p[2] == self.tab]
            for i, (code, name, _cat, cost, _mx) in enumerate(visible):
                top = 0.21 + i * 0.09
                els.append(Element(f"product_{code}", name, (0.1, top, 0.9, top + 0.08), btn, True))
        elif s == "confirm":
            code, name, _cat, cost, _mx = self.selected_product or ("", "", "", 0, 1)
            els += [Element("tv_confirm_title", "Confirmar regalo", (0.05, 0.1, 0.95, 0.15)),
                    Element("tv_confirm_recipient", self.player_input, (0.05, 0.17, 0.95, 0.22)),
                    Element("tv_confirm_product", name, (0.05, 0.24, 0.95, 0.29)),
                    Element("tv_confirm_product_code", code, (0.05, 0.3, 0.95, 0.34)),
                    Element("btn_qty_minus", "-", (0.05, 0.37, 0.25, 0.43), btn, True),
                    Element("tv_quantity", str(self.quantity), (0.3, 0.37, 0.7, 0.43)),
                    Element("btn_qty_plus", "+", (0.75, 0.37, 0.95, 0.43), btn, True),
                    Element("tv_total_cost", str(self.quantity * cost), (0.05, 0.46, 0.95, 0.51)),
                    Element("btn_confirm", "Confirmar envío", (0.1, 0.6, 0.9, 0.67), btn, True),
                    Element("btn_cancel", "Cancelar", (0.1, 0.7, 0.9, 0.77), btn, True)]
        elif s == "processing":
            els.append(Element("tv_processing", "Procesando...", (0.05, 0.4, 0.95, 0.46)))
        elif s in ("result_success", "result_failure"):
            tx = self.last_result
            ok = s == "result_success"
            els += [Element("tv_result_title", "Regalo enviado" if ok else "Envío rechazado", (0.05, 0.12, 0.95, 0.18)),
                    Element("tv_result_recipient", tx.player_id if tx else "", (0.05, 0.2, 0.95, 0.25)),
                    Element("tv_result_product_code", tx.product_code if tx else "", (0.05, 0.27, 0.95, 0.32)),
                    Element("tv_result_quantity", str(tx.quantity) if tx else "", (0.05, 0.33, 0.95, 0.37)),
                    Element("tv_result_ref", tx.ref if tx and ok else "", (0.05, 0.38, 0.95, 0.43)),
                    Element("btn_done", "Finalizar", (0.2, 0.6, 0.8, 0.67), btn, True)]
            if not ok:
                els.append(Element("tv_result_reason", self.error_text or "Rechazado", (0.05, 0.45, 0.95, 0.5)))
        return els

    def visible_elements(self) -> list[Element]:
        """Elementos visibles (usado por el oráculo de visión en pruebas)."""
        return self._elements()

    def _px(self, rect: tuple[float, float, float, float]) -> tuple[int, int, int, int]:
        x1, y1, x2, y2 = rect
        return int(x1 * self.width), int(y1 * self.height), int(x2 * self.width), int(y2 * self.height)

    # --------------------------------------------------------- DeviceController
    async def is_responsive(self) -> bool:
        try:
            await self._guard()
            return True
        except (DeviceTimeout, DeviceDisconnected):
            return False

    async def boot_completed(self) -> bool:
        return await self.is_responsive()

    async def screen_size(self) -> tuple[int, int]:
        await self._guard()
        return self.width, self.height

    async def orientation(self) -> int:
        await self._guard()
        return 0 if self.height >= self.width else 1

    async def screenshot_png(self) -> bytes:
        await self._guard()
        key = (self.unreadable, tuple((e.rid, e.text) for e in self._elements()))
        if key in self._png_cache:
            return self._png_cache[key]
        from PIL import Image, ImageDraw
        img = Image.new("RGB", (self.width, self.height), (12, 14, 22))
        if not self.unreadable:
            draw = ImageDraw.Draw(img)
            for el in self._elements():
                x1, y1, x2, y2 = self._px(el.rect)
                if el.clickable:
                    draw.rectangle((x1, y1, x2, y2), fill=(40, 90, 200) if "Button" in el.cls else (230, 230, 235))
                fill = (20, 20, 20) if "EditText" in el.cls else (235, 238, 245)
                draw.text((x1 + 8, y1 + 8), el.text or " ", fill=fill)
        buf = io.BytesIO()
        img.save(buf, format="PNG", compress_level=1)
        if len(self._png_cache) > 64:
            self._png_cache.clear()
        self._png_cache[key] = buf.getvalue()
        return self._png_cache[key]

    async def dump_ui(self) -> str | None:
        await self._guard()
        if self.unreadable or not self.hierarchy_available:
            return None
        pkg = self.foreground
        nodes = []
        for el in self._elements():
            rid = el.rid if ":" in el.rid else f"{pkg}:id/{el.rid}"
            x1, y1, x2, y2 = self._px(el.rect)
            nodes.append(
                f'<node index="0" text={quoteattr(el.text)} resource-id={quoteattr(rid)} class={quoteattr(el.cls)} '
                f'package={quoteattr(pkg)} content-desc="" clickable="{str(el.clickable).lower()}" enabled="true" '
                f'bounds="[{x1},{y1}][{x2},{y2}]" />')
        return ("<?xml version='1.0' encoding='UTF-8' standalone='yes' ?><hierarchy rotation=\"0\">"
                f'<node index="0" text="" resource-id="" class="android.widget.FrameLayout" package="{pkg}" '
                f'content-desc="" clickable="false" enabled="true" bounds="[0,0][{self.width},{self.height}]">'
                + "".join(nodes) + "</node></hierarchy>")

    async def tap(self, x: int, y: int) -> None:
        await self._guard()
        self.taps.append((x, y))
        if not (0 <= x < self.width and 0 <= y < self.height):
            return
        target = None
        for el in self._elements():
            x1, y1, x2, y2 = self._px(el.rect)
            if el.clickable and x1 <= x <= x2 and y1 <= y <= y2:
                target = el.rid
        if target:
            self._on_tap(target)

    def _on_tap(self, rid: str) -> None:
        s = self.screen
        self.field_focused = rid in ("field_player_id", "field_friend_id")
        if s == "home" and rid == "btn_start":
            self.screen, self.player_input, self.error_text = "recipient", "", ""
        elif s == "home" and rid == "btn_add_friend":
            self.screen, self.friend_input, self.error_text = "add_friend", "", ""
        elif s == "add_friend" and rid == "btn_friend_search":
            pid = self.friend_input
            if not pid.isdigit() or (self.known_players is not None and pid not in self.known_players):
                self.error_text = "Jugador no encontrado"
            else:
                self.screen, self.friend_target, self.error_text = "friend_profile", pid, ""
        elif s == "friend_profile" and rid == "btn_send_request":
            self.pending_requests.add(self.friend_target)
            if self.auto_accept_friends:
                self.accept_pending_requests()
            self.screen = "friend_request_sent"
        elif s == "friend_request_sent" and rid == "btn_done":
            self.screen, self.friend_input = "home", ""
        elif s == "recipient" and rid == "btn_search":
            pid = self.player_input
            if not pid.isdigit() or (self.known_players is not None and pid not in self.known_players):
                self.error_text = "Jugador no encontrado"
            elif self.require_friendship and pid not in self.friends:
                self.error_text = "no_friend"
            else:
                self.screen, self.error_text, self.tab = "gift_select", "", "pase"
        elif s == "gift_select" and rid.startswith("tab_"):
            self.tab = rid[len("tab_"):]
        elif s == "gift_select" and rid.startswith("product_"):
            code = rid[len("product_"):]
            self.selected_product = next(p for p in SIM_PRODUCTS if p[0] == code)
            self.quantity = 1
            self.screen = "confirm"
        elif s == "confirm" and rid == "btn_qty_plus":
            self.quantity = min(self.quantity + 1, self.selected_product[4] if self.selected_product else 1)
        elif s == "confirm" and rid == "btn_qty_minus":
            self.quantity = max(self.quantity - 1, 1)
        elif s == "confirm" and rid == "btn_cancel":
            self.screen = "gift_select"
        elif s == "confirm" and rid == "btn_confirm":
            self._commit()
        elif s in ("result_success", "result_failure") and rid == "btn_done":
            self.screen, self.player_input, self.selected_product = "home", "", None

    def _commit(self) -> None:
        code = self.selected_product[0] if self.selected_product else ""
        cost = (self.selected_product[3] if self.selected_product else 0) * self.quantity
        outcome = self.commit_outcome
        self.error_text = ""
        if outcome == "success" and cost > self.diamond_balance:
            outcome, self.error_text = "failure", "Diamantes insuficientes"
        tx = SimTransaction(f"REF-{uuid.uuid4().hex[:8].upper()}", self.player_input, code,
                            "failure" if outcome == "failure" else "success", self.quantity, cost)
        if outcome in ("success", "uncertain", "crash"):
            self.transactions.append(tx)  # el servidor ficticio sí registró la entrega
            self.diamond_balance -= cost
        self.last_result = tx
        if outcome == "success":
            self.screen = "result_success"
        elif outcome == "failure":
            self.screen = "result_failure"
        elif outcome == "uncertain":
            self.screen = "processing"
        elif outcome == "crash":
            self.screen = "processing"
            self.disconnected = True

    async def input_text(self, text: str) -> None:
        await self._guard()
        validate_text(text)
        if self.screen == "recipient" and self.field_focused:
            self.player_input += text
        elif self.screen == "add_friend" and self.field_focused:
            self.friend_input += text

    async def keyevent(self, key: str) -> None:
        await self._guard()
        if key not in ALLOWED_KEYS:
            raise UnauthorizedOperation(key)
        if key == "HOME":
            self.foreground, self.screen = LAUNCHER_PACKAGE, "launcher"
        elif key == "BACK" and self.foreground == SIM_PACKAGE:
            back = {"recipient": "home", "gift_select": "recipient", "confirm": "gift_select", "add_friend": "home",
                    "friend_profile": "add_friend", "friend_request_sent": "home"}
            self.screen = back.get(self.screen, self.screen)

    async def current_package(self) -> str | None:
        await self._guard()
        return self.foreground

    async def start_app(self, package: str, activity: str | None = None) -> None:
        await self._guard()
        validate_package(package)
        if package not in self.installed:
            raise UnauthorizedOperation(f"Paquete no instalado: {package}")
        if self.foreground != package:
            self.foreground = package
            self.screen = "home"

    async def is_package_installed(self, package: str) -> bool:
        await self._guard()
        return package in self.installed

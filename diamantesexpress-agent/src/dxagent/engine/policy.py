"""ExecutionPolicy: lista blanca de acciones y límites por pedido."""

from __future__ import annotations

import time
from dataclasses import dataclass, field
from pathlib import Path

from dxagent.android.device import validate_text

ALLOWED_ACTIONS = {"launch_app", "tap", "input_text", "key_back", "screenshot", "dump_ui"}


class PolicyViolation(Exception):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


class EmergencyStop(PolicyViolation):
    def __init__(self) -> None:
        super().__init__("EMERGENCY_STOP", "Parada de emergencia activada")


@dataclass
class ExecutionLimits:
    max_actions: int = 40
    max_duration_s: float = 300.0
    max_consecutive_errors: int = 3


@dataclass
class ExecutionPolicy:
    limits: ExecutionLimits
    authorized_packages: set[str]
    emergency_flag: Path | None = None
    started_at: float = field(default_factory=time.monotonic)
    actions: int = 0
    consecutive_errors: int = 0
    log: list[dict] = field(default_factory=list)

    def check_emergency(self) -> None:
        if self.emergency_flag is not None and self.emergency_flag.exists():
            raise EmergencyStop()

    def check_time(self) -> None:
        if time.monotonic() - self.started_at > self.limits.max_duration_s:
            raise PolicyViolation("MAX_DURATION", "Se superó la duración máxima del pedido")

    def authorize(self, action: str, *, x: float | None = None, y: float | None = None,
                  package: str | None = None, text: str | None = None, step_id: str = "") -> None:
        """Valida una acción ANTES de enviarla al dispositivo. Toda acción pasa por aquí."""
        self.check_emergency()
        self.check_time()
        if action not in ALLOWED_ACTIONS:
            raise PolicyViolation("ACTION_NOT_ALLOWED", f"Acción no permitida: {action}")
        if action in {"tap", "input_text", "key_back", "launch_app"}:
            if self.actions >= self.limits.max_actions:
                raise PolicyViolation("MAX_ACTIONS", "Se alcanzó el máximo de acciones por pedido")
            self.actions += 1
        if action == "tap":
            if x is None or y is None or not (0.0 <= x <= 1.0 and 0.0 <= y <= 1.0):
                raise PolicyViolation("BAD_COORDINATES", "Coordenadas fuera de la pantalla")
        if action == "launch_app" and package not in self.authorized_packages:
            raise PolicyViolation("PACKAGE_NOT_AUTHORIZED", f"Paquete no autorizado: {package}")
        if action == "input_text":
            try:
                validate_text(text or "")
            except Exception as exc:
                raise PolicyViolation("UNSAFE_TEXT", str(exc)) from exc
        self.log.append({"action": action, "step": step_id, "x": x, "y": y})

    def record_error(self) -> None:
        self.consecutive_errors += 1
        if self.consecutive_errors >= self.limits.max_consecutive_errors:
            raise PolicyViolation("MAX_CONSECUTIVE_ERRORS", "Demasiados errores consecutivos")

    def record_success(self) -> None:
        self.consecutive_errors = 0

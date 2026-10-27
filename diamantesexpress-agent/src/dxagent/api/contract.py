"""Contrato de la API real de DiamantesExpress (Hostinger Horizons).

El OpenAPI publicado por Horizons (`GET /automation/openapi`) no define operationId, así que el
agente identifica cada operación por MÉTODO + RUTA y comprueba al cargar el documento que todas
las rutas que necesita existen. Si Horizons cambia una ruta, `doctor` lo detecta y se puede
ajustar con `route_overrides` sin recompilar.
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from pathlib import Path

import yaml

from dxagent import paths
from dxagent.api.errors import ContractError

DEFAULT_CONTRACT = "openapi/diamantesexpress-agent-api.json"

# Operaciones que usa el agente (rutas relativas a DX_API_BASE_URL, p. ej. https://dominio/hcgi/api).
REQUIRED_OPERATIONS: dict[str, tuple[str, str]] = {
    "heartbeat": ("POST", "/agent/heartbeat"),
    "claimTask": ("POST", "/agent/tasks/claim"),
    "currentTasks": ("GET", "/agent/tasks/current"),
    "reportProgress": ("POST", "/agent/tasks/{id}/progress"),
    "reportResult": ("POST", "/agent/tasks/{id}/result"),
}
# v1.1 (opcionales: con un contrato v1.0 el agente sigue funcionando sin estas capacidades).
OPTIONAL_OPERATIONS: dict[str, tuple[str, str]] = {
    "deferTask": ("POST", "/agent/tasks/{id}/defer"),
}


@dataclass(frozen=True)
class Operation:
    name: str
    method: str
    path: str

    def render(self, **params: str) -> str:
        from urllib.parse import quote
        path = self.path
        for key, value in params.items():
            path = path.replace("{" + key + "}", quote(str(value), safe=""))
        if "{" in path:
            raise ContractError(f"Faltan parámetros de ruta para {self.name}: {path}")
        return path


class ApiContract:
    def __init__(self, operations: dict[str, Operation], source: str, version: str = ""):
        self.operations = operations
        self.source = source
        self.version = version

    @classmethod
    def load(cls, path: str | Path | None = None, overrides: dict[str, str] | None = None) -> "ApiContract":
        file = Path(path) if path else paths.resolve_config_file(DEFAULT_CONTRACT)
        try:
            text = file.read_text(encoding="utf-8")
            spec = json.loads(text) if file.suffix == ".json" else yaml.safe_load(text)
        except (OSError, ValueError, yaml.YAMLError) as exc:
            raise ContractError(f"No se pudo leer el contrato OpenAPI {file}: {exc}") from exc
        available = {(m.upper(), route) for route, item in (spec.get("paths") or {}).items()
                     for m in (item or {}) if m.lower() in {"get", "post", "put", "patch", "delete"}}
        operations: dict[str, Operation] = {}
        missing = []
        for name, (method, route) in REQUIRED_OPERATIONS.items():
            if name in (overrides or {}):
                o_method, _, o_route = overrides[name].strip().partition(" ")
                if not o_route:
                    raise ContractError(f"route_overrides[{name}] debe tener el formato 'METODO /ruta'")
                operations[name] = Operation(name, o_method.upper(), o_route.strip())
            elif (method, route) in available:
                operations[name] = Operation(name, method, route)
            else:
                missing.append(f"{method} {route}")
        if missing:
            raise ContractError("El contrato no define las rutas: " + ", ".join(missing))
        for name, (method, route) in OPTIONAL_OPERATIONS.items():
            if name in (overrides or {}):
                o_method, _, o_route = overrides[name].strip().partition(" ")
                operations[name] = Operation(name, o_method.upper(), o_route.strip())
            elif (method, route) in available:
                operations[name] = Operation(name, method, route)
        return cls(operations, str(file), str((spec.get("info") or {}).get("version", "")))

    def supports(self, name: str) -> bool:
        return name in self.operations

    def get(self, name: str) -> Operation:
        try:
            return self.operations[name]
        except KeyError as exc:
            raise ContractError(f"Operación no definida en el contrato: {name}") from exc

"""Resolución de rutas a partir de un contrato OpenAPI importable desde Horizons."""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

import yaml

from dxagent import paths
from dxagent.api.errors import ContractError

REQUIRED_OPERATIONS = (
    "pairAgent", "sendHeartbeat", "getAgentConfig", "listPendingJobs", "getJob", "claimJob",
    "reportProgress", "reportUsage", "uploadEvidence", "completeJob", "releaseJob", "reportAlert",
)
DEFAULT_CONTRACT = "openapi/horizons-agent-api.provisional.yaml"


@dataclass(frozen=True)
class Operation:
    operation_id: str
    method: str
    path: str

    def render(self, **params: str) -> str:
        path = self.path
        for key, value in params.items():
            path = path.replace("{" + key + "}", _quote(str(value)))
        if "{" in path:
            raise ContractError(f"Faltan parámetros de ruta para {self.operation_id}: {path}")
        return path


def _quote(value: str) -> str:
    from urllib.parse import quote
    return quote(value, safe="")


class ApiContract:
    def __init__(self, operations: dict[str, Operation], source: str):
        self.operations = operations
        self.source = source

    @classmethod
    def load(cls, path: str | Path | None = None, overrides: dict[str, str] | None = None) -> "ApiContract":
        file = Path(path) if path else paths.resolve_config_file(DEFAULT_CONTRACT)
        try:
            spec = yaml.safe_load(file.read_text(encoding="utf-8"))
        except (OSError, yaml.YAMLError) as exc:
            raise ContractError(f"No se pudo leer el contrato OpenAPI {file}: {exc}") from exc
        operations: dict[str, Operation] = {}
        for route, item in (spec.get("paths") or {}).items():
            for method, op in (item or {}).items():
                if method.lower() not in {"get", "post", "put", "patch", "delete"} or not isinstance(op, dict):
                    continue
                op_id = op.get("operationId")
                if op_id:
                    operations[op_id] = Operation(op_id, method.upper(), route)
        for op_id, spec_str in (overrides or {}).items():
            # Formato: "POST /ruta/{job_id}/claim"
            method, _, route = spec_str.strip().partition(" ")
            if not route:
                raise ContractError(f"route_overrides[{op_id}] debe tener el formato 'METODO /ruta'")
            operations[op_id] = Operation(op_id, method.upper(), route.strip())
        missing = [op for op in REQUIRED_OPERATIONS if op not in operations]
        if missing:
            raise ContractError("El contrato no define las operaciones: " + ", ".join(missing))
        return cls(operations, str(file))

    def get(self, operation_id: str) -> Operation:
        try:
            return self.operations[operation_id]
        except KeyError as exc:
            raise ContractError(f"Operación no definida en el contrato: {operation_id}") from exc

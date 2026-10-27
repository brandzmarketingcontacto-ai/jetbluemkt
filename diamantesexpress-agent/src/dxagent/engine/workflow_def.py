"""Definición externa y verificable de flujos (YAML) y validación de pedidos contra sus reglas."""

from __future__ import annotations

import re
from pathlib import Path
from typing import Any, Literal

import yaml
from pydantic import BaseModel, ConfigDict, Field, model_validator

from dxagent import paths
from dxagent.models import Job

PLACEHOLDER = "<VERIFICAR"
StepKind = Literal["launch_app", "navigate_to", "wait_screen", "tap", "input_text", "tap_option",
                   "verify_fields", "commit", "verify_result", "set_quantity", "read_balance"]
Phase = Literal["friend_request", "gift"]
ExitOutcome = Literal["completed", "failed", "defer"]
FRIEND_STATUSES = {"request_sent", "already_friends", "player_not_found"}


class WorkflowNotReady(Exception):
    """El flujo contiene valores sin verificar o no está permitido en el modo actual."""


class OrderRejected(Exception):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


class Marker(BaseModel):
    model_config = ConfigDict(extra="forbid")
    resource_id: str | None = None
    text: str | None = None


class ScreenDef(BaseModel):
    model_config = ConfigDict(extra="forbid")
    description: str
    markers: list[Marker] = Field(default_factory=list)


class ElementDef(BaseModel):
    model_config = ConfigDict(extra="forbid")
    description: str
    resource_id: str | None = None
    text: str | None = None
    calibrated: tuple[float, float] | None = None  # coordenadas normalizadas validadas


class ExitDef(BaseModel):
    """Salida anticipada (antes del envío) si un elemento aparece en pantalla.

    Ejemplos: "jugador no encontrado" -> failed; "ya son amigos" -> completed con friend_status;
    "aún no es tu amigo" -> defer (se aplaza la tarea con /defer).
    """
    model_config = ConfigDict(extra="forbid")
    element: str
    outcome: ExitOutcome
    code: str | None = None
    message: str = ""
    friend_status: str | None = None
    reason: str | None = None            # para defer, p. ej. not_friend_yet


class StepDef(BaseModel):
    model_config = ConfigDict(extra="forbid")
    id: str
    kind: StepKind
    element: str | None = None
    screen: str | None = None
    expect: str | None = None
    value: str | None = None
    label: str | None = None
    checks: dict[str, str] = Field(default_factory=dict)
    via_back: int = 0
    optional: bool = False
    fail_if_visible: dict[str, str] | None = None
    exits: list[ExitDef] = Field(default_factory=list)
    plus: str | None = None              # set_quantity: botón +
    minus: str | None = None             # set_quantity: botón –
    field: str | None = None             # set_quantity / read_balance: campo a leer
    success_data: dict[str, str] = Field(default_factory=dict)   # verify_result: datos del éxito (friend_status)
    result_screens: list[str] = Field(default_factory=list)
    success_screen: str | None = None
    failure_screen: str | None = None
    failure_is_definitive: bool = False
    reference_field: str | None = None


class AppDef(BaseModel):
    package: str
    activity: str | None = None


class AuthorizationDef(BaseModel):
    authorized: bool = False
    reference: str | None = None
    validated_at: str | None = None
    validated_by: str | None = None


class CalibrationDef(BaseModel):
    validated: bool = False
    resolution: tuple[int, int] | None = None


class OrderRules(BaseModel):
    player_id_pattern: str
    # kind -> texto visible. Con accept_server_catalog=true también se aceptan los productos del catálogo de
    # Horizons (agent_products), usando su gameItemName exacto.
    products: dict[str, str] = Field(default_factory=dict)
    accept_server_catalog: bool = False
    default_category: str = "pase"


class WorkflowDef(BaseModel):
    model_config = ConfigDict(extra="forbid")
    id: str
    version: int
    description: str = ""
    simulation: bool
    phase: Phase = "gift"
    allowed_modes: list[str]
    authorization: AuthorizationDef = Field(default_factory=AuthorizationDef)
    app: AppDef
    order_rules: OrderRules
    calibration: CalibrationDef = Field(default_factory=CalibrationDef)
    screens: dict[str, ScreenDef]
    elements: dict[str, ElementDef] = Field(default_factory=dict)
    fields: dict[str, ElementDef] = Field(default_factory=dict)
    steps: list[StepDef]

    @model_validator(mode="after")
    def _references(self) -> "WorkflowDef":
        commits = [s for s in self.steps if s.kind == "commit"]
        if len(commits) != 1:
            raise ValueError("Un flujo debe tener exactamente un paso 'commit' (operación transaccional)")
        index = {s.id: i for i, s in enumerate(self.steps)}
        if not any(s.kind == "verify_result" and index[s.id] > index[commits[0].id] for s in self.steps):
            raise ValueError("Después del paso 'commit' debe existir un paso 'verify_result'")
        if not any(s.kind == "verify_fields" and index[s.id] < index[commits[0].id] for s in self.steps):
            raise ValueError("Antes del paso 'commit' debe existir un paso 'verify_fields'")
        for step in self.steps:
            for ref in (step.screen, step.expect, step.success_screen, step.failure_screen, *step.result_screens):
                if ref and ref not in self.screens:
                    raise ValueError(f"Paso {step.id}: pantalla desconocida {ref}")
            for element in (step.element, step.plus, step.minus, *(e.element for e in step.exits)):
                # Los nombres con plantilla (p. ej. tab_{product.category}) se validan al ejecutar.
                if element and "{" not in element and element not in self.elements:
                    raise ValueError(f"Paso {step.id}: elemento desconocido {element}")
            for field in (*step.checks, *([step.field] if step.field else [])):
                if field not in self.fields:
                    raise ValueError(f"Paso {step.id}: campo desconocido {field}")
            for exit_def in step.exits:
                if exit_def.friend_status and exit_def.friend_status not in FRIEND_STATUSES:
                    raise ValueError(f"Paso {step.id}: friend_status inválido {exit_def.friend_status}")
            if step.kind == "set_quantity" and not (step.plus and step.minus and step.field and step.value):
                raise ValueError(f"Paso {step.id}: set_quantity requiere plus, minus, field y value")
            if step.kind == "read_balance" and not step.field:
                raise ValueError(f"Paso {step.id}: read_balance requiere field")
        return self

    def commit_step(self) -> StepDef:
        return next(s for s in self.steps if s.kind == "commit")


def _find_placeholders(data: Any, path: str = "") -> list[str]:
    found: list[str] = []
    if isinstance(data, dict):
        for k, v in data.items():
            if PLACEHOLDER in str(k):
                found.append(f"{path}.{k}")
            found += _find_placeholders(v, f"{path}.{k}")
    elif isinstance(data, list):
        for i, v in enumerate(data):
            found += _find_placeholders(v, f"{path}[{i}]")
    elif isinstance(data, str) and PLACEHOLDER in data:
        found.append(path)
    return found


def load_workflow(workflow_id: str, directory: Path | None = None) -> WorkflowDef:
    if not re.match(r"^[a-z0-9_]+$", workflow_id):
        raise WorkflowNotReady(f"ID de flujo inválido: {workflow_id}")
    if directory:
        file = directory / f"{workflow_id}.yaml"
    else:
        file = paths.resolve_config_file(f"workflows/{workflow_id}.yaml")
    if not file.exists():
        raise WorkflowNotReady(f"No existe el flujo {workflow_id} ({file})")
    raw = yaml.safe_load(file.read_text(encoding="utf-8"))
    pending = _find_placeholders(raw)
    if pending:
        raise WorkflowNotReady("El flujo tiene valores sin verificar: " + ", ".join(pending[:8]))
    return WorkflowDef.model_validate(raw)


def check_mode_allowed(workflow: WorkflowDef, mode: str, backend_production_authorized: bool) -> None:
    if mode not in workflow.allowed_modes:
        raise WorkflowNotReady(f"El flujo {workflow.id} no admite el modo {mode}")
    if mode == "SIMULATION" and not workflow.simulation:
        raise WorkflowNotReady("El modo SIMULACIÓN solo puede ejecutar flujos de simulación")
    if mode == "AUTHORIZED_PRODUCTION":
        auth = workflow.authorization
        if workflow.simulation or not auth.authorized or not auth.reference or not auth.validated_at:
            raise WorkflowNotReady("El flujo no tiene una autorización validada para PRODUCCIÓN AUTORIZADA")
        if not backend_production_authorized:
            raise WorkflowNotReady("DiamantesExpress no habilitó production_authorized para este agente")


def order_context(workflow: WorkflowDef, job: Job) -> dict[str, str]:
    """Valida la tarea contra las reglas del flujo y devuelve las variables para las plantillas."""
    rules = workflow.order_rules
    if job.phase != workflow.phase:
        raise OrderRejected("PHASE_MISMATCH", f"El flujo {workflow.id} es de la fase {workflow.phase}, "
                                              f"la tarea es {job.phase}")
    if not re.fullmatch(rules.player_id_pattern, job.recipient.player_id):
        raise OrderRejected("INVALID_PLAYER_ID", "El ID de jugador no cumple el formato configurado")
    ctx = {"recipient.player_id": job.recipient.player_id, "order.id": job.order_id, "product.code": job.product.code}
    if workflow.phase == "friend_request":
        return ctx
    product = job.product
    label = rules.products.get(product.code)
    if product.game_item_name and (rules.accept_server_catalog or label is not None):
        label = product.game_item_name  # el catálogo de Horizons manda el texto exacto del juego
    if label is None:
        raise OrderRejected("PRODUCT_NOT_ALLOWED", f"Producto no configurado en el flujo: {product.code}")
    if product.max_per_transaction and product.quantity > product.max_per_transaction:
        raise OrderRejected("QUANTITY_EXCEEDS_MAX", f"La tarea pide {product.quantity} unidades y el máximo por "
                                                    f"envío es {product.max_per_transaction}")
    total = product.quantity * product.unit_diamond_cost if product.unit_diamond_cost is not None else None
    ctx.update({
        "product.label": label,
        "product.category": product.category or rules.default_category,
        "product.quantity": str(product.quantity),
        # Vacío si Horizons no envía el costo (v1.0): esa comprobación se omite.
        "product.total_cost": "" if total is None else str(total),
    })
    return ctx


def render(template: str | None, ctx: dict[str, str]) -> str:
    if template is None:
        return ""

    def repl(match: re.Match) -> str:
        key = match.group(1)
        if key not in ctx:
            raise WorkflowNotReady(f"Variable desconocida en el flujo: {key}")
        return ctx[key]

    return re.sub(r"\{([a-z_.]+)\}", repl, template)

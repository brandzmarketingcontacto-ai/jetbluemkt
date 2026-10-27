"""Modelos de dominio validados con Pydantic."""

from __future__ import annotations

from datetime import datetime, timezone
from enum import Enum
from typing import Any

from pydantic import BaseModel, ConfigDict, Field, field_validator


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


def iso_now() -> str:
    return utcnow().isoformat()


class AgentState(str, Enum):
    STOPPED = "STOPPED"
    STARTING = "STARTING"
    RUNNING = "RUNNING"
    PAUSED = "PAUSED"
    DEGRADED = "DEGRADED"          # sin Internet / API no disponible / emulador caído
    SERVER_HOLD = "SERVER_HOLD"    # DiamantesExpress está en off, en pausa o en un modo no permitido localmente
    AUTH_ERROR = "AUTH_ERROR"      # token inválido, expirado o revocado
    BUDGET_EXHAUSTED = "BUDGET_EXHAUSTED"
    EMERGENCY_STOP = "EMERGENCY_STOP"


class JobStatus(str, Enum):
    CLAIMED = "claimed"
    RUNNING = "running"
    AWAITING_APPROVAL = "awaiting_approval"
    COMMITTING = "committing"      # operación transaccional en curso: resultado incierto
    VERIFYING = "verifying"
    COMPLETED = "completed"
    FAILED = "failed"
    NEEDS_REVIEW = "needs_review"
    RELEASED = "released"          # devuelto al backend sin haber ejecutado la entrega
    DEFERRED = "deferred"          # aplazado con /defer (p. ej. el jugador aún no aceptó la amistad)

    @property
    def terminal(self) -> bool:
        return self in {JobStatus.COMPLETED, JobStatus.FAILED, JobStatus.NEEDS_REVIEW, JobStatus.RELEASED,
                        JobStatus.DEFERRED}


class Recipient(BaseModel):
    model_config = ConfigDict(extra="ignore")
    player_id: str = Field(min_length=1, max_length=64)
    nickname: str | None = Field(default=None, max_length=64)
    region: str | None = Field(default=None, max_length=16)

    @field_validator("player_id")
    @classmethod
    def _no_whitespace(cls, value: str) -> str:
        value = value.strip()
        if not value.isalnum():
            raise ValueError("player_id debe ser alfanumérico")
        return value


class Product(BaseModel):
    model_config = ConfigDict(extra="ignore")
    code: str = Field(min_length=1, max_length=64, pattern=r"^[A-Za-z0-9_\-]+$")
    name: str = Field(min_length=1, max_length=120)
    quantity: int = Field(default=1, ge=1, le=10000)
    category: str | None = None              # pase | caja | token (pestaña de regalos del juego)
    game_item_name: str | None = None        # texto EXACTO del artículo en el juego (catálogo de Horizons)
    unit_diamond_cost: int | None = None
    max_per_transaction: int | None = None


class Job(BaseModel):
    """Tarea recibida de `/agent/tasks/claim` (ver Job.from_task)."""

    model_config = ConfigDict(extra="ignore")
    job_id: str = Field(min_length=1, max_length=64, pattern=r"^[A-Za-z0-9_\-:.]+$")
    order_id: str = Field(min_length=1, max_length=64)
    type: str = "gift_delivery"
    workflow_id: str | None = None
    recipient: Recipient
    product: Product
    attempt: int = 1
    max_attempts: int | None = None
    lease_expires_at: datetime | None = None
    created_at: datetime | None = None
    simulated: bool = False          # True = NO tocar el juego ni el pedido real
    server_mode: str | None = None   # off | simulation | supervised | production (lo decide Horizons)
    phase: str = "gift"              # friend_request (fase A) | gift (fase B)
    chunk_index: int = 0             # 0-based
    chunk_count: int = 1
    defer_count: int = 0
    metadata: dict[str, Any] = Field(default_factory=dict)

    @field_validator("phase")
    @classmethod
    def _phase(cls, value: str) -> str:
        if value not in ("friend_request", "gift"):
            raise ValueError(f"fase desconocida: {value}")
        return value

    @property
    def dedupe_key(self) -> str:
        """Una tarea por pedido, fase y chunk (Horizons divide los regalos por maxPerTransaction)."""
        return f"{self.order_id}:{self.phase}:{self.chunk_index}"

    @classmethod
    def from_task(cls, task: dict[str, Any], *, simulate: bool = False, mode: str | None = None) -> "Job":
        """Convierte una tarea de `/agent/tasks/claim` (esquema Task de Horizons) en un Job interno."""
        task_mode = task.get("mode") or mode
        kind = str(task.get("kind") or "")

        def opt_int(name: str) -> int | None:
            value = task.get(name)
            return int(value) if value not in (None, "") else None
        return cls(
            job_id=str(task.get("id") or ""),
            order_id=str(task.get("orderCode") or task.get("orderId") or task.get("id") or ""),
            type=kind or "gift_delivery",
            recipient=Recipient(player_id=str(task.get("playerId") or ""),
                                region=(task.get("playerZone") or None)),
            product=Product(code=kind, name=str(task.get("gameItemName") or task.get("productName") or kind),
                            quantity=opt_int("quantity") or 1, category=task.get("category") or None,
                            game_item_name=task.get("gameItemName") or None,
                            unit_diamond_cost=opt_int("unitDiamondCost"),
                            max_per_transaction=opt_int("maxPerTransaction")),
            attempt=int(task.get("attempts") or 1),
            max_attempts=task.get("maxAttempts"),
            lease_expires_at=task.get("leaseExpiresAt") or None,
            created_at=task.get("createdAt") or None,
            # Cualquier señal de simulación del servidor obliga a simular.
            simulated=bool(simulate or task.get("simulated") or task_mode == "simulation"),
            server_mode=task_mode,
            phase=task.get("phase") or "gift",  # v1.0 no tenía fases: todo era regalo
            chunk_index=opt_int("chunkIndex") or 0,
            chunk_count=opt_int("chunkCount") or 1,
            defer_count=opt_int("deferCount") or 0,
            metadata={"orderId": task.get("orderId") or "", "orderCode": task.get("orderCode") or ""},
        )


class ApiUsage(BaseModel):
    """Bloque `usage` del resultado (esquema Usage de Horizons)."""
    model: str
    prompt_tokens: int = 0
    completion_tokens: int = 0
    cached_tokens: int = 0
    cost_usd: float = 0.0


class UsageRecord(BaseModel):
    record_id: str
    job_id: str | None
    order_id: str | None
    agent_id: str
    model: str
    request_id: str | None
    response_id: str | None
    created_at: str
    input_tokens: int = 0
    cached_input_tokens: int = 0
    output_tokens: int = 0
    reasoning_tokens: int = 0
    total_tokens: int = 0
    latency_ms: int = 0
    cost_usd: float = 0.0
    status: str = "ok"
    error: str | None = None
    purpose: str = ""


class VerificationResult(BaseModel):
    outcome: str  # success | failure | uncertain
    method: str
    details: dict[str, Any] = Field(default_factory=dict)
    evidence_ids: list[str] = Field(default_factory=list)


class JobOutcome(BaseModel):
    job_id: str
    order_id: str
    status: JobStatus
    error_code: str | None = None
    message: str = ""
    pre_commit: bool = True       # True si nunca se ejecutó el paso transaccional
    verification: VerificationResult | None = None
    approved_by: str | None = None
    friend_status: str | None = None        # fase friend_request: request_sent | already_friends | player_not_found
    delivered_quantity: int | None = None   # fase gift: unidades entregadas (None = desconocido)
    defer_reason: str | None = None         # status DEFERRED: motivo para /defer
    balance: int | None = None              # saldo de diamantes leído en pantalla
    actions: int = 0
    ai_requests: int = 0
    tokens_total: int = 0
    cost_usd: float = 0.0
    duration_s: float = 0.0

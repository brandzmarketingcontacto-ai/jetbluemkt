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

    @property
    def terminal(self) -> bool:
        return self in {JobStatus.COMPLETED, JobStatus.FAILED, JobStatus.NEEDS_REVIEW, JobStatus.RELEASED}


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
    quantity: int = Field(default=1, ge=1, le=1)


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
    metadata: dict[str, Any] = Field(default_factory=dict)

    @classmethod
    def from_task(cls, task: dict[str, Any], *, simulate: bool = False, mode: str | None = None) -> "Job":
        """Convierte una tarea de `/agent/tasks/claim` (esquema Task de Horizons) en un Job interno."""
        task_mode = task.get("mode") or mode
        kind = str(task.get("kind") or "")
        return cls(
            job_id=str(task.get("id") or ""),
            order_id=str(task.get("orderCode") or task.get("orderId") or task.get("id") or ""),
            type=kind or "gift_delivery",
            recipient=Recipient(player_id=str(task.get("playerId") or ""),
                                region=(task.get("playerZone") or None)),
            product=Product(code=kind, name=str(task.get("productName") or kind)),
            attempt=int(task.get("attempts") or 1),
            max_attempts=task.get("maxAttempts"),
            lease_expires_at=task.get("leaseExpiresAt") or None,
            created_at=task.get("createdAt") or None,
            # Cualquier señal de simulación del servidor obliga a simular.
            simulated=bool(simulate or task.get("simulated") or task_mode == "simulation"),
            server_mode=task_mode,
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
    actions: int = 0
    ai_requests: int = 0
    tokens_total: int = 0
    cost_usd: float = 0.0
    duration_s: float = 0.0

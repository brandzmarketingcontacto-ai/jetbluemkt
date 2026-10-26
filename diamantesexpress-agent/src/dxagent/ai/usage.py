"""UsageTracker: registro idempotente de tokens y costos de OpenAI con límites de presupuesto."""

from __future__ import annotations

import logging
import uuid
from datetime import datetime
from typing import Any

from dxagent.ai.pricing import PricingTable
from dxagent.database import LocalDatabase, _local_day_bounds_utc
from dxagent.models import ApiUsage, UsageRecord, iso_now

log = logging.getLogger(__name__)


class BudgetExceeded(Exception):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


class UsageTracker:
    def __init__(self, db: LocalDatabase, pricing: PricingTable, agent_id: str,
                 max_cost_per_order: float, daily_budget: float, max_requests_per_order: int,
                 reserve_per_request: float = 0.01):
        self.db = db
        self.pricing = pricing
        self.agent_id = agent_id
        self.max_cost_per_order = max_cost_per_order
        self.daily_budget = daily_budget
        self.max_requests_per_order = max_requests_per_order
        self.reserve = reserve_per_request

    def spent_today(self, now: datetime | None = None) -> float:
        start, end = _local_day_bounds_utc(now)
        return float(self.db.usage_totals(start, end).get("cost_usd") or 0.0)

    def job_totals(self, job_id: str) -> dict[str, Any]:
        return self.db.usage_totals(job_id=job_id)

    def check_budget(self, model: str, job_id: str | None) -> None:
        """Se llama ANTES de cada solicitud. Lanza BudgetExceeded si no se debe gastar más."""
        if self.pricing.price_for(model) is None:
            raise BudgetExceeded("PRICING_MISSING", f"No hay precio configurado para el modelo {model}; "
                                 "no se envían solicitudes sin control de costo.")
        if self.spent_today() + self.reserve > self.daily_budget:
            raise BudgetExceeded("DAILY_BUDGET", "Presupuesto diario de IA agotado")
        if job_id:
            totals = self.job_totals(job_id)
            if int(totals.get("requests") or 0) >= self.max_requests_per_order:
                raise BudgetExceeded("ORDER_REQUESTS", "Límite de solicitudes de IA por pedido alcanzado")
            if float(totals.get("cost_usd") or 0.0) + self.reserve > self.max_cost_per_order:
                raise BudgetExceeded("ORDER_BUDGET", "Costo máximo de IA por pedido alcanzado")

    def daily_budget_exhausted(self) -> bool:
        return self.spent_today() + self.reserve > self.daily_budget

    def record(self, *, model: str, job_id: str | None, order_id: str | None, usage: Any | None,
               request_id: str | None, response_id: str | None, latency_ms: int,
               status: str = "ok", error: str | None = None, purpose: str = "") -> UsageRecord:
        """Registra el uso REAL devuelto por la API (objeto `usage` de la Responses API)."""
        input_tokens = int(getattr(usage, "input_tokens", 0) or 0)
        output_tokens = int(getattr(usage, "output_tokens", 0) or 0)
        details = getattr(usage, "input_tokens_details", None)
        cached = int(getattr(details, "cached_tokens", 0) or 0)
        out_details = getattr(usage, "output_tokens_details", None)
        reasoning = int(getattr(out_details, "reasoning_tokens", 0) or 0)
        total = int(getattr(usage, "total_tokens", 0) or (input_tokens + output_tokens))
        cost = self.pricing.cost(model, input_tokens, cached, output_tokens) or 0.0
        record = UsageRecord(
            record_id=str(uuid.uuid4()), job_id=job_id, order_id=order_id, agent_id=self.agent_id, model=model,
            request_id=request_id, response_id=response_id, created_at=iso_now(),
            input_tokens=input_tokens, cached_input_tokens=cached, output_tokens=output_tokens,
            reasoning_tokens=reasoning, total_tokens=total, latency_ms=latency_ms, cost_usd=cost,
            status=status, error=error, purpose=purpose,
        )
        # Se guarda localmente; el total por tarea viaja en el bloque `usage` de /agent/tasks/{id}/result.
        self.db.insert_usage(record)
        log.info("Uso OpenAI registrado", extra={"model": model, "total_tokens": total, "cost_usd": cost,
                                                  "job_id": job_id, "status": status})
        return record


def usage_for_result(db: LocalDatabase, job_id: str, default_model: str) -> ApiUsage | None:
    """Suma el uso REAL de OpenAI de una tarea en el formato `Usage` de Horizons.

    prompt_tokens incluye los tokens en caché (la API de OpenAI los cuenta dentro de input_tokens);
    Horizons los descuenta de la entrada facturable. Se envía cost_usd calculado con pricing.yaml.
    """
    records = db.usage_for_job(job_id)
    if not records:
        return None
    by_model: dict[str, int] = {}
    for r in records:
        by_model[r["model"]] = by_model.get(r["model"], 0) + int(r["total_tokens"] or 0)
    return ApiUsage(
        model=max(by_model, key=by_model.get) if by_model else default_model,
        prompt_tokens=sum(int(r["input_tokens"] or 0) for r in records),
        completion_tokens=sum(int(r["output_tokens"] or 0) for r in records),
        cached_tokens=sum(int(r["cached_input_tokens"] or 0) for r in records),
        cost_usd=round(sum(float(r["cost_usd"] or 0) for r in records), 8),
    )

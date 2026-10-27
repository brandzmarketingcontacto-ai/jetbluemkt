"""JobQueueManager: reclamación atómica (`/agent/tasks/claim`), validación y cierre de tareas.

Reglas del contrato real de Horizons:
- El servidor entrega la siguiente tarea con un lease. Para devolverla sin ejecutar, el agente
  simplemente deja vencer el lease: el servidor la reintenta hasta maxAttempts y luego la pasa a
  manual_review. No existe un endpoint de liberación.
- El resultado final (completed | failed | uncertain) lleva el uso de OpenAI y la evidencia (hashes).
- Una entrega ya ejecutada NUNCA se repite localmente: si la tarea vuelve, se reenvía su resultado.
"""

from __future__ import annotations

import logging

from pydantic import ValidationError

from dxagent import __version__
from dxagent.ai.usage import usage_for_result
from dxagent.api.client import ClaimResult, HorizonsApiClient
from dxagent.database import LocalDatabase
from dxagent.engine.evidence import EvidenceManager
from dxagent.models import Job, JobOutcome, JobStatus, iso_now

log = logging.getLogger(__name__)
OUTCOME_FOR = {JobStatus.COMPLETED: "completed", JobStatus.FAILED: "failed", JobStatus.NEEDS_REVIEW: "uncertain"}


def result_key(job_id: str, local_attempt: int) -> str:
    return f"result:{job_id}:{local_attempt}"


class JobQueueManager:
    def __init__(self, db: LocalDatabase, api: HorizonsApiClient, instance: str, account_key: str,
                 evidence: EvidenceManager | None = None, default_model: str = ""):
        self.db = db
        self.api = api
        self.instance = instance or "default"
        self.account_key = account_key or "default"
        self.evidence = evidence
        self.default_model = default_model
        self.last_claim: ClaimResult | None = None

    def _lock_keys(self) -> list[str]:
        # Una sola tarea activa por instancia Android y por cuenta del juego.
        return [f"instance:{self.instance}", f"account:{self.account_key}"]

    def busy(self) -> bool:
        keys = set(self._lock_keys())
        return any(lock["lock_key"] in keys for lock in self.db.locks())

    def result_delivered(self, job_id: str) -> bool:
        row = self.db.get_job(job_id)
        if not row:
            return False
        item = self.db.outbox_item(result_key(job_id, row.get("local_attempts") or 1))
        return bool(item and item["state"] == "sent")

    async def next_job(self) -> Job | None:
        if self.busy():
            return None
        claim = await self.api.claim_task()
        self.last_claim = claim
        if not claim.task:
            return None
        raw = claim.task
        task_id = str(raw.get("id") or "")
        try:
            job = Job.from_task(raw, simulate=claim.simulate, mode=claim.mode)
        except ValidationError as exc:
            # La tarea es nuestra (lease) pero sus datos no son válidos: no se ejecuta nada.
            if task_id:
                self.db.insert_job(task_id, str(raw.get("orderCode") or task_id), raw, self.instance, self.account_key)
                self._enqueue_result(task_id, 1, {"outcome": "failed", "errorCode": "INVALID_TASK",
                                                  "message": "Datos de la tarea inválidos; no se ejecutó ninguna acción",
                                                  "errorMessage": str(exc)[:500],
                                                  "evidence": {"preCommit": True, "agentVersion": __version__}})
                self.db.update_job(task_id, status=JobStatus.FAILED, error_code="INVALID_TASK", finished_at=iso_now())
            self.db.add_alert(f"invalid_task:{task_id}", "WARNING", "INVALID_TASK",
                              f"Tarea {task_id} con datos inválidos ({exc.error_count()} errores)")
            return None

        known = self.db.get_job(job.job_id)
        payload = job.model_dump(mode="json")
        if known:
            status = JobStatus(known["status"])
            delivered = self.result_delivered(job.job_id)
            if status == JobStatus.COMPLETED or (status in (JobStatus.FAILED, JobStatus.NEEDS_REVIEW) and not delivered):
                # Ya se procesó: nunca se re-ejecuta. Se reenvía el resultado (idempotente en el servidor).
                self.db.outbox_requeue(result_key(job.job_id, known.get("local_attempts") or 1))
                self.db.add_event("WARNING", "job", f"La tarea {job.order_id} volvió a entregarse; se reenvía su "
                                                    "resultado sin repetir la operación", job.job_id)
                return None
            # RELEASED/DEFERRED (no se ejecutó nada: lease vencido o amistad pendiente) o un reintento manual del
            # SUPER_ADMIN tras un resultado ya recibido.
            self.db.reclaim_job(job.job_id, payload)
        else:
            if self.db.order_active_or_done(job.dedupe_key):
                # Horizons garantiza una tarea por pedido, fase y chunk; si aun así llega otra, un humano decide.
                self.db.insert_job(job.job_id, job.order_id, payload, self.instance, self.account_key,
                                   order_key=f"{job.dedupe_key}:dup:{job.job_id}", phase=job.phase,
                                   quantity=job.product.quantity)
                self.finish(job, JobOutcome(job_id=job.job_id, order_id=job.order_id, status=JobStatus.NEEDS_REVIEW,
                                            error_code="DUPLICATE_ORDER", pre_commit=True,
                                            message="Otra tarea del mismo pedido, fase y parte ya fue procesada o está "
                                                    "en curso; no se ejecutó ninguna acción"))
                self.db.add_alert(f"dup_order:{job.job_id}", "WARNING", "DUPLICATE_ORDER",
                                  f"El pedido {job.order_id} ya tenía otra tarea; {job.job_id} pasa a revisión")
                return None
            self.db.insert_job(job.job_id, job.order_id, payload, self.instance, self.account_key,
                               order_key=job.dedupe_key, phase=job.phase, quantity=job.product.quantity)
        if not all(self.db.acquire_lock(k, job.job_id) for k in self._lock_keys()):
            self.db.release_locks(job.job_id)
            self.db.update_job(job.job_id, status=JobStatus.RELEASED, error_code="RESOURCE_BUSY")
            return None
        if self.db.audit is not None:
            self.db.audit.append("claim", job_id=job.job_id, order=job.order_id, attempt=job.attempt,
                                 simulated=job.simulated, server_mode=job.server_mode)
        mode_txt = "SIMULADA" if job.simulated else job.server_mode
        what = ("solicitud de amistad" if job.phase == "friend_request" else
                f"regalo {job.product.quantity} × {job.product.name} (parte {job.chunk_index + 1}/{job.chunk_count})")
        self.db.add_event("INFO", "job", f"Tarea {job.order_id} reclamada: {what} ({mode_txt}, intento {job.attempt})",
                          job.job_id)
        return job

    def _enqueue_result(self, job_id: str, local_attempt: int, body: dict) -> None:
        self.db.enqueue("result", result_key(job_id, local_attempt), body, job_id)

    def build_result(self, job: Job, outcome: JobOutcome, workflow_id: str = "", engine_mode: str = "") -> dict:
        verification = outcome.verification
        details = (verification.details if verification else {}) or {}
        evidence = {
            "simulated": job.simulated,
            "agentVersion": __version__,
            "agentMode": engine_mode,
            "workflow": workflow_id,
            "preCommit": outcome.pre_commit,
            "approvedBy": outcome.approved_by,
            "actions": outcome.actions,
            "durationS": outcome.duration_s,
            "verification": {"outcome": verification.outcome, "method": verification.method,
                             "screen": details.get("screen"), "reference": details.get("reference"),
                             "mismatches": details.get("mismatches")} if verification else None,
            "files": self.evidence.summary(job.job_id) if self.evidence else [],
        }
        body: dict = {
            "outcome": OUTCOME_FOR[outcome.status],
            "message": (outcome.message or "")[:1000],
            "evidence": evidence,
        }
        if outcome.status == JobStatus.COMPLETED and details.get("reference"):
            body["giftCode"] = str(details["reference"])[:120]
        if job.phase == "friend_request":
            if outcome.friend_status:
                body["friendStatus"] = outcome.friend_status
        elif outcome.status == JobStatus.COMPLETED:
            # Horizons exige deliveredQuantity == quantity para aceptar completed en la fase gift.
            body["deliveredQuantity"] = outcome.delivered_quantity if outcome.delivered_quantity is not None \
                else job.product.quantity
        elif outcome.status == JobStatus.FAILED:
            body["deliveredQuantity"] = 0
        # uncertain: no se informa cantidad (no se sabe); Horizons lo pasa a revisión manual.
        if outcome.error_code:
            body["errorCode"] = outcome.error_code[:60]
            body["errorMessage"] = (outcome.message or outcome.error_code)[:500]
        usage = usage_for_result(self.db, job.job_id, self.default_model)
        if usage:
            body["usage"] = usage.model_dump()
        return body

    def finish(self, job: Job, outcome: JobOutcome, workflow_id: str = "", engine_mode: str = "") -> None:
        row = self.db.get_job(job.job_id) or {}
        attempt = row.get("local_attempts") or 1
        self.db.update_job(job.job_id, status=outcome.status, finished_at=iso_now(), error_code=outcome.error_code,
                           message=outcome.message, actions=outcome.actions, ai_requests=outcome.ai_requests,
                           tokens_total=outcome.tokens_total, cost_usd=outcome.cost_usd, duration_s=outcome.duration_s,
                           approved_by=outcome.approved_by, result_json=outcome.model_dump_json(),
                           friend_status=outcome.friend_status, delivered_quantity=outcome.delivered_quantity)
        if outcome.status == JobStatus.DEFERRED and not self.api.supports_defer:
            # Contrato v1.0 sin /defer: se devuelve dejando vencer el lease.
            outcome.status = JobStatus.RELEASED
            self.db.update_job(job.job_id, status=JobStatus.RELEASED)
        if outcome.status == JobStatus.RELEASED:
            # Nada se ejecutó: no se reporta resultado; al vencer el lease Horizons reintenta (hasta maxAttempts).
            pass
        elif outcome.status == JobStatus.DEFERRED:
            # Nada se ejecutó: se aplaza (no consume maxAttempts) hasta el próximo reintento de amistad.
            self.db.enqueue("defer", f"defer:{job.job_id}:{attempt}", {
                "reason": outcome.defer_reason or "deferred",
                "retryAfterSeconds": self.api.state.friend_retry_minutes * 60,
                "message": outcome.message}, job.job_id)
        else:
            self._enqueue_result(job.job_id, attempt, self.build_result(job, outcome, workflow_id, engine_mode))
        if self.db.audit is not None:
            self.db.audit.append("finish", job_id=job.job_id, status=outcome.status.value, code=outcome.error_code,
                                 pre_commit=outcome.pre_commit, approved_by=outcome.approved_by)
        level = {JobStatus.COMPLETED: "INFO", JobStatus.RELEASED: "WARNING",
                 JobStatus.DEFERRED: "INFO"}.get(outcome.status, "ERROR")
        self.db.add_event(level, "job", f"Tarea {job.order_id}: {outcome.status.value} {outcome.error_code or ''} "
                                        f"{outcome.message}".strip(), job.job_id)
        self.db.release_locks(job.job_id)

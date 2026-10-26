"""JobQueueManager: recepción, validación, reclamación atómica y cierre de trabajos."""

from __future__ import annotations

import logging

from pydantic import ValidationError

from dxagent.api.client import HorizonsApiClient
from dxagent.api.errors import ConflictError
from dxagent.database import LocalDatabase
from dxagent.models import Job, JobOutcome, JobStatus, iso_now

log = logging.getLogger(__name__)


class JobQueueManager:
    def __init__(self, db: LocalDatabase, api: HorizonsApiClient, instance: str, account_key: str,
                 max_local_attempts: int = 3):
        self.db = db
        self.api = api
        self.instance = instance or "default"
        self.account_key = account_key or "default"
        self.max_local_attempts = max_local_attempts
        self.last_pending: int | None = None

    def _lock_keys(self) -> list[str]:
        # Una sola tarea activa por instancia Android y por cuenta del juego.
        return [f"instance:{self.instance}", f"account:{self.account_key}"]

    def busy(self) -> bool:
        keys = set(self._lock_keys())
        return any(lock["lock_key"] in keys for lock in self.db.locks())

    async def next_job(self) -> Job | None:
        if self.busy():
            return None
        listed_jobs = await self.api.list_pending_jobs(limit=5)
        self.last_pending = len(listed_jobs)
        for raw in listed_jobs:
            job_id = str(raw.get("job_id", ""))
            try:
                listed = Job.model_validate(raw)
            except ValidationError as exc:
                self.db.enqueue("alert", f"alert:invalid_job:{job_id}", {
                    "severity": "warning", "code": "INVALID_JOB", "at": iso_now(),
                    "message": f"Trabajo {job_id} con datos inválidos: {exc.error_count()} errores"})
                continue
            known = self.db.get_job(listed.job_id)
            if known:
                retry_ok = (known["status"] == JobStatus.RELEASED.value
                            and (known.get("local_attempts") or 1) < self.max_local_attempts)
                if not retry_ok:
                    log.info("Trabajo duplicado ignorado: %s (%s)", listed.job_id, known["status"])
                    continue
            elif self.db.order_active_or_done(listed.order_id):
                self.db.enqueue("alert", f"alert:dup_order:{listed.job_id}", {
                    "severity": "warning", "code": "DUPLICATE_ORDER", "at": iso_now(),
                    "message": f"El pedido {listed.order_id} ya fue procesado o está en curso; se ignora {listed.job_id}"})
                continue
            try:
                job = await self.api.claim_job(listed.job_id, self.instance)
            except ConflictError:
                continue
            except ValidationError:
                continue
            if job.job_id != listed.job_id:
                continue
            payload = job.model_dump(mode="json")
            if known:
                self.db.reclaim_job(job.job_id, payload)
            else:
                self.db.insert_job(job.job_id, job.order_id, payload, self.instance, self.account_key)
            if not all(self.db.acquire_lock(k, job.job_id) for k in self._lock_keys()):
                self.db.release_locks(job.job_id)
                self.finish(job, JobOutcome(job_id=job.job_id, order_id=job.order_id, status=JobStatus.RELEASED,
                                            error_code="RESOURCE_BUSY", message="Instancia o cuenta ocupada"))
                continue
            self.db.add_event("INFO", "job", f"Pedido {job.order_id} reclamado", job.job_id)
            return job
        return None

    def finish(self, job: Job, outcome: JobOutcome) -> None:
        row = self.db.get_job(job.job_id) or {}
        attempt = row.get("local_attempts") or 1
        self.db.update_job(job.job_id, status=outcome.status, finished_at=iso_now(), error_code=outcome.error_code,
                           message=outcome.message, actions=outcome.actions, ai_requests=outcome.ai_requests,
                           tokens_total=outcome.tokens_total, cost_usd=outcome.cost_usd, duration_s=outcome.duration_s,
                           approved_by=outcome.approved_by, result_json=outcome.model_dump_json())
        if outcome.status == JobStatus.RELEASED:
            self.db.enqueue("release", f"release:{job.job_id}:{attempt}",
                            {"reason": f"{outcome.error_code}: {outcome.message}"}, job.job_id)
        else:
            body = outcome.model_dump(mode="json", exclude={"job_id", "order_id"})
            self.db.enqueue("complete", f"complete:{job.job_id}:{attempt}", body, job.job_id)
        level = {JobStatus.COMPLETED: "INFO", JobStatus.RELEASED: "WARNING"}.get(outcome.status, "ERROR")
        self.db.add_event(level, "job", f"Pedido {job.order_id}: {outcome.status.value} {outcome.error_code or ''} "
                                        f"{outcome.message}".strip(), job.job_id)
        self.db.release_locks(job.job_id)

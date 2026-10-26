"""RecoveryManager: recuperación tras cierres inesperados y control de reinicios seguros.

Regla principal: una operación transaccional nunca se repite. Si al arrancar existe un
trabajo con el paso transaccional iniciado y sin resultado verificado, pasa a revisión manual.
"""

from __future__ import annotations

import logging

from dxagent.database import LocalDatabase
from dxagent.models import JobStatus, iso_now

log = logging.getLogger(__name__)
UNCERTAIN_PHASES = {"commit_started", "commit_sent", "commit_uncertain"}


class RecoveryManager:
    def __init__(self, db: LocalDatabase):
        self.db = db

    def commit_in_doubt(self, job_id: str) -> bool:
        phases = [c["phase"] for c in self.db.checkpoints(job_id)]
        return any(p in UNCERTAIN_PHASES for p in phases) and "verified" not in phases

    def restart_is_safe(self) -> bool:
        """Solo es seguro reiniciar el emulador si ningún trabajo activo está en fase incierta."""
        for job in self.db.non_terminal_jobs():
            if job["status"] in (JobStatus.COMMITTING.value, JobStatus.VERIFYING.value):
                return False
            if self.commit_in_doubt(job["job_id"]):
                return False
        return True

    def recover_on_startup(self) -> list[dict]:
        """Clasifica los trabajos que quedaron abiertos y encola su reporte al backend."""
        actions = []
        for job in self.db.non_terminal_jobs():
            job_id = job["job_id"]
            attempt = job.get("local_attempts") or 1
            if job["status"] in (JobStatus.COMMITTING.value, JobStatus.VERIFYING.value) or self.commit_in_doubt(job_id):
                message = ("El agente se reinició durante la confirmación. El resultado es incierto: "
                           "NO se repetirá la operación. Requiere revisión manual.")
                outcome = {"status": JobStatus.NEEDS_REVIEW.value, "error_code": "RESTART_DURING_COMMIT",
                           "message": message, "pre_commit": False}
                self.db.update_job(job_id, status=JobStatus.NEEDS_REVIEW, error_code="RESTART_DURING_COMMIT",
                                   message=message, finished_at=iso_now())
                self.db.enqueue("complete", f"complete:{job_id}:{attempt}", outcome, job_id)
                self.db.enqueue("alert", f"alert:restart_commit:{job_id}", {
                    "severity": "critical", "code": "RESTART_DURING_COMMIT", "message": f"{job['order_id']}: {message}",
                    "at": iso_now()})
                self.db.add_event("ERROR", "recovery", message, job_id)
                actions.append({"job_id": job_id, "action": "needs_review"})
            else:
                reason = "Reinicio del agente antes del paso de entrega; se devuelve el trabajo sin ejecutar."
                self.db.update_job(job_id, status=JobStatus.RELEASED, error_code="AGENT_RESTART", message=reason,
                                   finished_at=iso_now())
                self.db.enqueue("release", f"release:{job_id}:{attempt}", {"reason": reason}, job_id)
                self.db.add_event("WARNING", "recovery", reason, job_id)
                actions.append({"job_id": job_id, "action": "released"})
            self.db.release_locks(job_id)
        if actions:
            log.warning("Recuperación al iniciar", extra={"recovered": actions})
        return actions

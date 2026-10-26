"""RecoveryManager: recuperación tras cierres inesperados y control de reinicios seguros.

Regla principal: una operación transaccional nunca se repite. Si al arrancar existe una tarea con
el paso transaccional iniciado y sin resultado verificado, se reporta `uncertain` (Horizons la pasa
SIEMPRE a revisión manual). Las tareas que no llegaron al envío se abandonan localmente: al vencer
el lease, Horizons las reintenta.
"""

from __future__ import annotations

import json
import logging

from dxagent import __version__
from dxagent.database import LocalDatabase
from dxagent.models import JobStatus, iso_now

log = logging.getLogger(__name__)
UNCERTAIN_PHASES = {"commit_started", "commit_sent", "commit_uncertain"}
RESTART_MESSAGE = ("El agente se reinició durante la confirmación. El resultado es incierto: NO se repetirá "
                   "la operación. Requiere revisión manual.")


def uncertain_result(code: str, message: str, simulated: bool | None = None) -> dict:
    return {"outcome": "uncertain", "errorCode": code, "errorMessage": message[:500], "message": message[:1000],
            "evidence": {"preCommit": False, "simulated": simulated, "agentVersion": __version__}}


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

    @staticmethod
    def _simulated(row: dict) -> bool | None:
        try:
            return bool(json.loads(row.get("payload_json") or "{}").get("simulated"))
        except ValueError:
            return None

    def recover_on_startup(self) -> list[dict]:
        """Clasifica las tareas locales que quedaron abiertas y encola su reporte cuando corresponde."""
        actions = []
        for job in self.db.non_terminal_jobs():
            job_id = job["job_id"]
            attempt = job.get("local_attempts") or 1
            if job["status"] in (JobStatus.COMMITTING.value, JobStatus.VERIFYING.value) or self.commit_in_doubt(job_id):
                self.db.update_job(job_id, status=JobStatus.NEEDS_REVIEW, error_code="RESTART_DURING_COMMIT",
                                   message=RESTART_MESSAGE, finished_at=iso_now())
                self.db.enqueue("result", f"result:{job_id}:{attempt}",
                                uncertain_result("RESTART_DURING_COMMIT", RESTART_MESSAGE, self._simulated(job)), job_id)
                self.db.add_alert(f"restart_commit:{job_id}", "CRITICAL", "RESTART_DURING_COMMIT",
                                  f"{job['order_id']}: {RESTART_MESSAGE}", job_id)
                actions.append({"job_id": job_id, "action": "uncertain"})
            else:
                reason = "Reinicio del agente antes del paso de entrega; la tarea vuelve al servidor al vencer el lease."
                self.db.update_job(job_id, status=JobStatus.RELEASED, error_code="AGENT_RESTART", message=reason,
                                   finished_at=iso_now())
                self.db.add_event("WARNING", "recovery", reason, job_id)
                actions.append({"job_id": job_id, "action": "released"})
            self.db.release_locks(job_id)
        if actions:
            log.warning("Recuperación al iniciar", extra={"recovered": actions})
        return actions

    def reconcile_with_server(self, server_tasks: list[dict]) -> list[dict]:
        """Compara `/agent/tasks/current` con el estado local (tras reinicio o pérdida de la base local)."""
        actions = []
        for task in server_tasks:
            task_id = str(task.get("id") or "")
            if not task_id:
                continue
            row = self.db.get_job(task_id)
            if row is None:
                # No sabemos qué pasó con esta tarea (p. ej. base local perdida): ante duda, uncertain.
                message = "El agente no tiene registro local de esta tarea reclamada; estado desconocido."
                self.db.insert_job(task_id, str(task.get("orderCode") or task_id), task, "default", "default")
                self.db.update_job(task_id, status=JobStatus.NEEDS_REVIEW, error_code="UNKNOWN_LOCAL_STATE",
                                   message=message, finished_at=iso_now())
                self.db.enqueue("result", f"result:{task_id}:1",
                                uncertain_result("UNKNOWN_LOCAL_STATE", message, task.get("simulated")), task_id)
                self.db.add_alert(f"unknown_task:{task_id}", "CRITICAL", "UNKNOWN_LOCAL_STATE",
                                  f"{task.get('orderCode') or task_id}: {message}", task_id)
                actions.append({"job_id": task_id, "action": "uncertain"})
            elif row["status"] in (JobStatus.COMPLETED.value, JobStatus.FAILED.value, JobStatus.NEEDS_REVIEW.value):
                key = f"result:{task_id}:{row.get('local_attempts') or 1}"
                if self.db.outbox_item(key) is not None:
                    self.db.outbox_requeue(key)
                    actions.append({"job_id": task_id, "action": "resend_result"})
        return actions

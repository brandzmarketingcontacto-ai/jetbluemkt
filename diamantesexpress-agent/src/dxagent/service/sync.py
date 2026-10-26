"""Sincronización de eventos pendientes (outbox) con DiamantesExpress.

Progreso y resultados se escriben primero en SQLite y luego se envían en orden FIFO. Así una
pérdida de Internet o un reinicio no pierde información. `/agent/tasks/{id}/result` es idempotente
en el servidor (reintentar devuelve duplicate:true), por lo que reenviar nunca duplica.
"""

from __future__ import annotations

import json
import logging
import time

from dxagent.api.client import HorizonsApiClient
from dxagent.api.errors import ApiError, ApiUnavailable, AuthError, ConflictError, NotFoundError
from dxagent.database import LocalDatabase

log = logging.getLogger(__name__)
MAX_BACKOFF = 300.0


class OutboxSync:
    def __init__(self, db: LocalDatabase, api: HorizonsApiClient, clock=time.time):
        self.db = db
        self.api = api
        self.clock = clock
        self.last_success: float | None = None

    async def flush(self, limit: int = 50) -> int:
        """Envía los elementos pendientes en orden. Devuelve cuántos se enviaron."""
        # Orden FIFO estricto: si el primer pendiente está en espera (backoff), no se adelanta nada.
        now = self.clock()
        items = []
        for item in self.db.due_outbox(float("inf"), limit):
            if item["next_attempt_at"] > now:
                break
            items.append(item)
        sent = 0
        for item in items:
            try:
                await self._dispatch(item)
            except AuthError:
                raise
            except ApiUnavailable as exc:
                delay = min(MAX_BACKOFF, 2 ** min(item["attempts"] + 1, 9))
                self.db.outbox_retry(item["id"], self.clock() + delay, str(exc))
                return sent  # sin conexión: se conserva el orden y se reintenta luego
            except ConflictError as exc:
                self._conflict(item, exc)
            except NotFoundError as exc:
                self.db.outbox_sent(item["id"], "rejected", str(exc))
                self.db.add_event("ERROR", "sync", f"La tarea ya no existe en el servidor ({item['kind']})",
                                  item["job_id"])
            except (ApiError, ValueError) as exc:
                if item["attempts"] >= 5:
                    self.db.outbox_sent(item["id"], "rejected", str(exc))
                    self.db.add_event("ERROR", "sync", f"Envío descartado ({item['kind']}): {exc}", item["job_id"])
                else:
                    self.db.outbox_retry(item["id"], self.clock() + 30, str(exc))
            else:
                self.db.outbox_sent(item["id"])
                sent += 1
                self.last_success = self.clock()
        return sent

    def _conflict(self, item: dict, exc: ConflictError) -> None:
        code = (exc.body or {}).get("error") if isinstance(exc.body, dict) else None
        self.db.outbox_sent(item["id"], "conflict", str(exc))
        if item["kind"] == "progress" and code == "task_already_finished":
            return  # progreso tardío de una tarea ya cerrada: sin efecto
        self.db.add_alert(f"conflict:{item['idem_key']}", "CRITICAL" if item["kind"] == "result" else "ERROR",
                          code or "CONFLICT", f"El servidor rechazó {item['kind']} ({code}). Revise la tarea "
                          "en Automatización: otro agente puede tenerla.", item["job_id"])

    async def flush_all(self, max_rounds: int = 1000) -> int:
        total = 0
        for _ in range(max_rounds):
            sent = await self.flush()
            total += sent
            if sent == 0:
                break
        return total

    async def _dispatch(self, item: dict) -> None:
        kind, job_id = item["kind"], item["job_id"]
        payload = json.loads(item["payload_json"])
        if kind == "progress":
            await self.api.report_progress(job_id, payload["status"], payload.get("message", ""),
                                           payload.get("evidence"), item["idem_key"])
        elif kind == "result":
            response = await self.api.report_result(job_id, payload, item["idem_key"])
            self.db.mark_usage_synced([u["record_id"] for u in self.db.usage_for_job(job_id)])
            server_task = (response or {}).get("task") or {}
            if server_task.get("status"):
                self.db.update_job(job_id, server_status=server_task["status"])
        else:
            raise ValueError(f"Tipo de outbox desconocido: {kind}")

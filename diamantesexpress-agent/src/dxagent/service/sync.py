"""Sincronización de eventos pendientes (outbox) con DiamantesExpress.

Todo lo que se reporta al backend (progreso, consumo, evidencias, resultados, alertas) se
escribe primero en SQLite y luego se envía con su Idempotency-Key. Así una pérdida de
Internet o un reinicio no pierde ni duplica información.
"""

from __future__ import annotations

import json
import logging
import time

from dxagent.api.client import HorizonsApiClient
from dxagent.api.errors import ApiError, ApiUnavailable, AuthError, ConflictError
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
        i = 0
        while i < len(items):
            item = items[i]
            batch = [item]
            if item["kind"] == "usage":
                while i + len(batch) < len(items) and items[i + len(batch)]["kind"] == "usage" and len(batch) < 20:
                    batch.append(items[i + len(batch)])
            try:
                await self._dispatch(batch)
            except AuthError:
                raise
            except ApiUnavailable as exc:
                for b in batch:
                    delay = min(MAX_BACKOFF, 2 ** min(b["attempts"] + 1, 9))
                    self.db.outbox_retry(b["id"], self.clock() + delay, str(exc))
                return sent  # sin conexión: se conserva el orden y se reintenta luego
            except ConflictError as exc:
                for b in batch:
                    self.db.outbox_sent(b["id"], "conflict", str(exc))
                    self.db.add_event("ERROR", "sync", f"Conflicto al enviar {b['kind']}: {exc}", b["job_id"])
            except (ApiError, OSError, ValueError) as exc:
                for b in batch:
                    if b["attempts"] >= 5:
                        self.db.outbox_sent(b["id"], "rejected", str(exc))
                        self.db.add_event("ERROR", "sync", f"Envío descartado ({b['kind']}): {exc}", b["job_id"])
                    else:
                        self.db.outbox_retry(b["id"], self.clock() + 30, str(exc))
            else:
                for b in batch:
                    self.db.outbox_sent(b["id"])
                sent += len(batch)
                self.last_success = self.clock()
            i += len(batch)
        return sent

    async def flush_all(self, max_rounds: int = 1000) -> int:
        total = 0
        for _ in range(max_rounds):
            sent = await self.flush()
            total += sent
            if sent == 0:
                break
        return total

    async def _dispatch(self, batch: list[dict]) -> None:
        item = batch[0]
        kind, job_id = item["kind"], item["job_id"]
        payload = json.loads(item["payload_json"])
        if kind == "usage":
            records = [json.loads(b["payload_json"]) for b in batch]
            idem = "usage:" + ",".join(sorted(r["record_id"] for r in records))
            if len(records) == 1:
                idem = item["idem_key"]
            await self.api.report_usage(records, idem)
            self.db.mark_usage_synced([r["record_id"] for r in records])
        elif kind == "progress":
            await self.api.report_progress(job_id, payload, item["idem_key"])
        elif kind == "evidence":
            await self.api.upload_evidence(job_id, payload["metadata"], payload["path"], item["idem_key"])
        elif kind == "complete":
            await self.api.complete_job(job_id, payload, item["idem_key"])
        elif kind == "release":
            await self.api.release_job(job_id, payload.get("reason", ""), item["idem_key"])
        elif kind == "alert":
            await self.api.report_alert(payload, item["idem_key"])
        else:
            raise ValueError(f"Tipo de outbox desconocido: {kind}")

"""Lectura del estado del servicio en un hilo aparte: la interfaz nunca bloquea al agente
ni se bloquea a sí misma esperando a la base de datos."""

from __future__ import annotations

import time
from datetime import datetime, timezone
from typing import Any

from PySide6.QtCore import QObject, QThread, QTimer, Signal, Slot

from dxagent.database import LocalDatabase

SERVICE_STALE_SECONDS = 10.0


def snapshot(db: LocalDatabase) -> dict[str, Any]:
    agent_row = db.get_status_row("agent")
    agent = db.get_status("agent") or {}
    alive = False
    if agent_row:
        updated = datetime.fromisoformat(agent_row["updated_at"])
        alive = (datetime.now(timezone.utc) - updated).total_seconds() < SERVICE_STALE_SECONDS
    return {
        "agent": agent,
        "service_alive": alive and agent.get("state") != "STOPPED",
        "current_job": db.get_status("current_job"),
        "jobs_today": db.job_stats_today(),
        "usage_today": db.usage_today(),
        "jobs": db.recent_jobs(200),
        "usage": db.recent_usage(200),
        "approvals": db.pending_approvals(),
        "events": db.recent_events(200),
        "outbox_pending": db.outbox_pending_count(),
        "ts": time.time(),
    }


class _Worker(QObject):
    ready = Signal(dict)

    def __init__(self, db_path: str):
        super().__init__()
        self.db_path = db_path
        self.db: LocalDatabase | None = None

    @Slot()
    def poll(self) -> None:
        try:
            if self.db is None:
                self.db = LocalDatabase(self.db_path)
            self.ready.emit(snapshot(self.db))
        except Exception as exc:  # la interfaz sigue viva aunque la BD esté ocupada
            self.ready.emit({"error": str(exc)})


class StatusPoller(QObject):
    updated = Signal(dict)
    _tick = Signal()

    def __init__(self, db_path: str, interval_ms: int = 1000):
        super().__init__()
        self.thread = QThread()
        self.worker = _Worker(db_path)
        self.worker.moveToThread(self.thread)
        self.worker.ready.connect(self.updated)
        self._tick.connect(self.worker.poll)
        self.timer = QTimer(self)
        self.timer.setInterval(interval_ms)
        self.timer.timeout.connect(self._tick.emit)

    def start(self) -> None:
        self.thread.start()
        self.timer.start()
        self._tick.emit()

    def stop(self) -> None:
        self.timer.stop()
        self.thread.quit()
        self.thread.wait(3000)

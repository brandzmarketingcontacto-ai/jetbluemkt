"""LocalDatabase: persistencia SQLite (estado, checkpoints, consumo, outbox, aprobaciones, comandos).

SQLite en modo WAL con synchronous=FULL: un checkpoint escrito antes de una operación
transaccional sobrevive a un cierre inesperado o a un corte de energía.
La interfaz y el servicio comparten esta base de datos como canal de estado y comandos,
por lo que la ventana nunca bloquea al proceso de ejecución.
"""

from __future__ import annotations

import json
import sqlite3
import threading
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable

from dxagent.models import JobStatus, UsageRecord, iso_now

SCHEMA = """
CREATE TABLE IF NOT EXISTS jobs (
    job_id TEXT PRIMARY KEY,
    order_id TEXT NOT NULL,
    status TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    instance TEXT,
    account_key TEXT,
    claimed_at TEXT,
    started_at TEXT,
    finished_at TEXT,
    local_attempts INTEGER DEFAULT 1,
    actions INTEGER DEFAULT 0,
    ai_requests INTEGER DEFAULT 0,
    tokens_total INTEGER DEFAULT 0,
    cost_usd REAL DEFAULT 0,
    duration_s REAL,
    error_code TEXT,
    message TEXT,
    approved_by TEXT,
    result_json TEXT,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status);
CREATE TABLE IF NOT EXISTS checkpoints (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id TEXT NOT NULL,
    step_id TEXT NOT NULL,
    phase TEXT NOT NULL,
    data_json TEXT,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ckpt_job ON checkpoints(job_id);
CREATE TABLE IF NOT EXISTS usage (
    record_id TEXT PRIMARY KEY,
    job_id TEXT, order_id TEXT, agent_id TEXT, model TEXT,
    request_id TEXT, response_id TEXT, created_at TEXT NOT NULL,
    input_tokens INTEGER, cached_input_tokens INTEGER, output_tokens INTEGER,
    reasoning_tokens INTEGER, total_tokens INTEGER, latency_ms INTEGER,
    cost_usd REAL, status TEXT, error TEXT, purpose TEXT,
    synced INTEGER DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_usage_created ON usage(created_at);
CREATE TABLE IF NOT EXISTS outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    idem_key TEXT UNIQUE NOT NULL,
    kind TEXT NOT NULL,
    job_id TEXT,
    payload_json TEXT NOT NULL,
    attempts INTEGER DEFAULT 0,
    next_attempt_at REAL DEFAULT 0,
    created_at TEXT NOT NULL,
    sent_at TEXT,
    state TEXT DEFAULT 'pending',
    last_error TEXT
);
CREATE INDEX IF NOT EXISTS idx_outbox_state ON outbox(state, id);
CREATE TABLE IF NOT EXISTS approvals (
    approval_id TEXT PRIMARY KEY,
    job_id TEXT NOT NULL,
    step_id TEXT NOT NULL,
    summary_json TEXT NOT NULL,
    status TEXT NOT NULL,
    requested_at TEXT NOT NULL,
    decided_at TEXT,
    decided_by TEXT,
    note TEXT
);
CREATE TABLE IF NOT EXISTS commands (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    command TEXT NOT NULL,
    args_json TEXT,
    issued_by TEXT,
    created_at TEXT NOT NULL,
    processed_at TEXT,
    result TEXT
);
CREATE TABLE IF NOT EXISTS status (
    key TEXT PRIMARY KEY,
    value_json TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS locks (
    lock_key TEXT PRIMARY KEY,
    job_id TEXT NOT NULL,
    acquired_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ts TEXT NOT NULL,
    level TEXT NOT NULL,
    category TEXT NOT NULL,
    message TEXT NOT NULL,
    job_id TEXT
);
CREATE TABLE IF NOT EXISTS evidence (
    evidence_id TEXT PRIMARY KEY,
    job_id TEXT NOT NULL,
    kind TEXT NOT NULL,
    path TEXT NOT NULL,
    sha256 TEXT NOT NULL,
    label TEXT,
    created_at TEXT NOT NULL
);
"""


def _local_day_bounds_utc(now: datetime | None = None) -> tuple[str, str]:
    now = (now or datetime.now()).astimezone()
    start = now.replace(hour=0, minute=0, second=0, microsecond=0)
    from datetime import timedelta
    end = start + timedelta(days=1)
    return start.astimezone(timezone.utc).isoformat(), end.astimezone(timezone.utc).isoformat()


class LocalDatabase:
    def __init__(self, path: Path | str):
        self.path = str(path)
        self._lock = threading.RLock()
        self._conn = sqlite3.connect(self.path, check_same_thread=False, timeout=15, isolation_level=None)
        self._conn.row_factory = sqlite3.Row
        with self._lock:
            self._conn.execute("PRAGMA journal_mode=WAL")
            self._conn.execute("PRAGMA synchronous=FULL")
            self._conn.execute("PRAGMA busy_timeout=15000")
            self._conn.executescript(SCHEMA)

    def close(self) -> None:
        with self._lock:
            self._conn.close()

    # ------------------------------------------------------------------ helpers
    def _exec(self, sql: str, params: Iterable[Any] = ()) -> sqlite3.Cursor:
        with self._lock:
            return self._conn.execute(sql, tuple(params))

    def _all(self, sql: str, params: Iterable[Any] = ()) -> list[dict[str, Any]]:
        with self._lock:
            return [dict(r) for r in self._conn.execute(sql, tuple(params)).fetchall()]

    def _one(self, sql: str, params: Iterable[Any] = ()) -> dict[str, Any] | None:
        with self._lock:
            row = self._conn.execute(sql, tuple(params)).fetchone()
        return dict(row) if row else None

    # --------------------------------------------------------------------- jobs
    def insert_job(self, job_id: str, order_id: str, payload: dict, instance: str, account_key: str) -> bool:
        """Registra un trabajo. Devuelve False si ya existía (pedido duplicado)."""
        now = iso_now()
        try:
            self._exec(
                "INSERT INTO jobs(job_id, order_id, status, payload_json, instance, account_key, claimed_at, updated_at)"
                " VALUES (?,?,?,?,?,?,?,?)",
                (job_id, order_id, JobStatus.CLAIMED.value, json.dumps(payload, default=str), instance, account_key, now, now),
            )
            return True
        except sqlite3.IntegrityError:
            return False

    def reclaim_job(self, job_id: str, payload: dict) -> None:
        """Un trabajo liberado vuelve a reclamarse: nuevo intento local."""
        self._exec("UPDATE jobs SET status=?, payload_json=?, local_attempts=local_attempts+1, claimed_at=?,"
                   " started_at=NULL, finished_at=NULL, error_code=NULL, message=NULL, updated_at=? WHERE job_id=?",
                   (JobStatus.CLAIMED.value, json.dumps(payload, default=str), iso_now(), iso_now(), job_id))

    def get_job(self, job_id: str) -> dict[str, Any] | None:
        return self._one("SELECT * FROM jobs WHERE job_id=?", (job_id,))

    def job_known(self, job_id: str) -> bool:
        return self.get_job(job_id) is not None

    def order_active_or_done(self, order_id: str) -> bool:
        row = self._one("SELECT 1 FROM jobs WHERE order_id=? AND status NOT IN (?, ?) LIMIT 1",
                        (order_id, JobStatus.RELEASED.value, JobStatus.FAILED.value))
        return row is not None

    def update_job(self, job_id: str, **fields: Any) -> None:
        if not fields:
            return
        fields["updated_at"] = iso_now()
        if isinstance(fields.get("status"), JobStatus):
            fields["status"] = fields["status"].value
        cols = ", ".join(f"{k}=?" for k in fields)
        self._exec(f"UPDATE jobs SET {cols} WHERE job_id=?", (*fields.values(), job_id))

    def non_terminal_jobs(self) -> list[dict[str, Any]]:
        terminal = [s.value for s in JobStatus if s.terminal]
        marks = ",".join("?" * len(terminal))
        return self._all(f"SELECT * FROM jobs WHERE status NOT IN ({marks}) ORDER BY claimed_at", terminal)

    def recent_jobs(self, limit: int = 200) -> list[dict[str, Any]]:
        return self._all("SELECT * FROM jobs ORDER BY updated_at DESC LIMIT ?", (limit,))

    def job_stats_today(self) -> dict[str, Any]:
        start, end = _local_day_bounds_utc()
        rows = self._all("SELECT status, COUNT(*) c, AVG(duration_s) d FROM jobs WHERE claimed_at>=? AND claimed_at<? GROUP BY status",
                         (start, end))
        stats = {r["status"]: r["c"] for r in rows}
        done = self._one("SELECT AVG(duration_s) d FROM jobs WHERE status=? AND claimed_at>=? AND claimed_at<?",
                         (JobStatus.COMPLETED.value, start, end))
        return {
            "completed": stats.get(JobStatus.COMPLETED.value, 0),
            "failed": stats.get(JobStatus.FAILED.value, 0),
            "needs_review": stats.get(JobStatus.NEEDS_REVIEW.value, 0),
            "released": stats.get(JobStatus.RELEASED.value, 0),
            "avg_duration_s": round(done["d"], 1) if done and done["d"] else 0.0,
        }

    # -------------------------------------------------------------- checkpoints
    def add_checkpoint(self, job_id: str, step_id: str, phase: str, data: dict | None = None) -> None:
        self._exec("INSERT INTO checkpoints(job_id, step_id, phase, data_json, created_at) VALUES (?,?,?,?,?)",
                   (job_id, step_id, phase, json.dumps(data or {}, default=str), iso_now()))

    def checkpoints(self, job_id: str) -> list[dict[str, Any]]:
        return self._all("SELECT * FROM checkpoints WHERE job_id=? ORDER BY id", (job_id,))

    # -------------------------------------------------------------------- usage
    def insert_usage(self, record: UsageRecord) -> bool:
        data = record.model_dump()
        cols = ",".join(data)
        try:
            self._exec(f"INSERT INTO usage({cols}) VALUES ({','.join('?' * len(data))})", data.values())
            return True
        except sqlite3.IntegrityError:
            return False

    def mark_usage_synced(self, record_ids: list[str]) -> None:
        for rid in record_ids:
            self._exec("UPDATE usage SET synced=1 WHERE record_id=?", (rid,))

    def usage_totals(self, since_iso: str | None = None, until_iso: str | None = None,
                     job_id: str | None = None) -> dict[str, Any]:
        where, params = ["1=1"], []
        if since_iso:
            where.append("created_at>=?"); params.append(since_iso)
        if until_iso:
            where.append("created_at<?"); params.append(until_iso)
        if job_id:
            where.append("job_id=?"); params.append(job_id)
        row = self._one(
            "SELECT COUNT(*) requests, COALESCE(SUM(input_tokens),0) input_tokens,"
            " COALESCE(SUM(cached_input_tokens),0) cached_input_tokens, COALESCE(SUM(output_tokens),0) output_tokens,"
            " COALESCE(SUM(total_tokens),0) total_tokens, COALESCE(SUM(cost_usd),0) cost_usd,"
            " COALESCE(SUM(CASE WHEN synced=1 THEN 1 ELSE 0 END),0) synced"
            f" FROM usage WHERE {' AND '.join(where)}", params)
        return row or {}

    def usage_today(self) -> dict[str, Any]:
        start, end = _local_day_bounds_utc()
        return self.usage_totals(start, end)

    def recent_usage(self, limit: int = 200) -> list[dict[str, Any]]:
        return self._all("SELECT * FROM usage ORDER BY created_at DESC LIMIT ?", (limit,))

    # ------------------------------------------------------------------- outbox
    def enqueue(self, kind: str, idem_key: str, payload: dict, job_id: str | None = None) -> bool:
        try:
            self._exec("INSERT INTO outbox(idem_key, kind, job_id, payload_json, created_at) VALUES (?,?,?,?,?)",
                       (idem_key, kind, job_id, json.dumps(payload, default=str), iso_now()))
            return True
        except sqlite3.IntegrityError:
            return False  # ya encolado: idempotente

    def due_outbox(self, now_ts: float, limit: int = 50) -> list[dict[str, Any]]:
        return self._all("SELECT * FROM outbox WHERE state='pending' AND next_attempt_at<=? ORDER BY id LIMIT ?",
                         (now_ts, limit))

    def outbox_sent(self, item_id: int, state: str = "sent", error: str | None = None) -> None:
        self._exec("UPDATE outbox SET state=?, sent_at=?, last_error=? WHERE id=?", (state, iso_now(), error, item_id))

    def outbox_retry(self, item_id: int, next_attempt_at: float, error: str) -> None:
        self._exec("UPDATE outbox SET attempts=attempts+1, next_attempt_at=?, last_error=? WHERE id=?",
                   (next_attempt_at, error[:500], item_id))

    def outbox_pending_count(self) -> int:
        row = self._one("SELECT COUNT(*) c FROM outbox WHERE state='pending'")
        return int(row["c"]) if row else 0

    def outbox_items(self, kind: str | None = None) -> list[dict[str, Any]]:
        if kind:
            return self._all("SELECT * FROM outbox WHERE kind=? ORDER BY id", (kind,))
        return self._all("SELECT * FROM outbox ORDER BY id")

    # ---------------------------------------------------------------- approvals
    def create_approval(self, approval_id: str, job_id: str, step_id: str, summary: dict) -> None:
        self._exec("INSERT OR IGNORE INTO approvals(approval_id, job_id, step_id, summary_json, status, requested_at)"
                   " VALUES (?,?,?,?,?,?)", (approval_id, job_id, step_id, json.dumps(summary, default=str), "pending", iso_now()))

    def decide_approval(self, approval_id: str, approved: bool, decided_by: str, note: str = "") -> bool:
        cur = self._exec("UPDATE approvals SET status=?, decided_at=?, decided_by=?, note=? WHERE approval_id=? AND status='pending'",
                         ("approved" if approved else "rejected", iso_now(), decided_by, note, approval_id))
        return cur.rowcount == 1

    def expire_approval(self, approval_id: str) -> None:
        self._exec("UPDATE approvals SET status='expired', decided_at=? WHERE approval_id=? AND status='pending'",
                   (iso_now(), approval_id))

    def get_approval(self, approval_id: str) -> dict[str, Any] | None:
        return self._one("SELECT * FROM approvals WHERE approval_id=?", (approval_id,))

    def pending_approvals(self) -> list[dict[str, Any]]:
        return self._all("SELECT * FROM approvals WHERE status='pending' ORDER BY requested_at")

    # ----------------------------------------------------------------- commands
    def push_command(self, command: str, args: dict | None = None, issued_by: str = "") -> int:
        cur = self._exec("INSERT INTO commands(command, args_json, issued_by, created_at) VALUES (?,?,?,?)",
                         (command, json.dumps(args or {}), issued_by, iso_now()))
        return int(cur.lastrowid)

    def pop_commands(self) -> list[dict[str, Any]]:
        rows = self._all("SELECT * FROM commands WHERE processed_at IS NULL ORDER BY id")
        for row in rows:
            self._exec("UPDATE commands SET processed_at=? WHERE id=?", (iso_now(), row["id"]))
        return rows

    def command_result(self, command_id: int, result: str) -> None:
        self._exec("UPDATE commands SET result=? WHERE id=?", (result, command_id))

    # ------------------------------------------------------------------- status
    def set_status(self, key: str, value: Any) -> None:
        self._exec("INSERT INTO status(key, value_json, updated_at) VALUES (?,?,?)"
                   " ON CONFLICT(key) DO UPDATE SET value_json=excluded.value_json, updated_at=excluded.updated_at",
                   (key, json.dumps(value, default=str), iso_now()))

    def get_status(self, key: str, default: Any = None) -> Any:
        row = self._one("SELECT value_json FROM status WHERE key=?", (key,))
        return json.loads(row["value_json"]) if row else default

    def get_status_row(self, key: str) -> dict[str, Any] | None:
        return self._one("SELECT * FROM status WHERE key=?", (key,))

    # -------------------------------------------------------------------- locks
    def acquire_lock(self, lock_key: str, job_id: str) -> bool:
        try:
            self._exec("INSERT INTO locks(lock_key, job_id, acquired_at) VALUES (?,?,?)", (lock_key, job_id, iso_now()))
            return True
        except sqlite3.IntegrityError:
            row = self._one("SELECT job_id FROM locks WHERE lock_key=?", (lock_key,))
            return bool(row and row["job_id"] == job_id)

    def release_locks(self, job_id: str) -> None:
        self._exec("DELETE FROM locks WHERE job_id=?", (job_id,))

    def locks(self) -> list[dict[str, Any]]:
        return self._all("SELECT * FROM locks")

    # ------------------------------------------------------------------- events
    def add_event(self, level: str, category: str, message: str, job_id: str | None = None) -> None:
        self._exec("INSERT INTO events(ts, level, category, message, job_id) VALUES (?,?,?,?,?)",
                   (iso_now(), level, category, message, job_id))

    def recent_events(self, limit: int = 200) -> list[dict[str, Any]]:
        return self._all("SELECT * FROM events ORDER BY id DESC LIMIT ?", (limit,))

    # ----------------------------------------------------------------- evidence
    def add_evidence(self, evidence_id: str, job_id: str, kind: str, path: str, sha256: str, label: str) -> None:
        self._exec("INSERT OR IGNORE INTO evidence(evidence_id, job_id, kind, path, sha256, label, created_at)"
                   " VALUES (?,?,?,?,?,?,?)", (evidence_id, job_id, kind, path, sha256, label, iso_now()))

    def evidence_for(self, job_id: str) -> list[dict[str, Any]]:
        return self._all("SELECT * FROM evidence WHERE job_id=? ORDER BY created_at", (job_id,))

    def latest_evidence(self, kind: str = "screenshot") -> dict[str, Any] | None:
        return self._one("SELECT * FROM evidence WHERE kind=? ORDER BY created_at DESC LIMIT 1", (kind,))

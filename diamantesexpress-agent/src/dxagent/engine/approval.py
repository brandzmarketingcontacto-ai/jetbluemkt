"""ApprovalManager: aprobación humana de operaciones sensibles, con registro de quién aprobó."""

from __future__ import annotations

import asyncio
import time
import uuid
from dataclasses import dataclass

from dxagent.database import LocalDatabase


@dataclass
class ApprovalDecision:
    approved: bool
    decided_by: str
    status: str  # approved | rejected | expired | policy
    approval_id: str | None = None
    note: str = ""


class ApprovalManager:
    def __init__(self, db: LocalDatabase, poll_interval: float = 0.5):
        self.db = db
        self.poll_interval = poll_interval

    async def request(self, job_id: str, step_id: str, summary: dict, timeout: float,
                      should_abort=lambda: False) -> ApprovalDecision:
        """Crea una solicitud y espera la decisión de un operador desde la interfaz."""
        approval_id = f"apr-{uuid.uuid4().hex[:12]}"
        self.db.create_approval(approval_id, job_id, step_id, summary)
        self.db.add_event("WARNING", "approval", f"Aprobación requerida para {job_id}", job_id)
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            row = self.db.get_approval(approval_id)
            if row and row["status"] != "pending":
                return ApprovalDecision(row["status"] == "approved", row["decided_by"] or "", row["status"],
                                        approval_id, row["note"] or "")
            if should_abort():
                break
            await asyncio.sleep(self.poll_interval)
        self.db.expire_approval(approval_id)
        return ApprovalDecision(False, "", "expired", approval_id)

    def decide(self, approval_id: str, approved: bool, decided_by: str, note: str = "") -> bool:
        if not decided_by.strip():
            raise ValueError("Debe identificarse quién aprueba la operación")
        return self.db.decide_approval(approval_id, approved, decided_by.strip(), note)

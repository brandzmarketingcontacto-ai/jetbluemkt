"""EvidenceManager: almacenamiento local y envío de evidencias (capturas y jerarquía UI)."""

from __future__ import annotations

import hashlib
import json
import shutil
import time
import uuid
from pathlib import Path

from dxagent.database import LocalDatabase


class EvidenceManager:
    def __init__(self, db: LocalDatabase, root: Path):
        self.db = db
        self.root = root

    def save(self, job_id: str, kind: str, data: bytes, label: str) -> str:
        evidence_id = f"ev-{uuid.uuid4().hex[:12]}"
        folder = self.root / job_id
        folder.mkdir(parents=True, exist_ok=True)
        ext = {"screenshot": ".png", "ui_dump": ".xml"}.get(kind, ".json")
        path = folder / f"{int(time.time() * 1000)}_{label}_{evidence_id}{ext}"
        path.write_bytes(data)
        digest = hashlib.sha256(data).hexdigest()
        self.db.add_evidence(evidence_id, job_id, kind, str(path), digest, label)
        # Horizons no recibe archivos: la evidencia viaja como hash sha256 + metadatos en el resultado.
        return evidence_id

    def save_json(self, job_id: str, label: str, payload: dict) -> str:
        return self.save(job_id, "summary", json.dumps(payload, ensure_ascii=False, indent=2, default=str).encode(),
                         label)

    def summary(self, job_id: str, limit: int = 20) -> list[dict]:
        """Referencias compactas (sha256) para el campo `evidence` del resultado (máx. 2 MB en Horizons)."""
        return [{"id": e["evidence_id"], "kind": e["kind"], "label": e["label"], "sha256": f"sha256:{e['sha256']}"}
                for e in self.db.evidence_for(job_id)[-limit:]]

    def cleanup(self, retention_days: int) -> int:
        cutoff = time.time() - retention_days * 86400
        removed = 0
        if not self.root.exists():
            return 0
        for folder in self.root.iterdir():
            if folder.is_dir() and folder.stat().st_mtime < cutoff:
                shutil.rmtree(folder, ignore_errors=True)
                removed += 1
        return removed

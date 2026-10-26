"""LocalAuditLog: registro de auditoría en disco, solo-anexar y encadenado por hash.

Cada línea incluye el sha256 de la anterior: cualquier edición o borrado intermedio rompe la
cadena y `verify()` lo detecta. Registra los hechos sensibles: reclamo, aprobación, inicio y
envío del paso transaccional, verificación, resultado y alertas. Nunca contiene secretos.
"""

from __future__ import annotations

import hashlib
import json
import threading
from pathlib import Path

from dxagent.models import iso_now

GENESIS = "0" * 64


class AuditLog:
    def __init__(self, path: Path):
        self.path = path
        self._lock = threading.Lock()
        path.parent.mkdir(parents=True, exist_ok=True)
        self._last = self._read_last_hash()

    def _read_last_hash(self) -> str:
        if not self.path.exists():
            return GENESIS
        last = GENESIS
        with self.path.open("rb") as fh:
            for line in fh:
                if line.strip():
                    last = json.loads(line)["hash"]
        return last

    def append(self, event: str, **data) -> str:
        with self._lock:
            entry = {"ts": iso_now(), "event": event, "data": data, "prev": self._last}
            digest = hashlib.sha256(json.dumps(entry, sort_keys=True, ensure_ascii=False, default=str).encode()).hexdigest()
            entry["hash"] = digest
            with self.path.open("a", encoding="utf-8") as fh:
                fh.write(json.dumps(entry, ensure_ascii=False, default=str) + "\n")
                fh.flush()
            self._last = digest
            return digest

    def verify(self) -> tuple[bool, int]:
        """Devuelve (íntegro, número de registros)."""
        prev, count = GENESIS, 0
        if not self.path.exists():
            return True, 0
        with self.path.open(encoding="utf-8") as fh:
            for line in fh:
                if not line.strip():
                    continue
                entry = json.loads(line)
                digest = entry.pop("hash")
                if entry["prev"] != prev or hashlib.sha256(
                        json.dumps(entry, sort_keys=True, ensure_ascii=False, default=str).encode()).hexdigest() != digest:
                    return False, count
                prev, count = digest, count + 1
        return True, count

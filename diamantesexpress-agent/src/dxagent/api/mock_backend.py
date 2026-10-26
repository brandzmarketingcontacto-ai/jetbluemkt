"""Backend simulado de DiamantesExpress que implementa el contrato provisional.

Se usa en pruebas automatizadas (httpx.MockTransport), en el modo de simulación integral
y como servidor local (`dxagent mock-backend`) para probar la aplicación sin Horizons.
Permite inyectar fallos: API caída, token revocado, errores 5xx.
"""

from __future__ import annotations

import json
import re
import threading
import uuid
from datetime import datetime, timedelta, timezone
from typing import Any, Callable

import httpx

ROUTES: list[tuple[str, str, str]] = [
    ("POST", r"/agents/pair", "pair"),
    ("POST", r"/agents/(?P<agent_id>[^/]+)/heartbeat", "heartbeat"),
    ("GET", r"/agents/(?P<agent_id>[^/]+)/config", "config"),
    ("GET", r"/agents/(?P<agent_id>[^/]+)/jobs", "jobs"),
    ("POST", r"/agents/(?P<agent_id>[^/]+)/alerts", "alert"),
    ("GET", r"/jobs/(?P<job_id>[^/]+)", "get_job"),
    ("POST", r"/jobs/(?P<job_id>[^/]+)/claim", "claim"),
    ("POST", r"/jobs/(?P<job_id>[^/]+)/progress", "progress"),
    ("POST", r"/jobs/(?P<job_id>[^/]+)/evidence", "evidence"),
    ("POST", r"/jobs/(?P<job_id>[^/]+)/complete", "complete"),
    ("POST", r"/jobs/(?P<job_id>[^/]+)/release", "release"),
    ("POST", r"/usage", "usage"),
]


class MockHorizonsBackend:
    def __init__(self, agent_id: str = "agent-test", token: str = "test-token-123456",
                 pairing_code: str = "PAIR-0001", production_authorized: bool = False):
        self.lock = threading.RLock()
        self.agent_id = agent_id
        self.tokens = {token: agent_id}
        self.pairing_codes = {pairing_code}
        self.production_authorized = production_authorized
        self.jobs: dict[str, dict[str, Any]] = {}
        self.idempotency: dict[str, tuple[int, Any]] = {}
        self.usage_records: dict[str, dict] = {}
        self.usage_posts = 0
        self.heartbeats: dict[str, dict] = {}
        self.heartbeat_count = 0
        self.progress: list[dict] = []
        self.evidence: list[dict] = []
        self.completions: dict[str, dict] = {}
        self.releases: dict[str, dict] = {}
        self.alerts: list[dict] = []
        self.down = False
        self.fail_statuses: list[int] = []
        self.on_request: Callable[[str, str], None] | None = None

    # ------------------------------------------------------------- utilidades
    def add_job(self, job_id: str | None = None, order_id: str | None = None, player_id: str = "123456789",
                product_code: str = "DIAMANTES_100", product_name: str = "100 Diamantes", **extra: Any) -> dict:
        job_id = job_id or f"job-{uuid.uuid4().hex[:10]}"
        job = {
            "job_id": job_id, "order_id": order_id or f"DX-{job_id[-6:]}", "type": "gift_delivery",
            "recipient": {"player_id": player_id}, "product": {"code": product_code, "name": product_name, "quantity": 1},
            "created_at": datetime.now(timezone.utc).isoformat(), "attempt": 1, "status": "pending", "claimed_by": None,
        }
        job.update(extra)
        with self.lock:
            self.jobs[job_id] = job
        return job

    def revoke(self, token: str) -> None:
        with self.lock:
            self.tokens.pop(token, None)

    def public_job(self, job: dict) -> dict:
        return {k: v for k, v in job.items() if k not in {"status", "claimed_by"}}

    # ---------------------------------------------------------------- handler
    def handle(self, method: str, path: str, headers: dict[str, str], body: bytes,
               content_type: str = "", query: dict[str, str] | None = None) -> tuple[int, Any]:
        with self.lock:
            if self.on_request:
                self.on_request(method, path)
            if self.fail_statuses:
                return self.fail_statuses.pop(0), {"error": "fallo inyectado"}
            for route_method, pattern, name in ROUTES:
                if route_method != method:
                    continue
                match = re.search(pattern + r"$", path)
                if match:
                    break
            else:
                return 404, {"error": f"ruta no encontrada: {method} {path}"}
            if name != "pair":
                auth = headers.get("authorization", "")
                token = auth[7:] if auth.lower().startswith("bearer ") else ""
                if token not in self.tokens:
                    return 401, {"error": "token inválido o revocado"}
                agent = self.tokens[token]
            else:
                agent = None
            idem = headers.get("idempotency-key")
            if idem and idem in self.idempotency:
                return self.idempotency[idem]
            payload: Any = {}
            if body and "application/json" in content_type:
                payload = json.loads(body)
            elif body and "multipart/form-data" in content_type:
                payload = {"multipart_bytes": len(body)}
            result = getattr(self, f"_op_{name}")(agent, payload, query or {}, **match.groupdict())
            if idem and result[0] < 500:
                self.idempotency[idem] = result
            return result

    # ------------------------------------------------------------- operaciones
    def _op_pair(self, _agent, payload, _q):
        if payload.get("pairing_code") not in self.pairing_codes:
            return 401, {"error": "código inválido"}
        self.pairing_codes.discard(payload["pairing_code"])
        token = f"tok-{uuid.uuid4().hex}"
        self.tokens[token] = self.agent_id
        return 200, {"agent_id": self.agent_id, "agent_token": token}

    def _op_heartbeat(self, agent, payload, _q, agent_id):
        self.heartbeat_count += 1
        self.heartbeats[agent_id] = payload  # upsert: los heartbeats repetidos no duplican estado
        return 200, {"server_time": datetime.now(timezone.utc).isoformat()}

    def _op_config(self, agent, _p, _q, agent_id):
        return 200, {"production_authorized": self.production_authorized}

    def _op_jobs(self, agent, _p, query, agent_id):
        limit = int(query.get("limit", 5))
        pending = [self.public_job(j) for j in self.jobs.values() if j["status"] == "pending"][:limit]
        return 200, {"jobs": pending}

    def _op_get_job(self, agent, _p, _q, job_id):
        job = self.jobs.get(job_id)
        if not job:
            return 404, {"error": "no existe"}
        return 200, {"job_id": job_id, "status": job["status"], "claimed_by": job["claimed_by"]}

    def _op_claim(self, agent, _p, _q, job_id):
        job = self.jobs.get(job_id)
        if not job:
            return 404, {"error": "no existe"}
        if job["status"] != "pending":
            return 409, {"error": "ya reclamado"}
        job["status"], job["claimed_by"] = "claimed", agent
        job["lease_expires_at"] = (datetime.now(timezone.utc) + timedelta(minutes=15)).isoformat()
        return 200, self.public_job(job)

    def _owned(self, agent, job_id) -> tuple[int, Any] | None:
        job = self.jobs.get(job_id)
        if not job:
            return 404, {"error": "no existe"}
        if job["claimed_by"] != agent:
            return 409, {"error": "el trabajo no pertenece a este agente"}
        return None

    def _op_progress(self, agent, payload, _q, job_id):
        if err := self._owned(agent, job_id):
            return err
        self.progress.append({"job_id": job_id, **payload})
        return 200, {"ok": True}

    def _op_evidence(self, agent, payload, _q, job_id):
        if err := self._owned(agent, job_id):
            return err
        self.evidence.append({"job_id": job_id, **payload})
        return 200, {"ok": True}

    def _op_complete(self, agent, payload, _q, job_id):
        if err := self._owned(agent, job_id):
            return err
        self.completions[job_id] = payload
        self.jobs[job_id]["status"] = payload.get("status", "completed")
        return 200, {"ok": True}

    def _op_release(self, agent, payload, _q, job_id):
        if err := self._owned(agent, job_id):
            return err
        self.releases[job_id] = payload
        self.jobs[job_id].update(status="pending", claimed_by=None)
        return 200, {"ok": True}

    def _op_alert(self, agent, payload, _q, agent_id):
        self.alerts.append(payload)
        return 200, {"ok": True}

    def _op_usage(self, agent, payload, _q):
        self.usage_posts += 1
        accepted = duplicates = 0
        for record in payload.get("records", []):
            if record["record_id"] in self.usage_records:
                duplicates += 1
            else:
                self.usage_records[record["record_id"]] = record
                accepted += 1
        return 200, {"accepted": accepted, "duplicates": duplicates}

    # -------------------------------------------------------------- adaptadores
    def transport(self) -> httpx.MockTransport:
        def handler(request: httpx.Request) -> httpx.Response:
            if self.down:
                raise httpx.ConnectError("API simulada fuera de línea", request=request)
            status, body = self.handle(request.method, request.url.path,
                                       {k.lower(): v for k, v in request.headers.items()},
                                       request.read(), request.headers.get("content-type", ""),
                                       dict(request.url.params))
            return httpx.Response(status, json=body)
        return httpx.MockTransport(handler)

    def serve(self, host: str = "127.0.0.1", port: int = 8787):  # pragma: no cover - manual
        from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
        from urllib.parse import parse_qsl, urlsplit

        backend = self

        class Handler(BaseHTTPRequestHandler):
            def _dispatch(self):
                parts = urlsplit(self.path)
                length = int(self.headers.get("content-length") or 0)
                body = self.rfile.read(length) if length else b""
                status, payload = backend.handle(self.command, parts.path,
                                                 {k.lower(): v for k, v in self.headers.items()}, body,
                                                 self.headers.get("content-type", ""), dict(parse_qsl(parts.query)))
                data = json.dumps(payload).encode()
                self.send_response(status)
                self.send_header("content-type", "application/json")
                self.send_header("content-length", str(len(data)))
                self.end_headers()
                self.wfile.write(data)

            do_GET = do_POST = _dispatch

            def log_message(self, *args):
                pass

        server = ThreadingHTTPServer((host, port), Handler)
        return server

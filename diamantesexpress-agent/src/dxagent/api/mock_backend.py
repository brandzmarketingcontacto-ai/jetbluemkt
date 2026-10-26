"""Backend simulado que imita la API REAL de DiamantesExpress (`/hcgi/api/agent/...`).

Reproduce el comportamiento documentado por Horizons:
- modos off | simulation | supervised | production y pausa;
- claim atómico con lease; al vencer el lease la tarea vuelve a pending hasta maxAttempts y
  luego pasa a manual_review;
- progress solo para el agente propietario (409 task_owned_by_other_agent / task_already_finished);
- result idempotente (duplicate:true); uncertain -> manual_review; en supervised, completed -> manual_review;
- coste estimado con tarifas configurables cuando no se envía cost_usd.

Se usa en pruebas (httpx.MockTransport), en `dxagent simulate` y como servidor local (`dxagent mock-backend`).
No crea pedidos ni cobros reales.
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
    ("POST", r"/agent/heartbeat", "heartbeat"),
    ("POST", r"/agent/tasks/claim", "claim"),
    ("GET", r"/agent/tasks/current", "current"),
    ("POST", r"/agent/tasks/(?P<task_id>[^/]+)/progress", "progress"),
    ("POST", r"/agent/tasks/(?P<task_id>[^/]+)/result", "result"),
]
FINAL = {"completed", "failed", "manual_review", "cancelled"}


def _now() -> datetime:
    return datetime.now(timezone.utc)


class MockHorizonsBackend:
    def __init__(self, token: str = "dxa_test_token_123456", mode: str = "simulation", agent_name: str = "agente-1"):
        self.lock = threading.RLock()
        self.tokens = {token: agent_name}
        self.mode = mode
        self.paused = False
        self.booyah_agent = True
        self.lease_seconds = 300
        self.max_attempts = 3
        self.poll_after_seconds = 0
        self.spend_limit_reached = False
        self.price_in, self.price_out, self.price_cache = 0.15, 0.60, 0.075  # por 1M (tarifas de prueba)
        self.tasks: dict[str, dict[str, Any]] = {}
        self.order: list[str] = []
        self.heartbeats: dict[str, dict] = {}
        self.heartbeat_count = 0
        self.progress: list[dict] = []
        self.results: dict[str, dict] = {}
        self.result_posts = 0
        self.down = False
        self.fail_statuses: list[int] = []
        self.clock: Callable[[], datetime] = _now
        self.on_request: Callable[[str, str], None] | None = None

    # ------------------------------------------------------------- utilidades
    def add_task(self, task_id: str | None = None, order_code: str | None = None, player_id: str = "588141749",
                 kind: str = "pase_booyah", product_name: str = "Pase Booyah", simulated: bool | None = None,
                 **extra: Any) -> dict:
        task_id = task_id or f"task_{uuid.uuid4().hex[:10]}"
        sim = self.mode == "simulation" if simulated is None else simulated
        task = {
            "id": task_id, "orderId": "" if sim else f"ord_{task_id}", "orderCode": order_code or f"DX-{task_id[-6:]}",
            "productName": product_name, "kind": kind, "playerId": player_id, "playerZone": "",
            "status": "pending", "mode": self.mode, "simulated": sim, "attempts": 0,
            "maxAttempts": self.max_attempts, "leaseExpiresAt": None, "claimedAt": None, "owner": None,
            "evidence": None, "result": None, "errorCode": None, "errorMessage": None, "usage": None,
            "createdAt": self.clock().isoformat(), "updatedAt": self.clock().isoformat(),
        }
        task.update(extra)
        with self.lock:
            self.tasks[task_id] = task
            self.order.append(task_id)
        return task

    def add_job(self, job_id: str | None = None, order_id: str | None = None, player_id: str = "588141749",
                product_code: str = "pase_booyah", product_name: str = "Pase Booyah", **extra: Any) -> dict:
        """Atajo de pruebas con nombres de pedido (job_id/order_id/product_code)."""
        return self.add_task(task_id=job_id, order_code=order_id, player_id=player_id, kind=product_code,
                             product_name=product_name, **extra)

    def revoke(self, token: str) -> None:
        with self.lock:
            self.tokens.pop(token, None)

    def expire_leases(self) -> None:
        """Aplica el vencimiento de leases como lo hace Horizons."""
        now = self.clock()
        for t in self.tasks.values():
            if t["status"] in ("processing", "verifying") and t["leaseExpiresAt"] \
                    and datetime.fromisoformat(t["leaseExpiresAt"]) <= now:
                t["owner"] = None
                t["status"] = "manual_review" if t["attempts"] >= t["maxAttempts"] else "pending"

    def admin_retry(self, task_id: str) -> None:
        t = self.tasks[task_id]
        if t["status"] == "completed":
            raise ValueError("already_completed")
        t.update(status="pending", owner=None, result=None)

    @staticmethod
    def public(task: dict) -> dict:
        return {k: v for k, v in task.items() if k != "owner"}

    # ---------------------------------------------------------------- handler
    def handle(self, method: str, path: str, headers: dict[str, str], body: bytes) -> tuple[int, Any]:
        with self.lock:
            if self.on_request:
                self.on_request(method, path)
            if self.fail_statuses:
                return self.fail_statuses.pop(0), {"error": "fallo inyectado"}
            for route_method, pattern, name in ROUTES:
                match = re.search(pattern + r"$", path) if route_method == method else None
                if match:
                    break
            else:
                return 404, {"error": "not_found"}
            auth = headers.get("authorization", "")
            token = auth[7:] if auth.lower().startswith("bearer ") else headers.get("x-agent-token", "")
            if not token:
                return 401, {"error": "missing_agent_token"}
            if token not in self.tokens:
                return 401, {"error": "invalid_agent_token"}
            agent = self.tokens[token]
            payload = json.loads(body) if body else {}
            self.expire_leases()
            return getattr(self, f"_op_{name}")(agent, payload, **match.groupdict())

    # ------------------------------------------------------------- operaciones
    def _op_heartbeat(self, agent, payload):
        self.heartbeat_count += 1
        self.heartbeats[agent] = payload  # upsert: los heartbeats repetidos no duplican estado
        return 200, {"ok": True, "serverTime": self.clock().isoformat(), "mode": self.mode, "paused": self.paused,
                     "accepted": self.mode != "off" and not self.paused, "booyahAgent": self.booyah_agent,
                     "leaseSeconds": self.lease_seconds, "maxAttempts": self.max_attempts,
                     "pollAfterSeconds": self.poll_after_seconds}

    def _op_claim(self, agent, _payload):
        base = {"mode": None if self.mode == "off" else self.mode, "simulate": self.mode == "simulation",
                "leaseSeconds": self.lease_seconds, "limit": None, "instructions": []}
        if self.mode == "off":
            return 200, {**base, "task": None, "reason": "automation_off"}
        if self.paused:
            return 200, {**base, "task": None, "reason": "paused"}
        if self.spend_limit_reached:
            return 200, {**base, "task": None, "reason": "spend_limit_reached"}
        for task_id in self.order:
            t = self.tasks[task_id]
            if t["status"] == "pending":
                now = self.clock()
                t.update(status="processing", owner=agent, attempts=t["attempts"] + 1, claimedAt=now.isoformat(),
                         leaseExpiresAt=(now + timedelta(seconds=self.lease_seconds)).isoformat(),
                         mode=self.mode, updatedAt=now.isoformat())
                if self.mode == "simulation":
                    t["simulated"] = True
                    base["instructions"] = ["MODO SIMULACIÓN: no ejecutes ninguna acción real en el juego."]
                return 200, {**base, "task": self.public(t), "reason": None}
        return 200, {**base, "task": None, "reason": "empty"}

    def _op_current(self, agent, _payload):
        mine = [self.public(t) for t in self.tasks.values()
                if t["owner"] == agent and t["status"] in ("processing", "verifying")]
        return 200, {"tasks": mine}

    def _owned(self, agent, task_id):
        t = self.tasks.get(task_id)
        if not t:
            return None, (404, {"error": "task_not_found"})
        if t["owner"] not in (agent, None) or (t["owner"] is None and t["status"] not in FINAL):
            return t, (409, {"error": "task_owned_by_other_agent"})
        return t, None

    def _op_progress(self, agent, payload, task_id):
        t, err = self._owned(agent, task_id)
        if err:
            return err
        if t["status"] in FINAL:
            return 409, {"error": "task_already_finished"}
        status = payload.get("status")
        if status in ("processing", "verifying"):
            t["status"] = status
        if payload.get("evidence") is not None:
            t["evidence"] = payload["evidence"]
        t["leaseExpiresAt"] = (self.clock() + timedelta(seconds=self.lease_seconds)).isoformat()
        self.progress.append({"task_id": task_id, **payload})
        return 200, {"ok": True, "task": self.public(t)}

    def _op_result(self, agent, payload, task_id):
        self.result_posts += 1
        t, err = self._owned(agent, task_id)
        if err:
            return err
        if t["result"] is not None:
            return 200, {"ok": True, "duplicate": True, "task": self.public(t)}
        outcome = payload.get("outcome")
        if outcome not in ("completed", "failed", "uncertain"):
            return 422, {"error": "invalid_outcome"}
        usage = payload.get("usage") or {}
        cost = float(usage.get("cost_usd") or 0)
        if usage and cost <= 0:
            prompt, cached = int(usage.get("prompt_tokens") or 0), int(usage.get("cached_tokens") or 0)
            cost = ((prompt - cached) * self.price_in + cached * self.price_cache
                    + int(usage.get("completion_tokens") or 0) * self.price_out) / 1e6
        if usage:
            t["usage"] = {"model": usage.get("model"), "promptTokens": usage.get("prompt_tokens", 0),
                          "completionTokens": usage.get("completion_tokens", 0),
                          "cachedTokens": usage.get("cached_tokens", 0), "costUsd": round(cost, 8)}
        t["result"] = {"outcome": outcome, "simulated": t["simulated"], "giftCode": payload.get("giftCode", ""),
                       "message": payload.get("message", ""), "reportedAt": self.clock().isoformat()}
        t["evidence"] = payload.get("evidence", t["evidence"])
        t["errorCode"], t["errorMessage"] = payload.get("errorCode"), payload.get("errorMessage")
        if outcome == "uncertain" or (outcome == "completed" and t["mode"] == "supervised"):
            t["status"] = "manual_review"
        else:
            t["status"] = outcome
        t["owner"] = agent
        self.results[task_id] = payload
        return 200, {"ok": True, "duplicate": False, "task": self.public(t)}

    # -------------------------------------------------------------- adaptadores
    def transport(self) -> httpx.MockTransport:
        def handler(request: httpx.Request) -> httpx.Response:
            if self.down:
                raise httpx.ConnectError("API simulada fuera de línea", request=request)
            status, body = self.handle(request.method, request.url.path,
                                       {k.lower(): v for k, v in request.headers.items()}, request.read())
            return httpx.Response(status, json=body)
        return httpx.MockTransport(handler)

    def serve(self, host: str = "127.0.0.1", port: int = 8787):  # pragma: no cover - manual
        from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
        from urllib.parse import urlsplit

        backend = self

        class Handler(BaseHTTPRequestHandler):
            def _dispatch(self):
                length = int(self.headers.get("content-length") or 0)
                body = self.rfile.read(length) if length else b""
                status, payload = backend.handle(self.command, urlsplit(self.path).path,
                                                 {k.lower(): v for k, v in self.headers.items()}, body)
                data = json.dumps(payload).encode()
                self.send_response(status)
                self.send_header("content-type", "application/json")
                self.send_header("content-length", str(len(data)))
                self.end_headers()
                self.wfile.write(data)

            do_GET = do_POST = _dispatch

            def log_message(self, *args):
                pass

        return ThreadingHTTPServer((host, port), Handler)

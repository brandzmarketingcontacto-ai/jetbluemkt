"""HorizonsApiClient: comunicación con la API real de DiamantesExpress (`/hcgi/api/agent/...`).

- Solo HTTPS (HTTP únicamente hacia localhost y si se habilita explícitamente para pruebas).
- Autenticación `Authorization: Bearer dxa_...` (token creado en Automatización → Tokens del agente).
- El agente inicia todas las llamadas (Horizons no recibe conexiones entrantes).
- Clasificación de errores: ApiUnavailable (reintentable), AuthError, ConflictError, NotFoundError.
"""

from __future__ import annotations

import json
import logging
import platform
from dataclasses import dataclass, field
from typing import Any
from urllib.parse import urlparse

import httpx

from dxagent import __version__
from dxagent.api.contract import ApiContract
from dxagent.api.errors import ApiError, ApiUnavailable, AuthError, ConflictError, InsecureUrlError, NotFoundError

log = logging.getLogger(__name__)
LOCAL_HOSTS = {"localhost", "127.0.0.1", "::1"}


def validate_base_url(url: str, allow_insecure_localhost: bool) -> str:
    if not url:
        raise ApiError("DX_API_BASE_URL no está configurada (https://<dominio-publicado>/hcgi/api)")
    parsed = urlparse(url)
    if parsed.scheme == "https" and parsed.netloc:
        return url.rstrip("/")
    if parsed.scheme == "http" and parsed.hostname in LOCAL_HOSTS and allow_insecure_localhost:
        return url.rstrip("/")
    raise InsecureUrlError(f"La URL de la API debe usar HTTPS: {url}")


@dataclass
class ServerState:
    """Estado del sistema según el último heartbeat/claim. El servidor manda."""
    mode: str | None = None              # off | simulation | supervised | production
    paused: bool = False
    accepted: bool = True
    booyah_agent: bool | None = None
    lease_seconds: int = 300
    max_attempts: int | None = None
    poll_after_seconds: float | None = None
    server_time: str | None = None
    last_reason: str | None = None       # motivo del último claim sin tarea
    instructions: list[str] = field(default_factory=list)
    # v1.1
    friend_retry_minutes: int = 10
    friend_wait_max_hours: int | None = None
    sender_account_name: str | None = None
    sender_account_id: str | None = None
    balance_threshold: int | None = None
    server_diamond_balance: int | None = None   # saldo que conoce el servidor
    balance_needed: int | None = None           # claim con reason=insufficient_balance

    @property
    def dispatching(self) -> bool:
        return self.mode not in (None, "off") and not self.paused and self.accepted


@dataclass
class ClaimResult:
    task: dict[str, Any] | None
    reason: str | None
    mode: str | None
    simulate: bool
    lease_seconds: int | None
    instructions: list[str]


class HorizonsApiClient:
    def __init__(self, base_url: str, token: str | None, contract: ApiContract, *, agent_name: str = "",
                 timeout: float = 20.0, allow_insecure_localhost: bool = False,
                 transport: httpx.AsyncBaseTransport | None = None):
        self.base_url = validate_base_url(base_url, allow_insecure_localhost)
        self._token = token
        self.agent_name = (agent_name or platform.node() or "dxagent")[:80]
        self.contract = contract
        self.state = ServerState()
        self._client = httpx.AsyncClient(
            base_url=self.base_url, timeout=timeout, transport=transport,
            headers={"User-Agent": f"DiamantesExpressAgent/{__version__}", "Accept": "application/json"},
        )

    @property
    def agent_id(self) -> str:
        """Identificador local para registros (el servidor identifica al agente por su token)."""
        return self.agent_name

    async def aclose(self) -> None:
        await self._client.aclose()

    def set_token(self, token: str) -> None:
        self._token = token

    # ------------------------------------------------------------------ núcleo
    async def _request(self, name: str, *, path_params: dict[str, str] | None = None,
                       json_body: Any = None, idempotency_key: str | None = None) -> Any:
        op = self.contract.get(name)
        url = op.render(**(path_params or {}))
        if not self._token:
            raise AuthError("No hay token de agente (dxa_...). Créelo en Automatización → Tokens del agente.")
        headers = {"Authorization": f"Bearer {self._token}"}
        if idempotency_key:
            headers["Idempotency-Key"] = idempotency_key
        try:
            response = await self._client.request(op.method, url, json=json_body, headers=headers)
        except (httpx.TransportError, httpx.TimeoutException) as exc:
            raise ApiUnavailable(f"API no disponible ({name}): {type(exc).__name__}") from exc
        status = response.status_code
        try:
            body: Any = response.json() if response.content else {}
        except json.JSONDecodeError:
            body = {"raw": response.text[:500]}
        code = body.get("error") if isinstance(body, dict) else None
        if status in (401, 403):
            raise AuthError(f"Token rechazado por la API ({status} {code or ''})".strip(), status, body)
        if status == 404:
            raise NotFoundError(f"{name}: {code or 'no encontrado'}", status, body)
        if status == 409:
            raise ConflictError(f"{name}: {code or 'conflicto'}", status, body)
        if status == 429 or status >= 500:
            raise ApiUnavailable(f"API respondió {status} en {name}", status, body)
        if status >= 400:
            raise ApiError(f"API respondió {status} en {name}: {code or ''}", status, body)
        return body

    # -------------------------------------------------------------- operaciones
    async def heartbeat(self, diamond_balance: int | None = None) -> ServerState:
        payload: dict[str, Any] = {"hostname": self.agent_name, "appVersion": __version__[:40]}
        if diamond_balance is not None:
            payload["diamondBalance"] = max(int(diamond_balance), 0)  # v1.1
        body = await self._request("heartbeat", json_body=payload)
        st = self.state
        st.mode = body.get("mode", st.mode)
        st.paused = bool(body.get("paused", False))
        st.accepted = bool(body.get("accepted", True))
        st.booyah_agent = body.get("booyahAgent", st.booyah_agent)
        st.lease_seconds = int(body.get("leaseSeconds") or st.lease_seconds)
        st.max_attempts = body.get("maxAttempts", st.max_attempts)
        st.poll_after_seconds = body.get("pollAfterSeconds", st.poll_after_seconds)
        st.server_time = body.get("serverTime")
        st.friend_retry_minutes = int(body.get("friendRetryMinutes") or st.friend_retry_minutes)
        st.friend_wait_max_hours = body.get("friendWaitMaxHours", st.friend_wait_max_hours)
        st.sender_account_name = body.get("senderAccountName", st.sender_account_name)
        st.sender_account_id = body.get("senderAccountId", st.sender_account_id)
        st.balance_threshold = body.get("balanceThreshold", st.balance_threshold)
        return st

    async def claim_task(self) -> ClaimResult:
        body = await self._request("claimTask", json_body={})
        result = ClaimResult(task=body.get("task"), reason=body.get("reason"), mode=body.get("mode"),
                             simulate=bool(body.get("simulate", False)), lease_seconds=body.get("leaseSeconds"),
                             instructions=list(body.get("instructions") or []))
        if result.mode:
            self.state.mode = result.mode
        if result.lease_seconds:
            self.state.lease_seconds = int(result.lease_seconds)
        self.state.last_reason = None if result.task else result.reason
        self.state.instructions = result.instructions
        if result.reason == "paused":
            self.state.paused = True
        if body.get("diamondBalance") is not None:
            self.state.server_diamond_balance = body.get("diamondBalance")
        self.state.balance_needed = body.get("needed") if result.reason == "insufficient_balance" else None
        return result

    @property
    def supports_defer(self) -> bool:
        return self.contract.supports("deferTask")

    async def defer_task(self, task_id: str, reason: str, retry_after_seconds: int | None = None,
                         message: str = "", idem: str | None = None) -> dict:
        """v1.1: la tarea vuelve a waiting_friend hasta notBefore. No consume maxAttempts."""
        payload: dict[str, Any] = {"reason": reason[:80], "message": message[:1000]}
        if retry_after_seconds is not None:
            payload["retryAfterSeconds"] = min(max(int(retry_after_seconds), 30), 86400)
        return await self._request("deferTask", path_params={"id": task_id}, json_body=payload,
                                   idempotency_key=idem)

    async def current_tasks(self) -> list[dict[str, Any]]:
        body = await self._request("currentTasks")
        return list(body.get("tasks") or [])

    async def report_progress(self, task_id: str, status: str, message: str = "",
                              evidence: dict | None = None, idem: str | None = None) -> dict:
        if status not in ("processing", "verifying"):
            raise ValueError("status de progreso debe ser processing o verifying")
        payload: dict[str, Any] = {"status": status, "message": message[:1000]}
        if evidence:
            payload["evidence"] = evidence
        return await self._request("reportProgress", path_params={"id": task_id}, json_body=payload,
                                   idempotency_key=idem)

    async def report_result(self, task_id: str, payload: dict, idem: str | None = None) -> dict:
        if payload.get("outcome") not in ("completed", "failed", "uncertain"):
            raise ValueError("outcome debe ser completed, failed o uncertain")
        return await self._request("reportResult", path_params={"id": task_id}, json_body=payload,
                                   idempotency_key=idem)

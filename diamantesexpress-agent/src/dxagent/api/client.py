"""HorizonsApiClient: comunicación segura con el backend de DiamantesExpress.

- Solo HTTPS (HTTP únicamente hacia localhost y si se habilita explícitamente para pruebas).
- Rutas resueltas por operationId desde el contrato OpenAPI.
- Idempotency-Key en todas las escrituras.
- Clasificación de errores: ApiUnavailable (reintentable), AuthError, ConflictError.
"""

from __future__ import annotations

import json
import logging
import platform
from pathlib import Path
from typing import Any
from urllib.parse import urlparse

import httpx

from dxagent import __version__
from dxagent.api.contract import ApiContract
from dxagent.api.errors import ApiError, ApiUnavailable, AuthError, ConflictError, InsecureUrlError
from dxagent.models import Job

log = logging.getLogger(__name__)
LOCAL_HOSTS = {"localhost", "127.0.0.1", "::1"}


def validate_base_url(url: str, allow_insecure_localhost: bool) -> str:
    if not url:
        raise ApiError("DIAMANTESEXPRESS_API_URL no está configurada")
    parsed = urlparse(url)
    if parsed.scheme == "https" and parsed.netloc:
        return url.rstrip("/")
    if parsed.scheme == "http" and parsed.hostname in LOCAL_HOSTS and allow_insecure_localhost:
        return url.rstrip("/")
    raise InsecureUrlError(f"La URL de la API debe usar HTTPS: {url}")


class HorizonsApiClient:
    def __init__(self, base_url: str, agent_id: str, token: str | None, contract: ApiContract,
                 timeout: float = 20.0, allow_insecure_localhost: bool = False,
                 transport: httpx.AsyncBaseTransport | None = None):
        self.base_url = validate_base_url(base_url, allow_insecure_localhost)
        self.agent_id = agent_id
        self._token = token
        self.contract = contract
        self._client = httpx.AsyncClient(
            base_url=self.base_url, timeout=timeout, transport=transport,
            headers={"User-Agent": f"DiamantesExpressAgent/{__version__}", "Accept": "application/json"},
        )

    async def aclose(self) -> None:
        await self._client.aclose()

    def set_credentials(self, agent_id: str, token: str) -> None:
        self.agent_id, self._token = agent_id, token

    # ------------------------------------------------------------------ núcleo
    async def _request(self, operation_id: str, *, path_params: dict[str, str] | None = None,
                       json_body: Any = None, params: dict[str, Any] | None = None,
                       files: dict | None = None, data: dict | None = None,
                       idempotency_key: str | None = None, auth: bool = True) -> Any:
        op = self.contract.get(operation_id)
        url = op.render(**(path_params or {}))
        headers: dict[str, str] = {}
        if auth:
            if not self._token:
                raise AuthError("No hay token de agente configurado. Empareje el agente.")
            headers["Authorization"] = f"Bearer {self._token}"
        if idempotency_key:
            headers["Idempotency-Key"] = idempotency_key
        try:
            response = await self._client.request(op.method, url, json=json_body, params=params,
                                                   files=files, data=data, headers=headers)
        except (httpx.TransportError, httpx.TimeoutException) as exc:
            raise ApiUnavailable(f"API no disponible ({operation_id}): {type(exc).__name__}") from exc
        status = response.status_code
        body: Any
        try:
            body = response.json() if response.content else {}
        except json.JSONDecodeError:
            body = {"raw": response.text[:500]}
        if status in (401, 403):
            raise AuthError(f"Credenciales rechazadas por la API ({status})", status, body)
        if status == 409:
            raise ConflictError(f"Conflicto en {operation_id}", status, body)
        if status == 429 or status >= 500:
            raise ApiUnavailable(f"API respondió {status} en {operation_id}", status, body)
        if status >= 400:
            raise ApiError(f"API respondió {status} en {operation_id}", status, body)
        return body

    # -------------------------------------------------------------- operaciones
    async def pair(self, pairing_code: str) -> dict[str, Any]:
        body = await self._request("pairAgent", auth=False, json_body={
            "pairing_code": pairing_code, "machine_name": platform.node(), "agent_version": __version__})
        if not body.get("agent_id") or not body.get("agent_token"):
            raise ApiError("Respuesta de emparejamiento incompleta")
        self.set_credentials(body["agent_id"], body["agent_token"])
        return body

    async def heartbeat(self, payload: dict[str, Any]) -> dict[str, Any]:
        return await self._request("sendHeartbeat", path_params={"agent_id": self.agent_id}, json_body=payload)

    async def get_config(self) -> dict[str, Any]:
        return await self._request("getAgentConfig", path_params={"agent_id": self.agent_id})

    async def list_pending_jobs(self, limit: int = 5) -> list[dict[str, Any]]:
        body = await self._request("listPendingJobs", path_params={"agent_id": self.agent_id}, params={"limit": limit})
        jobs = body.get("jobs", []) if isinstance(body, dict) else body
        return list(jobs or [])

    async def claim_job(self, job_id: str, instance: str) -> Job:
        body = await self._request("claimJob", path_params={"job_id": job_id},
                                   json_body={"agent_id": self.agent_id, "instance": instance},
                                   idempotency_key=f"claim:{self.agent_id}:{job_id}")
        return Job.model_validate(body)

    async def get_job(self, job_id: str) -> dict[str, Any]:
        return await self._request("getJob", path_params={"job_id": job_id})

    async def report_progress(self, job_id: str, payload: dict, idem: str) -> Any:
        return await self._request("reportProgress", path_params={"job_id": job_id}, json_body=payload, idempotency_key=idem)

    async def report_usage(self, records: list[dict], idem: str) -> Any:
        return await self._request("reportUsage", json_body={"records": records}, idempotency_key=idem)

    async def upload_evidence(self, job_id: str, metadata: dict, file_path: str, idem: str) -> Any:
        path = Path(file_path)
        content = path.read_bytes() if path.exists() else b""
        mime = "image/png" if path.suffix == ".png" else "application/octet-stream"
        return await self._request("uploadEvidence", path_params={"job_id": job_id},
                                   data={"metadata": json.dumps(metadata)},
                                   files={"file": (path.name, content, mime)}, idempotency_key=idem)

    async def complete_job(self, job_id: str, outcome: dict, idem: str) -> Any:
        return await self._request("completeJob", path_params={"job_id": job_id}, json_body=outcome, idempotency_key=idem)

    async def release_job(self, job_id: str, reason: str, idem: str) -> Any:
        return await self._request("releaseJob", path_params={"job_id": job_id}, json_body={"reason": reason},
                                   idempotency_key=idem)

    async def report_alert(self, payload: dict, idem: str) -> Any:
        return await self._request("reportAlert", path_params={"agent_id": self.agent_id}, json_body=payload,
                                   idempotency_key=idem)

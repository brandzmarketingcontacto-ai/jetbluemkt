"""AgentService: proceso principal de ejecución, independiente de la interfaz.

Bucles asíncronos:
- control:   comandos de la interfaz (iniciar, pausar, detener, emergencia) vía SQLite.
- heartbeat: estado del agente hacia DiamantesExpress.
- sync:      envío del outbox (progreso, consumo, evidencias, resultados, alertas).
- work:      reclamación y ejecución de trabajos, de a uno por instancia Android.
- watchdog:  supervisión de bucles, Internet y emulador.
"""

from __future__ import annotations

import asyncio
import logging
import os
import time
from dataclasses import dataclass, field
from typing import Any, Awaitable, Callable

from dxagent import __version__, paths
from dxagent.ai.pricing import PricingTable
from dxagent.ai.usage import UsageTracker
from dxagent.ai.vision import OpenAIVisionAgent, build_openai_client
from dxagent.api.client import HorizonsApiClient
from dxagent.api.contract import ApiContract
from dxagent.api.errors import ApiError, ApiUnavailable, AuthError
from dxagent.android.device import DeviceController, DeviceError
from dxagent.database import LocalDatabase
from dxagent.engine.approval import ApprovalManager
from dxagent.engine.evidence import EvidenceManager
from dxagent.engine.recovery import RecoveryManager
from dxagent.engine.workflow import EngineConfig, WorkflowEngine
from dxagent.engine.workflow_def import WorkflowDef, WorkflowNotReady, check_mode_allowed, load_workflow
from dxagent.models import AgentState, Job, JobOutcome, JobStatus, iso_now
from dxagent.service.job_queue import JobQueueManager
from dxagent.service.sync import OutboxSync
from dxagent.service.watchdog import Watchdog
from dxagent.settings import AgentSettings, SecretStore

log = logging.getLogger(__name__)
DeviceFactory = Callable[[], Awaitable[DeviceController]]


@dataclass
class Health:
    api_online: bool = False
    api_last_ok: float | None = None
    api_error: str | None = None
    device_ok: bool = False
    device_error: str | None = None
    device_last_ok: float | None = None
    emulator_running: bool | None = None
    liveness: dict[str, float] = field(default_factory=dict)


class AgentService:
    def __init__(self, settings: AgentSettings, db: LocalDatabase, secrets: SecretStore, *,
                 api: HorizonsApiClient | None = None, device_factory: DeviceFactory | None = None,
                 vision: OpenAIVisionAgent | None = None, bluestacks=None, workflow: WorkflowDef | None = None,
                 evidence_root=None, emergency_flag=None, live_screenshot=None, autostart_running: bool = True,
                 engine_overrides: dict[str, Any] | None = None):
        self.settings = settings
        self.db = db
        self.secrets = secrets
        self.bluestacks = bluestacks
        self.emergency_flag = emergency_flag or paths.emergency_flag_path()
        self.live_screenshot = live_screenshot
        self.health = Health()
        self.state = AgentState.STOPPED
        self.desired_running = autostart_running
        self.stopping = asyncio.Event()
        self.current_job: Job | None = None
        self.production_authorized = False
        self.engine_overrides = engine_overrides or {}

        self.api = api or self._build_api()
        self.pricing = PricingTable.load(settings.pricing_path or None)
        self.tracker = UsageTracker(db, self.pricing, settings.agent_id or self.api.agent_id,
                                    settings.max_ai_cost_per_order_usd, settings.daily_ai_budget_usd,
                                    settings.max_ai_requests_per_order, settings.ai_reserve_per_request_usd)
        self.vision = vision if vision is not None else self._build_vision()
        self.workflow = workflow
        self.device_factory = device_factory
        self.device: DeviceController | None = None
        self.queue = JobQueueManager(db, self.api, settings.bluestacks_instance or "default", settings.account_key)
        self.sync = OutboxSync(db, self.api)
        self.recovery = RecoveryManager(db)
        self.approvals = ApprovalManager(db)
        self.evidence = EvidenceManager(db, evidence_root or paths.evidence_dir())
        self.watchdog = Watchdog(self)
        self._tasks: dict[str, asyncio.Task] = {}

    # --------------------------------------------------------------- builders
    def _build_api(self) -> HorizonsApiClient:
        contract = ApiContract.load(self.settings.openapi_contract_path or None, self.settings.route_overrides)
        return HorizonsApiClient(self.settings.api_url, self.settings.agent_id, self.secrets.get("agent_token"),
                                 contract, self.settings.api_timeout_seconds, self.settings.allow_insecure_http_localhost)

    def _build_vision(self) -> OpenAIVisionAgent | None:
        if self.settings.vision_backend == "disabled":
            return None
        key = self.secrets.get("openai_api_key")
        if not key:
            if self.settings.vision_backend == "openai":
                log.error("vision_backend=openai pero no hay OPENAI_API_KEY configurada")
            return None
        client = build_openai_client(key, self.settings.openai_timeout_seconds)
        return OpenAIVisionAgent(client, self.settings.openai_model, self.tracker, self.settings.ai_image_detail,
                                 self.settings.openai_max_output_tokens)

    # ----------------------------------------------------------------- estado
    def set_state(self, state: AgentState, reason: str = "") -> None:
        if state != self.state:
            log.info("Estado del agente: %s -> %s %s", self.state.value, state.value, reason)
            self.db.add_event("INFO" if state in (AgentState.RUNNING, AgentState.PAUSED) else "WARNING",
                              "agent", f"Estado: {state.value} {reason}".strip())
            self.state = state

    def publish_status(self) -> None:
        self.db.set_status("agent", {
            "state": self.state.value, "desired_running": self.desired_running, "pid": os.getpid(),
            "version": __version__, "mode": self.settings.automation_mode.value,
            "workflow": self.workflow.id if self.workflow else self.settings.workflow_id,
            "api_online": self.health.api_online, "api_error": self.health.api_error,
            "api_last_ok": self.health.api_last_ok, "device_ok": self.health.device_ok,
            "device_error": self.health.device_error, "emulator_running": self.health.emulator_running,
            "vision_enabled": self.vision is not None, "outbox_pending": self.db.outbox_pending_count(),
            "current_job_id": self.current_job.job_id if self.current_job else None,
            "emergency": self.emergency_flag.exists(), "ts": time.time(),
        })

    def touch(self, loop_name: str) -> None:
        self.health.liveness[loop_name] = time.monotonic()

    # ------------------------------------------------------------------ setup
    async def startup(self) -> None:
        self.set_state(AgentState.STARTING)
        self.recovery.recover_on_startup()
        if self.workflow is None:
            try:
                self.workflow = load_workflow(self.settings.workflow_id)
            except (WorkflowNotReady, ValueError) as exc:
                self.db.add_event("ERROR", "workflow", f"Flujo no disponible: {exc}")
                raise
        await self._refresh_remote_config()
        check_mode_allowed(self.workflow, self.settings.automation_mode.value, self.production_authorized)
        if self.emergency_flag.exists():
            self.set_state(AgentState.EMERGENCY_STOP, "bandera de emergencia presente")
        elif self.state == AgentState.AUTH_ERROR:
            pass
        else:
            self.set_state(AgentState.RUNNING if self.desired_running else AgentState.PAUSED)
        self.publish_status()

    async def _refresh_remote_config(self) -> None:
        try:
            config = await self.api.get_config()
            self.production_authorized = bool(config.get("production_authorized", False))
            self._api_ok()
        except AuthError as exc:
            self.health.api_error = str(exc)
            self.set_state(AgentState.AUTH_ERROR, str(exc))
        except ApiError as exc:
            self._api_fail(exc)

    def _api_ok(self) -> None:
        self.health.api_online, self.health.api_last_ok, self.health.api_error = True, time.time(), None

    def _api_fail(self, exc: Exception) -> None:
        self.health.api_online, self.health.api_error = False, str(exc)

    # ------------------------------------------------------------------ loops
    async def run_forever(self) -> None:
        await self.startup()
        loops = {"control": self.control_loop, "heartbeat": self.heartbeat_loop, "sync": self.sync_loop,
                 "work": self.work_loop, "watchdog": self.watchdog.loop}
        for name, fn in loops.items():
            self._tasks[name] = asyncio.create_task(fn(), name=name)
        await self.stopping.wait()
        # Detención ordenada: el trabajo en curso termina su fase segura.
        work = self._tasks.get("work")
        if work and not work.done():
            try:
                await asyncio.wait_for(asyncio.shield(work), timeout=self.settings.max_order_duration_seconds)
            except asyncio.TimeoutError:
                work.cancel()
        try:
            await asyncio.wait_for(self.sync.flush(), 15)
        except Exception:
            pass
        for task in self._tasks.values():
            task.cancel()
        await asyncio.gather(*self._tasks.values(), return_exceptions=True)
        self.set_state(AgentState.STOPPED)
        self.publish_status()
        await self.api.aclose()

    def restart_loop(self, name: str) -> None:
        loops = {"control": self.control_loop, "heartbeat": self.heartbeat_loop, "sync": self.sync_loop,
                 "work": self.work_loop}
        if name in loops and not self.stopping.is_set():
            old = self._tasks.get(name)
            if old and not old.done():
                old.cancel()
            self._tasks[name] = asyncio.create_task(loops[name](), name=name)
            self.db.add_event("WARNING", "watchdog", f"Componente reiniciado: {name}")

    async def control_loop(self) -> None:
        while not self.stopping.is_set():
            self.touch("control")
            for cmd in self.db.pop_commands():
                self.handle_command(cmd["command"], cmd.get("issued_by") or "")
            self.publish_status()
            await asyncio.sleep(0.5)

    def handle_command(self, command: str, issued_by: str = "") -> None:
        log.info("Comando recibido: %s (%s)", command, issued_by)
        if command in ("start", "resume"):
            if self.emergency_flag.exists():
                self.db.add_event("ERROR", "agent", "No se puede iniciar: parada de emergencia activa")
                return
            self.desired_running = True
            if self.state in (AgentState.PAUSED, AgentState.STOPPED, AgentState.STARTING, AgentState.DEGRADED):
                self.set_state(AgentState.RUNNING, f"por {issued_by}")
        elif command == "pause":
            self.desired_running = False
            self.set_state(AgentState.PAUSED, f"por {issued_by}")
        elif command == "stop":
            self.desired_running = False
            self.stopping.set()
        elif command == "emergency_stop":
            self.emergency_flag.write_text(f"{iso_now()} {issued_by}", encoding="utf-8")
            self.desired_running = False
            self.set_state(AgentState.EMERGENCY_STOP, f"por {issued_by}")
            self.db.enqueue("alert", f"alert:emergency:{int(time.time())}", {
                "severity": "critical", "code": "EMERGENCY_STOP", "message": f"Parada de emergencia por {issued_by}",
                "at": iso_now()})
        elif command == "clear_emergency":
            if self.emergency_flag.exists():
                self.emergency_flag.unlink()
            self.set_state(AgentState.PAUSED, f"emergencia liberada por {issued_by}")
        else:
            log.warning("Comando desconocido ignorado: %s", command)

    async def heartbeat_loop(self) -> None:
        seq = 0
        while not self.stopping.is_set():
            self.touch("heartbeat")
            seq += 1
            await self.send_heartbeat(seq)
            await asyncio.sleep(self.settings.heartbeat_interval_seconds)

    async def send_heartbeat(self, seq: int = 0) -> None:
        usage = self.db.usage_today()
        payload = {
            "agent_version": __version__, "state": self.state.value, "mode": self.settings.automation_mode.value,
            "current_job_id": self.current_job.job_id if self.current_job else None, "sequence": seq,
            "emulator": {"device_ok": self.health.device_ok, "running": self.health.emulator_running},
            "usage_today": {"requests": usage.get("requests"), "total_tokens": usage.get("total_tokens"),
                            "cost_usd": round(float(usage.get("cost_usd") or 0), 6)},
            "outbox_pending": self.db.outbox_pending_count(), "sent_at": iso_now(),
        }
        try:
            response = await self.api.heartbeat(payload)
            self._api_ok()
            if self.state == AgentState.AUTH_ERROR:
                self.set_state(AgentState.PAUSED, "credenciales válidas de nuevo")
            requested = (response or {}).get("requested_state")
            # El backend solo puede pausar o reanudar: nunca ejecutar comandos en la PC.
            if requested == "paused" and self.state == AgentState.RUNNING:
                self.handle_command("pause", "backend")
            elif requested == "running" and self.state == AgentState.PAUSED and not self.emergency_flag.exists():
                self.handle_command("resume", "backend")
        except AuthError as exc:
            self.health.api_error = str(exc)
            self.set_state(AgentState.AUTH_ERROR, "token inválido, expirado o revocado")
        except ApiError as exc:
            self._api_fail(exc)

    async def sync_loop(self) -> None:
        while not self.stopping.is_set():
            self.touch("sync")
            if self.state != AgentState.AUTH_ERROR:
                try:
                    if await self.sync.flush():
                        self._api_ok()
                except AuthError as exc:
                    self.set_state(AgentState.AUTH_ERROR, str(exc))
                except Exception as exc:  # nunca dejar morir el bucle
                    log.exception("Error en sincronización: %s", exc)
            await asyncio.sleep(self.settings.outbox_sync_interval_seconds)

    async def ensure_device(self) -> DeviceController | None:
        try:
            if self.device is None:
                if self.device_factory is None:
                    raise DeviceError("No hay controlador de dispositivo configurado")
                self.device = await self.device_factory()
            if not await self.device.is_responsive():
                raise DeviceError("El dispositivo Android no responde")
            self.health.device_ok, self.health.device_error = True, None
            self.health.device_last_ok = time.time()
            return self.device
        except DeviceError as exc:
            self.health.device_ok, self.health.device_error = False, str(exc)
            self.device = None
            return None

    async def work_loop(self) -> None:
        while not self.stopping.is_set():
            self.touch("work")
            if self.emergency_flag.exists():
                if self.state != AgentState.EMERGENCY_STOP:
                    self.set_state(AgentState.EMERGENCY_STOP)
                await asyncio.sleep(1)
                continue
            if not self.desired_running or self.state in (AgentState.PAUSED, AgentState.AUTH_ERROR):
                await asyncio.sleep(0.5)
                continue
            if self.vision is not None and self.tracker.daily_budget_exhausted():
                self.set_state(AgentState.BUDGET_EXHAUSTED, "presupuesto diario de IA agotado")
                await asyncio.sleep(max(self.settings.poll_interval_seconds, 0.05))
                continue
            processed = await self.work_once()
            if not processed:
                await asyncio.sleep(self.settings.poll_interval_seconds)

    async def work_once(self) -> bool:
        """Un ciclo: verificar entorno, reclamar un trabajo y ejecutarlo. Devuelve True si procesó uno."""
        device = await self.ensure_device()
        if device is None:
            self.set_state(AgentState.DEGRADED, "emulador no disponible")
            return False
        try:
            job = await self.queue.next_job()
            self._api_ok()
        except AuthError as exc:
            self.set_state(AgentState.AUTH_ERROR, str(exc))
            return False
        except ApiUnavailable as exc:
            self._api_fail(exc)
            self.set_state(AgentState.DEGRADED, "API no disponible")
            return False
        except ApiError as exc:
            self._api_fail(exc)
            return False
        if self.state in (AgentState.DEGRADED, AgentState.BUDGET_EXHAUSTED, AgentState.STARTING):
            self.set_state(AgentState.RUNNING)
        if job is None:
            return False
        await self.process_job(job, device)
        return True

    async def process_job(self, job: Job, device: DeviceController) -> JobOutcome:
        assert self.workflow is not None
        self.current_job = job
        s = self.settings
        cfg = EngineConfig(
            mode=s.automation_mode.value, agent_id=self.api.agent_id, max_actions=s.max_actions_per_order,
            max_duration_s=s.max_order_duration_seconds, max_step_retries=s.max_step_retries,
            max_consecutive_errors=s.max_consecutive_errors, screen_wait_timeout_s=s.screen_wait_timeout_seconds,
            approval_timeout_s=s.approval_timeout_seconds, min_confidence=s.ai_min_confidence,
            verify_result_with_ai=s.verify_result_with_ai)
        for key, value in self.engine_overrides.items():
            setattr(cfg, key, value)
        engine = WorkflowEngine(db=self.db, device=device, workflow=self.workflow, config=cfg,
                                approvals=self.approvals, evidence=self.evidence, vision=self.vision,
                                verify_job_still_assigned=self._job_still_assigned,
                                emergency_flag=self.emergency_flag, live_screenshot=self.live_screenshot)
        try:
            outcome = await engine.run(job)
        except Exception as exc:  # defensa final: nunca perder el estado de un trabajo
            log.exception("Error inesperado procesando %s", job.job_id)
            row = self.db.get_job(job.job_id) or {}
            doubt = self.recovery.commit_in_doubt(job.job_id) or row.get("status") in (
                JobStatus.COMMITTING.value, JobStatus.VERIFYING.value)
            outcome = JobOutcome(job_id=job.job_id, order_id=job.order_id,
                                 status=JobStatus.NEEDS_REVIEW if doubt else JobStatus.RELEASED,
                                 error_code="INTERNAL_ERROR", message=str(exc), pre_commit=not doubt)
        self.queue.finish(job, outcome)
        if outcome.status in (JobStatus.NEEDS_REVIEW,) or outcome.error_code in ("SCREEN_UNRECOGNIZED",):
            self.db.enqueue("alert", f"alert:{outcome.error_code}:{job.job_id}:{int(time.time())}", {
                "severity": "critical" if outcome.status == JobStatus.NEEDS_REVIEW else "warning",
                "code": outcome.error_code or "NEEDS_REVIEW", "message": f"{job.order_id}: {outcome.message}",
                "at": iso_now()})
        self.current_job = None
        self.db.set_status("current_job", None)
        return outcome

    async def _job_still_assigned(self, job_id: str) -> bool:
        state = await self.api.get_job(job_id)
        return state.get("status") == "claimed" and state.get("claimed_by") in (None, self.api.agent_id)


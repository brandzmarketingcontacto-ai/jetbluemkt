"""AgentService: proceso principal de ejecución, independiente de la interfaz.

Bucles asíncronos:
- control:   comandos de la interfaz (iniciar, pausar, detener, emergencia) vía SQLite.
- heartbeat: POST /agent/heartbeat; lee el modo y la pausa que decide DiamantesExpress.
- sync:      envío del outbox (progreso y resultados con uso de OpenAI y evidencia).
- work:      POST /agent/tasks/claim y ejecución de una tarea a la vez por instancia Android.
- watchdog:  supervisión de bucles, Internet y emulador.

El servidor manda: el agente solo reclama tareas cuando el modo del servidor es compatible con el
modo local, y una tarea con simulate/simulated=true se ejecuta SIEMPRE con el flujo de simulación
(la lista de paquetes autorizados de ese flujo impide tocar el juego).
"""

from __future__ import annotations

import asyncio
import logging
import os
import time
from dataclasses import dataclass, field
from datetime import datetime
from typing import Any, Awaitable, Callable

from dxagent import __version__, paths
from dxagent.ai.pricing import PricingTable
from dxagent.ai.usage import UsageTracker
from dxagent.ai.vision import OpenAIVisionAgent, build_openai_client
from dxagent.api.client import HorizonsApiClient
from dxagent.api.contract import ApiContract
from dxagent.api.errors import ApiError, ApiUnavailable, AuthError, ConflictError, NotFoundError
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
from dxagent.settings import AgentSettings, AutomationMode, SecretStore

log = logging.getLogger(__name__)
DeviceFactory = Callable[[], Awaitable[DeviceController]]

# Modos del servidor que cada modo local acepta reclamar.
COMPATIBLE_SERVER_MODES = {
    AutomationMode.SIMULATION: {"simulation"},
    AutomationMode.SUPERVISED: {"simulation", "supervised"},
    AutomationMode.AUTHORIZED_PRODUCTION: {"simulation", "supervised", "production"},
}


@dataclass
class Health:
    api_online: bool = False
    api_last_ok: float | None = None
    api_error: str | None = None
    device_ok: bool = False
    device_error: str | None = None
    device_last_ok: float | None = None
    emulator_running: bool | None = None
    server_checked_at: float | None = None
    liveness: dict[str, float] = field(default_factory=dict)


class AgentService:
    def __init__(self, settings: AgentSettings, db: LocalDatabase, secrets: SecretStore, *,
                 api: HorizonsApiClient | None = None, device_factory: DeviceFactory | None = None,
                 vision: OpenAIVisionAgent | None = None, emulator=None, workflow: WorkflowDef | None = None,
                 evidence_root=None, emergency_flag=None, live_screenshot=None, autostart_running: bool = True,
                 engine_overrides: dict[str, Any] | None = None):
        self.settings = settings
        self.db = db
        self.secrets = secrets
        self.emulator = emulator  # BlueStacksManager | LDPlayerManager | None (simulador interno)
        self.emergency_flag = emergency_flag or paths.emergency_flag_path()
        self.live_screenshot = live_screenshot
        self.health = Health()
        self.state = AgentState.STOPPED
        self.state_reason = ""
        self.desired_running = autostart_running
        self.stopping = asyncio.Event()
        self.current_job: Job | None = None
        self.engine_overrides = engine_overrides or {}
        self.reconciled = False
        self.lease_lost: set[str] = set()

        self.api = api or self._build_api()
        self.pricing = PricingTable.load(settings.pricing_path or None)
        self.tracker = UsageTracker(db, self.pricing, self.api.agent_id,
                                    settings.max_ai_cost_per_order_usd, settings.daily_ai_budget_usd,
                                    settings.max_ai_requests_per_order, settings.ai_reserve_per_request_usd)
        self.vision = vision if vision is not None else self._build_vision()
        self.workflow = workflow            # flujo de regalo configurado (real o de simulación)
        self.friend_workflow: WorkflowDef | None = None
        self.sim_workflow: WorkflowDef | None = None
        self.sim_friend_workflow: WorkflowDef | None = None
        self.diamond_balance: int | None = None   # saldo leído en pantalla; se informa en el heartbeat
        self._balance_dirty = False
        self.device_factory = device_factory
        self.device: DeviceController | None = None
        self.evidence = EvidenceManager(db, evidence_root or paths.evidence_dir())
        instance_key = f"{settings.emulator}:{settings.emulator_instance or 'default'}"
        self.queue = JobQueueManager(db, self.api, instance_key, settings.account_key,
                                     self.evidence, settings.openai_model)
        self.sync = OutboxSync(db, self.api)
        self.recovery = RecoveryManager(db)
        self.approvals = ApprovalManager(db)
        self.watchdog = Watchdog(self)
        self._tasks: dict[str, asyncio.Task] = {}

    # --------------------------------------------------------------- builders
    def _build_api(self) -> HorizonsApiClient:
        contract = ApiContract.load(self.settings.openapi_contract_path or None, self.settings.route_overrides)
        return HorizonsApiClient(self.settings.api_url, self.secrets.get("agent_token"), contract,
                                 agent_name=self.settings.agent_name, timeout=self.settings.api_timeout_seconds,
                                 allow_insecure_localhost=self.settings.allow_insecure_http_localhost)

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
        if state != self.state or reason != self.state_reason:
            if state != self.state:
                log.info("Estado del agente: %s -> %s %s", self.state.value, state.value, reason)
                self.db.add_event("INFO" if state in (AgentState.RUNNING, AgentState.PAUSED) else "WARNING",
                                  "agent", f"Estado: {state.value} {reason}".strip())
            self.state, self.state_reason = state, reason

    def publish_status(self) -> None:
        srv = self.api.state
        self.db.set_status("agent", {
            "state": self.state.value, "state_reason": self.state_reason, "desired_running": self.desired_running,
            "pid": os.getpid(), "version": __version__, "mode": self.settings.automation_mode.value,
            "workflow": self.workflow.id if self.workflow else self.settings.workflow_id,
            "server": {"mode": srv.mode, "paused": srv.paused, "accepted": srv.accepted,
                       "booyah_agent": srv.booyah_agent, "lease_seconds": srv.lease_seconds,
                       "last_reason": srv.last_reason, "instructions": srv.instructions},
            "api_online": self.health.api_online, "api_error": self.health.api_error,
            "api_last_ok": self.health.api_last_ok, "device_ok": self.health.device_ok,
            "device_error": self.health.device_error, "emulator_running": self.health.emulator_running,
            "emulator": self.emulator.display_name if self.emulator is not None else "Simulador interno",
            "vision_enabled": self.vision is not None, "outbox_pending": self.db.outbox_pending_count(),
            "current_job_id": self.current_job.job_id if self.current_job else None,
            "diamond_balance": self.diamond_balance,
            "sender_account": {"name": srv.sender_account_name, "id": srv.sender_account_id},
            "emergency": self.emergency_flag.exists(), "ts": time.time(),
        })

    def touch(self, loop_name: str) -> None:
        self.health.liveness[loop_name] = time.monotonic()

    # ------------------------------------------------------------------ setup
    async def startup(self) -> None:
        previous = self.db.get_status("agent") or {}
        self.set_state(AgentState.STARTING)
        # Respeta una pausa/detención manual anterior a un reinicio del servicio.
        if previous.get("desired_running") is False:
            self.desired_running = False
        # Comandos antiguos encolados mientras el servicio no corría quedan obsoletos (la emergencia usa su
        # archivo bandera). Los recientes (p. ej. "start" enviado por la interfaz al lanzar el servicio) se aplican.
        for cmd in self.db.pop_commands():
            age = time.time() - datetime.fromisoformat(cmd["created_at"]).timestamp()
            if age <= 30:
                self.handle_command(cmd["command"], cmd.get("issued_by") or "")
            else:
                log.info("Comando obsoleto descartado: %s (%.0fs)", cmd["command"], age)
        self.recovery.recover_on_startup()
        self._load_workflows()
        await self.send_heartbeat()
        if self.emergency_flag.exists():
            self.set_state(AgentState.EMERGENCY_STOP, "bandera de emergencia presente")
        elif self.state == AgentState.AUTH_ERROR:
            pass
        else:
            self.set_state(AgentState.RUNNING if self.desired_running else AgentState.PAUSED)
        self.publish_status()

    def _load_workflows(self) -> None:
        mode = self.settings.automation_mode
        try:
            self.sim_workflow = load_workflow(self.settings.simulation_workflow_id)
            self.sim_friend_workflow = load_workflow(self.settings.simulation_friend_workflow_id)
            for wf, phase in ((self.sim_workflow, "gift"), (self.sim_friend_workflow, "friend_request")):
                if not wf.simulation or wf.phase != phase:
                    raise WorkflowNotReady(f"{wf.id} debe ser un flujo de simulación de la fase {phase}")
            if self.workflow is None:
                self.workflow = load_workflow(self.settings.workflow_id)
            if self.friend_workflow is None:
                self.friend_workflow = load_workflow(self.settings.friend_workflow_id)
            if self.workflow.phase != "gift" or self.friend_workflow.phase != "friend_request":
                raise WorkflowNotReady("workflow_id debe ser de la fase gift y friend_workflow_id de friend_request")
            if mode == AutomationMode.SIMULATION and not (self.workflow.simulation and self.friend_workflow.simulation):
                raise WorkflowNotReady("El modo SIMULACIÓN solo puede usar flujos de simulación")
            if mode == AutomationMode.AUTHORIZED_PRODUCTION:
                # La parte del servidor (mode=production) se comprueba por tarea.
                check_mode_allowed(self.workflow, mode.value, backend_production_authorized=True)
                check_mode_allowed(self.friend_workflow, mode.value, backend_production_authorized=True)
        except (WorkflowNotReady, ValueError) as exc:
            self.db.add_event("ERROR", "workflow", f"Flujo no disponible: {exc}")
            raise

    @property
    def real_workflow(self) -> WorkflowDef | None:
        """Flujo de regalo para tareas NO simuladas (nunca un flujo de simulación)."""
        return self.workflow if self.workflow is not None and not self.workflow.simulation else None

    def workflow_for(self, phase: str, simulated: bool) -> WorkflowDef | None:
        if simulated:
            return self.sim_friend_workflow if phase == "friend_request" else self.sim_workflow
        wf = self.friend_workflow if phase == "friend_request" else self.workflow
        return wf if wf is not None and not wf.simulation else None

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
        # Detención ordenada: la tarea en curso termina su fase segura.
        work = self._tasks.get("work")
        if work and not work.done():
            try:
                await asyncio.wait_for(asyncio.shield(work), timeout=self.settings.max_order_duration_seconds)
            except asyncio.TimeoutError:
                work.cancel()
        try:
            await asyncio.wait_for(self.sync.flush_all(), 15)
        except Exception:
            pass
        for task in self._tasks.values():
            task.cancel()
        await asyncio.gather(*self._tasks.values(), return_exceptions=True)
        if self.settings.close_emulator_on_stop and self.emulator is not None and self.recovery.restart_is_safe():
            self.emulator.stop()
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
            self.db.add_alert(f"emergency:{time.time()}", "CRITICAL", "EMERGENCY_STOP",
                              f"Parada de emergencia por {issued_by}")
        elif command == "clear_emergency":
            if self.emergency_flag.exists():
                self.emergency_flag.unlink()
            self.set_state(AgentState.PAUSED, f"emergencia liberada por {issued_by}")
        else:
            log.warning("Comando desconocido ignorado: %s", command)

    async def heartbeat_loop(self) -> None:
        while not self.stopping.is_set():
            self.touch("heartbeat")
            await self.send_heartbeat()
            await asyncio.sleep(self.settings.heartbeat_interval_seconds)

    async def send_heartbeat(self) -> None:
        try:
            await self.api.heartbeat(self.diamond_balance)
            self._balance_dirty = False
            self._api_ok()
            self.health.server_checked_at = time.monotonic()
            if self.state == AgentState.AUTH_ERROR:
                self.set_state(AgentState.PAUSED if not self.desired_running else AgentState.RUNNING,
                               "credenciales válidas de nuevo")
            await self._reconcile_once()
        except AuthError as exc:
            self.health.api_error = str(exc)
            self.set_state(AgentState.AUTH_ERROR, "token inválido, expirado o revocado")
        except ApiError as exc:
            self._api_fail(exc)

    async def _reconcile_once(self) -> None:
        """Tras arrancar, compara las tareas que el servidor nos atribuye con el estado local."""
        if self.reconciled or self.current_job is not None:
            return
        try:
            tasks = await self.api.current_tasks()
        except ApiError as exc:
            log.warning("No se pudo consultar /agent/tasks/current: %s", exc)
            return
        self.reconciled = True
        actions = self.recovery.reconcile_with_server(tasks)
        if actions:
            log.warning("Reconciliación con el servidor", extra={"actions": actions})

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

    def poll_interval(self) -> float:
        suggested = self.api.state.poll_after_seconds or 0
        return max(self.settings.poll_interval_seconds, float(suggested))

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
                await asyncio.sleep(self.poll_interval())

    def can_claim(self) -> tuple[bool, str]:
        """Solo se reclama si el servidor reparte tareas y su modo es compatible con el modo local."""
        srv = self.api.state
        if srv.mode in (None, "off"):
            return False, "automatización apagada en DiamantesExpress (modo off)"
        if srv.paused:
            return False, "pausado desde DiamantesExpress"
        if not srv.accepted:
            return False, "el servidor no acepta trabajo de este agente"
        allowed = COMPATIBLE_SERVER_MODES[self.settings.automation_mode]
        if srv.mode not in allowed:
            return False, f"modo del servidor '{srv.mode}' no permitido por el modo local {self.settings.automation_mode.value}"
        if srv.mode != "simulation" and (self.workflow_for("gift", False) is None
                                         or self.workflow_for("friend_request", False) is None):
            return False, (f"el servidor está en '{srv.mode}' pero no hay flujos reales validados de amistad y "
                           "regalo (solo se ejecutan tareas simuladas)")
        return True, ""

    async def work_once(self) -> bool:
        """Un ciclo: verificar entorno, reclamar una tarea y ejecutarla. Devuelve True si procesó una."""
        stale = (self.health.server_checked_at is None or self._balance_dirty
                 or time.monotonic() - self.health.server_checked_at > self.settings.heartbeat_interval_seconds * 2)
        if stale:  # también tras leer un saldo nuevo: Horizons decide con él qué regalos entregar
            await self.send_heartbeat()
            if self.state == AgentState.AUTH_ERROR:
                return False
        ok, reason = self.can_claim()
        if not ok:
            if self.health.api_online:
                self.set_state(AgentState.SERVER_HOLD, reason)
            else:
                self.set_state(AgentState.DEGRADED, "API no disponible")
            return False
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
        if job is None and self.api.state.last_reason == "spend_limit_reached":
            self.set_state(AgentState.BUDGET_EXHAUSTED, "límite de gasto alcanzado en DiamantesExpress")
            return False
        if job is None and self.api.state.last_reason == "insufficient_balance":
            srv = self.api.state
            reason = (f"saldo de diamantes insuficiente: se necesitan {srv.balance_needed}, "
                      f"saldo {srv.server_diamond_balance if srv.server_diamond_balance is not None else self.diamond_balance}")
            self.set_state(AgentState.SERVER_HOLD, reason)
            self.db.add_alert(f"insufficient_balance:{int(time.time() // 3600)}", "CRITICAL", "INSUFFICIENT_BALANCE",
                              f"Recargue diamantes en la cuenta emisora: {reason}")
            return False
        if self.state in (AgentState.DEGRADED, AgentState.BUDGET_EXHAUSTED, AgentState.STARTING,
                          AgentState.SERVER_HOLD):
            self.set_state(AgentState.RUNNING)
        if job is None:
            return False
        await self.process_job(job, device)
        return True

    def plan_for(self, job: Job) -> tuple[WorkflowDef | None, str, str]:
        """Elige flujo y modo efectivo para una tarea. Devuelve (flujo, modo, motivo_si_no_se_puede)."""
        local = self.settings.automation_mode
        if job.simulated:
            mode = "SUPERVISED" if local == AutomationMode.SUPERVISED else "SIMULATION"
            return self.workflow_for(job.phase, True), mode, ""
        wf = self.workflow_for(job.phase, False)
        if wf is None:
            return None, "", f"tarea real ({job.phase}) sin flujo real validado"
        if job.server_mode == "supervised":
            return wf, "SUPERVISED", ""
        if job.server_mode == "production":
            if local == AutomationMode.AUTHORIZED_PRODUCTION:
                try:
                    check_mode_allowed(wf, "AUTHORIZED_PRODUCTION", backend_production_authorized=True)
                    return wf, "AUTHORIZED_PRODUCTION", ""
                except WorkflowNotReady as exc:
                    return None, "", str(exc)
            # El modo local es más restrictivo: se exige aprobación humana.
            return wf, "SUPERVISED", ""
        return None, "", f"modo de tarea no soportado: {job.server_mode}"

    async def process_job(self, job: Job, device: DeviceController) -> JobOutcome:
        self.current_job = job
        workflow, mode, problem = self.plan_for(job)
        if workflow is None:
            outcome = JobOutcome(job_id=job.job_id, order_id=job.order_id, status=JobStatus.RELEASED,
                                 error_code="MODE_MISMATCH", message=f"No se ejecuta: {problem}")
            self.queue.finish(job, outcome)
            self.db.add_alert(f"mode_mismatch:{job.job_id}", "WARNING", "MODE_MISMATCH", f"{job.order_id}: {problem}")
            self.current_job = None
            return outcome
        s = self.settings
        cfg = EngineConfig(
            mode=mode, agent_id=self.api.agent_id, max_actions=s.max_actions_per_order,
            max_duration_s=s.max_order_duration_seconds, max_step_retries=s.max_step_retries,
            max_consecutive_errors=s.max_consecutive_errors, screen_wait_timeout_s=s.screen_wait_timeout_seconds,
            approval_timeout_s=s.approval_timeout_seconds, min_confidence=s.ai_min_confidence,
            verify_result_with_ai=s.verify_result_with_ai)
        for key, value in self.engine_overrides.items():
            setattr(cfg, key, value)
        engine = WorkflowEngine(db=self.db, device=device, workflow=workflow, config=cfg,
                                approvals=self.approvals, evidence=self.evidence, vision=self.vision,
                                verify_job_still_assigned=self._job_still_assigned,
                                emergency_flag=self.emergency_flag, live_screenshot=self.live_screenshot)
        keeper = asyncio.create_task(self._keep_lease(job))
        try:
            outcome = await engine.run(job)
        except Exception as exc:  # defensa final: nunca perder el estado de una tarea
            log.exception("Error inesperado procesando %s", job.job_id)
            row = self.db.get_job(job.job_id) or {}
            doubt = self.recovery.commit_in_doubt(job.job_id) or row.get("status") in (
                JobStatus.COMMITTING.value, JobStatus.VERIFYING.value)
            outcome = JobOutcome(job_id=job.job_id, order_id=job.order_id,
                                 status=JobStatus.NEEDS_REVIEW if doubt else JobStatus.RELEASED,
                                 error_code="INTERNAL_ERROR", message=str(exc), pre_commit=not doubt)
        finally:
            keeper.cancel()
            await asyncio.gather(keeper, return_exceptions=True)
        self.queue.finish(job, outcome, workflow.id, mode)
        if outcome.balance is not None and outcome.balance != self.diamond_balance:
            self.diamond_balance = outcome.balance
            self._balance_dirty = True
            threshold = self.api.state.balance_threshold
            if threshold is not None and outcome.balance < threshold:
                self.db.add_alert(f"low_balance:{int(time.time() // 3600)}", "WARNING", "LOW_BALANCE",
                                  f"Saldo de diamantes bajo: {outcome.balance} (umbral {threshold})")
        if outcome.status == JobStatus.NEEDS_REVIEW or outcome.error_code == "SCREEN_UNRECOGNIZED":
            self.db.add_alert(f"{outcome.error_code}:{job.job_id}:{job.attempt}",
                              "CRITICAL" if outcome.status == JobStatus.NEEDS_REVIEW else "WARNING",
                              outcome.error_code or "NEEDS_REVIEW", f"{job.order_id}: {outcome.message}", job.job_id)
        self.lease_lost.discard(job.job_id)
        self.current_job = None
        self.db.set_status("current_job", None)
        return outcome

    async def _keep_lease(self, job: Job) -> None:
        """Renueva el lease (POST progress) mientras la tarea siga abierta, p. ej. esperando aprobación."""
        interval = max(5.0, min(60.0, self.api.state.lease_seconds / 3))
        while True:
            await asyncio.sleep(interval)
            row = self.db.get_job(job.job_id) or {}
            status = "verifying" if row.get("status") == JobStatus.VERIFYING.value else "processing"
            try:
                await self.api.report_progress(job.job_id, status, f"Agente activo ({row.get('status')})",
                                               {"step": "lease_keepalive", "simulated": job.simulated})
            except (ConflictError, NotFoundError):
                self.lease_lost.add(job.job_id)
                self.db.add_alert(f"lease_lost:{job.job_id}", "CRITICAL", "LEASE_LOST",
                                  f"{job.order_id}: el servidor ya no asigna esta tarea a este agente", job.job_id)
                return
            except ApiError as exc:
                log.warning("No se pudo renovar el lease de %s: %s", job.job_id, exc)

    async def _job_still_assigned(self, job_id: str) -> bool:
        """Antes del paso de entrega: confirma propiedad y renueva el lease con una llamada en vivo."""
        if job_id in self.lease_lost:
            return False
        try:
            await self.api.report_progress(job_id, "processing", "Verificación de propiedad antes de la entrega",
                                           {"step": "pre_commit_check"})
            return True
        except (ConflictError, NotFoundError):
            return False

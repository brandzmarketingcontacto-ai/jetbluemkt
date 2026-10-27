"""WorkflowEngine: máquina de estados que ejecuta un flujo configurable sobre un pedido.

Garantías de seguridad:
- Toda acción pasa por ExecutionPolicy (lista permitida + límites).
- Los datos (destinatario y producto) se verifican en pantalla ANTES del paso transaccional.
- Antes del paso transaccional se confirma con el backend que el trabajo sigue asignado.
- El paso transaccional se ejecuta UNA sola vez: se escribe un checkpoint durable antes de
  tocar y nunca se reintenta. Cualquier duda posterior termina en `needs_review`.
- Un pedido solo se marca `completed` si la pantalla de resultado muestra éxito y los datos
  del resultado coinciden con el pedido (no basta con haber pulsado el botón).
"""

from __future__ import annotations

import asyncio
import logging
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Awaitable, Callable

from dxagent.ai.usage import BudgetExceeded
from dxagent.ai.vision import OpenAIVisionAgent, VisionUnavailable
from dxagent.android.app_manager import AndroidAppManager
from dxagent.android.device import DeviceController, DeviceError
from dxagent.database import LocalDatabase
from dxagent.engine.approval import ApprovalManager
from dxagent.engine.evidence import EvidenceManager
from dxagent.engine.observer import Observation, ObservationError, ScreenObserver
from dxagent.engine.policy import EmergencyStop, ExecutionLimits, ExecutionPolicy, PolicyViolation
from dxagent.engine.workflow_def import (ExitDef, OrderRejected, StepDef, WorkflowDef, order_context, render)
from dxagent.models import Job, JobOutcome, JobStatus, VerificationResult

log = logging.getLogger(__name__)


class StepFailed(Exception):
    def __init__(self, code: str, message: str, *, order_fault: bool = False, retryable: bool = True):
        super().__init__(message)
        self.code = code
        self.order_fault = order_fault
        self.retryable = retryable


class StepExit(Exception):
    """Salida anticipada definida en el flujo (antes del envío)."""

    def __init__(self, exit_def: ExitDef):
        super().__init__(exit_def.message or exit_def.element)
        self.exit = exit_def


def parse_int(text: str | None) -> int | None:
    """'2,898' / '2.898' / 'x57' -> entero; None si no hay dígitos."""
    digits = "".join(ch for ch in (text or "") if ch.isdigit())
    return int(digits) if digits else None


@dataclass
class EngineConfig:
    mode: str
    agent_id: str
    max_actions: int = 40
    max_duration_s: float = 300.0
    max_step_retries: int = 2
    max_consecutive_errors: int = 3
    screen_wait_timeout_s: float = 20.0
    poll_interval_s: float = 0.5
    settle_s: float = 0.3
    approval_timeout_s: float = 600.0
    min_confidence: float = 0.75
    verify_result_with_ai: bool = False


VerifyJobCallback = Callable[[str], Awaitable[bool]]


class WorkflowEngine:
    def __init__(self, *, db: LocalDatabase, device: DeviceController, workflow: WorkflowDef, config: EngineConfig,
                 approvals: ApprovalManager, evidence: EvidenceManager, vision: OpenAIVisionAgent | None = None,
                 verify_job_still_assigned: VerifyJobCallback | None = None, emergency_flag: Path | None = None,
                 live_screenshot: Path | None = None):
        self.db = db
        self.device = device
        self.wf = workflow
        self.cfg = config
        self.approvals = approvals
        self.evidence = evidence
        self.vision = vision
        self.verify_job_still_assigned = verify_job_still_assigned
        self.emergency_flag = emergency_flag
        self.live_screenshot = live_screenshot
        self._progress_seq = 0
        self.balance: int | None = None

    # ------------------------------------------------------------- utilidades
    def _report(self, job: Job, status: str, step: str, message: str) -> None:
        """Progreso para /agent/tasks/{id}/progress (solo admite processing | verifying; renueva el lease)."""
        self._progress_seq += 1
        api_status = "verifying" if status == "verifying" else "processing"
        self.db.enqueue("progress", f"progress:{job.job_id}:{job.attempt}:{self._progress_seq}",
                        {"status": api_status, "message": message,
                         "evidence": {"step": step, "state": status, "simulated": job.simulated,
                                      "at": time.strftime("%Y-%m-%dT%H:%M:%S%z")}}, job.job_id)

    def _live(self, job: Job, **fields: Any) -> None:
        current = self.db.get_status("current_job") or {}
        if current.get("job_id") != job.job_id:
            current = {"job_id": job.job_id, "order_id": job.order_id, "recipient": job.recipient.player_id,
                       "product": job.product.name, "started_at": time.time()}
        current.update(fields)
        self.db.set_status("current_job", current)

    def _on_observation(self, job: Job, obs: Observation) -> None:
        if self.live_screenshot is not None:
            try:
                tmp = self.live_screenshot.with_suffix(".tmp")
                tmp.write_bytes(obs.png)
                tmp.replace(self.live_screenshot)
            except OSError:
                pass
        suggestion = None
        if obs.ai is not None:
            sa = obs.ai.suggested_action
            suggestion = f"{sa.action} {sa.element_id or ''}".strip()
        self._live(job, screen=obs.screen_id or "desconocida", screen_method=obs.method,
                   ai_state=obs.ai.detected_state if obs.ai else None, ai_suggestion=suggestion,
                   ai_reason=obs.ai.reason if obs.ai else None, screenshot_at=time.time())

    def _capture(self, job: Job, obs: Observation | None, label: str) -> list[str]:
        if obs is None:
            return []
        ids = [self.evidence.save(job.job_id, "screenshot", obs.png, label)]
        if obs.xml:
            ids.append(self.evidence.save(job.job_id, "ui_dump", obs.xml.encode("utf-8"), label))
        return ids

    async def _tap(self, policy: ExecutionPolicy, obs: Observation, point: tuple[float, float], step: StepDef) -> None:
        x, y = point
        policy.authorize("tap", x=x, y=y, step_id=step.id)
        w, h = obs.size
        await self.device.tap(int(x * w), int(y * h))

    def _check_exits(self, step: StepDef | None, obs: Observation) -> None:
        for exit_def in (step.exits if step else []):
            spec = self.wf.elements[exit_def.element]
            if obs.node(spec.resource_id, spec.text) if (spec.resource_id or spec.text) else False:
                raise StepExit(exit_def)
            if obs.ai is not None and obs.ai.element(exit_def.element) is not None:
                raise StepExit(exit_def)

    async def _wait_for(self, observer: ScreenObserver, targets: set[str], goal: str,
                        timeout: float | None = None, fail_if: dict[str, str] | None = None,
                        step: StepDef | None = None) -> Observation:
        deadline = time.monotonic() + (timeout or self.cfg.screen_wait_timeout_s)
        last: Observation | None = None
        while True:
            await asyncio.sleep(self.cfg.settle_s)
            last = await observer.observe(goal)
            self._check_exits(step, last)  # antes que la pantalla esperada: "ya son amigos" está en el perfil
            if last.screen_id in targets:
                return last
            if fail_if and fail_if.get("element"):
                spec = self.wf.elements[fail_if["element"]]
                if last.node(spec.resource_id, None):
                    raise StepFailed(fail_if.get("code", "APP_ERROR"), f"La aplicación mostró {fail_if['element']}",
                                     order_fault=True, retryable=False)
            if time.monotonic() >= deadline:
                break
            await asyncio.sleep(self.cfg.poll_interval_s)
        detail = f"pantalla actual: {last.screen_id or 'desconocida'}" if last else "sin captura"
        notes = "; ".join(last.notes) if last and last.notes else ""
        raise StepFailed("SCREEN_UNRECOGNIZED" if last and last.screen_id is None else "UNEXPECTED_SCREEN",
                         f"Se esperaba {sorted(targets)} ({detail}) {notes}".strip())

    # -------------------------------------------------------------- ejecución
    async def run(self, job: Job) -> JobOutcome:
        started = time.monotonic()
        outcome = await self._run(job)
        outcome.duration_s = round(time.monotonic() - started, 2)
        totals = self.db.usage_totals(job_id=job.job_id)
        outcome.tokens_total = int(totals.get("total_tokens") or 0)
        outcome.cost_usd = round(float(totals.get("cost_usd") or 0.0), 6)
        outcome.ai_requests = int(totals.get("requests") or 0)
        if outcome.balance is None:
            outcome.balance = self.balance
        return outcome

    async def _run(self, job: Job) -> JobOutcome:
        def result(status: JobStatus, code: str | None, message: str, **kw: Any) -> JobOutcome:
            return JobOutcome(job_id=job.job_id, order_id=job.order_id, status=status, error_code=code,
                              message=message, actions=policy.actions if policy else 0, **kw)

        policy: ExecutionPolicy | None = None
        try:
            ctx = order_context(self.wf, job)
        except OrderRejected as exc:
            return result(JobStatus.FAILED, exc.code, str(exc))

        policy = ExecutionPolicy(
            ExecutionLimits(self.cfg.max_actions, self.cfg.max_duration_s, self.cfg.max_consecutive_errors),
            {self.wf.app.package}, self.emergency_flag)
        observer = ScreenObserver(self.device, self.wf, self.vision, policy, job.job_id, job.order_id,
                                  self.cfg.min_confidence, on_observation=lambda o: self._on_observation(job, o))
        apps = AndroidAppManager(self.device, {self.wf.app.package})
        self.db.update_job(job.job_id, status=JobStatus.RUNNING, started_at=time.strftime("%Y-%m-%dT%H:%M:%S%z"))
        self._live(job, step="inicio", status=JobStatus.RUNNING.value, actions=0)
        self._report(job, "running", "start", "Procesamiento iniciado")

        commit_index = self.wf.steps.index(self.wf.commit_step())
        approved_by: str | None = None

        # ----------------------------------------------- fase previa a la entrega
        try:
            for step in self.wf.steps[:commit_index]:
                await self._run_with_retries(job, step, ctx, policy, observer, apps)
        except EmergencyStop as exc:
            return result(JobStatus.RELEASED, exc.code, "Parada de emergencia antes de la entrega")
        except StepExit as exc:
            obs = await self._safe_obs(observer)
            ev = self._capture(job, obs, f"salida_{exc.exit.outcome}")
            return self._exit_outcome(job, exc.exit, result, ev)
        except StepFailed as exc:
            status = JobStatus.FAILED if exc.order_fault else JobStatus.RELEASED
            obs = await self._safe_obs(observer)
            ev = self._capture(job, obs, f"error_{exc.code}")
            return result(status, exc.code, str(exc), verification=VerificationResult(
                outcome="not_executed", method="pre_commit", evidence_ids=ev))
        except (PolicyViolation, BudgetExceeded) as exc:
            return result(JobStatus.RELEASED, exc.code, str(exc))
        except (DeviceError, VisionUnavailable, ObservationError) as exc:
            code = getattr(exc, "code", None) or type(exc).__name__.upper()
            return result(JobStatus.RELEASED, code, str(exc))

        # ---------------------------------------------- paso transaccional (único)
        commit = self.wf.commit_step()
        try:
            approved_by, summary_obs = await self._prepare_commit(job, ctx, commit, policy, observer)
        except EmergencyStop as exc:
            return result(JobStatus.RELEASED, exc.code, "Parada de emergencia antes de la entrega")
        except StepFailed as exc:
            status = JobStatus.FAILED if exc.order_fault else JobStatus.RELEASED
            return result(status, exc.code, str(exc))
        except (PolicyViolation, BudgetExceeded, DeviceError, VisionUnavailable, ObservationError) as exc:
            return result(JobStatus.RELEASED, getattr(exc, "code", None) or type(exc).__name__.upper(), str(exc))

        try:
            point, method = await observer.locate(commit.element or "", summary_obs, "Localizar el botón de envío")
            policy.authorize("tap", x=point[0], y=point[1], step_id=commit.id)
        except (StepFailed, PolicyViolation, ObservationError, BudgetExceeded, VisionUnavailable) as exc:
            return result(JobStatus.RELEASED, getattr(exc, "code", "COMMIT_PREPARE"), str(exc))

        # Checkpoint durable ANTES de tocar: si el proceso muere, la recuperación sabrá que el
        # resultado es incierto y no repetirá la operación.
        self.db.update_job(job.job_id, status=JobStatus.COMMITTING, approved_by=approved_by)
        self.db.add_checkpoint(job.job_id, commit.id, "commit_started", {"approved_by": approved_by, "method": method})
        self._live(job, step=commit.id, status=JobStatus.COMMITTING.value, actions=policy.actions)
        self._report(job, "committing", commit.id, "Ejecutando paso de entrega")
        try:
            w, h = summary_obs.size
            await self.device.tap(int(point[0] * w), int(point[1] * h))
            self.db.add_checkpoint(job.job_id, commit.id, "commit_sent")
        except Exception as exc:  # resultado desconocido: nunca reintentar
            self.db.add_checkpoint(job.job_id, commit.id, "commit_uncertain", {"error": str(exc)})
            return result(JobStatus.NEEDS_REVIEW, "COMMIT_UNCERTAIN",
                          f"Se perdió el control durante la confirmación: {exc}", pre_commit=False,
                          approved_by=approved_by)

        # ------------------------------------------------- verificación posterior
        self.db.update_job(job.job_id, status=JobStatus.VERIFYING)
        self._report(job, "verifying", commit.id, "Paso de entrega ejecutado; verificando el resultado")
        self._live(job, step="verificacion", status=JobStatus.VERIFYING.value, actions=policy.actions)
        verify_steps = self.wf.steps[commit_index + 1:]
        verification: VerificationResult | None = None
        try:
            for step in verify_steps:
                if step.kind == "verify_result":
                    verification = await self._verify_result(job, ctx, step, commit, observer)
                    self.db.add_checkpoint(job.job_id, step.id, "verified", verification.model_dump())
                    if verification.outcome != "success":
                        break
                elif verification is not None and verification.outcome == "success":
                    try:
                        await self._run_step(job, step, ctx, policy, observer, apps)
                    except Exception as exc:  # pasos opcionales de cierre
                        if not step.optional:
                            log.warning("Paso posterior falló: %s", exc)
        except Exception as exc:
            obs = await self._safe_obs(observer)
            ev = self._capture(job, obs, "verificacion_error")
            return result(JobStatus.NEEDS_REVIEW, "VERIFICATION_ERROR", f"No se pudo verificar el resultado: {exc}",
                          pre_commit=False, approved_by=approved_by,
                          verification=VerificationResult(outcome="uncertain", method="error", evidence_ids=ev))

        assert verification is not None
        verify_step = next(s for s in verify_steps if s.kind == "verify_result")
        if verification.outcome == "success":
            gift = self.wf.phase == "gift"
            return result(JobStatus.COMPLETED, None, "Entrega verificada" if gift else "Solicitud de amistad verificada",
                          pre_commit=False, approved_by=approved_by, verification=verification,
                          friend_status=verify_step.success_data.get("friend_status"),
                          delivered_quantity=job.product.quantity if gift else None)
        if verification.outcome == "failure":
            return result(JobStatus.FAILED, "DELIVERY_REJECTED", "La aplicación informó que el envío fue rechazado",
                          pre_commit=False, approved_by=approved_by, verification=verification,
                          delivered_quantity=0 if self.wf.phase == "gift" else None)
        return result(JobStatus.NEEDS_REVIEW, "RESULT_UNCERTAIN", "Resultado no verificable: requiere revisión manual",
                      pre_commit=False, approved_by=approved_by, verification=verification)

    def _exit_outcome(self, job: Job, exit_def: ExitDef, result, evidence_ids: list[str]) -> JobOutcome:
        verification = VerificationResult(outcome="not_executed", method="pre_commit_exit",
                                          details={"element": exit_def.element}, evidence_ids=evidence_ids)
        gift = self.wf.phase == "gift"
        if exit_def.outcome == "defer":
            return result(JobStatus.DEFERRED, exit_def.code or "DEFERRED", exit_def.message or "Tarea aplazada",
                          defer_reason=exit_def.reason or "deferred", verification=verification)
        status = JobStatus.COMPLETED if exit_def.outcome == "completed" else JobStatus.FAILED
        return result(status, exit_def.code, exit_def.message, friend_status=exit_def.friend_status,
                      delivered_quantity=0 if gift else None, verification=verification)

    async def _safe_obs(self, observer: ScreenObserver) -> Observation | None:
        try:
            # La evidencia de error no consume IA.
            vision, observer.vision = observer.vision, None
            try:
                return await observer.observe("evidencia")
            finally:
                observer.vision = vision
        except Exception:
            return None

    async def _run_with_retries(self, job: Job, step: StepDef, ctx: dict[str, str], policy: ExecutionPolicy,
                                observer: ScreenObserver, apps: AndroidAppManager) -> None:
        attempts = 0
        self.db.add_checkpoint(job.job_id, step.id, "start")
        while True:
            try:
                self._live(job, step=step.id, actions=policy.actions)
                await self._run_step(job, step, ctx, policy, observer, apps)
                policy.record_success()
                self.db.add_checkpoint(job.job_id, step.id, "done")
                self._report(job, "running", step.id, "Paso completado")
                return
            except StepFailed as exc:
                if not exc.retryable or attempts >= self.cfg.max_step_retries:
                    raise
                attempts += 1
                policy.record_error()
                log.warning("Reintentando paso %s (%s/%s): %s", step.id, attempts, self.cfg.max_step_retries, exc)

    async def _run_step(self, job: Job, step: StepDef, ctx: dict[str, str], policy: ExecutionPolicy,
                        observer: ScreenObserver, apps: AndroidAppManager) -> None:
        policy.check_emergency()
        kind = step.kind
        if kind == "launch_app":
            if not await apps.is_foreground(self.wf.app.package):
                policy.authorize("launch_app", package=self.wf.app.package, step_id=step.id)
                await apps.ensure_open(self.wf.app.package, self.wf.app.activity)
            return
        if kind in ("wait_screen", "navigate_to"):
            target = step.screen or ""
            obs = await observer.observe(f"Identificar si estamos en {target}")
            for _ in range(step.via_back if kind == "navigate_to" else 0):
                if obs.screen_id == target:
                    return
                policy.authorize("key_back", step_id=step.id)
                await self.device.keyevent("BACK")
                await asyncio.sleep(self.cfg.settle_s)
                obs = await observer.observe(f"Volver a {target}")
            if obs.screen_id != target:
                await self._wait_for(observer, {target}, f"Esperar la pantalla {target}")
            return
        if kind == "tap":
            element = self._element_name(step.element, ctx, step)
            obs = await observer.observe(f"Pulsar {element}")
            if step.expect and obs.screen_id == step.expect:
                self._check_exits(step, obs)
                return  # ya estamos en la pantalla esperada (reintento idempotente)
            point, _ = await observer.locate(element, obs, f"Localizar {element}")
            await self._tap(policy, obs, point, step)
            if step.expect:
                await self._wait_for(observer, {step.expect}, f"Esperar {step.expect}", fail_if=step.fail_if_visible,
                                     step=step)
            elif step.exits:
                await asyncio.sleep(self.cfg.settle_s)
                self._check_exits(step, await observer.observe(f"Comprobar el resultado de {element}"))
            return
        if kind == "set_quantity":
            await self._set_quantity(job, step, ctx, policy, observer)
            return
        if kind == "read_balance":
            obs = await observer.observe("Leer el saldo de diamantes")
            values, _ = await observer.read_fields([step.field or ""], obs, "Leer el saldo de diamantes")
            balance = parse_int(values.get(step.field or ""))
            if balance is not None:
                self.balance = balance
                self._live(job, balance=balance)
            elif not step.optional:
                raise StepFailed("BALANCE_UNREADABLE", "No se pudo leer el saldo de diamantes")
            return
        if kind == "input_text":
            text = render(step.value, ctx)
            policy.authorize("input_text", text=text, step_id=step.id)
            await self.device.input_text(text)
            return
        if kind == "tap_option":
            label = render(step.label, ctx)
            obs = await observer.observe(f"Seleccionar la opción '{label}'")
            if step.expect and obs.screen_id == step.expect:
                return
            point, _ = await observer.locate_option(label, obs, f"Seleccionar '{label}'")
            await self._tap(policy, obs, point, step)
            if step.expect:
                await self._wait_for(observer, {step.expect}, f"Esperar {step.expect}")
            return
        if kind == "verify_fields":
            obs = await self._wait_for(observer, {step.screen or ""}, "Verificar datos antes del envío", step=step)
            await self._check_fields(step, ctx, obs, observer)
            return
        raise StepFailed("STEP_NOT_SUPPORTED", f"Paso no ejecutable aquí: {kind}", retryable=False)

    def _element_name(self, template: str | None, ctx: dict[str, str], step: StepDef) -> str:
        name = render(template, ctx)
        if name not in self.wf.elements:
            raise StepFailed("ELEMENT_NOT_CONFIGURED", f"Paso {step.id}: el flujo no define el elemento {name}",
                             retryable=False)
        return name

    async def _set_quantity(self, job: Job, step: StepDef, ctx: dict[str, str], policy: ExecutionPolicy,
                            observer: ScreenObserver) -> None:
        """Ajusta la cantidad con los botones – / + leyendo el valor en pantalla tras cada tanda de toques."""
        target = int(render(step.value, ctx))
        for _ in range(20):
            obs = await observer.observe(f"Leer la cantidad (objetivo {target})")
            values, _ = await observer.read_fields([step.field or ""], obs, "Leer la cantidad a regalar")
            current = parse_int(values.get(step.field or ""))
            if current is None:
                raise StepFailed("QUANTITY_UNREADABLE", "No se pudo leer la cantidad en pantalla")
            if current == target:
                self._live(job, quantity=current)
                return
            button = step.plus if target > current else step.minus
            point, _ = await observer.locate(button or "", obs, f"Localizar {button}")
            for _ in range(min(abs(target - current), 10)):
                await self._tap(policy, obs, point, step)
            await asyncio.sleep(self.cfg.settle_s)
        raise StepFailed("QUANTITY_NOT_SET", f"No se logró fijar la cantidad en {target}", retryable=False)

    async def _check_fields(self, step: StepDef, ctx: dict[str, str], obs: Observation,
                            observer: ScreenObserver) -> dict[str, Any]:
        # Un valor esperado vacío (p. ej. costo total cuando Horizons no envía unitDiamondCost) no se comprueba.
        expected = {name: v for name, tpl in step.checks.items() if (v := render(tpl, ctx)) != ""}
        values, method = await observer.read_fields(list(expected), obs, "Leer los datos visibles")
        mismatches = {k: {"esperado": v, "en_pantalla": values.get(k)} for k, v in expected.items()
                      if (values.get(k) or "").strip() != v}
        if mismatches:
            raise StepFailed("DATA_MISMATCH", f"Los datos en pantalla no coinciden con el pedido: {mismatches}",
                             retryable=False)
        return {"fields": values, "method": method}

    async def _prepare_commit(self, job: Job, ctx: dict[str, str], commit: StepDef, policy: ExecutionPolicy,
                              observer: ScreenObserver) -> tuple[str, Observation]:
        verify_step = next(s for s in reversed(self.wf.steps[:self.wf.steps.index(commit)]) if s.kind == "verify_fields")
        # 1) ¿Sigue siendo nuestro el trabajo? (no cancelado, no reasignado)
        if self.verify_job_still_assigned is not None:
            try:
                ok = await self.verify_job_still_assigned(job.job_id)
            except Exception as exc:
                raise StepFailed("BACKEND_UNREACHABLE", f"No se pudo confirmar el pedido con el backend: {exc}",
                                 retryable=False) from exc
            if not ok:
                raise StepFailed("JOB_NO_LONGER_ASSIGNED", "La tarea ya no está asignada a este agente "
                                 "(lease vencido, cancelada o reasignada)", retryable=False)
        # 2) Aprobación según el modo
        obs = await observer.observe("Resumen previo al envío")
        checked = await self._check_fields(verify_step, ctx, obs, observer)
        total_cost = ctx.get("product.total_cost") or ""
        if self.balance is not None and total_cost and self.balance < int(total_cost):
            # No se envía: el agente reporta el saldo en el heartbeat y Horizons deja de entregar regalos.
            raise StepFailed("INSUFFICIENT_BALANCE", f"Saldo de diamantes insuficiente: hay {self.balance}, "
                             f"se necesitan {total_cost}", retryable=False)
        evidence_ids = self._capture(job, obs, "resumen_previo")
        if self.cfg.mode == "SIMULATION":
            approved_by = "politica:simulacion"
        elif self.cfg.mode == "AUTHORIZED_PRODUCTION":
            approved_by = f"politica:produccion_autorizada:{self.wf.authorization.reference}"
        else:
            self.db.update_job(job.job_id, status=JobStatus.AWAITING_APPROVAL)
            self._live(job, step=commit.id, status=JobStatus.AWAITING_APPROVAL.value)
            self._report(job, "awaiting_approval", commit.id, "Esperando aprobación humana")
            decision = await self.approvals.request(job.job_id, commit.id, {
                "order_id": job.order_id, "recipient": job.recipient.player_id, "product_code": job.product.code,
                "product_label": ctx.get("product.label", ""), "phase": job.phase,
                "quantity": ctx.get("product.quantity", ""), "total_cost": ctx.get("product.total_cost", ""),
                "chunk": f"{job.chunk_index + 1}/{job.chunk_count}", "fields": checked["fields"],
                "evidence_ids": evidence_ids,
            }, self.cfg.approval_timeout_s, should_abort=lambda: bool(self.emergency_flag and self.emergency_flag.exists()))
            policy.check_emergency()
            if decision.status == "rejected":
                raise StepFailed("APPROVAL_REJECTED", f"Envío rechazado por {decision.decided_by}",
                                 order_fault=True, retryable=False)
            if not decision.approved:
                raise StepFailed("APPROVAL_EXPIRED", "La aprobación expiró sin decisión", retryable=False)
            approved_by = decision.decided_by
            # 3) La pantalla pudo cambiar mientras se esperaba: se verifica de nuevo.
            obs = await observer.observe("Resumen previo al envío (revalidación)")
            if obs.screen_id != verify_step.screen:
                raise StepFailed("STATE_CHANGED", "La pantalla cambió mientras se esperaba la aprobación", retryable=False)
            await self._check_fields(verify_step, ctx, obs, observer)
        self.db.add_event("INFO", "approval", f"Envío de {job.order_id} aprobado por {approved_by}", job.job_id)
        return approved_by, obs

    async def _verify_result(self, job: Job, ctx: dict[str, str], step: StepDef, commit: StepDef,
                             observer: ScreenObserver) -> VerificationResult:
        targets = {s for s in (step.success_screen, step.failure_screen) if s}
        try:
            obs = await self._wait_for(observer, targets, "Leer el resultado de la operación")
        except (StepFailed, DeviceError, VisionUnavailable, BudgetExceeded, ObservationError) as exc:
            obs = await self._safe_obs(observer)
            ev = self._capture(job, obs, "resultado_incierto")
            return VerificationResult(outcome="uncertain", method="timeout",
                                      details={"error": str(exc), "screen": obs.screen_id if obs else None},
                                      evidence_ids=ev)
        ev = self._capture(job, obs, "resultado")
        if obs.screen_id == step.failure_screen:
            return VerificationResult(outcome="failure" if step.failure_is_definitive else "uncertain",
                                      method=obs.method, details={"screen": obs.screen_id}, evidence_ids=ev)
        fields = list(step.checks) + ([step.reference_field] if step.reference_field else [])
        values, method = await observer.read_fields(fields, obs, "Leer datos del resultado")
        expected = {k: render(v, ctx) for k, v in step.checks.items()}
        mismatches = {k: {"esperado": v, "en_pantalla": values.get(k)} for k, v in expected.items()
                      if (values.get(k) or "").strip() != v}
        details: dict[str, Any] = {"screen": obs.screen_id, "fields": values, "screen_method": obs.method}
        if step.reference_field:
            details["reference"] = values.get(step.reference_field)
        if mismatches:
            details["mismatches"] = mismatches
            return VerificationResult(outcome="uncertain", method=method, details=details, evidence_ids=ev)
        if self.cfg.verify_result_with_ai and self.vision is not None and obs.method != "ai":
            # Verificación independiente con IA de la misma captura.
            try:
                analysis = await observer._ask_ai(obs, "Confirmar de forma independiente el resultado", list(step.checks))
                agrees = analysis.screen_id == step.success_screen and all(
                    (analysis.field(k) or "").strip() == v for k, v in expected.items())
                details["ai_confirmation"] = agrees
                if not agrees:
                    return VerificationResult(outcome="uncertain", method=f"{method}+ai", details=details, evidence_ids=ev)
            except (VisionUnavailable, BudgetExceeded) as exc:
                details["ai_confirmation_error"] = str(exc)
                return VerificationResult(outcome="uncertain", method=method, details=details, evidence_ids=ev)
        return VerificationResult(outcome="success", method=method, details=details, evidence_ids=ev)

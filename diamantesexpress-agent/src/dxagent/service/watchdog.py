"""Watchdog: supervisión continua de bucles internos, conectividad y emulador."""

from __future__ import annotations

import asyncio
import logging
import time
from typing import TYPE_CHECKING

from dxagent.models import AgentState, iso_now

if TYPE_CHECKING:
    from dxagent.service.agent import AgentService

log = logging.getLogger(__name__)


class Watchdog:
    def __init__(self, agent: "AgentService"):
        self.agent = agent
        self.device_down_since: float | None = None
        self.restarts = 0
        self.last_cleanup = 0.0

    def _stale_limit(self, name: str) -> float | None:
        s = self.agent.settings
        return {"control": 30.0,
                "heartbeat": s.heartbeat_interval_seconds * 3 + 30,
                "sync": s.outbox_sync_interval_seconds * 3 + 60}.get(name)

    def _alert(self, code: str, message: str, severity: str = "warning") -> None:
        bucket = int(time.time() // 900)  # como máximo una alerta igual cada 15 minutos
        self.agent.db.enqueue("alert", f"alert:{code}:{bucket}",
                              {"severity": severity, "code": code, "message": message, "at": iso_now()})

    async def check_once(self) -> None:
        agent = self.agent
        now = time.monotonic()
        # 1) Bucles caídos o colgados -> reinicio del componente.
        for name, task in list(agent._tasks.items()):
            if name == "watchdog":
                continue
            if task.done():
                exc = task.exception() if not task.cancelled() else None
                log.error("Bucle %s terminó inesperadamente: %s", name, exc)
                agent.restart_loop(name)
                continue
            limit = self._stale_limit(name)
            last = agent.health.liveness.get(name)
            if limit and last and now - last > limit:
                log.error("Bucle %s sin actividad durante %.0fs", name, now - last)
                agent.restart_loop(name)

        # 2) Conectividad con DiamantesExpress.
        if not agent.health.api_online:
            self._alert("API_OFFLINE", f"Sin conexión con la API: {agent.health.api_error}")

        # 3) Emulador: solo se comprueba si no hay un trabajo en curso.
        if agent.current_job is None and agent.state not in (AgentState.EMERGENCY_STOP,):
            if agent.bluestacks is not None:
                agent.health.emulator_running = agent.bluestacks.is_running()
            device = await agent.ensure_device()
            if device is None:
                self.device_down_since = self.device_down_since or now
                down_for = now - self.device_down_since
                if down_for > agent.settings.freeze_threshold_seconds:
                    await self._recover_emulator(down_for)
            else:
                self.device_down_since = None

        # 4) Limpieza periódica de evidencias antiguas.
        if now - self.last_cleanup > 6 * 3600:
            self.last_cleanup = now
            agent.evidence.cleanup(agent.settings.evidence_retention_days)

    async def _recover_emulator(self, down_for: float) -> None:
        agent = self.agent
        self._alert("EMULATOR_UNRESPONSIVE", f"El emulador no responde desde hace {down_for:.0f}s")
        if agent.bluestacks is None or not agent.settings.allow_emulator_restart:
            return
        if not agent.recovery.restart_is_safe():
            self._alert("RESTART_BLOCKED", "Reinicio del emulador bloqueado: operación con resultado incierto",
                        "critical")
            return
        try:
            if not agent.bluestacks.is_running():
                agent.bluestacks.launch()
            else:
                await agent.bluestacks.restart(agent.recovery.restart_is_safe,
                                               agent.settings.emulator_boot_timeout_seconds)
            self.restarts += 1
            self.device_down_since = None
            agent.db.add_event("WARNING", "watchdog", "Emulador reiniciado de forma controlada")
        except Exception as exc:
            log.error("No se pudo recuperar el emulador: %s", exc)

    async def loop(self) -> None:
        while not self.agent.stopping.is_set():
            try:
                await self.check_once()
            except Exception:
                log.exception("Error en el watchdog")
            await asyncio.sleep(self.agent.settings.watchdog_interval_seconds)

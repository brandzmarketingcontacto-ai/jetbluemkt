"""Las 16 pruebas exigidas en la especificación (sección 13), contra el contrato real de Horizons.
Ninguna realiza envíos reales: backend simulado, Android simulado y cliente OpenAI falso."""

from __future__ import annotations

import asyncio
import time
from datetime import datetime, timedelta, timezone

import httpx
import openai
import pytest

from conftest import TOKEN, make_api, make_settings
from dxagent import __version__
from dxagent.ai.pricing import PricingTable
from dxagent.ai.usage import UsageTracker
from dxagent.ai.vision import OpenAIVisionAgent
from dxagent.android.bluestacks import BlueStacksManager
from dxagent.android.device import DeviceError
from dxagent.models import AgentState, JobStatus


def vision_for(device, db, fake_openai_factory, settings=None, **kwargs):
    settings = settings or make_settings()
    tracker = UsageTracker(db, PricingTable.load(), "PC-PRUEBAS", settings.max_ai_cost_per_order_usd,
                           settings.daily_ai_budget_usd, settings.max_ai_requests_per_order,
                           settings.ai_reserve_per_request_usd)
    client = fake_openai_factory(device, **kwargs)
    return OpenAIVisionAgent(client, "gpt-4.1-mini", tracker), client


# 1 -----------------------------------------------------------------------------
async def test_01_start_without_internet(agent_factory, backend, db):
    backend.down = True
    backend.add_task(task_id="task_1")
    agent = agent_factory()
    await agent.startup()  # no debe lanzar excepciones
    assert agent.health.api_online is False
    assert await agent.work_once() is False
    assert agent.state == AgentState.DEGRADED
    assert db.get_job("task_1") is None
    backend.down = False
    assert await agent.work_once() is True
    assert db.get_job("task_1")["status"] == JobStatus.COMPLETED.value


# 2 -----------------------------------------------------------------------------
async def test_02_api_unavailable_5xx_backoff_and_recovery(agent_factory, backend, db):
    backend.add_task(task_id="task_2")
    agent = agent_factory()
    await agent.startup()
    await agent.work_once()
    backend.fail_statuses = [503]
    assert await agent.sync.flush() == 0
    first = db.outbox_items()[0]
    assert first["attempts"] == 1 and first["next_attempt_at"] > time.time()
    # El backoff se respeta y el orden FIFO también: nada se adelanta.
    assert await agent.sync.flush() == 0
    agent.sync.clock = lambda: time.time() + 3600
    assert await agent.sync.flush_all() > 0
    assert backend.results["task_2"]["outcome"] == "completed"


# 3 -----------------------------------------------------------------------------
async def test_03_invalid_or_revoked_token(agent_factory, backend, db):
    backend.add_task(task_id="task_3")
    agent = agent_factory(token="dxa_token_invalido_000")
    await agent.startup()
    assert agent.state == AgentState.AUTH_ERROR
    assert await agent.work_once() is False
    assert db.get_job("task_3") is None
    assert backend.tasks["task_3"]["status"] == "pending"
    # Revocación en caliente de un token que era válido.
    agent2 = agent_factory()
    await agent2.startup()
    assert agent2.state == AgentState.RUNNING
    backend.revoke(TOKEN)
    await agent2.send_heartbeat()
    assert agent2.state == AgentState.AUTH_ERROR


# 4 -----------------------------------------------------------------------------
async def test_04_bluestacks_closed_is_launched(tmp_path):
    install = tmp_path / "BlueStacks_nxt"
    install.mkdir()
    (install / "HD-Player.exe").write_bytes(b"")
    (install / "HD-Adb.exe").write_bytes(b"")
    conf = tmp_path / "bluestacks.conf"
    conf.write_text('bst.enable_adb_access="1"\n'
                    'bst.instance.Pie64.display_name="BlueStacks App Player"\n'
                    'bst.instance.Pie64.status.adb_port="5555"\n'
                    'bst.instance.Pie64_1.display_name="Pruebas"\n'
                    'bst.instance.Pie64_1.status.adb_port="5565"\n', encoding="utf-8")
    launched: list[list[str]] = []
    manager = BlueStacksManager(str(install), str(conf), instance="Pie64_1", process_lister=lambda: [],
                                launcher=launched.append, registry=lambda: {})
    status = manager.status()
    assert status["installed"] and status["adb_enabled"] is True and status["running"] is False
    assert status["instances"]["Pie64_1"]["adb_port"] == 5565
    assert manager.instance_info().adb_port == 5565
    manager.launch()
    assert launched == [[str(install / "HD-Player.exe"), "--instance", "Pie64_1"]]
    missing = BlueStacksManager(str(tmp_path / "no"), "", process_lister=lambda: [], registry=lambda: {})
    if not missing.install.found:
        with pytest.raises(DeviceError):
            missing.launch()


# 5 -----------------------------------------------------------------------------
async def test_05_adb_disconnected(agent_factory, backend, device, db):
    backend.add_task(task_id="task_5")
    device.disconnected = True
    agent = agent_factory()
    await agent.startup()
    assert await agent.work_once() is False
    assert agent.state == AgentState.DEGRADED
    assert backend.tasks["task_5"]["status"] == "pending"  # no se reclama sin entorno disponible
    # Desconexión a mitad del flujo (antes del envío): se abandona sin entregar ni reportar resultado.
    device.disconnected = False
    original = device.input_text

    async def drop(text):
        device.disconnected = True
        await original(text)
    device.input_text = drop
    assert await agent.work_once() is True
    assert db.get_job("task_5")["status"] == JobStatus.RELEASED.value
    assert device.transactions == []
    await agent.sync.flush_all()
    assert "task_5" not in backend.results
    # Al vencer el lease, Horizons la vuelve a poner en pending (reintento automático).
    backend.clock = lambda: datetime.now(timezone.utc) + timedelta(seconds=backend.lease_seconds + 1)
    backend.expire_leases()
    assert backend.tasks["task_5"]["status"] == "pending"


# 6 -----------------------------------------------------------------------------
class FakeBlueStacks:
    name, display_name = "bluestacks", "BlueStacks 5"

    def __init__(self):
        self.restarts = 0
        self.running = True

    def is_running(self):
        return self.running

    def launch(self):
        self.running = True

    def stop(self):
        self.running = False

    async def restart(self, is_safe, boot_timeout=0):
        if not is_safe():
            raise DeviceError("bloqueado")
        self.restarts += 1


async def test_06_android_frozen_restarts_only_when_safe(agent_factory, backend, device, db):
    device.frozen = True
    bs = FakeBlueStacks()
    agent = agent_factory(emulator=bs)
    await agent.startup()
    assert await agent.work_once() is False
    assert agent.state == AgentState.DEGRADED
    await agent.watchdog.check_once()  # marca el inicio del congelamiento
    # Simula que pasó el umbral (el reloj monotónico de Windows avanza en saltos de ~15 ms).
    agent.watchdog.device_down_since = time.monotonic() - 1000
    await agent.watchdog.check_once()
    assert bs.restarts >= 1
    # Con una operación incierta registrada, el reinicio queda bloqueado.
    db.insert_job("task_doubt", "DX-D", {}, "default", "default")
    db.update_job("task_doubt", status=JobStatus.COMMITTING)
    before = bs.restarts
    agent.watchdog.device_down_since = time.monotonic() - 1000
    await agent.watchdog.check_once()
    assert bs.restarts == before
    assert any("RESTART_BLOCKED" in a["message"] for a in db.alerts())


# 7 -----------------------------------------------------------------------------
async def test_07_test_app_closed_is_launched(agent_factory, backend, device, db):
    device.close_app()
    assert device.foreground != "com.diamantesexpress.giftsim"
    backend.add_task(task_id="task_7")
    agent = agent_factory()
    await agent.startup()
    await agent.work_once()
    assert db.get_job("task_7")["status"] == JobStatus.COMPLETED.value
    # Una app no instalada no se abre y la tarea se abandona sin ejecutar.
    device.installed.clear()
    device.close_app()
    backend.add_task(task_id="task_7b")
    await agent.work_once()
    assert db.get_job("task_7b")["status"] == JobStatus.RELEASED.value


# 8 -----------------------------------------------------------------------------
async def test_08_unreadable_screenshot(agent_factory, backend, device, db, fake_openai_factory):
    device.unreadable = True
    vision, client = vision_for(device, db, fake_openai_factory)
    backend.add_task(task_id="task_8")
    agent = agent_factory(vision=vision)
    await agent.startup()
    await agent.work_once()
    row = db.get_job("task_8")
    assert row["status"] == JobStatus.RELEASED.value
    assert row["error_code"] == "SCREEN_UNRECOGNIZED"
    assert client.responses.calls == []  # no se gastan tokens en una captura en blanco
    assert device.transactions == []
    assert any("SCREEN_UNRECOGNIZED" in a["message"] for a in db.alerts())


# 9 -----------------------------------------------------------------------------
async def test_09_openai_unavailable(agent_factory, backend, device, db, fake_openai_factory):
    device.hierarchy_available = False  # como un juego: requiere IA
    error = openai.APIConnectionError(request=httpx.Request("POST", "https://api.openai.com/v1/responses"))
    vision, client = vision_for(device, db, fake_openai_factory, error=error)
    backend.add_task(task_id="task_9")
    agent = agent_factory(vision=vision)
    await agent.startup()
    await agent.work_once()
    assert db.get_job("task_9")["status"] == JobStatus.RELEASED.value
    assert device.transactions == []
    usage = db.recent_usage()
    assert usage and usage[0]["status"] == "error" and usage[0]["total_tokens"] == 0


async def test_09b_ai_path_completes_and_reports_usage(agent_factory, backend, device, db, fake_openai_factory):
    device.hierarchy_available = False
    settings = make_settings(max_ai_requests_per_order=30, max_ai_cost_per_order_usd=1.0)
    vision, client = vision_for(device, db, fake_openai_factory, settings=settings)
    backend.add_task(task_id="task_ai")
    agent = agent_factory(vision=vision, settings=settings)
    await agent.startup()
    await agent.work_once()
    row = db.get_job("task_ai")
    assert row["status"] == JobStatus.COMPLETED.value, row["message"]
    usage = db.recent_usage()
    n = len(client.responses.calls)
    assert len(usage) == n > 0
    first = usage[-1]
    assert (first["input_tokens"], first["cached_input_tokens"], first["output_tokens"]) == (1200, 200, 150)
    assert first["request_id"].startswith("req_") and first["response_id"].startswith("resp_")
    assert first["cost_usd"] == pytest.approx(0.00066)  # (1000*0.40 + 200*0.10 + 150*1.60) / 1e6
    await agent.sync.flush_all()
    reported = backend.results["task_ai"]["usage"]
    assert reported == {"model": "gpt-4.1-mini-2025-04-14", "prompt_tokens": 1200 * n, "completion_tokens": 150 * n,
                        "cached_tokens": 200 * n, "cost_usd": pytest.approx(0.00066 * n)}
    assert backend.tasks["task_ai"]["usage"]["costUsd"] == pytest.approx(0.00066 * n)
    assert all(u["synced"] == 1 for u in db.recent_usage())


# 10 ----------------------------------------------------------------------------
async def test_10_budget_exhausted(agent_factory, backend, device, db, fake_openai_factory):
    device.hierarchy_available = False
    settings = make_settings(daily_ai_budget_usd=0.0)
    vision, client = vision_for(device, db, fake_openai_factory, settings=settings)
    backend.add_task(task_id="task_10")
    agent = agent_factory(vision=vision, settings=settings)
    await agent.startup()
    assert agent.tracker.daily_budget_exhausted()
    assert await agent.work_once() is True
    assert db.get_job("task_10")["error_code"] == "DAILY_BUDGET"
    assert client.responses.calls == []
    task = asyncio.create_task(agent.work_loop())
    await asyncio.sleep(0.05)
    agent.stopping.set()
    await asyncio.wait_for(task, 6)
    assert agent.state == AgentState.BUDGET_EXHAUSTED
    # Límite de gasto del servidor (claim con reason=spend_limit_reached).
    agent2 = agent_factory()
    await agent2.startup()
    backend.spend_limit_reached = True
    assert await agent2.work_once() is False
    assert agent2.state == AgentState.BUDGET_EXHAUSTED


# 11 ----------------------------------------------------------------------------
async def test_11_duplicate_task_and_order(agent_factory, backend, device, db):
    backend.add_task(task_id="task_11", order_code="DX-11")
    agent = agent_factory()
    await agent.startup()
    await agent.work_once()
    assert len(device.transactions) == 1
    # Se pierde la respuesta del resultado y el lease vence: Horizons vuelve a entregar la MISMA tarea.
    backend.tasks["task_11"].update(status="pending", owner=None, result=None)
    db.outbox_sent(db.outbox_items("result")[0]["id"], "pending")
    assert await agent.work_once() is False
    assert len(device.transactions) == 1  # nunca se repite la entrega
    await agent.sync.flush_all()
    assert backend.result_posts >= 1
    # Otra tarea para el mismo pedido: no se ejecuta; pasa a revisión manual.
    backend.add_task(task_id="task_11b", order_code="DX-11")
    assert await agent.work_once() is False
    assert len(device.transactions) == 1
    assert db.get_job("task_11b")["error_code"] == "DUPLICATE_ORDER"
    await agent.sync.flush_all()
    assert backend.results["task_11b"]["outcome"] == "uncertain"
    assert backend.tasks["task_11b"]["status"] == "manual_review"
    # Resultado repetido: el servidor responde duplicate y no cambia nada.
    api = make_api(backend)
    second = await api.report_result("task_11", {"outcome": "completed"})
    assert second["duplicate"] is True


# 12 ----------------------------------------------------------------------------
async def test_12_restart_during_task(db, backend, device, tmp_path, agent_factory):
    # Caso A: el proceso murió justo después de pulsar "Confirmar".
    backend.add_task(task_id="task_12")
    agent = agent_factory()
    await agent.startup()
    original_tap = device.tap

    async def tap_then_die(x, y):
        await original_tap(x, y)
        if device.screen == "result_success":
            raise SystemExit("cierre inesperado simulado")
    device.tap = tap_then_die
    with pytest.raises(SystemExit):
        await agent.work_once()
    assert db.get_job("task_12")["status"] == JobStatus.COMMITTING.value
    device.tap = original_tap
    agent2 = agent_factory()
    await agent2.startup()
    row = db.get_job("task_12")
    assert row["status"] == JobStatus.NEEDS_REVIEW.value
    assert row["error_code"] == "RESTART_DURING_COMMIT"
    assert len(device.transactions) == 1  # nunca se repitió
    await agent2.sync.flush_all()
    assert backend.results["task_12"]["outcome"] == "uncertain"
    assert backend.tasks["task_12"]["status"] == "manual_review"
    # Caso B: reinicio antes del paso transaccional -> se abandona localmente (lease).
    db.insert_job("task_12b", "DX-12B", {}, "default", "default")
    db.update_job("task_12b", status=JobStatus.RUNNING)
    agent3 = agent_factory()
    await agent3.startup()
    assert db.get_job("task_12b")["status"] == JobStatus.RELEASED.value
    assert db.locks() == []
    # Caso C: base local perdida; el servidor dice que la tarea es nuestra -> uncertain.
    backend.add_task(task_id="task_12c")
    await make_api(backend).claim_task()
    agent4 = agent_factory()
    agent4.db.update_job("task_12c", status=JobStatus.RUNNING)  # no existe: sin efecto
    await agent4.startup()
    await agent4.sync.flush_all()
    assert backend.results["task_12c"]["outcome"] == "uncertain"
    assert backend.results["task_12c"]["errorCode"] == "UNKNOWN_LOCAL_STATE"


# 13 ----------------------------------------------------------------------------
@pytest.mark.parametrize("outcome,code", [("uncertain", "RESULT_UNCERTAIN"), ("crash", None)])
async def test_13_uncertain_result(agent_factory, backend, device, db, outcome, code):
    device.commit_outcome = outcome
    backend.add_task(task_id="task_13")
    agent = agent_factory()
    await agent.startup()
    await agent.work_once()
    row = db.get_job("task_13")
    assert row["status"] == JobStatus.NEEDS_REVIEW.value
    if code:
        assert row["error_code"] == code
    assert len(device.transactions) == 1
    await agent.sync.flush_all()
    assert backend.results["task_13"]["outcome"] == "uncertain"  # nunca completed
    assert backend.results["task_13"]["evidence"]["preCommit"] is False
    assert backend.tasks["task_13"]["status"] == "manual_review"


async def test_13b_definitive_failure(agent_factory, backend, device, db):
    device.commit_outcome = "failure"
    backend.add_task(task_id="task_13f")
    agent = agent_factory()
    await agent.startup()
    await agent.work_once()
    row = db.get_job("task_13f")
    assert row["status"] == JobStatus.FAILED.value and row["error_code"] == "DELIVERY_REJECTED"


# 14 ----------------------------------------------------------------------------
async def test_14_repeated_heartbeat(agent_factory, backend):
    agent = agent_factory()
    await agent.startup()
    for _ in range(10):
        await agent.send_heartbeat()
    assert backend.heartbeat_count == 11
    assert list(backend.heartbeats) == ["agente-1"]  # un único estado por agente
    assert backend.heartbeats["agente-1"] == {"hostname": "PC-PRUEBAS", "appVersion": __version__}
    assert agent.health.api_online is True


# 15 ----------------------------------------------------------------------------
async def test_15_late_sync_of_results_and_usage(agent_factory, backend, device, db, fake_openai_factory):
    device.hierarchy_available = False
    settings = make_settings(max_ai_requests_per_order=30, max_ai_cost_per_order_usd=1.0)
    vision, client = vision_for(device, db, fake_openai_factory, settings=settings)
    backend.add_task(task_id="task_15")
    agent = agent_factory(vision=vision, settings=settings)
    await agent.startup()
    original = agent._job_still_assigned

    async def go_offline_after_check(task_id):
        ok = await original(task_id)
        backend.down = True  # se pierde Internet justo antes de pulsar "Confirmar"
        return ok
    agent._job_still_assigned = go_offline_after_check
    await agent.work_once()
    assert db.get_job("task_15")["status"] == JobStatus.COMPLETED.value
    assert "task_15" not in backend.results
    assert all(u["synced"] == 0 for u in db.recent_usage())
    assert await agent.sync.flush() == 0
    backend.down = False
    agent.sync.clock = lambda: time.time() + 3600
    await agent.sync.flush_all()
    result = backend.results["task_15"]
    assert result["outcome"] == "completed"
    assert result["usage"]["prompt_tokens"] == 1200 * len(client.responses.calls)
    assert all(u["synced"] == 1 for u in db.recent_usage())
    # Reenvío del mismo resultado (respuesta perdida): idempotente.
    again = await make_api(backend).report_result("task_15", result)
    assert again["duplicate"] is True


# 16 ----------------------------------------------------------------------------
async def test_16_hundred_consecutive_tasks(agent_factory, backend, device, db):
    for i in range(100):
        backend.add_task(task_id=f"task_{i:03d}", order_code=f"DX-{i:03d}", player_id=f"{100000000 + i}")
    agent = agent_factory()
    await agent.startup()
    processed = 0
    while await agent.work_once():
        processed += 1
    await agent.sync.flush_all()
    assert processed == 100
    statuses = [db.get_job(f"task_{i:03d}")["status"] for i in range(100)]
    assert statuses.count(JobStatus.COMPLETED.value) == 100
    assert len(device.transactions) == 100
    assert len({t.player_id for t in device.transactions}) == 100
    assert len(backend.results) == 100
    assert all(t["status"] == "completed" for t in backend.tasks.values())
    assert db.outbox_pending_count() == 0
    assert db.locks() == []
    assert db.job_stats_today()["completed"] == 100

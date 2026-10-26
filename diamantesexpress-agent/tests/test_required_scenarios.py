"""Las 16 pruebas exigidas en la especificación (sección 13). Ninguna realiza envíos reales."""

from __future__ import annotations

import time

import httpx
import openai
import pytest

from conftest import TOKEN, make_api, make_settings
from dxagent.ai.pricing import PricingTable
from dxagent.ai.usage import UsageTracker
from dxagent.ai.vision import OpenAIVisionAgent
from dxagent.android.bluestacks import BlueStacksManager
from dxagent.android.device import DeviceError
from dxagent.models import AgentState, JobStatus


def vision_for(device, db, fake_openai_factory, settings=None, **kwargs):
    settings = settings or make_settings()
    tracker = UsageTracker(db, PricingTable.load(), "agent-test", settings.max_ai_cost_per_order_usd,
                           settings.daily_ai_budget_usd, settings.max_ai_requests_per_order,
                           settings.ai_reserve_per_request_usd)
    client = fake_openai_factory(device, **kwargs)
    return OpenAIVisionAgent(client, "gpt-4.1-mini", tracker), client


# 1 -----------------------------------------------------------------------------
async def test_01_start_without_internet(agent_factory, backend, db):
    backend.down = True
    backend.add_job(job_id="job-1")
    agent = agent_factory()
    await agent.startup()  # no debe lanzar excepciones
    assert agent.health.api_online is False
    assert await agent.work_once() is False
    assert agent.state == AgentState.DEGRADED
    await agent.send_heartbeat()
    assert agent.health.api_online is False
    assert db.get_job("job-1") is None
    backend.down = False
    assert await agent.work_once() is True
    assert db.get_job("job-1")["status"] == JobStatus.COMPLETED.value


# 2 -----------------------------------------------------------------------------
async def test_02_api_unavailable_5xx_backoff_and_recovery(agent_factory, backend, db):
    backend.add_job(job_id="job-2")
    agent = agent_factory()
    await agent.startup()
    await agent.work_once()
    backend.fail_statuses = [503]
    sent = await agent.sync.flush()
    assert sent == 0
    pending = db.outbox_items()
    assert any(i["attempts"] == 1 and i["next_attempt_at"] > time.time() for i in pending)
    # El backoff se respeta: aún no toca reintentar.
    assert await agent.sync.flush() == 0
    agent.sync.clock = lambda: time.time() + 3600
    assert await agent.sync.flush() > 0
    assert backend.completions["job-2"]["status"] == "completed"


# 3 -----------------------------------------------------------------------------
async def test_03_invalid_token(agent_factory, backend, db):
    backend.add_job(job_id="job-3")
    agent = agent_factory(token="token-invalido-000")
    await agent.startup()
    assert agent.state == AgentState.AUTH_ERROR
    assert await agent.work_once() is False
    assert agent.state == AgentState.AUTH_ERROR
    assert db.get_job("job-3") is None
    # Revocación en caliente de un token que era válido.
    agent2 = agent_factory()
    await agent2.startup()
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
    # Sin instalación: error claro en lugar de suposiciones.
    missing = BlueStacksManager(str(tmp_path / "no"), "", process_lister=lambda: [], registry=lambda: {})
    if not missing.install.found:
        with pytest.raises(DeviceError):
            missing.launch()


# 5 -----------------------------------------------------------------------------
async def test_05_adb_disconnected(agent_factory, backend, device, db):
    backend.add_job(job_id="job-5")
    device.disconnected = True
    agent = agent_factory()
    await agent.startup()
    assert await agent.work_once() is False
    assert agent.state == AgentState.DEGRADED
    assert db.get_job("job-5") is None  # no se reclama sin entorno disponible
    # Desconexión a mitad del flujo (antes del envío): se libera sin entregar.
    device.disconnected = False
    original = device.input_text

    async def drop(text):
        device.disconnected = True
        await original(text)
    device.input_text = drop
    assert await agent.work_once() is True
    row = db.get_job("job-5")
    assert row["status"] == JobStatus.RELEASED.value
    assert device.transactions == []


# 6 -----------------------------------------------------------------------------
class FakeBlueStacks:
    def __init__(self):
        self.restarts = 0
        self.running = True

    def is_running(self):
        return self.running

    def launch(self):
        self.running = True

    async def restart(self, is_safe, boot_timeout=0):
        if not is_safe():
            raise DeviceError("bloqueado")
        self.restarts += 1


async def test_06_android_frozen_restarts_only_when_safe(agent_factory, backend, device, db):
    device.frozen = True
    bs = FakeBlueStacks()
    agent = agent_factory(bluestacks=bs)
    await agent.startup()
    assert await agent.work_once() is False
    assert agent.state == AgentState.DEGRADED
    await agent.watchdog.check_once()  # marca el inicio del congelamiento
    # Simula que pasó el umbral (el reloj monotónico de Windows avanza en saltos de ~15 ms).
    agent.watchdog.device_down_since = time.monotonic() - 1000
    await agent.watchdog.check_once()
    assert bs.restarts >= 1
    # Con una operación incierta registrada, el reinicio queda bloqueado.
    db.insert_job("job-doubt", "DX-D", {}, "default", "default")
    db.update_job("job-doubt", status=JobStatus.COMMITTING)
    before = bs.restarts
    agent.watchdog.device_down_since = time.monotonic() - 1000
    await agent.watchdog.check_once()
    assert bs.restarts == before
    assert any("RESTART_BLOCKED" in i["idem_key"] for i in db.outbox_items("alert"))


# 7 -----------------------------------------------------------------------------
async def test_07_test_app_closed_is_launched(agent_factory, backend, device, db):
    device.close_app()
    assert device.foreground != "com.diamantesexpress.giftsim"
    backend.add_job(job_id="job-7")
    agent = agent_factory()
    await agent.startup()
    await agent.work_once()
    assert db.get_job("job-7")["status"] == JobStatus.COMPLETED.value
    # Una app no instalada no se abre y el trabajo se devuelve.
    device.installed.clear()
    device.close_app()
    backend.add_job(job_id="job-7b")
    await agent.work_once()
    assert db.get_job("job-7b")["status"] == JobStatus.RELEASED.value


# 8 -----------------------------------------------------------------------------
async def test_08_unreadable_screenshot(agent_factory, backend, device, db, fake_openai_factory):
    device.unreadable = True
    vision, client = vision_for(device, db, fake_openai_factory)
    backend.add_job(job_id="job-8")
    agent = agent_factory(vision=vision)
    await agent.startup()
    await agent.work_once()
    row = db.get_job("job-8")
    assert row["status"] == JobStatus.RELEASED.value
    assert row["error_code"] == "SCREEN_UNRECOGNIZED"
    assert client.responses.calls == []  # no se gastan tokens en una captura en blanco
    assert device.transactions == []


# 9 -----------------------------------------------------------------------------
async def test_09_openai_unavailable(agent_factory, backend, device, db, fake_openai_factory):
    device.hierarchy_available = False  # como un juego: requiere IA
    error = openai.APIConnectionError(request=httpx.Request("POST", "https://api.openai.com/v1/responses"))
    vision, client = vision_for(device, db, fake_openai_factory, error=error)
    backend.add_job(job_id="job-9")
    agent = agent_factory(vision=vision)
    await agent.startup()
    await agent.work_once()
    row = db.get_job("job-9")
    assert row["status"] == JobStatus.RELEASED.value
    assert device.transactions == []
    usage = db.recent_usage()
    assert usage and usage[0]["status"] == "error" and usage[0]["total_tokens"] == 0


async def test_09b_ai_path_completes_and_records_real_usage(agent_factory, backend, device, db, fake_openai_factory):
    device.hierarchy_available = False
    vision, client = vision_for(device, db, fake_openai_factory, settings=make_settings(max_ai_requests_per_order=30,
                                                                                         max_ai_cost_per_order_usd=1.0))
    backend.add_job(job_id="job-ai")
    agent = agent_factory(vision=vision, settings=make_settings(max_ai_requests_per_order=30))
    await agent.startup()
    await agent.work_once()
    row = db.get_job("job-ai")
    assert row["status"] == JobStatus.COMPLETED.value, row["message"]
    usage = db.recent_usage()
    assert len(usage) == len(client.responses.calls) > 0
    first = usage[-1]
    assert (first["input_tokens"], first["cached_input_tokens"], first["output_tokens"]) == (1200, 200, 150)
    assert first["request_id"].startswith("req_") and first["response_id"].startswith("resp_")
    # (1000*0.40 + 200*0.10 + 150*1.60) / 1e6
    assert first["cost_usd"] == pytest.approx(0.00066)
    assert row["tokens_total"] == sum(u["total_tokens"] for u in usage)
    await agent.sync.flush()
    assert len(backend.usage_records) == len(usage)


# 10 ----------------------------------------------------------------------------
async def test_10_budget_exhausted(agent_factory, backend, device, db, fake_openai_factory):
    device.hierarchy_available = False
    settings = make_settings(daily_ai_budget_usd=0.0)
    vision, client = vision_for(device, db, fake_openai_factory, settings=settings)
    backend.add_job(job_id="job-10")
    agent = agent_factory(vision=vision, settings=settings)
    await agent.startup()
    assert agent.tracker.daily_budget_exhausted()
    processed = await agent.work_once()
    assert processed is True
    assert db.get_job("job-10")["status"] == JobStatus.RELEASED.value
    assert db.get_job("job-10")["error_code"] == "DAILY_BUDGET"
    assert client.responses.calls == []
    # El bucle de trabajo deja de tomar pedidos.
    agent.stopping.clear()
    import asyncio
    task = asyncio.create_task(agent.work_loop())
    await asyncio.sleep(0.05)
    agent.stopping.set()
    await asyncio.wait_for(task, 6)
    assert agent.state == AgentState.BUDGET_EXHAUSTED


# 11 ----------------------------------------------------------------------------
async def test_11_duplicate_order(agent_factory, backend, device, db):
    backend.add_job(job_id="job-11", order_id="DX-11")
    agent = agent_factory()
    await agent.startup()
    await agent.work_once()
    assert len(device.transactions) == 1
    # El backend vuelve a listar el mismo trabajo (p. ej. por un error): no se procesa otra vez.
    backend.jobs["job-11"]["status"] = "pending"
    backend.jobs["job-11"]["claimed_by"] = None
    assert await agent.work_once() is False
    # Otro trabajo para el mismo pedido: se ignora y se alerta.
    backend.add_job(job_id="job-11b", order_id="DX-11")
    assert await agent.work_once() is False
    assert len(device.transactions) == 1
    assert any("dup_order" in i["idem_key"] for i in db.outbox_items("alert"))
    # Reclamación concurrente: otro agente gana (409).
    backend.add_job(job_id="job-11c", order_id="DX-11C")
    backend.jobs["job-11c"]["status"] = "pending"
    original = backend._op_claim

    def stolen(agent_id, p, q, job_id):
        backend.jobs[job_id].update(status="claimed", claimed_by="otro-agente")
        return 409, {"error": "ya reclamado"}
    backend._op_claim = stolen
    assert await agent.work_once() is False
    backend._op_claim = original
    assert db.get_job("job-11c") is None


# 12 ----------------------------------------------------------------------------
async def test_12_restart_during_task(db, backend, device, tmp_path, agent_factory):
    # Caso A: el proceso murió justo después de pulsar "Confirmar".
    backend.add_job(job_id="job-12")
    agent = agent_factory()
    await agent.startup()
    original_tap = device.tap
    taps_after_commit = []

    async def tap_then_die(x, y):
        await original_tap(x, y)
        if device.screen == "result_success":
            taps_after_commit.append((x, y))
            raise SystemExit("cierre inesperado simulado")
    device.tap = tap_then_die
    with pytest.raises(SystemExit):
        await agent.work_once()
    assert db.get_job("job-12")["status"] == JobStatus.COMMITTING.value
    device.tap = original_tap
    # Nuevo arranque del agente.
    agent2 = agent_factory()
    await agent2.startup()
    row = db.get_job("job-12")
    assert row["status"] == JobStatus.NEEDS_REVIEW.value
    assert row["error_code"] == "RESTART_DURING_COMMIT"
    assert len(device.transactions) == 1  # nunca se repitió
    await agent2.sync.flush()
    assert backend.completions["job-12"]["status"] == "needs_review"
    # Caso B: reinicio antes del paso transaccional -> se libera el trabajo.
    db.insert_job("job-12b", "DX-12B", {}, "default", "default")
    db.update_job("job-12b", status=JobStatus.RUNNING)
    agent3 = agent_factory()
    await agent3.startup()
    assert db.get_job("job-12b")["status"] == JobStatus.RELEASED.value
    assert db.locks() == []


# 13 ----------------------------------------------------------------------------
@pytest.mark.parametrize("outcome,code", [("uncertain", "RESULT_UNCERTAIN"), ("crash", None)])
async def test_13_uncertain_result(agent_factory, backend, device, db, outcome, code):
    device.commit_outcome = outcome
    backend.add_job(job_id="job-13")
    agent = agent_factory()
    await agent.startup()
    await agent.work_once()
    row = db.get_job("job-13")
    assert row["status"] == JobStatus.NEEDS_REVIEW.value
    if code:
        assert row["error_code"] == code
    assert len(device.transactions) == 1
    await agent.sync.flush()
    # Nunca se reporta como completado.
    assert backend.completions["job-13"]["status"] == "needs_review"
    assert backend.completions["job-13"]["pre_commit"] is False


async def test_13b_definitive_failure(agent_factory, backend, device, db):
    device.commit_outcome = "failure"
    backend.add_job(job_id="job-13f")
    agent = agent_factory()
    await agent.startup()
    await agent.work_once()
    row = db.get_job("job-13f")
    assert row["status"] == JobStatus.FAILED.value and row["error_code"] == "DELIVERY_REJECTED"


# 14 ----------------------------------------------------------------------------
async def test_14_repeated_heartbeat(agent_factory, backend):
    agent = agent_factory()
    await agent.startup()
    for _ in range(10):
        await agent.send_heartbeat()
    assert backend.heartbeat_count == 10
    assert list(backend.heartbeats) == ["agent-test"]  # un único estado, sin duplicados
    assert agent.health.api_online is True


# 15 ----------------------------------------------------------------------------
async def test_15_late_metrics_sync_is_idempotent(agent_factory, backend, device, db, fake_openai_factory):
    device.hierarchy_available = False
    settings = make_settings(max_ai_requests_per_order=30, max_ai_cost_per_order_usd=1.0)
    vision, client = vision_for(device, db, fake_openai_factory, settings=settings)
    backend.add_job(job_id="job-15")
    agent = agent_factory(vision=vision, settings=settings)
    await agent.startup()
    backend.down = True  # se pierde Internet justo después de reclamar
    original = agent.queue.api.claim_job

    async def claim_then_offline(job_id, instance):
        backend.down = False
        job = await original(job_id, instance)
        backend.down = True
        return job
    agent.queue.api.claim_job = claim_then_offline
    backend.down = False
    await agent.work_once()
    # get_job (antes del envío) falla por estar offline -> se libera sin entregar.
    row = db.get_job("job-15")
    assert row["status"] == JobStatus.RELEASED.value and row["error_code"] == "BACKEND_UNREACHABLE"
    assert device.transactions == []
    local_usage = db.recent_usage()
    assert local_usage and backend.usage_records == {}
    assert await agent.sync.flush() == 0
    backend.down = False
    agent.sync.clock = lambda: time.time() + 3600
    await agent.sync.flush()
    assert set(backend.usage_records) == {u["record_id"] for u in local_usage}
    # Reenvío del mismo lote (respuesta perdida): el backend no duplica.
    records = [backend.usage_records[r] for r in backend.usage_records]
    api = make_api(backend)
    result = await api.report_usage(records, "otra-clave")
    assert result["accepted"] == 0 and result["duplicates"] == len(records)
    assert all(u["synced"] == 1 for u in db.recent_usage())


# 16 ----------------------------------------------------------------------------
async def test_16_hundred_consecutive_orders(agent_factory, backend, device, db):
    products = ["DIAMANTES_100", "DIAMANTES_310", "DIAMANTES_520", "DIAMANTES_1060"]
    for i in range(100):
        backend.add_job(job_id=f"job-{i:03d}", order_id=f"DX-{i:03d}", player_id=f"{100000000 + i}",
                        product_code=products[i % 4])
    agent = agent_factory()
    await agent.startup()
    processed = 0
    while await agent.work_once():
        processed += 1
    await agent.sync.flush_all()
    assert processed == 100
    statuses = [db.get_job(f"job-{i:03d}")["status"] for i in range(100)]
    assert statuses.count(JobStatus.COMPLETED.value) == 100
    assert len(device.transactions) == 100
    assert len({(t.player_id, t.product_code) for t in device.transactions}) == 100
    assert len(backend.completions) == 100
    assert db.outbox_pending_count() == 0
    assert db.locks() == []
    stats = db.job_stats_today()
    assert stats["completed"] == 100

"""Modo SUPERVISADO, PRODUCCIÓN AUTORIZADA, plantilla Free Fire y botón de emergencia."""

import asyncio
import shutil

import pytest
import yaml

from conftest import make_settings
from dxagent import paths
from dxagent.engine.workflow_def import WorkflowNotReady, check_mode_allowed, load_workflow
from dxagent.models import JobStatus
from dxagent.settings import AutomationMode


async def _approve_when_requested(db, approved=True, who="Ana (operadora)"):
    from dxagent.engine.approval import ApprovalManager
    manager = ApprovalManager(db)
    for _ in range(300):
        pending = db.pending_approvals()
        if pending:
            manager.decide(pending[0]["approval_id"], approved, who)
            return pending[0]
        await asyncio.sleep(0.01)
    raise AssertionError("no se pidió aprobación")


async def test_supervised_requires_human_approval_and_records_approver(agent_factory, backend, device, db):
    backend.add_job(job_id="job-s1")
    agent = agent_factory(settings=make_settings(automation_mode=AutomationMode.SUPERVISED))
    await agent.startup()
    approver = asyncio.create_task(_approve_when_requested(db))
    await agent.work_once()
    request = await approver
    row = db.get_job("job-s1")
    assert row["status"] == JobStatus.COMPLETED.value
    assert row["approved_by"] == "Ana (operadora)"
    assert "588141749" in request["summary_json"]
    await agent.sync.flush_all()
    assert backend.results["job-s1"]["evidence"]["approvedBy"] == "Ana (operadora)"
    assert backend.results["job-s1"]["evidence"]["agentMode"] == "SUPERVISED"


async def test_supervised_rejection_never_sends(agent_factory, backend, device, db):
    backend.add_job(job_id="job-s2")
    agent = agent_factory(settings=make_settings(automation_mode=AutomationMode.SUPERVISED))
    await agent.startup()
    approver = asyncio.create_task(_approve_when_requested(db, approved=False))
    await agent.work_once()
    await approver
    row = db.get_job("job-s2")
    assert row["status"] == JobStatus.FAILED.value and row["error_code"] == "APPROVAL_REJECTED"
    assert device.transactions == []


async def test_supervised_approval_timeout_releases(agent_factory, backend, device, db):
    backend.add_job(job_id="job-s3")
    agent = agent_factory(settings=make_settings(automation_mode=AutomationMode.SUPERVISED,
                                                 approval_timeout_seconds=0.2))
    await agent.startup()
    await agent.work_once()
    row = db.get_job("job-s3")
    assert row["status"] == JobStatus.RELEASED.value and row["error_code"] == "APPROVAL_EXPIRED"
    assert device.transactions == []


def test_approval_requires_identity(db):
    from dxagent.engine.approval import ApprovalManager
    db.create_approval("a1", "j", "s", {})
    with pytest.raises(ValueError):
        ApprovalManager(db).decide("a1", True, "  ")


async def test_production_mode_refused_without_authorization(agent_factory):
    agent = agent_factory(settings=make_settings(automation_mode=AutomationMode.AUTHORIZED_PRODUCTION))
    with pytest.raises(WorkflowNotReady):
        await agent.startup()


def test_production_requires_workflow_and_backend_authorization():
    wf = load_workflow("simulation_gift")
    with pytest.raises(WorkflowNotReady):
        check_mode_allowed(wf, "AUTHORIZED_PRODUCTION", True)  # simulación nunca es producción
    real = wf.model_copy(deep=True)
    real.simulation = False
    real.allowed_modes = ["SUPERVISED", "AUTHORIZED_PRODUCTION"]
    with pytest.raises(WorkflowNotReady):
        check_mode_allowed(real, "AUTHORIZED_PRODUCTION", True)  # sin autorización en el flujo
    real.authorization.authorized = True
    real.authorization.reference = "ACUERDO-001"
    real.authorization.validated_at = "2026-10-01"
    with pytest.raises(WorkflowNotReady):
        check_mode_allowed(real, "AUTHORIZED_PRODUCTION", False)  # backend no lo habilitó
    check_mode_allowed(real, "AUTHORIZED_PRODUCTION", True)
    with pytest.raises(WorkflowNotReady):
        check_mode_allowed(real, "SIMULATION", True)  # un flujo real no corre en simulación


@pytest.mark.parametrize("name,phase", [("freefire_gift_supervised", "gift"),
                                        ("freefire_friend_supervised", "friend_request")])
def test_freefire_workflows_are_real_and_need_authorization_for_production(name, phase):
    wf = load_workflow(name, paths.resources_dir() / "workflows")
    assert wf.simulation is False and "SIMULATION" not in wf.allowed_modes and wf.phase == phase
    assert wf.assets_dir is not None and (wf.assets_dir / "lobby_tienda.png").is_file()
    with pytest.raises(WorkflowNotReady):
        check_mode_allowed(wf, "AUTHORIZED_PRODUCTION", True)   # sin autorización validada no hay producción


async def test_local_recognition_identifies_screens_from_reference_images():
    from PIL import Image

    from dxagent.engine.local_vision import open_image
    from dxagent.engine.observer import Observation, ScreenObserver
    wf = load_workflow("freefire_gift_supervised", paths.resources_dir() / "workflows")

    def screen_with(*markers):
        canvas = Image.new("L", (1600, 900), 0)
        for m in markers:
            ref = Image.open(wf.assets_dir / m.image)
            x1, y1, x2, y2 = m.region
            box = (round(x1 * 1600), round(y1 * 900), round(x2 * 1600), round(y2 * 900))
            canvas.paste(ref.resize((box[2] - box[0], box[3] - box[1])), box[:2])
        import io
        buf = io.BytesIO()
        canvas.save(buf, "PNG")
        return Observation(png=buf.getvalue(), xml=None, nodes=[], size=(1600, 900))

    observer = ScreenObserver(None, wf, None, None, "t", "o")
    for screen_id in ("lobby", "store", "gift_store", "recipient_selected", "gift_result_success"):
        obs = screen_with(*wf.screens[screen_id].markers)
        assert await observer._identify_locally(obs) == screen_id
    assert await observer._identify_locally(screen_with()) is None


def test_digit_reader_reads_balance_with_game_font():
    from PIL import Image

    from dxagent.engine.local_vision import DigitReader
    wf = load_workflow("freefire_gift_supervised", paths.resources_dir() / "workflows")
    folder = wf.assets_dir / "saldo"
    canvas = Image.new("L", (200, 40), 0)
    x = 5
    for d in "2918":
        glyph = Image.open(folder / f"{d}.png").resize((14, 22))
        canvas.paste(glyph, (x, 9))
        x += 18
    plus = Image.new("L", (14, 22), 255)
    canvas.paste(plus, (x + 4, 9))
    assert DigitReader(folder, skip_last=1).read(canvas.convert("RGB"), (0, 0, 1, 1)) == "2918"


def test_text_similarity_tolerates_ocr_confusions():
    from dxagent.engine.local_vision import parse_number, text_similarity
    assert text_similarity("i NN-0Ficial", "NN-OFicial") >= 0.8
    assert text_similarity("Token Universal Arma Evo", "Caja de Tokens evo universales") < 0.8
    assert parse_number("150") == 150 and parse_number("") is None


async def test_emergency_stop_before_commit(agent_factory, backend, device, db, tmp_path):
    backend.add_job(job_id="job-e")
    agent = agent_factory()
    await agent.startup()
    original = device.input_text

    async def press_emergency(text):
        await original(text)
        agent.handle_command("emergency_stop", "Operador")
    device.input_text = press_emergency
    await agent.work_once()
    row = db.get_job("job-e")
    assert row["status"] == JobStatus.RELEASED.value and row["error_code"] == "EMERGENCY_STOP"
    assert device.transactions == []
    # No se toman nuevos pedidos mientras la emergencia esté activa.
    agent.handle_command("start", "Operador")
    assert (tmp_path / "EMERGENCY_STOP").exists()
    task = asyncio.create_task(agent.work_loop())
    await asyncio.sleep(0.1)
    agent.stopping.set()
    await task
    assert agent.state.value == "EMERGENCY_STOP"
    agent.handle_command("clear_emergency", "Operador")
    assert not (tmp_path / "EMERGENCY_STOP").exists()


async def test_server_controls_only_mode_and_pause(agent_factory, backend):
    agent = agent_factory()
    await agent.startup()
    original = backend._op_heartbeat

    def with_extra(*a, **k):
        status, body = original(*a, **k)
        return status, {**body, "paused": True, "command": "format c:"}  # campos desconocidos se ignoran
    backend._op_heartbeat = with_extra
    await agent.send_heartbeat()
    assert agent.api.state.paused is True
    assert await agent.work_once() is False
    assert agent.state.value == "SERVER_HOLD"


def test_plan_for_combines_server_and_local_modes(agent_factory):
    from conftest import make_settings
    from dxagent.models import Job
    from dxagent.settings import AutomationMode

    def plan(local, task):
        agent = agent_factory(settings=make_settings(automation_mode=local))
        agent.sim_workflow = load_workflow("simulation_gift")
        real = agent.sim_workflow.model_copy(deep=True)
        real.simulation, real.allowed_modes = False, ["SUPERVISED", "AUTHORIZED_PRODUCTION"]
        agent.workflow = real
        wf, mode, problem = agent.plan_for(Job.from_task(task))
        return (wf.simulation if wf else None), mode, problem

    base = {"id": "t1", "orderCode": "DX-1", "playerId": "588141749", "kind": "pase_booyah", "productName": "Pase Booyah"}
    # Cualquier señal de simulación -> flujo de simulación, nunca el juego.
    assert plan(AutomationMode.AUTHORIZED_PRODUCTION, {**base, "mode": "production", "simulated": True})[:2] == (True, "SIMULATION")
    assert plan(AutomationMode.SUPERVISED, {**base, "mode": "simulation"})[:2] == (True, "SUPERVISED")
    # Servidor supervised -> aprobación humana local.
    assert plan(AutomationMode.AUTHORIZED_PRODUCTION, {**base, "mode": "supervised"})[:2] == (False, "SUPERVISED")
    # Servidor production pero modo local SUPERVISADO -> se exige aprobación humana.
    assert plan(AutomationMode.SUPERVISED, {**base, "mode": "production"})[:2] == (False, "SUPERVISED")
    # Producción local sin autorización validada en el flujo -> no se ejecuta.
    wf, mode, problem = plan(AutomationMode.AUTHORIZED_PRODUCTION, {**base, "mode": "production"})
    assert wf is None and "autorización" in problem


async def test_service_restart_keeps_manual_pause_and_applies_recent_commands(agent_factory, db):
    agent = agent_factory()
    await agent.startup()
    agent.handle_command("pause", "Operador")
    agent.publish_status()
    restarted = agent_factory()
    await restarted.startup()
    assert restarted.state.value == "PAUSED"  # la pausa manual sobrevive al reinicio
    restarted.publish_status()
    db.push_command("start", issued_by="ui")  # la interfaz lanza el servicio y pide iniciar
    again = agent_factory()
    await again.startup()
    assert again.state.value == "RUNNING"


async def test_supervised_friend_request_and_gift_show_phase_and_quantity(agent_factory, backend, device, db):
    import json
    from datetime import timedelta
    device.require_friendship = True
    device.auto_accept_friends = True  # el cliente acepta al instante
    backend.add_order("DX-SUP", "588141749", "caja_tokens_evo_universales", 20)
    agent = agent_factory(settings=make_settings(automation_mode=AutomationMode.SUPERVISED))
    await agent.startup()
    approver = asyncio.create_task(_approve_when_requested(db))
    await agent.work_once()
    friend = json.loads((await approver)["summary_json"])
    assert friend["phase"] == "friend_request" and "quantity" in friend
    await agent.sync.flush_all()
    assert backend.results["DX-SUP-friend"]["friendStatus"] == "request_sent"
    base = backend.clock()
    backend.clock = lambda: base + timedelta(minutes=backend.friend_retry_minutes + 1)
    approver = asyncio.create_task(_approve_when_requested(db))
    await agent.work_once()
    gift = json.loads((await approver)["summary_json"])
    assert (gift["phase"], gift["quantity"], gift["total_cost"], gift["chunk"]) == ("gift", "20", "1000", "1/1")
    assert db.get_job("DX-SUP-gift-0")["status"] == JobStatus.COMPLETED.value

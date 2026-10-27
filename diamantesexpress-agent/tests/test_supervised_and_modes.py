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
def test_freefire_template_refuses_to_load_until_verified(tmp_path, name, phase):
    src = paths.resources_dir() / "workflows" / f"{name}.template.yaml"
    dst = tmp_path / f"{name}.yaml"
    shutil.copy(src, dst)
    with pytest.raises(WorkflowNotReady, match="sin verificar"):
        load_workflow(name, tmp_path)
    # Sin marcadores la estructura es válida (con valores de prueba, NO reales).
    text = dst.read_text(encoding="utf-8")
    data = yaml.safe_load(text)
    data["app"]["package"] = "com.ejemplo.verificado"
    data["authorization"]["reference"] = "REF-PRUEBA"
    data["order_rules"] = {"player_id_pattern": "^[0-9]{5,15}$", "products": {"P1": "Etiqueta"}}
    for group in ("screens", "elements", "fields"):
        for item in data[group].values():
            item["description"] = "descripción de prueba"
    dst.write_text(yaml.safe_dump(data, allow_unicode=True), encoding="utf-8")
    wf = load_workflow(name, tmp_path)
    assert wf.simulation is False and "SIMULATION" not in wf.allowed_modes and wf.phase == phase


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

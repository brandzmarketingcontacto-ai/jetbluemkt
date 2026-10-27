"""Flujo completo contra el contrato real de Horizons, con tareas simuladas (simulate=true)."""

from dxagent.models import AgentState, JobStatus


async def test_end_to_end_simulated_task_completes_and_reports(agent_factory, backend, device, db):
    task = backend.add_task(task_id="task_1", order_code="DX-1234", player_id="588141749")
    agent = agent_factory()
    await agent.startup()
    assert agent.state == AgentState.RUNNING
    assert agent.api.state.mode == "simulation"
    assert await agent.work_once() is True
    row = db.get_job("task_1")
    assert row["status"] == JobStatus.COMPLETED.value
    assert row["order_id"] == "DX-1234"
    assert row["approved_by"] == "politica:simulacion"
    # La entrega ficticia ocurrió una vez, en la app de simulación, con los datos de la tarea.
    assert len(device.transactions) == 1
    tx = device.transactions[0]
    assert (tx.player_id, tx.product_code) == ("588141749", "pase_booyah")
    await agent.sync.flush_all()
    result = backend.results["task_1"]
    assert result["outcome"] == "completed"
    assert result["giftCode"] == tx.ref
    assert result["evidence"]["simulated"] is True
    assert result["evidence"]["preCommit"] is False
    assert result["evidence"]["verification"]["outcome"] == "success"
    # Evidencia solo como hashes (Horizons no recibe archivos; máx. 2 MB).
    assert all(f["sha256"].startswith("sha256:") for f in result["evidence"]["files"])
    assert "usage" not in result  # sin IA no hay consumo que reportar
    assert task["status"] == "completed"
    # Progreso enviado con los únicos estados que acepta la API.
    assert backend.progress and {p["status"] for p in backend.progress} <= {"processing", "verifying"}
    assert any(e["label"] == "resultado" for e in db.evidence_for("task_1"))


async def test_recipient_not_found_fails_before_commit(agent_factory, backend, device, db):
    device.known_players = {"111111111"}
    backend.add_task(task_id="task_x", player_id="999999999")
    agent = agent_factory()
    await agent.startup()
    await agent.work_once()
    row = db.get_job("task_x")
    assert row["status"] == JobStatus.FAILED.value
    assert row["error_code"] == "RECIPIENT_NOT_FOUND"
    assert device.transactions == []
    await agent.sync.flush_all()
    assert backend.results["task_x"]["outcome"] == "failed"
    assert backend.results["task_x"]["evidence"]["preCommit"] is True


async def test_invalid_task_rejected_by_rules(agent_factory, backend, device, db):
    backend.add_task(task_id="task_bad", player_id="12")       # no cumple ^[0-9]{5,15}$
    backend.add_task(task_id="task_prod", kind="otro_producto")  # producto no configurado en el flujo
    agent = agent_factory()
    await agent.startup()
    await agent.work_once()
    await agent.work_once()
    assert db.get_job("task_bad")["error_code"] == "INVALID_PLAYER_ID"
    assert db.get_job("task_prod")["error_code"] == "PRODUCT_NOT_ALLOWED"
    assert device.transactions == []


async def test_data_mismatch_blocks_commit(agent_factory, backend, device, db):
    agent = agent_factory()
    await agent.startup()
    # Catálogo erróneo en Horizons: el kind es Pase Booyah pero gameItemName apunta a otro objeto del juego.
    backend.add_task(task_id="task_mm", gameItemName="310 Diamantes")
    await agent.work_once()
    row = db.get_job("task_mm")
    assert row["error_code"] == "DATA_MISMATCH"
    assert row["status"] == JobStatus.RELEASED.value
    assert device.transactions == []
    await agent.sync.flush_all()
    assert "task_mm" not in backend.results  # se devuelve dejando vencer el lease


async def test_server_mode_and_pause_are_respected(agent_factory, backend, device, db):
    backend.add_task(task_id="task_m")
    agent = agent_factory()
    backend.mode = "off"
    await agent.startup()
    assert await agent.work_once() is False
    assert agent.state == AgentState.SERVER_HOLD
    backend.mode, backend.paused = "simulation", True
    await agent.send_heartbeat()
    assert await agent.work_once() is False
    assert agent.state == AgentState.SERVER_HOLD
    # Servidor en supervised con agente local en SIMULACIÓN: no reclama.
    backend.mode, backend.paused = "supervised", False
    await agent.send_heartbeat()
    assert await agent.work_once() is False
    assert "no permitido por el modo local SIMULATION" in agent.state_reason
    assert backend.tasks["task_m"]["status"] == "pending"
    backend.mode = "simulation"
    await agent.send_heartbeat()
    assert await agent.work_once() is True
    assert agent.state == AgentState.RUNNING


async def test_supervised_server_without_real_workflow_does_not_claim(agent_factory, backend, db):
    from conftest import make_settings
    from dxagent.settings import AutomationMode
    backend.mode = "supervised"
    backend.add_task(task_id="task_real", simulated=False)
    agent = agent_factory(settings=make_settings(automation_mode=AutomationMode.SUPERVISED))
    await agent.startup()
    assert await agent.work_once() is False
    assert "flujos reales" in agent.state_reason
    assert backend.tasks["task_real"]["status"] == "pending"  # nunca se ejecuta un pedido real con la app ficticia

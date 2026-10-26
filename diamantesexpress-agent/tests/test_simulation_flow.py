"""Flujo completo en modo SIMULACIÓN con la app ficticia."""

from dxagent.models import AgentState, JobStatus


async def test_end_to_end_simulation_completes_and_verifies(agent_factory, backend, device, db):
    job = backend.add_job(job_id="job-1", order_id="DX-1", player_id="123456789", product_code="DIAMANTES_310",
                          product_name="310 Diamantes")
    agent = agent_factory()
    await agent.startup()
    assert agent.state == AgentState.RUNNING
    assert await agent.work_once() is True
    row = db.get_job("job-1")
    assert row["status"] == JobStatus.COMPLETED.value
    assert row["approved_by"] == "politica:simulacion"
    # La entrega ficticia ocurrió exactamente una vez y con los datos del pedido.
    assert len(device.transactions) == 1
    tx = device.transactions[0]
    assert (tx.player_id, tx.product_code) == ("123456789", "DIAMANTES_310")
    await agent.sync.flush()
    completion = backend.completions["job-1"]
    assert completion["status"] == "completed"
    assert completion["verification"]["outcome"] == "success"
    assert completion["verification"]["details"]["reference"] == tx.ref
    assert completion["pre_commit"] is False
    assert backend.progress and backend.evidence
    assert job["status"] == "completed"
    # Evidencias guardadas localmente
    assert any(e["label"] == "resultado" for e in db.evidence_for("job-1"))


async def test_recipient_not_found_fails_before_commit(agent_factory, backend, device, db):
    device.known_players = {"111111111"}
    backend.add_job(job_id="job-x", player_id="999999999")
    agent = agent_factory()
    await agent.startup()
    await agent.work_once()
    row = db.get_job("job-x")
    assert row["status"] == JobStatus.FAILED.value
    assert row["error_code"] == "RECIPIENT_NOT_FOUND"
    assert device.transactions == []


async def test_invalid_order_rejected_by_rules(agent_factory, backend, device, db):
    backend.add_job(job_id="job-bad", player_id="12")  # no cumple ^[0-9]{5,15}$
    backend.add_job(job_id="job-prod", product_code="NO_EXISTE")
    agent = agent_factory()
    await agent.startup()
    await agent.work_once()
    await agent.work_once()
    assert db.get_job("job-bad")["error_code"] == "INVALID_PLAYER_ID"
    assert db.get_job("job-prod")["error_code"] == "PRODUCT_NOT_ALLOWED"
    assert device.transactions == []


async def test_data_mismatch_blocks_commit(agent_factory, backend, device, db):
    agent = agent_factory()
    await agent.startup()
    # Configuración errónea: el código pide 100 pero la etiqueta apunta a 310.
    agent.workflow.order_rules.products["DIAMANTES_100"] = "310 Diamantes"
    backend.add_job(job_id="job-mm", product_code="DIAMANTES_100")
    await agent.work_once()
    row = db.get_job("job-mm")
    assert row["error_code"] == "DATA_MISMATCH"
    assert row["status"] == JobStatus.RELEASED.value
    assert device.transactions == []

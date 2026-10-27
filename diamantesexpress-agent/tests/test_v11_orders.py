"""API v1.1: pedidos en dos fases (amistad + regalo), cantidades divididas, /defer y saldo de diamantes.

Todo en modo simulación: backend ficticio de Horizons y app de simulación (sin envíos reales)."""

from datetime import timedelta

from dxagent.models import AgentState, JobStatus


def advance(backend, minutes: float) -> None:
    base = backend.clock()
    backend.clock = lambda: base + timedelta(minutes=minutes)


async def run_until_idle(agent, limit: int = 10) -> None:
    # El outbox se sincroniza en paralelo en el servicio real; aquí, después de cada tarea.
    for _ in range(limit):
        worked = await agent.work_once()
        await agent.sync.flush_all()
        if not worked:
            break


async def test_two_phase_order_with_split_quantity(agent_factory, backend, device, db):
    device.require_friendship = True
    backend.add_order("DX-100", "588141749", "caja_tokens_evo_universales", 100)
    agent = agent_factory()
    await agent.startup()

    # Fase A: solicitud de amistad. Los regalos quedan en waiting_friend.
    await run_until_idle(agent)
    friend = backend.results["DX-100-friend"]
    assert friend["outcome"] == "completed" and friend["friendStatus"] == "request_sent"
    assert "588141749" in device.pending_requests
    assert "deliveredQuantity" not in friend
    assert all(backend.tasks[t]["status"] == "waiting_friend" for t in ("DX-100-gift-0", "DX-100-gift-1"))

    # Pasa friendRetryMinutes sin aceptar: el agente comprueba, no puede regalar y aplaza (no consume intentos).
    advance(backend, 11)
    await run_until_idle(agent)
    assert [d["reason"] for d in backend.defers][:1] == ["not_friend_yet"]
    assert backend.tasks["DX-100-gift-0"]["attempts"] == 0
    assert backend.tasks["DX-100-gift-0"]["deferCount"] >= 1
    assert db.get_job("DX-100-gift-0")["status"] == JobStatus.DEFERRED.value
    assert device.transactions == []

    # El cliente acepta; en el siguiente reintento se entregan 57 + 43.
    device.accept_pending_requests()
    advance(backend, 25)
    await run_until_idle(agent)
    assert [tx.quantity for tx in device.transactions] == [57, 43]
    assert backend.results["DX-100-gift-0"]["deliveredQuantity"] == 57
    assert backend.results["DX-100-gift-1"]["deliveredQuantity"] == 43
    assert backend.order_delivered("DX-100") == 100
    assert device.diamond_balance == 1_000_000 - 100 * 50


async def test_already_friends_releases_gifts_immediately(agent_factory, backend, device, db):
    device.require_friendship = True
    device.friends.add("588141749")
    backend.add_order("DX-200", "588141749", "pase_booyah", 1)
    agent = agent_factory()
    await agent.startup()
    await run_until_idle(agent)
    assert backend.results["DX-200-friend"]["friendStatus"] == "already_friends"
    assert backend.results["DX-200-gift-0"]["outcome"] == "completed"
    assert backend.results["DX-200-gift-0"]["deliveredQuantity"] == 1
    assert backend.defers == []


async def test_player_not_found_in_friend_phase(agent_factory, backend, device, db):
    device.known_players = {"111111111"}
    backend.add_order("DX-300", "999999999", "pase_booyah", 1)
    agent = agent_factory()
    await agent.startup()
    await run_until_idle(agent)
    assert backend.results["DX-300-friend"]["friendStatus"] == "player_not_found"
    assert backend.tasks["DX-300-gift-0"]["status"] == "manual_review"
    assert device.pending_requests == set()


async def test_local_insufficient_balance_blocks_commit(agent_factory, backend, device, db):
    device.diamond_balance = 100
    backend.add_task(task_id="t_bal", kind="caja_tokens_evo_universales", quantity=10)
    agent = agent_factory()
    await agent.startup()
    await agent.work_once()
    row = db.get_job("t_bal")
    assert row["error_code"] == "INSUFFICIENT_BALANCE"
    assert device.transactions == []


async def test_server_insufficient_balance_holds_agent(agent_factory, backend, device, db):
    backend.diamond_balance = 10
    backend.add_task(task_id="t_srv", kind="caja_tokens_evo_universales", quantity=57)
    agent = agent_factory()
    await agent.startup()
    assert await agent.work_once() is False
    assert agent.api.state.last_reason == "insufficient_balance"
    assert agent.api.state.balance_needed == 57 * 50
    assert agent.state == AgentState.SERVER_HOLD
    assert db.get_job("t_srv") is None


async def test_v10_task_without_phase_fields_still_works(agent_factory, backend, device, db):
    # Tarea tal como la envía la v1.0: sin campos de catálogo, cantidad ni fase en la respuesta.
    backend.add_task(task_id="t_v10")
    for key in ("chunkIndex", "chunkCount", "category", "gameItemName", "maxPerTransaction", "friendStatus",
                "deliveredQuantity"):
        backend.tasks["t_v10"].pop(key)
    backend.tasks["t_v10"]["unitDiamondCost"] = None
    agent = agent_factory()
    await agent.startup()
    await agent.work_once()
    assert db.get_job("t_v10")["status"] == JobStatus.COMPLETED.value


async def test_quantity_above_max_is_rejected(agent_factory, backend, device, db):
    backend.add_task(task_id="t_big", kind="caja_tokens_evo_universales", quantity=60)
    agent = agent_factory()
    await agent.startup()
    await agent.work_once()
    assert db.get_job("t_big")["error_code"] == "QUANTITY_EXCEEDS_MAX"
    assert device.transactions == []


def test_from_task_tolerates_v10_and_empty_strings():
    from dxagent.models import Job
    v10 = {"id": "t1", "orderCode": "DX-1", "kind": "pase_booyah", "productName": "Pase Booyah", "playerId": "588141749",
           "attempts": 1, "maxAttempts": 3, "mode": "simulation"}
    job = Job.from_task(v10)
    assert (job.phase, job.product.quantity, job.chunk_index, job.product.unit_diamond_cost) == ("gift", 1, 0, None)
    assert job.dedupe_key == "DX-1:gift:0"
    v11 = {**v10, "phase": "friend_request", "quantity": None, "notBefore": "", "friendStatus": "",
           "chunkIndex": "", "unitDiamondCost": ""}
    job = Job.from_task(v11)
    assert job.phase == "friend_request" and job.dedupe_key == "DX-1:friend_request:0"

"""Seguridad, configuración, contrato, precios y política de ejecución."""

import json
import logging

import pytest

from dxagent.ai.pricing import PricingTable
from dxagent.api.client import validate_base_url
from dxagent.api.contract import ApiContract
from dxagent.api.errors import ContractError, InsecureUrlError
from dxagent.engine.policy import ExecutionLimits, ExecutionPolicy, PolicyViolation
from dxagent.logging_setup import JsonFormatter, redact, register_secret
from dxagent.settings import SecretStore, SettingsManager


def test_https_required():
    with pytest.raises(InsecureUrlError):
        validate_base_url("http://tienda.example.com/api", False)
    with pytest.raises(InsecureUrlError):
        validate_base_url("http://127.0.0.1:8787/api", False)
    assert validate_base_url("http://127.0.0.1:8787/api", True)
    with pytest.raises(InsecureUrlError):
        validate_base_url("http://evil.example.com/api", True)
    assert validate_base_url("https://tienda.example.com/api/", False) == "https://tienda.example.com/api"


def test_secrets_redacted_from_logs():
    register_secret("mi-token-super-secreto-123")
    text = redact("Authorization: Bearer mi-token-super-secreto-123 key=sk-abcdefghijklmnop123 password=hunter22")
    assert "mi-token-super-secreto-123" not in text and "sk-abcdefghijklmnop" not in text and "hunter22" not in text
    record = logging.makeLogRecord({"msg": "token=%s", "args": ("mi-token-super-secreto-123",)})
    assert "super-secreto" not in JsonFormatter().format(record)


def test_settings_env_precedence_and_no_secrets_on_disk(tmp_path):
    env = {"DX_API_BASE_URL": "https://x.example/hcgi/api/", "AGENT_MODE": "supervised",
           "DX_AGENT_TOKEN": "tok-desde-env-123", "DXAGENT_MAX_ACTIONS_PER_ORDER": "12",
           "DX_AGENT_NAME": "PC-WINDOWS-01", "ADB_DEVICE_SERIAL": "emulator-5554", "AGENT_POLL_SECONDS": "15",
           "DIAMANTESEXPRESS_API_URL": "https://alias-viejo.example/api"}
    manager = SettingsManager(tmp_path / "settings.json", env=env, env_file=tmp_path / ".env",
                              secrets=SecretStore(env=env, use_keyring=False))
    s = manager.load()
    assert s.api_url == "https://x.example/hcgi/api"  # el nombre oficial gana al alias anterior
    assert s.automation_mode.value == "SUPERVISED"
    assert (s.agent_name, s.adb_serial, s.poll_interval_seconds) == ("PC-WINDOWS-01", "emulator-5554", 15)
    env["AGENT_MODE"] = "production"
    assert manager.load().automation_mode.value == "AUTHORIZED_PRODUCTION"
    assert s.max_actions_per_order == 12
    assert manager.secrets.get("agent_token") == "tok-desde-env-123"
    manager.save(s)
    assert "tok-desde-env-123" not in (tmp_path / "settings.json").read_text()
    assert "agent_token" not in json.loads((tmp_path / "settings.json").read_text())


def test_official_contract_and_overrides(tmp_path):
    contract = ApiContract.load()
    assert contract.version == "1.0.0"
    assert contract.get("claimTask").method == "POST" and contract.get("claimTask").path == "/agent/tasks/claim"
    assert contract.get("reportResult").render(id="a/b") == "/agent/tasks/a%2Fb/result"
    over = ApiContract.load(overrides={"claimTask": "POST /agent/v2/tasks/claim"})
    assert over.get("claimTask").path == "/agent/v2/tasks/claim"
    broken = tmp_path / "c.json"
    broken.write_text('{"openapi": "3.0.3", "paths": {"/agent/heartbeat": {"post": {}}}}')
    with pytest.raises(ContractError, match="/agent/tasks/claim"):
        ApiContract.load(broken)


def test_pricing_uses_cached_rate_and_snapshots():
    table = PricingTable.load()
    assert table.cost("gpt-4.1-mini", 1_000_000, 0, 0) == pytest.approx(0.40)
    assert table.cost("gpt-4.1-mini-2025-04-14", 1_000_000, 1_000_000, 1_000_000) == pytest.approx(0.10 + 1.60)
    assert table.cost("modelo-desconocido", 10, 0, 10) is None


def test_usage_tracker_refuses_unpriced_model(db):
    from dxagent.ai.usage import BudgetExceeded, UsageTracker
    tracker = UsageTracker(db, PricingTable.load(), "a", 1, 1, 5)
    with pytest.raises(BudgetExceeded) as err:
        tracker.check_budget("modelo-sin-precio", "job")
    assert err.value.code == "PRICING_MISSING"


def test_usage_records_idempotent_and_aggregated_for_result(db):
    from types import SimpleNamespace as NS
    from dxagent.ai.usage import UsageTracker, usage_for_result
    tracker = UsageTracker(db, PricingTable.load(), "a", 1, 1, 5)
    usage = NS(input_tokens=1000, output_tokens=300, total_tokens=1300, input_tokens_details=NS(cached_tokens=200),
               output_tokens_details=NS(reasoning_tokens=0))
    rec = tracker.record(model="gpt-4.1-mini", job_id="j", order_id="o", usage=usage, request_id="r",
                         response_id="x", latency_ms=5)
    tracker.record(model="gpt-4.1-mini", job_id="j", order_id="o", usage=usage, request_id="r2",
                   response_id="x2", latency_ms=5)
    assert db.insert_usage(rec) is False  # el mismo registro no se duplica
    total = usage_for_result(db, "j", "gpt-4.1-mini")
    assert (total.prompt_tokens, total.completion_tokens, total.cached_tokens) == (2000, 600, 400)
    assert total.cost_usd == pytest.approx(2 * (800 * 0.40 + 200 * 0.10 + 300 * 1.60) / 1e6)
    assert usage_for_result(db, "otra", "m") is None


def test_policy_allowlist_and_limits(tmp_path):
    policy = ExecutionPolicy(ExecutionLimits(max_actions=2), {"com.ok.app"}, tmp_path / "flag")
    with pytest.raises(PolicyViolation):
        policy.authorize("shell", step_id="x")
    with pytest.raises(PolicyViolation):
        policy.authorize("tap", x=1.5, y=0.2)
    with pytest.raises(PolicyViolation):
        policy.authorize("launch_app", package="com.otra.app")
    with pytest.raises(PolicyViolation):
        policy.authorize("input_text", text="1; rm -rf /")
    policy = ExecutionPolicy(ExecutionLimits(max_actions=2), {"com.ok.app"}, tmp_path / "flag")
    policy.authorize("tap", x=0.1, y=0.1)
    policy.authorize("input_text", text="123456")
    with pytest.raises(PolicyViolation) as err:
        policy.authorize("tap", x=0.1, y=0.1)
    assert err.value.code == "MAX_ACTIONS"
    (tmp_path / "flag").write_text("x")
    with pytest.raises(PolicyViolation) as err:
        policy.authorize("screenshot")
    assert err.value.code == "EMERGENCY_STOP"


async def test_api_errors_are_classified(backend):
    from conftest import make_api
    from dxagent.api.errors import ApiUnavailable, AuthError, ConflictError, NotFoundError
    api = make_api(backend)
    backend.fail_statuses = [500]
    with pytest.raises(ApiUnavailable):
        await api.heartbeat()
    with pytest.raises(AuthError):
        await make_api(backend, token="dxa_malo_000000").heartbeat()
    with pytest.raises(NotFoundError):
        await api.report_progress("no_existe", "processing")
    backend.add_task(task_id="t_other")
    backend.tasks["t_other"].update(status="processing", owner="otro-agente")
    with pytest.raises(ConflictError):
        await api.report_result("t_other", {"outcome": "completed"})
    with pytest.raises(ValueError):
        await api.report_progress("t_other", "completed")  # la API solo admite processing | verifying
    backend.down = True
    with pytest.raises(ApiUnavailable):
        await api.heartbeat()


async def test_token_headers_and_claim_lease(backend):
    from conftest import TOKEN, make_api
    seen = {}
    original = backend.handle

    def spy(method, path, headers, body):
        seen.update(path=path, auth=headers.get("authorization"))
        return original(method, path, headers, body)
    backend.handle = spy
    api = make_api(backend)
    assert (await api.claim_task()).reason == "empty"
    assert seen == {"path": "/hcgi/api/agent/tasks/claim", "auth": f"Bearer {TOKEN}"}
    backend.add_task(task_id="t1")
    claim = await api.claim_task()
    assert claim.task["id"] == "t1" and claim.simulate is True and claim.task["leaseExpiresAt"]
    assert (await api.claim_task()).task is None  # reclamo atómico: no se entrega dos veces
    assert [t["id"] for t in await api.current_tasks()] == ["t1"]


async def test_audit_log_is_hash_chained_and_detects_tampering(tmp_path, backend, device):
    from conftest import make_agent
    from dxagent.audit import AuditLog
    from dxagent.database import LocalDatabase
    audit = AuditLog(tmp_path / "audit.jsonl")
    db = LocalDatabase(tmp_path / "a.db", audit=audit)
    backend.add_task(task_id="t_audit")
    agent = make_agent(db, backend, device, tmp_path)
    await agent.startup()
    await agent.work_once()
    events = [__import__("json").loads(line)["event"] for line in (tmp_path / "audit.jsonl").read_text().splitlines()]
    assert events[:1] == ["claim"] and "commit_started" in events and "commit_sent" in events
    assert "verified" in events and events[-1] == "finish"
    assert AuditLog(tmp_path / "audit.jsonl").verify() == (True, len(events))
    text = (tmp_path / "audit.jsonl").read_text().replace('"commit_sent"', '"commit_omitido"')
    (tmp_path / "audit.jsonl").write_text(text)
    assert AuditLog(tmp_path / "audit.jsonl").verify()[0] is False

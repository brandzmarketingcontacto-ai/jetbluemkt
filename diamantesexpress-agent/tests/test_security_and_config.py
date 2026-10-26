"""Seguridad, configuración, contrato, precios y política de ejecución."""

import json
import logging

import pytest

from dxagent.ai.pricing import PricingTable
from dxagent.api.client import HorizonsApiClient, validate_base_url
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
    env = {"DIAMANTESEXPRESS_API_URL": "https://x.example/api/", "AUTOMATION_MODE": "SUPERVISED",
           "DIAMANTESEXPRESS_AGENT_TOKEN": "tok-desde-env-123", "DXAGENT_MAX_ACTIONS_PER_ORDER": "12"}
    manager = SettingsManager(tmp_path / "settings.json", env=env, env_file=tmp_path / ".env",
                              secrets=SecretStore(env=env, use_keyring=False))
    s = manager.load()
    assert s.api_url == "https://x.example/api" and s.automation_mode.value == "SUPERVISED"
    assert s.max_actions_per_order == 12
    assert manager.secrets.get("agent_token") == "tok-desde-env-123"
    manager.save(s)
    assert "tok-desde-env-123" not in (tmp_path / "settings.json").read_text()
    assert "agent_token" not in json.loads((tmp_path / "settings.json").read_text())


def test_contract_resolution_and_overrides(tmp_path):
    contract = ApiContract.load()
    assert contract.get("claimJob").render(job_id="a/b") == "/jobs/a%2Fb/claim"
    over = ApiContract.load(overrides={"claimJob": "PUT /v2/tareas/{job_id}/reclamar"})
    assert over.get("claimJob").method == "PUT"
    broken = tmp_path / "c.yaml"
    broken.write_text("openapi: 3.1.0\npaths: {}\n")
    with pytest.raises(ContractError):
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


def test_usage_records_are_idempotent_in_outbox(db):
    from types import SimpleNamespace as NS
    from dxagent.ai.usage import UsageTracker
    tracker = UsageTracker(db, PricingTable.load(), "a", 1, 1, 5)
    usage = NS(input_tokens=10, output_tokens=5, total_tokens=15, input_tokens_details=NS(cached_tokens=0),
               output_tokens_details=NS(reasoning_tokens=0))
    rec = tracker.record(model="gpt-4.1-mini", job_id="j", order_id="o", usage=usage, request_id="r",
                         response_id="x", latency_ms=5)
    assert db.enqueue("usage", f"usage:{rec.record_id}", rec.model_dump(), "j") is False
    assert db.insert_usage(rec) is False


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
    from dxagent.api.errors import ApiUnavailable, AuthError
    api = make_api(backend)
    backend.fail_statuses = [500]
    with pytest.raises(ApiUnavailable):
        await api.get_config()
    bad = make_api(backend, token="malo-0000000")
    with pytest.raises(AuthError):
        await bad.get_config()
    backend.down = True
    with pytest.raises(ApiUnavailable):
        await api.heartbeat({})


async def test_pairing_flow(backend):
    api = HorizonsApiClient("https://api.test/v1", "", None, ApiContract.load(), transport=backend.transport())
    result = await api.pair("PAIR-0001")
    assert result["agent_id"] == "agent-test"
    assert (await api.get_config()) == {"production_authorized": False}
    from dxagent.api.errors import AuthError
    with pytest.raises(AuthError):
        await api.pair("PAIR-0001")  # código de un solo uso

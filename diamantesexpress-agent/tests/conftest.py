"""Fixtures compartidas. Ninguna prueba realiza compras ni envíos reales: todo usa
el backend simulado, el dispositivo simulado y un cliente OpenAI falso."""

from __future__ import annotations

import os
import types
from pathlib import Path

import pytest

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")


@pytest.fixture(autouse=True)
def isolated_home(tmp_path, monkeypatch):
    monkeypatch.setenv("DXAGENT_HOME", str(tmp_path / "home"))
    for var in ("DIAMANTESEXPRESS_AGENT_TOKEN", "OPENAI_API_KEY", "DIAMANTESEXPRESS_API_URL", "AUTOMATION_MODE",
                "DX_API_BASE_URL", "DX_AGENT_TOKEN", "DX_AGENT_NAME", "AGENT_MODE", "ADB_PATH", "ADB_DEVICE_SERIAL"):
        monkeypatch.delenv(var, raising=False)
    return tmp_path / "home"


from dxagent.ai.schemas import ExtractedField, ScreenAnalysis, SuggestedAction, VisibleElement  # noqa: E402
from dxagent.api.client import HorizonsApiClient  # noqa: E402
from dxagent.api.contract import ApiContract  # noqa: E402
from dxagent.api.mock_backend import MockHorizonsBackend  # noqa: E402
from dxagent.android.mock_device import MockDevice  # noqa: E402
from dxagent.database import LocalDatabase  # noqa: E402
from dxagent.service.agent import AgentService  # noqa: E402
from dxagent.settings import AgentSettings, AutomationMode, SecretStore  # noqa: E402

API_URL = "https://tienda.test/hcgi/api"
TOKEN = "dxa_test_token_123456"
FAST_ENGINE = {"settle_s": 0.0, "poll_interval_s": 0.01, "screen_wait_timeout_s": 0.3}


@pytest.fixture
def backend():
    return MockHorizonsBackend(token=TOKEN)


@pytest.fixture
def db(tmp_path):
    database = LocalDatabase(tmp_path / "agent.db")
    yield database
    database.close()


@pytest.fixture
def device():
    return MockDevice()


def make_api(backend: MockHorizonsBackend, token: str = TOKEN) -> HorizonsApiClient:
    return HorizonsApiClient(API_URL, token, ApiContract.load(), agent_name="PC-PRUEBAS", transport=backend.transport())


def make_settings(**overrides) -> AgentSettings:
    base = dict(api_url=API_URL, agent_name="PC-PRUEBAS", poll_interval_seconds=0.01, heartbeat_interval_seconds=0.05,
                outbox_sync_interval_seconds=0.01, watchdog_interval_seconds=0.05, vision_backend="disabled",
                automation_mode=AutomationMode.SIMULATION, approval_timeout_seconds=2.0, freeze_threshold_seconds=0.0)
    base.update(overrides)
    return AgentSettings(**base)


def make_agent(db, backend, device, tmp_path, *, settings=None, vision=None, token=TOKEN, emulator=None,
               engine=None) -> AgentService:
    async def factory():
        return device

    return AgentService(settings or make_settings(), db, SecretStore(env={}, use_keyring=False),
                        api=make_api(backend, token), device_factory=factory, vision=vision, emulator=emulator,
                        evidence_root=tmp_path / "evidence", emergency_flag=tmp_path / "EMERGENCY_STOP",
                        live_screenshot=tmp_path / "live.png", engine_overrides=engine or dict(FAST_ENGINE))


@pytest.fixture
def agent_factory(db, backend, device, tmp_path):
    def build(**kwargs):
        return make_agent(db, backend, device, tmp_path, **kwargs)
    return build


# ------------------------------------------------------------- OpenAI falso
SCREEN_OF = {"home": "home", "recipient": "recipient", "gift_select": "gift_select", "confirm": "confirm",
             "processing": "processing", "result_success": "result_success", "result_failure": "result_failure"}
FIELD_RID = {"confirm_recipient": "tv_confirm_recipient", "confirm_product_code": "tv_confirm_product_code",
             "result_recipient": "tv_result_recipient", "result_product_code": "tv_result_product_code",
             "result_ref": "tv_result_ref"}


class FakeResponses:
    """Imita `client.responses.parse` de la Responses API usando el estado real del simulador."""

    def __init__(self, device: MockDevice, error: Exception | None = None, confidence: float = 0.95,
                 usage=(1200, 200, 150)):
        self.device = device
        self.error = error
        self.confidence = confidence
        self.calls: list[dict] = []
        self.usage = usage

    async def parse(self, **kwargs):
        self.calls.append(kwargs)
        if self.error:
            raise self.error
        d = self.device
        screen = SCREEN_OF.get(d.screen, "unknown") if d.foreground.endswith("giftsim") else "unknown"
        prompt = kwargs["input"][0]["content"][0]["text"]
        elements, fields = [], []
        for el in d.visible_elements():
            x1, y1, x2, y2 = el.rect
            cx, cy = (x1 + x2) / 2, (y1 + y2) / 2
            elements.append(VisibleElement(element_id=el.rid, label=el.text, x=cx, y=cy))
            if el.rid.startswith("product_") and f"'{el.text}'" in prompt:
                elements.append(VisibleElement(element_id="target_option", label=el.text, x=cx, y=cy))
        for name, rid in FIELD_RID.items():
            el = next((e for e in d.visible_elements() if e.rid == rid), None)
            if el is not None:
                fields.append(ExtractedField(name=name, value=el.text))
        parsed = ScreenAnalysis(screen_id=screen, confidence=self.confidence, detected_state=d.screen,
                                visible_elements=elements, extracted_fields=fields,
                                suggested_action=SuggestedAction(action="none", element_id=None, x=None, y=None, text=None),
                                needs_human=False, reason="oráculo de prueba")
        inp, cached, out = self.usage
        usage = types.SimpleNamespace(input_tokens=inp, output_tokens=out, total_tokens=inp + out,
                                      input_tokens_details=types.SimpleNamespace(cached_tokens=cached),
                                      output_tokens_details=types.SimpleNamespace(reasoning_tokens=0))
        resp = types.SimpleNamespace(output_parsed=parsed, usage=usage, id=f"resp_{len(self.calls)}",
                                     model="gpt-4.1-mini-2025-04-14")
        resp._request_id = f"req_{len(self.calls)}"
        return resp


class FakeOpenAI:
    def __init__(self, responses: FakeResponses):
        self.responses = responses


@pytest.fixture
def fake_openai_factory():
    def build(device, **kwargs):
        return FakeOpenAI(FakeResponses(device, **kwargs))
    return build


def project_root() -> Path:
    return Path(__file__).resolve().parents[1]

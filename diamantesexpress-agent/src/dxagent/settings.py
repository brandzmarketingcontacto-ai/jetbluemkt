"""SettingsManager: configuración local y gestión de secretos.

Prioridad (de menor a mayor): valores por defecto < settings.json < archivo .env < variables de entorno.
Los secretos (token del agente y clave de OpenAI) nunca se guardan en settings.json:
se almacenan en el Administrador de credenciales de Windows mediante `keyring`.
"""

from __future__ import annotations

import json
import logging
import os
from enum import Enum
from pathlib import Path
from typing import Any

from pydantic import BaseModel, Field, field_validator

from dxagent import APP_ID, paths
from dxagent.logging_setup import register_secret

log = logging.getLogger(__name__)


class AutomationMode(str, Enum):
    SIMULATION = "SIMULATION"
    SUPERVISED = "SUPERVISED"
    AUTHORIZED_PRODUCTION = "AUTHORIZED_PRODUCTION"


class AgentSettings(BaseModel):
    # Backend DiamantesExpress (Hostinger Horizons): https://<dominio-publicado>/hcgi/api
    api_url: str = ""
    agent_name: str = ""          # hostname enviado en el heartbeat (DX_AGENT_NAME)
    api_timeout_seconds: float = 20.0
    openapi_contract_path: str = ""
    route_overrides: dict[str, str] = Field(default_factory=dict)
    allow_insecure_http_localhost: bool = False
    poll_interval_seconds: float = 10.0
    heartbeat_interval_seconds: float = 30.0

    # OpenAI
    vision_backend: str = "auto"  # auto | openai | disabled
    openai_model: str = "gpt-4.1-mini"
    openai_timeout_seconds: float = 60.0
    openai_max_output_tokens: int = 800
    ai_image_detail: str = "high"
    pricing_path: str = ""
    max_ai_cost_per_order_usd: float = 0.25
    daily_ai_budget_usd: float = 5.0
    ai_reserve_per_request_usd: float = 0.01
    max_ai_requests_per_order: int = 8
    ai_min_confidence: float = 0.75
    verify_result_with_ai: bool = False

    # Modo y flujo
    automation_mode: AutomationMode = AutomationMode.SIMULATION
    workflow_id: str = "simulation_gift"             # flujo para tareas reales (supervised/production)
    simulation_workflow_id: str = "simulation_gift"  # flujo para tareas con simulate=true
    friend_workflow_id: str = "simulation_friend"    # fase friend_request de tareas reales
    simulation_friend_workflow_id: str = "simulation_friend"
    approval_timeout_seconds: float = 600.0
    operator_name: str = ""

    # Límites de ejecución
    max_actions_per_order: int = 120  # la cantidad se ajusta con toques en – / + (hasta 57 por caja)
    max_order_duration_seconds: float = 300.0
    max_step_retries: int = 2
    max_consecutive_errors: int = 3
    screen_wait_timeout_seconds: float = 20.0

    # Android: emulador (BlueStacks 5 o LDPlayer 9) controlado por ADB
    device_backend: str = "mock"  # mock (simulador interno) | adb (emulador real)
    emulator: str = "bluestacks"  # bluestacks | ldplayer
    emulator_instance: str = ""   # BlueStacks: nombre interno (p. ej. Pie64_1); LDPlayer: índice o nombre
    bluestacks_install_dir: str = ""
    bluestacks_conf_path: str = ""
    ldplayer_install_dir: str = ""
    adb_path: str = ""
    adb_serial: str = ""
    adb_command_timeout_seconds: float = 20.0
    emulator_boot_timeout_seconds: float = 180.0
    freeze_threshold_seconds: float = 60.0
    allow_emulator_restart: bool = True
    close_emulator_on_stop: bool = False
    account_key: str = "default"

    # 24/7
    autostart: bool = False
    prevent_sleep: bool = False
    watchdog_interval_seconds: float = 15.0
    outbox_sync_interval_seconds: float = 5.0
    evidence_retention_days: int = 30
    log_level: str = "INFO"

    @field_validator("api_url")
    @classmethod
    def _strip_url(cls, value: str) -> str:
        return value.strip().rstrip("/")

    @field_validator("vision_backend")
    @classmethod
    def _vision_backend(cls, value: str) -> str:
        value = value.lower()
        if value not in {"auto", "openai", "disabled"}:
            raise ValueError("vision_backend debe ser auto, openai o disabled")
        return value

    @field_validator("emulator")
    @classmethod
    def _emulator(cls, value: str) -> str:
        value = value.lower().strip()
        if value not in {"bluestacks", "ldplayer"}:
            raise ValueError("emulator debe ser bluestacks o ldplayer")
        return value

    @field_validator("device_backend")
    @classmethod
    def _device_backend(cls, value: str) -> str:
        value = value.lower()
        if value not in {"mock", "adb"}:
            raise ValueError("device_backend debe ser mock o adb")
        return value


# Variables de entorno documentadas en la especificación -> campo de AgentSettings.
# Variables de entorno -> campo de AgentSettings. Primero los nombres oficiales de la especificación
# de DiamantesExpress (DX_*, ADB_*, AGENT_*); luego los alias de la versión anterior.
ENV_MAP: dict[str, str] = {
    "DX_API_BASE_URL": "api_url",
    "DX_AGENT_NAME": "agent_name",
    "ADB_PATH": "adb_path",
    "ADB_DEVICE_SERIAL": "adb_serial",
    "AGENT_POLL_SECONDS": "poll_interval_seconds",
    "AGENT_HEARTBEAT_SECONDS": "heartbeat_interval_seconds",
    "AGENT_MODE": "automation_mode",
    "DIAMANTESEXPRESS_API_URL": "api_url",
    "OPENAI_MODEL": "openai_model",
    "POLL_INTERVAL_SECONDS": "poll_interval_seconds",
    "HEARTBEAT_INTERVAL_SECONDS": "heartbeat_interval_seconds",
    "MAX_AI_COST_PER_ORDER_USD": "max_ai_cost_per_order_usd",
    "DAILY_AI_BUDGET_USD": "daily_ai_budget_usd",
    "AUTOMATION_MODE": "automation_mode",
    "DX_EMULATOR": "emulator",
    "EMULATOR_INSTANCE": "emulator_instance",
    "BLUESTACKS_INSTANCE": "emulator_instance",
}
SECRET_ENV = {
    "agent_token": ("DX_AGENT_TOKEN", "DIAMANTESEXPRESS_AGENT_TOKEN"),
    "openai_api_key": ("OPENAI_API_KEY",),
}
# AGENT_MODE usa los nombres del servidor en minúsculas.
MODE_ALIASES = {"simulation": "SIMULATION", "supervised": "SUPERVISED", "production": "AUTHORIZED_PRODUCTION",
                "authorized_production": "AUTHORIZED_PRODUCTION"}
ENV_PREFIX = "DXAGENT_"


def parse_env_file(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.exists():
        return values
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


class SecretStore:
    """Secretos en Windows Credential Manager (keyring) con respaldo en variables de entorno."""

    SERVICE = APP_ID

    def __init__(self, env: dict[str, str] | None = None, use_keyring: bool = True):
        self._env = env if env is not None else dict(os.environ)
        self._use_keyring = use_keyring
        self._memory: dict[str, str] = {}

    def _keyring(self):
        if not self._use_keyring:
            return None
        try:
            import keyring
            return keyring
        except Exception:  # pragma: no cover - depende del sistema
            return None

    def get(self, name: str) -> str | None:
        value = self._memory.get(name)
        if value is None:
            kr = self._keyring()
            if kr is not None:
                try:
                    value = kr.get_password(self.SERVICE, name)
                except Exception as exc:  # backend no disponible
                    log.debug("keyring no disponible: %s", type(exc).__name__)
        if not value:
            value = next((self._env[e] for e in SECRET_ENV.get(name, ()) if self._env.get(e)), None)
        register_secret(value)
        return value

    def set(self, name: str, value: str) -> bool:
        """Guarda el secreto. Devuelve True si quedó protegido por el sistema operativo."""
        register_secret(value)
        kr = self._keyring()
        if kr is not None:
            try:
                kr.set_password(self.SERVICE, name, value)
                self._memory.pop(name, None)
                return True
            except Exception as exc:
                log.warning("No se pudo guardar el secreto en el almacén seguro: %s", type(exc).__name__)
        self._memory[name] = value
        return False

    def delete(self, name: str) -> None:
        self._memory.pop(name, None)
        kr = self._keyring()
        if kr is not None:
            try:
                kr.delete_password(self.SERVICE, name)
            except Exception:
                pass

    def has(self, name: str) -> bool:
        return bool(self.get(name))


class SettingsManager:
    def __init__(self, settings_file: Path | None = None, env: dict[str, str] | None = None,
                 env_file: Path | None = None, secrets: SecretStore | None = None):
        self.settings_file = settings_file or (paths.data_dir() / "settings.json")
        self.env_file = env_file or (paths.data_dir() / ".env")
        self._env = env if env is not None else dict(os.environ)
        self.secrets = secrets or SecretStore(env=self._merged_env())
        self.overridden: set[str] = set()

    def _merged_env(self) -> dict[str, str]:
        merged = parse_env_file(self.env_file) if self.env_file else {}
        merged.update(self._env)
        return merged

    def load(self) -> AgentSettings:
        data: dict[str, Any] = {}
        if self.settings_file.exists():
            try:
                data = json.loads(self.settings_file.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError) as exc:
                log.error("settings.json ilegible, se usan valores por defecto: %s", exc)
        data.pop("agent_id", None)  # campo de la versión anterior (el servidor identifica por token)
        legacy_instance = data.pop("bluestacks_instance", None)  # versión anterior: solo BlueStacks
        if legacy_instance and not data.get("emulator_instance"):
            data["emulator_instance"] = legacy_instance
        env = self._merged_env()
        self.overridden = set()
        for env_name, field in reversed(list(ENV_MAP.items())):  # los nombres oficiales tienen prioridad
            if env.get(env_name):
                value = env[env_name]
                if field == "automation_mode":
                    value = MODE_ALIASES.get(value.strip().lower(), value.strip().upper())
                data[field] = value
                self.overridden.add(field)
        for field in AgentSettings.model_fields:
            env_name = ENV_PREFIX + field.upper()
            if env.get(env_name):
                raw = env[env_name]
                if field == "route_overrides":
                    data[field] = json.loads(raw)
                else:
                    data[field] = raw
                self.overridden.add(field)
        return AgentSettings.model_validate(data)

    def save(self, settings: AgentSettings) -> None:
        payload = settings.model_dump(mode="json")
        self.settings_file.parent.mkdir(parents=True, exist_ok=True)
        tmp = self.settings_file.with_suffix(".tmp")
        tmp.write_text(json.dumps(payload, indent=2, ensure_ascii=False), encoding="utf-8")
        os.replace(tmp, self.settings_file)

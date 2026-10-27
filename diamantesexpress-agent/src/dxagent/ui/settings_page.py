"""Página de Configuración: API, token del agente, secretos, emulador (BlueStacks o LDPlayer), OpenAI, presupuesto, modo y 24/7."""

from __future__ import annotations

import asyncio
from typing import Any, Callable

from PySide6.QtCore import QObject, QRunnable, QThreadPool, Signal
from PySide6.QtWidgets import (QCheckBox, QComboBox, QDoubleSpinBox, QFormLayout, QFrame, QHBoxLayout, QLabel,
                               QLineEdit, QMessageBox, QPushButton, QScrollArea, QSpinBox, QVBoxLayout, QWidget)

from dxagent import paths
from dxagent.settings import AgentSettings, AutomationMode, SettingsManager
from dxagent.winplatform import autostart_enabled, set_autostart

MODE_LABELS = {
    AutomationMode.SIMULATION: "SIMULACIÓN (aplicación ficticia)",
    AutomationMode.SUPERVISED: "SUPERVISADO (aprobación humana del envío)",
    AutomationMode.AUTHORIZED_PRODUCTION: "PRODUCCIÓN AUTORIZADA (requiere autorización validada)",
}


class _Signals(QObject):
    done = Signal(object, object)


class _Task(QRunnable):
    def __init__(self, fn: Callable[[], Any], callback: Callable[[Any, Exception | None], None]):
        super().__init__()
        self.fn = fn
        self.signals = _Signals()
        self.signals.done.connect(callback)

    def run(self) -> None:
        try:
            self.signals.done.emit(self.fn(), None)
        except Exception as exc:
            self.signals.done.emit(None, exc)


def run_in_background(fn, callback) -> None:
    QThreadPool.globalInstance().start(_Task(fn, callback))


def section(title: str) -> tuple[QFrame, QFormLayout]:
    frame = QFrame()
    frame.setObjectName("card")
    lay = QVBoxLayout(frame)
    lay.setContentsMargins(16, 12, 16, 12)
    head = QLabel(title)
    head.setObjectName("sectionTitle")
    lay.addWidget(head)
    form = QFormLayout()
    form.setHorizontalSpacing(18)
    lay.addLayout(form)
    return frame, form


def dspin(value: float, maximum: float, decimals: int = 2, step: float = 0.05) -> QDoubleSpinBox:
    w = QDoubleSpinBox()
    w.setDecimals(decimals)
    w.setMaximum(maximum)
    w.setSingleStep(step)
    w.setValue(value)
    return w


def ispin(value: int, maximum: int) -> QSpinBox:
    w = QSpinBox()
    w.setMaximum(maximum)
    w.setValue(value)
    return w


class SettingsPage(QScrollArea):
    def __init__(self, manager: SettingsManager, on_saved: Callable[[], None] | None = None):
        super().__init__()
        self.manager = manager
        self.on_saved = on_saved
        self.s = manager.load()
        self.setWidgetResizable(True)
        inner = QWidget()
        inner.setObjectName("root")
        self.setWidget(inner)
        lay = QVBoxLayout(inner)
        lay.setSpacing(12)
        s = self.s

        # --- API
        box, f = section("Conexión con DiamantesExpress (Hostinger Horizons)")
        self.api_url = QLineEdit(s.api_url)
        self.api_url.setPlaceholderText("https://<dominio-publicado>/hcgi/api")
        self.agent_name = QLineEdit(s.agent_name)
        self.agent_name.setPlaceholderText("PC-WINDOWS-01 (vacío = nombre del equipo)")
        self.contract = QLineEdit(s.openapi_contract_path)
        self.contract.setPlaceholderText("Vacío = contrato oficial incluido (OpenAPI 1.0.0 de Horizons)")
        self.poll = dspin(s.poll_interval_seconds, 3600, 1, 1)
        self.heartbeat = dspin(s.heartbeat_interval_seconds, 3600, 1, 5)
        f.addRow("URL de la API", self.api_url)
        f.addRow("Nombre del agente", self.agent_name)
        f.addRow("Contrato OpenAPI", self.contract)
        f.addRow("Consulta de tareas (s)", self.poll)
        f.addRow("Heartbeat (s)", self.heartbeat)
        test_btn = QPushButton("Probar conexión (heartbeat, no reclama tareas)")
        test_btn.clicked.connect(self.test_api)
        f.addRow("", test_btn)
        lay.addWidget(box)

        # --- Secretos
        box, f = section("Claves y secretos (Administrador de credenciales de Windows)")
        self.token_status = QLabel()
        self.openai_status = QLabel()
        self.token_input = QLineEdit()
        self.token_input.setEchoMode(QLineEdit.Password)
        self.token_input.setPlaceholderText("dxa_... (Automatización → Tokens del agente; se muestra una sola vez)")
        self.openai_input = QLineEdit()
        self.openai_input.setEchoMode(QLineEdit.Password)
        self.openai_input.setPlaceholderText("sk-…")
        save_token = QPushButton("Guardar token")
        save_token.clicked.connect(lambda: self.save_secret("agent_token", self.token_input))
        save_openai = QPushButton("Guardar clave OpenAI")
        save_openai.clicked.connect(lambda: self.save_secret("openai_api_key", self.openai_input))
        verify_openai = QPushButton("Verificar clave (sin consumir tokens)")
        verify_openai.clicked.connect(self.verify_openai)
        r1 = QHBoxLayout(); r1.addWidget(self.token_input); r1.addWidget(save_token); r1.addWidget(self.token_status)
        r2 = QHBoxLayout(); r2.addWidget(self.openai_input); r2.addWidget(save_openai); r2.addWidget(verify_openai)
        r2.addWidget(self.openai_status)
        f.addRow("Token del agente", r1)
        f.addRow("Clave OpenAI", r2)
        lay.addWidget(box)
        self.refresh_secret_status()

        # --- Emulador Android
        box, f = section("Emulador Android y ADB")
        self.emu_form = f
        self.device_backend = QComboBox()
        self.device_backend.addItem("Simulador interno (sin emulador)", ("mock", s.emulator))
        self.device_backend.addItem("BlueStacks 5 por ADB", ("adb", "bluestacks"))
        self.device_backend.addItem("LDPlayer 9 por ADB", ("adb", "ldplayer"))
        current = 0 if s.device_backend == "mock" else (2 if s.emulator == "ldplayer" else 1)
        self.device_backend.setCurrentIndex(current)
        self.bs_dir = QLineEdit(s.bluestacks_install_dir)
        self.bs_dir.setPlaceholderText("Vacío = detección automática (C:\\Program Files\\BlueStacks_nxt)")
        self.bs_conf = QLineEdit(s.bluestacks_conf_path)
        self.bs_conf.setPlaceholderText("Vacío = C:\\ProgramData\\BlueStacks_nxt\\bluestacks.conf")
        self.ld_dir = QLineEdit(s.ldplayer_install_dir)
        self.ld_dir.setPlaceholderText("Vacío = detección automática (C:\\LDPlayer\\LDPlayer9)")
        self.emu_instance = QComboBox()
        self.emu_instance.setEditable(True)
        self.emu_instance.setCurrentText(s.emulator_instance)
        detect = QPushButton("Detectar instalación e instancias")
        detect.clicked.connect(self.detect_emulator)
        self.emu_info = QLabel("")
        self.emu_info.setWordWrap(True)
        self.emu_hint = QLabel("")
        self.emu_hint.setObjectName("muted")
        self.emu_hint.setWordWrap(True)
        self.adb_path = QLineEdit(s.adb_path)
        self.adb_path.setPlaceholderText("Vacío = adb del emulador o del PATH")
        self.adb_serial = QLineEdit(s.adb_serial)
        self.adb_serial.setPlaceholderText("Vacío = puerto de la instancia (p. ej. emulator-5554 o 127.0.0.1:5555)")
        self.account_key = QLineEdit(s.account_key)
        f.addRow("Dispositivo", self.device_backend)
        f.addRow("", self.emu_hint)
        f.addRow("Carpeta de BlueStacks", self.bs_dir)
        f.addRow("bluestacks.conf", self.bs_conf)
        f.addRow("Carpeta de LDPlayer", self.ld_dir)
        f.addRow("Instancia", self.emu_instance)
        f.addRow("", detect)
        f.addRow("", self.emu_info)
        f.addRow("Ruta de adb", self.adb_path)
        f.addRow("Serial ADB", self.adb_serial)
        f.addRow("Cuenta del juego (clave de bloqueo)", self.account_key)
        self.device_backend.currentIndexChanged.connect(self._update_emulator_rows)
        self._update_emulator_rows()
        lay.addWidget(box)

        # --- OpenAI y presupuesto
        box, f = section("OpenAI y presupuesto")
        self.vision_backend = QComboBox()
        for value, text in (("auto", "Automático (si hay clave)"), ("openai", "OpenAI"), ("disabled", "Desactivado")):
            self.vision_backend.addItem(text, value)
        self.vision_backend.setCurrentIndex(["auto", "openai", "disabled"].index(s.vision_backend))
        self.model = QLineEdit(s.openai_model)
        self.max_cost = dspin(s.max_ai_cost_per_order_usd, 100, 3, 0.05)
        self.daily_budget = dspin(s.daily_ai_budget_usd, 10000, 2, 1)
        self.max_requests = ispin(s.max_ai_requests_per_order, 1000)
        self.min_conf = dspin(s.ai_min_confidence, 1, 2, 0.05)
        self.verify_ai = QCheckBox("Confirmar el resultado también con IA (verificación independiente)")
        self.verify_ai.setChecked(s.verify_result_with_ai)
        f.addRow("Interpretación visual", self.vision_backend)
        f.addRow("Modelo", self.model)
        f.addRow("Costo máx. por pedido (USD)", self.max_cost)
        f.addRow("Presupuesto diario (USD)", self.daily_budget)
        f.addRow("Solicitudes IA máx. por pedido", self.max_requests)
        f.addRow("Confianza mínima", self.min_conf)
        f.addRow("", self.verify_ai)
        lay.addWidget(box)

        # --- Modo y límites
        box, f = section("Modo de operación y límites")
        self.mode = QComboBox()
        for mode, text in MODE_LABELS.items():
            self.mode.addItem(text, mode.value)
        self.mode.setCurrentIndex(list(MODE_LABELS).index(s.automation_mode))
        self.workflow = QLineEdit(s.workflow_id)
        self.friend_workflow = QLineEdit(s.friend_workflow_id)
        self.max_actions = ispin(s.max_actions_per_order, 1000)
        self.max_duration = dspin(s.max_order_duration_seconds, 7200, 0, 30)
        self.retries = ispin(s.max_step_retries, 10)
        self.consecutive = ispin(s.max_consecutive_errors, 50)
        self.approval_timeout = dspin(s.approval_timeout_seconds, 86400, 0, 60)
        f.addRow("Modo", self.mode)
        f.addRow("Flujo de regalo", self.workflow)
        f.addRow("Flujo de solicitud de amistad", self.friend_workflow)
        f.addRow("Acciones máx. por pedido", self.max_actions)
        f.addRow("Duración máx. por pedido (s)", self.max_duration)
        f.addRow("Reintentos por paso", self.retries)
        f.addRow("Errores consecutivos máx.", self.consecutive)
        f.addRow("Tiempo de espera de aprobación (s)", self.approval_timeout)
        lay.addWidget(box)

        # --- 24/7 y recuperación
        box, f = section("Funcionamiento 24/7 y recuperación")
        self.autostart = QCheckBox("Iniciar con Windows (minimizado en la bandeja)")
        self.autostart.setChecked(autostart_enabled() or s.autostart)
        self.prevent_sleep = QCheckBox("Impedir la suspensión del equipo mientras el agente funciona (consentimiento)")
        self.prevent_sleep.setChecked(s.prevent_sleep)
        self.allow_restart = QCheckBox("Permitir reinicio controlado del emulador cuando sea seguro")
        self.allow_restart.setChecked(s.allow_emulator_restart)
        self.freeze = dspin(s.freeze_threshold_seconds, 3600, 0, 10)
        self.boot_timeout = dspin(s.emulator_boot_timeout_seconds, 3600, 0, 10)
        self.retention = ispin(s.evidence_retention_days, 3650)
        clear_emergency = QPushButton("Liberar parada de emergencia")
        clear_emergency.clicked.connect(self.clear_emergency)
        f.addRow("", self.autostart)
        f.addRow("", self.prevent_sleep)
        f.addRow("", self.allow_restart)
        f.addRow("Umbral de congelamiento (s)", self.freeze)
        f.addRow("Tiempo de arranque del emulador (s)", self.boot_timeout)
        f.addRow("Retención de evidencias (días)", self.retention)
        f.addRow("Emergencia", clear_emergency)
        lay.addWidget(box)

        save = QPushButton("Guardar configuración")
        save.setObjectName("primary")
        save.clicked.connect(self.save)
        lay.addWidget(save)
        if manager.overridden:
            note = QLabel("Valores definidos por variables de entorno (tienen prioridad): "
                          + ", ".join(sorted(manager.overridden)))
            note.setObjectName("muted")
            note.setWordWrap(True)
            lay.addWidget(note)
        lay.addStretch(1)

    # ------------------------------------------------------------- acciones
    def collect(self) -> AgentSettings:
        data = self.s.model_dump()
        data.update(
            api_url=self.api_url.text(), agent_name=self.agent_name.text().strip(),
            emulator=self.selected_emulator(), ldplayer_install_dir=self.ld_dir.text().strip(),
            openapi_contract_path=self.contract.text().strip(), poll_interval_seconds=self.poll.value(),
            heartbeat_interval_seconds=self.heartbeat.value(), device_backend=self.device_backend.currentData()[0],
            bluestacks_install_dir=self.bs_dir.text().strip(), bluestacks_conf_path=self.bs_conf.text().strip(),
            emulator_instance=self.emu_instance.currentText().strip(), adb_path=self.adb_path.text().strip(),
            adb_serial=self.adb_serial.text().strip(), account_key=self.account_key.text().strip() or "default",
            vision_backend=self.vision_backend.currentData(), openai_model=self.model.text().strip(),
            max_ai_cost_per_order_usd=self.max_cost.value(), daily_ai_budget_usd=self.daily_budget.value(),
            max_ai_requests_per_order=self.max_requests.value(), ai_min_confidence=self.min_conf.value(),
            verify_result_with_ai=self.verify_ai.isChecked(), automation_mode=self.mode.currentData(),
            workflow_id=self.workflow.text().strip(), friend_workflow_id=self.friend_workflow.text().strip(), max_actions_per_order=self.max_actions.value(),
            max_order_duration_seconds=self.max_duration.value(), max_step_retries=self.retries.value(),
            max_consecutive_errors=self.consecutive.value(), approval_timeout_seconds=self.approval_timeout.value(),
            autostart=self.autostart.isChecked(), prevent_sleep=self.prevent_sleep.isChecked(),
            allow_emulator_restart=self.allow_restart.isChecked(), freeze_threshold_seconds=self.freeze.value(),
            emulator_boot_timeout_seconds=self.boot_timeout.value(), evidence_retention_days=self.retention.value(),
        )
        return AgentSettings.model_validate(data)

    def save(self) -> None:
        try:
            settings = self.collect()
        except Exception as exc:
            QMessageBox.critical(self, "Configuración inválida", str(exc))
            return
        if settings.automation_mode == AutomationMode.AUTHORIZED_PRODUCTION and \
                QMessageBox.question(self, "Producción autorizada",
                                     "Este modo solo ejecuta entregas reales con un flujo cuya autorización fue "
                                     "validada y cuando DiamantesExpress está en modo production. ¿Confirmar?") != QMessageBox.Yes:
            return
        self.manager.save(settings)
        try:
            set_autostart(settings.autostart)
        except OSError as exc:
            QMessageBox.warning(self, "Inicio automático", f"No se pudo configurar: {exc}")
        self.s = settings
        if self.on_saved:
            self.on_saved()

    def refresh_secret_status(self) -> None:
        secrets = self.manager.secrets
        self.token_status.setText("✓ configurado" if secrets.has("agent_token") else "✗ falta")
        self.openai_status.setText("✓ configurada" if secrets.has("openai_api_key") else "✗ falta")

    def save_secret(self, name: str, field: QLineEdit) -> None:
        value = field.text().strip()
        if not value:
            return
        if name == "agent_token" and not value.startswith("dxa_"):
            if QMessageBox.question(self, "Token", "Los tokens de DiamantesExpress empiezan con dxa_. "
                                    "¿Guardar de todos modos?") != QMessageBox.Yes:
                return
        protected = self.manager.secrets.set(name, value)
        field.clear()
        self.refresh_secret_status()
        if not protected:
            QMessageBox.warning(self, "Secretos", "El almacén seguro de Windows no está disponible: el secreto solo "
                                "se mantendrá en memoria hasta cerrar la aplicación.")

    def _api_client(self):
        from dxagent.api.client import HorizonsApiClient
        from dxagent.api.contract import ApiContract
        s = self.collect()
        contract = ApiContract.load(s.openapi_contract_path or None, s.route_overrides)
        return HorizonsApiClient(s.api_url, self.manager.secrets.get("agent_token"), contract,
                                 agent_name=s.agent_name, timeout=s.api_timeout_seconds,
                                 allow_insecure_localhost=s.allow_insecure_http_localhost)

    def test_api(self) -> None:
        def work():
            async def go():
                client = self._api_client()
                try:
                    return await client.heartbeat()
                finally:
                    await client.aclose()
            return asyncio.run(go())

        def done(result, error):
            if error:
                QMessageBox.warning(self, "Conexión", f"Fallo: {type(error).__name__}: {error}")
            else:
                QMessageBox.information(self, "Conexión",
                                        f"API correcta.\nModo del servidor: {result.mode}\nEn pausa: {result.paused}\n"
                                        f"Acepta trabajo: {result.accepted}\nLease: {result.lease_seconds}s")
        run_in_background(work, done)

    def verify_openai(self) -> None:
        key = self.manager.secrets.get("openai_api_key")
        model = self.model.text().strip()
        if not key:
            QMessageBox.warning(self, "OpenAI", "No hay clave configurada.")
            return

        def work():
            from openai import OpenAI
            return OpenAI(api_key=key, timeout=20).models.retrieve(model).id  # no consume tokens

        def done(result, error):
            if error:
                QMessageBox.warning(self, "OpenAI", f"Fallo: {type(error).__name__}")
            else:
                QMessageBox.information(self, "OpenAI", f"Clave válida. Modelo disponible: {result}")
        run_in_background(work, done)

    def selected_emulator(self) -> str:
        return self.device_backend.currentData()[1]

    def _update_emulator_rows(self) -> None:
        backend, emulator = self.device_backend.currentData()
        uses_adb = backend == "adb"
        for widget, visible in ((self.bs_dir, uses_adb and emulator == "bluestacks"),
                                (self.bs_conf, uses_adb and emulator == "bluestacks"),
                                (self.ld_dir, uses_adb and emulator == "ldplayer")):
            self.emu_form.setRowVisible(widget, visible)
        for widget in (self.emu_instance, self.adb_path, self.adb_serial, self.emu_info):
            self.emu_form.setRowVisible(widget, uses_adb)
        hints = {
            "mock": "Pruebas sin emulador: el agente usa una app Android simulada interna.",
            "bluestacks": "BlueStacks 5: active Configuración → Avanzado → Android Debug Bridge (ADB). "
                          "Instancia = nombre interno (p. ej. Pie64 o Pie64_1).",
            "ldplayer": "LDPlayer 9: active Configuración → Otros ajustes → Depuración ADB → Abrir conexión local. "
                        "Instancia = índice (0 = principal) o nombre; el serial suele ser emulator-5554.",
        }
        self.emu_hint.setText(hints["mock" if not uses_adb else emulator])

    def detect_emulator(self) -> None:
        from dxagent.android.emulator import EMULATORS, build_emulator
        if self.device_backend.currentData()[0] != "adb":
            self.emu_info.setText("Seleccione BlueStacks o LDPlayer para detectar.")
            return
        try:
            manager = build_emulator(self.collect())
            st = manager.status()
        except Exception as exc:
            self.emu_info.setText(f"Error: {exc}")
            return
        current = self.emu_instance.currentText()
        self.emu_instance.clear()
        for name in st["instances"]:
            self.emu_instance.addItem(name)
        self.emu_instance.setCurrentText(current or st.get("selected_instance") or "")
        instances = ", ".join(f"{k} ({v['display_name'] or 'sin nombre'}, puerto {v['adb_port']})"
                              for k, v in st["instances"].items()) or "ninguna"
        adb_state = {True: "sí", False: "no", None: "verificar en el emulador"}[st["adb_enabled"]]
        conf = f" · Configuración: {st['conf_path'] or '—'}" if "conf_path" in st else ""
        self.emu_info.setText(
            f"{EMULATORS[manager.name]} · Instalado: {'sí' if st['installed'] else 'no'} · "
            f"Carpeta: {st['install_dir'] or '—'}{conf} · ADB: {st['adb_path'] or 'no encontrado'} · "
            f"ADB habilitado: {adb_state} · En ejecución: {'sí' if st['running'] else 'no'}<br>Instancias: {instances}")
        target = self.ld_dir if manager.name == "ldplayer" else self.bs_dir
        if st["install_dir"] and not target.text():
            target.setText(st["install_dir"])

    def clear_emergency(self) -> None:
        flag = paths.emergency_flag_path()
        if flag.exists() and QMessageBox.question(self, "Emergencia", "¿Liberar la parada de emergencia? El agente "
                                                  "quedará en pausa hasta que pulse Iniciar.") == QMessageBox.Yes:
            flag.unlink()
            from dxagent.database import LocalDatabase
            LocalDatabase(paths.db_path()).push_command("clear_emergency", issued_by="ui")

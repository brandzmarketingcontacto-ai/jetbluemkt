"""Ventana principal de DiamantesExpress Agent (PySide6).

La ventana solo LEE el estado (en un hilo aparte) y ESCRIBE comandos en SQLite. El agente
corre en otro proceso (`service`), por lo que cerrar o congelar la ventana no detiene la
ejecución, y la ejecución nunca bloquea la ventana.
"""

from __future__ import annotations

import getpass
import json
import time
from pathlib import Path
from typing import Any

from PySide6.QtCore import Qt, QTimer
from PySide6.QtGui import QAction, QColor, QIcon, QPainter, QPixmap, QPolygonF
from PySide6.QtCore import QPointF
from PySide6.QtWidgets import (QAbstractItemView, QApplication, QFrame, QGridLayout, QHBoxLayout, QHeaderView,
                               QLabel, QLineEdit, QListWidget, QMainWindow, QMenu, QMessageBox, QPushButton,
                               QSystemTrayIcon, QTableWidget, QTableWidgetItem, QTabWidget, QVBoxLayout,
                               QWidget, QComboBox, QPlainTextEdit)

from dxagent import APP_NAME, DEVELOPER, __version__, paths
from dxagent.database import LocalDatabase
from dxagent.engine.approval import ApprovalManager
from dxagent.settings import SettingsManager
from dxagent.ui.data_worker import StatusPoller
from dxagent.ui.settings_page import SettingsPage
from dxagent.ui.theme import COLORS, JOB_LABELS, PHASE_LABELS, QSS, STATE_COLORS, STATE_LABELS
from dxagent.winplatform import executable_command, spawn_detached


def app_icon() -> QIcon:
    pix = QPixmap(64, 64)
    pix.fill(Qt.transparent)
    p = QPainter(pix)
    p.setRenderHint(QPainter.Antialiasing)
    p.setBrush(QColor(COLORS["accent"]))
    p.setPen(Qt.NoPen)
    p.drawPolygon(QPolygonF([QPointF(32, 4), QPointF(60, 26), QPointF(32, 60), QPointF(4, 26)]))
    p.setBrush(QColor("#9fb9ff"))
    p.drawPolygon(QPolygonF([QPointF(32, 4), QPointF(46, 26), QPointF(18, 26)]))
    p.end()
    return QIcon(pix)


def card() -> QFrame:
    frame = QFrame()
    frame.setObjectName("card")
    return frame


def label(text: str = "", name: str | None = None) -> QLabel:
    lbl = QLabel(text)
    if name:
        lbl.setObjectName(name)
    lbl.setTextInteractionFlags(Qt.TextSelectableByMouse)
    return lbl


def fmt_duration(seconds: float | None) -> str:
    if not seconds:
        return "—"
    seconds = int(seconds)
    return f"{seconds // 60}m {seconds % 60:02d}s" if seconds >= 60 else f"{seconds}s"


def fmt_time(iso: str | None) -> str:
    if not iso:
        return "—"
    from datetime import datetime
    try:
        return datetime.fromisoformat(iso).astimezone().strftime("%d/%m %H:%M:%S")
    except ValueError:
        return iso


class Pill(QLabel):
    def __init__(self, title: str):
        super().__init__()
        self.title = title
        self.setObjectName("pill")
        self.set("—", COLORS["muted"])

    def set(self, text: str, color: str) -> None:
        self.setText(f'<span style="color:{color}">●</span> {self.title}: {text}')


class MetricCard(QFrame):
    def __init__(self, title: str):
        super().__init__()
        self.setObjectName("card")
        lay = QVBoxLayout(self)
        lay.setContentsMargins(16, 12, 16, 12)
        self.value = label("0", "metricValue")
        lay.addWidget(self.value)
        lay.addWidget(label(title, "metricLabel"))


class MainWindow(QMainWindow):
    def __init__(self, settings_manager: SettingsManager | None = None, db_path: str | None = None,
                 start_service: bool = True):
        super().__init__()
        self.settings_manager = settings_manager or SettingsManager()
        self.db_path = db_path or str(paths.db_path())
        self.db = LocalDatabase(self.db_path)
        self.approvals = ApprovalManager(self.db)
        self.start_service_enabled = start_service
        self.service_desired = True
        self.last_service_spawn = 0.0
        self.last_snapshot: dict[str, Any] = {}
        self.current_approval: dict | None = None
        self.really_quit = False
        self.restart_pending = False

        self.setWindowTitle(f"{APP_NAME} {__version__} · {DEVELOPER}")
        self.setWindowIcon(app_icon())
        self.resize(1280, 820)
        self.setStyleSheet(QSS)

        root = QWidget()
        root.setObjectName("root")
        self.setCentralWidget(root)
        outer = QVBoxLayout(root)
        outer.setContentsMargins(0, 0, 0, 0)
        outer.setSpacing(0)
        outer.addWidget(self._build_topbar())
        self.tabs = QTabWidget()
        self.tabs.addTab(self._build_dashboard(), "Panel")
        self.tabs.addTab(self._build_history(), "Historial")
        self.tabs.addTab(self._build_usage(), "Consumo IA")
        self.tabs.addTab(self._build_logs(), "Registros")
        self.settings_page = SettingsPage(self.settings_manager, on_saved=self._settings_saved)
        self.tabs.addTab(self.settings_page, "Configuración")
        body = QWidget()
        body_lay = QVBoxLayout(body)
        body_lay.setContentsMargins(18, 12, 18, 18)
        body_lay.addWidget(self.tabs)
        outer.addWidget(body)

        self._build_tray()
        self.poller = StatusPoller(self.db_path)
        self.poller.updated.connect(self.on_snapshot)
        self.poller.start()
        self.supervisor_timer = QTimer(self)
        self.supervisor_timer.setInterval(5000)
        self.supervisor_timer.timeout.connect(self.supervise_service)
        self.supervisor_timer.start()
        if start_service:
            QTimer.singleShot(500, self.supervise_service)

    # ------------------------------------------------------------- top bar
    def _build_topbar(self) -> QWidget:
        bar = QFrame()
        bar.setObjectName("topbar")
        lay = QHBoxLayout(bar)
        lay.setContentsMargins(18, 10, 18, 10)
        icon = QLabel()
        icon.setPixmap(app_icon().pixmap(26, 26))
        lay.addWidget(icon)
        lay.addWidget(label("DiamantesExpress Agent", "brand"))
        lay.addSpacing(16)
        self.pill_agent = Pill("Agente")
        self.pill_api = Pill("API")
        self.pill_android = Pill("Emulador")
        self.pill_mode = Pill("Modo")
        for pill in (self.pill_agent, self.pill_api, self.pill_android, self.pill_mode):
            lay.addWidget(pill)
        lay.addStretch(1)
        self.btn_start = QPushButton("Iniciar")
        self.btn_start.setObjectName("primary")
        self.btn_pause = QPushButton("Pausar")
        self.btn_stop = QPushButton("Detener")
        self.btn_emergency = QPushButton("EMERGENCIA")
        self.btn_emergency.setObjectName("danger")
        self.btn_emergency.setToolTip("Detiene inmediatamente toda acción sobre Android. Requiere liberación manual.")
        self.btn_start.clicked.connect(self.cmd_start)
        self.btn_pause.clicked.connect(lambda: self.send_command("pause"))
        self.btn_stop.clicked.connect(self.cmd_stop)
        self.btn_emergency.clicked.connect(self.cmd_emergency)
        for btn in (self.btn_start, self.btn_pause, self.btn_stop, self.btn_emergency):
            lay.addWidget(btn)
        return bar

    # ----------------------------------------------------------- dashboard
    def _build_dashboard(self) -> QWidget:
        page = QWidget()
        lay = QVBoxLayout(page)
        lay.setContentsMargins(0, 8, 0, 0)
        lay.setSpacing(12)
        metrics = QGridLayout()
        metrics.setSpacing(12)
        titles = ["Procesados hoy", "Modo del servidor", "Fallidos / revisión", "Saldo de diamantes",
                  "Tiempo promedio", "Tokens IA hoy", "Costo IA hoy (USD)"]
        self.metrics = [MetricCard(t) for t in titles]
        for i, m in enumerate(self.metrics):
            metrics.addWidget(m, 0, i)
        lay.addLayout(metrics)

        row = QHBoxLayout()
        row.setSpacing(12)
        # Ejecución
        exec_card = card()
        el = QGridLayout(exec_card)
        el.setContentsMargins(16, 14, 16, 14)
        el.addWidget(label("Pedido actual", "sectionTitle"), 0, 0, 1, 2)
        self.exec_fields: dict[str, QLabel] = {}
        for i, (key, title) in enumerate([("order", "Pedido"), ("status", "Estado"), ("recipient", "Destinatario"),
                                          ("product", "Producto"), ("step", "Paso"), ("elapsed", "Tiempo"),
                                          ("actions", "Acciones")], start=1):
            el.addWidget(label(title, "muted"), i, 0)
            value = label("—")
            self.exec_fields[key] = value
            el.addWidget(value, i, 1)
        el.setRowStretch(9, 1)
        row.addWidget(exec_card, 2)

        # Monitor Android
        mon = card()
        ml = QHBoxLayout(mon)
        ml.setContentsMargins(16, 14, 16, 14)
        self.screen_label = QLabel("Sin captura")
        self.screen_label.setAlignment(Qt.AlignCenter)
        self.screen_label.setFixedSize(220, 390)
        self.screen_label.setStyleSheet(f"background:{COLORS['bg']}; border-radius:10px; color:{COLORS['muted']}")
        ml.addWidget(self.screen_label)
        info = QVBoxLayout()
        info.addWidget(label("Monitor Android", "sectionTitle"))
        self.mon_screen = label("Pantalla: —")
        self.mon_ai = label("Estado IA: —")
        self.mon_ai.setWordWrap(True)
        self.mon_suggestion = label("Acción sugerida: —")
        self.mon_suggestion.setWordWrap(True)
        for w in (self.mon_screen, self.mon_ai, self.mon_suggestion):
            info.addWidget(w)
        info.addSpacing(10)
        self.approval_box = card()
        al = QVBoxLayout(self.approval_box)
        al.addWidget(label("Aprobación requerida", "sectionTitle"))
        self.approval_text = label("")
        self.approval_text.setWordWrap(True)
        al.addWidget(self.approval_text)
        self.operator_name = QLineEdit()
        self.operator_name.setPlaceholderText("Nombre del operador que aprueba")
        al.addWidget(self.operator_name)
        btns = QHBoxLayout()
        self.btn_approve = QPushButton("Aprobar envío")
        self.btn_approve.setObjectName("ok")
        self.btn_reject = QPushButton("Rechazar")
        self.btn_approve.clicked.connect(lambda: self.decide(True))
        self.btn_reject.clicked.connect(lambda: self.decide(False))
        btns.addWidget(self.btn_approve)
        btns.addWidget(self.btn_reject)
        al.addLayout(btns)
        self.approval_box.setVisible(False)
        info.addWidget(self.approval_box)
        info.addStretch(1)
        ml.addLayout(info, 1)
        row.addWidget(mon, 3)
        lay.addLayout(row)

        act = card()
        al2 = QVBoxLayout(act)
        al2.setContentsMargins(16, 12, 16, 12)
        al2.addWidget(label("Actividad reciente", "sectionTitle"))
        self.events = QListWidget()
        self.events.setMinimumHeight(140)
        al2.addWidget(self.events)
        lay.addWidget(act, 1)
        return page

    def _table(self, headers: list[str]) -> QTableWidget:
        table = QTableWidget(0, len(headers))
        table.setHorizontalHeaderLabels(headers)
        table.verticalHeader().setVisible(False)
        table.setEditTriggers(QAbstractItemView.NoEditTriggers)
        table.setSelectionBehavior(QAbstractItemView.SelectRows)
        table.horizontalHeader().setSectionResizeMode(QHeaderView.Stretch)
        return table

    def _build_history(self) -> QWidget:
        page = QWidget()
        lay = QVBoxLayout(page)
        lay.setContentsMargins(0, 8, 0, 0)
        self.history = self._table(["Pedido", "Trabajo", "Fase", "Cantidad", "Hora", "Estado", "Código",
                                    "Amistad", "Tokens", "Costo USD", "Duración", "Aprobado por"])
        lay.addWidget(self.history)
        return page

    def _build_usage(self) -> QWidget:
        page = QWidget()
        lay = QVBoxLayout(page)
        lay.setContentsMargins(0, 8, 0, 0)
        summary = card()
        sl = QHBoxLayout(summary)
        sl.setContentsMargins(16, 12, 16, 12)
        self.usage_summary = label("—")
        self.usage_summary.setWordWrap(True)
        sl.addWidget(self.usage_summary)
        lay.addWidget(summary)
        self.usage_table = self._table(["Hora", "Pedido", "Modelo", "Entrada", "En caché", "Salida", "Total",
                                        "Latencia ms", "Costo USD", "Estado", "Sincronizado"])
        lay.addWidget(self.usage_table, 1)
        return page

    def _build_logs(self) -> QWidget:
        page = QWidget()
        lay = QVBoxLayout(page)
        lay.setContentsMargins(0, 8, 0, 0)
        controls = QHBoxLayout()
        self.log_file = QComboBox()
        self.log_file.addItems(["service", "ui"])
        self.log_level = QComboBox()
        self.log_level.addItems(["Todos", "INFO", "WARNING", "ERROR"])
        self.log_filter = QLineEdit()
        self.log_filter.setPlaceholderText("Filtrar texto (pedido, error, componente)…")
        refresh = QPushButton("Actualizar")
        refresh.clicked.connect(self.load_logs)
        open_dir = QPushButton("Abrir carpeta")
        open_dir.clicked.connect(lambda: self._open_path(paths.logs_dir()))
        for w in (self.log_file, self.log_level, self.log_filter, refresh, open_dir):
            controls.addWidget(w)
        lay.addLayout(controls)
        self.log_view = QPlainTextEdit()
        self.log_view.setReadOnly(True)
        self.log_view.setMaximumBlockCount(5000)
        lay.addWidget(self.log_view, 1)
        return page

    def _build_tray(self) -> None:
        self.tray = None
        if not QSystemTrayIcon.isSystemTrayAvailable():
            return
        self.tray = QSystemTrayIcon(app_icon(), self)
        menu = QMenu()
        for text, slot in (("Mostrar", self.show_normal), ("Iniciar", self.cmd_start),
                           ("Pausar", lambda: self.send_command("pause")), ("Salir de la ventana", self.quit_ui)):
            action = QAction(text, self)
            action.triggered.connect(slot)
            menu.addAction(action)
        self.tray.setContextMenu(menu)
        self.tray.setToolTip(APP_NAME)
        self.tray.activated.connect(lambda reason: self.show_normal() if reason == QSystemTrayIcon.Trigger else None)
        self.tray.show()

    # ---------------------------------------------------------- comandos
    def operator(self) -> str:
        return f"{self.operator_name.text().strip() or 'operador'} ({getpass.getuser()})"

    def send_command(self, command: str) -> None:
        self.db.push_command(command, issued_by=self.operator())

    def cmd_start(self) -> None:
        self.service_desired = True
        if not self.last_snapshot.get("service_alive"):
            self.spawn_service()
        self.send_command("start")

    def cmd_stop(self) -> None:
        if QMessageBox.question(self, "Detener agente",
                                "El agente terminará el pedido en curso de forma segura y se detendrá. ¿Continuar?") \
                == QMessageBox.Yes:
            self.service_desired = False
            self.send_command("stop")

    def cmd_emergency(self) -> None:
        # Doble vía: archivo bandera (lo ve el motor antes de cada acción) + comando.
        paths.emergency_flag_path().write_text(f"{time.time()} {self.operator()}", encoding="utf-8")
        self.send_command("emergency_stop")
        QMessageBox.warning(self, "Parada de emergencia",
                            "Se detuvo toda acción sobre Android. Para reanudar, use 'Liberar emergencia' en "
                            "Configuración y luego Iniciar.")

    def decide(self, approved: bool) -> None:
        if not self.current_approval:
            return
        if not self.operator_name.text().strip():
            QMessageBox.warning(self, "Aprobación", "Ingrese el nombre del operador para registrar quién aprueba.")
            return
        self.approvals.decide(self.current_approval["approval_id"], approved, self.operator())
        self.approval_box.setVisible(False)
        self.current_approval = None

    def spawn_service(self) -> None:
        if not self.start_service_enabled or time.time() - self.last_service_spawn < 20:
            return
        self.last_service_spawn = time.time()
        spawn_detached(executable_command("service"))

    def supervise_service(self) -> None:
        """Si el servicio debía estar activo y no reporta estado, se relanza (autorecuperación)."""
        if self.service_desired and self.last_snapshot and not self.last_snapshot.get("service_alive"):
            if not paths.emergency_flag_path().exists():
                self.spawn_service()

    def _settings_saved(self) -> None:
        QMessageBox.information(self, "Configuración", "Configuración guardada. El servicio se reiniciará para "
                                "aplicar los cambios (el pedido en curso termina antes).")
        self.last_service_spawn = 0
        if self.last_snapshot.get("service_alive"):
            # Se relanza cuando el servicio actual termine de detenerse (ver on_snapshot).
            self.restart_pending = True
            self.send_command("stop")
        elif self.service_desired:
            self.spawn_service()

    # ------------------------------------------------------------ refresco
    def on_snapshot(self, snap: dict) -> None:
        if "error" in snap:
            return
        self.last_snapshot = snap
        if self.restart_pending and not snap["service_alive"]:
            self.restart_pending = False
            self.last_service_spawn = 0
            self.spawn_service()
            self.send_command("start" if self.service_desired else "pause")
        agent = snap["agent"] or {}
        state = agent.get("state", "SIN SERVICIO") if snap["service_alive"] else "SIN SERVICIO"
        self.pill_agent.set(STATE_LABELS.get(state, state), STATE_COLORS.get(state, COLORS["muted"]))
        if snap["service_alive"]:
            self.pill_api.set("Conectada" if agent.get("api_online") else "Sin conexión",
                              COLORS["ok"] if agent.get("api_online") else COLORS["error"])
            emu = agent.get("emulator") or "Android"
            self.pill_android.set(f"{emu} · " + ("disponible" if agent.get("device_ok") else "no disponible"),
                                  COLORS["ok"] if agent.get("device_ok") else COLORS["warn"])
            self.pill_mode.set(f"local {agent.get('mode', '—')}", COLORS["accent"])
            if agent.get("state_reason"):
                self.pill_agent.setToolTip(agent["state_reason"])
        else:
            for pill in (self.pill_api, self.pill_android):
                pill.set("—", COLORS["muted"])
        self.btn_pause.setEnabled(state == "RUNNING")
        self.btn_start.setEnabled(state not in ("RUNNING", "EMERGENCY_STOP"))

        jt, ut = snap["jobs_today"], snap["usage_today"]
        server = (agent.get("server") or {}) if snap["service_alive"] else {}
        server_txt = "—"
        if server.get("mode"):
            server_txt = server["mode"] + (" · pausa" if server.get("paused") else "")
        balance = agent.get("diamond_balance") if snap["service_alive"] else None
        sender = (agent.get("sender_account") or {}) if snap["service_alive"] else {}
        self.metrics[3].setToolTip(f"Cuenta emisora: {sender.get('name') or '—'} (ID {sender.get('id') or '—'})")
        values = [jt["completed"], server_txt, f"{jt['failed']} / {jt['needs_review']}",
                  "—" if balance is None else f"{int(balance):,}", fmt_duration(jt["avg_duration_s"]), f"{int(ut.get('total_tokens') or 0):,}",
                  f"{float(ut.get('cost_usd') or 0):.4f}"]
        for metric, value in zip(self.metrics, values):
            metric.value.setText(str(value))

        self._update_current(snap.get("current_job"))
        self._update_approval(snap["approvals"])
        self.events.clear()
        for ev in snap["events"][:100]:
            self.events.addItem(f"{fmt_time(ev['ts'])}  [{ev['level']}]  {ev['message']}")
        self._fill_history(snap["jobs"])
        self._fill_usage(snap["usage"], ut, snap["outbox_pending"])

    def _update_current(self, cur: dict | None) -> None:
        if not cur:
            for value in self.exec_fields.values():
                value.setText("—")
            self.exec_fields["status"].setText("Sin pedido en curso")
            return
        self.exec_fields["order"].setText(cur.get("order_id", "—"))
        status = cur.get("status", "running")
        self.exec_fields["status"].setText(JOB_LABELS.get(status, status))
        self.exec_fields["recipient"].setText(cur.get("recipient", "—"))
        self.exec_fields["product"].setText(cur.get("product", "—"))
        self.exec_fields["step"].setText(cur.get("step", "—"))
        self.exec_fields["elapsed"].setText(fmt_duration(time.time() - cur.get("started_at", time.time())))
        self.exec_fields["actions"].setText(str(cur.get("actions", 0)))
        self.mon_screen.setText(f"Pantalla: {cur.get('screen', '—')} ({cur.get('screen_method', '—')})")
        self.mon_ai.setText(f"Estado IA: {cur.get('ai_state') or '—'}  {cur.get('ai_reason') or ''}")
        self.mon_suggestion.setText(f"Acción sugerida: {cur.get('ai_suggestion') or '—'}")
        live = paths.data_dir() / "live_screen.png"
        if live.exists():
            pix = QPixmap(str(live))
            if not pix.isNull():
                self.screen_label.setPixmap(pix.scaled(self.screen_label.size(), Qt.KeepAspectRatio,
                                                       Qt.SmoothTransformation))

    def _update_approval(self, approvals: list[dict]) -> None:
        if not approvals:
            self.approval_box.setVisible(False)
            self.current_approval = None
            return
        first = approvals[0]
        if self.current_approval and self.current_approval["approval_id"] == first["approval_id"]:
            return
        self.current_approval = first
        summary = json.loads(first["summary_json"])
        self.approval_text.setText(
            f"Pedido <b>{summary.get('order_id')}</b><br>Destinatario: <b>{summary.get('recipient')}</b><br>"
            + ("Acción: <b>enviar solicitud de amistad</b><br>" if summary.get("phase") == "friend_request" else
               f"Producto: <b>{summary.get('product_label')}</b> ({summary.get('product_code')})<br>"
               f"Cantidad: <b>{summary.get('quantity')}</b> · costo {summary.get('total_cost') or '—'} diamantes"
               f" · envío {summary.get('chunk')}<br>")
            + f"Datos leídos en pantalla: {summary.get('fields')}")
        self.approval_box.setVisible(True)
        if self.tray:
            self.tray.showMessage(APP_NAME, f"Aprobación requerida para {summary.get('order_id')}")

    def _fill_history(self, jobs: list[dict]) -> None:
        self.history.setRowCount(len(jobs))
        for r, job in enumerate(jobs):
            phase = job.get("phase") or "gift"
            qty = "" if phase == "friend_request" else \
                f"{job.get('delivered_quantity') if job.get('delivered_quantity') is not None else '—'}/{job.get('quantity') or 1}"
            values = [job["order_id"], job["job_id"], PHASE_LABELS.get(phase, phase), qty, fmt_time(job["claimed_at"]),
                      JOB_LABELS.get(job["status"], job["status"]), job.get("error_code") or "",
                      job.get("friend_status") or "", str(job.get("tokens_total") or 0), f"{float(job.get('cost_usd') or 0):.5f}",
                      fmt_duration(job.get("duration_s")), job.get("approved_by") or ""]
            for c, v in enumerate(values):
                self.history.setItem(r, c, QTableWidgetItem(v))

    def _fill_usage(self, usage: list[dict], today: dict, pending: int) -> None:
        total = int(today.get("requests") or 0)
        synced = int(today.get("synced") or 0)
        self.usage_summary.setText(
            f"<b>Hoy</b> — solicitudes: {total} · entrada: {int(today.get('input_tokens') or 0):,} · "
            f"en caché: {int(today.get('cached_input_tokens') or 0):,} · salida: {int(today.get('output_tokens') or 0):,}"
            f" · total: {int(today.get('total_tokens') or 0):,} · costo: US$ {float(today.get('cost_usd') or 0):.4f}"
            f"<br><b>Sincronización con DiamantesExpress</b> — {synced}/{total} registros de hoy sincronizados · "
            f"{pending} eventos pendientes de envío")
        self.usage_table.setRowCount(len(usage))
        for r, u in enumerate(usage):
            values = [fmt_time(u["created_at"]), u.get("order_id") or "", u["model"], str(u["input_tokens"]),
                      str(u["cached_input_tokens"]), str(u["output_tokens"]), str(u["total_tokens"]),
                      str(u["latency_ms"]), f"{float(u['cost_usd']):.6f}", u["status"], "Sí" if u["synced"] else "No"]
            for c, v in enumerate(values):
                self.usage_table.setItem(r, c, QTableWidgetItem(v))

    def load_logs(self) -> None:
        file = paths.logs_dir() / f"{self.log_file.currentText()}.jsonl"
        if not file.exists():
            self.log_view.setPlainText("Aún no hay registros.")
            return
        lines = file.read_text(encoding="utf-8", errors="replace").splitlines()[-3000:]
        level = self.log_level.currentText()
        needle = self.log_filter.text().strip().lower()
        out = []
        for line in lines:
            try:
                entry = json.loads(line)
            except json.JSONDecodeError:
                continue
            if level != "Todos" and entry.get("level") != level:
                continue
            if needle and needle not in line.lower():
                continue
            extra = {k: v for k, v in entry.items() if k not in {"ts", "level", "logger", "msg"}}
            out.append(f"{fmt_time(entry.get('ts'))} {entry.get('level', ''):7} {entry.get('logger', '')}: "
                       f"{entry.get('msg', '')} {json.dumps(extra, ensure_ascii=False) if extra else ''}")
        self.log_view.setPlainText("\n".join(out[-1000:]))
        self.log_view.verticalScrollBar().setValue(self.log_view.verticalScrollBar().maximum())

    def _open_path(self, path: Path) -> None:
        from PySide6.QtCore import QUrl
        from PySide6.QtGui import QDesktopServices
        QDesktopServices.openUrl(QUrl.fromLocalFile(str(path)))

    # ----------------------------------------------------------- ventana
    def show_normal(self) -> None:
        self.showNormal()
        self.raise_()
        self.activateWindow()

    def quit_ui(self) -> None:
        """Cierra solo la ventana: el servicio sigue procesando pedidos."""
        self.really_quit = True
        self.close()

    def closeEvent(self, event) -> None:  # noqa: N802
        if self.tray and not self.really_quit:
            event.ignore()
            self.hide()
            self.tray.showMessage(APP_NAME, "El agente sigue funcionando en segundo plano.")
            return
        self.poller.stop()
        self.supervisor_timer.stop()
        if self.tray:
            self.tray.hide()
        event.accept()
        QApplication.quit()

"""La interfaz se construye, muestra el estado y escribe comandos sin bloquear (Qt offscreen)."""

import time

import pytest

pytest.importorskip("PySide6.QtWidgets")


def test_main_window_renders_and_sends_commands(qtbot_free_app):
    from dxagent import paths
    from dxagent.database import LocalDatabase
    from dxagent.ui.main_window import MainWindow

    db = LocalDatabase(paths.db_path())
    db.set_status("agent", {"state": "RUNNING", "api_online": True, "device_ok": True, "mode": "SIMULATION",
                            "server": {"mode": "simulation", "paused": False}})
    db.create_approval("apr-1", "job-1", "send", {"order_id": "DX-1", "recipient": "123456789",
                                                   "product_label": "100 Diamantes", "product_code": "DIAMANTES_100"})
    window = MainWindow(start_service=False)
    app = qtbot_free_app
    deadline = time.time() + 5
    while time.time() < deadline and not window.last_snapshot:
        app.processEvents()
        time.sleep(0.02)
    assert window.last_snapshot["service_alive"] is True
    assert "En ejecución" in window.pill_agent.text()
    assert window.metrics[1].value.text() == "simulation"
    assert window.approval_box.isVisibleTo(window)
    window.operator_name.setText("Luis")
    window.decide(True)
    assert db.get_approval("apr-1")["decided_by"].startswith("Luis")
    window.send_command("pause")
    assert db.pop_commands()[0]["command"] == "pause"
    window.really_quit = True
    window.poller.stop()
    window.supervisor_timer.stop()


@pytest.fixture
def qtbot_free_app():
    from PySide6.QtWidgets import QApplication
    return QApplication.instance() or QApplication([])


def test_detect_emulator_always_gives_feedback(qtbot_free_app, monkeypatch):
    from PySide6.QtCore import QThreadPool
    from PySide6.QtWidgets import QMessageBox

    import dxagent.android.emulator as emulator
    from dxagent.settings import SettingsManager
    from dxagent.ui.settings_page import SettingsPage

    shown = []
    monkeypatch.setattr(QMessageBox, "information", lambda *a, **k: shown.append(a[2]))
    monkeypatch.setattr(QMessageBox, "warning", lambda *a, **k: shown.append(a[2]))
    page = SettingsPage(SettingsManager())
    page.device_backend.setCurrentIndex(0)  # simulador interno: el botón se oculta y, si se pulsa, avisa
    assert not page.emu_form.isRowVisible(page.detect_btn)
    page.detect_emulator()
    assert "LDPlayer" in shown[-1]

    class FakeLD:
        name = "ldplayer"

        def status(self):
            return {"installed": True, "install_dir": r"C:\LDPlayer\LDPlayer9", "adb_path": "adb.exe",
                    "adb_enabled": None, "running": True, "selected_instance": "0",
                    "instances": {"0": {"display_name": "LDPlayer", "adb_port": 5555}}}
    monkeypatch.setattr(emulator, "build_emulator", lambda settings: FakeLD())
    page.device_backend.setCurrentIndex(2)
    assert page.emu_form.isRowVisible(page.detect_btn)
    page.detect_emulator()
    QThreadPool.globalInstance().waitForDone(5000)
    for _ in range(50):
        qtbot_free_app.processEvents()
        if "Instancias" in page.emu_info.text():
            break
    assert "0 (LDPlayer, puerto 5555)" in page.emu_info.text()
    assert page.emu_instance.currentText() == "0"
    assert page.ld_dir.text() == r"C:\LDPlayer\LDPlayer9"


def test_mouse_wheel_never_changes_settings(qtbot_free_app):
    from PySide6.QtCore import QPoint, QPointF, Qt
    from PySide6.QtGui import QWheelEvent
    from PySide6.QtWidgets import QApplication

    from dxagent.settings import SettingsManager
    from dxagent.ui.settings_page import SettingsPage

    def wheel(widget, delta):
        event = QWheelEvent(QPointF(5, 5), QPointF(5, 5), QPoint(0, 0), QPoint(0, delta), Qt.NoButton, Qt.NoModifier,
                            Qt.NoScrollPhase, False)
        QApplication.sendEvent(widget, event)

    page = SettingsPage(SettingsManager())
    page.show()
    mode, budget, requests = page.mode.currentIndex(), page.daily_budget.value(), page.max_requests.value()
    for widget in (page.mode, page.daily_budget, page.max_requests):
        widget.setFocus()
        wheel(widget, -120)
    assert page.mode.currentIndex() == mode
    assert page.daily_budget.value() == budget
    assert page.max_requests.value() == requests

    page.daily_budget.removeEventFilter(page._ignore_wheel)  # sin el filtro, Qt sí cambia el valor
    wheel(page.daily_budget, -120)
    assert page.daily_budget.value() != budget
    page.close()

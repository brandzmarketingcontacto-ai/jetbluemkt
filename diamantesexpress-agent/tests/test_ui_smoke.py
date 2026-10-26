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

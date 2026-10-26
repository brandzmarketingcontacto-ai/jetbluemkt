"""Arranque de la interfaz gráfica."""

from __future__ import annotations

import logging
import sys

from dxagent import APP_NAME, paths
from dxagent.logging_setup import setup_logging


def run_ui(minimized: bool = False, start_service: bool = True) -> int:
    from PySide6.QtWidgets import QApplication

    from dxagent.ui.main_window import MainWindow, app_icon

    setup_logging(paths.logs_dir(), "ui", logging.INFO, console=False)
    app = QApplication.instance() or QApplication(sys.argv)
    app.setApplicationName(APP_NAME)
    app.setWindowIcon(app_icon())
    app.setQuitOnLastWindowClosed(False)
    window = MainWindow(start_service=start_service)
    if minimized and window.tray:
        window.hide()
    else:
        window.show()
    return app.exec()

"""Tema oscuro minimalista (estética SaaS)."""

COLORS = {
    "bg": "#0b0d13",
    "surface": "#12151f",
    "surface2": "#181c29",
    "border": "#252a3a",
    "text": "#e6e9f2",
    "muted": "#8a91a8",
    "accent": "#5b8cff",
    "ok": "#2ecc8f",
    "warn": "#f5b544",
    "error": "#ff5d6c",
}

STATE_COLORS = {
    "RUNNING": COLORS["ok"], "PAUSED": COLORS["warn"], "STARTING": COLORS["accent"], "STOPPED": COLORS["muted"],
    "DEGRADED": COLORS["warn"], "AUTH_ERROR": COLORS["error"], "BUDGET_EXHAUSTED": COLORS["warn"],
    "EMERGENCY_STOP": COLORS["error"], "SIN SERVICIO": COLORS["error"], "SERVER_HOLD": COLORS["warn"],
}

STATE_LABELS = {
    "RUNNING": "En ejecución", "PAUSED": "En pausa", "STARTING": "Iniciando", "STOPPED": "Detenido",
    "DEGRADED": "Degradado", "AUTH_ERROR": "Error de credenciales", "BUDGET_EXHAUSTED": "Presupuesto IA agotado",
    "EMERGENCY_STOP": "PARADA DE EMERGENCIA", "SIN SERVICIO": "Servicio no iniciado",
    "SERVER_HOLD": "En espera del servidor",
}

JOB_LABELS = {
    "claimed": "Reclamado", "running": "En proceso", "awaiting_approval": "Esperando aprobación",
    "committing": "Enviando", "verifying": "Verificando", "completed": "Completado", "failed": "Fallido",
    "needs_review": "Revisión manual", "released": "Devuelto",
}

QSS = f"""
* {{ font-family: "Segoe UI", "Inter", sans-serif; font-size: 13px; color: {COLORS['text']}; }}
QMainWindow, QWidget#root {{ background: {COLORS['bg']}; }}
QFrame#card {{ background: {COLORS['surface']}; border: 1px solid {COLORS['border']}; border-radius: 12px; }}
QFrame#topbar {{ background: {COLORS['surface']}; border-bottom: 1px solid {COLORS['border']}; }}
QLabel#brand {{ font-size: 17px; font-weight: 600; }}
QLabel#muted, QLabel[muted="true"] {{ color: {COLORS['muted']}; }}
QLabel#metricValue {{ font-size: 24px; font-weight: 600; }}
QLabel#metricLabel {{ color: {COLORS['muted']}; font-size: 12px; }}
QLabel#sectionTitle {{ font-size: 14px; font-weight: 600; }}
QLabel#pill {{ border-radius: 10px; padding: 3px 10px; background: {COLORS['surface2']}; }}
QPushButton {{ background: {COLORS['surface2']}; border: 1px solid {COLORS['border']}; border-radius: 8px;
              padding: 7px 14px; }}
QPushButton:hover {{ border-color: {COLORS['accent']}; }}
QPushButton:disabled {{ color: {COLORS['muted']}; }}
QPushButton#primary {{ background: {COLORS['accent']}; border-color: {COLORS['accent']}; color: white; }}
QPushButton#danger {{ background: {COLORS['error']}; border-color: {COLORS['error']}; color: white; font-weight: 600; }}
QPushButton#ok {{ background: {COLORS['ok']}; border-color: {COLORS['ok']}; color: #06120c; font-weight: 600; }}
QTabWidget::pane {{ border: none; }}
QTabBar::tab {{ background: transparent; color: {COLORS['muted']}; padding: 9px 16px; border: none; }}
QTabBar::tab:selected {{ color: {COLORS['text']}; border-bottom: 2px solid {COLORS['accent']}; }}
QTableWidget, QPlainTextEdit, QListWidget {{ background: {COLORS['surface']}; border: 1px solid {COLORS['border']};
              border-radius: 10px; gridline-color: {COLORS['border']}; }}
QHeaderView::section {{ background: {COLORS['surface2']}; color: {COLORS['muted']}; border: none; padding: 6px; }}
QLineEdit, QComboBox, QSpinBox, QDoubleSpinBox {{ background: {COLORS['surface2']}; border: 1px solid {COLORS['border']};
              border-radius: 8px; padding: 6px; }}
QCheckBox {{ spacing: 8px; }}
QScrollArea {{ border: none; background: transparent; }}
"""

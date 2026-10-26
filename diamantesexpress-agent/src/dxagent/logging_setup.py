"""Logging estructurado (JSON lines) con rotación y redacción de secretos."""

from __future__ import annotations

import json
import logging
import logging.handlers
import re
import sys
import threading
from datetime import datetime, timezone
from pathlib import Path

_SECRET_PATTERNS = [
    re.compile(r"sk-[A-Za-z0-9_\-]{8,}"),
    re.compile(r"(?i)(bearer\s+)[A-Za-z0-9._\-]{8,}"),
    re.compile(r"(?i)((?:token|api[_-]?key|password|secret|authorization)[\"']?\s*[:=]\s*[\"']?)[^\s\"',}]{4,}"),
]
_known_secrets: set[str] = set()
_lock = threading.Lock()


def register_secret(value: str | None) -> None:
    """Registra un valor secreto para que nunca aparezca en los logs."""
    if value and len(value) >= 6:
        with _lock:
            _known_secrets.add(value)


def redact(text: str) -> str:
    with _lock:
        secrets = list(_known_secrets)
    for value in secrets:
        text = text.replace(value, "***")
    text = _SECRET_PATTERNS[0].sub("sk-***", text)
    text = _SECRET_PATTERNS[1].sub(r"\1***", text)
    text = _SECRET_PATTERNS[2].sub(r"\1***", text)
    return text


class JsonFormatter(logging.Formatter):
    _std = set(vars(logging.makeLogRecord({})).keys()) | {"message", "asctime"}

    def format(self, record: logging.LogRecord) -> str:
        entry = {
            "ts": datetime.fromtimestamp(record.created, tz=timezone.utc).isoformat(),
            "level": record.levelname,
            "logger": record.name,
            "msg": record.getMessage(),
        }
        for key, value in record.__dict__.items():
            if key not in self._std and not key.startswith("_"):
                entry[key] = value
        if record.exc_info:
            entry["exc"] = self.formatException(record.exc_info)
        return redact(json.dumps(entry, ensure_ascii=False, default=str))


class ConsoleFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        return redact(super().format(record))


def setup_logging(log_dir: Path, component: str, level: int = logging.INFO,
                  max_bytes: int = 5 * 1024 * 1024, backups: int = 10, console: bool = True) -> Path:
    log_dir.mkdir(parents=True, exist_ok=True)
    log_file = log_dir / f"{component}.jsonl"
    root = logging.getLogger()
    root.setLevel(level)
    for handler in list(root.handlers):
        root.removeHandler(handler)
    file_handler = logging.handlers.RotatingFileHandler(
        log_file, maxBytes=max_bytes, backupCount=backups, encoding="utf-8")
    file_handler.setFormatter(JsonFormatter())
    root.addHandler(file_handler)
    if console and sys.stderr is not None:  # en el EXE de ventana no hay consola
        stream = logging.StreamHandler()
        stream.setFormatter(ConsoleFormatter("%(asctime)s %(levelname)s %(name)s: %(message)s"))
        root.addHandler(stream)
    for noisy in ("httpx", "httpcore", "openai"):
        logging.getLogger(noisy).setLevel(logging.WARNING)
    return log_file

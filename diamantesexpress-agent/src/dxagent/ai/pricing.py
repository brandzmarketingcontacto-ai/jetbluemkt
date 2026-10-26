"""Tabla de precios configurable por modelo (entrada, entrada en caché y salida)."""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

import yaml

from dxagent import paths


@dataclass(frozen=True)
class ModelPrice:
    input: float
    cached_input: float
    output: float


class PricingTable:
    def __init__(self, models: dict[str, ModelPrice], unit_tokens: int = 1_000_000, verified_at: str | None = None):
        self.models = models
        self.unit = unit_tokens
        self.verified_at = verified_at

    @classmethod
    def load(cls, path: str | Path | None = None) -> "PricingTable":
        file = Path(path) if path else paths.resolve_config_file("pricing.yaml")
        data = yaml.safe_load(file.read_text(encoding="utf-8")) or {}
        models = {
            name: ModelPrice(float(v["input"]), float(v.get("cached_input", v["input"])), float(v["output"]))
            for name, v in (data.get("models") or {}).items()
        }
        return cls(models, int(data.get("unit_tokens", 1_000_000)), data.get("verified_at"))

    def price_for(self, model: str) -> ModelPrice | None:
        if model in self.models:
            return self.models[model]
        # Snapshots con fecha (p. ej. gpt-4.1-mini-2025-04-14) usan el precio del modelo base.
        candidates = [m for m in self.models if model.startswith(m + "-")]
        return self.models[max(candidates, key=len)] if candidates else None

    def cost(self, model: str, input_tokens: int, cached_input_tokens: int, output_tokens: int) -> float | None:
        price = self.price_for(model)
        if price is None:
            return None
        uncached = max(input_tokens - cached_input_tokens, 0)
        total = (uncached * price.input + cached_input_tokens * price.cached_input + output_tokens * price.output)
        return round(total / self.unit, 8)

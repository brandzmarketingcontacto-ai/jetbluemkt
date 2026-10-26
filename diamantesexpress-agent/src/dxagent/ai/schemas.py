"""Esquemas estrictos (Structured Outputs) para la interpretación visual con OpenAI."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field

SuggestedActionType = Literal["tap", "input_text", "back", "wait", "none", "stop"]


class VisibleElement(BaseModel):
    model_config = ConfigDict(extra="forbid")
    element_id: str = Field(description="ID del catálogo proporcionado o 'unknown'")
    label: str = Field(description="Texto visible del elemento")
    x: float | None = Field(description="Centro horizontal normalizado 0..1")
    y: float | None = Field(description="Centro vertical normalizado 0..1")


class ExtractedField(BaseModel):
    model_config = ConfigDict(extra="forbid")
    name: str
    value: str


class SuggestedAction(BaseModel):
    model_config = ConfigDict(extra="forbid")
    action: SuggestedActionType
    element_id: str | None
    x: float | None
    y: float | None
    text: str | None


class ScreenAnalysis(BaseModel):
    model_config = ConfigDict(extra="forbid")
    screen_id: str = Field(description="ID de pantalla del catálogo o 'unknown'")
    confidence: float = Field(description="Confianza 0..1 en la identificación")
    detected_state: str
    visible_elements: list[VisibleElement]
    extracted_fields: list[ExtractedField]
    suggested_action: SuggestedAction
    needs_human: bool
    reason: str

    def field(self, name: str) -> str | None:
        for item in self.extracted_fields:
            if item.name == name:
                return item.value
        return None

    def element(self, element_id: str) -> VisibleElement | None:
        for item in self.visible_elements:
            if item.element_id == element_id:
                return item
        return None

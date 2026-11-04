"""Observación híbrida de pantalla: jerarquía UI y coordenadas calibradas primero, IA solo si hace falta."""

from __future__ import annotations

import io
import logging
from dataclasses import dataclass, field

from dxagent.ai.schemas import ScreenAnalysis
from dxagent.ai.vision import OpenAIVisionAgent
from dxagent.android.device import DeviceController, DeviceError, UiNode, parse_hierarchy
from dxagent.engine import local_vision
from dxagent.engine.policy import ExecutionPolicy
from dxagent.engine.workflow_def import ElementDef, Marker, WorkflowDef

log = logging.getLogger(__name__)


class ObservationError(Exception):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


@dataclass
class Observation:
    png: bytes
    xml: str | None
    nodes: list[UiNode]
    size: tuple[int, int]
    screen_id: str | None = None
    method: str = "none"          # hierarchy | ai | none
    confidence: float = 0.0
    ai: ScreenAnalysis | None = None
    notes: list[str] = field(default_factory=list)
    _image: object | None = field(default=None, repr=False)

    def image(self):
        if self._image is None:
            self._image = local_vision.open_image(self.png)
        return self._image

    def node(self, resource_id: str | None = None, text: str | None = None) -> UiNode | None:
        for n in self.nodes:
            if resource_id and n.short_id != resource_id:
                continue
            if text is not None and n.text != text:
                continue
            if resource_id or text is not None:
                return n
        return None

    def normalized(self, point: tuple[int, int]) -> tuple[float, float]:
        w, h = self.size
        return min(max(point[0] / w, 0.0), 1.0), min(max(point[1] / h, 0.0), 1.0)


def _png_size(png: bytes) -> tuple[int, int]:
    from PIL import Image
    try:
        with Image.open(io.BytesIO(png)) as img:
            return img.size
    except Exception as exc:
        raise ObservationError("UNREADABLE_SCREENSHOT", f"Captura ilegible: {exc}") from exc


def _looks_blank(png: bytes) -> bool:
    from PIL import Image
    with Image.open(io.BytesIO(png)) as img:
        extrema = img.convert("L").getextrema()
    return extrema[1] - extrema[0] < 8


class ScreenObserver:
    def __init__(self, device: DeviceController, workflow: WorkflowDef, vision: OpenAIVisionAgent | None,
                 policy: ExecutionPolicy, job_id: str, order_id: str, min_confidence: float = 0.75,
                 on_observation=None):
        self.device = device
        self.wf = workflow
        self.vision = vision
        self.policy = policy
        self.job_id = job_id
        self.order_id = order_id
        self.min_confidence = min_confidence
        self.on_observation = on_observation
        self.ai_requests = 0
        self._references: dict[str, object] = {}
        self._digit_readers: dict[tuple[str, int, int], local_vision.DigitReader] = {}

    # ---------------------------------------------------------------- captura
    async def observe(self, goal: str, fields: list[str] | None = None) -> Observation:
        self.policy.authorize("screenshot")
        png = await self.device.screenshot_png()
        size = _png_size(png)
        self.policy.authorize("dump_ui")
        xml = await self.device.dump_ui()
        obs = Observation(png=png, xml=xml, nodes=parse_hierarchy(xml), size=size)
        screen = self._identify_by_markers(obs)
        if screen:
            obs.screen_id, obs.method, obs.confidence = screen, "hierarchy", 1.0
        elif (screen := await self._identify_locally(obs)) is not None:
            obs.screen_id, obs.method, obs.confidence = screen, "local", 1.0
        elif self.vision is not None and not _looks_blank(png):
            await self._ask_ai(obs, goal, fields or [])
        elif _looks_blank(png) and not obs.nodes:
            obs.notes.append("captura en blanco o ilegible")
        if self.on_observation:
            self.on_observation(obs)
        return obs

    def _identify_by_markers(self, obs: Observation) -> str | None:
        if not obs.nodes:
            return None
        matches = []
        for screen_id, screen in self.wf.screens.items():
            if screen.markers and not any(m.local for m in screen.markers) \
                    and all(obs.node(m.resource_id, m.text) for m in screen.markers):
                matches.append(screen_id)
        return matches[0] if len(matches) == 1 else None

    # ------------------------------------------------- reconocimiento local
    def _reference(self, name: str):
        if name not in self._references:
            from PIL import Image
            if self.wf.assets_dir is None:
                raise ObservationError("LOCAL_ASSETS_MISSING", "El flujo no tiene carpeta de recursos")
            path = self.wf.assets_dir / name
            if not path.is_file():
                raise ObservationError("LOCAL_ASSETS_MISSING", f"Falta la imagen de referencia {path}")
            self._references[name] = Image.open(path).convert("L")
        return self._references[name]

    async def check_marker(self, marker: Marker, obs: Observation) -> bool:
        """¿Está la señal en pantalla? Jerarquía si la hay; si no, imagen de referencia u OCR en la zona."""
        if not marker.local:
            return bool(obs.node(marker.resource_id, marker.text)) if (marker.resource_id or marker.text) else False
        if marker.image is not None:
            score = local_vision.patch_score(obs.image(), marker.region, self._reference(marker.image))
            if score < (marker.min_score or 0.90):
                return False
        if marker.ocr is not None:
            try:
                text = await local_vision.read_text(obs.image(), marker.region)
            except local_vision.LocalVisionUnavailable as exc:
                raise ObservationError("LOCAL_OCR_UNAVAILABLE", str(exc)) from exc
            if local_vision.text_similarity(text, marker.ocr) < (marker.min_score or 0.80):
                return False
        return True

    async def _identify_locally(self, obs: Observation) -> str | None:
        """Pantallas con marcadores locales: gana la que cumple todos sus marcadores y tiene más (la más específica,
        p. ej. «tienda con destinatario elegido» frente a «tienda»). Un empate no se resuelve: pantalla desconocida."""
        matches: list[tuple[int, str]] = []
        for screen_id, screen in self.wf.screens.items():
            if not any(m.local for m in screen.markers):
                continue
            ok = True
            for m in screen.markers:
                if not await self.check_marker(m, obs):
                    ok = False
                    break
            if ok:
                matches.append((len(screen.markers), screen_id))
        if not matches:
            return None
        matches.sort(reverse=True)
        if len(matches) > 1 and matches[0][0] == matches[1][0]:
            obs.notes.append(f"varias pantallas coinciden: {[s for _, s in matches]}")
            return None
        return matches[0][1]

    async def element_visible(self, element_id: str, obs: Observation) -> bool:
        spec = self.wf.elements[element_id]
        if spec.region is not None and (spec.image or spec.ocr):
            return await self.check_marker(spec.as_marker(), obs)
        if spec.resource_id or spec.text:
            return obs.node(spec.resource_id, spec.text) is not None
        return obs.ai is not None and obs.ai.element(element_id) is not None

    async def _read_local(self, spec: ElementDef, obs: Observation) -> str | None:
        assert spec.region is not None
        if spec.reader == "digits":
            if not spec.digits or self.wf.assets_dir is None:
                raise ObservationError("LOCAL_ASSETS_MISSING", "Falta la carpeta de plantillas de dígitos")
            key = (spec.digits, spec.threshold, spec.skip_last)
            if key not in self._digit_readers:
                self._digit_readers[key] = local_vision.DigitReader(self.wf.assets_dir / spec.digits,
                                                                    spec.threshold, skip_last=spec.skip_last)
            return self._digit_readers[key].read(obs.image(), spec.region)
        try:
            text = await local_vision.read_text(obs.image(), spec.region, scale=spec.scale)
        except local_vision.LocalVisionUnavailable as exc:
            raise ObservationError("LOCAL_OCR_UNAVAILABLE", str(exc)) from exc
        return text or None

    async def _ask_ai(self, obs: Observation, goal: str, fields: list[str],
                      extra_elements: list[dict[str, str]] | None = None) -> ScreenAnalysis:
        assert self.vision is not None
        screens = [{"id": k, "descripcion": v.description} for k, v in self.wf.screens.items()]
        elements = [{"id": k, "descripcion": v.description} for k, v in self.wf.elements.items()]
        elements += extra_elements or []
        field_specs = [f"{f}: {self.wf.fields[f].description}" for f in fields if f in self.wf.fields]
        self.ai_requests += 1
        analysis = await self.vision.analyze(obs.png, job_id=self.job_id, order_id=self.order_id, screens=screens,
                                             elements=elements, fields=field_specs, goal=goal)
        obs.ai = analysis
        if analysis.screen_id in self.wf.screens and analysis.confidence >= self.min_confidence and not analysis.needs_human:
            obs.screen_id, obs.method, obs.confidence = analysis.screen_id, "ai", analysis.confidence
        else:
            obs.notes.append(f"IA no reconoce la pantalla con seguridad: {analysis.reason}")
        return analysis

    # ------------------------------------------------------------ localización
    def _calibrated(self, element: ElementDef, obs: Observation) -> tuple[float, float] | None:
        cal = self.wf.calibration
        if not cal.validated or element.calibrated is None:
            return None
        if cal.resolution and tuple(cal.resolution) != tuple(obs.size):
            obs.notes.append("resolución distinta a la calibrada: se ignoran coordenadas fijas")
            return None
        return element.calibrated

    async def locate(self, element_id: str, obs: Observation, goal: str) -> tuple[tuple[float, float], str]:
        element = self.wf.elements[element_id]
        if element.resource_id or element.text is not None:
            node = obs.node(element.resource_id, element.text)
            if node and node.enabled:
                return obs.normalized(node.center), "hierarchy"
        coords = self._calibrated(element, obs)
        if coords:
            return coords, "calibrated"
        if self.vision is not None:
            analysis = obs.ai if obs.ai is not None else await self._ask_ai(obs, goal, [])
            found = analysis.element(element_id)
            if found and found.x is not None and found.y is not None and analysis.confidence >= self.min_confidence:
                return (found.x, found.y), "ai"
        raise ObservationError("ELEMENT_NOT_FOUND", f"No se encontró el elemento {element_id}")

    async def locate_option(self, label: str, obs: Observation, goal: str) -> tuple[tuple[float, float], str]:
        node = next((n for n in obs.nodes if n.text == label and n.clickable and n.enabled), None)
        if node:
            return obs.normalized(node.center), "hierarchy"
        if self.vision is not None:
            extra = [{"id": "target_option", "descripcion": f"Opción con el texto exacto '{label}'"}]
            analysis = await self._ask_ai(obs, goal, [], extra)
            found = analysis.element("target_option")
            if found and found.x is not None and found.y is not None and found.label.strip() == label \
                    and analysis.confidence >= self.min_confidence:
                return (found.x, found.y), "ai"
        raise ObservationError("OPTION_NOT_FOUND", f"No se encontró la opción '{label}'")

    async def read_fields(self, names: list[str], obs: Observation, goal: str) -> tuple[dict[str, str | None], str]:
        values: dict[str, str | None] = {}
        missing = []
        local_used = False
        for name in names:
            spec = self.wf.fields[name]
            node = obs.node(spec.resource_id, None) if spec.resource_id else None
            if node is not None:
                values[name] = node.text
            elif spec.region is not None and spec.reader:
                values[name] = await self._read_local(spec, obs)
                local_used = True
            else:
                missing.append(name)
        method = "local" if local_used and not obs.nodes else "hierarchy"
        if missing and self.vision is not None:
            analysis = obs.ai if (obs.ai is not None and all(obs.ai.field(m) is not None for m in missing)) \
                else await self._ask_ai(obs, goal, missing)
            for name in missing:
                values[name] = analysis.field(name)
            method = "ai" if len(missing) == len(names) else "hierarchy+ai"
        else:
            for name in missing:
                values[name] = None
        return values, method


async def safe_observe(observer: ScreenObserver, goal: str) -> Observation | None:
    try:
        return await observer.observe(goal)
    except (DeviceError, ObservationError):
        return None

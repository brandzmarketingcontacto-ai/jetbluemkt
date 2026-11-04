"""Reconocimiento local de pantalla, sin internet ni costo por pedido.

Para juegos que no exponen jerarquía de Android (Unity, p. ej. Free Fire) se combinan tres técnicas:
- trozos de imagen: un recorte de la captura se compara con una referencia guardada (botones, títulos fijos);
- OCR de Windows (Windows.Media.Ocr, incluido en Windows 10/11): textos legibles como títulos, nombres de objetos,
  precios y avisos;
- dígitos por plantilla: números con la fuente del juego que el OCR no reconoce bien (saldo de diamantes).

Las zonas se expresan normalizadas (x1, y1, x2, y2 en 0..1) para no depender de la resolución.
"""

from __future__ import annotations

import io
import logging
import re
import unicodedata
from difflib import SequenceMatcher
from pathlib import Path

from PIL import Image, ImageChops, ImageOps, ImageStat

log = logging.getLogger(__name__)

Region = tuple[float, float, float, float]
GLYPH_SIZE = (12, 18)
# Confusiones habituales del OCR en textos del juego: se aplican a los dos lados al comparar.
_CONFUSABLES = str.maketrans({"1": "l", "|": "l", "i": "l", "0": "o", "5": "s", "¡": "", "!": "", "¿": "", "?": ""})


class LocalVisionUnavailable(Exception):
    """El OCR de Windows no está disponible en este equipo."""


def open_image(png: bytes) -> Image.Image:
    return Image.open(io.BytesIO(png)).convert("RGB")


def crop(img: Image.Image, region: Region) -> Image.Image:
    w, h = img.size
    x1, y1, x2, y2 = region
    return img.crop((round(x1 * w), round(y1 * h), round(x2 * w), round(y2 * h)))


# ------------------------------------------------------------ trozos de imagen
def patch_score(img: Image.Image, region: Region, reference: Image.Image) -> float:
    """Parecido (0..1) entre la zona de la captura y la referencia: 1 - diferencia media en gris."""
    current = crop(img, region).convert("L").resize(reference.size)
    diff = ImageChops.difference(current, reference.convert("L"))
    return 1.0 - ImageStat.Stat(diff).mean[0] / 255.0


# ------------------------------------------------------------------- OCR
def _prepare(img: Image.Image, scale: int, invert: bool) -> Image.Image:
    gray = img.convert("L")
    gray = gray.resize((max(gray.width * scale, 1), max(gray.height * scale, 1)), Image.LANCZOS)
    return ImageOps.invert(gray) if invert else gray


async def ocr_image(img: Image.Image) -> str:
    """Texto reconocido por el OCR de Windows (líneas separadas por espacios)."""
    try:
        from winrt.windows.graphics.imaging import BitmapDecoder
        from winrt.windows.media.ocr import OcrEngine
        from winrt.windows.storage.streams import DataWriter, InMemoryRandomAccessStream
    except ImportError as exc:  # fuera de Windows o sin los componentes instalados
        raise LocalVisionUnavailable(f"OCR de Windows no disponible: {exc}") from exc
    engine = OcrEngine.try_create_from_user_profile_languages()
    if engine is None:
        raise LocalVisionUnavailable("Windows no tiene un idioma de OCR instalado")
    buf = io.BytesIO()
    img.convert("RGB").save(buf, "PNG")
    stream = InMemoryRandomAccessStream()
    writer = DataWriter(stream)
    writer.write_bytes(buf.getvalue())
    await writer.store_async()
    await writer.flush_async()
    stream.seek(0)
    bitmap = await (await BitmapDecoder.create_async(stream)).get_software_bitmap_async()
    result = await engine.recognize_async(bitmap)
    return " ".join(line.text for line in result.lines).strip()


async def read_text(img: Image.Image, region: Region, scale: int = 3, invert: bool = False) -> str:
    return await ocr_image(_prepare(crop(img, region), scale, invert))


def normalize_text(text: str) -> str:
    text = unicodedata.normalize("NFKD", text or "")
    text = "".join(ch for ch in text if not unicodedata.combining(ch)).lower().translate(_CONFUSABLES)
    return re.sub(r"[^a-z0-9]+", " ", text).strip()


def text_similarity(found: str, expected: str) -> float:
    a, b = normalize_text(found), normalize_text(expected)
    if not a or not b:
        return 0.0
    if b in a:
        return 1.0
    return SequenceMatcher(None, a, b).ratio()


def parse_number(text: str) -> int | None:
    digits = re.sub(r"\D", "", text or "")
    return int(digits) if digits else None


# ------------------------------------------------------- dígitos por plantilla
def segment_glyphs(img: Image.Image, threshold: int = 170, skip_last: int = 0) -> list[Image.Image]:
    """Corta los caracteres claros sobre fondo oscuro; descarta separadores (comas, puntos) por su altura.

    skip_last quita símbolos fijos al final (p. ej. el «+» junto al saldo de diamantes).
    """
    gray = img.convert("L")
    mask = gray.point(lambda p: 255 if p > threshold else 0)
    w, h = mask.size
    pixels = mask.load()
    columns = [any(pixels[x, y] for y in range(h)) for x in range(w)]
    spans, start = [], None
    for x, on in enumerate(columns + [False]):
        if on and start is None:
            start = x
        elif not on and start is not None:
            spans.append((start, x))
            start = None
    glyphs, boxes = [], []
    for x1, x2 in spans:
        box = mask.crop((x1, 0, x2, h)).getbbox()
        if box:
            boxes.append((x1 + box[0], box[1], x1 + box[2], box[3]))
    if not boxes:
        return []
    tallest = max(b[3] - b[1] for b in boxes)
    for b in boxes:
        if b[3] - b[1] < tallest * 0.6:  # coma o punto de miles
            continue
        glyphs.append(mask.crop(b).resize(GLYPH_SIZE, Image.LANCZOS))
    return glyphs[:len(glyphs) - skip_last] if skip_last else glyphs


class DigitReader:
    """Lee números comparando cada carácter con plantillas 0.png … 9.png de la fuente del juego."""

    def __init__(self, directory: Path, threshold: int = 170, min_score: float = 0.80, skip_last: int = 0):
        self.threshold = threshold
        self.skip_last = skip_last
        self.min_score = min_score
        self.templates = {p.stem: Image.open(p).convert("L").resize(GLYPH_SIZE)
                          for p in sorted(Path(directory).glob("[0-9].png"))}

    def read(self, img: Image.Image, region: Region) -> str | None:
        """Número leído o None si algún carácter no se reconoce con seguridad (nunca se adivina)."""
        glyphs = segment_glyphs(crop(img, region), self.threshold, self.skip_last)
        if not glyphs or not self.templates:
            return None
        out = []
        for glyph in glyphs:
            scores = {d: 1.0 - ImageStat.Stat(ImageChops.difference(glyph, t)).mean[0] / 255.0
                      for d, t in self.templates.items()}
            digit, score = max(scores.items(), key=lambda kv: kv[1])
            if score < self.min_score:
                return None
            out.append(digit)
        return "".join(out)


def build_digit_templates(samples: list[tuple[Image.Image, Region, str]], out_dir: Path,
                          threshold: int = 170, skip_last: int = 0) -> set[str]:
    """Genera plantillas a partir de capturas con un número conocido. Devuelve los dígitos obtenidos."""
    out_dir.mkdir(parents=True, exist_ok=True)
    got: set[str] = set()
    for img, region, text in samples:
        digits = re.sub(r"\D", "", text)
        glyphs = segment_glyphs(crop(img, region), threshold, skip_last)
        if len(glyphs) != len(digits):
            log.warning("Muestra %s: %d caracteres para %d dígitos, se omite", text, len(glyphs), len(digits))
            continue
        for d, glyph in zip(digits, glyphs):
            if d not in got:
                glyph.save(out_dir / f"{d}.png")
                got.add(d)
    return got

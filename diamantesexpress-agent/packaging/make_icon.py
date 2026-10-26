"""Genera packaging/dxagent.ico (diamante) con Pillow; evita guardar binarios en el repositorio."""
from pathlib import Path

from PIL import Image, ImageDraw

size = 256
img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
d = ImageDraw.Draw(img)
d.polygon([(128, 16), (240, 104), (128, 240), (16, 104)], fill=(91, 140, 255, 255))
d.polygon([(128, 16), (184, 104), (72, 104)], fill=(159, 185, 255, 255))
out = Path(__file__).with_name("dxagent.ico")
img.save(out, sizes=[(16, 16), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)])
print(out)

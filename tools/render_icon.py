#!/usr/bin/env python3
"""Render our simple SVG as a PNG for Unraid's PNG-only menu icon loader.

Build-time dependency: Pillow. Supports only the shapes used by this icon.
"""
import re
import xml.etree.ElementTree as ET
from pathlib import Path
from PIL import Image, ImageDraw

web = Path(__file__).resolve().parents[1] / "web"
svg = ET.parse(web / "intel-gpu-firmware.svg").getroot()
scale = 4
image = Image.new("RGBA", (128 * scale, 128 * scale))
draw = ImageDraw.Draw(image)
for element in svg:
    tag = element.tag.rsplit("}", 1)[-1]
    a = element.attrib
    width = round(float(a.get("stroke-width", 0)) * scale)
    fill = None if a.get("fill", "none") == "none" else a["fill"]
    if tag == "title":
        continue
    if tag == "rect":
        x, y, w, h = [float(a[k]) * scale for k in ("x", "y", "width", "height")]
        inset = width / 2
        draw.rounded_rectangle((x-inset, y-inset, x+w+inset, y+h+inset),
                               radius=float(a.get("rx", 0))*scale+inset,
                               fill=fill, outline=a.get("stroke"), width=width or 1)
    elif tag == "path":
        tokens = iter(re.findall(r"[MHV]|-?\d+(?:\.\d+)?", a["d"]))
        x = y = 0
        for command in tokens:
            if command == "M":
                x, y = float(next(tokens))*scale, float(next(tokens))*scale
                continue
            old = (x, y)
            if command == "H":
                x = float(next(tokens))*scale
            elif command == "V":
                y = float(next(tokens))*scale
            else:
                raise ValueError("Unsupported SVG path command")
            draw.line((old, (x,y)), fill=a["stroke"], width=width)
            if a.get("stroke-linecap") == "round":
                r = width / 2
                for px, py in (old, (x,y)):
                    draw.ellipse((px-r, py-r, px+r, py+r), fill=a["stroke"])
    else:
        raise ValueError("Unsupported SVG element: " + tag)
image.resize((128,128), Image.Resampling.LANCZOS).save(web / "intel-gpu-firmware.png")

#!/usr/bin/env python3
"""Verify a publisher archive and generate a metadata-only catalog."""
import argparse
import datetime
import hashlib
import json
import re
import subprocess
import tempfile
from pathlib import Path

p = argparse.ArgumentParser(description=__doc__)
p.add_argument("--archive", type=Path, required=True)
p.add_argument("--sha512", required=True, help="Digest independently obtained from the official Intel page")
p.add_argument("--version", required=True)
p.add_argument("--source-url", required=True)
p.add_argument("--source-page", required=True)
p.add_argument("--extractor", default="7zz")
p.add_argument("--output", type=Path, default=Path(__file__).resolve().parents[1] / "catalog/catalog.json")
a = p.parse_args()
if not re.fullmatch(r"[a-f0-9]{128}", a.sha512):
    p.error("Invalid SHA-512")
if not re.fullmatch(r"https://downloadmirror\.intel\.com/[0-9]+/[a-zA-Z0-9_.-]+\.exe", a.source_url):
    p.error("Expected an official Intel download URL")
if not a.source_page.startswith("https://www.intel.com/"):
    p.error("Expected an official Intel source page")
h = hashlib.sha512()
with a.archive.open("rb") as f:
    for chunk in iter(lambda: f.read(1048576), b""):
        h.update(chunk)
if h.hexdigest() != a.sha512:
    p.error("Archive does not match the publisher digest")
components = {"fwcode": "gsc", "fwdata": "fw-data", "opromcode": "oprom-code", "opromdata": "oprom-data"}
images = []
with tempfile.TemporaryDirectory(prefix="intel-firmware-catalog-") as tmp:
    command = [a.extractor, "x", "-y", "-o" + tmp, str(a.archive),
               *["Graphics/ifwi/*/" + name + "/*" for name in components]]
    r = subprocess.run(command, capture_output=True, text=True)
    if r.returncode not in (0, 1):
        p.error("Extraction failed: " + r.stderr[-1000:])
    for file in sorted(Path(tmp).rglob("*")):
        if not file.is_file() or file.suffix not in (".bin", ".rom"):
            continue
        rel = file.relative_to(tmp).as_posix()
        if not re.fullmatch(r"Graphics/ifwi/[a-z][a-z0-9_-]{1,31}/[a-z0-9_-]+/[a-zA-Z0-9_.-]+\.(bin|rom)", rel):
            p.error("Unexpected image path")
        if file.parent.name not in components or file.is_symlink():
            p.error("Unexpected component")
        images.append({"path": rel, "component": components[file.parent.name],
                       "size": file.stat().st_size, "sha256": hashlib.sha256(file.read_bytes()).hexdigest()})
if not any(i["component"] == "gsc" for i in images):
    p.error("No GSC images found; do not publish an empty catalog")
catalog = {"schema": 1, "verified_on": datetime.date.today().isoformat(),
           "package": {"version": a.version, "url": a.source_url, "source_page": a.source_page,
                       "size": a.archive.stat().st_size, "sha512": a.sha512, "hash_verified": True},
           "images": images}
a.output.write_text(json.dumps(catalog, indent=2) + "\n")
print(f"Verified catalog: {len(images)} images; {sum(i['component']=='gsc' for i in images)} GSC images.")
print("No hardware was accessed. Review package dependencies and hardware evidence before release.")

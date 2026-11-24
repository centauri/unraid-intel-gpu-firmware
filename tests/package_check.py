#!/usr/bin/env python3
"""Check the deliverable without installing it or invoking any GPU command."""
import base64
import hashlib
import io
import json
import subprocess
import tarfile
import xml.etree.ElementTree as ET
import zipfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
plg = root / "dist/intel.gpu.firmware.plg"
xml = ET.fromstring(plg.read_bytes())
assert xml.tag == "PLUGIN" and xml.attrib["min"] == "7.4.1"
# Unraid uses strcmp, not semantic version comparison (.10 would sort before .9).
assert xml.attrib["version"] > "2026.11.24g-candidate"
assert xml.attrib['pluginURL'] == json.loads((root/'release.json').read_text())['plugin_url']
embedded = [f for f in xml.findall("FILE") if f.attrib.get("Type") == "base64"]
assert len(embedded) == 1
data = base64.b64decode(embedded[0].findtext("INLINE"), validate=True)
assert data[9] == 255, 'Gzip header must be platform independent'
sha = hashlib.sha256(data).hexdigest()
assert embedded[0].attrib["Name"].endswith("/payload-" + sha + ".tar.gz")
scripts = [f.findtext("INLINE") for f in xml.findall("FILE") if "Run" in f.attrib]
assert len(scripts) == 3
assert "@PAYLOAD" not in "".join(scripts)
assert sha in scripts[1] and "sha256sum -c -" in scripts[1]
for text in scripts:
    subprocess.run(["bash", "-n"], input=text, text=True, check=True)
with tarfile.open(fileobj=io.BytesIO(data), mode="r:gz") as tar:
    names = tar.getnames()
    assert "src/Core.php" in names and "web/IntelGpuFirmware.page" in names and "LICENSE" in names
    assert b'const WRITES_ENABLED = false;' in tar.extractfile('src/Core.php').read()
    for member in tar.getmembers():
        assert member.isfile() and not member.name.startswith("/") and ".." not in Path(member.name).parts
        assert tar.extractfile(member).read() == (root / member.name).read_bytes(), member.name
    assert not any("vendor/" in n or "tests/" in n or n.endswith((".exe", ".bin", ".deb")) for n in names)
for file in [*root.glob("src/*.php"), *root.glob("web/*.php"), *root.glob("tests/*.php")]:
    subprocess.run(["php", "-l", str(file)], check=True, stdout=subprocess.DEVNULL)
subprocess.run(["node", "--check", str(root / "web/app.js")], check=True)
catalog = json.loads((root / "catalog/catalog.json").read_text())
assert len(catalog["images"]) == 70 and sum(i["component"] == "gsc" for i in catalog["images"]) == 5
assert len({i["path"] for i in catalog["images"]}) == len(catalog["images"])
with zipfile.ZipFile(root / ('dist/intel-gpu-firmware-'+xml.attrib['version']+'-source.zip')) as z:
    assert z.testzip() is None
    assert not any("/vendor/" in n or "audit" in n or n.endswith((".exe", ".deb", ".bin")) for n in z.namelist())
    assert 'intel-gpu-firmware/.github/workflows/validate.yml' in z.namelist()
for line in (root / "dist/SHA256SUMS").read_text().splitlines():
    digest, name = line.split("  ")
    assert hashlib.sha256((root / "dist" / name).read_bytes()).hexdigest() == digest
print("PASS PLG XML, embedded payload, source archive, checksums, shell/PHP/JS syntax and catalog structure.")

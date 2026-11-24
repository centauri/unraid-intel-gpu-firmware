#!/usr/bin/env python3
"""Create a self-contained source PLG; dependencies download from their publishers."""
import base64
import argparse
import hashlib
import gzip
import io
import re
import json
import tarfile
import zipfile
import xml.etree.ElementTree as ET
from pathlib import Path

root = Path(__file__).resolve().parents[1]
release = json.loads((root / 'release.json').read_text())
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--candidate', action='store_true', help='Build a separate experimental write-enabled candidate; never installed automatically.')
args = parser.parse_args()
version = re.search(r"const VERSION = '([^']+)';", (root / "src/Core.php").read_text())[1]
if args.candidate:
    version += '-candidate'
buf = io.BytesIO()
with tarfile.open(fileobj=buf, mode="w", format=tarfile.PAX_FORMAT) as tar:
    files = [root / "README.md", root / "LICENSE"]
    for dirname in ("src", "web", "catalog", "docs"):
        files.extend(sorted((root / dirname).rglob("*")))
    for p in files:
            if p.is_file():
                info = tar.gettarinfo(str(p), p.relative_to(root).as_posix())
                info.uid = info.gid = 0
                info.uname = info.gname = "root"
                info.mtime = 0
                info.mode = 0o644
                data = p.read_bytes()
                if args.candidate and p == root / 'src/Core.php':
                    text = data.decode().replace("const MODE = 'read_only_test';", "const MODE = 'experimental_write';")
                    text = text.replace('const WRITES_ENABLED = false;', 'const WRITES_ENABLED = true;')
                    text = re.sub(r"const VERSION = '[^']+';", "const VERSION = '" + version + "';", text)
                    data = text.encode()
                info.size = len(data)
                tar.addfile(info, io.BytesIO(data))
payload = gzip.compress(buf.getvalue(), mtime=0)
sha = hashlib.sha256(payload).hexdigest()
plugin = ET.Element("PLUGIN", name="intel.gpu.firmware", author="Intel GPU Firmware contributors",
                    version=version, launch="Tools/IntelGpuFirmware", icon="/plugins/intel.gpu.firmware/intel-gpu-firmware.png", min="7.4.1")
if not args.candidate:
    assert release['channel'] == 'alpha'
    assert release['plugin_url'] == 'https://raw.githubusercontent.com/' + release['repository'] + '/alpha/intel.gpu.firmware.plg'
    plugin.set('pluginURL', release['plugin_url'])
    plugin.set('support', 'https://github.com/' + release['repository'] + '/issues')
ET.SubElement(plugin, "CHANGES").text = """2026.11.24h-alpha1 — Public alpha, physical writes disabled
Separate recoverable preflight from uncertain writes; consume failed plans.
Restore after failed rehearsals and resume partial restoration safely.
Re-arm guards after simulated reboot; reserve changed device mappings conservatively.
Show exact hardware evidence, add interruption tests and a read-only alpha update channel.
2026.11.24g — Targeted GPU maintenance
Review GPU users, stop only affected workloads, and guard Docker/libvirt starts.
Preserve restart policies and original running states; rehearse without flashing.
Keep guards on uncertain outcomes and require post-reboot verification before restore.
2026.11.24a — Optional UPS advice
A UPS is recommended, not required. Missing or unhealthy monitoring is advisory.
Keep firmware identity, compatibility, device-use and explicit confirmation checks.
Use a version suffix that sorts after the previous release in Unraid's string comparison.
2026.11.24.9 — Firmware history and Intel release checks
Persist observed versions, compatible GSC images and update outcomes per PCI identity.
Import the existing update journal without another flash; avoid duplicate USB writes.
Check Intel's official package page on request; retain old results on connection/parser failure.
Discovered packages never replace the trusted catalog or enable a firmware update.
Correct the timeout message so it cannot deny a preceding firmware write.
2026.11.24.8 — Final target revalidation
Recheck PCI identity, driver and MEI mapping immediately before the native write.
2026.11.24.7 — Hardened update workflow and separate candidate
Reject Intel diagnostics even when the CLI returns zero; check its version transcript.
Invalidate old plans and recheck maintenance after persisting intent.
Test the shared background worker, locking, duplicate requests and failed writes.
The standard build remains read-only. A separate candidate allows confirmed GSC writes.
2026.11.24.6 — Tools tile and GPU icon
Open the tool from its own System Information tile instead of embedding it in Tools.
Add a GPU circuit-board icon to the tile, plugin listing and page header.
2026.11.24.5 — Read-only live validation
Scope GPU facts styling against Unraid's global definition-list styles.
Record native PHP, device, download, dry-run and HTTP rejection evidence.
2026.11.24.4 — Preserve CSRF validation after Unraid's prepend
Decode the original bounded form after Unraid removes the validated token from POST.
Use one version source for plugin metadata and static asset cache keys.
2026.11.24.3 — Native Unraid integration fixes
Use an outer form CSRF token for Unraid's PHP security prepend.
Version static assets so reinstallations do not retain stale browser code.
2026.11.24.2 — Read-only test build
Native firmware writes are blocked in the API, engine and process executor.
Added non-writing dry tests against the real detected card.
2026.11.24.1 — Initial preview
Generic Intel display-controller detection across board vendors.
Pinned firmware catalog, hardware compatibility checks and explicit GSC update plans.
No automatic firmware updates. OPROM and board data remain read-only.
"""
cache = "/boot/config/plugins/intel.gpu.firmware"
# Check the operation lock before even replacing cached payloads.
guard = ET.SubElement(plugin, "FILE", Run="/bin/bash")
ET.SubElement(guard, "INLINE").text = """set -e
test "$(uname -m)" = x86_64 || { echo "This build requires x86_64 Unraid."; exit 1; }
mkdir -p /var/lib/intel-gpu-firmware
exec 9>/var/lib/intel-gpu-firmware/work.lock
flock -n 9 || { echo "Firmware operation in progress."; exit 1; }
"""
payload_name = "/payload-" + sha + ".tar.gz"
file = ET.SubElement(plugin, "FILE", Name=cache + payload_name, Type="base64")
ET.SubElement(file, "INLINE").text = base64.b64encode(payload).decode()
install = ET.SubElement(plugin, "FILE", Run="/bin/bash")
ET.SubElement(install, "INLINE").text = (
    "set -e\nprintf '%s  %s\\n' '" + sha + "' '" + cache +
    payload_name + "' | sha256sum -c -\n" + (root / "tools/install.sh").read_text().replace("@PAYLOAD_SHA256@", sha)
)
remove = ET.SubElement(plugin, "FILE", Run="/bin/bash", Method="remove")
ET.SubElement(remove, "INLINE").text = (root / "tools/remove.sh").read_text()
out = root / ('dist/candidate' if args.candidate else 'dist') / 'intel.gpu.firmware.plg'
out.parent.mkdir(exist_ok=True)
ET.indent(plugin)
ET.ElementTree(plugin).write(out, encoding="utf-8", xml_declaration=True)
if args.candidate:
    (out.parent / 'SHA256SUMS').write_text(hashlib.sha256(out.read_bytes()).hexdigest() + '  ' + out.name + '\n')
    print(f'Built experimental candidate {out}: {out.stat().st_size:,} bytes; payload SHA-256 {sha}')
    raise SystemExit(0)
source = root / ("dist/intel-gpu-firmware-" + version + "-source.zip")
source_files = [root / name for name in ('README.md','LICENSE','release.json','.gitignore')]
for dirname in ("src", "web", "catalog", "docs", "tools", "tests", ".github"):
    source_files.extend(p for p in (root / dirname).rglob("*") if p.is_file() and "__pycache__" not in p.parts)
with zipfile.ZipFile(source, "w", compression=zipfile.ZIP_DEFLATED) as z:
    for p in sorted(source_files):
        info = zipfile.ZipInfo("intel-gpu-firmware/" + p.relative_to(root).as_posix(), (2026,11,24,0,0,0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        z.writestr(info, p.read_bytes())
(root / "dist/SHA256SUMS").write_text("".join(hashlib.sha256(p.read_bytes()).hexdigest() + "  " + p.name + "\n" for p in (out, source)))
print(f"Built {out.name}: {out.stat().st_size:,} bytes; payload SHA-256 {sha}")

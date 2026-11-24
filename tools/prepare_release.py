#!/usr/bin/env python3
"""Prepare an allowlisted, non-writing public release. Does not upload anything."""
import base64
import hashlib
import io
import json
import re
import shutil
import tarfile
import xml.etree.ElementTree as ET
from pathlib import Path

root = Path(__file__).resolve().parents[1]
policy = json.loads((root/'release.json').read_text())
assert policy['channel'] == 'alpha' and policy['physical_writes'] is False
plg = root/'dist/intel.gpu.firmware.plg'
xml = ET.fromstring(plg.read_bytes())
version = xml.attrib['version']
assert 'alpha' in version and 'candidate' not in version
assert xml.attrib['pluginURL'] == policy['plugin_url']
payload = next(f for f in xml.findall('FILE') if f.attrib.get('Type') == 'base64')
with tarfile.open(fileobj=io.BytesIO(base64.b64decode(payload.findtext('INLINE'))), mode='r:gz') as tar:
    core = tar.extractfile('src/Core.php').read()
    assert b'const WRITES_ENABLED = false;' in core
    assert b"const MODE = 'read_only_test';" in core
    for member in tar.getmembers():
        content = tar.extractfile(member).read()
        for private in (rb'\b192\.168\.\d{1,3}\.\d{1,3}\b', rb'[a-f0-9]{20,}\.myunraid\.net', rb'/Users/[A-Za-z0-9_.-]+/', rb'Authorization: Bearer [A-Za-z0-9_-]{20,}'):
            assert not re.search(private, content), 'Private host data in '+member.name
out = root/'release'
out.mkdir(exist_ok=True)
names = ['intel.gpu.firmware.plg', 'intel-gpu-firmware-'+version+'-source.zip']
# Refuse stale/candidate artifacts rather than accidentally carrying them forward.
expected = set(names + ['SHA256SUMS','RELEASE.md'])
assert not (set(p.name for p in out.iterdir()) - expected), 'Use a fresh release directory'
for name in names:
    source = root/'dist'/name
    expected_hash = next(line.split('  ')[0] for line in (root/'dist/SHA256SUMS').read_text().splitlines() if line.endswith('  '+name))
    assert hashlib.sha256(source.read_bytes()).hexdigest() == expected_hash
    shutil.copyfile(source, out/name)
shutil.copyfile(root/'docs/ALPHA-RELEASE.md', out/'RELEASE.md')
(out/'SHA256SUMS').write_text(''.join(hashlib.sha256((out/name).read_bytes()).hexdigest()+'  '+name+'\n' for name in names+['RELEASE.md']))
print('Prepared public alpha '+version+': '+', '.join(sorted(expected)))
print('Physical writes disabled. No upload, installation or GPU operation performed.')

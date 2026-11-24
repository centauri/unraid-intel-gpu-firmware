#!/usr/bin/env python3
"""Validate and exercise the separate candidate using fake devices only."""
import base64
import hashlib
import io
import subprocess
import tarfile
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path

root = Path(__file__).resolve().parents[1]
directory = root / 'dist/candidate'
plg = directory / 'intel.gpu.firmware.plg'
xml = ET.fromstring(plg.read_bytes())
assert xml.attrib['version'].endswith('-candidate')
digest, name = (directory / 'SHA256SUMS').read_text().strip().split('  ')
assert name == plg.name and digest == hashlib.sha256(plg.read_bytes()).hexdigest()
for file in xml.findall('FILE'):
    if 'Run' in file.attrib:
        subprocess.run(['bash','-n'],input=file.findtext('INLINE'),text=True,check=True)
payload = next(f for f in xml.findall('FILE') if f.attrib.get('Type') == 'base64')
data = base64.b64decode(payload.findtext('INLINE'),validate=True)
assert payload.attrib['Name'].endswith('/payload-'+hashlib.sha256(data).hexdigest()+'.tar.gz')
with tempfile.TemporaryDirectory(prefix='igf-candidate-test-') as temp:
    folder = Path(temp)
    with tarfile.open(fileobj=io.BytesIO(data),mode='r:gz') as tar:
        for member in tar.getmembers():
            assert member.isfile() and not member.name.startswith('/') and '..' not in Path(member.name).parts
            content = tar.extractfile(member).read()
            if member.name == 'src/Core.php':
                assert b"const WRITES_ENABLED = true;" in content
                assert b"const MODE = 'experimental_write';" in content
            else:
                assert content == (root / member.name).read_bytes(), member.name
            if member.name.startswith('src/'):
                dest = folder / member.name
                dest.parent.mkdir(exist_ok=True)
                dest.write_bytes(content)
                subprocess.run(['php','-l',str(dest)],check=True,stdout=subprocess.DEVNULL)
    subprocess.run(['php',str(root/'tests/run.php'),str(folder/'src')],check=True)
print('PASS candidate payload, checksums and simulated worker/update tests. No installer or native writer was run.')

#!/usr/bin/env python3
"""Exercise packaged code with fake devices, never a native writer."""
import argparse
import base64
import hashlib
import io
import re
import subprocess
import tarfile
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
profiles = parser.add_mutually_exclusive_group()
profiles.add_argument('--candidate', action='store_true')
profiles.add_argument('--read-only', action='store_true')
args = parser.parse_args()
directory = root / ('dist/candidate' if args.candidate else 'dist/read-only' if args.read_only else 'dist')
plg = directory / 'intel.gpu.firmware.plg'
xml = ET.fromstring(plg.read_bytes())
suffix = '-candidate' if args.candidate else '-readonly' if args.read_only else ''
source_version = re.search(r"const VERSION = '([^']+)';", (root/'src/Core.php').read_text())[1]
assert xml.attrib['version'] == source_version + suffix
assert ('pluginURL' in xml.attrib) == (not bool(suffix))
digest, name = next(line.split('  ') for line in (directory / 'SHA256SUMS').read_text().splitlines() if line.endswith('  '+plg.name))
assert name == plg.name and digest == hashlib.sha256(plg.read_bytes()).hexdigest()
for file in xml.findall('FILE'):
    if 'Run' in file.attrib:
        subprocess.run(['bash','-n'],input=file.findtext('INLINE'),text=True,check=True)
payload = next(f for f in xml.findall('FILE') if f.attrib.get('Type') == 'base64')
data = base64.b64decode(payload.findtext('INLINE'),validate=True)
assert payload.attrib['Name'].endswith('/payload-'+hashlib.sha256(data).hexdigest()+'.tar.gz')
with tempfile.TemporaryDirectory(prefix='igf-artifact-test-') as temp:
    folder = Path(temp)
    with tarfile.open(fileobj=io.BytesIO(data),mode='r:gz') as tar:
        for member in tar.getmembers():
            assert member.isfile() and not member.name.startswith('/') and '..' not in Path(member.name).parts
            content = tar.extractfile(member).read()
            expected = (root / member.name).read_bytes()
            if member.name == 'src/Core.php':
                if suffix:
                    expected = expected.replace(("const VERSION = '"+xml.attrib['version'][:-len(suffix)]+"';").encode(), ("const VERSION = '"+xml.attrib['version']+"';").encode())
                if args.read_only:
                    expected = expected.replace(b"const WRITES_ENABLED = true;", b"const WRITES_ENABLED = false;")
                    expected = expected.replace(b"const MODE = 'experimental_write';", b"const MODE = 'read_only_test';")
            assert content == expected, member.name
            if member.name.startswith('src/'):
                dest = folder / member.name
                dest.parent.mkdir(exist_ok=True)
                dest.write_bytes(content)
                subprocess.run(['php','-l',str(dest)],check=True,stdout=subprocess.DEVNULL)
    subprocess.run(['php',str(root/'tests/run.php'),str(folder/'src')],check=True)
print('PASS '+xml.attrib['version']+' packaged code, checksums and simulated worker/update tests. No installer or native writer was run.')

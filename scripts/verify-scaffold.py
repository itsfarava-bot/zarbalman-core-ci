import pathlib
import hashlib
import json
import xml.etree.ElementTree as ET
import subprocess

root = pathlib.Path(__file__).resolve().parents[1]
manifest = json.loads((root / 'source-sha256.json').read_text())
for name, digest in manifest.items():
    assert hashlib.sha256((root / 'plugin' / name).read_bytes()).hexdigest() == digest, name
print(f'PASS: {len(manifest)} source hashes verified. Hashes verify copies, not correctness.')
for name in ['phpunit.xml', 'phpcs.xml']:
    ET.parse(root / name)
    print('PASS: XML parses:', name)
json.loads((root / 'composer.json').read_text())
print('PASS: composer.json parses.')
subprocess.run(['bash', '-n', str(root / 'scripts/setup.sh')], check=True)
print('PASS: bash -n scripts/setup.sh')
for path in root.rglob('*'):
    if path.is_file() and (path.name == 'wp-config.php' or path.suffix in {'.sql', '.gz', '.zip'}):
        raise RuntimeError('Forbidden file in deliverable: ' + str(path.relative_to(root)))
print('PASS: no wp-config.php, database dump or archive in deliverable.')


"""Encrypt CI diagnostics with a public certificate before artifact upload."""
import io
import pathlib
import subprocess
import tarfile

root = pathlib.Path(__file__).resolve().parents[1]
runtime = root / '.runtime'
runtime.mkdir(exist_ok=True)
payload = io.BytesIO()
with tarfile.open(fileobj=payload, mode='w:gz') as archive:
    paths = list(runtime.glob('check-*.log'))
    evidence = root / 'evidence'
    if evidence.exists():
        paths.extend(p for p in evidence.rglob('*') if p.is_file())
    for path in sorted(paths):
        archive.add(path, arcname=str(path.relative_to(root)), recursive=False)
result = subprocess.run(
    ['openssl', 'cms', '-encrypt', '-binary', '-outform', 'DER',
     '-aes-256-cbc', '-out', str(runtime / 'diagnostics.cms'),
     '-recip', str(root / 'ci/diagnostics.crt'),
     '-keyopt', 'rsa_padding_mode:oaep'],
    input=payload.getvalue(), stdout=subprocess.PIPE, stderr=subprocess.PIPE,
)
if result.returncode:
    raise SystemExit('Diagnostic encryption failed.')
print('Diagnostics encrypted.')

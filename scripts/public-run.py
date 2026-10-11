"""Run a CI check, retaining its status and publishing only bounded summaries."""
import json
import os
import pathlib
import re
import subprocess
import sys

root = pathlib.Path(__file__).resolve().parents[1]
runtime = root / '.runtime'
runtime.mkdir(exist_ok=True)
summary_path = runtime / 'public-summary.json'
summary = json.loads(summary_path.read_text()) if summary_path.exists() else {'runs': []}
command = sys.stdin.read()
number = len(summary['runs']) + 1
log = runtime / ('check-' + str(number) + '.log')
with log.open('wb') as output:
    os.chmod(log, 0o600)
    result = subprocess.run(['bash', '-euo', 'pipefail', '-c', command], cwd=root, stdout=output, stderr=subprocess.STDOUT)
text = log.read_text(errors='replace')
patterns = [
    r'OK \(\d+ tests?, \d+ assertions?\)',
    r'Tests: \d+, Assertions: \d+(?:, (?:Errors|Failures|Warnings|Skipped|Incomplete|Risky): \d+)*\.',
    r'(?:phpcs|phpstan) ratchet: current=\d+ known=\d+ new=\d+ absent=\d+',
    r"PHPStan (?:raw|gated) \{'errors': \d+, 'file_errors': \d+\}",
    r'Ran \d+ tests? in [0-9.]+s',
    r'PHPStan inventory: \d+',
]
lines = []
for pattern in patterns:
    lines.extend(re.findall(pattern, text))
entry = {'check': number, 'exit_code': result.returncode, 'summary': lines}
summary['runs'].append(entry)
summary_path.write_text(json.dumps(summary, indent=2) + '\n')
print('Check', number, 'exit_code=', result.returncode)
for line in lines:
    print(line)
raise SystemExit(result.returncode)

"""Report all PHPCS findings; gate every source except an exact style allowlist."""
import argparse
import collections
import json
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('report', type=Path)
parser.add_argument('--report-only', action='store_true')
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
style = json.loads((root / 'ci/phpcs-style-sniffs.json').read_text())
assert not any('Security' in s or 'PreparedSQL' in s or 'PHPCompatibility' in s for s in style)
data = json.loads(args.report.read_text())
assert isinstance(data.get('files'), dict) and data['files'], 'Missing PHPCS files'
groups = {'blocking': [], 'style': []}
for path, file in data['files'].items():
    for finding in file['messages']:
        finding = dict(finding, file='plugin/' + path.split('/plugin/')[-1])
        groups['style' if finding['source'] in style else 'blocking'].append(finding)
assert sum(map(len, groups.values())) == data['totals']['errors'] + data['totals']['warnings']
summary = {k: len(v) for k, v in groups.items()}
summary['by_source'] = {k: dict(collections.Counter(m['source'] for m in v)) for k, v in groups.items()}
out = args.report.parent
(out / 'phpcs-partition.json').write_text(json.dumps(groups, indent=2))
(out / 'phpcs-counts.json').write_text(json.dumps(summary, indent=2))
print(json.dumps(summary, indent=2))
if not args.report_only and groups['blocking']:
    raise SystemExit(1)

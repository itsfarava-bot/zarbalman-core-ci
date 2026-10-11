"""Exact, counted diagnostic ratchet. All analyzers still execute every rule."""
import argparse
import collections
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FIELDS = ('file', 'line', 'rule', 'message', 'type')
# Reviewed, immutable fingerprints; never updated automatically.
BASELINE_SHA256 = {'phpcs': 'f3bcf09cf87300f162d822763b83799de3427a46e9a4558ee75eddeae5bbd233', 'phpstan': '14391628f80321b47556b18ff677928738958210225c7c20bd1cda11051bd498'}

def normalize(path):
    if path.startswith('plugin/'):
        return path
    if '/plugin/' not in path:
        raise ValueError('Diagnostic outside plugin: ' + path)
    return 'plugin/' + path.split('/plugin/', 1)[1]

def findings(tool, report):
    data = json.loads(Path(report).read_text())
    rows = []
    if tool == 'phpcs':
        assert isinstance(data.get('files'), dict) and data['files'], 'Missing PHPCS files'
        style = json.loads((ROOT / 'ci/phpcs-style-sniffs.json').read_text())
        count = 0
        for file, result in data['files'].items():
            for m in result['messages']:
                validate(dict(file=normalize(file), line=m['line'], rule=m['source'], message=m['message'], type=m['type']))
                count += 1
                if m['source'] not in style:
                    rows.append(dict(file=normalize(file), line=m['line'], rule=m['source'], message=m['message'], type=m['type']))
        assert count == data['totals']['errors'] + data['totals']['warnings'], 'Incomplete PHPCS report'
    else:
        assert data['totals']['errors'] == 0 and not data.get('errors'), 'PHPStan global error'
        for file, result in data['files'].items():
            for m in result['messages']:
                assert m.get('identifier'), 'Missing PHPStan identifier'
                rows.append(dict(file=normalize(file), line=m['line'], rule=m['identifier'], message=m['message'], type='ERROR'))
        assert len(rows) == data['totals']['file_errors'], 'Incomplete PHPStan report'
    for row in rows:
        validate(row)
    return rows

def validate(row):
    assert type(row['line']) is int and row['line'] > 0, 'Malformed diagnostic line'
    assert all(isinstance(row[k],str) and row[k] for k in ('file','rule','message','type')), 'Malformed diagnostic text'
    assert row['type'] in ('ERROR','WARNING'), 'Malformed diagnostic severity'

def fingerprint(row):
    if isinstance(row, str):
        assert len(row) == 64 and all(c in '0123456789abcdef' for c in row), 'Malformed fingerprint'
        return row
    validate(row)
    value = [row[k] for k in FIELDS]
    return hashlib.sha256(json.dumps(value, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest()

def counter(rows):
    return collections.Counter(fingerprint(row) for row in rows)

def compare(actual, baseline):
    return counter(actual) - counter(baseline)

def main():
    p = argparse.ArgumentParser()
    p.add_argument('tool', choices=['phpcs','phpstan'])
    p.add_argument('report', type=Path)
    p.add_argument('--baseline', type=Path)
    a = p.parse_args()
    baseline_path = a.baseline or ROOT / ('ci/ratchet/' + a.tool + '.json')
    assert hashlib.sha256(baseline_path.read_bytes()).hexdigest() == BASELINE_SHA256[a.tool], 'Baseline integrity mismatch'
    baseline = json.loads(baseline_path.read_text())
    assert baseline['tool'] == a.tool
    rows = findings(a.tool, a.report)
    added = compare(rows, baseline['findings'])
    existing = counter(rows) & counter(baseline['findings'])
    resolved = counter(baseline['findings']) - counter(rows)
    result = {'tool':a.tool,'current_blocking':len(rows),'known_remaining':sum(existing.values()),'new_blocking':sum(added.values()),'absent_from_baseline':sum(resolved.values()),'findings':rows,'new':dict(sorted(added.items()))}
    out = a.report.parent / (a.tool + '-ratchet.json')
    out.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(f"{a.tool} ratchet: current={len(rows)} known={sum(existing.values())} new={sum(added.values())} absent={sum(resolved.values())}")
    raise SystemExit(1 if added else 0)

if __name__ == '__main__':
    main()

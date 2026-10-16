"""Publish bounded test metadata; detailed diagnostics remain encrypted.

Messages are deliberately fail-closed: only fixed, value-free assertion
sentences are public. Unknown text is redacted, including synthetic secrets.
"""
import argparse
from datetime import datetime
import json
import os
from pathlib import Path
import re
import sys
import urllib.request
import xml.etree.ElementTree as ET

sys.path.insert(0, str(Path(__file__).resolve().parent))
from ci_matrix_deferrals import apply_deferrals

COUNTERS = ('tests', 'assertions', 'failures', 'errors', 'skipped', 'risky', 'incomplete')
SAFE_MESSAGES = {
    'Failed asserting that false is true.',
    'Failed asserting that true is false.',
    'Failed asserting that false is identical to true.',
    'Failed asserting that true is identical to false.',
    'Failed asserting that null is not null.',
    'Failed asserting that two strings are identical.',
    'Failed asserting that two arrays are identical.',
    'Failed asserting that an array is empty.',
    'Failed asserting that actual size matches expected size.',
}


def safe_job_name(value, root):
    match = re.fullmatch(r'([a-z][a-z0-9-]{0,60})(?: \((7\.1\.2|7\.0\.3)\))?', value)
    workflow = root / '.github/workflows/task1.yml'
    if match and workflow.is_file() and re.search(r'^  ' + re.escape(match[1]) + r':\s*$', workflow.read_text(), re.M):
        return value
    return '[redacted]'


def safe_test_name(value, root):
    match = re.fullmatch(r'([A-Za-z_][A-Za-z0-9_]*)::(test[A-Za-z0-9_]+)(?: with data set "(http-[0-9]{3})")?', value)
    if not match:
        return '[redacted]'
    cls, method, dataset = match.groups()
    for path in (root / 'tests').rglob(cls + '.php'):
        source = path.read_text()
        if not re.search(r'\bclass\s+' + re.escape(cls) + r'\b', source) or not re.search(r'\bfunction\s+' + re.escape(method) + r'\s*\(', source):
            continue
        if dataset:
            catalog = root / 'ci/http-surfaces.json'
            if not catalog.is_file() or dataset not in {row['id'] for row in json.loads(catalog.read_text())['rows']}:
                return '[redacted]'
        return value
    return '[redacted]'


def message(value):
    first = value.strip().splitlines()[0] if value.strip() else ''
    return first[:160] if first in SAFE_MESSAGES else '[redacted]'




MAX_JOB_BYTES = 256 * 1024
MAX_AGGREGATE_BYTES = 900000
MAX_FAILURE_ROWS = 1000
MAX_CHECK_ROWS = 128
TRUNCATED = 'Summary truncated; detailed diagnostics remain encrypted.'


def bounded(lines, limit, truncated=False):
    output, used = [], 0
    reserve = len(('\n' + TRUNCATED + '\n').encode())
    for line in lines:
        size = len((line + '\n').encode())
        if used + size > limit - reserve:
            truncated = True
            break
        output.append(line)
        used += size
    if truncated:
        output += ['', TRUNCATED]
    return '\n'.join(output) + '\n'


def safe_location(value, root):
    if not isinstance(value, str) or len(value) > 240:
        return '[redacted]'
    match = re.fullmatch(r'(tests/[A-Za-z0-9_./\-]+\.php):([1-9][0-9]{0,5})', value)
    if not match:
        return '[redacted]'
    path, line = match.groups()
    target = (root / path).resolve()
    tests = (root / 'tests').resolve()
    if not target.is_relative_to(tests) or not target.is_file():
        return '[redacted]'
    canonical = target.relative_to(root.resolve()).as_posix()
    if path != canonical or int(line) > len(target.read_text().splitlines()):
        return '[redacted]'
    return canonical + ':' + str(int(line))


def matrix_pending(root):
    path = root / 'evidence/http-matrix-coverage.json'
    if not path.exists():
        return None
    report = json.loads(path.read_text())
    manifest = json.loads((root / 'ci/deferred-http-matrix.json').read_text())
    control = apply_deferrals(report['rows'], manifest)
    return (control['deferred'] if not report['errors'] and not control['errors']
            and len(report['rows']) == 86 else 0)


def job_seconds(job):
    if not job.get('started_at') or not job.get('completed_at'):
        return 0
    return max(0, int((datetime.fromisoformat(job['completed_at'].replace('Z', '+00:00'))
                       - datetime.fromisoformat(job['started_at'].replace('Z', '+00:00'))).total_seconds()))


def collect(root):
    runtime = root / '.runtime'
    source = runtime / 'public-summary.json'
    runs = json.loads(source.read_text()).get('runs', []) if source.exists() else []
    counts = dict.fromkeys(COUNTERS, 0)
    for run in runs:
        log = runtime / ('check-' + str(int(run['check'])) + '.log')
        if not log.exists():
            continue
        for line in log.read_text(errors='replace').splitlines():
            if re.fullmatch(r'OK \(\d+ tests?, \d+ assertions?\)', line):
                tests, assertions = re.findall(r'\d+', line)
                counts['tests'] += int(tests)
                counts['assertions'] += int(assertions)
            elif re.fullmatch(r'Tests: \d+, Assertions: \d+(?:, (?:Errors|Failures|Warnings|Skipped|Incomplete|Risky): \d+)*\.', line):
                for key, number in re.findall(r'([A-Za-z]+): (\d+)', line):
                    if key.lower() in counts:
                        counts[key.lower()] += int(number)
    failures = []
    truncated = len(runs) > MAX_CHECK_ROWS
    junit = root / 'evidence/candidate-junit.xml'
    if junit.exists():
        for case in ET.parse(junit).iter('testcase'):
            for issue in list(case):
                if issue.tag not in ('failure', 'error'):
                    continue
                if len(failures) >= MAX_FAILURE_ROWS:
                    truncated = True
                    break
                body = issue.text or ''
                location = '[redacted]'
                for path, line in re.findall(r'(tests/[A-Za-z0-9_./\-]+\.php):(\d+)\s*$', body, re.M):
                    candidate = safe_location(path + ':' + line, root)
                    if candidate != '[redacted]':
                        location = candidate
                        break
                identity = case.get('classname', '') + '::' + case.get('name', '')
                lines = body.strip().splitlines()
                if lines and lines[0].startswith(identity):
                    lines = lines[1:]
                failures.append({'name': safe_test_name(identity, root), 'location': location,
                                 'message': message('\n'.join(lines))})
    outcome = root / 'evidence/test-result-control.json'
    control = json.loads(outcome.read_text()) if outcome.exists() else {}
    pending = int(control.get('pending', 0)) if control.get('exit_code') == 0 else 0
    if not 0 <= pending <= 5:
        raise ValueError('Invalid pending count')
    return {'job': safe_job_name(os.environ.get('GITHUB_JOB', 'local'), root),
            'counts': counts, 'failures': failures, 'pending': pending, 'matrix_pending': matrix_pending(root), 'truncated': truncated,
            'checks': [{'check': int(r['check']), 'exit_code': int(r['exit_code']),
                        'duration_seconds': round(float(r.get('duration_seconds', 0)), 2)} for r in runs[:MAX_CHECK_ROWS]]}


def render(data):
    lines = ['| Job | Check | Result | Seconds |', '|---|---:|---|---:|']
    truncated = bool(data.get('truncated')) or len(data['checks']) > MAX_CHECK_ROWS or len(data['failures']) > MAX_FAILURE_ROWS
    for check in data['checks'][:MAX_CHECK_ROWS]:
        lines.append(f"| {data['job']} | {check['check']} | {'success' if check['exit_code'] == 0 else 'failure'} | {check['duration_seconds']} |")
    lines += ['', '| Tests | Assertions | Failures | Errors | Skip | Risky | Incomplete |', '|---:|---:|---:|---:|---:|---:|---:|',
              '| ' + ' | '.join(str(data['counts'][key]) if data['counts'][key] is not None else 'unknown' for key in COUNTERS) + ' |', '',
              '| Failed test | File:line | First message line |', '|---|---|---|']
    for issue in data['failures'][:MAX_FAILURE_ROWS]:
        lines.append('| ' + ' | '.join(issue[key] for key in ('name', 'location', 'message')) + ' |')
    pending = int(data.get('pending', 0))
    if not 0 <= pending <= 5:
        raise ValueError('Invalid pending count')
    if pending:
        lines += ['', 'Pending failures retained: ' + str(pending) + ' (two-way outcome guard).']
    if data.get('matrix_pending') is not None:
        matrix = data['matrix_pending']
        if type(matrix) is not int or matrix not in (0, 9):
            raise ValueError('Invalid matrix deferral count')
        lines += ['', f'Deferred tests: {pending}; deferred HTTP matrix rows: {matrix}.']
    if data.get('unavailable'):
        lines += ['', 'Summary unavailable: [redacted]']
    return bounded(lines, MAX_JOB_BYTES, truncated)


def publish(args):
    unavailable = False
    if args.aggregate is not None:
        repository = os.environ['GITHUB_REPOSITORY']
        run_id = int(os.environ['GITHUB_RUN_ID'])
        if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', repository):
            raise ValueError('Invalid repository')
        url = f'https://api.github.com/repos/{repository}/actions/runs/{run_id}/jobs?per_page=100'
        request = urllib.request.Request(url, headers={'Authorization': 'Bearer ' + os.environ['GH_TOKEN'], 'Accept': 'application/vnd.github+json'})
        with urllib.request.urlopen(request, timeout=30) as response:
            jobs = json.load(response)['jobs']
        lines = ['| Job | Result | Seconds |', '|---|---|---:|']
        for job in jobs[:128]:
            seconds = job_seconds(job)
            result = job['conclusion'] or job['status']
            if result not in ('success', 'failure', 'cancelled', 'skipped', 'neutral', 'timed_out', 'action_required', 'stale', 'queued', 'in_progress', 'completed', 'pending', 'waiting', 'requested'):
                result = '[redacted]'
            lines.append(f"| {safe_job_name(job['name'], args.root)} | {result} | {seconds} |")
        text = bounded(lines, MAX_AGGREGATE_BYTES, len(jobs) > 128) + '\n'
        paths = sorted(args.aggregate.rglob('safe-summary.json'))
        for path in paths[:128]:
            # Revalidate metadata and messages at the publication boundary.
            data = json.loads(path.read_text())
            data['job'] = safe_job_name(data['job'], args.root)
            unavailable = unavailable or data.get('unavailable', False)
            data['counts'] = {key: None if data.get('unavailable') else max(0, int(data['counts'][key])) for key in COUNTERS}
            data['truncated'] = bool(data.get('truncated')) or len(data['checks']) > MAX_CHECK_ROWS or len(data['failures']) > MAX_FAILURE_ROWS
            data['checks'] = [{'check': int(row['check']), 'exit_code': int(row['exit_code']), 'duration_seconds': round(float(row['duration_seconds']), 2)} for row in data['checks'][:MAX_CHECK_ROWS]]
            for issue in data['failures'][:MAX_FAILURE_ROWS]:
                issue['name'] = safe_test_name(issue['name'], args.root)
                issue['location'] = safe_location(issue['location'], args.root)
                issue['message'] = message(issue['message'])
            text += render(data) + '\n'
            if len(text.encode()) > MAX_AGGREGATE_BYTES:
                text = bounded(text.splitlines(), MAX_AGGREGATE_BYTES, True)
                break
        if len(paths) > 128:
            text = bounded(text.splitlines(), MAX_AGGREGATE_BYTES, True)
    else:
        data = collect(args.root)
        (args.root / '.runtime/safe-summary.json').write_text(json.dumps(data, indent=2) + '\n')
        text = render(data)
    if os.environ.get('GITHUB_STEP_SUMMARY'):
        with open(os.environ['GITHUB_STEP_SUMMARY'], 'a') as output:
            output.write(text)
    print(text, end='')
    return int(unavailable)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--aggregate', type=Path)
    args = parser.parse_args()
    try:
        return publish(args)
    except Exception:
        # Never publish exception payloads, parser context, or absolute paths.
        data = {'job': '[redacted]', 'counts': dict.fromkeys(COUNTERS), 'checks': [], 'failures': [], 'unavailable': True}
        text = render(data)
        try:
            (args.root / '.runtime').mkdir(exist_ok=True)
            (args.root / '.runtime/safe-summary.json').write_text(json.dumps(data) + '\n')
            if os.environ.get('GITHUB_STEP_SUMMARY'):
                with open(os.environ['GITHUB_STEP_SUMMARY'], 'a') as output:
                    output.write(text)
        except OSError:
            pass
        print(text, end='')
        return 1


if __name__ == '__main__':
    raise SystemExit(main())

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
import urllib.request
import xml.etree.ElementTree as ET

COUNTERS = ('tests', 'assertions', 'failures', 'errors', 'skipped', 'risky', 'incomplete')
SAFE_MESSAGES = {
    'Failed asserting that false is true.',
    'Failed asserting that true is false.',
    'Failed asserting that null is not null.',
    'Failed asserting that two strings are identical.',
    'Failed asserting that two arrays are identical.',
    'Failed asserting that an array is empty.',
    'Failed asserting that actual size matches expected size.',
}


def safe_identifier(value):
    if (len(value) <= 240 and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_\\: ."#()\-]*', value)
            and not re.search(r'[A-Za-z0-9]{32,}|secret|token|password|bearer', value, re.I)):
        return value
    return '[redacted]'


def message(value):
    first = value.strip().splitlines()[0] if value.strip() else ''
    return first[:160] if first in SAFE_MESSAGES else '[redacted]'


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
    junit = root / 'evidence/candidate-junit.xml'
    if junit.exists():
        for case in ET.parse(junit).iter('testcase'):
            for issue in list(case):
                if issue.tag not in ('failure', 'error'):
                    continue
                body = issue.text or ''
                location = '[redacted]'
                for path, line in re.findall(r'(tests/[A-Za-z0-9_./\-]+\.php):(\d+)\s*$', body, re.M):
                    target = (root / path).resolve()
                    if target.is_relative_to((root / 'tests').resolve()) and target.is_file():
                        location = path + ':' + line
                        break
                failures.append({'name': safe_identifier(case.get('classname', '') + '::' + case.get('name', '')),
                                 'location': location, 'message': message(body)})
    return {'job': safe_identifier(os.environ.get('GITHUB_JOB', 'local')),
            'counts': counts, 'failures': failures,
            'checks': [{'check': int(r['check']), 'exit_code': int(r['exit_code']),
                        'duration_seconds': round(float(r.get('duration_seconds', 0)), 2)} for r in runs]}


def render(data):
    lines = ['| Job | Check | Result | Seconds |', '|---|---:|---|---:|']
    for check in data['checks']:
        lines.append(f"| {data['job']} | {check['check']} | {'success' if check['exit_code'] == 0 else 'failure'} | {check['duration_seconds']} |")
    lines += ['', '| Tests | Assertions | Failures | Errors | Skip | Risky | Incomplete |', '|---:|---:|---:|---:|---:|---:|---:|',
              '| ' + ' | '.join(str(data['counts'][key]) for key in COUNTERS) + ' |', '',
              '| Failed test | File:line | First message line |', '|---|---|---|']
    for issue in data['failures']:
        lines.append('| ' + ' | '.join(issue[key] for key in ('name', 'location', 'message')) + ' |')
    return '\n'.join(lines) + '\n'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--aggregate', type=Path)
    args = parser.parse_args()
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
        for job in jobs:
            seconds = 0
            if job.get('started_at') and job.get('completed_at'):
                seconds = int((datetime.fromisoformat(job['completed_at'].replace('Z', '+00:00')) - datetime.fromisoformat(job['started_at'].replace('Z', '+00:00'))).total_seconds())
            lines.append(f"| {safe_identifier(job['name'])} | {safe_identifier(job['conclusion'] or job['status'])} | {seconds} |")
        text = '\n'.join(lines) + '\n\n'
        for path in sorted(args.aggregate.rglob('safe-summary.json')):
            # Revalidate metadata and messages at the publication boundary.
            data = json.loads(path.read_text())
            data['job'] = safe_identifier(data['job'])
            data['counts'] = {key: max(0, int(data['counts'][key])) for key in COUNTERS}
            for issue in data['failures']:
                issue['name'] = safe_identifier(issue['name'])
                if not re.fullmatch(r'tests/[A-Za-z0-9_./\-]+\.php:\d+', issue['location']) or '..' in issue['location']:
                    issue['location'] = '[redacted]'
                issue['message'] = message(issue['message'])
            text += render(data) + '\n'
    else:
        data = collect(args.root)
        (args.root / '.runtime/safe-summary.json').write_text(json.dumps(data, indent=2) + '\n')
        text = render(data)
    if os.environ.get('GITHUB_STEP_SUMMARY'):
        with open(os.environ['GITHUB_STEP_SUMMARY'], 'a') as output:
            output.write(text)
    print(text, end='')


if __name__ == '__main__':
    main()

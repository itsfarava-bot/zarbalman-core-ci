"""Fail-closed JUnit outcomes and a bounded, two-way pending list."""
import hashlib
import re
import xml.etree.ElementTree as ET


def first_message(text, identity):
    lines = [line.strip() for line in text.splitlines() if line.strip()]
    if lines and lines[0].startswith(identity):
        lines = lines[1:]
    return lines[0] if lines else ''


def check(junit, native_exit, deferred=None, require_all=False):
    deferred = deferred or {}
    errors, failures, selected = [], {}, set()
    counts = dict.fromkeys(('tests', 'assertions', 'failures', 'errors', 'skipped', 'risky'), 0)
    if (not isinstance(deferred, dict) or len(deferred) > 5
            or any(not isinstance(name, str) or '::' not in name or not isinstance(value, str)
                   or not re.fullmatch(r'[a-f0-9]{64}', value) for name, value in deferred.items())):
        deferred = {}
        errors.append('invalid_pending_list')
    try:
        root = ET.parse(junit).getroot()
        if root.tag not in ('testsuites', 'testsuite'):
            errors.append('invalid_junit_root')
        for case in root.iter('testcase'):
            cls = case.get('classname') or case.get('class', '')
            name = cls + '::' + case.get('name', '')
            if not cls or not case.get('name') or name in selected:
                errors.append('invalid_or_duplicate_case')
            selected.add(name)
            counts['tests'] += 1
            counts['assertions'] += int(case.get('assertions', '0'))
            for issue in case:
                if issue.tag == 'skipped':
                    counts['skipped'] += 1
                elif issue.tag == 'error':
                    counts['errors'] += 1
                    if 'Risky' in issue.get('type', ''):
                        counts['risky'] += 1
                elif issue.tag == 'failure':
                    counts['failures'] += 1
                    failures[name] = hashlib.sha256(first_message(issue.text or '', name).encode()).hexdigest()
        for suite in root.iter('testsuite'):
            if any(int(suite.get(key, '0')) > 0 for key in ('skipped', 'risky', 'incomplete', 'warnings')):
                errors.append('nonpassing_suite')
    except (OSError, ValueError, TypeError, ET.ParseError):
        errors.append('invalid_junit')
    if counts['tests'] == 0:
        errors.append('empty_suite')
    if counts['skipped'] or counts['risky'] or counts['errors']:
        errors.append('nonpassing_case')
    expected = set(deferred) & selected
    if require_all and expected != set(deferred):
        errors.append('missing_pending_case')
    if set(failures) - expected:
        errors.append('unreviewed_failure')
    if expected - set(failures):
        errors.append('unexpected_pass')
    if any(failures[name] != deferred[name] for name in expected & set(failures)):
        errors.append('changed_failure')
    if native_exit not in (0, 1) or (native_exit == 1 and not failures) or (native_exit == 0 and failures):
        errors.append('native_exit_mismatch')
    status = (native_exit if native_exit not in (0, 1) else 1) if errors else 0
    return {'exit_code': status, 'counts': counts, 'pending': len(expected), 'errors': sorted(set(errors))}

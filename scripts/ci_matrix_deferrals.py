"""Classify the nine reviewed HTTP rows without changing their evidence gaps."""
import re

DEFERRED_IDS = frozenset('http-' + suffix for suffix in
                         ('003', '004', '013', '014', '015', '020', '021', '042', '048'))


def valid_gaps(value, allow_empty=False):
    return (isinstance(value, list) and (allow_empty or bool(value))
            and all(isinstance(gap, str) and 0 < len(gap) <= 240 for gap in value)
            and len(value) == len(set(value)))


def apply_deferrals(rows, manifest):
    result = {'deferred': 0, 'unreviewed': 0, 'errors': []}
    if (not isinstance(manifest, dict) or set(manifest) != DEFERRED_IDS
            or any(not isinstance(entry, dict) or set(entry) != {'gaps', 'reason', 'required_environment'}
                   or not valid_gaps(entry['gaps'])
                   or any(not isinstance(entry[key], str) or not 0 < len(entry[key].strip()) <= 800
                          for key in ('reason', 'required_environment')) for entry in manifest.values())):
        result['errors'].append('invalid-deferral-manifest')
        return result
    if not isinstance(rows, list):
        result['errors'].append('invalid-matrix-rows')
        return result
    seen = set()
    for row in rows:
        if (not isinstance(row, dict) or not isinstance(row.get('id'), str)
                or re.fullmatch(r'http-[0-9]{3}', row['id']) is None):
            result['errors'].append('invalid-matrix-row')
            continue
        key = row['id']
        if key in seen:
            result['errors'].append('duplicate-matrix-row:' + key)
            continue
        seen.add(key)
        gaps = row.get('gaps')
        if (not valid_gaps(gaps, allow_empty=True)
                or type(row.get('positive_and_negative_complete')) is not bool
                or row['positive_and_negative_complete'] != (not gaps)):
            result['errors'].append('invalid-matrix-row:' + key)
            continue
        if key in manifest:
            if not gaps:
                result['errors'].append('unexpected-deferred-pass:' + key)
            elif set(gaps) != set(manifest[key]['gaps']):
                result['unreviewed'] += 1
                result['errors'].append('changed-deferred-gaps:' + key)
            else:
                result['deferred'] += 1
        elif gaps:
            result['unreviewed'] += 1
            result['errors'].append('unreviewed-row:' + key)
    result['errors'].extend('missing-deferred-row:' + key for key in sorted(DEFERRED_IDS - seen))
    return result

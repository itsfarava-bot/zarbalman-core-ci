"""Run native PHPUnit with a fail-closed, exhaustive full-suite partition.

All detailed output stays under ignored evidence/ for encrypted diagnostics.
The unchanged configuration remains the source of test discovery and ordering.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import xml.etree.ElementTree as ET

sys.path.insert(0, str(Path(__file__).resolve().parent))
from ci_test_results import check

ROOT = Path(__file__).resolve().parents[1]
SECURITY_CLASSES = ('FinanceDestinationTest', 'EndpointSecurityTest')
PARTITION_FILTERS = {
    'main': '/^(?!(?:FinanceDestinationTest|EndpointSecurityTest)::).+$/',
    'security': '/^(?:FinanceDestinationTest|EndpointSecurityTest)::/',
}

# PHPUnit 9.6.31 ignores --filter for --list-tests-xml (Command.php).
# Its XmlTestListRenderer preserves getName(false) and the dataSet suffix;
# NameFilterIterator applies preg_match to class::method plus that suffix.
# Use the same native PCRE engine on the full native inventory, then pass
# precisely the validated fixed filter to the actual PHPUnit invocation.
PCRE_MEMBERSHIP = r'''
$request = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$memberships = [];
foreach ($request['filters'] as $partition => $filter) {
    $memberships[$partition] = [];
    foreach ($request['ids'] as $index => $name) {
        $match = preg_match($filter, $name);
        if ($match === false) { throw new RuntimeException('Invalid partition regex'); }
        if ($match === 1) { $memberships[$partition][] = $index; }
    }
}
echo json_encode($memberships, JSON_THROW_ON_ERROR);
'''


class NativeFailure(Exception):
    def __init__(self, status):
        self.status = status
        super().__init__('Native inventory command failed: ' + str(status))


def native_configuration(root):
    """Append additive coverage without editing or dropping the original suite.

    Queue tests need the fresh database before the older financial fixtures.
    Absolute paths preserve PHPUnit path resolution in the generated evidence file.
    """
    if not (root / 'tests/task7').is_dir():
        return 'phpunit-task4.xml'
    tree = ET.parse(root / 'phpunit-task4.xml')
    config = tree.getroot()
    suites = config.findall('testsuites/testsuite')
    if len(suites) != 1 or not config.get('bootstrap'):
        raise ValueError('Expected one authoritative native suite and bootstrap')
    config.set('bootstrap', str(root / config.get('bootstrap')))
    for node in config.findall('testsuites/testsuite/*'):
        if node.tag not in ('file', 'directory') or not node.text:
            raise ValueError('Unsupported native suite entry')
        node.text = str(root / node.text)
    additions = sorted((root / 'tests/task7').rglob('*Test.php'))
    if not additions:
        raise ValueError('Empty additive native suite')
    for path in additions:
        addition = ET.Element('file')
        addition.text = str(path)
        if path.name == 'ActionSchedulerNativeTest.php':
            suites[0].insert(0, addition)
        else:
            suites[0].append(addition)
    target = 'evidence/task7-phpunit.xml'
    tree.write(root / target, encoding='utf-8', xml_declaration=True)
    return target


def selection(environment):
    partition = environment.get('ZBM_SUITE_PARTITION', 'all')
    focused_filter = environment.get('ZBM_PHPUNIT_FILTER', '')
    scope = environment.get('ZBM_TEST_SCOPE', 'focused' if focused_filter else 'full')
    if partition not in ('all', 'main', 'security') or scope not in ('full', 'focused'):
        raise ValueError('Unknown test scope or suite partition')
    if scope == 'full' and focused_filter:
        raise ValueError('A full run cannot use a focused filter')
    if scope == 'focused' and not focused_filter:
        raise ValueError('Focused scope requires an explicit filter')
    if partition != 'all' and (scope != 'full' or focused_filter):
        raise ValueError('Suite partitions are reserved for unfiltered full runs')
    return partition, focused_filter


def inventory_ids(xml):
    root = ET.fromstring(xml)
    if root.tag != 'tests':
        raise ValueError('Unsupported native inventory root')
    ids = []
    for node in root:
        if node.tag == 'testCaseClass' and node.get('name'):
            if not len(node):
                raise ValueError('Empty native test class')
            for method in node:
                if method.tag != 'testCaseMethod' or not method.get('name') or len(method):
                    raise ValueError('Unsupported native test method')
                name = node.get('name') + '::' + method.get('name')
                if 'dataSet' in method.attrib:
                    name += ' with data set ' + method.get('dataSet')
                ids.append(name)
        elif node.tag == 'phptFile' and node.get('path') and not len(node):
            ids.append(node.get('path'))
        else:
            raise ValueError('Unsupported native inventory entry')
    if not ids or len(ids) != len(set(ids)):
        raise ValueError('Empty or duplicate native inventory')
    return ids


def validate_partition(ids, memberships):
    if not ids or len(ids) != len(set(ids)) or set(memberships) != set(PARTITION_FILTERS):
        raise ValueError('Invalid full inventory or partition keys')
    sets = {}
    for partition, indices in memberships.items():
        if (not isinstance(indices, list) or not indices
                or any(type(index) is not int or index < 0 or index >= len(ids) for index in indices)
                or indices != sorted(set(indices))):
            raise ValueError('Empty, invalid, or unordered partition indices')
        sets[partition] = set(indices)
    if sets['main'] & sets['security'] or sets['main'] | sets['security'] != set(range(len(ids))):
        raise ValueError('Partition must be a disjoint union of the full inventory')
    security = {i for i, name in enumerate(ids) if name.split('::', 1)[0] in SECURITY_CLASSES}
    if sets['security'] != security:
        raise ValueError('Native filter did not select precisely the security dependency pair')
    finance = [i for i in security if ids[i].split('::', 1)[0] == SECURITY_CLASSES[0]]
    endpoint = [i for i in security if ids[i].split('::', 1)[0] == SECURITY_CLASSES[1]]
    if not finance or not endpoint or max(finance) >= min(endpoint):
        raise ValueError('Finance fixture must precede every endpoint security case')
    return {'schema_version': 1,
            'counts': {'all': len(ids), **{key: len(indices) for key, indices in memberships.items()}},
            'partitions': {key: [ids[i] for i in indices] for key, indices in memberships.items()}}


def guard_partition(root, base, partition):
    evidence = root / 'evidence'
    inventory = evidence / 'suite-inventory.xml'
    inventory.unlink(missing_ok=True)
    command = base + ['--list-tests-xml', str(inventory)]
    result = subprocess.run(command, cwd=root, stdout=subprocess.PIPE,
                            stderr=subprocess.STDOUT, text=True)
    (evidence / 'suite-inventory.txt').write_text(result.stdout)
    if result.returncode:
        raise NativeFailure(result.returncode)
    ids = inventory_ids(inventory.read_text())
    result = subprocess.run(['php', '-r', PCRE_MEMBERSHIP], cwd=root,
                            input=json.dumps({'ids': ids, 'filters': PARTITION_FILTERS}),
                            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    (evidence / 'suite-filter-check.txt').write_text(result.stdout)
    if result.returncode:
        raise NativeFailure(result.returncode)
    report = validate_partition(ids, json.loads(result.stdout))
    report.update({'selected_partition': partition, 'filter': PARTITION_FILTERS[partition],
                   'configuration_sha256': hashlib.sha256((root / 'phpunit-task4.xml').read_bytes()).hexdigest(),
                   'run_id': os.environ.get('GITHUB_RUN_ID', 'local'),
                   'run_attempt': os.environ.get('GITHUB_RUN_ATTEMPT', 'local')})
    (evidence / 'suite-partition.json').write_text(json.dumps(report, indent=2) + '\n')
    print('Partition counts: ' + ' '.join(key + '=' + str(count) for key, count in report['counts'].items()))


def main(root=ROOT, environment=None):
    environment = os.environ if environment is None else environment
    root = Path(root)
    evidence = root / 'evidence'
    evidence.mkdir(exist_ok=True)
    try:
        configuration = native_configuration(root)
        base = [str(root / 'vendor/bin/phpunit'), '--configuration=' + configuration]
        partition, focused_filter = selection(environment)
        if partition != 'all':
            guard_partition(root, base, partition)
    except (ValueError, TypeError, OSError, ET.ParseError, NativeFailure) as error:
        (evidence / 'suite-partition-error.txt').write_text(type(error).__name__ + ': ' + str(error) + '\n')
        status = error.status if isinstance(error, NativeFailure) else 1
        print('Suite partition validation exit_code=' + str(status))
        return status
    command = base + ['--log-junit=evidence/candidate-junit.xml']
    if partition != 'all':
        command += ['--filter', PARTITION_FILTERS[partition]]
    elif focused_filter:
        command += ['--filter', focused_filter]
    # Create an empty, fresh marker after native discovery and before execution.
    (evidence / 'http-matrix-run-start').write_bytes(b'')
    (evidence / 'candidate-junit.xml').unlink(missing_ok=True)
    result = subprocess.run(command, cwd=root, stdout=subprocess.PIPE,
                            stderr=subprocess.STDOUT, text=True)
    (evidence / 'candidate-phpunit.txt').write_text(result.stdout)
    summaries = re.findall(r'^(?:OK \(\d+ tests?, \d+ assertions?\)|Tests: \d+, Assertions: \d+(?:, (?:Errors|Failures|Warnings|Skipped|Incomplete|Risky): \d+)*\.)$',
                           result.stdout, re.M)
    for summary in summaries:
        print(summary)
    print('PHPUnit exit_code=' + str(result.returncode))
    try:
        deferred = json.loads((root / 'ci/deferred-tests.json').read_text())
        guarded = check(evidence / 'candidate-junit.xml', result.returncode, deferred,
                        require_all=partition in ('all', 'main') and not focused_filter)
    except (OSError, ValueError, TypeError):
        guarded = {'exit_code': 1, 'errors': ['invalid_pending_list']}
    (evidence / 'test-result-control.json').write_text(json.dumps(guarded, sort_keys=True) + '\n')
    print('Outcome control ' + json.dumps(guarded, sort_keys=True))
    return guarded['exit_code']


if __name__ == '__main__':
    raise SystemExit(main())

"""Native disposable negative probes; these never enter the product inventory."""
import argparse
import json
import hashlib
import os
from pathlib import Path
import subprocess
import tempfile
from ci_test_results import check


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--phpunit', default='vendor/bin/phpunit')
    args = parser.parse_args()
    command = str(Path(args.phpunit).resolve())
    results = {}
    with tempfile.TemporaryDirectory(prefix='zbm-native-outcome-') as tmp:
        root = Path(tmp)
        for mode, method in [('empty', ''),
                             ('skipped', "public function test_probe(): void { self::markTestSkipped('Synthetic negative control'); }"),
                             ('risky', 'public function test_probe(): void {}'),
                             ('success', 'public function test_probe(): void { self::assertTrue(true); }')]:
            directory = root / mode
            directory.mkdir()
            if method:
                (directory / 'SyntheticProbeTest.php').write_text('<?php\nfinal class SyntheticProbeTest extends \\PHPUnit\\Framework\\TestCase { ' + method + ' }\n')
            junit = root / (mode + '.xml')
            native = subprocess.run([command, '--no-configuration', '--log-junit=' + str(junit), str(directory)],
                                    stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
            guarded = check(junit, native.returncode)
            if mode == 'success':
                assert native.returncode == 0 and guarded['exit_code'] == 0
            else:
                assert guarded['exit_code'] != 0
                counter = {'empty': 'tests', 'skipped': 'skipped', 'risky': 'risky'}[mode]
                assert guarded['counts'][counter] == (0 if mode == 'empty' else 1)
            results[mode] = {'native_exit': native.returncode, 'guard_exit': guarded['exit_code'], 'counts': guarded['counts']}
        known = {'SyntheticProbeTest::test_probe': hashlib.sha256(b'Failed asserting that false is true.').hexdigest()}
        for mode, name, expression in [('pending_failure', 'test_probe', 'false'),
                                       ('pending_pass', 'test_probe', 'true'),
                                       ('new_failure', 'test_other', 'false')]:
            directory = root / mode
            directory.mkdir()
            (directory / 'SyntheticProbeTest.php').write_text('<?php\nfinal class SyntheticProbeTest extends \\PHPUnit\\Framework\\TestCase { public function ' + name + '(): void { self::assertTrue(' + expression + '); } }\n')
            junit = root / (mode + '.xml')
            native = subprocess.run([command, '--no-configuration', '--log-junit=' + str(junit), str(directory)],
                                    stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
            guarded = check(junit, native.returncode, known, require_all=True)
            assert guarded['exit_code'] == (0 if mode == 'pending_failure' else 1)
            assert guarded['counts']['tests'] == 1
            results[mode] = {'native_exit': native.returncode, 'guard_exit': guarded['exit_code'], 'counts': guarded['counts']}
    Path('evidence').mkdir(exist_ok=True)
    Path('evidence/native-outcome-probes.json').write_text(json.dumps(results, sort_keys=True) + '\n')
    summary = os.environ.get('GITHUB_STEP_SUMMARY')
    if summary:
        lines = ['| Isolated native control | Native exit | Guard exit | Tests | Skip | Risky |', '|---|---:|---:|---:|---:|---:|']
        for mode, result in results.items():
            counts = result['counts']
            lines.append('| ' + mode + ' | ' + ' | '.join(str(value) for value in [result['native_exit'], result['guard_exit'], counts['tests'], counts['skipped'], counts['risky']]) + ' |')
        with open(summary, 'a') as output:
            output.write('\n'.join(lines) + '\n\n')
    print(json.dumps(results, sort_keys=True))


if __name__ == '__main__':
    main()

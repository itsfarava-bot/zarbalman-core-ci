"""Fail-closed controls for the native PHPUnit full-suite partition guard."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('selected_tests', ROOT / 'scripts/run-selected-tests.py')
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)


class SuitePartitionTest(unittest.TestCase):
    def setUp(self):
        self.ids = ['InstallationTest::test_installation',
                    'FinanceDestinationTest::test_authorized_destination',
                    'EndpointSecurityTest::test_real_http_negative_matrix',
                    'NewFutureTest::test_with_provider with data set "later<&"']
        self.membership = {'main': [0, 3], 'security': [1, 2]}

    def test_native_xml_preserves_methods_and_named_and_numeric_datasets(self):
        xml = ('<tests><testCaseClass name="FutureTest">'
               '<testCaseMethod name="test_one" groups=""/>'
               '<testCaseMethod name="test_provider" dataSet="&quot;later&lt;&amp;&quot;"/>'
               '<testCaseMethod name="test_provider" dataSet="#0"/>'
               '</testCaseClass><phptFile path="tests/future.phpt"/></tests>')
        self.assertEqual(M.inventory_ids(xml), [
            'FutureTest::test_one', 'FutureTest::test_provider with data set "later<&"',
            'FutureTest::test_provider with data set #0', 'tests/future.phpt'])

    def test_unknown_or_duplicate_inventory_entries_fail_closed(self):
        for xml in ('<tests><unknown/></tests>', '<notTests/>',
                    '<tests><testCaseClass name="A"><testCaseMethod name="m"/>'
                    '<testCaseMethod name="m"/></testCaseClass></tests>',
                    '<tests><testCaseClass><testCaseMethod name="m"/></testCaseClass></tests>'):
            with self.subTest(xml=xml), self.assertRaises(ValueError):
                M.inventory_ids(xml)

    def test_future_class_and_datasets_remain_in_main_and_pair_remains_ordered(self):
        report = M.validate_partition(self.ids, self.membership)
        self.assertEqual(report['counts'], {'all': 4, 'main': 2, 'security': 2})
        self.assertEqual(report['partitions']['main'], [self.ids[0], self.ids[3]])

    def test_missing_overlapping_or_empty_partition_fails_closed(self):
        for membership in ({'main': [0], 'security': [1, 2]},
                           {'main': [0, 2, 3], 'security': [1, 2]},
                           {'main': [], 'security': [0, 1, 2, 3]},
                           {'main': [0, 3], 'security': []}):
            with self.subTest(membership=membership), self.assertRaises(ValueError):
                M.validate_partition(self.ids, membership)

    def test_missing_fixture_or_reversed_dependency_order_fails_closed(self):
        for ids, members in (([self.ids[0], self.ids[2]], {'main': [0], 'security': [1]}),
                             ([self.ids[0], self.ids[2], self.ids[1]],
                              {'main': [0], 'security': [1, 2]})):
            with self.subTest(ids=ids), self.assertRaises(ValueError):
                M.validate_partition(ids, members)

    def test_wrong_class_assignment_duplicate_and_invalid_indices_fail_closed(self):
        for membership in ({'main': [0, 2], 'security': [1, 3]},
                           {'main': [0, 0, 3], 'security': [1, 2]},
                           {'main': [False, 3], 'security': [1, 2]},
                           {'main': [0, 8], 'security': [1, 2]}):
            with self.subTest(membership=membership), self.assertRaises(ValueError):
                M.validate_partition(self.ids, membership)

    def test_full_partition_rejects_focused_filter_unknown_partition_and_scope(self):
        for env in ({'ZBM_SUITE_PARTITION': 'main', 'ZBM_PHPUNIT_FILTER': 'test_one'},
                    {'ZBM_SUITE_PARTITION': 'security', 'ZBM_TEST_SCOPE': 'focused'},
                    {'ZBM_SUITE_PARTITION': 'typo'},
                    {'ZBM_SUITE_PARTITION': 'main', 'ZBM_TEST_SCOPE': 'typo'},
                    {'ZBM_TEST_SCOPE': 'full', 'ZBM_PHPUNIT_FILTER': 'test_one'}):
            with self.subTest(env=env), self.assertRaises(ValueError):
                M.selection(env)
        self.assertEqual(M.selection({}), ('all', ''))
        self.assertEqual(M.selection({'ZBM_PHPUNIT_FILTER': 'test_one'}), ('all', 'test_one'))

    def test_empty_focused_scope_cannot_accidentally_execute_full_suite(self):
        with self.assertRaises(ValueError):
            M.selection({'ZBM_TEST_SCOPE': 'focused'})

    def run_fake_native(self, root, exit_code=0, list_exit=0, bad_membership=False):
        calls = []
        def native(command, **kwargs):
            calls.append(command)
            if '--list-tests-xml' in command:
                target = Path(command[command.index('--list-tests-xml') + 1])
                target.write_text('<tests><testCaseClass name="InstallationTest">'
                    '<testCaseMethod name="test_installation"/></testCaseClass>'
                    '<testCaseClass name="FinanceDestinationTest">'
                    '<testCaseMethod name="test_authorized_destination"/></testCaseClass>'
                    '<testCaseClass name="EndpointSecurityTest">'
                    '<testCaseMethod name="test_real_http_negative_matrix"/></testCaseClass>'
                    '<testCaseClass name="NewFutureTest">'
                    '<testCaseMethod name="test_with_provider" dataSet="&quot;later&lt;&amp;&quot;"/>'
                    '</testCaseClass></tests>')
                return subprocess.CompletedProcess(command, list_exit, 'private inventory output')
            if command[0] == 'php':
                request = json.loads(kwargs['input'])
                self.assertEqual(request['ids'], self.ids)
                self.assertEqual(request['filters'], M.PARTITION_FILTERS)
                memberships = {'main': [0], 'security': [1, 2]} if bad_membership else self.membership
                return subprocess.CompletedProcess(command, 0, json.dumps(memberships))
            self.assertTrue((root / 'evidence/http-matrix-run-start').is_file())
            self.assertEqual((root / 'evidence/http-matrix-run-start').stat().st_size, 0)
            return subprocess.CompletedProcess(command, exit_code,
                                               'private trace and sensitive fixture\nTests: 2, Assertions: 9, Failures: 1.\n')
        return native, calls

    def test_each_shard_uses_guarded_fixed_filter_preserves_native_status_and_private_logs(self):
        for partition in ('main', 'security'):
            with self.subTest(partition=partition), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)
                (root / 'phpunit-task4.xml').write_text('<phpunit/>')
                native, calls = self.run_fake_native(root, exit_code=7)
                output = io.StringIO()
                with patch.object(M.subprocess, 'run', side_effect=native), contextlib.redirect_stdout(output):
                    status = M.main(root, {'ZBM_SUITE_PARTITION': partition, 'ZBM_TEST_SCOPE': 'full'})
                self.assertEqual(status, 7)
                self.assertEqual(len(calls), 3)
                self.assertNotIn('--filter', calls[0])
                self.assertEqual(calls[-1][-2:], ['--filter', M.PARTITION_FILTERS[partition]])
                self.assertIn('--configuration=phpunit-task4.xml', calls[0])
                self.assertIn('--configuration=phpunit-task4.xml', calls[-1])
                self.assertNotIn('private', output.getvalue())
                self.assertIn('Tests: 2, Assertions: 9, Failures: 1.', output.getvalue())
                self.assertIn('private trace', (root / 'evidence/candidate-phpunit.txt').read_text())
                self.assertEqual(json.loads((root / 'evidence/suite-partition.json').read_text())['counts'],
                                 {'all': 4, 'main': 2, 'security': 2})

    def test_failed_native_inventory_or_invalid_union_blocks_execution(self):
        for list_exit, bad_membership in ((6, False), (0, True)):
            with self.subTest(list_exit=list_exit), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)
                (root / 'phpunit-task4.xml').write_text('<phpunit/>')
                native, calls = self.run_fake_native(root, list_exit=list_exit, bad_membership=bad_membership)
                with patch.object(M.subprocess, 'run', side_effect=native), contextlib.redirect_stdout(io.StringIO()):
                    self.assertNotEqual(M.main(root, {'ZBM_SUITE_PARTITION': 'main'}), 0)
                self.assertFalse(any('--log-junit=evidence/candidate-junit.xml' in cmd for cmd in calls))

    def test_default_and_focused_modes_preserve_existing_cli_selection(self):
        for env, suffix in (({}, []), ({'ZBM_PHPUNIT_FILTER': 'FutureTest::test_one'},
                                       ['--filter', 'FutureTest::test_one'])):
            with self.subTest(env=env), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)
                calls = []
                def native(command, **kwargs):
                    calls.append(command)
                    return subprocess.CompletedProcess(command, 0, 'OK (1 test, 2 assertions)\n')
                with patch.object(M.subprocess, 'run', side_effect=native), contextlib.redirect_stdout(io.StringIO()):
                    self.assertEqual(M.main(root, env), 0)
                self.assertEqual(len(calls), 1)
                expected = [str(root / 'vendor/bin/phpunit'), '--configuration=phpunit-task4.xml',
                            '--log-junit=evidence/candidate-junit.xml'] + suffix
                self.assertEqual(calls[0], expected)


if __name__ == '__main__':
    unittest.main()

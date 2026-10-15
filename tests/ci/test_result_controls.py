import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('result_controls', ROOT / 'scripts/ci_test_results.py')
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)


class ResultControlsTest(unittest.TestCase):
    def gate(self, cases, native=0, deferred=None, require_all=False):
        with tempfile.TemporaryDirectory() as tmp:
            junit = Path(tmp) / 'junit.xml'
            junit.write_text('<testsuites><testsuite>' + cases + '</testsuite></testsuites>')
            return M.check(junit, native, deferred or {}, require_all)

    def test_nonempty_native_success_remains_success(self):
        self.assertEqual(0, self.gate('<testcase classname="Synthetic" name="test_ok" assertions="1"/>')['exit_code'])

    def test_zero_skip_and_risky_always_fail(self):
        for cases in ['', '<testcase classname="Synthetic" name="test_skip"><skipped/></testcase>',
                      '<testcase classname="Synthetic" name="test_risky"><error type="RiskyTestError">No assertions</error></testcase>']:
            with self.subTest(cases=cases):
                self.assertNotEqual(0, self.gate(cases)['exit_code'])

    def test_known_failures_are_exact_and_two_way(self):
        known = {'Synthetic::test_pending': hashlib.sha256(b'Synthetic assertion mismatch').hexdigest()}
        failed = '<testcase classname="Synthetic" name="test_pending"><failure>Synthetic assertion mismatch</failure></testcase>'
        self.assertEqual(0, self.gate(failed, 1, known, True)['exit_code'])
        for altered, status in [('<testcase classname="Synthetic" name="test_pending" assertions="1"/>', 0),
                                (failed.replace('mismatch', 'different cause'), 1),
                                (failed.replace('test_pending', 'test_other'), 1),
                                ('<testcase classname="Synthetic" name="test_ok" assertions="1"/>', 0)]:
            with self.subTest(altered=altered):
                self.assertNotEqual(0, self.gate(altered, status, known, True)['exit_code'])

    def test_focused_run_does_not_require_unselected_pending_cases(self):
        known = {'Synthetic::test_pending': hashlib.sha256(b'Pending').hexdigest()}
        self.assertEqual(0, self.gate('<testcase classname="Synthetic" name="test_ok" assertions="1"/>', 0, known)['exit_code'])

    def test_native_infrastructure_error_cannot_be_overridden(self):
        self.assertEqual(7, self.gate('<testcase classname="Synthetic" name="test_ok" assertions="1"/>', 7)['exit_code'])

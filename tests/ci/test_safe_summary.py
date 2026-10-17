import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class SafeSummaryTest(unittest.TestCase):
    def run_summary(self, log, junit='', expected_exit=0):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / '.runtime').mkdir()
            (root / 'evidence').mkdir()
            (root / '.runtime/check-1.log').write_text(log)
            (root / '.runtime/public-summary.json').write_text(json.dumps({'runs': [{'check': 1, 'exit_code': 1, 'duration_seconds': 2.5}]}))
            (root / 'tests').mkdir()
            (root / 'tests/SyntheticTest.php').write_text('<?php\n\nfinal class SyntheticTest {\n\n public function test_safe(): void {\n }\n}\n')
            (root / '.github/workflows').mkdir(parents=True)
            (root / '.github/workflows/task1.yml').write_text('jobs:\n  synthetic:\n    runs-on: ubuntu-24.04\n')
            if junit:
                (root / 'evidence/candidate-junit.xml').write_text(junit)
            summary = root / 'summary.md'
            result = subprocess.run(['python3', str(ROOT / 'scripts/safe-summary.py'), '--root', str(root)],
                                    env={**os.environ, 'GITHUB_STEP_SUMMARY': str(summary), 'GITHUB_JOB': 'synthetic'},
                                    capture_output=True, text=True)
            self.assertNotIn('Traceback', result.stderr)
            self.assertEqual(expected_exit, result.returncode, result.stderr)
            # Check both publication channels for accidental raw diagnostics.
            return summary.read_text() + result.stdout, json.loads((root / '.runtime/safe-summary.json').read_text())

    def test_publish_only_safe_failure_metadata(self):
        xml = '''<testsuites><testsuite><testcase classname="SyntheticTest" name="test_safe" file="/runner/tests/SyntheticTest.php" line="2"><failure>Failed asserting that false is true.
/runner/tests/SyntheticTest.php:7
secret@example.invalid token=synthetic-only-secret</failure></testcase></testsuite></testsuites>'''
        summary, data = self.run_summary('Tests: 1, Assertions: 2, Failures: 1.\n', xml)
        self.assertIn('SyntheticTest::test_safe', summary)
        self.assertIn('tests/SyntheticTest.php:7', summary)
        self.assertIn('Failed asserting that false is true.', summary)
        self.assertNotIn('/runner', summary)
        self.assertNotIn('secret@example', summary)
        self.assertNotIn('synthetic-only-secret', summary)
        self.assertEqual({'tests': 1, 'assertions': 2, 'failures': 1, 'errors': 0, 'skipped': 0, 'risky': 0, 'incomplete': 0}, data['counts'])

    def test_redact_entire_suspicious_first_line_and_bound_output(self):
        for message in ['buyer@example.invalid', 'Bearer synthetic-private-token', '/srv/customer/order.php',
                        'amount 1000000000000', 'reference abcdef1234567890', '{"data":"private"}',
                        'https://example.invalid/callback', 'token ' + 'x' * 200, 'Failed asserting 503 equals 400.']:
            with self.subTest(message=message[:30]):
                from xml.sax.saxutils import escape
                xml = '<testsuites><testsuite><testcase classname="SyntheticTest" name="test_safe"><failure>' + escape(message) + '\n/runner/tests/SyntheticTest.php:7</failure></testcase></testsuite></testsuites>'
                summary, data = self.run_summary('Tests: 1, Assertions: 1, Failures: 1.', xml)
                self.assertEqual('[redacted]', data['failures'][0]['message'])
                self.assertNotIn(message, summary)

    def test_no_payload_published_without_junit(self):
        summary, data = self.run_summary('raw synthetic body token=do-not-publish\nOK (2 tests, 3 assertions)')
        self.assertNotIn('do-not-publish', summary)
        self.assertEqual(2, data['counts']['tests'])
        self.assertEqual([], data['failures'])

    def test_public_annotation_contains_only_bounded_sanitized_result_metadata(self):
        xml = '''<testsuites><testsuite tests="1" assertions="2" failures="1" errors="0" skipped="0"><testcase classname="SyntheticTest" name="test_safe"><failure>Bearer synthetic-private-token
/runner/tests/SyntheticTest.php:7</failure></testcase></testsuite></testsuites>'''
        summary, data = self.run_summary('Tests: 1, Assertions: 2, Failures: 1.', xml)
        prefix = '::notice title=Verification result::'
        notices = [line for line in summary.splitlines() if line.startswith(prefix)]
        self.assertEqual(1, len(notices))
        result = json.loads(notices[0][len(prefix):])
        self.assertEqual(data['counts'], result['counts'])
        self.assertEqual(['tests/SyntheticTest.php:7'], result['locations'])
        self.assertEqual({'counts', 'locations'}, set(result))
        self.assertNotIn('synthetic-private-token', summary)
        self.assertNotIn('/runner', notices[0])

    def test_dataset_cannot_publish_contact_number_or_identifier(self):
        xml = '<testsuites><testsuite><testcase classname="SyntheticTest" name="test_safe with data set &quot;09120000000&quot;"><failure>Failed asserting that false is true.</failure></testcase></testsuite></testsuites>'
        summary, data = self.run_summary('Tests: 1, Assertions: 1, Failures: 1.', xml)
        self.assertNotIn('09120000000', summary)
        self.assertEqual('[redacted]', data['failures'][0]['name'])

    def test_native_junit_header_is_not_the_failure_message(self):
        xml = '<testsuites><testsuite><testcase classname="SyntheticTest" name="test_safe"><failure>SyntheticTest::test_safe(synthetic-private-value)\nFailed asserting that false is true.\n/runner/tests/SyntheticTest.php:7</failure></testcase></testsuite></testsuites>'
        summary, data = self.run_summary('Tests: 1, Assertions: 1, Failures: 1.', xml)
        self.assertEqual('Failed asserting that false is true.', data['failures'][0]['message'])
        self.assertNotIn('synthetic-private-value', summary)

    def test_unverified_dataset_values_and_fake_method_names_are_redacted(self):
        for label in ['customer-Alice-Smith', 'sk_live_abCdEfGhiJkl', '123.45 USD']:
            xml = '<testsuites><testsuite><testcase classname="SyntheticTest" name="test_safe with data set &quot;' + label + '&quot;"><failure>Failed asserting that false is true.</failure></testcase></testsuite></testsuites>'
            summary, data = self.run_summary('Tests: 1, Assertions: 1, Failures: 1.', xml)
            self.assertNotIn(label, summary)
            self.assertEqual('[redacted]', data['failures'][0]['name'])
        xml = '<testsuites><testsuite><testcase classname="SyntheticTest" name="test_sk_live_private"><failure>Failed asserting that false is true.</failure></testcase></testsuite></testsuites>'
        summary, data = self.run_summary('Tests: 1, Assertions: 1, Failures: 1.', xml)
        self.assertEqual('[redacted]', data['failures'][0]['name'])

    def test_diagnostic_paths_and_line_numbers_cannot_publish_payloads(self):
        for location in ['tests/SYNTHETIC_PRIVATE_VALUE/../SyntheticTest.php:2',
                         'tests/SyntheticTest.php:09120000000', 'tests/SyntheticTest.php:999999',
                         'tests//SyntheticTest.php:2', 'tests/./SyntheticTest.php:2']:
            with self.subTest(location=location):
                xml = '<testsuites><testsuite><testcase classname="SyntheticTest" name="test_safe"><failure>Failed asserting that false is true.\n' + location + '</failure></testcase></testsuite></testsuites>'
                summary, data = self.run_summary('Tests: 1, Assertions: 1, Failures: 1.', xml)
                self.assertEqual('[redacted]', data['failures'][0]['location'])
                self.assertNotIn(location, summary)
                self.assertNotIn('SYNTHETIC_PRIVATE_VALUE', summary)
                self.assertNotIn('09120000000', summary)

    def test_malformed_junit_publishes_fixed_unavailable_summary_without_trace(self):
        summary, data = self.run_summary('raw synthetic secret', '<testsuites>', expected_exit=1)
        self.assertTrue(data['unavailable'])
        self.assertIn('[redacted]', summary)
        self.assertIsNone(data['counts']['tests'])


if __name__ == '__main__':
    unittest.main()

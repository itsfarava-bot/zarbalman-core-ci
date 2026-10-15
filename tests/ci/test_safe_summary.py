import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class SafeSummaryTest(unittest.TestCase):
    def run_summary(self, log, junit=''):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / '.runtime').mkdir()
            (root / 'evidence').mkdir()
            (root / '.runtime/check-1.log').write_text(log)
            (root / '.runtime/public-summary.json').write_text(json.dumps({'runs': [{'check': 1, 'exit_code': 1, 'duration_seconds': 2.5}]}))
            (root / 'tests').mkdir()
            (root / 'tests/SyntheticTest.php').write_text('<?php')
            if junit:
                (root / 'evidence/candidate-junit.xml').write_text(junit)
            summary = root / 'summary.md'
            result = subprocess.run(['python3', str(ROOT / 'scripts/safe-summary.py'), '--root', str(root)],
                                    env={**os.environ, 'GITHUB_STEP_SUMMARY': str(summary), 'GITHUB_JOB': 'synthetic'},
                                    capture_output=True, text=True)
            self.assertEqual(0, result.returncode, result.stderr)
            return summary.read_text(), json.loads((root / '.runtime/safe-summary.json').read_text())

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


if __name__ == '__main__':
    unittest.main()

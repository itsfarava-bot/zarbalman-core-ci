import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('summary_limits', ROOT / 'scripts/safe-summary.py')
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)


class SummaryLimitsTest(unittest.TestCase):
    def data(self, rows):
        return {'job': 'synthetic', 'counts': dict.fromkeys(M.COUNTERS, 0),
                'checks': [{'check': 1, 'exit_code': 1, 'duration_seconds': 1}],
                'failures': [{'name': '[redacted]', 'location': '[redacted]', 'message': '[redacted]'} for _ in range(rows)]}

    def test_per_job_rows_and_bytes_are_bounded(self):
        rendered = M.render(self.data(20000))
        self.assertLessEqual(len(rendered.encode()), 256 * 1024)
        self.assertLessEqual(sum(line.startswith('| [redacted] |') for line in rendered.splitlines()), 1000)
        self.assertTrue('Summary truncated' in rendered, 'Fixed truncation notice required.')

    def test_single_oversized_row_does_not_exceed_budget(self):
        data = self.data(1); data['failures'][0]['name'] = 'x' * 300000
        rendered = M.render(data)
        self.assertLessEqual(len(rendered.encode()), 256 * 1024)
        self.assertTrue('Summary truncated' in rendered, 'Fixed truncation notice required.')

    def test_aggregate_has_an_independent_byte_budget(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp); artifacts = root / 'summaries'; artifacts.mkdir()
            (root / '.github/workflows').mkdir(parents=True)
            (root / '.github/workflows/task1.yml').write_text('jobs:\n  synthetic:\n    runs-on: ubuntu-24.04\n')
            for index in range(30):
                directory = artifacts / str(index); directory.mkdir()
                (directory / 'safe-summary.json').write_text(json.dumps(self.data(1000)))
            summary = root / 'summary.md'
            jobs = {'jobs': [{'name': 'synthetic', 'conclusion': 'success', 'status': 'completed'}]}
            with patch.dict(os.environ, {'GITHUB_REPOSITORY': 'synthetic/repo', 'GITHUB_RUN_ID': '1',
                                         'GH_TOKEN': 'synthetic-placeholder', 'GITHUB_STEP_SUMMARY': str(summary)}), \
                 patch.object(M.urllib.request, 'urlopen', return_value=io.BytesIO(json.dumps(jobs).encode())), \
                 contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(0, M.publish(SimpleNamespace(root=root, aggregate=artifacts)))
            rendered = summary.read_bytes()
            self.assertLessEqual(len(rendered), 900000)
            self.assertTrue(b'Summary truncated' in rendered, 'Fixed truncation notice required.')

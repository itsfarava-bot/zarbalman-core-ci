"""Public summaries report only validated deferral counts and bounded durations."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('deferred_summary', ROOT / 'scripts/safe-summary.py')
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)


class SummaryDeferralsTest(unittest.TestCase):
    def test_matrix_pending_is_revalidated_and_missing_evidence_is_not_a_pass(self):
        self.assertTrue(hasattr(M, 'matrix_pending'), 'Summary must validate the matrix deferral guard.')
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.assertIsNone(M.matrix_pending(root))
            (root / 'ci').mkdir(); (root / 'evidence').mkdir()
            ids = ['http-' + n for n in ('003', '004', '015', '042')]
            rows = [{'id': 'http-%03d' % n, 'gaps': ['positive:synthetic'] if 'http-%03d' % n in ids else [],
                     'positive_and_negative_complete': 'http-%03d' % n not in ids} for n in range(1, 87)]
            manifest = {key: {'gaps': ['positive:synthetic'], 'reason': 'Synthetic pending control.',
                              'required_environment': 'Disposable native fixture.'} for key in ids}
            (root / 'ci/deferred-http-matrix.json').write_text(json.dumps(manifest))
            path = root / 'evidence/http-matrix-coverage.json'
            path.write_text(json.dumps({'rows': rows, 'errors': []}))
            self.assertEqual(4, M.matrix_pending(root))
            path.write_text(json.dumps({'rows': rows, 'errors': ['stale-run-evidence']}))
            self.assertEqual(0, M.matrix_pending(root))
            rows[2].update(gaps=[], positive_and_negative_complete=True)
            path.write_text(json.dumps({'rows': rows, 'errors': [], 'summary': {'deferred': 4}}))
            self.assertEqual(0, M.matrix_pending(root), 'A claimed summary count must not override actual row gaps.')
            path.write_text(json.dumps({'rows': rows[:-1], 'errors': []}))
            self.assertEqual(0, M.matrix_pending(root))

    def test_both_pending_counts_are_visible_in_rendered_step_summary(self):
        data = {'job': 'candidate-regressions', 'counts': dict.fromkeys(M.COUNTERS, 0),
                'checks': [], 'failures': [], 'pending': 5, 'matrix_pending': 4}
        self.assertIn('Deferred tests: 5; deferred HTTP matrix rows: 4.', M.render(data))
        data['matrix_pending'] = 9
        with self.assertRaises(ValueError):
            M.render(data)

    def test_skipped_jobs_cannot_publish_negative_duration(self):
        self.assertTrue(hasattr(M, 'job_seconds'), 'Job durations must be normalized at publication.')
        self.assertEqual(0, M.job_seconds({'started_at': '2026-01-01T00:00:01Z', 'completed_at': '2026-01-01T00:00:00Z'}))
        self.assertEqual(12, M.job_seconds({'started_at': '2026-01-01T00:00:00Z', 'completed_at': '2026-01-01T00:00:12Z'}))
        self.assertEqual(0, M.job_seconds({'started_at': '2026-01-01T00:00:00Z'}))


if __name__ == '__main__': unittest.main()

"""Two-way HTTP coverage deferrals preserve every original evidence gap."""
import copy
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
IDS = ('http-003', 'http-004', 'http-015', 'http-042')


class MatrixDeferralsTest(unittest.TestCase):
    def setUp(self):
        path = ROOT / 'scripts/ci_matrix_deferrals.py'
        self.assertTrue(path.is_file(), 'Missing two-way HTTP coverage deferral control.')
        spec = importlib.util.spec_from_file_location('matrix_deferrals', path)
        self.module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.module)
        self.rows = [{'id': key, 'gaps': ['positive:synthetic-proof'],
                      'positive_and_negative_complete': False} for key in IDS]
        self.rows.append({'id': 'http-001', 'gaps': [], 'positive_and_negative_complete': True})
        self.manifest = {key: {'gaps': ['positive:synthetic-proof'],
                              'reason': 'Synthetic evidence is not yet available.',
                              'required_environment': 'Disposable native HTTP fixture.'} for key in IDS}

    def test_exact_four_are_explicitly_pending_without_closing_any_gap(self):
        before = copy.deepcopy(self.rows)
        result = self.module.apply_deferrals(self.rows, self.manifest)
        self.assertEqual([], result['errors'])
        self.assertEqual(4, result['deferred'])
        self.assertEqual(0, result['unreviewed'])
        self.assertEqual(before, self.rows)

    def test_a_new_gap_outside_the_manifest_fails(self):
        self.rows[-1].update(gaps=['negative:wrong_role'], positive_and_negative_complete=False)
        result = self.module.apply_deferrals(self.rows, self.manifest)
        self.assertEqual(1, result['unreviewed'])
        self.assertIn('unreviewed-row:http-001', result['errors'])

    def test_added_removed_or_replaced_known_gaps_fail(self):
        for gaps in (['positive:synthetic-proof', 'negative:wrong_role'], ['negative:wrong_role']):
            with self.subTest(gaps=gaps):
                rows = copy.deepcopy(self.rows); rows[0]['gaps'] = gaps
                self.assertIn('changed-deferred-gaps:http-003', self.module.apply_deferrals(rows, self.manifest)['errors'])
        rows = copy.deepcopy(self.rows); rows[0]['gaps'] += ['branch:synthetic']
        manifest = copy.deepcopy(self.manifest); manifest['http-003']['gaps'] += ['branch:synthetic']
        rows[0]['gaps'] = ['positive:synthetic-proof']
        self.assertIn('changed-deferred-gaps:http-003', self.module.apply_deferrals(rows, manifest)['errors'])

    def test_sudden_success_of_one_or_all_deferred_rows_fails(self):
        for count in (1, 4):
            rows = copy.deepcopy(self.rows)
            for row in rows[:count]: row.update(gaps=[], positive_and_negative_complete=True)
            result = self.module.apply_deferrals(rows, self.manifest)
            self.assertIn('unexpected-deferred-pass:http-003', result['errors'])
            self.assertEqual(4-count, result['deferred'])

    def test_retired_rows_cannot_reappear_as_gaps_or_be_silently_deferred(self):
        for key in ('http-013', 'http-014', 'http-020', 'http-021', 'http-048'):
            rows = self.rows + [{'id': key, 'gaps': ['branch:regression'], 'positive_and_negative_complete': False}]
            self.assertIn('unreviewed-row:' + key, self.module.apply_deferrals(rows, self.manifest)['errors'])
            manifest = copy.deepcopy(self.manifest); manifest[key] = manifest['http-003']
            self.assertIn('invalid-deferral-manifest', self.module.apply_deferrals(rows, manifest)['errors'])

    def test_missing_duplicate_or_malformed_row_is_never_accepted(self):
        variants = [self.rows[1:], self.rows + [self.rows[0]],
                    [*self.rows[:-1], None], [*self.rows[:-1], {'id': 'http-001', 'gaps': 'payload'}]]
        for rows in variants:
            with self.subTest(rows=rows):
                self.assertTrue(self.module.apply_deferrals(rows, self.manifest)['errors'])
        for gaps, complete in (([], False), (['positive:synthetic-proof'], True), ([True], False)):
            rows = copy.deepcopy(self.rows); rows[0].update(gaps=gaps, positive_and_negative_complete=complete)
            self.assertTrue(self.module.apply_deferrals(rows, self.manifest)['errors'])

    def test_manifest_requires_exact_ids_unique_gaps_reason_and_environment(self):
        variants = []
        missing = copy.deepcopy(self.manifest); del missing['http-003']; variants.append(missing)
        extra = copy.deepcopy(self.manifest); extra['http-099'] = extra['http-003']; variants.append(extra)
        for key, value in [('reason', ''), ('required_environment', ' '), ('gaps', []),
                           ('gaps', ['a', 'a']), ('gaps', [False]), ('reason', None)]:
            bad = copy.deepcopy(self.manifest); bad['http-003'][key] = value; variants.append(bad)
        variants.extend([None, [], {'http-003': None}])
        for manifest in variants:
            with self.subTest(manifest=manifest):
                self.assertTrue(self.module.apply_deferrals(self.rows, manifest)['errors'])


if __name__ == '__main__': unittest.main()

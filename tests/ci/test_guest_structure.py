"""Structural guest proof may close guest gaps only, with native HTTP evidence."""
import copy
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('guest_matrix', ROOT / 'scripts/check-http-matrix.py')
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)


class GuestStructureTest(unittest.TestCase):
    def setUp(self):
        self.row = {'id': 'http-001', 'target': {'kind': 'action', 'hook': 'admin_post_zbm_core_self_test'}}
        suffixes = ['zbm_workflows', 'zbm_audit_logs', 'zbm_idempotency_keys', 'zbm_order_weights',
                    'zbm_purchase_requests', 'zbm_payments', 'zbm_ledger_entries', 'zbm_financial_actions',
                    'zbm_notification_outbox', 'zbm_dokan_credit_mirrors', 'zbm_managed_cases', 'zbm_managed_case_commands',
                    'posts', 'postmeta', 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'wc_orders',
                    'wc_order_addresses', 'wc_order_operational_data', 'wc_orders_meta', 'dokan_vendor_balance', 'dokan_orders',
                    'users', 'usermeta', 'protected_options']
        snapshot = {'synthetic_' + suffix: 'a' * 64 for suffix in suffixes}
        self.proof = {'state': 'passed', 'assertion_status': 'passed', 'actual_http': True,
                      'catalog_ids': ['http-001'], 'test': 'GuestStructureTest::test_private_dispatchers_have_no_guest_hook_and_preserve_state',
                      'table_prefix': 'synthetic_', 'scope': 'native-dispatcher-wp-die', 'protected_hook': 'admin_post_zbm_core_self_test',
                      'guest_hook': 'admin_post_nopriv_zbm_core_self_test', 'guest_hook_registered': False,
                      'guest_actor_id': 0, 'empty_cookie': True, 'http': 400, 'path': 'wp-admin/admin-post.php',
                      'before_snapshot': snapshot, 'after_snapshot': copy.deepcopy(snapshot)}
        self.junit = {('GuestStructureTest', 'test_private_dispatchers_have_no_guest_hook_and_preserve_state'): True}

    def overlay(self, proof=None, junit=None):
        self.assertTrue(hasattr(M, 'apply_guest_overlay'), 'Structural guest evidence requires a dedicated checked overlay.')
        return M.apply_guest_overlay(self.row, ['negative:op:guest', 'negative:form:guest', 'negative:other/guest', 'negative:wrong_role', 'negative:other_buyer', 'negative:other_vendor', 'negative:no_nonce', 'positive:guest'],
                                     {'http-001': self.proof if proof is None else proof}, self.junit if junit is None else junit)

    def test_valid_native_absence_closes_only_guest_gaps(self):
        self.assertEqual(['negative:wrong_role', 'negative:other_buyer', 'negative:other_vendor', 'negative:no_nonce', 'positive:guest'], self.overlay())

    def test_callback_presence_or_unobserved_scope_cannot_close_guest_gaps(self):
        for key, value in [('guest_hook_registered', True), ('scope', 'cli'), ('actual_http', False),
                           ('guest_actor_id', 1), ('empty_cookie', False), ('http', 200),
                           ('guest_hook', 'admin_post_nopriv_other'), ('protected_hook', 'admin_post_other'),
                           ('path', 'test-harness.php'), ('catalog_ids', ['http-002'])]:
            with self.subTest(key=key):
                bad = copy.deepcopy(self.proof); bad[key] = value
                self.assertIn('negative:op:guest', self.overlay(bad))

    def test_missing_or_nonpassing_native_test_cannot_close_guest_gaps(self):
        self.assertIn('negative:op:guest', self.overlay(junit={}))
        self.assertIn('negative:op:guest', self.overlay(junit={next(iter(self.junit)): False}))

    def test_rest_and_public_actions_cannot_use_private_action_proof(self):
        self.row['target']['kind'] = 'REST'
        self.assertIn('negative:op:guest', self.overlay())
        self.row['target']['kind'] = 'action'
        self.row['target']['hook'] = 'admin_post_nopriv_zbm_core_self_test'
        self.assertIn('negative:op:guest', self.overlay())

    def test_prefix_without_trailing_separator_is_supported_exactly(self):
        proof = copy.deepcopy(self.proof); proof['table_prefix'] = '_ci'
        for key in ('before_snapshot', 'after_snapshot'):
            proof[key] = {'_ci' + name.removeprefix('synthetic_'): digest for name, digest in proof[key].items()}
        self.assertEqual(['negative:wrong_role', 'negative:other_buyer', 'negative:other_vendor', 'negative:no_nonce', 'positive:guest'], self.overlay(proof))
        proof['table_prefix'] = '_other'
        self.assertIn('negative:op:guest', self.overlay(proof))

    def test_every_financial_order_and_settings_snapshot_must_match(self):
        for key in self.proof['before_snapshot']:
            with self.subTest(key=key):
                bad = copy.deepcopy(self.proof); bad['after_snapshot'][key] = 'b' * 64
                self.assertIn('negative:op:guest', self.overlay(bad))
                bad = copy.deepcopy(self.proof); del bad['before_snapshot'][key]; del bad['after_snapshot'][key]
                self.assertIn('negative:op:guest', self.overlay(bad))

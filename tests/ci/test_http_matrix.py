"""Regression controls for the permanent checker (no WordPress mocks)."""
import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('http_matrix', ROOT / 'scripts/check-http-matrix.py')
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)


class HttpMatrixTest(unittest.TestCase):
    def setUp(self):
        self.row = {'id': 'http-001', 'kind': 'action', 'coverage': {'type': 'remaining', 'test': 'RemainingEndpointsTest::test_real', 'keys': ['zbm_finance_destination']}}
        self.catalog = {'rows': [self.row]}
        self.registered = {'rows': {'http-001': {'registered': True}}}
        self.junit = {('RemainingEndpointsTest', 'test_real'): True, ('RegisteredHttpSurfacesTest', 'test_production_surface_callback_is_actually_registered with data set "http-001"'): True}
        denial = {'assertion_status': 'passed', 'http': 403, 'effect': 'denied-no-persisted-effect', 'reason': 'exact finance denial'}
        self.controls = {key: copy.deepcopy(denial) for key in M.NEGATIVES}
        self.controls['authorized_positive'] = {'assertion_status': 'passed', 'http': 200, 'effect': 'private destination returned only with durable view audit', 'audit_id': 12}

    def evaluate(self):
        return M.evaluate(self.catalog, self.registered, {'zbm_finance_destination': self.controls}, {}, {}, self.junit)[0]

    def test_valid_persisted_positive_and_distinct_rejections_pass(self):
        self.assertTrue(self.evaluate()['positive_and_negative_complete'])

    def test_missing_registration_fails_even_with_passing_http_test(self):
        self.registered['rows']['http-001']['registered'] = False
        self.assertIn('registration', self.evaluate()['gaps'])

    def test_missing_registered_callback_junit_is_not_proof(self):
        del self.junit[('RegisteredHttpSurfacesTest', 'test_production_surface_callback_is_actually_registered with data set "http-001"')]
        self.assertIn('registration-junit', self.evaluate()['gaps'])

    def test_positive_status_without_durable_effect_fails(self):
        self.controls['authorized_positive'] = {'assertion_status': 'passed', 'http': 200}
        self.assertIn('zbm_finance_destination:positive-control', self.evaluate()['gaps'])

    def test_persisted_claim_without_audit_id_fails(self):
        del self.controls['authorized_positive']['audit_id']
        self.assertFalse(self.evaluate()['positive_and_negative_complete'])

    def test_generic_wordpress_400_is_not_capability_denial(self):
        self.controls['guest'] = {'assertion_status': 'passed', 'http': 400, 'effect': 'denied-no-persisted-effect', 'reason': 'WordPress dispatcher: no public handler'}
        self.assertIn('zbm_finance_destination:negative:guest', self.evaluate()['gaps'])

    def test_named_validation_400_with_actual_response_message_is_accepted(self):
        validation = {'assertion_status': 'passed', 'http': 400, 'reason': 'callback syntax validation', 'message': 'بازگشت پرداخت معتبر نیست.'}
        self.assertTrue(M.negative_ok(validation))
        self.assertTrue(M.negative_ok({'assertion_status': 'passed', 'http': 400, 'code': 'zbm_callback_invalid_authority'}))
        del validation['message']
        self.assertFalse(M.negative_ok(validation))

    def test_generic_400_cannot_be_laundered_with_claimed_message(self):
        self.assertFalse(M.negative_ok({'assertion_status': 'passed', 'http': 400, 'reason': 'WordPress dispatcher: no public handler', 'message': 'claimed denial'}))
        self.assertFalse(M.negative_ok({'assertion_status': 'passed', 'http': 400, 'body': '0', 'code': 'claimed_denial'}))
        self.assertFalse(M.negative_ok({'assertion_status': 'passed', 'http': 400, 'message': 'Bad Request'}))

    def test_public_read_and_provider_callback_expose_tested_na_contracts(self):
        control = {'assertion_status': 'passed', 'http': 200, 'effect': 'allowed scoped read', 'nonce_contract': 'N/A: no form nonce by public read contract'}
        contracts = M.not_applicable_contracts('zbm_request_auth_state', {'wrong_role': control, 'no_nonce': control})
        self.assertIn('role', contracts['zbm_request_auth_state:wrong_role'])
        self.assertIn('nonce', contracts['zbm_request_auth_state:no_nonce'])
        control['http'] = 303
        contracts = M.not_applicable_contracts('zbm_balance_callback', {'wrong_role': control, 'invalid_nonce': control})
        self.assertIn('role', contracts['zbm_balance_callback:wrong_role'])
        self.assertIn('nonce', contracts['zbm_balance_callback:invalid_nonce'])

    def test_missing_negative_control_fails(self):
        del self.controls['other_vendor']
        self.assertIn('zbm_finance_destination:negative:other_vendor', self.evaluate()['gaps'])

    def test_failed_junit_overrules_passing_evidence(self):
        self.junit[('RemainingEndpointsTest', 'test_real')] = False
        self.assertIn('junit', self.evaluate()['gaps'])

    def test_real_named_commercial_dependency_control_is_valid(self):
        rows = {'authorized_dependency_control': {'assertion_status': 'passed', 'http': 303, 'dependency_code': 'zbm_request_dependencies_unavailable', 'reason': 'Dokan Pro and commercial dependency absent', 'effect': 'valid owned request reaches commercial dependency limitation'}}
        self.assertTrue(M.remaining_positive('zbm_vendor_request_decision/accept', rows))
        rows['authorized_dependency_control']['reason'] = 'not_payable; ownership or gateway'
        self.assertFalse(M.remaining_positive('zbm_vendor_request_decision/accept', rows))

    def test_ambiguous_balance_start_dependency_never_passes(self):
        rows = {'authorized_dependency_control': {'assertion_status': 'passed', 'http': 303, 'reason': 'actual application not_payable denial; unavailable gateway or actor ownership', 'effect': 'valid fixture'}}
        self.assertFalse(M.remaining_positive('zbm_balance_start', rows))

    def test_managed_positive_requires_action_specific_durable_assertions(self):
        result = {'state': 'passed', 'positive': {'result': 'accepted-durable-effects', 'http': 303, 'before_version': 0, 'after_version': 1, 'effects': {'durable_action_specific_assertions': True}}}
        self.assertTrue(M.managed_positive(result))
        result['positive']['effects'] = {}
        self.assertFalse(M.managed_positive(result))

    def test_retired_route_requires_explicit_named_fail_closed_reason(self):
        result = {'state': 'passed', 'operation': 'flow_override_delivery', 'positive': {'result': 'retired-fail-closed', 'reason': 'Delivery code required; override retired', 'observed': {'http': 409, 'code': 'retired', 'message': 'Delivery code required'}}}
        self.assertTrue(M.managed_positive(result))
        result['positive']['reason'] = ''
        self.assertFalse(M.managed_positive(result))

    def test_line_number_changes_do_not_change_discovery(self):
        catalog = json.loads((ROOT / 'ci/http-surfaces.json').read_text())
        by_id = {row['id']: row for row in catalog['rows']}
        self.assertEqual(M.discover(ROOT), [by_id[key]['declaration'] for key in catalog['discovery_order']])
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'plugin').mkdir()
            source = "<?php add_action('admin_post_example', array($this, 'handle'));"
            file = root / 'plugin/Controller.php'
            file.write_text(source)
            before = M.discover(root)
            file.write_text('\n\n' + source)
            self.assertEqual(before, M.discover(root))
            file.write_text(source + "\nadd_action('wp_ajax_new', array($this, 'newHandler'));")
            self.assertNotEqual(before, M.discover(root))

    def test_new_callback_syntax_cannot_hide_a_declared_http_action(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'plugin').mkdir()
            (root / 'plugin/New.php').write_text("<?php add_action('admin_post_new', static fn() => true);")
            self.assertIn('unresolved action', [row['kind'] for row in M.discover(root)])

    def test_added_removed_or_changed_form_contract_changes_discovery(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'plugin').mkdir()
            file = root / 'plugin/Form.php'
            file.write_text('<form method="post"><input name="action" value="first"></form>')
            before = M.discover(root)
            file.write_text('<form method="post"><input name="action" value="second"></form>')
            self.assertNotEqual(before, M.discover(root))
            file.write_text('')
            self.assertEqual([], M.discover(root))

    def test_junit_skips_errors_zero_assertions_do_not_count(self):
        with tempfile.TemporaryDirectory() as directory:
            file = Path(directory) / 'junit.xml'
            file.write_text('<testsuites><testcase classname="C" name="good" assertions="1"/><testcase classname="C" name="zero" assertions="0"/><testcase classname="C" name="skip" assertions="1"><skipped/></testcase></testsuites>')
            results = M.junit_results(file)
            self.assertTrue(M.junit_pass(results, 'C::good'))
            self.assertFalse(M.junit_pass(results, 'C::zero'))
            self.assertFalse(M.junit_pass(results, 'C::skip'))

if __name__ == '__main__': unittest.main()

"""Regression controls for the permanent checker (no WordPress mocks)."""
import copy
import importlib.util
import json
import os
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
        self.registered = {'rows': {'http-001': {'registered': True, 'actual_http': True, 'runtime': 'authenticated-http'}}}
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

    def test_cli_registration_boolean_is_not_actual_http_registration_proof(self):
        del self.registered['rows']['http-001']['actual_http']
        self.assertIn('registration-runtime', self.evaluate()['gaps'])

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

    def test_none_list_and_bare_notice_do_not_crash_or_turn_400_into_named_denial(self):
        for notice in (None, [], [{'message': 'a claim outside response contract'}], {}):
            with self.subTest(notice=notice):
                self.assertFalse(M.negative_ok({'http': 400, 'notice': notice, 'effect': 'claimed validation'}))
                self.assertTrue(M.negative_ok({'http': 403, 'notice': notice, 'message': 'actual named capability denial'}))
                self.assertFalse(M.negative_ok({'notice': notice, 'message': 'missing HTTP result'}))
        self.assertFalse(M.negative_ok({'http': 400}))

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

    def payment_record(self):
        catalog = json.loads((ROOT / 'ci/http-surfaces.json').read_text())
        row = next(row for row in catalog['rows'] if row['id'] == 'http-033')
        binding = row['supplemental'][0]
        snapshot = {'wp_' + suffix: ('a' * 64) for suffix in ('zbm_managed_cases', 'zbm_payments', 'zbm_ledger_entries', 'zbm_financial_actions', 'zbm_purchase_requests', 'zbm_audit_logs')}
        health = {'currency': {'ok': True, 'detected': 'IRT'}}
        health['zarinpal_version'] = {'label': 'Secure Zarinpal', 'detected': '', 'expected': '5.0.17', 'ok': False, 'scope': 'payment'}
        health['zarinpal_settings'] = {'label': 'Gateway settings', 'detected': 'unavailable', 'expected': 'enabled real provider credentials', 'ok': False, 'scope': 'payment'}
        fixture = {key: True for key in ('live', 'owned', 'delivered', 'final_locked', 'deposit_verified', 'spec4_valid', 'payable')}
        fixture.update({key: False for key in ('paused', 'reconciliation_required', 'manual_balance')})
        fixture.update(state='balance_due', substate='balance_due', total_toman=1000, deposit_toman=100, balance_toman=0, remaining_toman=900)
        record = {'state': 'passed', 'assertion_status': 'passed', 'actual_http': True, 'test': binding['test'], 'catalog_ids': ['http-033', 'http-061'], 'type': 'dependency', 'http': 303, 'fixture_contract': fixture, 'online_available': False, 'provider_issuance_verified': False, 'notice': {'type': 'error', 'message': 'Actual provider unavailable'}, 'dependency_report': health, 'missing_dependencies': {key: health[key] for key in ('zarinpal_version', 'zarinpal_settings')}, 'before_snapshot': snapshot, 'after_snapshot': copy.deepcopy(snapshot)}
        record.update({key: True for key in ('actor_valid', 'key_valid', 'nonce_valid', 'financial_unchanged', 'case_unchanged', 'audit_unchanged')})
        cls, method = binding['test'].split('::')
        return row, binding, record, {(cls, method): True}

    def test_confirmed_gateway_dependency_overlay_preserves_generic_guest_400_gap(self):
        row, binding, record, junit = self.payment_record()
        self.assertTrue(M.payment_control(record, binding, row['id'], junit))
        gaps = ['zbm_balance_start:positive-control', 'zbm_balance_start:negative:guest']
        remaining, na, kind = M.apply_payment_overlay(row, gaps, {'http-payment-controls.json': {'zbm_balance_start': {'authorized_dependency_control': record}}}, junit)
        self.assertEqual(['zbm_balance_start:negative:guest'], remaining)
        self.assertEqual('confirmed-commercial-dependency-control', kind)

    def test_bogus_or_synthetic_dependency_overlay_is_rejected(self):
        row, binding, record, junit = self.payment_record()
        record['synthetic_dependency'] = True
        self.assertFalse(M.payment_control(record, binding, row['id'], junit))
        del record['synthetic_dependency']
        record['missing_dependencies'] = {'fake_gateway': {'ok': False}}
        self.assertFalse(M.payment_control(record, binding, row['id'], junit))

    def test_unpayable_or_unobserved_dependency_overlay_is_rejected(self):
        row, binding, record, junit = self.payment_record()
        record['fixture_contract']['payable'] = False
        self.assertFalse(M.payment_control(record, binding, row['id'], junit))
        record['fixture_contract']['payable'] = True
        record['actual_http'] = False
        self.assertFalse(M.payment_control(record, binding, row['id'], junit))
        record['actual_http'] = True
        self.assertFalse(M.payment_control(record, binding, row['id'], {}))

    def test_callback_review_proof_requires_durable_audit_and_zero_credit(self):
        _, _, base, _ = self.payment_record()
        catalog = json.loads((ROOT / 'ci/http-surfaces.json').read_text())
        row = next(row for row in catalog['rows'] if row['id'] == 'http-034')
        binding = row['supplemental'][0]
        before = base['before_snapshot']
        after = copy.deepcopy(before)
        for suffix in ('zbm_payments', 'zbm_managed_cases', 'zbm_audit_logs'):
            after['wp_' + suffix] = 'b' * 64
        record = {'state': 'passed', 'assertion_status': 'passed', 'actual_http': True,
                  'test': binding['test'], 'catalog_ids': ['http-034'], 'type': 'dependency', 'http': 303,
                  'stored_binding_valid': True, 'callback_observed': True, 'amounts_and_ledger_unchanged': True,
                  'provider_credentials_configured': False, 'provider_verified': False, 'outbound_attempted': False,
                  'provider_prerequisite_error': 'Provider verification settings unavailable.',
                  'before_version': 1, 'after_version': 3, 'after_state': 'admin_review', 'substate': 'payment_review',
                  'payment_status': 'verification_pending', 'balance_toman': 0, 'remaining_toman': 900,
                  'audit_events': ['spec4_callback_received', 'spec4_payment_review'],
                  'dependency_report': base['dependency_report'], 'missing_dependencies': base['missing_dependencies'],
                  'before_snapshot': before, 'after_snapshot': after}
        cls, method = binding['test'].split('::')
        junit = {(cls, method): True}
        self.assertTrue(M.payment_control(record, binding, row['id'], junit))
        record['balance_toman'] = 900
        self.assertFalse(M.payment_control(record, binding, row['id'], junit))
        record['balance_toman'] = 0
        record['after_snapshot']['wp_zbm_ledger_entries'] = 'b' * 64
        self.assertFalse(M.payment_control(record, binding, row['id'], junit))
        record['after_snapshot']['wp_zbm_ledger_entries'] = before['wp_zbm_ledger_entries']
        record['audit_events'] = []
        self.assertFalse(M.payment_control(record, binding, row['id'], junit))

    def form_record(self, row_id='http-013'):
        _, _, payment, _ = self.payment_record()
        catalog = json.loads((ROOT / 'ci/http-surfaces.json').read_text())
        row = next(row for row in catalog['rows'] if row['id'] == row_id)
        binding = row['supplemental'][0]
        before = copy.deepcopy(payment['before_snapshot'])
        before['wp_zbm_idempotency_keys'] = 'a' * 64
        after = copy.deepcopy(before)
        if row_id == 'http-013':
            after['wp_zbm_purchase_requests'] = 'b' * 64
            after['wp_zbm_audit_logs'] = 'b' * 64
            after['wp_zbm_idempotency_keys'] = 'b' * 64
        record = {'state': 'passed', 'assertion_status': 'passed', 'actual_http': True,
                  'test': binding['test'], 'catalog_ids': [row_id], 'covered_operations': ['pause'], 'controls': {}}
        positive = {key: record[key] for key in ('state', 'assertion_status', 'actual_http', 'test', 'catalog_ids')}
        positive.update(type='mutation', http=302, actor='admin', actor_valid=True, nonce_valid=True,
                        persisted_assertions=True, financial_unchanged=True, before_snapshot=before, after_snapshot=after,
                        before_version=0, after_version=1, is_paused=True, notice={'type': 'success', 'message': 'pause persisted'})
        positive['request_id'] = 123
        positive['idempotency_assertions'] = {'appended_rows': 1, 'existing_rows_unchanged': True,
            'namespace': 'purchase_request_transition', 'status': 'completed', 'result_resource_id': 123,
            'result_event_uuid': '12345678-1234-1234-1234-123456789abc', 'audit_event_uuid': '12345678-1234-1234-1234-123456789abc',
            'audit_correlation_id': 'abcdef12-abcd-abcd-abcd-abcdef123456', 'audit_actor_user_id': 1, 'audit_reason': 'actual submitted reason'}
        record['controls']['authorized_positive'] = positive
        cls, method = binding['test'].split('::')
        return row, binding, record, {(cls, method): True}

    def test_actual_pause_form_does_not_claim_other_declared_state_branches(self):
        row, binding, record, junit = self.form_record()
        self.assertTrue(M.form_positive(row['id'], record['controls']['authorized_positive'], binding, junit))
        record['covered_operations'] = row['branches']
        gaps, _, kind = M.apply_form_overlay(row, ['positive-and-negative-controls:purchase-request-state-control'], {'http-form-controls.json': {row['id']: record}}, junit)
        self.assertIn('branch:resume', gaps)
        self.assertIn('branch:accept_late', gaps)
        self.assertNotIn('branch:pause', gaps)
        self.assertEqual('actual-form-durable-mutation', kind)

    def resume_branch_record(self):
        row, _, base, junit = self.form_record()
        binding = {'format': 'branch', 'file': 'http-form-branch-controls.json', 'key': 'resume_supplier',
                   'case': 'resume_supplier', 'branch': 'resume',
                   'test': 'FormBranchControlsTest::test_rendered_admin_branch', 'dataset': 'resume_supplier'}
        row['supplemental'] = row['supplemental'][:1] + [binding]
        positive = base['controls']['authorized_positive']
        for suffix in ('posts', 'postmeta', 'woocommerce_order_items', 'woocommerce_order_itemmeta'):
            for key in ('before_snapshot', 'after_snapshot'):
                positive[key]['wp_' + suffix] = 'a' * 64
        proof = {'state': 'passed', 'assertion_status': 'passed', 'actual_http': True,
                 'catalog_ids': ['http-013'], 'test': binding['test'], 'dataset': binding['dataset'],
                 'case': binding['case'], 'branch': 'resume', 'http': 302, 'actor_type': 'admin', 'actor_id': 1,
                 'nonce_valid': True, 'resource_id': 123, 'from_state': 'supplier_review', 'to_state': 'supplier_review',
                 'before_version': 0, 'after_version': 1, 'row_changes_verified': True,
                 'unrelated_rows_unchanged': True, 'financial_and_orders_unchanged': True,
                 'before_snapshot': positive['before_snapshot'], 'after_snapshot': positive['after_snapshot'],
                 'replay_preserved': True, 'replay_http': 302, 'replay_result': 'error',
                 'audit': {'event_key': 'request_resumed', 'event_uuid': '12345678-1234-1234-1234-123456789abc',
                           'correlation_id': 'abcdef12-abcd-abcd-abcd-abcdef123456', 'count': 1,
                           'actor_type': 'admin', 'actor_id': 1, 'resource_id': 123, 'reason_matches': True},
                 'idempotency': {'appended_rows': 1, 'existing_rows_unchanged': True, 'namespace': 'purchase_request_transition',
                                 'status': 'completed', 'result_event_uuid': '12345678-1234-1234-1234-123456789abc'},
                 'specific': {'paused_before': True, 'paused_after': False, 'remaining_before': 1200,
                              'remaining_after': None, 'deadline_in_range': True}, 'negative_controls': {}}
        for scenario in ('wrong_role', 'other_buyer', 'other_vendor', 'no_nonce', 'invalid_nonce'):
            proof['negative_controls'][scenario] = {'actual_http': True, 'unchanged': True,
                'http': 403 if 'nonce' in scenario else 500,
                'message': 'The link you followed has expired.' if 'nonce' in scenario else 'دسترسی غیرمجاز است.'}
        cls, method = binding['test'].split('::')
        junit[(cls, method + ' with data set "resume_supplier"')] = True
        supplemental = {'http-form-controls.json': {'http-013': base}, binding['file']: {binding['key']: proof}}
        return row, binding, proof, supplemental, junit

    def test_exact_observed_branch_clears_only_its_own_branch_gap(self):
        row, _, _, supplemental, junit = self.resume_branch_record()
        gaps, _, _ = M.apply_form_overlay(row, [], supplemental, junit)
        self.assertNotIn('branch:resume', gaps)
        self.assertIn('branch:reopen_deposit', gaps)
        self.assertIn('negative:form:guest', gaps)

    def test_branch_claims_without_actual_passing_dataset_do_not_clear_gaps(self):
        row, binding, proof, supplemental, junit = self.resume_branch_record()
        for mutation in ({'actual_http': False}, {'dataset': 'another'}, {'test': 'Other::test_real'}, {'nonce_valid': False},
                         {'row_changes_verified': False}, {'unrelated_rows_unchanged': False}, {'replay_preserved': False}):
            changed = copy.deepcopy(supplemental); changed[binding['file']][binding['key']].update(mutation)
            with self.subTest(mutation=mutation):
                self.assertIn('branch:resume', M.apply_form_overlay(row, [], changed, junit)[0])
        self.assertIn('branch:resume', M.apply_form_overlay(row, [], supplemental, {})[0])

    def test_branch_with_ledger_drift_or_missing_command_is_rejected(self):
        row, binding, proof, supplemental, junit = self.resume_branch_record()
        for change in ('ledger', 'command', 'audit'):
            changed = copy.deepcopy(supplemental); control = changed[binding['file']][binding['key']]
            if change == 'ledger': control['after_snapshot']['wp_zbm_ledger_entries'] = 'b' * 64
            elif change == 'command': control['idempotency']['appended_rows'] = 0
            else: control['audit']['actor_id'] = 999
            with self.subTest(change=change):
                self.assertIn('branch:resume', M.apply_form_overlay(row, [], changed, junit)[0])

    def test_branch_accepts_wordpress_prefix_without_trailing_underscore(self):
        row, binding, proof, _, junit = self.resume_branch_record()
        for key in ('before_snapshot', 'after_snapshot'):
            proof[key] = {name.replace('wp_', 'sandbox', 1): value for name, value in proof[key].items()}
        self.assertTrue(M.branch_control(proof, binding, row['id'], junit))

    def test_branch_without_order_table_evidence_is_rejected(self):
        row, binding, proof, _, junit = self.resume_branch_record()
        for suffix in ('posts', 'postmeta', 'woocommerce_order_items', 'woocommerce_order_itemmeta'):
            changed = copy.deepcopy(proof)
            for key in ('before_snapshot', 'after_snapshot'):
                changed[key] = {name: value for name, value in changed[key].items() if not name.endswith(suffix)}
            with self.subTest(suffix=suffix):
                self.assertFalse(M.branch_control(changed, binding, row['id'], junit))

    def test_all_frontend_branch_groups_require_every_passing_native_dataset(self):
        _, _, base, _, junit = self.resume_branch_record()
        catalog = json.loads((ROOT / 'ci/http-surfaces.json').read_text())
        row = next(row for row in catalog['rows'] if row['id'] == 'http-031')
        records = {}
        bindings = [binding for binding in row['supplemental'] if binding.get('format') == 'branch']
        for binding in bindings:
            case = binding['case']; _, branch, actor, before_state, after_state, event = M.BRANCH_CASES[case]
            proof = copy.deepcopy(base)
            proof.update(catalog_ids=[row['id']], test=binding['test'], dataset=case, case=case,
                         branch=branch, actor_type=actor, from_state=before_state, to_state=after_state,
                         replay_result='success', specific={'workflow_state_verified': True})
            proof['audit'].update(event_key=event, actor_type=actor)
            proof['idempotency']['namespace'] = 'workflow'
            before = proof['before_snapshot']; before['wp_zbm_workflows'] = 'a' * 64
            before['wp_zbm_order_weights'] = 'a' * 64
            proof['after_snapshot'] = copy.deepcopy(before)
            for suffix in ('zbm_workflows', 'zbm_audit_logs', 'zbm_idempotency_keys'):
                proof['after_snapshot']['wp_' + suffix] = 'b' * 64
            if case in ('vendor_weight', 'buyer_weight', 'vendor_confirm'):
                proof['after_snapshot']['wp_zbm_order_weights'] = 'b' * 64
                proof['specific'] = ({'final_weight_tenths': 8, 'buyer_weight_tenths': 8, 'weights_verified': True}
                    if case == 'vendor_confirm' else {'stored_weight_tenths': 8, 'weight_role': actor, 'weights_verified': True})
            if case in ('buyer_dispute', 'vendor_dispute'):
                for suffix in ('posts', 'postmeta'): proof['after_snapshot']['wp_' + suffix] = 'b' * 64
                proof['specific'] = {'attachment_count': 1, 'encrypted': True, 'private_flags_verified': True,
                    'download_bytes_match': True, 'existing_attachment_rows_unchanged': True, 'file_sha256': 'a' * 64}
            for scenario in ('wrong_role', 'other_buyer', 'other_vendor'):
                code = 'zbm_transition_forbidden' if scenario == 'wrong_role' else 'forbidden'
                proof['negative_controls'][scenario] = {'actual_http': True, 'http': 302, 'unchanged': True,
                    'code': code, 'reason': 'Redirect query zbm_result=' + code}
            cls, method = binding['test'].split('::'); junit[(cls, method + ' with data set "' + case + '"')] = True
            self.assertTrue(M.branch_control(proof, binding, row['id'], junit), case)
            records[case] = proof
        gaps = ['negative:form:guest'] + ['branch:' + branch for branch in row['branches'] if branch != 'buyer_cancellation_requested']
        supplemental = {'http-form-branch-controls.json': records}
        self.assertEqual(['negative:form:guest'], M.apply_branch_overlay(row, gaps, supplemental, junit))
        for binding in bindings:
            changed = copy.deepcopy(supplemental); del changed[binding['file']][binding['key']]
            with self.subTest(case=binding['case']):
                result = M.apply_branch_overlay(row, gaps, changed, junit)
                self.assertIn('branch:' + binding['branch'], result)
                self.assertIn('negative:form:guest', result)

    def test_form_guest_renderer_does_not_replace_generic_post_dispatcher_400(self):
        row, binding, record, junit = self.form_record()
        control = copy.deepcopy(record['controls']['authorized_positive'])
        control.update(type='validation', http=400, generic_dispatcher_only=True, message='dispatcher_no_public_handler')
        control['after_snapshot'] = copy.deepcopy(control['before_snapshot'])
        control['observed_response'] = {'message': 'dispatcher_no_public_handler', 'renderer_denial': 'exact_guest_denial'}
        self.assertFalse(M.form_negative(row['id'], 'guest', control, binding, junit))

    def test_pause_boolean_without_actual_request_and_audit_changes_does_not_qualify(self):
        row, binding, record, junit = self.form_record()
        record['controls']['authorized_positive']['after_snapshot'] = copy.deepcopy(record['controls']['authorized_positive']['before_snapshot'])
        record['controls']['authorized_positive']['after_snapshot']['wp_zbm_notification_outbox'] = 'b' * 64
        self.assertFalse(M.form_positive(row['id'], record['controls']['authorized_positive'], binding, junit))

    def test_pause_idempotency_change_requires_exact_completed_append_and_matching_audit(self):
        row, binding, record, junit = self.form_record()
        control = record['controls']['authorized_positive']
        mutations = [('appended_rows', 2), ('existing_rows_unchanged', False), ('namespace', 'other'),
                     ('status', 'pending'), ('result_resource_id', 999), ('result_event_uuid', 'other'),
                     ('audit_event_uuid', 'not-a-uuid'), ('audit_correlation_id', 'not-a-uuid'),
                     ('audit_actor_user_id', 0), ('audit_reason', '')]
        for key, value in mutations:
            bad = copy.deepcopy(control); bad['idempotency_assertions'][key] = value
            with self.subTest(key=key): self.assertFalse(M.form_positive(row['id'], bad, binding, junit))
        bad = copy.deepcopy(control); del bad['idempotency_assertions']
        self.assertFalse(M.form_positive(row['id'], bad, binding, junit))
        bad = copy.deepcopy(control); bad['after_snapshot']['wp_zbm_idempotency_keys'] = bad['before_snapshot']['wp_zbm_idempotency_keys']
        self.assertFalse(M.form_positive(row['id'], bad, binding, junit))
        bad = copy.deepcopy(control); bad['after_snapshot']['wp_zbm_ledger_entries'] = 'b' * 64
        self.assertFalse(M.form_positive(row['id'], bad, binding, junit))

    def settings_record(self):
        row, binding, record, junit = self.form_record('http-016')
        positive = record['controls']['authorized_positive']
        before_options = {'zbm_core_delivery_reminder_minutes': 60, 'zbm_core_sms_templates': []}
        for name in before_options:
            positive['before_snapshot']['option:' + name] = 'a' * 64
            positive['after_snapshot']['option:' + name] = 'b' * 64
        positive.update(verified_settings=True, stored_minutes=120,
                        stored_templates={'request_rejected': 'Sandbox {request} message'}, before_options=before_options,
                        after_options={'zbm_core_delivery_reminder_minutes': 120, 'zbm_core_sms_templates': {'request_rejected': 'Sandbox {request} message'}},
                        notice=[{'code': 'settings_updated', 'type': 'success'}])
        for scenario, minutes in (('zero_disabled', 0), ('minimum_five', 5), ('maximum_1440', 1440)):
            control = copy.deepcopy(positive); control['stored_minutes'] = minutes
            control['after_options']['zbm_core_delivery_reminder_minutes'] = minutes
            record['controls'][scenario] = control
        denied = copy.deepcopy(positive)
        denied.update(type='validation', after_snapshot=copy.deepcopy(denied['before_snapshot']), after_options=copy.deepcopy(before_options),
                      notice=None, actor_valid=False, nonce_valid=True)
        for scenario, actor, role in (('wrong_role', 'buyer_1', 'customer'), ('other_buyer', 'buyer_2', 'customer'), ('other_vendor', 'vendor_2', 'seller')):
            control = copy.deepcopy(denied)
            control.update(http=302, actor=actor, denial_layer='dokan_admin_init', message='dokan_block_admin_access_local_home_redirect',
                           observed_response={'message': 'dokan_block_admin_access_local_home_redirect', 'notice': None,
                               'redirect_path': '', 'redirect_is_local_home': True},
                           upstream_contract={'dependency_version': '5.0.10', 'hook': 'admin_init',
                               'callback': 'WeDevs\\Dokan\\Core::block_admin_access', 'registered': True, 'priority': 10,
                               'http_probe_verified': True, 'wc_prevent_access': False,
                               'callback_order': 2, 'woocommerce_callback_order': 1,
                               'source_contract_verified': True, 'source_path': '/wp/plugins/dokan-lite/includes/Core.php',
                               'hpos_enabled': True, 'configured_admin_access': 'off', 'effective_admin_access': 'on',
                               'hpos_helper_class': 'WeDevs\\Dokan\\Utilities\\OrderUtil', 'hpos_option_enabled': 'yes',
                               'actor_roles': [role], 'primary_role': role, 'manage_options': False,
                               'request_page': 'options.php', 'page_exempt': False, 'nonce_verified': True,
                               'redirect_is_local_home': True, 'redirect_url': 'http://localhost', 'home_url': 'http://localhost',
                               'home_path': '', 'redirect_path': ''})
            record['controls'][scenario] = control
        cap = copy.deepcopy(denied)
        cap.update(http=403, actor='admin', denial_layer='wordpress_options_capability',
                   message='Sorry, you are not allowed to manage options for this site.',
                   observed_response={'message': 'Sorry, you are not allowed to manage options for this site.', 'notice': None},
                   capability_contract={'actor_roles': ['administrator'], 'manage_options': False, 'manage_woocommerce': True,
                       'option_page_capability': 'manage_options', 'nonce_verified': True, 'per_user_cap_override': True, 'capability_restored': True})
        record['controls']['capability_denied'] = cap
        for scenario in ('no_nonce', 'invalid_nonce', 'guest'):
            control = copy.deepcopy(denied)
            control.update(actor='guest' if scenario == 'guest' else 'admin', http=302 if scenario == 'guest' else 403,
                           nonce_valid=False, message='actual login or expired link response',
                           observed_response={'message': 'actual login or expired link response'})
            record['controls'][scenario] = control
        return row, binding, record, junit

    def test_settings_role_redirect_requires_actual_pinned_upstream_contract(self):
        row, binding, record, junit = self.settings_record()
        for scenario in ('wrong_role', 'other_buyer', 'other_vendor'):
            control = record['controls'][scenario]
            self.assertTrue(M.form_negative(row['id'], scenario, control, binding, junit))
            for key, value in (('dependency_version', 'other'), ('registered', False), ('source_contract_verified', False),
                               ('source_path', '/claimed/Core.php'), ('callback', 'claimed'), ('hook', 'other'),
                               ('hpos_enabled', False), ('effective_admin_access', 'off'), ('request_page', 'admin.php'),
                               ('hpos_helper_class', 'WooCommerce\\Utilities\\OrderUtil'), ('hpos_option_enabled', 'no'),
                               ('page_exempt', True), ('nonce_verified', False), ('redirect_path', '/wrong'),
                               ('http_probe_verified', False), ('wc_prevent_access', True),
                               ('callback_order', 1), ('woocommerce_callback_order', 3),
                               ('redirect_url', 'http://external.example'), ('home_path', '/wrong')):
                bad = copy.deepcopy(control); bad['upstream_contract'][key] = value
                with self.subTest(scenario=scenario, key=key): self.assertFalse(M.form_negative(row['id'], scenario, bad, binding, junit))
            bad = copy.deepcopy(control); del bad['upstream_contract']
            self.assertFalse(M.form_negative(row['id'], scenario, bad, binding, junit))
            bad = copy.deepcopy(control); bad['after_snapshot']['wp_zbm_ledger_entries'] = 'b' * 64
            self.assertFalse(M.form_negative(row['id'], scenario, bad, binding, junit))
            self.assertFalse(M.form_negative(row['id'], scenario, control, binding, {}))

    def test_settings_source_labels_and_bare_302_cannot_qualify_as_role_denials(self):
        row, binding, record, junit = self.settings_record()
        control = record['controls']['wrong_role']
        for key in ('upstream_contract', 'observed_response'):
            bad = copy.deepcopy(control); del bad[key]
            self.assertFalse(M.form_negative(row['id'], 'wrong_role', bad, binding, junit))
        bad = copy.deepcopy(control); bad['message'] = 'specific claimed role denial'
        self.assertFalse(M.form_negative(row['id'], 'wrong_role', bad, binding, junit))
        bad = copy.deepcopy(control); bad['http'] = 403
        self.assertFalse(M.form_negative(row['id'], 'wrong_role', bad, binding, junit))
        bad = copy.deepcopy(control); bad['actor'] = 'vendor_2'
        self.assertFalse(M.form_negative(row['id'], 'wrong_role', bad, binding, junit))

    def woocommerce_settings_control(self, record, scenario='wrong_role'):
        control = copy.deepcopy(record['controls'][scenario])
        control.update(denial_layer='woocommerce_admin_init', message='woocommerce_prevent_admin_access_local_account_redirect',
                       observed_response={'message': 'woocommerce_prevent_admin_access_local_account_redirect',
                           'redirect_path': '/', 'redirect_is_local_account': True},
                       upstream_contract={'dependency_version': '10.9.4', 'hook': 'admin_init', 'callback': 'WC_Admin::prevent_admin_access',
                           'registered': True, 'priority': 10, 'source_path': '/wp/plugins/woocommerce/includes/admin/class-wc-admin.php',
                           'source_contract_verified': True, 'http_probe_verified': True, 'callback_order': 1, 'dokan_callback_order': 2,
                           'runs_before_dokan': True, 'actor_roles': ['customer'], 'primary_role': 'customer',
                           'manage_options': False, 'edit_posts': False, 'manage_woocommerce': False,
                           'view_admin_dashboard': False, 'doing_ajax': False, 'disable_admin_bar': True, 'prevent_access': True,
                           'request_page': 'options.php', 'page_exempt': False, 'nonce_verified': True,
                           'expected_url': 'http://localhost/?page_id=8', 'my_account_url': 'http://localhost/?page_id=8',
                           'redirect_url': 'http://localhost/?page_id=8', 'expected_path': '/', 'redirect_path': '/',
                           'expected_query': 'page_id=8', 'redirect_query': 'page_id=8'})
        return control

    def test_settings_woocommerce_customer_gate_requires_exact_native_request_and_order_proof(self):
        row, binding, record, junit = self.settings_record()
        for scenario in ('wrong_role', 'other_buyer'):
            control = self.woocommerce_settings_control(record, scenario)
            self.assertTrue(M.form_negative(row['id'], scenario, control, binding, junit))
            for key, value in (('dependency_version', 'other'), ('callback', 'claimed'), ('registered', False),
                               ('source_path', '/claimed/class-wc-admin.php'), ('source_contract_verified', False), ('http_probe_verified', False),
                               ('callback_order', 3), ('runs_before_dokan', False), ('edit_posts', True), ('manage_woocommerce', True),
                               ('view_admin_dashboard', True), ('doing_ajax', True), ('disable_admin_bar', False), ('prevent_access', False),
                               ('request_page', 'other.php'), ('page_exempt', True), ('nonce_verified', False),
                               ('expected_path', '/wrong'), ('redirect_path', '/wrong'), ('redirect_query', 'page_id=9'),
                               ('expected_query', 'page_id=9'), ('my_account_url', 'http://localhost/?page_id=9'),
                               ('redirect_url', 'http://external.example/?page_id=8')):
                bad = copy.deepcopy(control); bad['upstream_contract'][key] = value
                with self.subTest(scenario=scenario, key=key): self.assertFalse(M.form_negative(row['id'], scenario, bad, binding, junit))
            bad = copy.deepcopy(control); del bad['upstream_contract']
            self.assertFalse(M.form_negative(row['id'], scenario, bad, binding, junit))
            bad = copy.deepcopy(control); bad['after_snapshot']['wp_zbm_ledger_entries'] = 'b' * 64
            self.assertFalse(M.form_negative(row['id'], scenario, bad, binding, junit))
            self.assertFalse(M.form_negative(row['id'], scenario, control, binding, {}))
        vendor = self.woocommerce_settings_control(record, 'other_vendor')
        self.assertFalse(M.form_negative(row['id'], 'other_vendor', vendor, binding, junit))

    def test_settings_earlier_woocommerce_gate_does_not_waive_dokan_vendor_or_direct_wp_controls(self):
        row, binding, record, junit = self.settings_record()
        for scenario in ('wrong_role', 'other_buyer'):
            record['controls'][scenario] = self.woocommerce_settings_control(record, scenario)
        supplemental = {binding['file']: {row['id']: record}}
        gaps, na, _ = M.apply_form_overlay(row, [], supplemental, junit)
        self.assertEqual([], gaps)
        self.assertEqual({}, na)
        del record['controls']['other_vendor']['upstream_contract']
        gaps, _, _ = M.apply_form_overlay(row, [], supplemental, junit)
        self.assertIn('negative:form:other_vendor', gaps)
        del record['controls']['capability_denied']
        gaps, _, _ = M.apply_form_overlay(row, [], supplemental, junit)
        self.assertIn('negative:form:capability_denied', gaps)
        del record['controls']['maximum_1440']
        gaps, _, _ = M.apply_form_overlay(row, [], supplemental, junit)
        self.assertIn('positive:form-contract', gaps)

    def test_settings_upstream_denials_still_require_positive_ranges_and_direct_wp_capability_denial(self):
        row, binding, record, junit = self.settings_record()
        supplemental = {binding['file']: {row['id']: record}}
        gaps, _, _ = M.apply_form_overlay(row, [], supplemental, junit)
        self.assertEqual([], gaps)
        del record['controls']['capability_denied']
        gaps, _, _ = M.apply_form_overlay(row, [], supplemental, junit)
        self.assertIn('negative:form:capability_denied', gaps)
        del record['controls']['minimum_five']
        gaps, _, _ = M.apply_form_overlay(row, [], supplemental, junit)
        self.assertIn('positive:form-contract', gaps)

    def test_settings_direct_capability_proof_requires_named_wp403_and_actual_privileges(self):
        row, binding, record, junit = self.settings_record()
        control = record['controls']['capability_denied']
        self.assertTrue(M.form_negative(row['id'], 'capability_denied', control, binding, junit))
        for key, value in (('http', 302), ('nonce_valid', False), ('actor_valid', True), ('message', 'claimed denial'),
                           ('denial_layer', 'dokan_admin_init')):
            bad = copy.deepcopy(control); bad[key] = value
            with self.subTest(key=key): self.assertFalse(M.form_negative(row['id'], 'capability_denied', bad, binding, junit))
        for key, value in (('actor_roles', ['customer']), ('manage_options', True), ('manage_woocommerce', False),
                           ('option_page_capability', 'other'), ('nonce_verified', False), ('per_user_cap_override', False), ('capability_restored', False)):
            bad = copy.deepcopy(control); bad['capability_contract'][key] = value
            with self.subTest(capability=key): self.assertFalse(M.form_negative(row['id'], 'capability_denied', bad, binding, junit))
        bad = copy.deepcopy(control); bad['after_snapshot']['wp_zbm_payments'] = 'b' * 64
        self.assertFalse(M.form_negative(row['id'], 'capability_denied', bad, binding, junit))

    def test_exact_scoped_get_form_read_requires_actual_returned_reference(self):
        row, binding, record, junit = self.form_record('http-012')
        control = record['controls']['authorized_positive']
        control.update(type='scoped-read', http=200, exact_scoped_data=True, nonce_contract='N/A: admin GET read')
        control['after_snapshot'] = copy.deepcopy(control['before_snapshot'])
        control['observed_response'] = {'returned_references': ['654321']}
        self.assertTrue(M.form_positive(row['id'], control, binding, junit))
        control['observed_response']['returned_references'] = []
        self.assertFalse(M.form_positive(row['id'], control, binding, junit))

    def test_supplier_and_create_dependency_gate_do_not_prove_later_role_checks(self):
        public = {'assertion_status': 'passed', 'http': 200, 'effect': 'same early health paragraph', 'nonce_contract': 'N/A: read-only shortcode'}
        supplier = {key: copy.deepcopy(public) for key in M.NEGATIVES}
        gaps = M.check_remaining('zbm_supplier_requests', supplier)
        for scenario in ('guest', 'wrong_role', 'other_buyer', 'other_vendor'):
            self.assertIn('negative:blocked-by-dependency:' + scenario, gaps)
        create = {key: {'assertion_status': 'passed', 'http': 303, 'reason': 'zbm_request_dependencies_unavailable'} for key in M.NEGATIVES}
        gaps = M.check_remaining('zbm_create_purchase_request', create)
        self.assertIn('negative:blocked-by-dependency:wrong_role', gaps)

    def legacy_record(self, row_id='http-015'):
        _, _, payment, _ = self.payment_record()
        catalog = json.loads((ROOT / 'ci/http-surfaces.json').read_text())
        row = next(row for row in catalog['rows'] if row['id'] == row_id)
        binding = row['supplemental'][0]
        before = copy.deepcopy(payment['before_snapshot'])
        for suffix in ('zbm_dokan_credit_mirrors', 'zbm_managed_case_commands', 'posts', 'postmeta',
                       'woocommerce_order_items', 'woocommerce_order_itemmeta'):
            before['wp_' + suffix] = 'a' * 64
        control = {'state': 'passed', 'assertion_status': 'passed', 'actual_http': True,
                   'test': binding['test'], 'catalog_ids': [row_id], 'type': 'mutation', 'http': 302,
                   'actor': 'admin', 'actor_valid': True, 'nonce_valid': True, 'key_valid': True,
                   'persisted_assertions': True, 'financial_unchanged': True, 'before_snapshot': before,
                   'after_snapshot': copy.deepcopy(before), 'before_state': 'supplier_accepted',
                   'after_state': 'deposit_paid', 'before_version': 0, 'after_version': 1,
                   'before_execution_mode': 'test_pending', 'execution_mode': 'test',
                   'payment_ready': False, 'payment_count': 0, 'ledger_count': 0,
                   'submitted_version': 0, 'reason_present': True,
                   'notice': {'type': 'success', 'message': 'actual persisted recovery'},
                   'order_contract': {'order_id': 123, 'snapshot_matches': True, 'route': 'test',
                       'execution_mode': 'test', 'payment_enabled': 'no', 'status': 'pending',
                       'is_paid': False, 'paid_at': None, 'transaction_id': '', 'line_item_count': 1,
                       'other_orders_unchanged': True, 'other_requests_unchanged': True},
                   'stock_contract': {'before': 100, 'after': 99, 'reduced_stock': 1,
                       'request_reserved': True, 'request_released': False},
                   'audit_contract': {'event': 'admin_test_deposit_advanced', 'count': 1,
                       'actor_type': 'admin', 'actor_user_id': 1, 'reason_matches': True}}
        if row_id == 'http-015':
            for suffix in ('zbm_purchase_requests', 'zbm_audit_logs', 'postmeta', 'woocommerce_order_items', 'woocommerce_order_itemmeta'):
                control['after_snapshot']['wp_' + suffix] = 'b' * 64
        else:
            control.update(type='validation', stored_binding_valid=True, identity_guard_before_verifier=True,
                           before_state='late_payment_review', after_state='late_payment_review', after_version=0,
                           notice={'type': 'error', 'message': 'issued identity prevents cancellation'},
                           provider_verification_verified=False, dependency_control_claimed=False,
                           identity_contract={'snapshot_matches': True, 'route': 'online', 'execution_mode': 'live',
                               'authority_matches': True, 'payment_status': 'callback_received_late',
                               'paid_amount': None, 'reference': None, 'paid_at': None})
        record = {key: control[key] for key in ('state', 'assertion_status', 'actual_http', 'test', 'catalog_ids')}
        record['controls'] = {'authorized_positive' if row_id == 'http-015' else 'authorized_identity_safety_control': control}
        record['covered_operations'] = ['test_advance'] if row_id == 'http-015' else ['cancel_review_identity_safety_denial']
        cls, method = binding['test'].split('::')
        return row, binding, record, {(cls, method): True}

    def legacy_negative(self, control, scenario):
        result = copy.deepcopy(control)
        result.update(type='validation', after_state='supplier_accepted', after_version=0, execution_mode='test_pending',
                      after_snapshot=copy.deepcopy(control['before_snapshot']), reason='specific observed validation denial',
                      actor={'guest': 'guest', 'wrong_role': 'buyer_1', 'other_buyer': 'buyer_2', 'other_vendor': 'vendor_2'}.get(scenario, 'admin'),
                      nonce_valid=scenario not in ('guest', 'no_nonce', 'invalid_nonce'),
                      submitted_version=9 if scenario == 'stale_version' else 0, reason_present=scenario != 'missing_reason',
                      http=400 if scenario == 'guest' else (500 if scenario in ('wrong_role', 'other_buyer', 'other_vendor') else (403 if scenario in ('no_nonce', 'invalid_nonce') else 302)),
                      notice={'type': 'error', 'message': 'actual named denial'})
        if scenario == 'guest': result.update(generic_dispatcher_only=True, body='0')
        return result

    def test_legacy_recovery_proves_quarantined_order_and_keeps_generic_guest_red(self):
        row, binding, record, junit = self.legacy_record()
        positive = record['controls']['authorized_positive']
        self.assertTrue(M.legacy_recovery_positive(positive, binding, junit))
        for scenario in row['required_scenarios']:
            record['controls'][scenario] = self.legacy_negative(positive, scenario)
        gaps, na, kind = M.apply_legacy_form_overlay(row, ['positive-and-negative-controls:legacy'], {binding['file']: {row['id']: record}}, junit)
        self.assertEqual(['negative:form:guest'], gaps)
        self.assertEqual({}, na)
        self.assertEqual('actual-form-quarantined-test-recovery', kind)

    def test_legacy_recovery_rejects_missing_stock_order_audit_or_zero_money_proof(self):
        row, binding, record, junit = self.legacy_record()
        control = record['controls']['authorized_positive']
        for key in ('order_contract', 'stock_contract', 'audit_contract', 'payment_count', 'ledger_count', 'payment_ready'):
            bad = copy.deepcopy(control)
            del bad[key]
            with self.subTest(missing=key): self.assertFalse(M.legacy_recovery_positive(bad, binding, junit))
        for key, value in (('is_paid', True), ('route', 'online'), ('transaction_id', 'credited')):
            bad = copy.deepcopy(control); bad['order_contract'][key] = value
            with self.subTest(order=key): self.assertFalse(M.legacy_recovery_positive(bad, binding, junit))
        bad = copy.deepcopy(control); bad['stock_contract']['after'] = 98
        self.assertFalse(M.legacy_recovery_positive(bad, binding, junit))

    def test_legacy_recovery_rejects_changed_ledger_dokan_credit_or_case(self):
        row, binding, record, junit = self.legacy_record()
        control = record['controls']['authorized_positive']
        for suffix in ('zbm_ledger_entries', 'zbm_payments', 'zbm_financial_actions', 'zbm_dokan_credit_mirrors', 'zbm_managed_cases', 'zbm_managed_case_commands'):
            bad = copy.deepcopy(control); bad['after_snapshot']['wp_' + suffix] = 'b' * 64
            with self.subTest(table=suffix): self.assertFalse(M.legacy_recovery_positive(bad, binding, junit))
        bad = copy.deepcopy(control); bad['after_snapshot'] = copy.deepcopy(bad['before_snapshot'])
        self.assertFalse(M.legacy_recovery_positive(bad, binding, junit))

    def test_legacy_controls_require_actual_http_unmocked_and_passing_same_test_junit(self):
        for row_id in ('http-014', 'http-015'):
            row, binding, record, junit = self.legacy_record(row_id)
            control = next(iter(record['controls'].values()))
            check = M.legacy_recovery_positive if row_id == 'http-015' else M.legacy_identity_safety
            for key, value in (('actual_http', False), ('synthetic_dependency', True), ('mock_dependency', True), ('mock_provider', True), ('dependency_overridden', True), ('test', 'Other::test')):
                bad = copy.deepcopy(control); bad[key] = value
                with self.subTest(row=row_id, key=key): self.assertFalse(check(bad, binding, junit))
            self.assertFalse(check(control, binding, {}))
            self.assertFalse(check(control, binding, {key: False for key in junit}))

    def test_legacy_missing_reason_stale_version_and_roles_are_distinct_required_controls(self):
        row, binding, record, junit = self.legacy_record()
        positive = record['controls']['authorized_positive']
        for scenario in row['required_scenarios']:
            control = self.legacy_negative(positive, scenario)
            if scenario == 'guest': continue
            self.assertTrue(M.legacy_recovery_negative(scenario, control, binding, junit))
            bad = copy.deepcopy(control); bad['after_snapshot']['wp_zbm_ledger_entries'] = 'b' * 64
            self.assertFalse(M.legacy_recovery_negative(scenario, bad, binding, junit))
        wrong = self.legacy_negative(positive, 'other_buyer')
        self.assertFalse(M.legacy_recovery_negative('other_vendor', wrong, binding, junit))
        stale = self.legacy_negative(positive, 'stale_version'); stale['submitted_version'] = 0
        self.assertFalse(M.legacy_recovery_negative('stale_version', stale, binding, junit))
        missing = self.legacy_negative(positive, 'missing_reason'); missing['reason_present'] = True
        self.assertFalse(M.legacy_recovery_negative('missing_reason', missing, binding, junit))
        gaps, _, _ = M.apply_legacy_form_overlay(row, [], {binding['file']: {row['id']: record}}, junit)
        self.assertIn('negative:form:missing_reason', gaps)
        self.assertIn('negative:form:stale_version', gaps)

    def test_legacy_issued_identity_safety_never_replaces_mutation_or_negative_gaps(self):
        row, binding, record, junit = self.legacy_record('http-014')
        self.assertTrue(M.legacy_identity_safety(record['controls']['authorized_identity_safety_control'], binding, junit))
        gaps, na, kind = M.apply_legacy_form_overlay(row, [], {binding['file']: {row['id']: record}}, junit)
        self.assertIn('positive:form-contract', gaps)
        self.assertIn('branch:cancel_review', gaps)
        self.assertEqual(set(M.NEGATIVES), {gap.removeprefix('negative:form:') for gap in gaps if gap.startswith('negative:form:')})
        self.assertEqual({}, na)
        self.assertEqual('actual-form-identity-safety-denial', kind)
        registration = {'rows': {row['id']: {'registered': True, 'actual_http': True, 'runtime': 'authenticated-http'}}}
        junit[('RegisteredHttpSurfacesTest', 'test_production_surface_callback_is_actually_registered with data set "' + row['id'] + '"')] = True
        report = M.evaluate({'rows': [row]}, registration, {}, {}, {}, junit, {binding['file']: {row['id']: record}})[0]
        self.assertTrue(report['supplemental_safety_control_complete'])
        self.assertIsNone(report['supplemental_positive_kind'])
        self.assertFalse(report['positive_control_complete'])
        self.assertFalse(report['negative_controls_complete'])
        self.assertFalse(report['positive_and_negative_complete'])

    def test_legacy_identity_safety_requires_identity_guard_preservation_and_no_dependency_claim(self):
        row, binding, record, junit = self.legacy_record('http-014')
        control = record['controls']['authorized_identity_safety_control']
        for key, value in (('identity_guard_before_verifier', False), ('dependency_control_claimed', True), ('provider_verification_verified', True), ('http', 400)):
            bad = copy.deepcopy(control); bad[key] = value
            with self.subTest(key=key): self.assertFalse(M.legacy_identity_safety(bad, binding, junit))
        bad = copy.deepcopy(control); bad['after_snapshot']['wp_zbm_payments'] = 'b' * 64
        self.assertFalse(M.legacy_identity_safety(bad, binding, junit))
        bad = copy.deepcopy(control); del bad['identity_contract']['paid_amount']
        self.assertFalse(M.legacy_identity_safety(bad, binding, junit))

    def test_stale_or_other_run_supplemental_evidence_cannot_be_loaded(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'http-payment-controls.json'
            path.write_text(json.dumps({'_meta': {'schema_version': 1, 'run_id': '123', 'run_attempt': '1'}}))
            started = path.stat().st_mtime_ns + 1
            data, error = M.load_supplemental(path, started, {'GITHUB_RUN_ID': '123', 'GITHUB_RUN_ATTEMPT': '1'})
            self.assertEqual({}, data)
            self.assertIn('stale-supplemental-evidence', error)
            data, error = M.load_supplemental(path, 0, {'GITHUB_RUN_ID': '456', 'GITHUB_RUN_ATTEMPT': '1'})
            self.assertEqual({}, data)
            self.assertIn('supplemental-run-mismatch', error)
            data, error = M.load_supplemental(path, 0, {'GITHUB_RUN_ID': '123', 'GITHUB_RUN_ATTEMPT': '1'})
            self.assertIsNone(error)
            self.assertEqual('123', data['_meta']['run_id'])

    def test_legacy_supplemental_file_is_bound_to_marker_run_and_attempt(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'http-legacy-request-form-controls.json'
            path.write_text(json.dumps({'_meta': {'schema_version': 1, 'run_id': '123', 'run_attempt': '2'}}))
            for started, run_id, attempt, expected in ((path.stat().st_mtime_ns + 1, '123', '2', 'stale-supplemental-evidence'),
                                                      (0, '456', '2', 'supplemental-run-mismatch'),
                                                      (0, '123', '1', 'supplemental-run-mismatch')):
                data, error = M.load_supplemental(path, started, {'GITHUB_RUN_ID': run_id, 'GITHUB_RUN_ATTEMPT': attempt})
                self.assertEqual({}, data)
                self.assertIn(expected, error)

    def test_junit_skips_errors_zero_assertions_do_not_count(self):
        with tempfile.TemporaryDirectory() as directory:
            file = Path(directory) / 'junit.xml'
            file.write_text('<testsuites><testcase classname="C" name="good" assertions="1"/><testcase classname="C" name="zero" assertions="0"/><testcase classname="C" name="skip" assertions="1"><skipped/></testcase></testsuites>')
            results = M.junit_results(file)
            self.assertTrue(M.junit_pass(results, 'C::good'))
            self.assertFalse(M.junit_pass(results, 'C::zero'))
            self.assertFalse(M.junit_pass(results, 'C::skip'))

if __name__ == '__main__': unittest.main()

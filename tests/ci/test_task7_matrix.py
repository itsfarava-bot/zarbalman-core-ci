"""Task 7 evidence closes only the explicitly proved native contracts."""
import copy
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('task7_matrix', ROOT / 'scripts/task7_matrix_evidence.py')
M = importlib.util.module_from_spec(SPEC) if SPEC else None
if SPEC and SPEC.loader and (ROOT / 'scripts/task7_matrix_evidence.py').exists():
    SPEC.loader.exec_module(M)


class Task7MatrixTest(unittest.TestCase):
    def test_missing_evidence_never_retires_any_gap(self):
        self.assertTrue(hasattr(M, 'apply_task7_overlay'), 'Task 7 needs a dedicated fail-closed evidence overlay')
        gaps = ['registration', 'branch:reopen_deposit', 'negative:form:guest']
        self.assertEqual((gaps, {}, None), M.apply_task7_overlay({'id': 'http-013'}, gaps, {}, lambda *args: True))



def snapshot():
    return {'wp_' + suffix: 'a' * 64 for suffix in M.SNAPSHOT_SUFFIXES + ('wc_orders', 'wc_orders_meta')}


def base(test, ids):
    return {'state': 'passed', 'assertion_status': 'passed', 'actual_http': True, 'test': test,
            'catalog_ids': ids, 'dependency_mode': 'double-backed', 'commercial_compatibility_proved': False, 'real_provider_called': False}


def branch(operation):
    ids = ['http-014'] if operation == 'cancel_review' else ['http-013']
    before = snapshot(); after = copy.deepcopy(before)
    for suffix in ['zbm_purchase_requests', 'zbm_audit_logs', 'postmeta', 'zbm_payments'] + (['zbm_ledger_entries'] if operation == 'accept_late' else []):
        after['wp_' + suffix] = 'b' * 64
    states = {'reopen_deposit': ('deposit_expired', 'deposit_pending'), 'accept_late': ('late_payment_review', 'deposit_paid'), 'cancel_review': ('late_payment_review', 'cancelled')}
    events = {'reopen_deposit': 'deposit_deadline_reopen_requested', 'accept_late': 'late_deposit_accepted', 'cancel_review': 'admin_review_cancelled'}
    scenarios = ['wrong_role', 'other_buyer', 'other_vendor', 'guest', 'no_nonce', 'invalid_nonce', 'missing_reason']
    scenarios += ['invalid_hours'] if operation == 'reopen_deposit' else (['stale_version'] if operation == 'cancel_review' else [])
    negatives = {}
    actors = {'wrong_role': 'buyer_1', 'other_buyer': 'buyer_2', 'other_vendor': 'vendor_2', 'guest': 'guest'}
    for scenario in scenarios:
        status = 400 if scenario == 'guest' else (500 if scenario in actors else (403 if scenario in ['no_nonce', 'invalid_nonce'] else 302))
        message = {'reopen_deposit': 'دلیل و مدت معتبر برای بازگشایی لازم است.', 'accept_late': 'دسترسی و دلیل مستند مدیریت معتبر نیست.', 'cancel_review': M.CANCEL_ERROR}[operation]
        negatives[scenario] = {'state': 'passed', 'assertion_status': 'passed', 'actual_http': True, 'catalog_ids': ids, 'http': status,
            'actor': actors.get(scenario, 'admin'), 'before_snapshot': before, 'after_snapshot': copy.deepcopy(before), 'snapshot_unchanged': True,
            'financial_unchanged': True, 'provider_calls_added': 0, 'notice': {'type': 'error', 'message': message} if status == 302 else None,
            'response_contract': 'guest-native-dispatcher' if scenario == 'guest' else ('unauthorized' if scenario in actors else ('wordpress-expired-link' if status == 403 else 'admin-notice'))}
    effects = {'payment_count': 1, 'order_id_matches': True, 'reconciliation_required': False, 'payment_status': 'expected', 'paid_amount_toman': None,
        'ledger_count': 0, 'order_status': 'pending', 'stock_reserved': True, 'stock_released': False, 'payment_ready': True,
        'provider_calls': [], 'deadline_in_window': True}
    if operation == 'accept_late':
        effects.update(payment_status='late_accepted', paid_amount_toman=100, ledger_count=3, order_status='processing',
            ledger_amounts={'employer_cash_received': 100, 'platform_share_provisional': 10, 'vendor_share_provisional': 90},
            simulated_verification_calls=1, authority_matches=True, reference_matches=True, order_paid=True, transaction_matches=True,
            provider_calls=[{'path': 'verify.json', 'amount': 1000, 'expected_binding_matches': True, 'merchant_is_synthetic': True}])
    if operation == 'cancel_review':
        effects.update(payment_status='expired', order_status='cancelled', stock_released=True)
    replay = {'reopen_deposit': 'این مهلت پرداخت قابل بازگشایی نیست.', 'accept_late': 'این پرونده پرداخت دیرهنگام قابل پذیرش نیست.', 'cancel_review': M.CANCEL_ERROR}[operation]
    return dict(base(M.LIFECYCLE, ids), dataset=operation, branch=operation, http=302, actor='admin', request_id=11, order_id=21,
        before_state=states[operation][0], after_state=states[operation][1], before_version=3, after_version=4,
        before_snapshot=before, after_snapshot=after, negative_controls=negatives, effects=effects,
        nonce_valid=True, actual_form_submitted=True, snapshot_matches=True, replay_preserved=True, account_emails_unchanged=True,
        notice={'type': 'success', 'message': M.SUCCESS}, historical_unissued_review_fixture=operation == 'cancel_review',
        audit={'event_key': events[operation], 'actor_type': 'admin', 'actor_id': 7, 'count': 1, 'event_uuid': '11111111-1111-4111-a111-111111111111'},
        replay_provider_calls_added=0, replay_account_emails_unchanged=True, replay_http=302, replay_notice={'type': 'error', 'message': replay}, replay_snapshot=copy.deepcopy(after))


def identity():
    return dict(base(M.IDENTITY, ['http-014']), type='validation', http=302, actor='admin', request_id=12,
        before_version=2, after_version=2, before_state='late_payment_review', after_state='late_payment_review',
        before_snapshot=snapshot(), after_snapshot=snapshot(), nonce_valid=True, actor_valid=True, stored_binding_valid=True,
        persisted_assertions=True, financial_unchanged=True, identity_guard_before_verifier=True, account_emails_unchanged=True,
        provider_verification_verified=False, dependency_control_claimed=False, provider_calls_added=0,
        notice={'type': 'error', 'message': M.GUARD_ERROR}, identity_contract={'snapshot_matches': True, 'authority_matches': True,
            'route': 'online', 'execution_mode': 'live', 'payment_status': 'callback_received_late', 'paid_amount': None, 'reference': None, 'paid_at': None})


def creation():
    controls = {}
    for number, scenario in enumerate(['wrong_role', 'other_buyer', 'other_vendor']):
        actor = ['vendor_1', 'buyer_2', 'vendor_2'][number]
        control = {'test': M.CREATION, 'catalog_ids': M.CREATE_IDS, 'assertion_status': 'passed', 'actual_http': True, 'http': 303,
            'actor': actor, 'before_snapshot': snapshot(), 'after_snapshot': snapshot(), 'dependency_error_absent': True,
            'actual_form_submitted': True, 'account_emails_unchanged': True, 'provider_calls_added': 0}
        if scenario == 'wrong_role':
            control.update(result='rejected-exact-contract-no-persisted-effect', snapshot_unchanged=True,
                notice={'type': 'error', 'code': 'zbm_self_purchase_forbidden', 'message': 'فروشنده نمی‌تواند برای محصول خودش درخواست خرید ثبت کند.'})
        else:
            control['after_snapshot']['wp_zbm_purchase_requests'] = 'b' * 64
            control.update(result='allowed-own-scope-native-creation', forged_buyer_ignored=True, forged_vendor_ignored=True,
                financial_and_orders_unchanged=True, existing_requests_unchanged=True, request_id=100 + number,
                stored_buyer_id=20 + number, actor_user_id=20 + number, stored_vendor_id=10, product_vendor_id=10,
                product_id=30, posted_buyer_id=1, posted_vendor_id=22, requests_before_count=40, requests_after_count=41,
                request_contract={'id': 100 + number, 'buyer_id': 20 + number, 'vendor_id': 10, 'product_id': 30, 'state': 'supplier_review',
                    'execution_mode': 'live', 'version': 0, 'wc_order_id': None, 'stock_reserved': False, 'payments_count': 0, 'ledger_count': 0})
        controls[scenario] = control
    return dict(base(M.CREATION, M.CREATE_IDS), controls=controls)


class OverlayContractsTest(unittest.TestCase):
    def setUp(self):
        self.data = {'_meta': {'dependency_mode': 'double-backed', 'commercial_compatibility_proved': False, 'real_provider_called': False},
            'controls': {'reopen_deposit': branch('reopen_deposit'), 'accept_late': branch('accept_late'), 'cancel_review': branch('cancel_review'),
                'cancel_review_identity_safety': identity(), 'zbm_create_purchase_request': creation()}}
        self.calls = []

    def overlay(self, row='http-013', gaps=None, junit=None):
        if gaps is None:
            gaps = ['branch:reopen_deposit', 'branch:accept_late', 'registration', 'negative:form:guest', 'branch:resume']
        def passed(test, dataset=None):
            self.calls.append((test, dataset)); return True
        return M.apply_task7_overlay({'id': row}, gaps, self.data, junit or passed)

    def test_complete_branches_close_only_their_two_gaps(self):
        gaps, na, kind = self.overlay()
        self.assertEqual(['registration', 'negative:form:guest', 'branch:resume'], gaps)
        self.assertEqual({}, na)
        self.assertIn('simulated-provider', kind)
        self.assertEqual([(M.LIFECYCLE, 'reopen_deposit'), (M.LIFECYCLE, 'accept_late')], self.calls)

    def test_actual_evaluator_keeps_registration_and_junit_controls(self):
        spec = importlib.util.spec_from_file_location('task7_evaluator', ROOT / 'scripts/check-http-matrix.py')
        checker = importlib.util.module_from_spec(spec); spec.loader.exec_module(checker)
        row = next(r for r in json.loads((ROOT / 'ci/http-surfaces.json').read_text())['rows'] if r['id'] == 'http-020')
        registration = {'rows': {'http-020': {'registered': True, 'actual_http': True, 'runtime': 'authenticated-http'}}}
        old_class, old_method = row['coverage']['test'].split('::')
        new_class, new_method = M.CREATION.split('::')
        registered = ('RegisteredHttpSurfacesTest', 'test_production_surface_callback_is_actually_registered with data set "http-020"')
        junit = {(old_class, old_method): True, (new_class, new_method): True, registered: True}
        controls = {s: {'http': 403, 'reason': 'specific native denial'} for s in checker.NEGATIVES}
        for s in ('wrong_role', 'other_buyer', 'other_vendor'):
            controls[s].update(reason='zbm_request_dependencies_unavailable')
        controls['authorized_dependency_control'] = {'assertion_status': 'passed', 'http': 303,
            'dependency_code': 'zbm_request_dependencies_unavailable', 'reason': 'Dokan Pro absent', 'effect': 'valid request dependency control'}
        def evaluate():
            return checker.evaluate({'rows': [row]}, registration, {'zbm_create_purchase_request': controls}, {}, {}, junit,
                {'task7-matrix-double-backed.json': self.data})[0]
        result = evaluate()
        self.assertTrue(result['positive_and_negative_complete'])
        self.assertIn('double-backed', result['supplemental_positive_kind'])
        self.assertIn('role', result['not_applicable_controls']['zbm_create_purchase_request:other_buyer'])
        junit[(new_class, new_method)] = False
        self.assertEqual(3, len(evaluate()['dependency_blocked_controls']))
        junit[(new_class, new_method)] = True
        registration['rows']['http-020']['registered'] = False
        self.assertIn('registration', evaluate()['gaps'])
        junit[registered] = False
        self.assertIn('registration-junit', evaluate()['gaps'])

    def test_one_complete_branch_cannot_cover_the_other(self):
        del self.data['controls']['accept_late']
        self.assertIn('branch:accept_late', self.overlay()[0])
        self.assertNotIn('branch:reopen_deposit', self.overlay()[0])

    def test_failed_named_dataset_does_not_borrow_another_dataset_pass(self):
        gaps, _, _ = self.overlay(junit=lambda test, dataset=None: dataset != 'accept_late')
        self.assertIn('branch:accept_late', gaps)
        self.assertNotIn('branch:reopen_deposit', gaps)
        self.assertIn('branch:reopen_deposit', self.overlay(junit=lambda *args: False)[0])

    def test_run_and_record_labels_cannot_claim_provider_or_commercial_proof(self):
        for where in ['_meta', 'accept_late']:
            for key, value in [('dependency_mode', 'commercial'), ('real_provider_called', True), ('commercial_compatibility_proved', True)]:
                with self.subTest(where=where, key=key):
                    saved = copy.deepcopy(self.data)
                    target = self.data['_meta'] if where == '_meta' else self.data['controls'][where]
                    target[key] = value
                    self.assertIn('branch:accept_late', self.overlay()[0])
                    self.data = saved

    def test_invalid_lifecycle_evidence_cannot_retire_the_branch(self):
        mutations = [('state', 'pending'), ('assertion_status', 'failed'), ('test', 'AnotherTest::test_same'), ('dataset', 'cancel_review'),
            ('catalog_ids', ['http-014']), ('before_state', 'deposit_pending'), ('after_state', 'cancelled'), ('after_version', 3),
            ('request_id', 0), ('order_id', True), ('replay_provider_calls_added', 1), ('replay_account_emails_unchanged', False), ('actual_http', False), ('replay_preserved', False), ('replay_snapshot', snapshot())]
        for key, value in mutations:
            with self.subTest(key=key):
                saved = copy.deepcopy(self.data); self.data['controls']['accept_late'][key] = value
                self.assertIn('branch:accept_late', self.overlay()[0]); self.data = saved

    def test_amounts_state_and_provider_transport_are_substantive(self):
        mutations = [('paid_amount_toman', 101), ('ledger_amounts', {'employer_cash_received': 100, 'platform_share_provisional': 11, 'vendor_share_provisional': 89}),
            ('ledger_count', 2), ('payment_status', 'expected'), ('authority_matches', False), ('stock_released', True),
            ('provider_calls', [{'path': 'request.json', 'amount': 1000, 'expected_binding_matches': True, 'merchant_is_synthetic': True}]),
            ('provider_calls', [{'path': 'verify.json', 'amount': 100, 'expected_binding_matches': True, 'merchant_is_synthetic': True}]),
            ('provider_calls', [{'path': 'verify.json', 'amount': 1000, 'expected_binding_matches': False, 'merchant_is_synthetic': True}])]
        for key, value in mutations:
            with self.subTest(key=key, value=value):
                saved = copy.deepcopy(self.data); self.data['controls']['accept_late']['effects'][key] = value
                self.assertIn('branch:accept_late', self.overlay()[0]); self.data = saved

    def test_unchanged_positive_snapshot_and_financial_rewrite_fail_closed(self):
        record = self.data['controls']['accept_late']; record['after_snapshot'] = copy.deepcopy(record['before_snapshot'])
        record['replay_snapshot'] = copy.deepcopy(record['after_snapshot'])
        self.assertIn('branch:accept_late', self.overlay()[0])
        record = branch('accept_late'); self.data['controls']['accept_late'] = record
        record['after_snapshot']['wp_zbm_financial_actions'] = 'c' * 64; record['replay_snapshot'] = copy.deepcopy(record['after_snapshot'])
        self.assertIn('branch:accept_late', self.overlay()[0])

    def test_every_negative_requires_exact_status_and_full_unchanged_snapshot(self):
        for scenario in self.data['controls']['reopen_deposit']['negative_controls']:
            with self.subTest(scenario=scenario):
                saved = copy.deepcopy(self.data); control = self.data['controls']['reopen_deposit']['negative_controls'][scenario]
                control['after_snapshot']['wp_zbm_payments'] = 'b' * 64
                self.assertIn('branch:reopen_deposit', self.overlay()[0]); self.data = saved
        self.data['controls']['cancel_review']['negative_controls']['invalid_nonce']['http'] = 500
        self.assertEqual(['positive:form-contract'], self.overlay('http-014', ['positive:form-contract'])[0])

    def test_malformed_nested_types_and_missing_snapshot_tables_fail_closed(self):
        for value in [None, [], 'passed', 1]:
            with self.subTest(value=value):
                saved = copy.deepcopy(self.data); self.data['controls']['accept_late']['effects'] = value
                self.assertIn('branch:accept_late', self.overlay()[0]); self.data = saved
        del self.data['controls']['accept_late']['before_snapshot']['wp_zbm_payments']
        self.assertIn('branch:accept_late', self.overlay()[0])

    def test_cancellation_needs_positive_negatives_and_issued_identity_guard(self):
        gaps = ['positive:form-contract', 'branch:cancel_review', 'safety:identity-guard', 'negative:form:wrong_role', 'negative:form:no_nonce',
                'negative:form:guest', 'registration', 'positive-and-negative-controls:other']
        result = self.overlay('http-014', gaps)
        self.assertEqual(gaps[-3:], result[0])
        self.assertIn('issued-identity-preservation', result[2])
        self.data['controls']['cancel_review_identity_safety']['before_version'] = 0
        self.data['controls']['cancel_review_identity_safety']['after_version'] = 0
        self.assertEqual(gaps, self.overlay('http-014', gaps)[0])

    def test_issued_identity_cannot_be_paid_mutated_verified_or_dependency_exempted(self):
        mutations = [('provider_calls_added', 1), ('provider_verification_verified', True), ('dependency_control_claimed', True), ('before_state', 'deposit_paid')]
        for key, value in mutations:
            with self.subTest(key=key):
                saved = copy.deepcopy(self.data); self.data['controls']['cancel_review_identity_safety'][key] = value
                self.assertEqual(['safety:identity-guard'], self.overlay('http-014', ['safety:identity-guard'])[0]); self.data = saved
        self.data['controls']['cancel_review_identity_safety']['identity_contract']['reference'] = 'paid-reference'
        self.assertEqual(['safety:identity-guard'], self.overlay('http-014', ['safety:identity-guard'])[0])

    def creation_gaps(self):
        return ['zbm_create_purchase_request:negative:blocked-by-dependency:' + actor for actor in ['wrong_role', 'other_buyer', 'other_vendor']]

    def test_creation_permissions_are_scoped_na_and_never_guest_or_registration(self):
        untouched = ['registration', 'junit', 'zbm_create_purchase_request:negative:guest', 'zbm_create_purchase_request:negative:no_nonce',
                     'zbm_create_purchase_request:positive-control', 'zbm_create_purchase_request:negative:other_buyer']
        for row in M.CREATE_IDS:
            with self.subTest(row=row):
                gaps, na, kind = self.overlay(row, self.creation_gaps() + untouched)
                self.assertEqual(untouched, gaps)
                self.assertEqual({'zbm_create_purchase_request:other_buyer', 'zbm_create_purchase_request:other_vendor'}, set(na))
                self.assertTrue(all(value['role'].startswith('N/A:') for value in na.values()))
                self.assertIn('actor-scope', kind)
        self.assertEqual(self.creation_gaps(), self.overlay('http-042', self.creation_gaps())[0])

    def test_self_purchase_requires_exact_error_code(self):
        self.data['controls']['zbm_create_purchase_request']['controls']['wrong_role']['notice']['code'] = 'zbm_request_dependencies_unavailable'
        self.assertEqual(self.creation_gaps(), self.overlay('http-020', self.creation_gaps())[0])

    def test_allowed_actors_must_create_new_own_request_and_ignore_posted_identity(self):
        mutations = [('request_id', 0), ('stored_buyer_id', 1), ('stored_vendor_id', 22), ('requests_after_count', 40),
            ('existing_requests_unchanged', False), ('forged_buyer_ignored', False), ('result', 'denied')]
        for key, value in mutations:
            with self.subTest(key=key):
                saved = copy.deepcopy(self.data); self.data['controls']['zbm_create_purchase_request']['controls']['other_vendor'][key] = value
                self.assertEqual(self.creation_gaps(), self.overlay('http-020', self.creation_gaps())[0]); self.data = saved
        self.data['controls']['zbm_create_purchase_request']['controls']['other_buyer']['request_contract']['wc_order_id'] = 44
        self.assertEqual(self.creation_gaps(), self.overlay('http-020', self.creation_gaps())[0])

    def test_all_financial_and_order_snapshots_must_be_preserved_for_creation(self):
        for suffix in M.FINANCIAL_SUFFIXES + M.ORDER_SUFFIXES:
            with self.subTest(suffix=suffix):
                saved = copy.deepcopy(self.data); control = self.data['controls']['zbm_create_purchase_request']['controls']['other_buyer']
                control['before_snapshot'].setdefault('wp_' + suffix, 'a' * 64); control['after_snapshot']['wp_' + suffix] = 'b' * 64
                self.assertEqual(self.creation_gaps(), self.overlay('http-048', self.creation_gaps())[0]); self.data = saved

    def test_unrelated_method_or_failed_creation_junit_cannot_retire_actor_controls(self):
        self.assertEqual(self.creation_gaps(), self.overlay('http-021', self.creation_gaps(), junit=lambda *args: False)[0])
        self.data['controls']['zbm_create_purchase_request']['test'] = M.LIFECYCLE
        self.assertEqual(self.creation_gaps(), self.overlay('http-021', self.creation_gaps())[0])

    def test_numeric_values_cannot_forge_boolean_provider_or_request_flags(self):
        call = self.data['controls']['accept_late']['effects']['provider_calls'][0]
        call['expected_binding_matches'] = 1
        self.assertIn('branch:accept_late', self.overlay()[0])
        request = self.data['controls']['zbm_create_purchase_request']['controls']['other_buyer']['request_contract']
        request['stock_reserved'] = 0
        self.assertEqual(self.creation_gaps(), self.overlay('http-020', self.creation_gaps())[0])

    def test_native_wordpress_prefix_needs_no_trailing_separator(self):
        for prefix in ['_ci', 'wm_']:
            with self.subTest(prefix=prefix):
                def rename(value):
                    if isinstance(value, dict):
                        return {(prefix + key[3:] if key.startswith('wp_') else key): rename(item) for key, item in value.items()}
                    if isinstance(value, list):
                        return [rename(item) for item in value]
                    return value
                saved = self.data; self.data = rename(saved)
                self.assertNotIn('branch:accept_late', self.overlay()[0])
                self.assertEqual([], self.overlay('http-014', ['safety:identity-guard'])[0])
                self.assertEqual([], self.overlay('http-020', self.creation_gaps())[0])
                self.data = saved


if __name__ == '__main__':
    unittest.main()

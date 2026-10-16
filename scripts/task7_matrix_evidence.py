"""Narrow, fail-closed Task 7 overlay for native HTTP with dependency doubles.

Freshness/run metadata is checked by the caller's supplemental evidence loader.
This module never claims commercial compatibility or real provider verification.
"""
import re

LIFECYCLE = 'MatrixDoubleBackedTest::test_rendered_native_lifecycle_branch'
IDENTITY = 'MatrixDoubleBackedTest::test_rendered_cancellation_preserves_issued_identity_before_any_verifier'
CREATION = 'MatrixDoubleBackedTest::test_rendered_creation_resolves_actor_scope_after_dependency_gate'
CREATE_IDS = ['http-020', 'http-021', 'http-048']
SUCCESS = 'عملیات مدیریت همراه با دلیل و سابقه حسابرسی ثبت شد.'
CANCEL_ERROR = 'درخواست، نسخه، دسترسی یا دلیل لغو معتبر نیست.'
GUARD_ERROR = 'لغو امن انجام نشد؛ وجود پرداخت قطعی یا وضعیت موجودی/سفارش باید بررسی شود.'
SNAPSHOT_SUFFIXES = ('zbm_managed_cases', 'zbm_payments', 'zbm_ledger_entries', 'zbm_financial_actions',
                     'zbm_purchase_requests', 'zbm_audit_logs', 'zbm_dokan_credit_mirrors',
                     'zbm_managed_case_commands', 'posts', 'postmeta', 'woocommerce_order_items',
                     'woocommerce_order_itemmeta')
ORDER_SUFFIXES = ('posts', 'postmeta', 'woocommerce_order_items', 'woocommerce_order_itemmeta',
                  'wc_orders', 'wc_order_addresses', 'wc_order_operational_data', 'wc_orders_meta')
FINANCIAL_SUFFIXES = ('zbm_payments', 'zbm_ledger_entries', 'zbm_financial_actions', 'zbm_dokan_credit_mirrors')


def _integer(value, minimum=0):
    return type(value) is int and value >= minimum


def _labels(record):
    return (isinstance(record, dict) and record.get('dependency_mode') == 'double-backed'
            and record.get('commercial_compatibility_proved') is False and record.get('real_provider_called') is False
            and not any(record.get(key) for key in ('synthetic_dependency', 'mock_provider', 'dependency_overridden', 'provider_verified')))


def _base(record, test, ids, junit, dataset=None):
    return (_labels(record) and record.get('state') == record.get('assertion_status') == 'passed'
            and record.get('actual_http') is True and record.get('test') == test
            and record.get('catalog_ids') == ids and junit(test, dataset) is True
            and (dataset is None or record.get('dataset') == dataset))


def _snapshot_pair(record, unchanged=False):
    before, after = record.get('before_snapshot'), record.get('after_snapshot')
    if not isinstance(before, dict) or not before or not isinstance(after, dict) or set(before) != set(after):
        return False
    if not all(isinstance(key, str) and isinstance(value, str) and re.fullmatch(r'[0-9a-f]{64}', value)
               for snapshot in (before, after) for key, value in snapshot.items()):
        return False
    if not all(any(key.endswith(suffix) for key in before) for suffix in SNAPSHOT_SUFFIXES):
        return False
    return not unchanged or before == after


def _same_tables(record, suffixes):
    before, after = record['before_snapshot'], record['after_snapshot']
    return all(before[key] == after[key] for key in before if key.endswith(suffixes))


def _changed(record, suffix):
    return any(key.endswith(suffix) and digest != record['after_snapshot'][key]
               for key, digest in record['before_snapshot'].items())


def _notice(record, kind, message):
    return record.get('notice') == {'type': kind, 'message': message}


def _negative(record, operation, scenario, ids):
    actors = {'wrong_role': 'buyer_1', 'other_buyer': 'buyer_2', 'other_vendor': 'vendor_2', 'guest': 'guest'}
    status = 400 if scenario == 'guest' else (500 if scenario in actors else (403 if scenario in ('no_nonce', 'invalid_nonce') else 302))
    if not (isinstance(record, dict) and record.get('state') == record.get('assertion_status') == 'passed'
            and record.get('actual_http') is True and record.get('catalog_ids') == ids
            and type(record.get('http')) is int and record['http'] == status and record.get('actor') == actors.get(scenario, 'admin')
            and record.get('snapshot_unchanged') is True and record.get('financial_unchanged') is True
            and type(record.get('provider_calls_added')) is int and record['provider_calls_added'] == 0
            and _snapshot_pair(record, True)):
        return False
    expected_contract = 'guest-native-dispatcher' if scenario == 'guest' else ('unauthorized' if scenario in actors else ('wordpress-expired-link' if status == 403 else 'admin-notice'))
    if record.get('response_contract') != expected_contract:
        return False
    if status != 302:
        return record.get('notice') is None
    message = {'reopen_deposit': 'دلیل و مدت معتبر برای بازگشایی لازم است.',
               'accept_late': 'دسترسی و دلیل مستند مدیریت معتبر نیست.', 'cancel_review': CANCEL_ERROR}[operation]
    return _notice(record, 'error', message)


def _branch(record, operation, junit):
    ids = ['http-014'] if operation == 'cancel_review' else ['http-013']
    if not _base(record, LIFECYCLE, ids, junit, operation) or not _snapshot_pair(record):
        return False
    scenarios = ['wrong_role', 'other_buyer', 'other_vendor', 'guest', 'no_nonce', 'invalid_nonce', 'missing_reason']
    scenarios += ['invalid_hours'] if operation == 'reopen_deposit' else (['stale_version'] if operation == 'cancel_review' else [])
    negatives = record.get('negative_controls')
    if not isinstance(negatives, dict) or set(negatives) != set(scenarios):
        return False
    if not all(_negative(negatives[scenario], operation, scenario, ids) for scenario in scenarios):
        return False
    states = {'reopen_deposit': ('deposit_expired', 'deposit_pending'),
              'accept_late': ('late_payment_review', 'deposit_paid'), 'cancel_review': ('late_payment_review', 'cancelled')}
    events = {'reopen_deposit': 'deposit_deadline_reopen_requested', 'accept_late': 'late_deposit_accepted', 'cancel_review': 'admin_review_cancelled'}
    if not (record.get('branch') == operation and type(record.get('http')) is int and record['http'] == 302
            and record.get('actor') == 'admin' and _integer(record.get('request_id'), 1) and _integer(record.get('order_id'), 1)
            and (record.get('before_state'), record.get('after_state')) == states[operation]
            and _integer(record.get('before_version')) and _integer(record.get('after_version'), 1)
            and record['after_version'] > record['before_version']
            and all(record.get(key) is True for key in ('nonce_valid', 'actual_form_submitted', 'snapshot_matches', 'replay_preserved', 'account_emails_unchanged'))
            and _notice(record, 'success', SUCCESS) and _same_tables(record, ('zbm_financial_actions', 'zbm_dokan_credit_mirrors'))
            and _changed(record, 'zbm_purchase_requests') and _changed(record, 'zbm_audit_logs')):
        return False
    replay_message = {'reopen_deposit': 'این مهلت پرداخت قابل بازگشایی نیست.',
                      'accept_late': 'این پرونده پرداخت دیرهنگام قابل پذیرش نیست.', 'cancel_review': CANCEL_ERROR}[operation]
    if not (record.get('replay_http') == 302
            and type(record.get('replay_provider_calls_added')) is int and record['replay_provider_calls_added'] == 0
            and record.get('replay_account_emails_unchanged') is True and record.get('replay_snapshot') == record['after_snapshot']
            and record.get('replay_notice') == {'type': 'error', 'message': replay_message}):
        return False
    audit = record.get('audit', {})
    if not (isinstance(audit, dict) and audit.get('event_key') == events[operation] and audit.get('actor_type') == 'admin'
            and _integer(audit.get('actor_id'), 1) and type(audit.get('count')) is int and audit['count'] == 1
            and isinstance(audit.get('event_uuid'), str) and re.fullmatch(r'[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}', audit['event_uuid'])):
        return False
    effects = record.get('effects', {})
    if not (isinstance(effects, dict) and effects.get('payment_count') == 1 and type(effects.get('payment_count')) is int
            and effects.get('order_id_matches') is True and effects.get('reconciliation_required') is False):
        return False
    if operation == 'accept_late':
        calls = effects.get('provider_calls')
        return (effects.get('payment_status') == 'late_accepted' and type(effects.get('paid_amount_toman')) is int
                and effects['paid_amount_toman'] == 100 and effects.get('ledger_count') == 3
                and effects.get('ledger_amounts') == {'employer_cash_received': 100, 'platform_share_provisional': 10, 'vendor_share_provisional': 90}
                and all(type(value) is int for value in effects['ledger_amounts'].values())
                and type(effects.get('simulated_verification_calls')) is int and effects['simulated_verification_calls'] == 1 and effects.get('stock_reserved') is True and effects.get('stock_released') is False
                and all(effects.get(key) is True for key in ('authority_matches', 'reference_matches', 'order_paid', 'transaction_matches'))
                and effects.get('order_status') in ('processing', 'completed')
                and isinstance(calls, list) and len(calls) == 1 and isinstance(calls[0], dict)
                and calls == [{'path': 'verify.json', 'amount': 1000, 'expected_binding_matches': True, 'merchant_is_synthetic': True}]
                and type(calls[0]['amount']) is int and calls[0]['expected_binding_matches'] is True and calls[0]['merchant_is_synthetic'] is True
                and _changed(record, 'zbm_payments') and _changed(record, 'zbm_ledger_entries'))
    return (effects.get('payment_status') == ('expected' if operation == 'reopen_deposit' else 'expired')
            and 'paid_amount_toman' in effects and effects['paid_amount_toman'] is None
            and type(effects.get('ledger_count')) is int and effects['ledger_count'] == 0 and effects.get('provider_calls') == []
            and _same_tables(record, ('zbm_ledger_entries',))
            and effects.get('order_status') == ('pending' if operation == 'reopen_deposit' else 'cancelled')
            and (effects.get('stock_reserved') is True and effects.get('stock_released') is False
                 and effects.get('payment_ready') is True and effects.get('deadline_in_window') is True
                 if operation == 'reopen_deposit' else effects.get('stock_released') is True and record.get('historical_unissued_review_fixture') is True))


def _identity(record, junit):
    if not _base(record, IDENTITY, ['http-014'], junit) or not _snapshot_pair(record, True):
        return False
    identity = record.get('identity_contract')
    return (isinstance(identity, dict) and record.get('type') == 'validation' and record.get('http') == 302
            and record.get('actor') == 'admin' and _integer(record.get('request_id'), 1)
            and _integer(record.get('before_version'), 1) and record['before_version'] == record.get('after_version')
            and record.get('before_state') == record.get('after_state') == 'late_payment_review'
            and all(record.get(key) is True for key in ('nonce_valid', 'actor_valid', 'stored_binding_valid', 'persisted_assertions',
                'financial_unchanged', 'identity_guard_before_verifier', 'account_emails_unchanged'))
            and record.get('provider_verification_verified') is False and record.get('dependency_control_claimed') is False
            and type(record.get('provider_calls_added')) is int and record['provider_calls_added'] == 0
            and _notice(record, 'error', GUARD_ERROR)
            and identity.get('snapshot_matches') is True and identity.get('authority_matches') is True
            and identity.get('route') == 'online' and identity.get('execution_mode') == 'live'
            and identity.get('payment_status') == 'callback_received_late'
            and all(key in identity and identity[key] is None for key in ('paid_amount', 'reference', 'paid_at')))


def _creation(record, junit):
    if not _base(record, CREATION, CREATE_IDS, junit):
        return False
    controls = record.get('controls')
    if not isinstance(controls, dict):
        return False
    for scenario, actor in [('wrong_role', 'vendor_1'), ('other_buyer', 'buyer_2'), ('other_vendor', 'vendor_2')]:
        control = controls.get(scenario)
        if not (isinstance(control, dict) and control.get('test') == CREATION and control.get('catalog_ids') == CREATE_IDS
                and control.get('assertion_status') == 'passed' and control.get('actual_http') is True
                and control.get('http') == 303 and control.get('actor') == actor and _snapshot_pair(control)
                and all(control.get(key) is True for key in ('dependency_error_absent', 'actual_form_submitted', 'account_emails_unchanged'))
                and type(control.get('provider_calls_added')) is int and control['provider_calls_added'] == 0):
            return False
        if scenario == 'wrong_role':
            if not (control.get('result') == 'rejected-exact-contract-no-persisted-effect' and control.get('snapshot_unchanged') is True
                    and control['before_snapshot'] == control['after_snapshot']
                    and control.get('notice') == {'type': 'error', 'code': 'zbm_self_purchase_forbidden',
                        'message': 'فروشنده نمی‌تواند برای محصول خودش درخواست خرید ثبت کند.'}):
                return False
            continue
        request = control.get('request_contract')
        if not (isinstance(request, dict) and control.get('result') == 'allowed-own-scope-native-creation'
                and all(control.get(key) is True for key in ('forged_buyer_ignored', 'forged_vendor_ignored', 'financial_and_orders_unchanged', 'existing_requests_unchanged'))
                and all(_integer(control.get(key), 1) for key in ('request_id', 'stored_buyer_id', 'stored_vendor_id', 'actor_user_id',
                    'product_vendor_id', 'product_id', 'posted_buyer_id', 'posted_vendor_id'))
                and control['stored_buyer_id'] == control['actor_user_id'] != control['posted_buyer_id']
                and control['stored_vendor_id'] == control['product_vendor_id'] != control['posted_vendor_id']
                and control['actor_user_id'] != control['product_vendor_id']
                and _integer(control.get('requests_before_count')) and _integer(control.get('requests_after_count'), 1)
                and control['requests_after_count'] == control['requests_before_count'] + 1
                and all(type(request.get(key)) is int for key in ('id', 'buyer_id', 'vendor_id', 'product_id', 'version', 'payments_count', 'ledger_count'))
                and request.get('stock_reserved') is False
                and request == {'id': control['request_id'], 'buyer_id': control['actor_user_id'], 'vendor_id': control['product_vendor_id'],
                    'product_id': control['product_id'], 'state': 'supplier_review', 'execution_mode': 'live', 'version': 0,
                    'wc_order_id': None, 'stock_reserved': False, 'payments_count': 0, 'ledger_count': 0}
                and all(any(key.endswith(suffix) for key in control['before_snapshot']) for suffix in ('wc_orders', 'wc_orders_meta'))
                and _same_tables(control, FINANCIAL_SUFFIXES + ORDER_SUFFIXES) and _changed(control, 'zbm_purchase_requests')):
            return False
    return controls['other_buyer']['request_id'] != controls['other_vendor']['request_id']


def apply_task7_overlay(row, gaps, data, junit_pass_callback):
    """Return remaining gaps, scoped N/A contracts, and bounded evidence kind.

    ``junit_pass_callback(test, dataset=None)`` must consult this run's JUnit.
    Missing or malformed evidence leaves all existing gaps untouched.
    """
    unchanged = (list(gaps), {}, None)
    if not isinstance(data, dict) or not _labels(data.get('_meta')):
        return unchanged
    controls = data.get('controls')
    if not isinstance(controls, dict) or not isinstance(row, dict):
        return unchanged
    try:
        row_id = row.get('id')
        if row_id == 'http-013':
            accepted = {operation for operation in ('reopen_deposit', 'accept_late') if _branch(controls.get(operation), operation, junit_pass_callback)}
            return ([gap for gap in gaps if gap not in {'branch:' + operation for operation in accepted}], {},
                    'actual-http-double-backed-lifecycle-simulated-provider' if accepted else None)
        if row_id == 'http-014' and _branch(controls.get('cancel_review'), 'cancel_review', junit_pass_callback) and _identity(controls.get('cancel_review_identity_safety'), junit_pass_callback):
            retired = {'positive:form-contract', 'branch:cancel_review', 'safety:identity-guard'}
            retired.update('negative:form:' + scenario for scenario in ('wrong_role', 'other_buyer', 'other_vendor', 'no_nonce', 'invalid_nonce'))
            return [gap for gap in gaps if gap not in retired], {}, 'actual-http-double-backed-unissued-cancel-and-issued-identity-preservation'
        if row_id in CREATE_IDS and _creation(controls.get('zbm_create_purchase_request'), junit_pass_callback):
            endpoint = 'zbm_create_purchase_request'
            retired = {endpoint + ':negative:blocked-by-dependency:' + actor for actor in ('wrong_role', 'other_buyer', 'other_vendor')}
            na = {endpoint + ':' + actor: {'role': 'N/A: tested native permission permits own new purchase; authenticated buyer and product vendor determine scope'} for actor in ('other_buyer', 'other_vendor')}
            return [gap for gap in gaps if gap not in retired], na, 'actual-http-double-backed-native-creation-actor-scope'
    except (KeyError, TypeError, ValueError, AttributeError):
        return unchanged
    return unchanged

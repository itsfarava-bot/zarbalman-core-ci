#!/usr/bin/env python3
"""Fail closed on incomplete, stale, or dispatcher-only HTTP verification.
Detailed diagnostics are written only below ignored evidence/. Stdout is counts only.
Run marker must be created immediately before PHPUnit, in an emptied evidence directory.
"""
from pathlib import Path
import argparse
import collections
import hashlib
import json
import os
import re
import sys
import xml.etree.ElementTree as ET
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[1]
NEGATIVES = ('guest', 'wrong_role', 'other_buyer', 'other_vendor', 'no_nonce', 'invalid_nonce')
MANAGED_METHOD = 'test_operation_positive_or_explicit_dependency_control_and_six_rejections'


def discover(root):
    """Discover source declarations; identities do not depend on source line numbers.

    Form hashes cover their actual routing/field block, with whitespace normalized.
    This deliberately requires review if a form contract changes. It does not turn
    source declarations into runtime registration or coverage evidence.
    """
    rows = []
    def add(kind, path, surface, callback='', fingerprint=''):
        rows.append(dict(kind=kind, source_path=str(path.relative_to(root)), surface=surface,
                         callback=callback, fingerprint=fingerprint))
    for path in sorted((root / 'plugin').rglob('*.php')):
        source = path.read_text()
        constants = {m[1]: m[3] for m in re.finditer(r'const\s+(\w+)\s*=\s*([\'\"])(.*?)\2', source, re.S)}
        for m in re.finditer(r'add_action\s*\(\s*((?:[\'\"])(?:admin_post_|wp_ajax_).*?)\s*,\s*array\(\s*\$this\s*,\s*[\'\"]([^\'\"]+)[\'\"]', source, re.S):
            literal = re.match(r'([\'\"])(.*?)\1', m[1], re.S)
            name = literal[2]
            suffix = re.search(r'\.\s*self::(\w+)', m[1])
            if suffix:
                name += constants[suffix[1]]
            add('action', path, name, m[2])
        parsed_actions = sum(r['source_path'] == str(path.relative_to(root)) and r['kind'] == 'action' for r in rows)
        declared_actions = len(re.findall(r"add_action\s*\(\s*['\"](?:admin_post_|wp_ajax_)", source))
        if parsed_actions != declared_actions:
            add('unresolved action', path, 'unsupported-registration-expression')
        for m in re.finditer(r'add_shortcode\s*\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*array\(\s*\$this\s*,\s*[\'\"]([^\'\"]+)[\'\"]', source, re.S):
            add('shortcode', path, m[1], m[2])
        parsed_shortcodes = sum(r['source_path'] == str(path.relative_to(root)) and r['kind'] == 'shortcode' for r in rows)
        if parsed_shortcodes != len(re.findall(r'add_shortcode\s*\(', source)):
            add('unresolved shortcode', path, 'unsupported-registration-expression')
        parsed_rest_calls = 0
        for m in re.finditer(r'register_rest_route\s*\(\s*self::NAMESPACE\s*,\s*[\'\"]([^\'\"]+)[\'\"]', source, re.S):
            parsed_rest_calls += 1
            end = source.find('\n\t\t);', m.end())
            for method, callback in re.findall(r"'methods'\s*=>\s*'(\w+)'.*?'callback'\s*=>\s*array\(\s*\$this\s*,\s*'(\w+)'", source[m.start():end], re.S):
                add('REST', path, f'{method} /{constants["NAMESPACE"]}{m[1]}', callback)
        parsed_rest_methods = sum(r['source_path'] == str(path.relative_to(root)) and r['kind'] == 'REST' for r in rows)
        if parsed_rest_calls != len(re.findall(r'register_rest_route\s*\(', source)) or (parsed_rest_calls and not parsed_rest_methods):
            add('unresolved REST', path, 'unsupported-registration-expression')
        for index, m in enumerate(re.finditer(r'<form\b', source), 1):
            end = source.find('</form>', m.end())
            segment = source[m.start():end if end != -1 else m.end() + 1700]
            signature = hashlib.sha256(re.sub(r'\s+', ' ', segment).encode()).hexdigest()
            add('form', path, f'form-{index}', fingerprint=signature)
        if path.name == 'DokanVendorReportsEndpoint.php':
            for m in re.finditer(r"add_action\s*\(\s*'dokan_load_custom_template'\s*,\s*array\(\s*\$this\s*,\s*'(\w+)'", source):
                add('Dokan view', path, 'dokan:zbm-vendor-reports', m[1])
    return rows


def junit_results(path):
    results = {}
    for case in ET.parse(path).iter('testcase'):
        name = case.get('name', '')
        cls = case.get('classname') or case.get('class', '')
        ok = int(case.get('assertions', '0')) > 0 and not any(case.find(tag) is not None for tag in ('failure', 'error', 'skipped'))
        results[(cls, name)] = ok
    return results


def junit_pass(results, test, dataset=None):
    if not test or '::' not in test:
        return False
    cls, method = test.split('::', 1)
    if dataset is not None:
        return results.get((cls, method + ' with data set "' + dataset + '"'), False)
    matches = [ok for (name, case), ok in results.items() if name == cls and (case == method or case.startswith(method + ' with data set '))]
    return bool(matches) and all(matches)


def negative_ok(row):
    """Require observed contracts; dispatcher-only 400 is not a named denial.

    A validation 400 needs an explicit response message/code/notice, not the
    scenario label. This helper is paired with passing same-run JUnit by callers.
    """
    if not isinstance(row, dict) or row.get('assertion_status', 'passed') != 'passed':
        return False
    if row.get('generic_dispatcher_only') is True: return False
    observed = row.get('observed', row)
    if not isinstance(observed, dict): return False
    notice = observed.get('notice')
    notice_message = notice.get('message') if isinstance(notice, dict) else None
    reason_text = ' '.join(str(observed.get(key, '')) for key in ('reason', 'message', 'code')).lower()
    dispatcher = any(label in reason_text for label in (
        'dispatcher', 'no public', 'no handler', 'unregistered', 'nopriv',
        'default wordpress', 'generic wordpress',
    ))
    if observed.get('http') == 400:
        body = observed.get('body')
        if dispatcher or (isinstance(body, str) and body.strip() in ('', '0', 'wp_die')):
            return False
        specific = [observed.get('message'), observed.get('code'), notice_message]
        generic = {'', '0', '400', 'bad request', 'wordpress', 'wp_die', 'wp-die-message'}
        return any(isinstance(value, str) and value.strip().lower() not in generic for value in specific)
    reason = observed.get('reason') or observed.get('message') or notice_message or row.get('effect')
    return bool(reason) and isinstance(observed.get('http'), int)


def not_applicable_contracts(endpoint, controls):
    """Expose explicit tested N/A contracts without calling public reads denials."""
    result = {}
    public_reads = {'zbm_request_auth_state', 'zbm_request_pricing_preview', 'zbm_request_vendor_products'}
    for scenario in NEGATIVES:
        control = controls.get(scenario, {})
        contract = control.get('nonce_contract', '')
        if not negative_ok(control) or not isinstance(contract, str) or not contract.startswith('N/A:'):
            continue
        if scenario in ('no_nonce', 'invalid_nonce'):
            result[endpoint + ':' + scenario] = {'nonce': contract}
        if endpoint in public_reads and scenario != 'guest':
            result.setdefault(endpoint + ':' + scenario, {})['role'] = 'N/A: tested public or authenticated read returns server-derived scope'
        if endpoint == 'zbm_balance_callback':
            result.setdefault(endpoint + ':' + scenario, {})['role'] = 'N/A: tested public callback checks persisted provider identity'
    return result


def managed_positive(row):
    if row.get('state') != 'passed':
        return False
    positive = row.get('positive', {})
    result = positive.get('result')
    if result in ('accepted-durable-effects', 'accepted-durable-review-provider-unverified'):
        return positive.get('http') in (200, 303) and positive.get('after_version', -1) > positive.get('before_version', -1) >= 0 and positive.get('effects', {}).get('durable_action_specific_assertions') is True
    if result == 'retired-fail-closed':
        return row.get('operation') == 'flow_override_delivery' and bool(positive.get('reason')) and negative_ok(positive)
    if result == 'untestable-commercial-dependency':
        reason = positive.get('reason', '').lower()
        return row.get('operation') == 'manual_offer' and any(d in reason for d in ('yith', 'dokan', 'gateway', 'commercial')) and negative_ok(positive)
    # Missing native assertions are not a commercial dependency exemption.
    return False


def remaining_positive(endpoint, rows):
    """Known executed evidence contracts, paired with passing JUnit, not HTTP status alone."""
    positives = {k: v for k, v in rows.items() if k.startswith('authorized') and v.get('assertion_status') == 'passed'}
    def exact(key, effect):
        p = positives.get(key, {})
        return p.get('effect') == effect and p.get('http') in (200, 302, 303)
    expected = {
        'zbm_core_self_test': ('authorized_positive', 'real selftest result stored'),
        'zbm_core_test_mode': ('authorized_disable_positive', 'legacy setting disabled with audit'),
        'zbm_finance_destination': ('authorized_positive', 'private destination returned only with durable view audit'),
        'zbm_finance_export': ('authorized_positive', 'read-only actual CSV; dangerous formula field escaped'),
        'zbm_buyer_requests': ('authorized_positive', 'owner request detail rendered'),
        'zbm_order_workflow': ('authorized_positive', 'owner legacy workflow rendered'),
    }
    if endpoint in expected:
        key, effect = expected[endpoint]
        p = positives.get(key, {})
        if not exact(key, effect): return False
        if endpoint == 'zbm_core_self_test': return isinstance(p.get('checks'), int) and p['checks'] > 0
        if endpoint == 'zbm_core_test_mode':
            return p.get('audit_id', 0) > 0 and exact('authorized_enable_retired', 'retired mutation correctly denied') and bool(positives['authorized_enable_retired'].get('reason'))
        if endpoint == 'zbm_finance_destination': return p.get('audit_id', 0) > 0
        if endpoint == 'zbm_finance_export': return p.get('ledger_id', 0) > 0
        return True
    if endpoint.startswith('REST '):
        p = positives.get('authorized_positive', {})
        result = p.get('result', {})
        if endpoint.startswith('REST GET '):
            return exact('authorized_positive', 'owner-scoped read; private pause reason omitted') and result.get('order_id', 0) > 0 and 'state' in result and 'version' in result
        return exact('authorized_positive', 'actual REST workflow mutation persisted') and result.get('version', 0) > 0 and bool(result.get('event_uuid'))
    if endpoint in ('zbm_request_auth_state', 'zbm_request_pricing_preview', 'zbm_request_vendor_products'):
        return exact('authorized_positive', 'allowed read; nonce N/A by source contract; submitted role/vendor does not change server product scope') and bool(positives['authorized_positive'].get('nonce_contract'))
    if endpoint == 'zbm_save_vendor_shipping_rate':
        return all(exact('authorized_' + operation, 'real vendor shipping rate ' + operation + ' persisted') for operation in ('add', 'update', 'delete'))
    if endpoint.startswith('zbm_vendor_request_decision/') or endpoint == 'zbm_create_purchase_request':
        p = positives.get('authorized_dependency_control', {})
        reason = p.get('reason', '') + ' ' + p.get('message', '')
        code = p.get('dependency_code') or p.get('reason')
        return p.get('http') == 303 and code == 'zbm_request_dependencies_unavailable' and 'Dokan Pro' in reason and 'valid' in p.get('effect', '')
    if endpoint == 'zpal_manual_verify':
        p = positives.get('authorized_retired_control', {})
        return p.get('http') == 403 and p.get('effect') == 'retired dependency manual mutation intercepted for managed order' and bool(p.get('reason'))
    # Ambiguous not_payable, forged callbacks and a rendered dependency gate do
    # not prove accepted provider issuance/verification or an exact dependency cause.
    return False


def check_remaining(endpoint, rows):
    gaps = []
    if not remaining_positive(endpoint, rows): gaps.append('positive-control')
    for scenario in NEGATIVES:
        if endpoint == 'zbm_supplier_requests' and scenario in ('guest', 'wrong_role', 'other_buyer', 'other_vendor'):
            # The real health gate runs before the role/ownership checks. Its
            # paragraph proves the dependency control, not those later checks.
            gaps.append('negative:blocked-by-dependency:' + scenario)
        elif endpoint == 'zbm_create_purchase_request' and scenario in ('wrong_role', 'other_buyer', 'other_vendor') and rows.get(scenario, {}).get('reason') == 'zbm_request_dependencies_unavailable':
            gaps.append('negative:blocked-by-dependency:' + scenario)
        elif not negative_ok(rows.get(scenario)): gaps.append('negative:' + scenario)
    return gaps


def additional_controls(contract, data):
    group = data.get(contract['scenario'], {})
    if group.get('status') != 'passed': return ['scenario-incomplete']
    detail = group.get('detail', {})
    gaps = []
    for key in contract['positive']:
        p = detail.get(key, {})
        reason = p.get('reason', '')
        # Names alone and a 2xx/3xx response do not establish a positive.
        persisted = p.get('audit_id', 0) > 0 or 'durably paused with audit' in reason
        read = (p.get('sha256') and len(p['sha256']) == 64) or ('own scoped report rendered' in reason and p.get('deposit_toman', 0) > 0)
        routing = 'safe local return encoded and login filter consumes cookie' == reason
        if p.get('http') not in (200, 302, 303) or not (persisted or read or routing): gaps.append('positive:' + key)
    for key in contract['negative']:
        if not negative_ok(detail.get(key)): gaps.append('negative:' + key)
    return gaps


def snapshot_valid(snapshot):
    required = ('zbm_managed_cases', 'zbm_payments', 'zbm_ledger_entries', 'zbm_financial_actions', 'zbm_purchase_requests', 'zbm_audit_logs')
    return isinstance(snapshot, dict) and all(any(key.endswith(suffix) for key in snapshot) for suffix in required) and all(isinstance(value, str) and re.fullmatch(r'[0-9a-f]{64}', value) for value in snapshot.values())


def supplemental_base(record, binding, row_id, junit):
    return (isinstance(record, dict) and record.get('state') == 'passed'
            and record.get('assertion_status') == 'passed' and record.get('actual_http') is True
            and record.get('test') == binding['test'] and row_id in record.get('catalog_ids', [])
            and junit_pass(junit, binding['test'])
            and not any(record.get(key) for key in ('synthetic_dependency', 'mock_dependency', 'mock_provider', 'dependency_overridden')))


def actual_dependency_report(record, allowed_keys):
    report = record.get('dependency_report', {})
    missing = record.get('missing_dependencies', {})
    currency = report.get('currency', {})
    if currency.get('ok') is not True or currency.get('detected') != 'IRT': return False
    if not isinstance(missing, dict) or not missing or not set(missing).issubset(allowed_keys): return False
    for key, row in missing.items():
        if row != report.get(key) or row.get('ok') is not False: return False
        if not all(isinstance(row.get(field), str) for field in ('label', 'detected', 'expected', 'scope')): return False
        if not row['label'] or not row['expected']: return False
    expected_versions = {'zarinpal_version': '5.0.17', 'dokan_lite': '5.0.10', 'dokan_pro': '5.0.8', 'yith': '2.41.0'}
    for key in allowed_keys:
        row = report.get(key, {})
        if not isinstance(row.get('ok'), bool): return False
        if key in expected_versions and row.get('expected') != expected_versions[key]: return False
    return True


def unchanged_supplement(record):
    before, after = record.get('before_snapshot'), record.get('after_snapshot')
    return snapshot_valid(before) and snapshot_valid(after) and before == after


def payment_control(record, binding, row_id, junit):
    if not supplemental_base(record, binding, row_id, junit): return False
    endpoint = binding['endpoint']
    if endpoint == 'zbm_balance_start':
        fixture = record.get('fixture_contract', {})
        true_fields = ('live', 'owned', 'delivered', 'final_locked', 'deposit_verified', 'spec4_valid', 'payable')
        false_fields = ('paused', 'reconciliation_required', 'manual_balance')
        notice = record.get('notice', {})
        return (record.get('type') == 'dependency' and record.get('http') == 303
                and all(record.get(key) is True for key in ('actor_valid', 'key_valid', 'nonce_valid', 'financial_unchanged', 'case_unchanged', 'audit_unchanged'))
                and all(fixture.get(key) is True for key in true_fields) and all(fixture.get(key) is False for key in false_fields)
                and fixture.get('state') == fixture.get('substate') == 'balance_due'
                and fixture.get('total_toman') == 1000 and fixture.get('deposit_toman') == 100
                and fixture.get('balance_toman') == 0 and fixture.get('remaining_toman') == 900
                and record.get('online_available') is False and record.get('provider_issuance_verified') is False
                and notice.get('type') == 'error' and bool(notice.get('message'))
                and actual_dependency_report(record, {'zarinpal_version', 'zarinpal_settings'})
                and unchanged_supplement(record))
    if endpoint == 'zbm_balance_callback':
        if binding['scenario'].startswith('invalid_authority'):
            return (record.get('type') == 'validation' and record.get('http') == 400
                    and negative_ok(record) and record.get('financial_unchanged') is True
                    and unchanged_supplement(record))
        before, after = record.get('before_snapshot'), record.get('after_snapshot')
        if not snapshot_valid(before) or not snapshot_valid(after) or set(before) != set(after): return False
        allowed = ('zbm_payments', 'zbm_managed_cases', 'zbm_audit_logs', 'zbm_managed_case_commands', 'zbm_notification_outbox')
        if any(before[key] != after[key] for key in before if not key.endswith(allowed)): return False
        changed = ('zbm_payments', 'zbm_managed_cases', 'zbm_audit_logs')
        if not all(any(key.endswith(suffix) and before[key] != after[key] for key in before) for suffix in changed): return False
        return (record.get('type') == 'dependency' and record.get('http') == 303
                and all(record.get(key) is True for key in ('stored_binding_valid', 'callback_observed', 'amounts_and_ledger_unchanged'))
                and all(record.get(key) is False for key in ('provider_credentials_configured', 'provider_verified', 'outbound_attempted'))
                and record.get('provider_prerequisite_error') == 'Provider verification settings unavailable.'
                and record.get('after_version', -1) == record.get('before_version', -1) + 2
                and record.get('after_state') == 'admin_review' and record.get('substate') == 'payment_review'
                and record.get('payment_status') == 'verification_pending' and record.get('balance_toman') == 0
                and record.get('remaining_toman') == 900
                and record.get('audit_events') == ['spec4_callback_received', 'spec4_payment_review']
                and actual_dependency_report(record, {'zarinpal_version', 'zarinpal_settings'}))
    if endpoint == 'zbm_supplier_requests':
        return (record.get('type') == 'dependency' and record.get('http') == 200
                and record.get('can_use_dokan_bridge') is False and record.get('owner_detail_rendered') is False
                and record.get('approved_owner_vendor') is True and record.get('financial_unchanged') is True
                and record.get('case_unchanged') is True and bool(record.get('message'))
                and actual_dependency_report(record, {'dokan_lite', 'dokan_pro'})
                and record.get('dependency_report', {}).get('dokan_pro', {}).get('ok') is False
                and record.get('dependency_report', {}).get('dokan_pro', {}).get('detected') == ''
                and unchanged_supplement(record))
    return False


def apply_payment_overlay(row, gaps, supplemental, junit):
    bindings = row.get('supplemental', [])
    if not bindings: return gaps, {}, None
    records = []
    for binding in bindings:
        record = supplemental.get(binding['file'], {}).get(binding['endpoint'], {}).get(binding['scenario'], {})
        if not payment_control(record, binding, row['id'], junit): return gaps, {}, None
        records.append(record)
    endpoints = {binding['endpoint'] for binding in bindings}
    # The overlay supplies qualifying positive/validation proof. Original guest
    # dispatcher gaps and all independent required negative controls remain.
    gaps = [gap for gap in gaps if not any(gap == endpoint + ':positive-control' for endpoint in endpoints)]
    na = {}
    for binding, record in zip(bindings, records):
        verified = [(binding['scenario'], record)]
        if binding['endpoint'] == 'zbm_supplier_requests':
            controls = supplemental.get(binding['file'], {}).get(binding['endpoint'], {})
            verified.extend((scenario, controls[scenario]) for scenario in NEGATIVES
                            if scenario in controls and payment_control(controls[scenario], binding, row['id'], junit))
        for scenario, control in verified:
            for kind in ('role', 'nonce'):
                contract = control.get(kind + '_contract')
                if kind == 'role' and binding['endpoint'] == 'zbm_supplier_requests':
                    continue
                if isinstance(contract, str) and contract.startswith('N/A:'):
                    na.setdefault(binding['endpoint'] + ':' + scenario, {})[kind] = contract
    return gaps, na, 'confirmed-commercial-dependency-control'


def form_control_base(control, binding, row_id, junit):
    return supplemental_base(control, binding, row_id, junit) and snapshot_valid(control.get('before_snapshot')) and snapshot_valid(control.get('after_snapshot'))


def pause_idempotency_proof(control):
    proof = control.get('idempotency_assertions', {})
    if not isinstance(proof, dict): return False
    uuid = r'[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}'
    return (proof.get('appended_rows') == 1 and proof.get('existing_rows_unchanged') is True
            and proof.get('namespace') == 'purchase_request_transition' and proof.get('status') == 'completed'
            and isinstance(control.get('request_id'), int) and control['request_id'] > 0
            and proof.get('result_resource_id') == control['request_id']
            and proof.get('result_event_uuid') == proof.get('audit_event_uuid')
            and isinstance(proof.get('audit_event_uuid'), str) and bool(re.fullmatch(uuid, proof['audit_event_uuid']))
            and isinstance(proof.get('audit_correlation_id'), str) and bool(re.fullmatch(uuid, proof['audit_correlation_id']))
            and proof.get('audit_actor_user_id', 0) > 0 and isinstance(proof.get('audit_reason'), str) and bool(proof['audit_reason'].strip()))


def form_positive(row_id, control, binding, junit):
    if not form_control_base(control, binding, row_id, junit): return False
    if control.get('actor_valid') is not True or control.get('financial_unchanged') is not True: return False
    before, after = control['before_snapshot'], control['after_snapshot']
    if set(before) != set(after): return False
    if row_id == 'http-012':
        references = control.get('observed_response', {}).get('returned_references')
        return (control.get('type') == 'scoped-read' and control.get('http') == 200
                and control.get('exact_scoped_data') is True and before == after
                and isinstance(references, list) and len(references) == 1
                and bool(re.fullmatch(r'[1-9][0-9]{5}', str(references[0])))
                and str(control.get('nonce_contract', '')).startswith('N/A:'))
    if control.get('type') != 'mutation' or control.get('nonce_valid') is not True or control.get('persisted_assertions') is not True or before == after: return False
    if control.get('http') != 302: return False
    if row_id == 'http-016':
        return (control.get('actor') == 'admin' and control.get('verified_settings') is True
                and control.get('before_options') != control.get('after_options')
                and control.get('stored_templates') == {'request_rejected': 'Sandbox {request} message'}
                and str(control.get('after_options', {}).get('zbm_core_delivery_reminder_minutes')) == str(control.get('stored_minutes'))
                and all(before[key] == after[key] for key in before if not key.startswith('option:'))
                and any(isinstance(notice, dict) and notice.get('code') == 'settings_updated' and notice.get('type') == 'success' for notice in (control.get('notice') or [])))
    if row_id == 'http-013':
        allowed = ('zbm_purchase_requests', 'zbm_audit_logs', 'zbm_notification_outbox', 'zbm_idempotency_keys')
        return (control.get('actor') == 'admin' and control.get('is_paused') is True
                and control.get('before_version') == 0 and control.get('after_version') == 1
                and pause_idempotency_proof(control)
                and isinstance(control.get('notice'), dict) and control['notice'].get('type') == 'success'
                and all(before[key] == after[key] for key in before if not key.endswith(allowed))
                and all(any(key.endswith(suffix) and before[key] != after[key] for key in before) for suffix in ('zbm_purchase_requests', 'zbm_audit_logs', 'zbm_idempotency_keys')))
    if row_id == 'http-031':
        allowed = ('zbm_workflows', 'zbm_audit_logs', 'zbm_idempotency_keys')
        return (control.get('actor') == 'buyer_1' and control.get('result') == 'success'
                and control.get('before_state') == 'deposit_pending' and control.get('after_state') == 'cancellation_requested'
                and control.get('after_version', -1) > control.get('before_version', -1) >= 0
                and all(before[key] == after[key] for key in before if not key.endswith(allowed))
                and all(any(key.endswith(suffix) and before[key] != after[key] for key in before) for suffix in ('zbm_workflows', 'zbm_audit_logs')))
    return False


def settings_upstream_role_denial(scenario, control):
    proof = control.get('upstream_contract', {})
    response = control.get('observed_response', {})
    if not isinstance(proof, dict) or not isinstance(response, dict): return False
    expected_actor = {'wrong_role': 'buyer_1', 'other_buyer': 'buyer_2', 'other_vendor': 'vendor_2'}.get(scenario)
    expected_role = 'seller' if scenario == 'other_vendor' else 'customer'
    home, redirect = proof.get('home_url'), proof.get('redirect_url')
    if not isinstance(home, str) or not isinstance(redirect, str): return False
    parsed = urlsplit(home)
    return (control.get('type') == 'validation' and control.get('http') == 302
            and control.get('actor') == expected_actor and expected_actor is not None
            and control.get('nonce_valid') is True and control.get('persisted_assertions') is True
            and control.get('denial_layer') == 'dokan_admin_init'
            and control.get('message') == response.get('message') == 'dokan_block_admin_access_local_home_redirect'
            and control.get('before_options') == control.get('after_options')
            and isinstance(control.get('before_options'), dict)
            and set(control['before_options']) == {'zbm_core_delivery_reminder_minutes', 'zbm_core_sms_templates'}
            and proof.get('dependency_version') == '5.0.10' and proof.get('hook') == 'admin_init'
            and proof.get('callback') == 'WeDevs\\Dokan\\Core::block_admin_access' and proof.get('priority') == 10
            and all(proof.get(key) is True for key in ('registered', 'source_contract_verified', 'hpos_enabled', 'nonce_verified', 'redirect_is_local_home'))
            and isinstance(proof.get('source_path'), str) and proof['source_path'].endswith('/dokan-lite/includes/Core.php')
            and proof.get('configured_admin_access') in ('on', 'off') and proof.get('effective_admin_access') == 'on'
            and proof.get('actor_roles') == [expected_role] and proof.get('primary_role') == expected_role
            and proof.get('manage_options') is False and proof.get('request_page') == 'options.php' and proof.get('page_exempt') is False
            and parsed.scheme in ('http', 'https') and bool(parsed.netloc) and not parsed.query and not parsed.fragment
            and home == redirect and proof.get('home_path') == proof.get('redirect_path') == response.get('redirect_path') == parsed.path
            and response.get('redirect_is_local_home') is True)


def settings_direct_capability_denial(control):
    proof = control.get('capability_contract', {})
    response = control.get('observed_response', {})
    if not isinstance(proof, dict) or not isinstance(response, dict): return False
    message = 'Sorry, you are not allowed to manage options for this site.'
    return (control.get('type') == 'validation' and control.get('http') == 403 and control.get('actor') == 'admin'
            and control.get('actor_valid') is False and control.get('nonce_valid') is True and control.get('persisted_assertions') is True
            and control.get('denial_layer') == 'wordpress_options_capability'
            and control.get('message') == response.get('message') == message
            and control.get('before_options') == control.get('after_options')
            and isinstance(control.get('before_options'), dict)
            and set(control['before_options']) == {'zbm_core_delivery_reminder_minutes', 'zbm_core_sms_templates'}
            and proof.get('actor_roles') == ['administrator'] and proof.get('manage_options') is False
            and proof.get('manage_woocommerce') is True and proof.get('option_page_capability') == 'manage_options'
            and proof.get('nonce_verified') is True and proof.get('per_user_cap_override') is True
            and proof.get('capability_restored') is True)


def form_negative(row_id, scenario, control, binding, junit):
    if not form_control_base(control, binding, row_id, junit): return False
    if control.get('generic_dispatcher_only') is True: return False
    if control.get('before_snapshot') != control.get('after_snapshot') or control.get('financial_unchanged') is not True: return False
    if row_id == 'http-016' and scenario in ('wrong_role', 'other_buyer', 'other_vendor'):
        return settings_upstream_role_denial(scenario, control)
    if row_id == 'http-016' and scenario == 'capability_denied':
        return settings_direct_capability_denial(control)
    if row_id in ('http-012', 'http-016'):
        expected_status = 302 if scenario == 'guest' else (200 if row_id == 'http-012' and scenario in ('no_nonce', 'invalid_nonce') else 403)
    elif row_id == 'http-013':
        expected_status = 403 if scenario in ('no_nonce', 'invalid_nonce') else (400 if scenario == 'guest' else 500)
    else:
        expected_status = 403 if scenario in ('no_nonce', 'invalid_nonce') else (400 if scenario == 'guest' else 302)
    if control.get('http') != expected_status: return False
    if row_id == 'http-031' and scenario in ('wrong_role', 'other_buyer', 'other_vendor') and control.get('result') != 'forbidden': return False
    if row_id == 'http-012' and scenario in ('no_nonce', 'invalid_nonce'):
        return form_positive(row_id, control, binding, junit)
    observed = dict(control)
    response = control.get('observed_response', {})
    observed.update({key: response[key] for key in ('message', 'code', 'notice') if key in response})
    if response.get('result'):
        observed['reason'] = response['result']
    return negative_ok(observed)


def apply_form_overlay(row, gaps, supplemental, junit):
    binding = row['supplemental'][0]
    record = supplemental.get(binding['file'], {}).get(binding['key'], {})
    if not supplemental_base(record, binding, row['id'], junit): return gaps, {}, None
    controls = record.get('controls', {})
    positive = form_positive(row['id'], controls.get('authorized_positive', {}), binding, junit)
    if row['id'] == 'http-016':
        for scenario, minutes in (('zero_disabled', 0), ('minimum_five', 5), ('maximum_1440', 1440)):
            control = controls.get(scenario, {})
            positive = positive and form_positive(row['id'], control, binding, junit) and control.get('stored_minutes') == minutes
        positive = positive and controls.get('authorized_positive', {}).get('stored_minutes') == 120
    gaps = [gap for gap in gaps if not gap.startswith('positive-and-negative-controls:')]
    if not positive: gaps.append('positive:form-contract')
    na = {}
    for scenario in row.get('required_scenarios', NEGATIVES):
        control = controls.get(scenario, {})
        if not form_negative(row['id'], scenario, control, binding, junit): gaps.append('negative:form:' + scenario)
        elif scenario in ('no_nonce', 'invalid_nonce') and str(control.get('nonce_contract', '')).startswith('N/A:'):
            na['form:' + scenario] = {'nonce': control['nonce_contract']}
    # A passing pause/cancellation submission proves its actual branch only.
    expected_branches = row.get('branches', [])
    verified_operation = {'http-013': 'pause', 'http-031': 'buyer_cancellation_requested'}.get(row['id'])
    covered = [verified_operation] if positive and verified_operation in record.get('covered_operations', []) else []
    for branch in expected_branches:
        if branch not in covered: gaps.append('branch:' + branch)
    return gaps, na, 'actual-form-scoped-read' if row['id'] == 'http-012' else 'actual-form-durable-mutation'


def legacy_snapshot_valid(control):
    before, after = control.get('before_snapshot'), control.get('after_snapshot')
    required = ('zbm_dokan_credit_mirrors', 'zbm_managed_case_commands', 'posts', 'postmeta',
                'woocommerce_order_items', 'woocommerce_order_itemmeta')
    return (snapshot_valid(before) and snapshot_valid(after) and set(before) == set(after)
            and all(any(key.endswith('_' + suffix) for key in before) for suffix in required))


def legacy_recovery_positive(control, binding, junit):
    if not form_control_base(control, binding, 'http-015', junit) or not legacy_snapshot_valid(control): return False
    before, after = control['before_snapshot'], control['after_snapshot']
    allowed = ('zbm_purchase_requests', 'zbm_audit_logs', 'zbm_idempotency_keys', 'zbm_notification_outbox',
               'posts', 'postmeta', 'woocommerce_order_items', 'woocommerce_order_itemmeta',
               'wc_orders', 'wc_order_addresses', 'wc_order_operational_data', 'wc_orders_meta')
    if any(before[key] != after[key] for key in before if not key.endswith(tuple('_' + suffix for suffix in allowed))): return False
    if not all(any(key.endswith('_' + suffix) and before[key] != after[key] for key in before)
               for suffix in ('zbm_purchase_requests', 'zbm_audit_logs', 'postmeta', 'woocommerce_order_items', 'woocommerce_order_itemmeta')): return False
    order = control.get('order_contract', {})
    stock = control.get('stock_contract', {})
    audit = control.get('audit_contract', {})
    return (control.get('type') == 'mutation' and control.get('http') == 302
            and control.get('actor') == 'admin'
            and all(control.get(key) is True for key in ('actor_valid', 'nonce_valid', 'key_valid', 'persisted_assertions', 'financial_unchanged'))
            and control.get('before_state') == 'supplier_accepted' and control.get('after_state') == 'deposit_paid'
            and control.get('before_version') == 0 and control.get('after_version') == 1
            and control.get('before_execution_mode') == 'test_pending' and control.get('execution_mode') == 'test'
            and control.get('submitted_version') == 0 and control.get('reason_present') is True
            and control.get('payment_ready') is False and control.get('payment_count') == control.get('ledger_count') == 0
            and isinstance(control.get('notice'), dict) and control['notice'].get('type') == 'success' and bool(control['notice'].get('message'))
            and isinstance(order.get('order_id'), int) and order['order_id'] > 0
            and order.get('snapshot_matches') is True and order.get('route') == order.get('execution_mode') == 'test'
            and order.get('payment_enabled') == 'no' and order.get('status') == 'pending'
            and order.get('is_paid') is False and 'paid_at' in order and order['paid_at'] is None
            and order.get('transaction_id') == '' and order.get('line_item_count') == 1
            and order.get('other_orders_unchanged') is True and order.get('other_requests_unchanged') is True
            and stock.get('before') == 100 and stock.get('after') == 99 and stock.get('reduced_stock') == 1
            and stock.get('request_reserved') is True and stock.get('request_released') is False
            and audit.get('event') == 'admin_test_deposit_advanced' and audit.get('count') == 1
            and audit.get('actor_type') == 'admin' and audit.get('actor_user_id', 0) > 0 and audit.get('reason_matches') is True)


def legacy_identity_safety(control, binding, junit):
    if not form_control_base(control, binding, 'http-014', junit) or not legacy_snapshot_valid(control): return False
    identity = control.get('identity_contract', {})
    notice = control.get('notice', {})
    if not isinstance(identity, dict) or not isinstance(notice, dict): return False
    return (control.get('type') == 'validation' and control.get('http') == 302
            and control.get('actor') == 'admin'
            and all(control.get(key) is True for key in ('actor_valid', 'nonce_valid', 'stored_binding_valid', 'persisted_assertions', 'financial_unchanged', 'identity_guard_before_verifier'))
            and control.get('before_snapshot') == control.get('after_snapshot')
            and control.get('before_state') == control.get('after_state') == 'late_payment_review'
            and control.get('before_version') == control.get('after_version') == 0
            and notice.get('type') == 'error' and bool(notice.get('message'))
            and identity.get('snapshot_matches') is True and identity.get('authority_matches') is True
            and identity.get('route') == 'online' and identity.get('execution_mode') == 'live'
            and identity.get('payment_status') == 'callback_received_late'
            and all(key in identity and identity[key] is None for key in ('paid_amount', 'reference', 'paid_at'))
            and control.get('provider_verification_verified') is False and control.get('dependency_control_claimed') is False)


def legacy_recovery_negative(scenario, control, binding, junit):
    if not form_control_base(control, binding, 'http-015', junit) or not legacy_snapshot_valid(control): return False
    actors = {'guest': 'guest', 'wrong_role': 'buyer_1', 'other_buyer': 'buyer_2', 'other_vendor': 'vendor_2'}
    if control.get('actor') != actors.get(scenario, 'admin'): return False
    if control.get('generic_dispatcher_only') is True: return False
    if control.get('submitted_version') != (9 if scenario == 'stale_version' else 0): return False
    if control.get('reason_present') is not (scenario != 'missing_reason'): return False
    if scenario != 'guest' and control.get('nonce_valid') is not (scenario not in ('no_nonce', 'invalid_nonce')): return False
    status = 400 if scenario == 'guest' else (500 if scenario in actors else (403 if scenario in ('no_nonce', 'invalid_nonce') else 302))
    if control.get('http') != status or control.get('financial_unchanged') is not True: return False
    if control['before_snapshot'] != control['after_snapshot']: return False
    if control.get('after_state') != 'supplier_accepted' or control.get('after_version') != 0 or control.get('execution_mode') != 'test_pending': return False
    notice = control.get('notice')
    if scenario in ('missing_reason', 'stale_version') and (not isinstance(notice, dict) or notice.get('type') != 'error'): return False
    return negative_ok(control)


def apply_legacy_form_overlay(row, gaps, supplemental, junit):
    binding = row['supplemental'][0]
    record = supplemental.get(binding['file'], {}).get(binding['key'], {})
    if not supplemental_base(record, binding, row['id'], junit): return gaps, {}, None
    controls = record.get('controls', {})
    gaps = [gap for gap in gaps if not gap.startswith('positive-and-negative-controls:')]
    if row['id'] == 'http-014':
        # The valid issued identity is denied before provider verification. It
        # proves preservation only, never cancellation or a dependency exemption.
        safety = legacy_identity_safety(controls.get('authorized_identity_safety_control', {}), binding, junit)
        gaps.append('positive:form-contract')
        gaps.extend('negative:form:' + scenario for scenario in NEGATIVES)
        gaps.append('branch:cancel_review')
        if not safety: gaps.append('safety:identity-guard')
        return gaps, {}, 'actual-form-identity-safety-denial' if safety else None
    positive = legacy_recovery_positive(controls.get('authorized_positive', {}), binding, junit)
    if not positive: gaps.append('positive:form-contract')
    for scenario in row['required_scenarios']:
        if not legacy_recovery_negative(scenario, controls.get(scenario, {}), binding, junit):
            gaps.append('negative:form:' + scenario)
    if not positive or 'test_advance' not in record.get('covered_operations', []): gaps.append('branch:test_advance')
    return gaps, {}, 'actual-form-quarantined-test-recovery' if positive else None


def apply_supplemental_overlay(row, gaps, supplemental, junit):
    if row.get('supplemental') and row['supplemental'][0].get('format') == 'legacy-form':
        return apply_legacy_form_overlay(row, gaps, supplemental, junit)
    if row.get('supplemental') and row['supplemental'][0].get('format') == 'form':
        return apply_form_overlay(row, gaps, supplemental, junit)
    return apply_payment_overlay(row, gaps, supplemental, junit)


def evaluate(catalog, registration, remaining, additional, managed, junit, supplemental=None):
    supplemental = supplemental or {}
    output = []
    for row in catalog['rows']:
        gaps = []
        not_applicable = {}
        runtime = registration.get('rows', {}).get(row['id'], {})
        if runtime.get('registered') is not True: gaps.append('registration')
        if runtime.get('actual_http') is not True or runtime.get('runtime') != 'authenticated-http': gaps.append('registration-runtime')
        if not junit_pass(junit, 'RegisteredHttpSurfacesTest::test_production_surface_callback_is_actually_registered', row['id']): gaps.append('registration-junit')
        contract = row['coverage']
        if contract['type'] == 'form-contract':
            gaps.append('positive-and-negative-controls:' + contract['key'])
        elif contract['type'] == 'managed':
            for key in contract['keys']:
                result = managed.get('results', {}).get(key, {})
                if not junit_pass(junit, contract['test'], key): gaps.append('junit:' + key)
                if not managed_positive(result): gaps.append('positive:' + key)
                for scenario in NEGATIVES:
                    if not negative_ok(result.get('negative', {}).get(scenario)): gaps.append('negative:' + key + ':' + scenario)
        else:
            if not junit_pass(junit, contract['test']): gaps.append('junit')
            if contract['type'] == 'remaining':
                for endpoint in contract['keys']:
                    controls = remaining.get(endpoint, {})
                    gaps.extend(endpoint + ':' + gap for gap in check_remaining(endpoint, controls))
                    if junit_pass(junit, contract['test']):
                        not_applicable.update(not_applicable_contracts(endpoint, controls))
            elif contract['type'] == 'additional': gaps.extend(additional_controls(contract, additional))
            else: gaps.append('unknown-contract')
        gaps, supplemental_na, positive_kind = apply_supplemental_overlay(row, gaps, supplemental, junit)
        not_applicable.update(supplemental_na)
        positive_gaps = [gap for gap in gaps if gap.startswith(('positive:', 'positive-and-negative-controls:', 'junit')) or ':positive-control' in gap or gap == 'scenario-incomplete']
        negative_gaps = [gap for gap in gaps if gap.startswith(('negative:', 'positive-and-negative-controls:', 'junit')) or ':negative:' in gap or gap == 'scenario-incomplete']
        output.append({'id': row['id'], 'kind': row['kind'], 'registered': runtime.get('registered') is True,
                       'registration_control_complete': not any(gap.startswith('registration') for gap in gaps),
                       'positive_control_complete': not positive_gaps,
                       'supplemental_positive_kind': None if positive_kind == 'actual-form-identity-safety-denial' else positive_kind,
                       'supplemental_safety_control_complete': positive_kind == 'actual-form-identity-safety-denial',
                       'negative_controls_complete': not negative_gaps,
                       'branch_coverage_complete': not any(gap.startswith('branch:') for gap in gaps),
                       'dependency_blocked_controls': [gap for gap in gaps if ':blocked-by-dependency:' in gap],
                       'not_applicable_controls': not_applicable,
                       'positive_and_negative_complete': not gaps, 'gaps': gaps})
    return output


def load_supplemental(path, started, environment=None):
    environment = os.environ if environment is None else environment
    if path.stat().st_mtime_ns < started:
        return {}, 'stale-supplemental-evidence:' + path.name
    data = json.loads(path.read_text())
    meta = data.get('_meta', {})
    if meta.get('schema_version') != 1 or any(str(meta.get(key)) != environment.get(env, 'local') for key, env in (('run_id', 'GITHUB_RUN_ID'), ('run_attempt', 'GITHUB_RUN_ATTEMPT'))):
        return {}, 'supplemental-run-mismatch:' + path.name
    return data, None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=ROOT)
    parser.add_argument('--run-start', type=Path, required=True)
    parser.add_argument('--junit', type=Path, default=Path('evidence/candidate-junit.xml'))
    args = parser.parse_args()
    root = args.root.resolve()
    evidence = root / 'evidence'
    evidence.mkdir(exist_ok=True)
    report = {'schema_version': 1, 'errors': [], 'rows': []}
    try:
        catalog = json.loads((root / 'ci/http-surfaces.json').read_text())
        actual = discover(root)
        by_id = {row['id']: row for row in catalog['rows']}
        expected = [by_id[key]['declaration'] for key in catalog['discovery_order']]
        if actual != expected: report['errors'].append('source-declarations-changed')
        if len(catalog['rows']) != 86: report['errors'].append('catalog-count')
        marker = args.run_start if args.run_start.is_absolute() else root / args.run_start
        started = marker.stat().st_mtime_ns
        junit_path = args.junit if args.junit.is_absolute() else root / args.junit
        paths = [junit_path] + [evidence / name for name in ('http-surface-registration.json', 'task4-remaining-http.json', 'task4-additional-http.json', 'task4-managed-http.json')]
        if any(path.stat().st_mtime_ns < started for path in paths): report['errors'].append('stale-run-evidence')
        if marker.stat().st_size != 0: report['errors'].append('invalid-run-marker')
        if not junit_pass(junit_results(junit_path), 'ManagedHttpMatrixTest::test_declared_operation_inventory_is_exactly_the_35_reviewed_operations'):
            report['errors'].append('managed-operation-inventory-not-proved')
        loaded = [json.loads(path.read_text()) for path in paths[1:]]
        supplemental = {}
        for name in sorted({binding['file'] for row in catalog['rows'] for binding in row.get('supplemental', [])}):
            path = evidence / name
            data, error = load_supplemental(path, started)
            if error:
                report['errors'].append(error)
                continue
            supplemental[name] = data
        report['rows'] = evaluate(catalog, *loaded, junit_results(junit_path), supplemental)
    except (OSError, ValueError, KeyError, TypeError, AttributeError, IndexError, ET.ParseError) as error:
        report['errors'].append(type(error).__name__ + ':' + str(error))
    failed = sum(not row['positive_and_negative_complete'] for row in report['rows'])
    report['summary'] = {'rows': len(report['rows']), 'failed': failed, 'errors': len(report['errors'])}
    (evidence / 'http-matrix-coverage.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report['summary'], sort_keys=True))
    return int(bool(failed or report['errors'] or len(report['rows']) != 86))

if __name__ == '__main__': sys.exit(main())

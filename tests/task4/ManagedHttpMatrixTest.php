<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/Task4ManagedFixture.php';

use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Application\ManualPaymentSettings;
use Zarbalman\Core\Frontend\ManagedCasePanel;
use Zarbalman\Core\Http\ManagedCaseActionController;
use Zarbalman\Core\Infrastructure\{Schema, ProtectedText, DeliveryDataCrypto, FinancialActionRepository};

/** Every declared operation is exercised through each registered real WordPress HTTP transport. */
final class ManagedHttpMatrixTest extends TestCase {
    private static array $evidence = [];
    private const NONCE_MESSAGE = 'اعتبار فرم پایان یافته است؛ صفحه را تازه و دوباره تلاش کنید.';
    private const FORBIDDEN_AJAX = 'درخواست معتبر یا مجاز نیست؛ صفحه را تازه کنید.';
    private const FORBIDDEN_POST = 'درخواست معتبر یا مجاز نیست.';

    public static function operations(): array {
        return [
            'flow_record_weight' => 'vendor_1', 'flow_ready_delivery' => 'vendor_1', 'flow_ready_pickup' => 'vendor_1',
            'flow_dispatch' => 'vendor_1', 'flow_deliver' => 'vendor_1', 'flow_propose_weight' => 'buyer_1',
            'flow_pause' => 'admin', 'flow_resume' => 'admin', 'flow_extend' => 'admin', 'flow_extend_delivery' => 'admin',
            'flow_reopen_preparation' => 'admin', 'flow_reopen_buyer' => 'admin', 'flow_reopen_seller' => 'admin',
            'flow_set_final_weight' => 'admin', 'flow_reissue_code' => 'admin', 'flow_override_delivery' => 'admin',
            'flow_note' => 'admin', 'flow_refer_return' => 'admin', 'flow_cancel' => 'admin',
            'flow_accept_weight' => 'vendor_1', 'flow_object' => 'buyer_1',
            'manual_offer' => 'buyer_1', 'manual_receipt' => 'buyer_1', 'manual_approve' => 'admin',
            'manual_reject' => 'admin', 'manual_credit_seller' => 'admin',
            'spec4_contact' => 'admin', 'spec4_confirm_return' => 'admin', 'spec4_create_refund' => 'admin',
            'spec4_approve_action' => 'admin', 'spec4_execute_action' => 'admin', 'spec4_fail_action' => 'admin',
            'spec4_reconcile_action' => 'admin', 'spec4_accept_late' => 'admin', 'spec4_reverify' => 'admin',
        ];
    }
    public static function matrix(): array {
        $rows = [];
        foreach (self::operations() as $operation => $owner) {
            foreach (['admin-post.php', 'admin-ajax.php'] as $transport) { $rows[$operation . '/' . $transport] = [$operation, $owner, $transport]; }
        }
        return $rows;
    }
    public function test_declared_operation_inventory_is_exactly_the_35_reviewed_operations(): void {
        $constants = (new ReflectionClass(ManagedCaseActionController::class))->getConstants();
        $actual = array_merge(array_keys($constants['VENDOR_FLOW']), array_keys($constants['BUYER_FLOW']), array_keys($constants['ADMIN_FLOW']), $constants['FINANCE_ACTIONS'], ['flow_accept_weight', 'flow_object', 'manual_offer', 'manual_receipt', 'manual_approve', 'manual_reject', 'manual_credit_seller']);
        $expected = array_keys(self::operations()); sort($actual); sort($expected);
        self::assertCount(35, $actual); self::assertSame($expected, $actual, 'A new or removed operation requires an explicit HTTP fixture and expectation.');
        self::assertCount(70, self::matrix());
    }

    /** @dataProvider matrix */
    public function test_operation_positive_or_explicit_dependency_control_and_six_rejections(string $operation, string $owner, string $transport): void {
        global $wpdb;
        $key = $operation . '/' . $transport;
        self::$evidence[$key] = ['operation' => $operation, 'transport' => $transport, 'state' => 'started', 'negative' => []];
        $settingsOption = 'zbm_core_real_manual_payment';
        $previousSettings = get_option($settingsOption, null);
        $previousUnit = get_option('woocommerce_weight_unit', null);
        try {
            update_option('woocommerce_weight_unit', 'kg');
            $users = Task4ManagedFixture::users();
            $repos = CandidateFixture::repositories();
            $protected = new ProtectedText(new DeliveryDataCrypto());
            self::assertTrue($protected->ensureKey());
            $settings = new ManualPaymentSettings($protected, $repos['audit']);
            self::assertTrue($settings->save($users['admin'], ['enabled' => true, 'card_number' => '4242424242424242', 'holder_name' => 'Synthetic test holder'], 'Synthetic isolated HTTP fixture settings'));
            self::assertTrue($settings->enabled());
            // No real commercial transport can be loaded in this disposable environment.
            self::assertFalse(class_exists('WC_ZPal'), 'The sandbox must not load a commercial gateway SDK.');
            if (function_exists('PWSMS')) {
                self::assertSame(realpath(__DIR__ . '/../support/RecordingSms.php'), realpath((string) (new ReflectionFunction('PWSMS'))->getFileName()), 'Only the existing test recording SMS transport may be loaded in the PHPUnit process.');
            }
            $positive = new Task4ManagedFixture($operation);
            $positive->validate();
            $fields = $positive->fields($operation);
            [$cookie, $nonce] = CandidateHttp::auth($users[$owner], 'zbm_managed_case_' . $positive->id);
            $fields['_zbm_nonce'] = $nonce;
            delete_transient(ManagedCasePanel::NOTICE_KEY . $users[$owner]);
            $beforeCase = $positive->case();
            $beforeSnapshot = Task4ManagedFixture::snapshot();
            $beforeAudit = $this->auditCount($positive->id);
            $http = CandidateHttp::post('wp-admin/' . $transport, $fields, $cookie);
            if ($positive->blockedCode !== null) {
                $observed = $this->assertDomainRejection($http, $transport, $users[$owner], $owner === 'admin', $positive->blockedCode, (string) $positive->blockedMessage);
                self::assertSame($beforeSnapshot, Task4ManagedFixture::snapshot(), 'Fail-closed dependency/retired control changed persisted facts.');
                self::$evidence[$key]['positive'] = ['result' => $operation === 'flow_override_delivery' ? 'retired-fail-closed' : ($operation === 'manual_offer' ? 'untestable-commercial-dependency' : 'untestable-native-dependency'), 'reason' => $positive->limitation, 'observed' => $observed];
            } else {
                $this->assertAccepted($http, $transport, $users[$owner]);
                $afterCase = $positive->case();
                self::assertGreaterThan($beforeCase['version'], $afterCase['version'], 'A successful HTTP response must durably advance the aggregate.');
                self::assertGreaterThan($beforeAudit, $this->auditCount($positive->id), 'A successful action must be durably audited.');
                self::assertNotSame($beforeSnapshot, Task4ManagedFixture::snapshot());
                $positive->validate();
                $effects = $this->assertEffects($positive, $operation, $beforeCase, $fields);
                self::$evidence[$key]['positive'] = ['result' => $operation === 'spec4_reverify' ? 'accepted-durable-review-provider-unverified' : 'accepted-durable-effects', 'http' => $http['status'], 'request_id' => $positive->id, 'before_version' => $beforeCase['version'], 'after_version' => $afterCase['version'], 'effects' => $effects, 'limitation' => $positive->limitation];
            }
            // Separate, independently proved pre-action state keeps object/accept role derivation eligible.
            $negative = new Task4ManagedFixture($operation);
            $negative->validate();
            self::$evidence[$key]['negative_fixture'] = ['request_id' => $negative->id, 'state' => $negative->case()['state'], 'version' => $negative->case()['version'], 'aggregate_validated' => true, 'captured_deposit_and_cash_proved' => true];
            foreach (['no_nonce', 'invalid_nonce', 'wrong_role', 'other_buyer', 'other_vendor', 'guest'] as $scenario) {
                $actor = match ($scenario) {
                    'wrong_role' => $owner === 'buyer_1' ? 'vendor_1' : 'buyer_1',
                    'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', default => $owner,
                };
                $fields = $negative->fields($operation);
                if ($scenario === 'guest') { $cookie = ''; $nonce = ''; }
                else { [$cookie, $nonce] = CandidateHttp::auth($users[$actor], 'zbm_managed_case_' . $negative->id); }
                if ($scenario !== 'no_nonce') { $fields['_zbm_nonce'] = $scenario === 'invalid_nonce' ? 'invalid-task4-nonce' : $nonce; }
                delete_transient(ManagedCasePanel::NOTICE_KEY . $users[$actor]);
                $before = Task4ManagedFixture::snapshot();
                $http = CandidateHttp::post('wp-admin/' . $transport, $fields, $cookie);
                if (in_array($scenario, ['no_nonce', 'invalid_nonce'], true)) {
                    $observed = $this->assertDomainRejection($http, $transport, $users[$actor], $owner === 'admin', 'zbm_managed_nonce', self::NONCE_MESSAGE);
                } elseif ($scenario === 'guest') {
                    self::assertSame(400, $http['status'], 'WordPress rejects the unregistered nopriv action before controller dispatch.');
                    if ($transport === 'admin-ajax.php') { self::assertSame('0', trim($http['body'])); }
                    else { self::assertStringContainsString('wp-die-message', $http['body']); }
                    $observed = ['http' => 400, 'reason' => 'wordpress-unregistered-nopriv-action', 'body' => $transport === 'admin-ajax.php' ? '0' : 'wp_die'];
                } else {
                    self::assertSame(403, $http['status'], $key . '/' . $scenario . ': exact ownership/capability rejection');
                    if ($transport === 'admin-ajax.php') {
                        $json = json_decode($http['body'], true, 32, JSON_THROW_ON_ERROR);
                        self::assertFalse($json['success']); self::assertSame(['message' => self::FORBIDDEN_AJAX], $json['data']);
                    } else { self::assertStringContainsString(self::FORBIDDEN_POST, html_entity_decode(strip_tags($http['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8')); }
                    $observed = ['http' => 403, 'reason' => 'controller-derived-role-forbidden', 'message' => $transport === 'admin-ajax.php' ? self::FORBIDDEN_AJAX : self::FORBIDDEN_POST];
                }
                self::assertSame($before, Task4ManagedFixture::snapshot(), $key . '/' . $scenario . ': rejection changed persisted business/evidence/audit facts.');
                self::$evidence[$key]['negative'][$scenario] = ['result' => 'rejected-exact-contract-no-persisted-effect', 'observed' => $observed];
                $this->writeEvidence();
            }
            self::assertCount(6, self::$evidence[$key]['negative']);
            self::$evidence[$key]['state'] = 'passed';
        } catch (Throwable $error) {
            self::$evidence[$key]['state'] = 'failed';
            self::$evidence[$key]['failure'] = ['class' => get_class($error), 'message' => $error->getMessage()];
            throw $error;
        } finally {
            if ($previousSettings === null) { delete_option($settingsOption); } else { update_option($settingsOption, $previousSettings); }
            if ($previousUnit === null) { delete_option('woocommerce_weight_unit'); } else { update_option('woocommerce_weight_unit', $previousUnit); }
            wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]);
            $this->writeEvidence();
        }
    }

    private function assertAccepted(array $http, string $transport, int $actor): void {
        if ($transport === 'admin-ajax.php') {
            self::assertSame(200, $http['status'], $http['body']);
            $json = json_decode($http['body'], true, 32, JSON_THROW_ON_ERROR);
            self::assertTrue($json['success']); self::assertSame('success', $json['data']['notice_type']);
            self::assertIsString($json['data']['view_url']);
        } else {
            self::assertSame(303, $http['status'], $http['headers'] . $http['body']); self::assertStringContainsString('Location:', $http['headers']);
            $notice = Task4ManagedFixture::notice($actor); self::assertIsArray($notice); self::assertSame('success', $notice['type']); self::assertNotSame('', $notice['message']);
        }
    }
    private function assertDomainRejection(array $http, string $transport, int $actor, bool $admin, string $code, string $message): array {
        if ($transport === 'admin-ajax.php') {
            self::assertSame(409, $http['status'], $http['body']);
            $json = json_decode($http['body'], true, 32, JSON_THROW_ON_ERROR);
            self::assertFalse($json['success']);
            self::assertSame(['message' => $message, 'code' => $admin ? $code : ''], $json['data']);
            return ['http' => 409, 'code' => $admin ? $code : '', 'message' => $message];
        }
        self::assertSame(303, $http['status'], $http['body']); self::assertStringContainsString('Location:', $http['headers']);
        $notice = Task4ManagedFixture::notice($actor); self::assertSame(['type' => 'error', 'message' => $message], $notice);
        return ['http' => 303, 'notice' => $notice];
    }
    private function auditCount(int $request): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE resource_id=%d', (new Schema($wpdb))->audit_table(), $request));
    }

    private function assertEffects(Task4ManagedFixture $fixture, string $operation, array $before, array $fields): array {
        global $wpdb;
        $case = $fixture->case(); $data = $case['data']; $line = reset($data['lines']);
        $schema = new Schema($wpdb);
        switch ($operation) {
            case 'flow_record_weight': self::assertSame(10, $line['vendor_tenths']); self::assertCount(1, $line['vendor_evidence']); break;
            case 'flow_ready_delivery': self::assertSame('ready_for_delivery', $case['state']); break;
            case 'flow_ready_pickup': self::assertSame('ready_for_pickup', $case['state']); self::assertSame(1, $data['delivery']['generation']); self::assertNotNull($data['delivery']['hmac']); break;
            case 'flow_dispatch': self::assertSame('in_transit', $case['state']); self::assertNotNull($data['operations']['dispatched_at']); self::assertNotNull($data['delivery']['hmac']); break;
            case 'flow_deliver': self::assertSame('buyer_review', $case['state']); self::assertNotNull($data['delivery']['delivered_at']); self::assertNull($data['delivery']['hmac']); self::assertNull($data['delivery']['encrypted_code']); break;
            case 'flow_propose_weight': self::assertSame('seller_review', $case['state']); self::assertSame(10, $line['buyer_tenths']); self::assertCount(1, $line['buyer_evidence']); break;
            case 'flow_object': self::assertSame('admin_review', $case['state']); self::assertCount(1, $line['buyer_objection']); self::assertSame($fields['reason'], $line['buyer_reason']); break;
            case 'flow_pause': self::assertTrue($data['pause']['active']); self::assertTrue($fixture->request()->isPaused()); break;
            case 'flow_resume': self::assertFalse($data['pause']['active']); self::assertFalse($fixture->request()->isPaused()); break;
            case 'flow_extend': self::assertSame(gmdate('Y-m-d H:i:s', strtotime($before['deadline_gmt'] . ' UTC') + 300), $case['deadline_gmt']); break;
            case 'flow_extend_delivery': self::assertSame($before['data']['operations']['window']['generation'] + 1, $data['operations']['window']['generation']); self::assertCount(1, $data['operations']['window']['history']); break;
            case 'flow_reopen_preparation': self::assertSame('preparing', $case['state']); self::assertNull($line['vendor_tenths']); self::assertSame([], $line['vendor_evidence']); self::assertCount(1, $data['operations']['evidence_history']); break;
            case 'flow_reopen_buyer': self::assertSame('buyer_review', $case['state']); self::assertNotNull($case['deadline_gmt']); break;
            case 'flow_reopen_seller': self::assertSame('seller_review', $case['state']); self::assertSame(10, $line['buyer_tenths']); self::assertNotNull($case['deadline_gmt']); break;
            case 'flow_accept_weight': case 'flow_set_final_weight':
                self::assertSame(10, $line['final_tenths']); self::assertSame(1000, $data['final']['total']); self::assertSame(100, $data['final']['commission']); self::assertSame(900, $data['final']['net']); self::assertNotNull($data['final']['locked_at']); self::assertSame(0, $data['funds']['balance']['amount']); self::assertNotSame('credited', $data['final']['settlement']); break;
            case 'flow_reissue_code': self::assertSame($before['data']['delivery']['generation'] + 1, $data['delivery']['generation']); self::assertNotSame($before['data']['delivery']['hmac'], $data['delivery']['hmac']); break;
            case 'flow_note': self::assertCount(1, $data['notes']['private']); self::assertSame($fields['reason'], $data['notes']['private'][0]['text']); self::assertSame($fixture->users['admin'], $data['notes']['private'][0]['actor_id']); break;
            case 'flow_refer_return': self::assertSame('return_review', $case['state']); break;
            case 'flow_cancel': self::assertSame('cancelled', $case['state']); self::assertNotNull($case['closed_at_gmt']); break;
            case 'manual_receipt': self::assertSame('submitted', $data['manual']['balance']['status']); self::assertCount(1, $data['manual']['balance']['receipt_ids']); self::assertSame(0, $data['funds']['balance']['amount']); break;
            case 'manual_reject': self::assertSame('rejected', $data['manual']['balance']['status']); self::assertSame($fixture->users['admin'], $data['manual']['balance']['reviewed_by']); self::assertSame($fields['reason'], $data['manual']['balance']['reason']); self::assertSame(0, $data['funds']['balance']['amount']); break;
            case 'manual_approve':
                self::assertSame('completed', $case['state']); self::assertSame('approved', $data['manual']['balance']['status']); self::assertSame(900, $data['funds']['balance']['amount']); self::assertSame('manual_bank', $data['funds']['balance']['provider']);
                $this->assertCash($fixture, $data['funds']['balance']['payment_id'], 900); break;
            case 'manual_credit_seller':
                self::assertSame('credited', $data['final']['settlement']); self::assertNotNull($data['final']['allocation_hash']);
                $entries = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE request_id=%d AND entry_type=%s', $schema->ledger_table(), $fixture->id, 'vendor_final_credit'), ARRAY_A);
                self::assertCount(1, $entries); self::assertSame(900, (int) $entries[0]['amount_toman']); self::assertSame($fixture->users['vendor_1'], (int) $entries[0]['vendor_id']); self::assertSame('allocated', $entries[0]['status']);
                $credit = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_id=%d', $schema->dokan_credit_mirrors_table(), $fixture->orderId), ARRAY_A);
                self::assertIsArray($credit); self::assertSame('credited', $credit['status']); self::assertSame(900, (int) $credit['amount_toman']);
                $native = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $wpdb->prefix . 'dokan_vendor_balance', (int) $credit['dokan_balance_id']), ARRAY_A);
                self::assertIsArray($native); self::assertSame($fixture->users['vendor_1'], (int) $native['vendor_id']); self::assertSame($fixture->orderId, (int) $native['trn_id']); self::assertSame('approved', $native['status']); break;
            case 'spec4_contact': self::assertSame('warning', $data['spec4']['substate']); self::assertCount(1, $data['spec4']['contact_history']); self::assertNotNull($data['spec4']['warning_deadline_gmt']); break;
            case 'spec4_confirm_return': self::assertSame('return_confirmed', $data['spec4']['substate']); self::assertSame('nonpayment_return', $data['spec4']['trigger']); self::assertCount(1, $data['spec4']['return_evidence_ids']); self::assertSame('return_review', $case['state']); break;
            case 'spec4_create_refund':
                self::assertSame('refund_pending', $data['spec4']['substate']); self::assertSame('seller_incapacity', $data['spec4']['trigger']); self::assertCount(2, $data['spec4']['finance_action_ids']);
                $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE request_id=%d ORDER BY id', $schema->financial_actions_table(), $fixture->id), ARRAY_A);
                self::assertCount(2, $rows); self::assertSame(10, (int) $rows[0]['site_amount_toman']); self::assertSame(90, (int) $rows[1]['buyer_refund_amount_toman']); self::assertNotEmpty($rows[1]['destination_ciphertext']); break;
            case 'spec4_approve_action': case 'spec4_execute_action': case 'spec4_fail_action': case 'spec4_reconcile_action':
                $action = (new FinancialActionRepository($wpdb, $fixture->repos['cases']))->get((int) $fields['financial_action_id'], $fixture->id);
                self::assertIsArray($action);
                $expected = ['spec4_approve_action' => 'approved', 'spec4_execute_action' => 'executed', 'spec4_fail_action' => 'failed', 'spec4_reconcile_action' => 'reconciled'][$operation];
                self::assertSame($expected, $action['status']); self::assertSame(90, (int) $action['buyer_refund_amount_toman']);
                if ($operation === 'spec4_execute_action') {
                    self::assertSame($fields['reference'], $action['external_reference']); self::assertGreaterThan(0, (int) $action['ledger_entry_id']);
                    $ledger = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $schema->ledger_table(), (int) $action['ledger_entry_id']), ARRAY_A);
                    self::assertSame('buyer_refund_executed', $ledger['entry_type']); self::assertSame(90, (int) $ledger['amount_toman']); self::assertSame($fixture->id, (int) $ledger['request_id']);
                }
                break;
            case 'spec4_accept_late':
                self::assertSame('completed', $case['state']); self::assertSame(900, $data['funds']['balance']['amount']);
                $payment = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $schema->payments_table(), $data['funds']['balance']['payment_id']), ARRAY_A);
                self::assertSame('late_accepted', $payment['status']); $this->assertCash($fixture, $data['funds']['balance']['payment_id'], 900); break;
            case 'spec4_reverify':
                self::assertSame('admin_review', $case['state']); self::assertSame('payment_review', $data['spec4']['substate']); self::assertSame('review', $data['spec4']['reconciliation_status']); self::assertSame(0, $data['funds']['balance']['amount']);
                $payment = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $schema->payments_table(), $data['spec4']['payment_id']), ARRAY_A);
                self::assertSame('verification_pending', $payment['status']); self::assertNull($payment['paid_amount_toman']);
                self::assertSame('reverify_payment', $data['spec4']['decision_history'][0]['action']); break;
            default: self::fail('Missing durable effects assertion for ' . $operation);
        }
        if (str_starts_with($operation, 'spec4_')) {
            $last = end($data['spec4']['decision_history']); self::assertIsArray($last);
            self::assertSame($fixture->users['admin'], $last['actor_id']);
            self::assertSame($operation === 'spec4_reverify' ? 'reverify_payment' : substr($operation, 6), $last['action']);
        }
        return ['state' => $case['state'], 'final_total' => $data['final']['total'], 'deposit_toman' => $data['funds']['deposit']['amount'], 'balance_toman' => $data['funds']['balance']['amount'], 'settlement' => $data['final']['settlement'], 'spec4_substate' => $data['spec4']['substate'] ?? null, 'durable_action_specific_assertions' => true];
    }
    private function assertCash(Task4ManagedFixture $fixture, int $payment, int $amount): void {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE request_id=%d AND payment_id=%d AND entry_type=%s', (new Schema($wpdb))->ledger_table(), $fixture->id, $payment, 'employer_cash_received'), ARRAY_A);
        self::assertCount(1, $rows); self::assertSame($fixture->orderId, (int) $rows[0]['order_id']); self::assertSame($amount, (int) $rows[0]['amount_toman']); self::assertSame('recorded', $rows[0]['status']);
    }
    private function writeEvidence(): void {
        $path = __DIR__ . '/../../evidence/task4-managed-http.json';
        $json = wp_json_encode(['scope' => 'ManagedCaseActionController', 'expected_operations' => 35, 'expected_transport_rows' => 70, 'negative_scenarios_per_row' => 6, 'results' => self::$evidence], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!is_dir(dirname($path))) { self::assertTrue(mkdir(dirname($path), 0755, true)); }
        self::assertSame(strlen($json), file_put_contents($path, $json, LOCK_EX));
    }
}

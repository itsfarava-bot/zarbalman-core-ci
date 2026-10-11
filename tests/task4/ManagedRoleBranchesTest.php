<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/Task4ManagedFixture.php';

use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Frontend\ManagedCasePanel;
use Zarbalman\Core\Infrastructure\{ManagedEvidence, Schema};
use Zarbalman\Core\Security\AdminCapabilities;

/** Additive coverage of the second objection owner and independent finance/operations capabilities. */
final class ManagedRoleBranchesTest extends TestCase {
    private static array $evidence = [];
    private mixed $previousUnit;
    private const NONCE_MESSAGE = 'اعتبار فرم پایان یافته است؛ صفحه را تازه و دوباره تلاش کنید.';
    private const FORBIDDEN_AJAX = 'درخواست معتبر یا مجاز نیست؛ صفحه را تازه کنید.';
    private const FORBIDDEN_POST = 'درخواست معتبر یا مجاز نیست.';

    protected function setUp(): void {
        parent::setUp();
        $this->previousUnit = get_option('woocommerce_weight_unit', null);
        update_option('woocommerce_weight_unit', 'kg');
        self::assertTrue(defined('ZBM_TEST_SANDBOX') && ZBM_TEST_SANDBOX === true);
    }
    protected function tearDown(): void {
        if ($this->previousUnit === null) { delete_option('woocommerce_weight_unit'); }
        else { update_option('woocommerce_weight_unit', $this->previousUnit); }
        wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]);
        $this->writeEvidence();
        parent::tearDown();
    }
    public static function transports(): array {
        return ['admin-post' => ['admin-post.php'], 'admin-ajax' => ['admin-ajax.php']];
    }
    public static function capabilityBranches(): array {
        $rows = [];
        foreach (['admin-post.php', 'admin-ajax.php'] as $transport) {
            $rows['finance-only/spec4_contact/' . $transport] = ['spec4_contact', 'finance', 'operations', $transport];
            $rows['operations-only/flow_note/' . $transport] = ['flow_note', 'operations', 'finance', $transport];
        }
        return $rows;
    }

    /** @dataProvider transports */
    public function test_vendor_objection_in_seller_review_and_six_exact_rejections(string $transport): void {
        $key = 'flow_object/owning-vendor/seller_review/' . $transport;
        self::$evidence[$key] = ['state' => 'started', 'transport' => $transport, 'negative' => []];
        try {
            // This factory reaches seller_review through real vendor and buyer multipart submissions.
            $positive = new Task4ManagedFixture('flow_accept_weight');
            $positive->validate();
            $before = $positive->case();
            self::assertSame('seller_review', $before['state']);
            $vendor = $positive->users['vendor_1'];
            $reason = 'Synthetic owning vendor objection to buyer weight';
            $http = $this->send($positive, 'flow_object', $vendor, $transport, ['reason' => $reason]);
            $this->assertAccepted($http, $transport, $vendor);
            $after = $positive->case(); $line = reset($after['data']['lines']);
            self::assertSame($before['version'] + 1, $after['version']);
            self::assertSame('admin_review', $after['state']); self::assertNull($after['deadline_gmt']);
            self::assertCount(1, $line['vendor_objection']); self::assertSame($reason, $line['vendor_reason']);
            self::assertSame(10, $line['vendor_tenths']); self::assertSame(10, $line['buyer_tenths']);
            self::assertSame([], $line['buyer_objection']); self::assertSame(0, $after['data']['funds']['balance']['amount']);
            $metadata = ManagedEvidence::metadata($line['vendor_objection'][0]);
            self::assertTrue(ManagedEvidence::matchesOwner($metadata, $positive->id, $vendor, 'vendor_objection', $positive->orderId));
            self::assertIsString(ManagedEvidence::privatePath($line['vendor_objection'][0]));
            $this->assertAudit($positive->id, 'managed_object', 'vendor', $vendor, 'seller_review', 'admin_review');
            $positive->validate();
            self::$evidence[$key]['positive'] = ['result' => 'accepted-durable-vendor-objection', 'http' => $http['status'], 'request_id' => $positive->id, 'before_version' => $before['version'], 'after_version' => $after['version'], 'evidence_id' => $line['vendor_objection'][0], 'evidence_purpose' => $metadata['purpose'], 'actor_user_id' => $vendor, 'state' => $after['state'], 'reason' => $line['vendor_reason']];
            $negative = new Task4ManagedFixture('flow_accept_weight');
            $negative->validate(); self::assertSame('seller_review', $negative->case()['state']);
            self::$evidence[$key]['negative_fixture'] = ['request_id' => $negative->id, 'state' => 'seller_review', 'aggregate_validated' => true, 'captured_deposit_and_cash_proved' => true];
            foreach (['no_nonce', 'invalid_nonce', 'wrong_role', 'other_buyer', 'other_vendor', 'guest'] as $scenario) {
                $actor = match ($scenario) {
                    'wrong_role' => $negative->users['buyer_1'],
                    'other_buyer' => $negative->users['buyer_2'],
                    'other_vendor' => $negative->users['vendor_2'],
                    default => $negative->users['vendor_1'],
                };
                $beforeSnapshot = Task4ManagedFixture::snapshot();
                $http = $this->send($negative, 'flow_object', $actor, $transport, ['reason' => $reason], $scenario);
                if ($scenario === 'no_nonce' || $scenario === 'invalid_nonce') {
                    $observed = $this->assertNonceRejection($http, $transport, $actor);
                } elseif ($scenario === 'guest') {
                    self::assertSame(400, $http['status'], 'WordPress must reject the unregistered nopriv action before controller dispatch.');
                    if ($transport === 'admin-ajax.php') { self::assertSame('0', trim($http['body'])); }
                    else { self::assertStringContainsString('wp-die-message', $http['body']); }
                    $observed = ['http' => 400, 'reason' => 'wordpress-unregistered-nopriv-action', 'body' => $transport === 'admin-ajax.php' ? '0' : 'wp_die'];
                } else { $observed = $this->assertForbidden($http, $transport); }
                self::assertSame($beforeSnapshot, Task4ManagedFixture::snapshot(), 'Rejected vendor objection changed persisted business/evidence/audit facts: ' . $scenario);
                self::$evidence[$key]['negative'][$scenario] = ['result' => 'rejected-exact-contract-no-persisted-effect', 'observed' => $observed];
                $this->writeEvidence();
            }
            self::assertCount(6, self::$evidence[$key]['negative']);
            self::$evidence[$key]['state'] = 'passed';
        } catch (Throwable $error) { $this->recordFailure($key, $error); throw $error; }
    }

    /** @dataProvider capabilityBranches */
    public function test_finance_and_operations_are_distinct_capability_boundaries(string $operation, string $allowedKind, string $forbiddenKind, string $transport): void {
        $key = $allowedKind . '-only/' . $operation . '/' . $transport;
        self::$evidence[$key] = ['state' => 'started', 'operation' => $operation, 'transport' => $transport];
        try {
            $actors = ['finance' => $this->capabilityUser('finance'), 'operations' => $this->capabilityUser('operations')];
            $fixture = new Task4ManagedFixture($operation);
            $fixture->validate();
            $before = $fixture->case();
            $reason = 'Synthetic isolated ' . $allowedKind . ' capability decision';
            $http = $this->send($fixture, $operation, $actors[$allowedKind], $transport, ['reason' => $reason]);
            $this->assertAccepted($http, $transport, $actors[$allowedKind]);
            $after = $fixture->case(); self::assertSame($before['version'] + 1, $after['version']);
            if ($operation === 'spec4_contact') {
                self::assertSame('contact_due', $before['data']['spec4']['substate']);
                self::assertSame('warning', $after['data']['spec4']['substate']); self::assertSame('balance_due', $after['state']);
                self::assertCount(1, $after['data']['spec4']['contact_history']);
                self::assertSame($actors['finance'], $after['data']['spec4']['contact_history'][0]['actor_id']);
                self::assertNotNull($after['data']['spec4']['warning_deadline_gmt']);
                $decision = end($after['data']['spec4']['decision_history']);
                self::assertSame('contact', $decision['action']); self::assertSame($actors['finance'], $decision['actor_id']);
            } else {
                self::assertCount(1, $after['data']['notes']['private']);
                self::assertSame($reason, $after['data']['notes']['private'][0]['text']);
                self::assertSame($actors['operations'], $after['data']['notes']['private'][0]['actor_id']);
            }
            $this->assertAudit($fixture->id, $operation === 'spec4_contact' ? 'spec4_contact' : 'managed_note', 'admin', $actors[$allowedKind], $before['state'], $after['state']);
            $fixture->validate();
            self::$evidence[$key]['positive'] = ['result' => 'accepted-durable-effects-with-only-required-capability', 'http' => $http['status'], 'request_id' => $fixture->id, 'actor_user_id' => $actors[$allowedKind], 'role' => 'subscriber', 'manage_options' => true, 'required_capability' => $allowedKind === 'finance' ? AdminCapabilities::MANAGE_FINANCE : AdminCapabilities::MANAGE_OPERATIONS, 'other_capability' => false, 'before_version' => $before['version'], 'after_version' => $after['version'], 'state' => $after['state']];
            $beforeSnapshot = Task4ManagedFixture::snapshot();
            $http = $this->send($fixture, $operation, $actors[$forbiddenKind], $transport, ['reason' => 'Synthetic cross-capability denial']);
            $observed = $this->assertForbidden($http, $transport);
            self::assertSame($beforeSnapshot, Task4ManagedFixture::snapshot(), 'A different administrative capability must not permit the action.');
            self::$evidence[$key]['cross_capability'] = ['result' => 'rejected-exact-403-no-persisted-effect', 'actor_user_id' => $actors[$forbiddenKind], 'role' => 'subscriber', 'manage_options' => true, 'only_business_capability' => $forbiddenKind, 'observed' => $observed];
            self::$evidence[$key]['state'] = 'passed';
        } catch (Throwable $error) { $this->recordFailure($key, $error); throw $error; }
    }

    /** Actual nonadministrator WordPress users retain their narrow grants when Installer repairs administrator roles. */
    private function capabilityUser(string $kind): int {
        self::assertInstanceOf(WP_Role::class, get_role('subscriber'));
        $login = 'zbm_task4_' . $kind . '_' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 12);
        $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(32, true, true), 'user_email' => $login . '@example.invalid', 'role' => 'subscriber']);
        self::assertFalse(is_wp_error($id)); self::assertGreaterThan(0, (int) $id);
        $user = new WP_User((int) $id);
        $user->add_cap('manage_options', true);
        $user->add_cap(AdminCapabilities::MANAGE_OPERATIONS, $kind === 'operations');
        $user->add_cap(AdminCapabilities::MANAGE_FINANCE, $kind === 'finance');
        $user->add_cap(AdminCapabilities::MANAGE_TEST_MODE, false);
        self::assertSame(['subscriber'], $user->roles);
        self::assertTrue(user_can((int) $id, 'manage_options'));
        self::assertSame($kind === 'operations', AdminCapabilities::userCanOperate((int) $id));
        self::assertSame($kind === 'finance', AdminCapabilities::userCanManageFinance((int) $id));
        return (int) $id;
    }
    private function send(Task4ManagedFixture $fixture, string $operation, int $actor, string $transport, array $extra = [], string $scenario = 'valid'): array {
        $fields = array_replace($fixture->fields($operation), $extra);
        if ($scenario === 'guest') { $cookie = ''; $nonce = ''; }
        else { [$cookie, $nonce] = CandidateHttp::auth($actor, 'zbm_managed_case_' . $fixture->id); }
        if ($scenario !== 'no_nonce') { $fields['_zbm_nonce'] = $scenario === 'invalid_nonce' ? 'invalid-task4-role-nonce' : $nonce; }
        delete_transient(ManagedCasePanel::NOTICE_KEY . $actor);
        return CandidateHttp::post('wp-admin/' . $transport, $fields, $cookie);
    }
    private function assertAccepted(array $http, string $transport, int $actor): void {
        if ($transport === 'admin-ajax.php') {
            self::assertSame(200, $http['status'], $http['body']);
            $json = json_decode($http['body'], true, 32, JSON_THROW_ON_ERROR);
            self::assertTrue($json['success']); self::assertSame('success', $json['data']['notice_type']);
        } else {
            self::assertSame(303, $http['status'], $http['body']); self::assertStringContainsString('Location:', $http['headers']);
            $notice = Task4ManagedFixture::notice($actor); self::assertIsArray($notice); self::assertSame('success', $notice['type']); self::assertNotEmpty($notice['message']);
        }
    }
    private function assertNonceRejection(array $http, string $transport, int $actor): array {
        if ($transport === 'admin-ajax.php') {
            self::assertSame(409, $http['status']);
            $json = json_decode($http['body'], true, 32, JSON_THROW_ON_ERROR);
            self::assertFalse($json['success']); self::assertSame(['message' => self::NONCE_MESSAGE, 'code' => ''], $json['data']);
            return ['http' => 409, 'message' => self::NONCE_MESSAGE, 'code' => ''];
        }
        self::assertSame(303, $http['status']); self::assertStringContainsString('Location:', $http['headers']);
        $notice = Task4ManagedFixture::notice($actor); self::assertSame(['type' => 'error', 'message' => self::NONCE_MESSAGE], $notice);
        return ['http' => 303, 'notice' => $notice];
    }
    private function assertForbidden(array $http, string $transport): array {
        self::assertSame(403, $http['status'], $http['body']);
        if ($transport === 'admin-ajax.php') {
            $json = json_decode($http['body'], true, 32, JSON_THROW_ON_ERROR);
            self::assertFalse($json['success']); self::assertSame(['message' => self::FORBIDDEN_AJAX], $json['data']);
        } else { self::assertStringContainsString(self::FORBIDDEN_POST, html_entity_decode(strip_tags($http['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8')); }
        return ['http' => 403, 'reason' => 'controller-derived-role-forbidden', 'message' => $transport === 'admin-ajax.php' ? self::FORBIDDEN_AJAX : self::FORBIDDEN_POST];
    }
    private function assertAudit(int $request, string $event, string $actorType, int $actor, string $from, string $to): void {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE resource_id=%d AND event_key=%s AND actor_user_id=%d', (new Schema($wpdb))->audit_table(), $request, $event, $actor), ARRAY_A);
        self::assertCount(1, $rows); self::assertSame($actorType, $rows[0]['actor_type']); self::assertSame($from, $rows[0]['from_state']); self::assertSame($to, $rows[0]['to_state']);
    }
    private function recordFailure(string $key, Throwable $error): void {
        self::$evidence[$key]['state'] = 'failed';
        self::$evidence[$key]['failure'] = ['class' => get_class($error), 'message' => $error->getMessage()];
    }
    private function writeEvidence(): void {
        $path = __DIR__ . '/../../evidence/task4-managed-role-branches.json';
        $json = wp_json_encode(['scope' => 'ManagedCaseActionController additional role branches', 'expected_vendor_transport_rows' => 2, 'expected_vendor_negative_scenarios' => 12, 'expected_capability_transport_rows' => 4, 'results' => self::$evidence], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!is_dir(dirname($path))) { self::assertTrue(mkdir(dirname($path), 0755, true)); }
        self::assertSame(strlen($json), file_put_contents($path, $json, LOCK_EX));
    }
}

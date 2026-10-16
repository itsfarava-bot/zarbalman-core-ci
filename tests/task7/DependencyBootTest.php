<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/Task7DependencyHarness.php';
require_once __DIR__ . '/../support/Task5DependencyDoubles.php';
require_once __DIR__ . '/../support/Task4RemainingFixture.php';

use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Infrastructure\Schema;

/** Native boot exclusions and explicitly labelled double-backed HTTP isolation. */
final class DependencyBootTest extends TestCase {
    public static function nativeFailures(): array {
        return [
            'Woo missing' => ['missing_woocommerce', 'woocommerce'],
            'Dokan missing' => ['missing_dokan', 'dokan_lite'],
            'Woo and its bundled AS absent (not AS-only)' => ['missing_woo_and_scheduler', 'action_scheduler'],
            'native wrong currency' => ['broken_currency', 'currency'],
            'native wrong weight' => ['broken_weight', 'weight'],
            'native commercial YITH unavailable (other missing dependencies remain)' => ['native_yith_absent', 'yith'],
        ];
    }

    /** @dataProvider nativeFailures */
    public function test_native_dependency_failure_boots_without_partial_business_mutation(string $scenario, string $failedKey): void {
        $before = Task7DependencyHarness::snapshot();
        $facts = Task7DependencyHarness::boot($scenario);
        self::assertSame($before, Task7DependencyHarness::snapshot(), 'A dependency boot/denied creation must not persist partial orders, requests, cash, audit or account changes.');
        self::assertFalse($facts['health'][$failedKey]);
        self::assertNotNull($facts['deposit_error']);
        if (in_array($scenario, ['missing_woocommerce', 'missing_woo_and_scheduler'], true)) {
            self::assertFalse($facts['woocommerce_loaded']);
            self::assertTrue($facts['missing_woo_notice']);
            self::assertNotContains(true, $facts['business_hooks'], 'Missing Woo must not register mutation handlers.');
            self::assertFalse($facts['mutation_attempted']);
            if ($scenario === 'missing_woo_and_scheduler') { self::assertFalse($facts['scheduler_loaded']); }
        } else {
            self::assertTrue($facts['woocommerce_loaded']);
            self::assertNotContains(false, $facts['business_hooks']);
            self::assertTrue($facts['mutation_attempted']);
            self::assertSame('zbm_request_dependencies_unavailable', $facts['mutation_error']);
            self::assertSame($facts['request_error'], $facts['mutation_error']);
            if ($scenario === 'missing_dokan') { self::assertFalse($facts['dokan_loaded']); }
            if ($scenario === 'native_yith_absent') { self::assertFalse($facts['yith_loaded']); }
        }
        self::assertFalse(getenv('ZBM_TASK7_DEPENDENCY_SCENARIO'), 'Exclusion must be child-scoped.');
        self::assertTrue(class_exists('WooCommerce'));
        self::assertTrue(function_exists('dokan_is_user_seller'));
        self::assertTrue(function_exists('as_schedule_single_action'));
    }

    public static function yithModes(): array { return ['YITH present double' => [true], 'YITH absent' => [false]]; }

    /** @dataProvider yithModes */
    public function test_double_backed_yith_presence_does_not_gate_actual_http_request_creation(bool $yith): void {
        global $wpdb;
        $previous = get_current_user_id(); $hadCookie = isset($_COOKIE[LOGGED_IN_COOKIE]); $cookieBefore = $_COOKIE[LOGGED_IN_COOKIE] ?? null;
        $doubles = null;
        try {
            $doubles = Task5DependencyDoubles::install(['yith' => $yith, 'gateway' => true, 'supporting' => true]);
            // Independent fixture accounts avoid inherited rate limits and never
            // change any existing user's email, mobile or vendor profile.
            $buyer = $this->actor('customer'); $vendor = $this->actor('seller');
            $product = Task4RemainingFixture::product($vendor, 'Task 7 request-only YITH isolation');
            $fields = Task4RemainingFixture::createFields($product, $vendor);
            [$adminCookie] = CandidateHttp::auth((int) get_user_by('login', 'zbm_test_admin')->ID, 'task7-dependency-facts');
            $facts = $doubles->facts($adminCookie);
            self::assertSame($yith, $facts['report']['yith']['ok']);
            $failed = array_keys(array_filter($facts['report'], static fn (array $row): bool => !$row['ok']));
            self::assertSame($yith ? [] : ['yith'], $failed, 'No unrelated dependency may satisfy an isolated YITH assertion.');
            self::assertNull($facts['request_error'], 'All request dependencies must be independently present.');
            self::assertSame($yith ? null : 'zbm_manual_order_dependencies_unavailable', $facts['manual_error']['code'] ?? null);
            $before = Task7DependencyHarness::snapshot(false);
            $schema = new Schema($wpdb);
            $countBefore = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $schema->purchase_requests_table()));
            self::assertSame('', $wpdb->last_error);
            [$cookie, $nonce] = CandidateHttp::auth($buyer, 'zbm_create_purchase_request');
            $fields['_zbm_nonce'] = $nonce;
            $http = CandidateHttp::post('wp-admin/admin-post.php', $fields, $cookie);
            self::assertSame(303, $http['status']);
            $location = Task4EndpointHttp::header($http, 'Location'); $query = [];
            parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
            self::assertSame('1', (string) ($query['zbm_created'] ?? ''));
            self::assertGreaterThan(0, (int) ($query['request'] ?? 0));
            $row = $wpdb->get_row($wpdb->prepare('SELECT buyer_id,vendor_id,current_state,wc_order_id FROM %i WHERE id=%d', $schema->purchase_requests_table(), (int) $query['request']), ARRAY_A);
            self::assertSame('', $wpdb->last_error); self::assertIsArray($row);
            self::assertSame($buyer, (int) $row['buyer_id']); self::assertSame($vendor, (int) $row['vendor_id']);
            self::assertSame('supplier_review', $row['current_state']); self::assertNull($row['wc_order_id']);
            $countAfter = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $schema->purchase_requests_table()));
            self::assertSame('', $wpdb->last_error); self::assertSame((int) $countBefore + 1, (int) $countAfter);
            self::assertSame($before, Task7DependencyHarness::snapshot(false), 'Request creation alone must not create orders or cash or change account contacts.');
            self::assertSame([], $doubles->calls(), 'No provider or SMS transport belongs in request-only creation.');
        } finally {
            if ($doubles) { $doubles->restore(); }
            wp_set_current_user($previous);
            if ($hadCookie) { $_COOKIE[LOGGED_IN_COOKIE] = $cookieBefore; } else { unset($_COOKIE[LOGGED_IN_COOKIE]); }
        }
    }

    private function actor(string $role): int {
        $login = 'task7_dependency_' . str_replace('-', '', wp_generate_uuid4());
        $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(32, true, true),
            'user_email' => $login . '@example.invalid', 'role' => $role]);
        self::assertFalse(is_wp_error($id)); self::assertGreaterThan(0, $id);
        update_user_meta($id, 'billing_phone', '09120000000');
        return (int) $id;
    }
}

<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/Task5FinancialFixture.php';
require_once __DIR__ . '/../support/Task4RemainingFixture.php';

use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Application\ManualPaymentSettings;
use Zarbalman\Core\Infrastructure\{DeliveryDataCrypto, OnlinePaymentAvailability, ProtectedText};

/** Observational checks only; the product acceptance contract is not declared green here. */
final class OrderProfileObservationTest extends TestCase {
    private ?Task5DependencyDoubles $doubles = null;
    private array $previous = [];

    protected function tearDown(): void {
        try {
            $this->doubles?->restore();
            foreach ($this->previous as $key => $row) {
                if ($row['exists']) { update_option($key, $row['value'], false); } else { delete_option($key); }
            }
        } finally { wp_set_current_user(0); parent::tearDown(); }
    }

    public function test_native_services_record_order_result_and_preserve_account_mobile(): void {
        global $wpdb;
        foreach (['zbm_core_real_manual_payment', 'zbm_core_online_payment_control', '_transient_zbm_core_online_payment_observation', '_transient_timeout_zbm_core_online_payment_observation'] as $key) {
            $missing = new stdClass(); $value = get_option($key, $missing);
            $this->previous[$key] = ['exists' => $value !== $missing, 'value' => $value === $missing ? null : $value];
            delete_option($key);
        }
        $this->doubles = Task5DependencyDoubles::install(['supporting' => true]);
        $email = get_user_by('login', 'zbm_test_buyer_1')->user_email;
        self::assertNotSame('', $email, 'The observation requires an existing synthetic account contact value.');
        $results = [];
        foreach (['online', 'manual_bank', 'test'] as $route) {
            $buyer = wp_insert_user(['user_login' => 'task7_profile_' . str_replace('-', '', wp_generate_uuid4()),
                'user_pass' => wp_generate_password(32, true, true), 'user_email' => 'profile' . wp_generate_uuid4() . substr($email, strpos($email, chr(64))), 'role' => 'customer']);
            self::assertFalse(is_wp_error($buyer)); self::assertGreaterThan(0, $buyer);
            $accountEmail = get_userdata($buyer)->user_email;
            $mobile = '09120000000'; update_user_meta($buyer, 'billing_phone', $mobile);
            $vendor = (int) get_user_by('login', 'zbm_test_vendor_1')->ID;
            update_user_meta($vendor, 'dokan_enable_selling', 'yes');
            $product = Task4RemainingFixture::product($vendor, 'Task seven synthetic profile product');
            [$request, $repos] = CandidateFixture::request(['buyer_id' => $buyer, 'vendor_id' => $vendor,
                'product_id' => $product->get_id(), 'execution_mode' => $route === 'test' ? 'test_pending' : 'live',
                'current_state' => $route === 'online' ? 'supplier_review' : 'supplier_accepted',
                'supplier_deadline_gmt' => gmdate('Y-m-d H:i:s', time() + 3600), 'pricing_version' => 2]);
            if ($route === 'manual_bank') {
                $crypto = new DeliveryDataCrypto(); self::assertTrue($crypto->ensureKey());
                $settings = new ManualPaymentSettings(new ProtectedText($crypto), $repos['audit']);
                $admin = (int) get_user_by('login', 'zbm_test_admin')->ID;
                self::assertTrue($settings->save($admin, ['enabled' => true, 'card_number' => '4242424242424242', 'holder_name' => 'Synthetic Holder'], 'Synthetic profile setup'));
                $availability = new OnlinePaymentAvailability($wpdb, $repos['audit']);
                self::assertTrue($availability->setStopped($admin, true, $availability->control()['revision'], 'Synthetic manual observation'));
            }
            $process = proc_open([PHP_BINARY, __DIR__ . '/../support/Task7ProfileProbe.php'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fwrite($pipes[0], json_encode(['request_id' => $request->id(), 'route' => $route, 'mobile' => $mobile], JSON_THROW_ON_ERROR)); fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), 'The isolated native observation must finish normally.');
            self::assertSame('', $stderr, 'No native warning or fatal error is allowed.');
            $result = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
            self::assertNotFalse(file_put_contents(__DIR__ . '/../../evidence/task7-profile-' . $route . '.json',
                wp_json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)));
            self::assertNotSame('native_exception', $result['result_error'], 'Observation infrastructure must invoke the actual native service.');
            self::assertNotEmpty($result['observations'], 'The route must reach the actual native managed-order save boundary.');
            self::assertTrue($result['native_service']); self::assertTrue($result['account_email_present']);
            clean_user_cache($buyer);
            self::assertSame($accountEmail, get_userdata($buyer)->user_email, 'The observation must preserve the existing account contact value.');
            self::assertTrue($result['mobile_preserved'], 'The observation must not alter buyer mobile data.');
            // Observation is deliberately outcome-neutral until product review.
            // Existing acceptance/deferral guards remain authoritative and unchanged.
            self::assertSame(0, $result['financial_counts']['ledger_table']);
            self::assertSame(0, $result['financial_counts']['financial_actions_table']);
            $results[$route] = $result;
        }
        $path = __DIR__ . '/../../evidence/task7-order-profile-observations.json';
        self::assertNotFalse(file_put_contents($path, wp_json_encode(['observational_only' => true,
            'product_acceptance_green_claimed' => false, 'commercial_dependency_native' => false,
            'run_id' => getenv('GITHUB_RUN_ID'), 'routes' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)));
    }
}

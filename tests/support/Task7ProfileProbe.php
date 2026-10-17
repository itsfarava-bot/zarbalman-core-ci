<?php
declare(strict_types=1);

// Read-only observations of production service results in the disposable runtime.
if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require getenv('ZBM_TEST_WP_ROOT') . '/wp-load.php';
if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true) { exit(1); }
require_once __DIR__ . '/Task5FinancialFixture.php';

use Zarbalman\Core\Admin\PurchaseRequestAdmin;
use Zarbalman\Core\Http\ManagedCaseActionController;
use Zarbalman\Core\Infrastructure\Schema;
use Zarbalman\Core\Integration\ManagedOrderSnapshot;

$input = json_decode((string) stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$repos = CandidateFixture::repositories();
$request = $repos['requests']->find((int) $input['request_id']);
if (!$request) { throw new RuntimeException('Synthetic request required.'); }
$observations = [];
$observer = static function ($order) use ($request, &$observations): void {
    if ($order instanceof WC_Order && (int) $order->get_meta('_zbm_request_id', true) === $request->id()) {
        $observations[] = ['email_present' => $order->get_billing_email('edit') !== '',
            'phone_present' => $order->get_billing_phone('edit') !== '',
            'private_fields_present' => ManagedOrderSnapshot::containsPrivateCustomerData($order),
            'snapshot_matches' => ManagedOrderSnapshot::matches($order, $request)];
    }
};
add_action('woocommerce_after_order_object_save', $observer, PHP_INT_MAX);
wp_set_current_user($request->buyerId());
$error = null; $exceptionClass = null;
try {
    $admin = Task5FinancialFixture::registered('admin_post_zbm_admin_request_control', PurchaseRequestAdmin::class);
    if ($input['route'] === 'online') {
        $service = (new ReflectionProperty($admin, 'service'))->getValue($admin);
        $key = wp_generate_uuid4();
        $result = $service->accept($request->id(), $request->vendorId(), $request->version(), $key, $key);
    } elseif ($input['route'] === 'manual_bank') {
        $controller = Task5FinancialFixture::registered('wp_ajax_zbm_managed_case_action', ManagedCaseActionController::class);
        $service = (new ReflectionProperty($controller, 'manual'))->getValue($controller);
        $offered = $service->offer($request->id(), 0, $request->buyerId(), wp_generate_uuid4());
        $result = is_wp_error($offered) ? $offered : $service->recover($request->id());
    } elseif ($input['route'] === 'test') {
        $advance = (new ReflectionProperty($admin, 'test_advance'))->getValue($admin);
        $result = $advance->advanceDeposit($request->id(), (int) get_user_by('login', 'zbm_test_admin')->ID,
            $request->version(), 'Synthetic profile observation', wp_generate_uuid4(), wp_generate_uuid4());
    } else { throw new RuntimeException('Unsupported route.'); }
    $error = is_wp_error($result) ? $result->get_error_code() : null;
} catch (Throwable $failure) { $error = 'native_exception'; $exceptionClass = get_class($failure); }
finally { remove_action('woocommerce_after_order_object_save', $observer, PHP_INT_MAX); }
$fresh = $repos['requests']->find($request->id());
$order = $fresh->wcOrderId() ? wc_get_order($fresh->wcOrderId()) : null;
$money = [];
foreach (['payments_table', 'ledger_table', 'financial_actions_table'] as $method) {
    $money[$method] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE request_id=%d', (new Schema($wpdb))->$method(), $request->id()));
    if ($wpdb->last_error !== '') { throw new RuntimeException('Native count unavailable.'); }
}
echo json_encode(['route' => $input['route'], 'native_service' => true, 'http_boundary' => false,
    'result_error' => $error, 'exception_class' => $exceptionClass,
    'request_state' => $fresh->state(), 'order_created' => $order instanceof WC_Order,
    'account_email_present' => get_userdata($request->buyerId())->user_email !== '',
    'mobile_preserved' => get_user_meta($request->buyerId(), 'billing_phone', true) === $input['mobile'],
    'order_mobile_present' => $order instanceof WC_Order && $order->get_billing_phone('edit') !== '',
    'observations' => $observations, 'financial_counts' => $money], JSON_THROW_ON_ERROR);

<?php
declare(strict_types=1);
require __DIR__ . '/wp-load.php';
if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true) { wp_send_json(['code' => 'sandbox_required'], 403); }
if (!current_user_can('manage_options')) { wp_send_json(['code' => 'sandbox_admin_required'], 403); }
if (!isset($GLOBALS['zbm_task5_dependency_double_config'])) { wp_send_json(['code' => 'double_fixture_missing'], 500); }
$health = new Zarbalman\Core\Infrastructure\DependencyHealth();
$error = static fn (?WP_Error $value): ?array => $value ? ['code' => $value->get_error_code(), 'message' => $value->get_error_message()] : null;
$managedCallbackPriorities = [];
foreach (($GLOBALS['wp_filter']['woocommerce_api_wc_zpal']->callbacks ?? []) as $priority => $callbacks) {
    foreach ($callbacks as $callback) {
        $target = $callback['function'];
        if (is_array($target) && ($target[0] ?? null) instanceof Zarbalman\Core\Integration\ZarinpalAdapter
            && ($target[1] ?? '') === 'handleManagedCallback') { $managedCallbackPriorities[] = (int) $priority; }
    }
}
$registered = WC()->payment_gateways()->payment_gateways();
$guardProbe = null;
$probeOrderId = absint($_GET['order_id'] ?? 0);
if ($probeOrderId > 0) {
    $probeOrder = wc_get_order($probeOrderId);
    if (!$probeOrder instanceof WC_Order || 'yes' !== $probeOrder->get_meta('_zbm_managed_order', true)) { wp_send_json(['code' => 'synthetic_managed_order_required'], 400); }
    $items = array_values($probeOrder->get_items('line_item'));
    if (count($items) !== 1) { wp_send_json(['code' => 'single_synthetic_line_required'], 400); }
    $item = $items[0];
    $guardProbe = ['order_id' => $probeOrderId, 'physical_result' => apply_filters('yith_wcdp_virtual_on_deposit', true, $probeOrder),
        'skip_balance_result' => apply_filters('yith_wcdp_skip_suborder_creation', false, $item->get_id(), $item, $probeOrderId, $probeOrder, $item->get_product())];
}
wp_send_json(['dependency_mode' => 'double-backed', 'actual_http' => true, 'real_provider_called' => false,
    'gateway_registered' => isset($registered['WC_ZPal']) && $registered['WC_ZPal'] instanceof WC_ZPal,
    'managed_callback_priorities' => $managedCallbackPriorities,
    'native_yith_guard_probe' => $guardProbe,
    'doubles' => array_intersect_key($GLOBALS['zbm_task5_dependency_double_config'], array_flip(['yith', 'gateway', 'supporting'])),
    'report' => $health->report(), 'online_available' => $health->onlineAvailableForNewPayment(),
    'request_error' => $error($health->requestCreationError()), 'deposit_error' => $error($health->depositOrderError()),
    'manual_error' => $error($health->manualOrderError()), 'test_managed_error' => $error($health->testManagedOrderError()),
    'verification_error' => $error($health->paymentVerificationError()),
    'request_control_registered' => has_action('admin_post_zbm_admin_request_control') !== false,
    'balance_callback_registered' => has_action('admin_post_zbm_balance_callback') !== false && has_action('admin_post_nopriv_zbm_balance_callback') !== false,
    'balance_callback_hooks' => ['admin_post_zbm_balance_callback' => has_action('admin_post_zbm_balance_callback') !== false,
        'admin_post_nopriv_zbm_balance_callback' => has_action('admin_post_nopriv_zbm_balance_callback') !== false],
    'legacy_test_mode_registered' => has_action('admin_post_zbm_core_test_mode') !== false,
    'yith_physical_guard_registered' => has_filter('yith_wcdp_virtual_on_deposit') !== false,
    'yith_balance_guard_registered' => has_filter('yith_wcdp_skip_suborder_creation') !== false]);

<?php
/**
 * Test-only guard; NOT a payment/SMS implementation or gateway compatibility mock.
 * Installed only in the disposable environment, never in the production plugin.
 */
if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true) {
    throw new RuntimeException('Sandbox guard used outside an approved sandbox.');
}
add_filter('pre_http_request', static function ($preempt) {
    return new WP_Error('zbm_test_outbound_disabled', 'All outbound WordPress HTTP is disabled in Task 1.');
}, PHP_INT_MAX);
add_filter('pre_wp_mail', static function () { return false; }, PHP_INT_MAX);
// cURL/SDK calls bypassing WordPress HTTP are not protected by this guard.
// Commercial payment/SMS plugins therefore must not be installed in this CI.

// Capture only sandbox event names and synthetic identifiers; never recipients or cookies.
$zbmTask4Trace = static function (string $event): void {
    $directory = dirname(rtrim(ABSPATH, '/'), 2) . '/evidence';
    if (!is_dir($directory) || ($_POST['operation'] ?? '') !== 'manual_credit_seller') { return; }
    file_put_contents($directory . '/task4-http-events.jsonl', wp_json_encode([
        'event' => $event, 'request_id' => absint($_POST['request_id'] ?? 0),
    ]) . "\n", FILE_APPEND | LOCK_EX);
};
add_filter('pre_wp_mail', static function ($value) use ($zbmTask4Trace) { $zbmTask4Trace('mail'); return $value; }, 1);
add_action('woocommerce_order_status_changed', static function () use ($zbmTask4Trace) { $zbmTask4Trace('order_status_changed'); });
add_action('shutdown', static function () use ($zbmTask4Trace): void {
    $zbmTask4Trace('request_completed');
    if (!in_array($_POST['action'] ?? '', ['zbm_core_transition', 'zbm_core_control', 'zbm_core_test_mode'], true)) { return; }
    $directory = dirname(rtrim(ABSPATH, '/'), 2) . '/evidence';
    if (!is_dir($directory)) { return; }
    $location = '';
    foreach (headers_list() as $header) { if (str_starts_with(strtolower($header), 'location:')) { $location = trim(substr($header, 9)); } }
    file_put_contents($directory . '/task4-routing.jsonl', wp_json_encode([
        'action' => $_POST['action'], 'order_id' => absint($_POST['order_id'] ?? 0),
        'actor' => get_current_user_id(), 'roles' => wp_get_current_user()->roles,
        'status' => http_response_code(), 'location' => $location,
        'referer' => wp_get_referer(), 'fallback' => wc_get_account_endpoint_url('orders'),
        'registered' => has_action('admin_post_' . $_POST['action']),
    ]) . "\n", FILE_APPEND | LOCK_EX);
});

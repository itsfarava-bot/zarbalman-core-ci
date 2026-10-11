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

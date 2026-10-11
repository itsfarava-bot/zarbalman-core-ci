<?php
declare(strict_types=1);
// Install at the disposable WP root as task4-endpoint-harness.php. No production replacement.
$view = isset($_GET['view']) && is_string($_GET['view']) ? $_GET['view'] : '';
// Bootstrap real WooCommerce/Dokan admin providers before plugins_loaded.
if ($view === 'metabox' && !defined('WP_ADMIN')) { define('WP_ADMIN', true); }
require __DIR__ . '/wp-load.php';
if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true) { http_response_code(403); exit('Sandbox required'); }
if ($view === 'reports') {
    do_action('dokan_load_custom_template', ['zbm-vendor-reports' => true]);
} elseif ($view === 'metabox') {
    try {
    require_once ABSPATH . 'wp-admin/includes/admin.php';
    $order = wc_get_order(absint($_GET['order_id'] ?? 0));
    if (!$order) { http_response_code(404); exit('Order missing'); }
    $screen = wc_get_page_screen_id('shop-order');
    if (!is_admin() || !str_ends_with($screen, 'wc-orders')) { http_response_code(500); exit('Real HPOS admin screen required'); }
    set_current_screen($screen);
    do_action('add_meta_boxes', $screen, $order);
    $box = $GLOBALS['wp_meta_boxes'][$screen]['normal']['high']['zbm-order-operations'] ?? null;
    if (!is_array($box)) { http_response_code(500); exit('HPOS metabox registration missing'); }
    echo '<div data-task4-screen="' . esc_attr($screen) . '">';
    call_user_func($box['callback'], $order);
    echo '</div>';
    } catch (Throwable $error) {
        http_response_code(500);
        echo wp_json_encode(['sandbox_error'=>get_class($error),'message'=>$error->getMessage(),'file'=>basename($error->getFile()),'line'=>$error->getLine()]);
    }
} elseif ($view === 'deadline-sweep') {
    if (!current_user_can('manage_options')) { wp_send_json(['code' => 'sandbox_admin_required'], 403); }
    $hook = Zarbalman\Core\Infrastructure\DeadlineScheduler::RECOVERY_HOOK;
    $registered = has_action($hook);
    $scheduled = as_has_scheduled_action($hook, [], Zarbalman\Core\Infrastructure\Scheduler::GROUP);
    do_action($hook);
    wp_send_json(['registered' => $registered, 'scheduled' => $scheduled]);
} elseif ($view === 'login-return') {
    // The actual registered login filters consume the HTTP cookie.
    $fallback = home_url('/safe-login-fallback/');
    $result = apply_filters('woocommerce_login_redirect', $fallback, wp_get_current_user());
    wp_send_json(['return_to' => $result]);
} else { http_response_code(400); echo 'Unknown sandbox view'; }

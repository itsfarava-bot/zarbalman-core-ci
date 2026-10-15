<?php
declare(strict_types=1);

// CI-only observer. It returns WordPress's original wp_die handler unchanged.
// Copy only into the disposable sandbox's mu-plugins directory.
$zbm_guest_observer = static function (mixed $handler): mixed {
    if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true || ($_POST['_zbm_guest_structure'] ?? null) !== '1') {
        return $handler;
    }
    $action = $_POST['action'] ?? null;
    if (!is_string($action) || preg_match('/\A[a-z][a-z0-9_]{0,99}\z/', $action) !== 1) { return $handler; }
    $prefix = defined('DOING_AJAX') && DOING_AJAX ? 'wp_ajax_nopriv_' : 'admin_post_nopriv_';
    $guest_hook = $prefix . $action;
    header('X-Zbm-Guest-Scope: native-dispatcher-wp-die');
    header('X-Zbm-Guest-Hook: ' . $guest_hook);
    header('X-Zbm-Guest-Hook-Present: ' . (has_action($guest_hook) === false ? '0' : '1'));
    header('X-Zbm-Guest-Actor: ' . get_current_user_id());
    header('X-Zbm-Guest-Empty-Cookie: ' . (trim((string) ($_SERVER['HTTP_COOKIE'] ?? '')) === '' ? '1' : '0'));
    return $handler;
};
add_filter('wp_die_handler', $zbm_guest_observer, PHP_INT_MAX);
add_filter('wp_die_ajax_handler', $zbm_guest_observer, PHP_INT_MAX);

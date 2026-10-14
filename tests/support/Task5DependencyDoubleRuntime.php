<?php
declare(strict_types=1);
/** Installed only as a temporary disposable-sandbox MU plugin by Task5DependencyDoubles. */
if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true) {
    throw new RuntimeException('Task 5 dependency doubles require the disposable sandbox.');
}
$task5ConfigPath = __DIR__ . '/zbm-task5-dependency-double-config.json';
$task5Config = json_decode((string) file_get_contents($task5ConfigPath), true, 32, JSON_THROW_ON_ERROR);
$task5SupportRoot = $task5Config['support_root'] ?? '';
if (!is_string($task5SupportRoot) || !is_file($task5SupportRoot . '/doubles/yith/YithDouble.php')) {
    throw new RuntimeException('Task 5 double support directory unavailable.');
}
$GLOBALS['zbm_task5_dependency_double_config'] = $task5Config;
add_action('plugins_loaded', static function () use ($task5Config, $task5SupportRoot): void {
    if (!class_exists('WooCommerce')) { throw new RuntimeException('Real WooCommerce required for dependency doubles.'); }
    foreach (['YITH_WCDP', 'WC_ZPal', 'ZarinpalHelperClass'] as $name) {
        if (class_exists($name)) { throw new RuntimeException('Refusing to replace an installed commercial dependency: ' . $name); }
    }
    if ($task5Config['yith']) { require_once $task5SupportRoot . '/doubles/yith/YithDouble.php'; }
    if ($task5Config['gateway']) {
        require_once $task5SupportRoot . '/doubles/zarinpal/GatewayDouble.php';
        add_filter('woocommerce_payment_gateways', static function (array $gateways): array { $gateways[] = 'WC_ZPal'; return $gateways; });
    }
    if ($task5Config['supporting']) {
        if (class_exists('Dokan_Pro') || defined('DOKAN_PRO_FILE') || function_exists('PWSMS') || defined('PWSMS_VERSION')) {
            throw new RuntimeException('Refusing to replace installed supporting dependencies.');
        }
        define('DOKAN_PRO_FILE', $task5SupportRoot . '/doubles/dokan-pro/index.php');
        require_once DOKAN_PRO_FILE;
        define('PWSMS_VERSION', '7.2.2');
        function PWSMS(): object {
            static $instance;
            return $instance ??= new Task5SupportingSmsDouble();
        }
    }
}, 1);
final class Task5SupportingSmsTransportDouble {
    public function send(...$arguments): bool {
        throw new RuntimeException('Task 5 SMS presence double never delivers messages.');
    }
}
final class Task5SupportingSmsDouble {
    public function get_sms_gateway(): object { return new Task5SupportingSmsTransportDouble(); }
}
// The existing sandbox guard overwrites preemption at PHP_INT_MAX. Appending
// this filter at plugins_loaded makes the closed synthetic transport the last
// filter without removing that guard or allowing any network request.
add_action('plugins_loaded', static function () use ($task5Config): void {
    add_filter('pre_http_request', static function ($preempt, array $args, string $url) use ($task5Config) {
        $base = 'https://payment.zarinpal.com/pg/v4/payment/';
        $path = str_starts_with($url, $base) ? substr($url, strlen($base)) : '';
        if (!in_array($path, ['request.json', 'verify.json'], true) || !$task5Config['gateway']) {
            return new WP_Error('zbm_task5_outbound_disabled', 'Task 5 double transport blocks outbound HTTP.');
        }
        $body = json_decode((string) ($args['body'] ?? ''), true);
        $reply = $task5Config['responses'][$path] ?? null;
        $bindingMatches = true;
        foreach (is_array($reply['expected_body'] ?? null) ? $reply['expected_body'] : [] as $key => $expected) {
            if (!is_array($body) || !array_key_exists($key, $body) || $body[$key] !== $expected) { $bindingMatches = false; }
        }
        $record = ['dependency_mode' => 'double-backed', 'path' => $path, 'pid' => getmypid(), 'at' => microtime(true), 'expected_binding_matches' => $bindingMatches,
            'amount' => is_array($body) ? ($body['amount'] ?? null) : null,
            'authority_present' => is_array($body) && is_string($body['authority'] ?? null),
            'merchant_is_synthetic' => is_array($body) && ($body['merchant_id'] ?? null) === Task5DependencyDoubleRuntime::MERCHANT];
        // Separate option rows are atomic inserts, visible to HTTP and runner
        // processes without a read/modify/write race or stale option cache.
        if (!add_option('zbm_task5_double_call_' . wp_generate_uuid4(), $record, '', false)) {
            return new WP_Error('zbm_task5_double_record_failed', 'Synthetic transport recording failed closed.');
        }
        if (!$record['merchant_is_synthetic']) { return new WP_Error('zbm_task5_double_merchant_mismatch', 'Synthetic transport accepts only the owned synthetic merchant.'); }
        if (!$bindingMatches) { return new WP_Error('zbm_task5_double_binding_mismatch', 'Synthetic provider request did not match the expected binding.'); }
        if (!is_array($reply)) { return new WP_Error('zbm_task5_double_response_missing', 'No synthetic response was configured.'); }
        return ['headers' => [], 'body' => (string) $reply['body'], 'response' => ['code' => (int) $reply['status'], 'message' => 'Task 5 synthetic response'], 'cookies' => [], 'filename' => null];
    }, PHP_INT_MAX, 3);
}, 2);
final class Task5DependencyDoubleRuntime {
    public const MERCHANT = 'TASK5-SYNTHETIC-NOT-A-PROVIDER-CREDENTIAL';
}
// Observe real callback request lifetimes before Core's callback lock. This
// bounded two-worker barrier controls only test timing, never business state.
add_action('init', static function () use ($task5Config): void {
    $order = $task5Config['callback_probe_order_id'];
    if ($order < 1 || absint($_GET['wc_order'] ?? 0) !== $order || strtolower((string) ($_GET['wc-api'] ?? '')) !== 'wc_zpal') { return; }
    global $wpdb;
    $epoch = $task5Config['callback_probe_epoch'];
    $identity = wp_generate_uuid4(); $prefix = 'zbm_task5_double_probe_start_' . $order . '_' . $epoch . '_';
    $record = ['event' => 'start', 'order_id' => $order, 'epoch' => $epoch, 'identity' => $identity, 'pid' => getmypid(), 'at' => microtime(true), 'dependency_mode' => 'double-backed'];
    if (!add_option($prefix . $identity, $record, '', false)) { wp_die('Callback probe recording failed closed.', '', ['response' => 500]); }
    add_action('shutdown', static function () use ($order, $identity, $epoch): void {
        add_option('zbm_task5_double_probe_end_' . $order . '_' . $epoch . '_' . $identity,
            ['event' => 'end', 'order_id' => $order, 'epoch' => $epoch, 'identity' => $identity, 'pid' => getmypid(), 'at' => microtime(true), 'dependency_mode' => 'double-backed'], '', false);
    }, PHP_INT_MAX);
    if ($task5Config['callback_barrier_count'] !== 2) { return; }
    $deadline = microtime(true) + 5;
    do {
        $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like($prefix) . '%'));
        if ($wpdb->last_error !== '') { wp_die('Callback barrier read failed closed.', '', ['response' => 500]); }
        if ((int) $count >= 2) { return; }
        usleep(10000);
    } while (microtime(true) < $deadline);
    wp_die('Two distinct callback workers did not enter the barrier.', '', ['response' => 503]);
}, -999);

<?php
declare(strict_types=1);

/** Separate native WP boot. Only allowlisted booleans and error codes leave it. */
if (PHP_SAPI !== 'cli' || count($argv) !== 2) { exit(2); }
$root = getenv('ZBM_TEST_WP_ROOT');
if (!is_string($root) || !is_file($root . '/wp-load.php')) { exit(2); }
require $root . '/wp-load.php';
if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true) { exit(2); }
$scenario = getenv('ZBM_TASK7_DEPENDENCY_SCENARIO');
$facts = [
    'native_subprocess' => true, 'pid' => getmypid(),
    'woocommerce_loaded' => class_exists('WooCommerce'),
    'dokan_loaded' => function_exists('dokan_is_user_seller'),
    'yith_loaded' => class_exists('YITH_WCDP'),
    'scheduler_loaded' => function_exists('as_schedule_single_action') && function_exists('as_schedule_recurring_action'),
    'native_scheduler_bootstrap_removed' => ($GLOBALS['task7_as_bootstrap_removed'] ?? false) === true,
    'business_hooks' => [],
    'missing_woo_notice' => has_action('admin_notices', [Zarbalman\Core\Plugin::class, 'render_dependency_notice']) !== false,
];
foreach (['admin_post_zbm_create_purchase_request', 'admin_post_zbm_vendor_request_decision',
    'admin_post_zbm_admin_request_control', 'wp_ajax_zbm_managed_case_action', 'admin_post_zbm_balance_start'] as $hook) {
    $facts['business_hooks'][$hook] = has_action($hook) !== false;
}
$health = new Zarbalman\Core\Infrastructure\DependencyHealth();
$facts['health'] = array_map(static fn (array $row): bool => $row['ok'], $health->report());
$facts['request_error'] = $health->requestCreationError()?->get_error_code();
$facts['manual_error'] = $health->manualOrderError()?->get_error_code();
$facts['deposit_error'] = $health->depositOrderError()?->get_error_code();
$facts['mutation_attempted'] = false;
$facts['mutation_error'] = null;
if (in_array($scenario, ['missing_dokan', 'scheduler_bootstrap_unavailable', 'broken_currency', 'broken_weight', 'native_yith_absent'], true)) {
    $controllers = [];
    foreach (($GLOBALS['wp_filter']['admin_post_zbm_admin_request_control']->callbacks ?? []) as $callbacks) {
        foreach ($callbacks as $entry) {
            $callback = $entry['function'];
            if (is_array($callback) && $callback[0] instanceof Zarbalman\Core\Admin\PurchaseRequestAdmin) {
                $controllers[spl_object_id($callback[0])] = $callback[0];
            }
        }
    }
    if (count($controllers) !== 1) { throw new RuntimeException('Registered request service required.'); }
    $service = (new ReflectionProperty(array_values($controllers)[0], 'service'))->getValue(array_values($controllers)[0]);
    // Valid value object; actual production create must stop at the real health
    // gate before persistence. This does not claim successful form validation.
    $vendor = get_user_by('login', 'zbm_test_vendor_1');
    $buyer = get_user_by('login', 'zbm_test_buyer_1');
    if (!$vendor instanceof WP_User || !$buyer instanceof WP_User) { throw new RuntimeException('Synthetic actors required.'); }
    $start = (new DateTimeImmutable('today', wp_timezone()))->modify('+5 days')->setTime(12, 0)->setTimezone(new DateTimeZone('UTC'));
    $window = Zarbalman\Core\Domain\PurchaseRequest\DeliveryWindow::exact($start->format('Y-m-d H:i:s'), $start->modify('+2 hours')->format('Y-m-d H:i:s'), current_time('mysql', true), wp_timezone_string());
    $input = new Zarbalman\Core\Application\PurchaseRequestInput(1, 0, (int) $vendor->ID, 1, 1000, 10,
        'pickup_from_vendor', $window, 'Synthetic Buyer', null, null, null, null, null, null, null, null, true,
        ['store_name' => 'Synthetic store', 'street_1' => 'Synthetic street', 'street_2' => '', 'city' => 'Synthetic city', 'zip' => '00000', 'state' => 'TEH', 'country' => 'IR']);
    $result = $service->create($input, (int) $buyer->ID, wp_generate_uuid4(), wp_generate_uuid4());
    $facts['mutation_attempted'] = true;
    $facts['mutation_error'] = is_wp_error($result) ? $result->get_error_code() : null;
}
$json = json_encode($facts, JSON_THROW_ON_ERROR);
if (file_put_contents($argv[1], $json, LOCK_EX) !== strlen($json)) { exit(2); }

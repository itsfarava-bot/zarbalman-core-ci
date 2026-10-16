<?php
declare(strict_types=1);

/** Owned CLI child: real WordPress, a separate SQL connection, unchanged production services. */
if (PHP_SAPI !== 'cli' || count($argv) !== 3) { exit(2); }
$directory = $argv[1]; $slot = $argv[2];
if (!in_array($slot, ['0', '1'], true) || !is_dir($directory) || is_link($directory)) { exit(2); }
$spec = json_decode((string) file_get_contents($directory . '/input-' . $slot . '.json'), true, 32, JSON_THROW_ON_ERROR);
$root = getenv('ZBM_TEST_WP_ROOT');
if (!is_string($root) || !is_file($root . '/wp-load.php')) { exit(2); }
require $root . '/wp-load.php';
if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true) { exit(2); }

function task7Registered(string $hook, string $class): object {
    $objects = [];
    foreach (($GLOBALS['wp_filter'][$hook]->callbacks ?? []) as $callbacks) {
        foreach ($callbacks as $registration) {
            $target = $registration['function'];
            if (is_array($target) && ($target[0] ?? null) instanceof $class) { $objects[spl_object_id($target[0])] = $target[0]; }
        }
    }
    if (count($objects) !== 1) { throw new RuntimeException('Unique registered production object required.'); }
    return array_values($objects)[0];
}

$started = microtime(true); $arrived = null;
$connection = (int) $wpdb->get_var('SELECT CONNECTION_ID()');
$scope = defined('DB_NAME') ? (string) DB_NAME : $wpdb->prefix;
$lockName = 'zbm:' . substr(hash('sha256', $scope . '|' . $wpdb->prefix), 0, 36) . ':' . (int) $spec['request_id'];
// Observe the existing query before execution. Never replace SQL, its result,
// the lock object, authorization, policy, clock, or repository behavior.
$observer = static function (string $query) use ($lockName, $directory, $slot, $connection, &$arrived): string {
    if ($arrived === null && str_contains($query, 'SELECT GET_LOCK(') && str_contains($query, "'" . $lockName . "'")) {
        $arrived = microtime(true);
        file_put_contents($directory . '/arrival-' . $slot . '.tmp', json_encode([
            'pid' => getmypid(), 'connection_id' => $connection, 'at' => $arrived,
        ], JSON_THROW_ON_ERROR), LOCK_EX);
        rename($directory . '/arrival-' . $slot . '.tmp', $directory . '/arrival-' . $slot . '.json');
        $deadline = microtime(true) + 20;
        while (!is_file($directory . '/release')) {
            if (microtime(true) >= $deadline) { throw new RuntimeException('Two-process lock-boundary barrier timed out.'); }
            usleep(10000);
        }
    }
    return $query;
};
add_filter('query', $observer, PHP_INT_MAX);
try {
    wp_set_current_user((int) ($spec['actor_id'] ?? 0));
    if ($spec['kind'] === 'accept') {
        $controller = task7Registered('admin_post_zbm_admin_request_control', Zarbalman\Core\Admin\PurchaseRequestAdmin::class);
        $service = (new ReflectionProperty($controller, 'service'))->getValue($controller);
        $result = $service->accept((int) $spec['request_id'], (int) $spec['actor_id'], (int) $spec['version'], $spec['key'], $spec['key']);
    } elseif ($spec['kind'] === 'balance') {
        $controller = task7Registered('admin_post_zbm_balance_callback', Zarbalman\Core\Http\BalancePaymentController::class);
        $service = (new ReflectionProperty($controller, 'service'))->getValue($controller);
        $result = $service->callback((int) $spec['payment_id'], $spec['offer'], $spec['authority']);
    } elseif ($spec['kind'] === 'flow') {
        $controller = task7Registered('wp_ajax_zbm_managed_case_action', Zarbalman\Core\Http\ManagedCaseActionController::class);
        $service = (new ReflectionProperty($controller, 'flow'))->getValue($controller);
        $result = $service->act((int) $spec['request_id'], (int) $spec['version'], $spec['role'], (int) $spec['actor_id'], $spec['key'], $spec['operation'], $spec['payload']);
    } else { throw new RuntimeException('Unsupported bounded process scenario.'); }
    $facts = ['success' => !is_wp_error($result), 'error' => is_wp_error($result) ? $result->get_error_code() : null,
        'state' => is_wp_error($result) ? null : (is_array($result) ? $result['state'] : $result->state())];
} catch (Throwable) { $facts = ['success' => false, 'error' => 'task7_worker_exception', 'state' => null]; }
finally { remove_filter('query', $observer, PHP_INT_MAX); }
$facts += ['pid' => getmypid(), 'connection_id' => $connection, 'started_at' => $started,
    'lock_arrival_at' => $arrived, 'finished_at' => microtime(true)];
file_put_contents($directory . '/result-' . $slot . '.json', json_encode($facts, JSON_THROW_ON_ERROR), LOCK_EX);
exit(0);

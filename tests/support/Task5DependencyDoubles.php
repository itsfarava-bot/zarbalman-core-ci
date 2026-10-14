<?php
declare(strict_types=1);
require_once __DIR__ . '/Task4EndpointHttp.php';
require_once __DIR__ . '/HttpClient.php';

/** Explicit double-backed dependencies, installed only into a disposable runtime.
 * No production objects, actors, capabilities, eligibility, or health reports are replaced.
 */
final class Task5DependencyDoubles {
    private string $root;
    private string $mu;
    private array $previous = [];
    private array $created = [];
    private mixed $lock;
    private array $config = [];
    private array $adminBootstrap = [];
    private ?string $projectionDiagnosticsPath = null;
    private const MERCHANT = 'TASK5-SYNTHETIC-NOT-A-PROVIDER-CREDENTIAL';

    public static function install(array $config = []): self {
        $fixture = new self();
        try { $fixture->start($config); return $fixture; }
        catch (Throwable $error) { $fixture->restore(); throw $error; }
    }
    private function __construct() {
        if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true) { throw new RuntimeException('Disposable sandbox required.'); }
        $root = getenv('ZBM_TEST_WP_ROOT');
        if (!is_string($root) || !is_file($root . '/wp-load.php')) { throw new RuntimeException('Real disposable WP root required.'); }
        $this->root = rtrim($root, '/'); $this->mu = $this->root . '/wp-content/mu-plugins';
        $this->lock = fopen($this->mu . '/zbm-task5-dependency-double.lock', 'c');
        if (!is_resource($this->lock) || !flock($this->lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Another dependency-double fixture owns this sandbox.'); }
    }
    private function start(array $config): void {
        $this->primeAdmin();
        foreach (['zz-zbm-task5-dependency-double.php', 'zbm-task5-dependency-double-config.json', 'zbm-task5-projection-diagnostics.jsonl'] as $name) {
            if (file_exists($this->mu . '/' . $name)) { throw new RuntimeException('Dependency-double runtime already exists.'); }
        }
        $harness = $this->root . '/task5-dependency-double-harness.php';
        if (file_exists($harness)) { throw new RuntimeException('Dependency-double harness already exists.'); }
        // File writes survive the native creation transaction's ROLLBACK.
        // Exclusive creation makes this fixture the only cleanup owner.
        $diagnostics = $this->mu . '/zbm-task5-projection-diagnostics.jsonl';
        $handle = fopen($diagnostics, 'x');
        if (!is_resource($handle)) { throw new RuntimeException('Unable to create owned projection diagnostic file.'); }
        $this->created[] = $diagnostics;
        fclose($handle);
        if (!chmod($diagnostics, 0600)) { throw new RuntimeException('Unable to restrict projection diagnostic file.'); }
        $this->projectionDiagnosticsPath = $diagnostics;
        foreach (['woocommerce_WC_ZPal_settings', 'woocommerce_currency', 'woocommerce_weight_unit', 'woocommerce_manage_stock'] as $name) {
            $missing = new stdClass(); $value = get_option($name, $missing);
            $this->previous[$name] = ['exists' => $value !== $missing, 'value' => $value === $missing ? null : $value];
        }
        update_option('woocommerce_WC_ZPal_settings', ['enabled' => 'yes', 'merchantcode' => self::MERCHANT, 'fee_payer' => 'merchant', 'sandbox' => 'no'], false);
        update_option('woocommerce_currency', 'IRT'); update_option('woocommerce_weight_unit', 'kg'); update_option('woocommerce_manage_stock', 'yes');
        $this->configure($config);
        foreach ([__DIR__ . '/Task5DependencyDoubleRuntime.php' => $this->mu . '/zz-zbm-task5-dependency-double.php',
            __DIR__ . '/Task5DependencyDoubleHarness.php' => $harness] as $source => $destination) {
            if (!copy($source, $destination)) { throw new RuntimeException('Unable to copy sandbox dependency-double support.'); }
            $this->created[] = $destination;
        }
    }
    public function configure(array $config): void {
        $config += ['yith' => true, 'gateway' => true, 'supporting' => false, 'responses' => [], 'callback_probe_order_id' => 0, 'callback_barrier_count' => 0];
        foreach (['yith', 'gateway', 'supporting'] as $key) {
            if (!is_bool($config[$key])) { throw new InvalidArgumentException('Double mode must be boolean.'); }
        }
        if (!is_array($config['responses'])) { throw new InvalidArgumentException('Synthetic responses must be an array.'); }
        if (!is_int($config['callback_probe_order_id']) || $config['callback_probe_order_id'] < 0
            || !in_array($config['callback_barrier_count'], [0, 2], true)) { throw new InvalidArgumentException('Unsupported bounded callback probe.'); }
        foreach ($config['responses'] as $path => $reply) {
            if (!in_array($path, ['request.json', 'verify.json'], true) || !is_array($reply)
                || !is_int($reply['status'] ?? null) || !is_string($reply['body'] ?? null)
                || isset($reply['expected_body']) && !is_array($reply['expected_body'])) { throw new InvalidArgumentException('Unsupported synthetic response.'); }
        }
        $config = array_intersect_key($config, array_flip(['yith', 'gateway', 'supporting', 'responses', 'callback_probe_order_id', 'callback_barrier_count']));
        $config['support_root'] = __DIR__;
        // A fresh timing epoch prevents older callback arrivals from satisfying
        // a later two-worker barrier for the same synthetic order.
        $config['callback_probe_epoch'] = wp_generate_uuid4();
        $path = $this->mu . '/zbm-task5-dependency-double-config.json';
        $json = json_encode($config, JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) { throw new RuntimeException('Unable to persist dependency-double configuration.'); }
        if (!in_array($path, $this->created, true)) { $this->created[] = $path; }
        $this->config = $config;
    }
    private function primeAdmin(): void {
        $admin = get_user_by('login', 'zbm_test_admin');
        if (!$admin instanceof WP_User || !user_can($admin, 'manage_options')) { throw new RuntimeException('Native sandbox administrator required.'); }
        $actor = get_current_user_id(); $hadCookie = isset($_COOKIE[LOGGED_IN_COOKIE]); $oldCookie = $_COOKIE[LOGGED_IN_COOKIE] ?? null;
        try {
            [$cookie] = CandidateHttp::auth((int) $admin->ID, 'task5-admin-bootstrap');
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $http = Task4EndpointHttp::get('wp-admin/index.php', [], $cookie);
                $location = Task4EndpointHttp::header($http, 'Location'); $query = [];
                parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
                $this->adminBootstrap[] = ['http' => $http['status'], 'path' => parse_url($location, PHP_URL_PATH), 'page' => $query['page'] ?? null];
                if ($http['status'] === 200) { return; }
                if ($http['status'] !== 302 || parse_url(home_url('/'), PHP_URL_HOST) !== parse_url($location, PHP_URL_HOST)
                    || !str_starts_with((string) parse_url($location, PHP_URL_PATH), '/wp-admin/')) { throw new RuntimeException('Unexpected native admin bootstrap response.'); }
            }
            $http = Task4EndpointHttp::get('wp-admin/index.php', [], $cookie);
            if ($http['status'] !== 200) { throw new RuntimeException('Native admin bootstrap did not complete.'); }
        } finally {
            wp_set_current_user($actor);
            if ($hadCookie) { $_COOKIE[LOGGED_IN_COOKIE] = $oldCookie; } else { unset($_COOKIE[LOGGED_IN_COOKIE]); }
        }
    }
    public function facts(string $cookie, int $orderId = 0): array {
        if ($orderId < 0) { throw new InvalidArgumentException('Invalid synthetic order probe.'); }
        $http = Task4EndpointHttp::get('task5-dependency-double-harness.php', $orderId > 0 ? ['order_id' => $orderId] : [], $cookie);
        if ($http['status'] !== 200) { throw new RuntimeException('Double-backed facts HTTP failed with status ' . $http['status']); }
        $facts = json_decode($http['body'], true, 64, JSON_THROW_ON_ERROR);
        if (($facts['dependency_mode'] ?? null) !== 'double-backed' || ($facts['actual_http'] ?? null) !== true) { throw new RuntimeException('Double-backed facts label missing.'); }
        $facts['admin_bootstrap'] = $this->adminBootstrap;
        $facts['projection_diagnostics'] = $this->projectionDiagnostics();
        return $facts;
    }
    /** Only explicitly projected diagnostic fields; never native logger context. */
    public function projectionDiagnostics(): array {
        $path = $this->projectionDiagnosticsPath;
        if ($path === null || !in_array($path, $this->created, true) || !is_file($path) || is_link($path)) {
            throw new RuntimeException('Owned projection diagnostic file unavailable.');
        }
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) { throw new RuntimeException('Projection diagnostics read failed.'); }
        try {
            if (!flock($handle, LOCK_SH)) { throw new RuntimeException('Projection diagnostics read lock failed.'); }
            $rows = [];
            while (($line = fgets($handle)) !== false) { $rows[] = json_decode($line, true, 64, JSON_THROW_ON_ERROR); }
            if (!feof($handle)) { throw new RuntimeException('Projection diagnostics read was incomplete.'); }
            return $rows;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
    public function calls(): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT option_name, option_value FROM %i WHERE option_name LIKE %s ORDER BY option_id',
            $wpdb->options, $wpdb->esc_like('zbm_task5_double_call_') . '%'), ARRAY_A);
        if ($wpdb->last_error !== '') { throw new RuntimeException('Synthetic transport facts read failed.'); }
        return array_map(static fn (array $row): array => maybe_unserialize($row['option_value']), $rows);
    }
    public function probes(): array {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare('SELECT option_value FROM %i WHERE option_name LIKE %s ORDER BY option_id',
            $wpdb->options, $wpdb->esc_like('zbm_task5_double_probe_') . '%'));
        if ($wpdb->last_error !== '') { throw new RuntimeException('Callback probe facts read failed.'); }
        return array_map('maybe_unserialize', $rows);
    }
    public function events(int $requestId): array {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare('SELECT option_value FROM %i WHERE option_name LIKE %s ORDER BY option_id',
            $wpdb->options, $wpdb->esc_like('zbm_task5_double_event_') . '%'));
        if ($wpdb->last_error !== '') { throw new RuntimeException('Native event observations unavailable.'); }
        return array_values(array_filter(array_map('maybe_unserialize', $rows), static fn (array $row): bool => $row['request_id'] === $requestId));
    }
    public function restore(): void {
        foreach (array_reverse($this->created) as $path) { if (is_file($path) && !unlink($path)) { throw new RuntimeException('Unable to remove owned temporary double support.'); } }
        $this->created = [];
        $this->projectionDiagnosticsPath = null;
        foreach ($this->previous as $name => $previous) {
            if ($previous['exists']) { update_option($name, $previous['value'], false); }
            else { delete_option($name); }
        }
        $this->previous = [];
        global $wpdb;
        if ($wpdb instanceof wpdb) {
            $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s', $wpdb->options,
                $wpdb->esc_like('zbm_task5_double_call_') . '%', $wpdb->esc_like('zbm_task5_double_probe_') . '%', $wpdb->esc_like('zbm_task5_double_event_') . '%'));
            if ($wpdb->last_error !== '') { throw new RuntimeException('Unable to clean synthetic call rows.'); }
        }
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); $this->lock = null; }
    }
}

<?php
declare(strict_types=1);
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Infrastructure\Schema;
use Zarbalman\Core\Integration\ManagedOrderSnapshot;
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
        if (($reply['transport_error'] ?? '') === 'timeout') { return new WP_Error('http_request_failed', 'cURL error 28: Synthetic verification timeout.'); }
        return ['headers' => [], 'body' => (string) $reply['body'], 'response' => ['code' => (int) $reply['status'], 'message' => 'Task 5 synthetic response'], 'cookies' => [], 'filename' => null];
    }, PHP_INT_MAX, 3);
}, 2);
final class Task5DependencyDoubleRuntime {
    public const MERCHANT = 'TASK5-SYNTHETIC-NOT-A-PROVIDER-CREDENTIAL';

    /** A read-only projection at Woo's actual logger boundary, before rollback. */
    public static function projectionDiagnostic(array $context): array {
        $reason = is_string($context['error'] ?? null) ? $context['error'] : '';
        $knownReasons = [
            'Managed WooCommerce order snapshot was not persisted.' => 'snapshot_not_persisted',
            'Managed order snapshot could not be saved.' => 'snapshot_save_id_mismatch',
            'Managed order has missing lines.' => 'missing_lines',
            'Managed order has no persisted WooCommerce item ID.' => 'missing_item_id',
            'WooCommerce did not create an order.' => 'order_not_created',
            'Managed order transaction could not start.' => 'transaction_start_failed',
            'Managed order transaction could not commit.' => 'transaction_commit_failed',
            'Request product is unavailable.' => 'product_unavailable',
        ];
        $row = ['dependency_mode' => 'double-backed', 'observation_boundary' => 'woocommerce_logger_log_message',
            'stage' => self::enum($context['stage'] ?? null, ['create', 'order_note', 'conversion_note']),
            'route' => self::enum($context['route'] ?? null, ['online', 'manual_bank', 'test']),
            'request_id' => self::integer($context['request_id'] ?? null),
            'referenced_order_id' => self::integer($context['order_id'] ?? null),
            'exception_class' => self::exceptionClass($context['exception'] ?? null),
            'known_reason' => $knownReasons[$reason] ?? 'unrecognized', 'reason_sha256' => hash('sha256', $reason)];
        global $wpdb;
        $savedDbError = $wpdb->last_error;
        try {
            $schema = new Schema($wpdb);
            $requestRow = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $schema->purchase_requests_table(), $row['request_id'] ?? 0), ARRAY_A);
            $row['request_read_error'] = $wpdb->last_error !== '';
            $row['request_found'] = is_array($requestRow);
            if (!is_array($requestRow)) { return $row; }
            $request = PurchaseRequest::fromRow($requestRow);
            // The request's order ID is still zero inside failed creation. Use
            // the same bounded immutable-meta lookup as native Core recovery.
            $orders = wc_get_orders(['type' => 'shop_order', 'limit' => 2, 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'objects',
                'meta_query' => [['key' => '_zbm_request_id', 'value' => (string) $request->id(), 'compare' => '=']]]);
            $row['immutable_meta_candidate_count'] = count($orders);
            $row['candidate_lookup'] = 'immutable_request_meta';
            // A native save can reject the staged snapshot before its request
            // meta exists. Retain only request-local native creation IDs as a
            // separately labelled observation, never a recovery/ownership proof.
            if ($orders === []) {
                foreach (array_slice($GLOBALS['zbm_task5_observed_native_order_ids'] ?? [], -2) as $id) {
                    $candidate = wc_get_order($id);
                    if ($candidate instanceof WC_Order && (int) $candidate->get_customer_id() === $request->buyerId()) { $orders[] = $candidate; }
                }
                $row['candidate_lookup'] = 'request_local_native_creation_same_customer';
            }
            $row['candidate_count'] = count($orders);
            $row['candidates'] = [];
            foreach ($orders as $order) {
                if ($order instanceof WC_Order) {
                    try { $row['candidates'][] = self::snapshotDiagnostic($order, $request); }
                    catch (Throwable $error) { $row['candidates'][] = ['order_id' => $order->get_id(), 'capture_error_class' => get_class($error)]; }
                }
            }
            return $row;
        } catch (Throwable $error) {
            $row['capture_error_class'] = get_class($error);
            return $row;
        } finally {
            // A diagnostic read must not change the transaction's error state.
            $wpdb->last_error = $savedDbError;
        }
    }

    private static function snapshotDiagnostic(WC_Order $order, PurchaseRequest $request): array {
        $route = ManagedOrderSnapshot::route($order);
        $mode = (string) $order->get_meta('_zbm_execution_mode', true);
        $mode = $mode === '' ? 'live' : $mode;
        $expectedMode = $request->isTest() ? 'test' : 'live';
        $validRoute = match ($route) {
            'online' => $expectedMode === 'live' && $order->get_payment_method() === 'WC_ZPal',
            'manual_bank' => $expectedMode === 'live' && $order->get_payment_method() === 'zbm_manual_bank',
            'test' => $expectedMode === 'test' && $order->get_payment_method() === 'WC_ZPal',
            default => false,
        };
        $pairs = [
            'customer_id' => [self::integer($order->get_customer_id()), $request->buyerId()],
            'request_id' => [self::integer($order->get_meta('_zbm_request_id', true)), $request->id()],
            'vendor_id' => [self::integer($order->get_meta('_zbm_vendor_id', true)), $request->vendorId()],
            'dokan_vendor_id' => [self::integer($order->get_meta('_dokan_vendor_id', true)), $request->vendorId()],
            'total' => [self::integer($order->get_total()), $request->depositToman()],
            'shipping_total' => [self::integer($order->get_shipping_total()), 0],
            'tax_total' => [self::integer($order->get_total_tax()), 0],
            'discount_total' => [self::integer($order->get_discount_total()), 0],
            'estimated_total_toman' => [self::integer($order->get_meta('_zbm_estimated_total_toman', true)), $request->estimatedTotalToman()],
            'deposit_toman' => [self::integer($order->get_meta('_zbm_deposit_toman', true)), $request->depositToman()],
            'reference_weight_tenths' => [self::integer($order->get_meta('_zbm_reference_weight_tenths', true)), $request->referenceWeightTenths()],
            'pricing_version' => [self::integer($order->get_meta('_zbm_pricing_version', true)), $request->pricingVersion()],
            'estimated_shipping_toman' => [self::integer($order->get_meta('_zbm_estimated_shipping_toman', true)), $request->estimatedShippingToman()],
            'shipping_rate_per_kg_toman' => [self::integer($order->get_meta('_zbm_shipping_rate_per_kg_toman', true)), $request->shippingRatePerKgToman()],
            'estimated_gross_toman' => [self::integer($order->get_meta('_zbm_estimated_gross_toman', true)), $request->estimatedGrossToman()],
            'execution_mode' => [self::enum($mode, ['live', 'test_pending', 'test']), $expectedMode],
            'payment_route' => [self::enum($route, ['online', 'manual_bank', 'test']), $expectedMode === 'test' ? 'test' : null],
            'payment_method' => [self::enum($order->get_payment_method(), ['WC_ZPal', 'zbm_manual_bank']), $route === 'manual_bank' ? 'zbm_manual_bank' : 'WC_ZPal'],
        ];
        $status = $order->get_status();
        $row = ['order_id' => $order->get_id(), 'matches' => ManagedOrderSnapshot::matches($order, $request),
            'pricing_metadata_matches' => ManagedOrderSnapshot::pricingMetadataMatches($order, $request),
            'private_customer_data_present' => ManagedOrderSnapshot::containsPrivateCustomerData($order),
            'managed_marker_matches' => $order->get_meta('_zbm_managed_order', true) === 'yes',
            'created_via_matches' => $order->get_created_via() === 'zarbalman-core-spec3',
            'currency_matches' => strtoupper($order->get_currency()) === 'IRT',
            'valid_route' => $validRoute, 'execution_mode_matches' => $mode === $expectedMode,
            'test_advance_matches' => ($route === 'test') === ($order->get_meta('_zbm_test_advance', true) === 'yes'),
            'request_public_id_matches' => hash_equals($request->publicId(), (string) $order->get_meta('_zbm_request_public_id', true)),
            'authoritative_status_matches' => (string) $order->get_meta('_zbm_authoritative_wc_status', true) === $status,
            'status' => self::enum($status, ['pending', 'processing', 'completed', 'on-hold', 'cancelled', 'refunded', 'failed', 'trash']),
            'core_deposit_engine' => $order->get_meta('_zbm_deposit_engine', true) === 'core',
            'yith_has_deposit_present' => (bool) $order->get_meta('_has_deposit', true),
            'multiline' => $request->isMultiline(), 'numeric_and_enum_pairs' => $pairs, 'item_counts' => [], 'private_presence' => [], 'items' => []];
        foreach (['line_item', 'shipping', 'fee', 'tax', 'coupon'] as $type) { $row['item_counts'][$type] = count($order->get_items($type)); }
        foreach (['billing_first_name', 'billing_last_name', 'billing_company', 'billing_address_1', 'billing_address_2', 'billing_city',
            'billing_state', 'billing_postcode', 'billing_country', 'billing_email', 'billing_phone', 'shipping_first_name', 'shipping_last_name',
            'shipping_company', 'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_state', 'shipping_postcode',
            'shipping_country', 'shipping_phone', 'customer_note', 'customer_ip_address', 'customer_user_agent'] as $field) {
            $getter = 'get_' . $field;
            $row['private_presence'][$field] = ['view' => trim((string) $order->$getter()) !== '', 'edit' => trim((string) $order->$getter('edit')) !== ''];
        }
        $lines = $request->isMultiline() ? ManagedOrderSnapshot::multilineProjection($request) : [];
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof WC_Order_Item_Product) { $row['items'][] = ['product_item' => false]; continue; }
            $line = $request->isMultiline() ? ($lines[(string) $item->get_meta('_zbm_request_line_id', true)] ?? null) : null;
            $row['items'][] = ['product_item' => true, 'persisted_item_id_present' => $item->get_id() > 0,
                'item_matches' => ManagedOrderSnapshot::itemMatches($item, $request),
                'line_key_matches' => !$request->isMultiline() || $line !== null,
                'line_name_matches' => $line === null ? null : $item->get_name() === $line['name'],
                'yith_deposit_present' => (bool) $item->get_meta('_deposit', true),
                'numeric_pairs' => [
                    'product_id' => [self::integer($item->get_product_id()), $line['product_id'] ?? $request->productId()],
                    'variation_id' => [self::integer($item->get_variation_id()), $line['variation_id'] ?? $request->variationId()],
                    'quantity' => [self::integer($item->get_quantity()), $line['quantity'] ?? $request->quantity()],
                    'subtotal' => [self::integer($item->get_subtotal()), $line['subtotal'] ?? $request->estimatedGrossToman()],
                    'total' => [self::integer($item->get_total()), $line['deposit'] ?? $request->depositToman()],
                    'subtotal_tax' => [self::integer($item->get_subtotal_tax()), 0],
                    'total_tax' => [self::integer($item->get_total_tax()), 0],
                    'package_price_toman' => [self::integer($item->get_meta('_zbm_package_price_toman', true)), $line['package_price_toman'] ?? $request->packagePriceToman()],
                    'nominal_package_weight_tenths' => [self::integer($item->get_meta('_zbm_nominal_package_weight_tenths', true)), $line['nominal_weight_tenths'] ?? $request->nominalPackageWeightTenths()],
                    'reference_weight_tenths' => [self::integer($item->get_meta('_zbm_reference_weight_tenths', true)), $line['reference_tenths'] ?? $request->referenceWeightTenths()],
                    'vendor_id' => [self::integer($item->get_meta('_zbm_vendor_id', true)), $request->isMultiline() ? $request->vendorId() : null],
                    'authoritative_line_deposit_toman' => [self::integer($item->get_meta('_zbm_authoritative_line_deposit_toman', true)), $line['deposit'] ?? null],
                ]];
        }
        return $row;
    }
    private static function integer(mixed $value): ?int {
        if (!is_int($value) && !is_string($value) && !is_float($value)) { return null; }
        if (preg_match('/\A([0-9]+)(?:\.0+)?\z/', trim((string) $value), $matches) !== 1) { return null; }
        $canonical = ltrim($matches[1], '0');
        $maximum = (string) PHP_INT_MAX;
        if (strlen($canonical) > strlen($maximum) || (strlen($canonical) === strlen($maximum) && strcmp($canonical, $maximum) > 0)) { return null; }
        return (int) $canonical;
    }
    private static function enum(mixed $value, array $allowed): ?string { return in_array($value, $allowed, true) ? $value : null; }
    private static function exceptionClass(mixed $value): ?string {
        return is_string($value) && preg_match('/\A[A-Za-z_][A-Za-z0-9_\\\\]{0,199}\z/', $value) === 1 && class_exists($value) && is_a($value, Throwable::class, true) ? $value : null;
    }
}
add_action('woocommerce_new_order', static function ($orderId): void {
    $GLOBALS['zbm_task5_observed_native_order_ids'][] = (int) $orderId;
}, PHP_INT_MAX, 1);
add_action('zbm_purchase_request_deposit_paid', static function (int $requestId): void {
    add_option('zbm_task5_double_event_' . wp_generate_uuid4(), ['event' => 'zbm_purchase_request_deposit_paid',
        'request_id' => $requestId, 'pid' => getmypid(), 'at' => microtime(true), 'dependency_mode' => 'double-backed'], '', false);
}, PHP_INT_MAX, 1);
// Woo invokes this filter once per native handler. Duplicate rows are retained
// as observations, never counted as distinct business failures. The observer
// always returns the original message and never installs/replaces a logger.
add_filter('woocommerce_logger_log_message', static function ($message, $level, $context, $handler) {
    if ($message !== 'Managed WooCommerce order projection failed.' || $level !== 'error'
        || !is_array($context) || ($context['source'] ?? null) !== 'zarbalman-core') { return $message; }
    $path = __DIR__ . '/zbm-task5-projection-diagnostics.jsonl';
    if (!is_file($path) || is_link($path)) { return $message; }
    try {
        try { $row = Task5DependencyDoubleRuntime::projectionDiagnostic($context); }
        catch (Throwable $error) { $row = ['dependency_mode' => 'double-backed', 'observation_boundary' => 'woocommerce_logger_log_message', 'capture_error_class' => get_class($error)]; }
        $line = json_encode($row, JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable) { /* Observation must never alter native logging or recovery. */ }
    return $message;
}, PHP_INT_MAX, 4);
// Observe real callback request lifetimes before Core's callback lock. This
// bounded two-worker barrier controls only test timing, never business state.
add_filter('woocommerce_order_get_status', static function ($status, $order) use ($task5Config) {
    if ($task5Config['callback_barrier_count'] !== 2 || !$order instanceof WC_Order
        || $order->get_id() !== $task5Config['callback_probe_order_id'] || $status !== 'pending'
        || strtolower((string) ($_GET['wc-api'] ?? '')) !== 'wc_zpal'
        || !isset($GLOBALS['zbm_task5_callback_probe_identity']) || isset($GLOBALS['zbm_task5_pending_read_observed'])) { return $status; }
    $GLOBALS['zbm_task5_pending_read_observed'] = true;
    global $wpdb;
    $identity = $GLOBALS['zbm_task5_callback_probe_identity'];
    $prefix = 'zbm_task5_double_probe_prelock_' . $order->get_id() . '_' . $task5Config['callback_probe_epoch'] . '_';
    $record = ['event' => 'prelock_read', 'order_id' => $order->get_id(), 'epoch' => $task5Config['callback_probe_epoch'],
        'identity' => $identity, 'pid' => getmypid(), 'at' => microtime(true), 'status' => $status, 'dependency_mode' => 'double-backed'];
    if (!add_option($prefix . $identity, $record, '', false)) { wp_die('Pending order probe recording failed closed.', '', ['response' => 500]); }
    $deadline = microtime(true) + 5;
    do {
        $rows = $wpdb->get_col($wpdb->prepare('SELECT option_value FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like($prefix) . '%'));
        if ($wpdb->last_error !== '') { wp_die('Pending order barrier read failed closed.', '', ['response' => 500]); }
        $pids = array_unique(array_map(static fn ($value): int => (int) (maybe_unserialize($value)['pid'] ?? 0), $rows));
        if (count($pids) >= 2 && !in_array(0, $pids, true)) { return $status; }
        usleep(10000);
    } while (microtime(true) < $deadline);
    wp_die('Two distinct callback workers did not read the pending order.', '', ['response' => 503]);
}, PHP_INT_MAX, 2);
add_action('init', static function () use ($task5Config): void {
    $order = $task5Config['callback_probe_order_id'];
    if ($order < 1 || absint($_GET['wc_order'] ?? 0) !== $order || strtolower((string) ($_GET['wc-api'] ?? '')) !== 'wc_zpal') { return; }
    global $wpdb;
    $epoch = $task5Config['callback_probe_epoch'];
    $identity = wp_generate_uuid4(); $prefix = 'zbm_task5_double_probe_start_' . $order . '_' . $epoch . '_';
    $GLOBALS['zbm_task5_callback_probe_identity'] = $identity;
    $record = ['event' => 'start', 'order_id' => $order, 'epoch' => $epoch, 'identity' => $identity, 'pid' => getmypid(), 'at' => microtime(true), 'dependency_mode' => 'double-backed'];
    if (!add_option($prefix . $identity, $record, '', false)) { wp_die('Callback probe recording failed closed.', '', ['response' => 500]); }
    add_action('shutdown', static function () use ($order, $identity, $epoch): void {
        add_option('zbm_task5_double_probe_end_' . $order . '_' . $epoch . '_' . $identity,
            ['event' => 'end', 'order_id' => $order, 'epoch' => $epoch, 'identity' => $identity, 'pid' => getmypid(), 'at' => microtime(true), 'dependency_mode' => 'double-backed'], '', false);
    }, PHP_INT_MAX);
    if ($task5Config['callback_barrier_count'] !== 2) { return; }
    $deadline = microtime(true) + 5;
    do {
        $rows = $wpdb->get_col($wpdb->prepare('SELECT option_value FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like($prefix) . '%'));
        if ($wpdb->last_error !== '') { wp_die('Callback barrier read failed closed.', '', ['response' => 500]); }
        $pids = array_unique(array_map(static fn ($value): int => (int) (maybe_unserialize($value)['pid'] ?? 0), $rows));
        if (count($pids) >= 2 && !in_array(0, $pids, true)) { return; }
        usleep(10000);
    } while (microtime(true) < $deadline);
    wp_die('Two distinct callback workers did not enter the barrier.', '', ['response' => 503]);
}, -999);

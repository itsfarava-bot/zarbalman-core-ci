<?php
declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Zarbalman\Core\Infrastructure\Schema;

final class Task7DependencyHarness {
    public static function boot(string $scenario): array {
        Assert::assertTrue(defined('ZBM_TEST_SANDBOX') && ZBM_TEST_SANDBOX === true);
        $root = getenv('ZBM_TEST_WP_ROOT');
        Assert::assertIsString($root);
        Assert::assertFileExists($root . '/wp-load.php');
        $token = bin2hex(random_bytes(16));
        $mu = $root . '/wp-content/mu-plugins/zbm-task7-dependency-' . $token . '.php';
        $directory = sys_get_temp_dir() . '/zbm-task7-dependency-' . $token;
        Assert::assertTrue(mkdir($directory, 0700));
        $owned = false; $process = null;
        try {
            $handle = fopen($mu, 'x');
            Assert::assertIsResource($handle, 'Do not overwrite another fixture.');
            $owned = true;
            $source = file_get_contents(__DIR__ . '/Task7DependencyMu.php');
            Assert::assertIsString($source);
            Assert::assertSame(strlen($source), fwrite($handle, $source));
            fclose($handle);
            $environment = getenv();
            $environment['ZBM_TASK7_DEPENDENCY_SCENARIO'] = $scenario;
            $process = proc_open([PHP_BINARY, __DIR__ . '/Task7DependencyWorker.php', $directory . '/result.json'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/stdout', 'a'], 2 => ['file', $directory . '/stderr', 'a']], $pipes, null, $environment);
            Assert::assertIsResource($process);
            $deadline = microtime(true) + 30; $exit = null;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) { $exit = $status['exitcode']; break; }
                usleep(10000);
            } while (microtime(true) < $deadline);
            if ($exit !== 0) {
                $evidence = __DIR__ . '/../../evidence';
                foreach (['stdout', 'stderr'] as $stream) {
                    file_put_contents($evidence . '/task7-dependency-' . $scenario . '-' . $stream . '.txt',
                        (string) file_get_contents($directory . '/' . $stream), LOCK_EX);
                }
            }
            Assert::assertSame(0, $exit, 'Native dependency boot must finish normally; raw child logs are never emitted.');
            Assert::assertFileExists($directory . '/result.json');
            $facts = json_decode((string) file_get_contents($directory . '/result.json'), true, 32, JSON_THROW_ON_ERROR);
            Assert::assertTrue($facts['native_subprocess']);
            Assert::assertNotSame(getmypid(), $facts['pid']);
            return $facts;
        } finally {
            if (is_resource($process)) { if (proc_get_status($process)['running']) { proc_terminate($process); } proc_close($process); }
            if ($owned) { unlink($mu); }
            foreach (glob($directory . '/*') ?: [] as $path) { unlink($path); }
            rmdir($directory);
        }
    }

    /** SQL persistence oracle; hashes never expose customer or encrypted values. */
    public static function snapshot(bool $includeRequests = true): array {
        global $wpdb;
        $schema = new Schema($wpdb);
        $tables = [$schema->payments_table(), $schema->ledger_table(), $schema->financial_actions_table(),
            $wpdb->prefix . 'wc_orders', $wpdb->prefix . 'wc_order_addresses', $wpdb->prefix . 'wc_orders_meta',
            $wpdb->prefix . 'wc_order_operational_data', $wpdb->prefix . 'woocommerce_order_items', $wpdb->prefix . 'woocommerce_order_itemmeta'];
        if ($includeRequests) {
            $tables = array_merge($tables, [$schema->purchase_requests_table(), $schema->managed_cases_table(),
                $schema->audit_table(), $schema->notification_outbox_table(), $schema->idempotency_table()]);
        }
        $snapshot = [];
        foreach ($tables as $table) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY 1', $table), ARRAY_A);
            Assert::assertSame('', $wpdb->last_error); Assert::assertIsArray($rows);
            $snapshot[$table] = hash('sha256', wp_json_encode($rows, JSON_THROW_ON_ERROR));
        }
        $identity = $wpdb->get_results($wpdb->prepare('SELECT ID,user_email FROM %i ORDER BY ID', $wpdb->users), ARRAY_A);
        Assert::assertSame('', $wpdb->last_error);
        $mobiles = $wpdb->get_results($wpdb->prepare("SELECT umeta_id,user_id,meta_key,meta_value FROM %i WHERE meta_key IN ('billing_phone','phone','phone_number','mobile','dokan_profile_settings') ORDER BY umeta_id", $wpdb->usermeta), ARRAY_A);
        Assert::assertSame('', $wpdb->last_error);
        $snapshot['account_email_and_mobile'] = hash('sha256', wp_json_encode([$identity, $mobiles], JSON_THROW_ON_ERROR));
        $options = $wpdb->get_results($wpdb->prepare("SELECT option_name,option_value FROM %i WHERE option_name IN ('active_plugins','woocommerce_currency','woocommerce_weight_unit') ORDER BY option_name", $wpdb->options), ARRAY_A);
        Assert::assertSame('', $wpdb->last_error);
        $snapshot['persistent_dependency_options'] = hash('sha256', wp_json_encode($options, JSON_THROW_ON_ERROR));
        return $snapshot;
    }
}

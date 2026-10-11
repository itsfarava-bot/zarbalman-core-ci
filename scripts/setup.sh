#!/usr/bin/env bash
set -euo pipefail
task_root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$task_root"
test "${ZBM_TEST_SANDBOX:-}" = "1" || { echo 'Sandbox acknowledgement required'; exit 1; }
command -v php
command -v composer
command -v wp
php -r 'if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 2) { exit(1); }'
mkdir -p .runtime/wp evidence
test ! -e .runtime/wp/wp-config.php || { echo 'Existing sandbox config: refusing overwrite'; exit 1; }
wp core download --path=.runtime/wp --version="${ZBM_WP_VERSION:-7.0.3}" --locale=en_US
wp config create --path=.runtime/wp --dbname=zbm_task1 --dbuser=zbm_test --dbpass=zbm_test_only --dbhost=127.0.0.1:3306 --skip-salts --dbprefix=_ci --dbcharset=utf8mb4 --dbcollate=utf8mb4_unicode_520_ci
wp config set ZBM_TEST_SANDBOX true --raw --path=.runtime/wp
wp config set DISABLE_WP_CRON true --raw --path=.runtime/wp
wp core install --path=.runtime/wp --url=http://127.0.0.1:8080 --title='ZBM disposable test' --admin_user=zbm_test_admin --admin_password=zbm_local_test_only --admin_email=admin@example.invalid --skip-email
wp option update timezone_string Asia/Tehran --path=.runtime/wp
wp eval 'global $wpdb; if ($wpdb->prefix !== "_ci" || wp_timezone_string() !== "Asia/Tehran" || date_default_timezone_get() !== "UTC" || !extension_loaded("gd") || extension_loaded("imagick")) { throw new RuntimeException("Runtime alignment mismatch"); } echo "Prefix _ci; WP Asia/Tehran; GD only; DB " . $wpdb->db_version() . " verified\n";' --path=.runtime/wp
wp plugin install woocommerce --version=10.9.4 --path=.runtime/wp
wp plugin install dokan-lite --version=5.0.10 --path=.runtime/wp
mkdir -p .runtime/wp/wp-content/mu-plugins
cp tests/sandbox-guard.php .runtime/wp/wp-content/mu-plugins/zbm-task1-guard.php
wp plugin activate woocommerce dokan-lite --path=.runtime/wp
wp option update woocommerce_custom_orders_table_enabled yes --path=.runtime/wp
wp option update woocommerce_custom_orders_table_data_sync_enabled no --path=.runtime/wp
test ! -e .runtime/wp/wp-content/plugins/zarbalman-core
cp -R plugin .runtime/wp/wp-content/plugins/zarbalman-core
wp plugin activate zarbalman-core --path=.runtime/wp
wp eval-file tests/fixtures.php --use-include --path=.runtime/wp
wp eval 'if (!class_exists("ActionScheduler_Versions") || ActionScheduler_Versions::instance()->latest_version() !== "3.9.3") { throw new RuntimeException("Unexpected Action Scheduler version"); } echo "Action Scheduler 3.9.3 verified\n";' --path=.runtime/wp
export ZBM_TEST_WP_ROOT="$task_root/.runtime/wp"
composer test


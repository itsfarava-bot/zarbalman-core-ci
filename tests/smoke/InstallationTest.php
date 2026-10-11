<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class InstallationTest extends TestCase {
    public function test_original_plugin_is_active_and_version_matches(): void {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        self::assertTrue(is_plugin_active('zarbalman-core/zarbalman-core.php'));
        self::assertSame('1.0.4', ZBM_CORE_VERSION);
        self::assertSame('14', ZBM_CORE_DB_VERSION);
        self::assertSame('1.0.4', get_option('zbm_core_plugin_version'));
    }

    public function test_hpos_is_authoritative_without_legacy_sync(): void {
        self::assertTrue(\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled());
        self::assertSame('no', get_option('woocommerce_custom_orders_table_data_sync_enabled'));
    }

    public function test_action_scheduler_and_synthetic_users_exist(): void {
        self::assertTrue(function_exists('as_schedule_single_action'));
        self::assertInstanceOf(WP_User::class, get_user_by('login', 'zbm_test_buyer_1'));
        self::assertInstanceOf(WP_User::class, get_user_by('login', 'zbm_test_vendor_1'));
        self::assertInstanceOf(WP_User::class, get_user_by('login', 'zbm_test_admin'));
    }
}

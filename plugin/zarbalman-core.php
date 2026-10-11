<?php
/**
 * Plugin Name:       Zarbalman Core
 * Plugin URI:        https://example.invalid/
 * Update URI:        https://example.invalid/plugins/zarbalman-core
 * Description:       هسته امن و قابل توسعه گردش سفارش زربال‌من.
 * Version:           1.0.4
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Zarbalman
 * Text Domain:       zarbalman-core
 * Domain Path:       /languages
 * WC requires at least: 10.0
 * WC tested up to:   10.9
 * License:           All rights reserved
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZBM_CORE_VERSION', '1.0.4' );
define( 'ZBM_CORE_DB_VERSION', '14' );
define( 'ZBM_CORE_FILE', __FILE__ );
define( 'ZBM_CORE_DIR', plugin_dir_path( __FILE__ ) );

require_once ZBM_CORE_DIR . 'src/Autoloader.php';

\Zarbalman\Core\Autoloader::register( ZBM_CORE_DIR . 'src/' );

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				ZBM_CORE_FILE,
				true
			);
		}
	}
);

register_activation_hook( ZBM_CORE_FILE, array( \Zarbalman\Core\Infrastructure\Installer::class, 'activate' ) );
register_deactivation_hook( ZBM_CORE_FILE, array( \Zarbalman\Core\Infrastructure\Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Zarbalman\Core\Plugin::class, 'boot' ), 20 );
add_action( 'init', array( \Zarbalman\Core\Infrastructure\Installer::class, 'maybe_upgrade' ), 1 );

<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use Zarbalman\Core\Security\AdminCapabilities;

final class Installer {
	private const VERSION_OPTION = 'zbm_core_plugin_version';

	public static function activate(): void {
		$errors = self::dependency_errors();

		if ( $errors ) {
			deactivate_plugins( plugin_basename( ZBM_CORE_FILE ) );
			wp_die(
				esc_html( implode( ' ', $errors ) ),
				esc_html__( 'Zarbalman Core فعال نشد', 'zarbalman-core' ),
				array( 'back_link' => true )
			);
		}

		global $wpdb;
		( new Migrator( $wpdb ) )->migrate();
		AdminCapabilities::install();
		add_rewrite_endpoint( 'purchase-requests', EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( 'zbm-shipping-rates', EP_ROOT | EP_PAGES );
		flush_rewrite_rules( false );
		update_option( self::VERSION_OPTION, ZBM_CORE_VERSION, false );
		self::purgePublicCache();
	}

	/**
	 * Complete one-time work after an in-place plugin update.  Activation hooks
	 * do not run when WordPress replaces a plugin zip, so without this guard the
	 * account and Dokan endpoints remain 404 until an administrator saves the
	 * permalink settings manually.
	 */
	public static function maybe_upgrade(): void {
		if ( ! defined( 'ZBM_CORE_VERSION' ) || ! function_exists( 'get_option' ) ) {
			return;
		}
		AdminCapabilities::install();
		$installed = (string) get_option( self::VERSION_OPTION, '' );
		if ( ZBM_CORE_VERSION === $installed ) {
			return;
		}
		global $wp_rewrite;
		if (
			! function_exists( 'add_rewrite_endpoint' )
			|| ! function_exists( 'flush_rewrite_rules' )
			|| ! is_object( $wp_rewrite )
			|| ! method_exists( $wp_rewrite, 'add_endpoint' )
		) {
			return;
		}
		add_rewrite_endpoint( 'purchase-requests', EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( 'zbm-shipping-rates', EP_ROOT | EP_PAGES );
		flush_rewrite_rules( false );
		update_option( self::VERSION_OPTION, ZBM_CORE_VERSION, false );
		self::purgePublicCache();
	}

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			// An empty hook cancels our entire group, including actions with arguments.
			as_unschedule_all_actions( '', array(), Scheduler::GROUP );
		}
		foreach ( array(
			Scheduler::CLEANUP_HOOK,
			DeadlineScheduler::BUYER_HOOK,
			DeadlineScheduler::VENDOR_HOOK,
			DeadlineScheduler::RECOVERY_HOOK,
			EvidenceMigrationScheduler::HOOK,
			PurchaseRequestScheduler::SUPPLIER_HOOK,
			PurchaseRequestScheduler::DEPOSIT_HOOK,
			PurchaseRequestScheduler::RECOVERY_HOOK,
			ManagedFlowScheduler::BOOTSTRAP_HOOK,
			ManagedFlowScheduler::DEADLINE_HOOK,
			ManagedFlowScheduler::SYNC_HOOK,
			ManagedFlowScheduler::RECOVERY_HOOK,
			ManagedFlowScheduler::DELIVERY_REMINDER_HOOK,
			ManagedFlowScheduler::DELIVERY_OVERDUE_HOOK,
			Spec4Scheduler::TICK,
			Spec4Scheduler::SWEEP,
			ManagedRetention::HOOK,
			NotificationWorker::HOOK,
		) as $hook ) {
			wp_unschedule_hook( $hook );
		}
		flush_rewrite_rules( false );
	}

	/** @return list<string> */
	private static function dependency_errors(): array {
		$errors = array();

		if ( version_compare( PHP_VERSION, '8.1.0', '<' ) ) {
			$errors[] = __( 'PHP 8.1 یا جدیدتر لازم است.', 'zarbalman-core' );
		}

		if ( version_compare( (string) get_bloginfo( 'version' ), '6.8', '<' ) ) {
			$errors[] = __( 'WordPress 6.8 یا جدیدتر لازم است.', 'zarbalman-core' );
		}

		if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
			$errors[] = __( 'WooCommerce باید فعال باشد.', 'zarbalman-core' );
		} elseif ( version_compare( WC_VERSION, '10.0.0', '<' ) ) {
			$errors[] = __( 'WooCommerce 10.0 یا جدیدتر لازم است.', 'zarbalman-core' );
		}

		return $errors;
	}

	private static function purgePublicCache(): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( 'litespeed_purge_all' );
		}
	}
}

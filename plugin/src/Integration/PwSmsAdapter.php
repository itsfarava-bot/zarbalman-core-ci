<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use RuntimeException;
use Zarbalman\Core\Infrastructure\DependencyHealth;

final class PwSmsAdapter {
	public function __construct( private readonly DependencyHealth $health ) {}

	public function register(): void {
		add_action( 'wp_loaded', array( $this, 'scopeAutomaticOrderMessages' ), 100 );
	}

	/** Preserve PWSMS for ordinary orders and explicit manual sends. Managed
	 * automatic order notifications belong to the canonical request outbox.
	 * Callback names/signatures are from the supplied PWSMS Orders.php. */
	public function scopeAutomaticOrderMessages(): void {
		global $wp_filter;
		foreach ( array( 'woocommerce_order_status_changed', 'woocommerce_checkout_order_processed', 'woocommerce_process_shop_order_meta' ) as $hook ) {
			$registered = $wp_filter[ $hook ] ?? null;
			if ( ! $registered instanceof \WP_Hook ) { continue; }
			foreach ( $registered->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback_id => $entry ) {
					$callback = $entry['function'];
					if ( ! is_array( $callback ) || 2 !== count( $callback ) || ! is_object( $callback[0] ) || ! is_a( $callback[0], 'PW\\PWSMS\\Orders' ) || 'send_order_sms' !== $callback[1] ) { continue; }
					// Keep the original callback ID, priority and accepted_args. PWSMS
					// removes its own callbacks on resume and after a status change;
					// re-registering this closure under a new ID would defeat those guards.
					$registered->callbacks[ $priority ][ $callback_id ]['function'] = static function ( ...$args ) use ( $callback, $hook ): void {
						$order_id = (int) ( $args[0] ?? 0 );
						$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
						$parent = $order instanceof \WC_Order && $order->get_parent_id() > 0 ? wc_get_order( $order->get_parent_id() ) : false;
						$managed = ( $order instanceof \WC_Order && 'yes' === $order->get_meta( '_zbm_managed_order', true ) ) || ( $parent instanceof \WC_Order && 'yes' === $parent->get_meta( '_zbm_managed_order', true ) );
						$manual = 'woocommerce_process_shop_order_meta' === $hook && is_admin() && ! empty( $_POST['sms_order_send'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo/PWSMS own this existing manual action.
						if ( ! $managed || $manual ) { call_user_func_array( $callback, $args ); }
					};
				}
			}
		}
	}

	public function send( string $mobile, string $message, int $order_id = 0 ): void {
		if ( $this->health->notificationError() ) {
			throw new RuntimeException( 'The pinned SMS integration is unavailable.' );
		}
		if ( ! function_exists( 'PWSMS' ) || ! is_object( PWSMS() ) || ! method_exists( PWSMS(), 'send_sms' ) ) {
			throw new RuntimeException( 'SMS service is unavailable.' );
		}
		if ( '' === $mobile || ( method_exists( PWSMS(), 'validate_mobile' ) && ! PWSMS()->validate_mobile( $mobile ) ) ) {
			throw new RuntimeException( 'SMS recipient is unavailable.' );
		}
		$result = PWSMS()->send_sms(
			array(
				'post_id' => $order_id,
				'type'    => 0,
				'mobile'  => $mobile,
				'message' => $message,
			)
		);
		if ( true !== $result ) {
			throw new RuntimeException( 'SMS provider rejected the message.' );
		}
	}
}

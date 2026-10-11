<?php

declare(strict_types=1);

namespace Zarbalman\Core\Frontend;

/** Private response boundaries; markers never authorize or change a request. */
final class RequestResponse {
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'prepare' ), -100 );
	}

	public function prepare(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if (
			( function_exists( 'is_product' ) && is_product() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() )
			|| null !== get_query_var( 'purchase-requests', null )
			|| is_page( array( 'order-review-by-buyer', 'approval-by-supplier' ) )
		) {
			self::preventCaching();
		}
	}

	public static function preventCaching(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		// WordPress >= 6.8 (the plugin's minimum) includes private and no-store.
		// WordPress also safely skips header writes when output has already begun.
		nocache_headers();
		// LiteSpeed Cache must be told through its public control hook as well;
		// this does not modify or depend on LiteSpeed plugin files.
		if ( function_exists( 'do_action' ) ) {
			do_action( 'litespeed_control_set_nocache', 'zarbalman-private-request-surface' );
		}
	}

	public static function freshUrl( string $url ): string {
		return add_query_arg( '_zbm_response', wp_generate_uuid4(), $url );
	}

	/** Keep native nonce verification/hooks while allowing an actionable error response. */
	public static function verifyFormNonce( mixed $value, string $action ): bool {
		$nonce = is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
		$result = '' !== $nonce ? wp_verify_nonce( $nonce, $action ) : false;
		do_action( 'check_admin_referer', $action, $result );
		return (bool) $result;
	}

	public static function redirect( string $url ): never {
		self::preventCaching();
		wp_safe_redirect( self::freshUrl( $url ), 303 );
		exit;
	}
}

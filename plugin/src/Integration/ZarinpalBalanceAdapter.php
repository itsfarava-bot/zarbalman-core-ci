<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use RuntimeException;
use Zarbalman\Core\Infrastructure\OnlinePaymentAvailability;

/** Uses the production API contract in the supplied Zarinpal 5.0.17 source. */
final class ZarinpalBalanceAdapter {
	private const ENDPOINT = 'https://payment.zarinpal.com/pg/v4/payment/';
	public function __construct( private readonly OnlinePaymentAvailability $availability ) {}

	public function fingerprint(): string { return hash( 'sha256', 'zarinpal-production|' . $this->merchant() ); }

	/** Only explicit provider rejection means no Authority was created. Network failures are unknown. */
	public function issue( array $intent, string $callback ): array {
		try {
			if ( ! hash_equals( (string) $intent['merchant_fingerprint'], $this->fingerprint() ) ) { return array( 'status' => 'unknown' ); }
			$response = $this->post( 'request.json', array( 'merchant_id' => $this->merchant(), 'amount' => self::rial( (int) $intent['expected_amount_toman'] ),
				'callback_url' => $callback, 'description' => 'Zarbalman balance #' . (int) $intent['request_id'] ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { $this->availability->observe( false ); return array( 'status' => 'unknown' ); }
			$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true, 32, JSON_THROW_ON_ERROR );
			$data = is_array( $decoded['data'] ?? null ) ? $decoded['data'] : array();
			if ( in_array( $data['code'] ?? null, array( 100, '100' ), true ) && is_string( $data['authority'] ?? null ) && preg_match( '/\A[A-Za-z0-9]{20,128}\z/', $data['authority'] ) ) {
				$this->availability->observe( true ); return array( 'status' => 'issued', 'authority' => $data['authority'] );
			}
			$this->availability->observe( false );
			$code = $decoded['errors']['code'] ?? null;
			return array( 'status' => empty( $data ) && ( is_int( $code ) || is_string( $code ) && preg_match( '/\A-[0-9]+\z/', $code ) ) && (int) $code < 0 ? 'rejected' : 'unknown' );
		} catch ( \Throwable ) { $this->availability->observe( false ); return array( 'status' => 'unknown' ); }
	}

	/** New-link availability and admin stop deliberately do not disable verification. */
	public function verify( array $intent ): ZarinpalVerificationResponse {
		try {
			if ( ! hash_equals( (string) $intent['merchant_fingerprint'], $this->fingerprint() ) || ! preg_match( '/\A[A-Za-z0-9]{20,128}\z/', (string) $intent['authority'] ) ) { return ZarinpalVerificationResponse::fromHttp( 0, '' ); }
			$response = $this->post( 'verify.json', array( 'merchant_id' => $this->merchant(), 'authority' => $intent['authority'], 'amount' => self::rial( (int) $intent['expected_amount_toman'] ) ) );
			return is_wp_error( $response ) ? ZarinpalVerificationResponse::fromHttp( 0, '' ) : ZarinpalVerificationResponse::fromHttp( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ) );
		} catch ( \Throwable ) { return ZarinpalVerificationResponse::fromHttp( 0, '' ); }
	}
	public static function url( string $authority ): string {
		if ( ! preg_match( '/\A[A-Za-z0-9]{20,128}\z/', $authority ) ) { throw new RuntimeException( 'Invalid payment URL authority.' ); }
		return 'https://payment.zarinpal.com/pg/StartPay/' . $authority;
	}
	private function post( string $path, array $body ): array|\WP_Error {
		return wp_remote_post( self::ENDPOINT . $path, array( 'body' => wp_json_encode( $body, JSON_THROW_ON_ERROR ), 'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
			'timeout' => 15, 'redirection' => 0, 'reject_unsafe_urls' => true, 'limit_response_size' => 65536, 'data_format' => 'body' ) );
	}
	private function merchant(): string {
		$settings = get_option( 'woocommerce_WC_ZPal_settings', array() );
		if ( ! is_array( $settings ) || ! is_string( $settings['merchantcode'] ?? null ) || '' === trim( $settings['merchantcode'] ) || 'merchant' !== ( $settings['fee_payer'] ?? null ) || 'yes' === ( $settings['sandbox'] ?? null ) ) { throw new RuntimeException( 'Provider verification settings unavailable.' ); }
		return trim( $settings['merchantcode'] );
	}
	private static function rial( int $toman ): int { if ( $toman < 1 || $toman > intdiv( PHP_INT_MAX, 10 ) ) { throw new RuntimeException( 'Invalid payment amount.' ); } return $toman * 10; }
}

<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use WC_Order;
use WP_Error;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Infrastructure\DependencyHealth;

final class SecureZarinpalVerifier {
	public function __construct( private readonly DependencyHealth $health ) {}

	/** @return array{authority:string,reference:string}|WP_Error */
	public function verifyCapturedDeposit( PurchaseRequest $request ): array|WP_Error {
		$dependency_error = $this->health->paymentVerificationError();
		if ( $dependency_error ) {
			return $dependency_error;
		}
		$order = $request->wcOrderId() ? wc_get_order( $request->wcOrderId() ) : false;
		if (
			! $order instanceof WC_Order
			|| ! ManagedOrderSnapshot::matches( $order, $request )
			|| ! class_exists( '\\ZarinpalHelperClass' )
			|| $request->depositToman() > intdiv( PHP_INT_MAX, 10 )
		) {
			return new WP_Error( 'zbm_zarinpal_reverify_invalid', __( 'سفارش برای استعلام مجدد زرین‌پال معتبر نیست.', 'zarbalman-core' ) );
		}
		$reference = trim( (string) $order->get_transaction_id() );
		if ( '' === $reference || strlen( $reference ) > 128 ) {
			return new WP_Error( 'zbm_zarinpal_reverify_missing_reference', __( 'کد رهگیری پرداخت برای استعلام مجدد موجود نیست.', 'zarbalman-core' ) );
		}
		$candidates = $this->knownAuthorities( $order );

		return $this->verifyCandidates( $request, $candidates, $reference );
	}

	/**
	 * Performs Core's own provider verification during the normal callback.
	 * This deliberately does not trust a generic payment_complete invocation,
	 * even when the dependency callback query parameters look correct.
	 *
	 * @return array{authority:string,reference:string}|WP_Error
	 */
	public function verifyDepositCompletion( PurchaseRequest $request, string $authority, string $reference ): array|WP_Error {
		$dependency_error = $this->health->paymentVerificationError();
		if ( $dependency_error ) {
			return $dependency_error;
		}
		$order = $request->wcOrderId() ? wc_get_order( $request->wcOrderId() ) : false;
		$authority = trim( $authority );
		$reference = trim( $reference );
		if (
			! $order instanceof WC_Order
			|| ! ManagedOrderSnapshot::matches( $order, $request )
			|| '' === $authority
			|| '' === $reference
			|| strlen( $authority ) > 128
			|| strlen( $reference ) > 128
			|| ! $this->authorityBelongsToOrder( $order, $authority )
		) {
			return new WP_Error( 'zbm_zarinpal_callback_invalid', __( 'هویت پرداخت بازگشتی زرین‌پال معتبر نیست.', 'zarbalman-core' ) );
		}

		return $this->verifyCandidates( $request, array( $authority ), $reference );
	}

	/**
	 * Reconciles a callback whose Authority was persisted before the provider
	 * verify call. Zarinpal's verified response is the only source of RefID.
	 *
	 * @return array{authority:string,reference:string}|WP_Error
	 */
	public function verifyReceivedCallback( PurchaseRequest $request, string $authority ): array|WP_Error {
		$dependency_error = $this->health->paymentVerificationError();
		if ( $dependency_error ) {
			return $dependency_error;
		}
		$order = $request->wcOrderId() ? wc_get_order( $request->wcOrderId() ) : false;
		$authority = trim( $authority );
		if (
			! $order instanceof WC_Order
			|| ! ManagedOrderSnapshot::matches( $order, $request )
			|| '' === $authority
			|| strlen( $authority ) > 128
			|| ! $this->authorityBelongsToOrder( $order, $authority )
		) {
			return new WP_Error( 'zbm_zarinpal_callback_invalid', __( 'هویت callback ذخیره‌شده معتبر نیست.', 'zarbalman-core' ) );
		}

		return $this->verifyCandidates( $request, array( $authority ), null );
	}

	/** @param list<string> $candidates
	 *  @return array{authority:string,reference:string}|WP_Error
	 */
	private function verifyCandidates( PurchaseRequest $request, array $candidates, ?string $reference ): array|WP_Error {
		if ( ! class_exists( '\\ZarinpalHelperClass' ) || $request->depositToman() > intdiv( PHP_INT_MAX, 10 ) ) {
			return new WP_Error( 'zbm_zarinpal_reverify_invalid', __( 'سفارش برای استعلام مجدد زرین‌پال معتبر نیست.', 'zarbalman-core' ) );
		}
		$settings = get_option( 'woocommerce_WC_ZPal_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$merchant = trim( (string) ( $settings['merchantcode'] ?? '' ) );
		if ( '' === $merchant || 'merchant' !== (string) ( $settings['fee_payer'] ?? '' ) || 'yes' === (string) ( $settings['sandbox'] ?? '' ) ) {
			return new WP_Error( 'zbm_zarinpal_reverify_settings', __( 'تنظیمات امن زرین‌پال برای استعلام مجدد کامل نیست.', 'zarbalman-core' ) );
		}
		$candidates = array_slice( array_values( array_unique( array_filter( $candidates, static fn ( string $value ): bool => strlen( $value ) <= 128 ) ) ), 0, 10 );
		$amount_rial       = $request->depositToman() * 10;
		$provider_rejected = false;
		$provider_unclear  = false;
		foreach ( $candidates as $authority ) {
			$response = $this->requestVerification(
				$merchant,
				false,
				$authority,
				$amount_rial
			);
			if ( $response->isUnavailable() ) {
				$provider_unclear = true;
				continue;
			}
			if ( $response->isRejected() ) {
				$provider_rejected = true;
				continue;
			}
			$verified_reference = $response->reference();
			if ( null !== $verified_reference && ( null === $reference || hash_equals( $reference, $verified_reference ) ) ) {
				return array( 'authority' => $authority, 'reference' => $verified_reference );
			}
			$provider_rejected = true;
		}
		if ( $provider_unclear || ! $provider_rejected ) {
			return new WP_Error( 'zbm_zarinpal_reverify_unavailable', __( 'پاسخ معتبر فنی از زرین‌پال دریافت نشد.', 'zarbalman-core' ) );
		}

		return new WP_Error( 'zbm_zarinpal_reverify_failed', __( 'زرین‌پال پرداخت قطعیِ منطبق با مبلغ و کد رهگیری سفارش را تأیید نکرد.', 'zarbalman-core' ) );
	}

	private function requestVerification( string $merchant, bool $sandbox, string $authority, int $amount_rial ): ZarinpalVerificationResponse {
		$url = ( $sandbox
			? 'https://sandbox.zarinpal.com/pg/v4/payment/'
			: 'https://payment.zarinpal.com/pg/v4/payment/'
		) . 'verify.json';
		$body = wp_json_encode(
			array(
				'merchant_id' => $merchant,
				'authority'   => $authority,
				'amount'      => $amount_rial,
			)
		);
		if ( ! is_string( $body ) ) {
			return ZarinpalVerificationResponse::fromHttp( 0, '' );
		}
		$response = wp_remote_post(
			$url,
			array(
				'body'                => $body,
				'headers'             => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'User-Agent'   => 'Zarbalman-Core/' . ( defined( 'ZBM_CORE_VERSION' ) ? ZBM_CORE_VERSION : 'unknown' ),
				),
				'timeout'             => 15,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => 65536,
				'data_format'         => 'body',
			)
		);
		if ( is_wp_error( $response ) ) {
			return ZarinpalVerificationResponse::fromHttp( 0, '' );
		}
		return ZarinpalVerificationResponse::fromHttp(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response )
		);
	}

	/** @return list<string> */
	private function knownAuthorities( WC_Order $order ): array {
		$candidates = array();
		$current = (string) $order->get_meta( '_zarinpal_authority', true );
		if ( '' !== trim( $current ) ) {
			$candidates[] = trim( $current );
		}
		$history = $order->get_meta( '_zarinpal_authority_history', true );
		if ( is_array( $history ) ) {
			foreach ( array_reverse( $history ) as $authority ) {
				if ( is_string( $authority ) && '' !== trim( $authority ) ) {
					$candidates[] = trim( $authority );
				}
			}
		}
		return $candidates;
	}

	private function authorityBelongsToOrder( WC_Order $order, string $authority ): bool {
		$current = trim( (string) $order->get_meta( '_zarinpal_authority', true ) );
		if ( '' !== $current && hash_equals( $current, $authority ) ) {
			return true;
		}
		$history = $order->get_meta( '_zarinpal_authority_history', true );
		if ( ! is_array( $history ) ) {
			return false;
		}
		foreach ( $history as $candidate ) {
			if ( is_string( $candidate ) && hash_equals( trim( $candidate ), $authority ) ) {
				return true;
			}
		}
		return false;
	}
}

<?php

declare(strict_types=1);

namespace Zarbalman\Core\Http;

use Zarbalman\Core\Application\BalancePaymentService;
use Zarbalman\Core\Frontend\{BuyerRequestEndpoint, ManagedCasePanel, RequestResponse};
use Zarbalman\Core\Infrastructure\{BalancePaymentRepository, PurchaseRequestRepository};

final class BalancePaymentController {
	public function __construct( private readonly BalancePaymentService $service, private readonly BalancePaymentRepository $payments, private readonly PurchaseRequestRepository $requests ) {}
	public function register(): void {
		add_action( 'admin_post_zbm_balance_start', array( $this, 'start' ) );
		add_action( 'admin_post_zbm_balance_callback', array( $this, 'callback' ) );
		add_action( 'admin_post_nopriv_zbm_balance_callback', array( $this, 'callback' ) );
	}
	public function start(): never {
		RequestResponse::preventCaching();
		$id = self::integer( $_POST['request_id'] ?? null ); $version = self::integer( $_POST['version'] ?? null );
		$key = is_string( $_POST['idempotency_key'] ?? null ) ? wp_unslash( $_POST['idempotency_key'] ) : '';
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_user_logged_in() || ! $id || null === $version || ! RequestResponse::verifyFormNonce( $_POST['_zbm_nonce'] ?? null, 'zbm_managed_case_' . $id ) ) { wp_die( esc_html__( 'اعتبار فرم پایان یافته است؛ صفحه را تازه کنید.', 'zarbalman-core' ), '', array( 'response' => 403 ) ); }
		$result = $this->service->start( $id, $version, get_current_user_id(), $key );
		if ( ! is_wp_error( $result ) ) {
			// The application constructs this fixed-host HTTPS URL from stored Authority.
			wp_redirect( $result, 303, 'Zarbalman Core' ); exit;
		}
		ManagedCasePanel::storeNotice( get_current_user_id(), array( 'type' => 'error', 'message' => $result->get_error_message() ) );
		RequestResponse::redirect( $this->buyerUrl( $id ) );
	}
	public function callback(): never {
		RequestResponse::preventCaching();
		$id = self::integer( $_GET['payment'] ?? null );
		$offer = is_string( $_GET['offer'] ?? null ) ? wp_unslash( $_GET['offer'] ) : '';
		$authority = is_string( $_GET['Authority'] ?? null ) ? wp_unslash( $_GET['Authority'] ) : '';
		if ( ! $id || ! wp_is_uuid( $offer ) || ! preg_match( '/\A[A-Za-z0-9]{20,128}\z/', $authority ) ) { wp_die( esc_html__( 'بازگشت پرداخت معتبر نیست.', 'zarbalman-core' ), '', array( 'response' => 400 ) ); }
		$result = $this->service->callback( $id, $offer, $authority );
		try { $row = $this->payments->get( $id ); $request = $row ? $this->requests->find( (int) $row['request_id'] ) : null; }
		catch ( \Throwable ) { $request = null; }
		$owned = $request && get_current_user_id() === $request->buyerId();
		if ( $owned ) {
			$paid = ! is_wp_error( $result ) && ( $result['data']['funds']['balance']['amount'] ?? 0 ) > 0;
			ManagedCasePanel::storeNotice( get_current_user_id(), array( 'type' => $paid ? 'success' : 'error', 'message' => $paid
				? __( 'پرداخت مانده با موفقیت تأیید شد. نتیجهٔ نهایی در همین پرونده ثبت شده است.', 'zarbalman-core' )
				: __( 'نتیجهٔ پرداخت مانده نیازمند بررسی است. تا روشن‌شدن نتیجه دوباره واریز نکنید.', 'zarbalman-core' ) ) );
		}
		RequestResponse::redirect( $this->buyerUrl( $owned ? $request->id() : 0 ) );
	}
	private function buyerUrl( int $id ): string {
		$base = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( BuyerRequestEndpoint::ENDPOINT ) : home_url( '/order-review-by-buyer/' );
		return $id > 0 ? add_query_arg( 'request', $id, $base ) : $base;
	}
	private static function integer( mixed $value ): ?int {
		if ( ! is_string( $value ) || ! preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) || strlen( $value ) > strlen( (string) PHP_INT_MAX ) || strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) > 0 ) { return null; }
		return (int) $value;
	}
}

<?php

declare(strict_types=1);

namespace Zarbalman\Core\Frontend;

use RuntimeException;
use WC_Order;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;
use Zarbalman\Core\Infrastructure\DeliveryDataCrypto;
use Zarbalman\Core\Infrastructure\DependencyHealth;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;
use Zarbalman\Core\Integration\ManagedOrderSnapshot;

final class BuyerRequestEndpoint {
	public const ENDPOINT = 'purchase-requests';

	public function __construct(
		private readonly PurchaseRequestRepository $repository,
		private readonly DeliveryDataCrypto $crypto,
		private readonly RequestPresenter $presenter,
		private readonly DependencyHealth $health,
		private readonly ManagedCasePanel $managed
	) {}

	public function register(): void {
		add_action( 'init', array( $this, 'rewrite' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'renderAccount' ) );
		add_shortcode( 'zbm_buyer_requests', array( $this, 'shortcode' ) );
	}

	public function rewrite(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/** @param array<string,string> $items
	 *  @return array<string,string>
	 */
	public function menu( array $items ): array {
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items[ self::ENDPOINT ] = __( 'درخواست‌ها و سفارش‌های من', 'zarbalman-core' );
		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	public function renderAccount(): void {
		echo $this->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Templates escape all dynamic values.
	}

	public function shortcode(): string {
		return $this->render();
	}

	private function render(): string {
		RequestResponse::preventCaching();
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'برای مشاهده درخواست‌ها وارد حساب خود شوید.', 'zarbalman-core' ) . '</p>';
		}
		$buyer_id = get_current_user_id();
		$base_url = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( self::ENDPOINT ) : home_url( '/order-review-by-buyer/' );
		$base_url = RequestResponse::freshUrl( remove_query_arg( 'request', $base_url ) );
		$request_id = isset( $_GET['request'] ) ? absint( $_GET['request'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only owner-gated view.
		ob_start();
		BuyerRequestController::printRequestNotice();
		if ( $request_id > 0 ) {
			$request = $this->repository->findForBuyerDashboard( $request_id, $buyer_id );
			if ( ! $request ) {
				echo $this->renderNotFound( $base_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Method escapes its output.
				return (string) ob_get_clean();
			}
			if ( isset( $_GET['zbm_created'] ) && '1' === (string) $_GET['zbm_created'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice after authoritative ownership succeeds.
				echo '<div class="zbm-alert zbm-alert-success zbm-created-request" role="status"><div class="zbm-created-request-copy"><strong>' . esc_html__( 'درخواست شما با موفقیت برای فروشنده ارسال شد.', 'zarbalman-core' ) . '</strong><p>' . esc_html__( 'فروشنده حداکثر دو روز فرصت دارد درخواست شما را تأیید یا رد کند. لطفاً تا اعلام نتیجه منتظر بمانید. در صورت تأیید، مرحلهٔ پرداخت و لینک پیش‌پرداخت نیز در همین بخش نمایش داده می‌شود.', 'zarbalman-core' ) . '</p></div></div>';
			}
			try {
				$delivery = $this->crypto->decrypt( $request->encryptedDeliveryPayload() );
			} catch ( RuntimeException ) {
				$delivery = array( 'recipient_name' => __( 'اطلاعات امن در دسترس نیست', 'zarbalman-core' ), 'postal_code' => null, 'address' => null );
			}
			$view = $this->presenter->present( $request );
			$view['estimated_total_with_shipping'] = $this->presenter->estimatedGrandTotal( $request, $delivery );
			$payment_url = null;
			if ( RequestState::DEPOSIT_PENDING === $request->state() && $request->paymentReady() && ! $request->isPaused() && $request->wcOrderId() ) {
				$order = wc_get_order( $request->wcOrderId() );
				if (
					$order instanceof WC_Order
					&& $this->health->canCreateDepositOrder()
					&& ManagedOrderSnapshot::matches( $order, $request )
					&& (int) $order->get_customer_id() === $buyer_id
					&& ! $order->is_paid()
					&& 'yes' === $order->get_meta( '_zbm_payment_enabled', true )
					&& null !== $request->depositDeadlineGmt()
					&& $request->depositDeadlineGmt() > current_time( 'mysql', true )
				) {
					$payment_url = $order->get_checkout_payment_url();
				}
			}
			$managed_panel = $this->managed->render( $request, 'buyer', $buyer_id, is_string( $payment_url ) ? $payment_url : null );
			$back_url = $base_url;
			include ZBM_CORE_DIR . 'templates/buyer/request-detail.php';
		} else {
			$requests = array_map( fn ( $request ): array => $this->presenter->present( $request ), $this->repository->forBuyer( $buyer_id ) );
			$detail_base_url = $base_url;
			$shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/products/' );
			include ZBM_CORE_DIR . 'templates/buyer/request-list.php';
		}
		return (string) ob_get_clean();
	}

	private function renderNotFound( string $back_url ): string {
		return '<div class="zbm-empty-state zbm-not-found" role="status"><span class="zbm-empty-state-icon" aria-hidden="true">⌕</span><h3>' .
			esc_html__( 'فعلاً خبری نیست', 'zarbalman-core' ) . '</h3><p>' .
			esc_html__( 'این درخواست پیدا نشد یا دیگر در دسترس نیست. از همین‌جا می‌توانید درخواست‌های فعال خود را ببینید.', 'zarbalman-core' ) .
			'</p><a class="button" href="' . esc_url( $back_url ) . '">' . esc_html__( 'بازگشت به درخواست‌ها', 'zarbalman-core' ) . '</a></div>';
	}
}

<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use RuntimeException;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;
use Zarbalman\Core\Frontend\RequestPresenter;
use Zarbalman\Core\Frontend\RequestResponse;
use Zarbalman\Core\Frontend\ManagedCasePanel;
use Zarbalman\Core\Infrastructure\DeliveryDataCrypto;
use Zarbalman\Core\Infrastructure\DependencyHealth;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;

final class DokanPurchaseRequestEndpoint {
	public const ENDPOINT = 'purchase-requests';

	public function __construct(
		private readonly PurchaseRequestRepository $repository,
		private readonly DeliveryDataCrypto $crypto,
		private readonly RequestPresenter $presenter,
		private readonly DependencyHealth $health,
		private readonly DokanVendorEligibility $vendor_eligibility,
		private readonly ManagedCasePanel $managed
	) {}

	public function register(): void {
		add_filter( 'dokan_query_var_filter', array( $this, 'queryVar' ) );
		add_filter( 'dokan_get_dashboard_nav', array( $this, 'navigation' ), 12 );
		add_action( 'dokan_load_custom_template', array( $this, 'loadTemplate' ), 12 );
		add_shortcode( 'zbm_supplier_requests', array( $this, 'shortcode' ) );
	}

	/** @param list<string> $vars
	 *  @return list<string>
	 */
	public function queryVar( array $vars ): array {
		$vars[] = self::ENDPOINT;
		return array_values( array_unique( $vars ) );
	}

	/** @param array<string,mixed> $menus
	 *  @return array<string,mixed>
	 */
	public function navigation( array $menus ): array {
		if ( ! $this->vendor_eligibility->isApprovedVendor( get_current_user_id() ) ) {
			unset( $menus[ self::ENDPOINT ] );
			return $menus;
		}
		$menus[ self::ENDPOINT ] = array(
			'title' => __( 'درخواست‌های خرید', 'zarbalman-core' ),
			'icon'  => '<i class="fas fa-clipboard-check"></i>',
			'url'   => function_exists( 'dokan_get_navigation_url' ) ? dokan_get_navigation_url( self::ENDPOINT ) : home_url( '/approval-by-supplier/' ),
			'pos'   => 31,
		);
		return $menus;
	}

	/** @param array<string,mixed> $query_vars */
	public function loadTemplate( array $query_vars ): void {
		if ( isset( $query_vars[ self::ENDPOINT ] ) ) {
			echo $this->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Templates escape values.
		}
	}

	public function shortcode(): string {
		return $this->render();
	}

	private function render(): string {
		RequestResponse::preventCaching();
		if ( ! $this->health->canUseDokanBridge() ) {
			return '<p>' . esc_html__( 'نمای درخواست‌های فروشنده تا بازگرداندن نسخه تأییدشده دکان موقتاً متوقف است.', 'zarbalman-core' ) . '</p>';
		}
		$vendor_id = get_current_user_id();
		if ( ! $this->vendor_eligibility->isApprovedVendor( $vendor_id ) ) {
			return '<div class="zbm-alert zbm-alert-warning" role="status"><strong>'
				. esc_html__( 'حساب شما در انتظار تأیید مدیر است.', 'zarbalman-core' )
				. '</strong><p>'
				. esc_html__( 'تا پیش از فعال‌شدن امکان فروش در Dokan، درخواست خرید یا عملیات فروش در این بخش نمایش داده نمی‌شود.', 'zarbalman-core' )
				. '</p></div>';
		}
		$base_url = function_exists( 'dokan_get_navigation_url' ) ? dokan_get_navigation_url( self::ENDPOINT ) : home_url( '/approval-by-supplier/' );
		$base_url = RequestResponse::freshUrl( remove_query_arg( 'request', $base_url ) );
		$dashboard_url = function_exists( 'dokan_get_navigation_url' )
			? RequestResponse::freshUrl( remove_query_arg( 'request', dokan_get_navigation_url( '' ) ) )
			: home_url( '/' );
		$request_id = isset( $_GET['request'] ) ? absint( $_GET['request'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only owner-gated view.
		$notice = get_transient( 'zbm_vendor_notice_' . $vendor_id );
		if ( is_array( $notice ) ) {
			delete_transient( 'zbm_vendor_notice_' . $vendor_id );
		}
		ob_start();
		// Show only the current user's generic flash before any early return.
		// Request data below remains behind the unchanged ownership check.
		if ( is_array( $notice ) ) {
			echo '<div class="zbm-alert zbm-alert-' . esc_attr( 'success' === ( $notice['type'] ?? '' ) ? 'success' : 'danger' ) . '" role="status" tabindex="-1" data-zbm-request-feedback>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</div>';
		}
		if ( $request_id > 0 ) {
			$request = $this->repository->findForVendorDashboard( $request_id, $vendor_id );
			if ( ! $request ) {
				echo $this->renderNotFound( $base_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Method escapes its output.
				return (string) ob_get_clean();
			}
			try {
				$delivery = RequestState::isTerminal( $request->state() )
					? array( 'recipient_name' => __( 'اطلاعات تحویل پس از پایان درخواست پنهان شد', 'zarbalman-core' ), 'postal_code' => null, 'address' => null )
					: $this->crypto->decrypt( $request->encryptedDeliveryPayload() );
			} catch ( RuntimeException ) {
				$delivery = array( 'recipient_name' => __( 'اطلاعات امن در دسترس نیست', 'zarbalman-core' ), 'postal_code' => null, 'address' => null );
			}
			// Project operational delivery facts before the vendor template receives
			// them; never expose shipping amounts or buyer contact/bank fields.
			$delivery = array_intersect_key( $delivery, array_flip( array( 'recipient_name', 'postal_code', 'address', 'province_name', 'pickup_address', 'pickup_note' ) ) );
			if ( is_array( $delivery['pickup_address'] ?? null ) ) {
				$delivery['pickup_address'] = array_intersect_key( $delivery['pickup_address'], array_flip( array( 'store_name', 'street_1', 'zip' ) ) );
			}
			$view = $this->presenter->present( $request, false );
			$can_decide = RequestState::SUPPLIER_REVIEW === $request->state()
				&& ! $request->isPaused()
				&& null !== $request->supplierDeadlineGmt()
				&& $request->supplierDeadlineGmt() > current_time( 'mysql', true );
			$back_url = $base_url;
			$accept_key = wp_generate_uuid4();
			$reject_key = wp_generate_uuid4();
			$managed_panel = $this->managed->render( $request, 'vendor', $vendor_id );
			include ZBM_CORE_DIR . 'templates/vendor/request-detail.php';
		} else {
			$requests = array_map( fn ( $request ): array => $this->presenter->present( $request, false ), $this->repository->forVendor( $vendor_id ) );
			$detail_base_url = $base_url;
			$products_url = function_exists( 'dokan_get_navigation_url' ) ? dokan_get_navigation_url( 'products' ) : home_url( '/products/' );
			include ZBM_CORE_DIR . 'templates/vendor/request-list.php';
		}
		return (string) ob_get_clean();
	}

	private function renderNotFound( string $back_url ): string {
		return '<div class="zbm-empty-state zbm-not-found" role="status"><span class="zbm-empty-state-icon" aria-hidden="true">⌕</span><h3>' .
			esc_html__( 'فعلاً خبری نیست', 'zarbalman-core' ) . '</h3><p>' .
			esc_html__( 'این درخواست پیدا نشد یا دسترسی به آن برای شما مجاز نیست.', 'zarbalman-core' ) .
			'</p><a class="dokan-btn dokan-btn-theme" href="' . esc_url( $back_url ) . '">' . esc_html__( 'بازگشت به درخواست‌ها', 'zarbalman-core' ) . '</a></div>';
	}
}

<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use Zarbalman\Core\Domain\Shipping\IranProvinceCatalog;
use Zarbalman\Core\Frontend\RequestResponse;
use Zarbalman\Core\Infrastructure\VendorShippingRateRepository;
use Zarbalman\Core\Infrastructure\VendorShippingRateLock;

/** Provides approved Dokan vendors a private dashboard for province shipping rates. */
final class DokanShippingRateSettings {
	public const ENDPOINT = 'zbm-shipping-rates';
	private const POST_ACTION = 'zbm_save_vendor_shipping_rate';
	private const NONCE_ACTION = 'zbm_save_vendor_shipping_rate';
	private const NONCE_FIELD = '_zbm_shipping_rate_nonce';
	private const NOTICE_KEY = 'zbm_vendor_shipping_rate_notice_';

	public function __construct(
		private readonly DokanVendorEligibility $eligibility,
		private readonly VendorShippingRateRepository $rates,
		private readonly VendorShippingRateLock $lock
	) {}

	public function register(): void {
		add_filter( 'dokan_query_var_filter', array( $this, 'queryVar' ) );
		add_filter( 'dokan_get_dashboard_nav', array( $this, 'navigation' ), 12 );
		add_action( 'dokan_load_custom_template', array( $this, 'loadTemplate' ), 12 );
		add_action( 'admin_post_' . self::POST_ACTION, array( $this, 'save' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	/** @param list<string> $vars @return list<string> */
	public function queryVar( array $vars ): array {
		$vars[] = self::ENDPOINT;
		return array_values( array_unique( $vars ) );
	}

	/** @param array<string,mixed> $menus @return array<string,mixed> */
	public function navigation( array $menus ): array {
		if ( ! $this->eligibility->isApprovedVendor( get_current_user_id() ) ) {
			unset( $menus[ self::ENDPOINT ] );
			return $menus;
		}

		$menus[ self::ENDPOINT ] = array(
			'title' => __( 'نرخ ارسال استانی', 'zarbalman-core' ),
			'icon'  => '<i class="fas fa-shipping-fast"></i>',
			'url'   => $this->endpointUrl(),
			'pos'   => 32,
		);
		return $menus;
	}

	/** @param array<string,mixed> $query_vars */
	public function loadTemplate( array $query_vars ): void {
		if ( isset( $query_vars[ self::ENDPOINT ] ) ) {
			echo $this->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template escapes its values.
		}
	}

	public function assets(): void {
		if ( ! function_exists( 'get_query_var' ) || null === get_query_var( self::ENDPOINT, null ) ) {
			return;
		}
		wp_enqueue_style( 'zbm-vendor-shipping-rates', plugins_url( 'assets/css/purchase-requests.css', ZBM_CORE_FILE ), array(), ZBM_CORE_VERSION );
		wp_enqueue_script( 'zbm-vendor-shipping-rates', plugins_url( 'assets/js/vendor-shipping-rates.js', ZBM_CORE_FILE ), array(), ZBM_CORE_VERSION, true );
	}

	public function save(): never {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '';
		if ( 'POST' !== $request_method ) {
			wp_die( esc_html__( 'روش درخواست مجاز نیست.', 'zarbalman-core' ), '', array( 'response' => 405 ) );
		}
		RequestResponse::preventCaching();
		$vendor_id = get_current_user_id();
		if ( $vendor_id < 1 || ! $this->eligibility->isApprovedVendor( $vendor_id ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز است.', 'zarbalman-core' ), '', array( 'response' => 403 ) );
		}
		if ( ! RequestResponse::verifyFormNonce( $this->postScalar( self::NONCE_FIELD ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'درخواست نامعتبر است.', 'zarbalman-core' ), '', array( 'response' => 403 ) );
		}

		$catalog = IranProvinceCatalog::all();
		if ( ! IranProvinceCatalog::isComplete( $catalog ) ) {
			wp_die( esc_html__( 'فهرست استان‌ها کامل در دسترس نیست.', 'zarbalman-core' ), '', array( 'response' => 503 ) );
		}
		$operation = sanitize_key( $this->postScalar( 'operation' ) );
		if ( ! in_array( $operation, array( 'add', 'update', 'delete' ), true ) ) {
			wp_die( esc_html__( 'عملیات نرخ ارسال معتبر نیست.', 'zarbalman-core' ), '', array( 'response' => 400 ) );
		}
		$province = IranProvinceCatalog::find( $catalog, $this->postScalar( 'province_code' ) );
		if ( null === $province ) {
			wp_die( esc_html__( 'استان انتخاب‌شده معتبر نیست.', 'zarbalman-core' ), '', array( 'response' => 400 ) );
		}

		$rate = 'delete' === $operation ? null : VendorShippingRateRepository::normalizeRate( $this->postScalar( 'rate' ) );
		if ( 'delete' !== $operation && null === $rate ) {
			wp_die( esc_html__( 'نرخ باید عددی بین صفر و سقف مجاز باشد.', 'zarbalman-core' ), '', array( 'response' => 400 ) );
		}

		try {
			$notice = $this->lock->synchronized( $vendor_id, function () use ( $vendor_id, $operation, $province, $rate ): array {
				// Eligibility or other earlier hooks may have populated this cache before waiting.
				wp_cache_delete( $vendor_id, 'user_meta' );
				$rates = $this->rates->allRates( $vendor_id );
				$exists = array_key_exists( $province['code'], $rates );
				if ( 'add' === $operation && $exists ) {
					return array( 'type' => 'error', 'message' => __( 'برای این استان قبلاً نرخ ثبت شده است؛ صفحه را تازه کنید و از به‌روزرسانی همان استان استفاده کنید.', 'zarbalman-core' ) );
				}
				if ( 'add' !== $operation && ! $exists ) {
					return array( 'type' => 'error', 'message' => __( 'نرخ این استان دیگر وجود ندارد؛ صفحه را تازه کنید و در صورت نیاز استان را دوباره اضافه کنید.', 'zarbalman-core' ) );
				}
				if ( 'delete' === $operation ) {
					unset( $rates[ $province['code'] ] );
					$message = __( 'نرخ ارسال استان حذف شد.', 'zarbalman-core' );
				} else {
					$rates[ $province['code'] ] = $rate;
					$message = 'add' === $operation
						? __( 'نرخ ارسال استان افزوده شد.', 'zarbalman-core' )
						: __( 'نرخ ارسال استان به‌روزرسانی شد.', 'zarbalman-core' );
				}
				$this->rates->saveRates( $vendor_id, $rates );
				return array( 'type' => 'success', 'message' => $message );
			} );
		} catch ( \Throwable ) {
			$notice = array( 'type' => 'error', 'message' => __( 'ذخیره تغییرات نرخ تأیید نشد؛ صفحه را تازه کنید، نرخ‌های ثبت‌شده را بررسی کنید و دوباره تلاش کنید. اگر خطا ادامه داشت با پشتیبانی تماس بگیرید.', 'zarbalman-core' ) );
		}
		// Never redirect or terminate inside the lock callback: finally must run first.
		set_transient( self::NOTICE_KEY . $vendor_id, $notice, MINUTE_IN_SECONDS );
		RequestResponse::redirect( $this->endpointUrl() );
	}

	private function render(): string {
		RequestResponse::preventCaching();
		$vendor_id = get_current_user_id();
		if ( $vendor_id < 1 || ! $this->eligibility->isApprovedVendor( $vendor_id ) ) {
			return '';
		}
		$catalog = IranProvinceCatalog::all();
		$catalog_complete = IranProvinceCatalog::isComplete( $catalog );
		$supported = $catalog_complete ? $this->rates->supportedProvinces( $vendor_id, $catalog ) : array();
		$stored = $this->rates->allRates( $vendor_id );
		$unregistered = array_diff_key( $catalog, $stored );
		$notice = get_transient( self::NOTICE_KEY . $vendor_id );
		if ( is_array( $notice ) ) {
			delete_transient( self::NOTICE_KEY . $vendor_id );
		}
		$dashboard_url = function_exists( 'dokan_get_navigation_url' ) ? dokan_get_navigation_url( '' ) : home_url( '/' );
		ob_start();
		include ZBM_CORE_DIR . 'templates/vendor/shipping-rates.php';
		return (string) ob_get_clean();
	}

	private function endpointUrl(): string {
		return function_exists( 'dokan_get_navigation_url' )
			? dokan_get_navigation_url( self::ENDPOINT )
			: home_url( '/approval-by-supplier/' . self::ENDPOINT . '/' );
	}

	private function postScalar( string $key ): string {
		$value = $_POST[ $key ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The dedicated nonce is verified before mutating rates.
		return is_scalar( $value ) ? sanitize_text_field( wp_unslash( (string) $value ) ) : '';
	}
}

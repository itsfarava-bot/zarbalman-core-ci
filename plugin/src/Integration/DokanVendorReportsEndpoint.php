<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;
use Zarbalman\Core\Frontend\RequestResponse;
use Zarbalman\Core\Infrastructure\VendorReportsRepository;
use Zarbalman\Core\Support\JalaliDate;

/** Read-only, approved-vendor-only daily and summary reports in Dokan. */
final class DokanVendorReportsEndpoint {
	public const ENDPOINT = 'zbm-vendor-reports';
	private const MAX_RANGE_DAYS = 366;

	public function __construct(
		private readonly DokanVendorEligibility $eligibility,
		private readonly VendorReportsRepository $reports
	) {}

	public function register(): void {
		add_filter( 'dokan_query_var_filter', array( $this, 'queryVar' ) );
		add_filter( 'dokan_get_dashboard_nav', array( $this, 'navigation' ), 12 );
		add_action( 'dokan_load_custom_template', array( $this, 'loadTemplate' ), 12 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'isolateAssets' ), 100 );
		add_action( 'admin_init', array( $this, 'ensureReportIndexes' ) );
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
			'title' => __( 'گزارش فروش', 'zarbalman-core' ),
			'icon'  => '<i class="fas fa-clipboard-list"></i>',
			'url'   => $this->endpointUrl(),
			'pos'   => 33,
		);
		return $menus;
	}

	/** @param array<string,mixed> $query_vars */
	public function loadTemplate( array $query_vars ): void {
		if ( ! isset( $query_vars[ self::ENDPOINT ] ) ) {
			return;
		}

		$vendor_id = get_current_user_id();
		if ( $vendor_id < 1 || ! $this->eligibility->isApprovedVendor( $vendor_id ) ) {
			wp_die( esc_html__( 'دسترسی به این گزارش مجاز نیست.', 'zarbalman-core' ), '', array( 'response' => 403 ) );
		}

		echo $this->render( $vendor_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template escapes values and formats WooCommerce prices safely.
	}

	public function assets(): void {
		if ( ! function_exists( 'get_query_var' ) || null === get_query_var( self::ENDPOINT, null ) ) {
			return;
		}
		wp_enqueue_style( 'zbm-vendor-reports', plugins_url( 'assets/css/vendor-reports.css', ZBM_CORE_FILE ), array(), $this->assetVersion( 'assets/css/vendor-reports.css' ) );
		wp_enqueue_script( 'zbm-vendor-report-jalali', plugins_url( 'assets/js/vendor-report-jalali.js', ZBM_CORE_FILE ), array(), $this->assetVersion( 'assets/js/vendor-report-jalali.js' ), true );
		wp_enqueue_script( 'zbm-vendor-reports', plugins_url( 'assets/js/vendor-reports.js', ZBM_CORE_FILE ), array( 'zbm-vendor-report-jalali' ), $this->assetVersion( 'assets/js/vendor-reports.js' ), true );
	}

	/** The buyer form is registered globally; it has no controls on this Dokan report. */
	public function isolateAssets(): void {
		if ( ! function_exists( 'get_query_var' ) || null === get_query_var( self::ENDPOINT, null ) ) {
			return;
		}
		wp_dequeue_script( 'zbm-request-lines' );
		wp_dequeue_script( 'zbm-purchase-requests' );
		wp_dequeue_style( 'zbm-purchase-requests' );
	}

	/** Add report-only indexes without advancing the Core finance/workflow schema gate. */
	public function ensureReportIndexes(): void {
		if (
			! current_user_can( 'manage_options' )
			|| (string) get_option( 'zbm_core_db_version', '0' ) !== (string) ZBM_CORE_DB_VERSION
			|| '1' === (string) get_option( 'zbm_vendor_report_indexes_ready', '0' )
		) {
			return;
		}
		try {
			$this->reports->ensureReportIndexes();
			update_option( 'zbm_vendor_report_indexes_ready', '1', false );
		} catch ( Throwable ) {
			// Report index creation is additive. Never disable payment or order flows if it fails.
		}
	}

	private function assetVersion( string $relative_path ): string {
		$path = ZBM_CORE_DIR . $relative_path;
		$modified = is_file( $path ) ? filemtime( $path ) : false;
		return ZBM_CORE_VERSION . ( false === $modified ? '' : '.' . $modified );
	}

	private function render( int $vendor_id ): string {
		RequestResponse::preventCaching();
		$timezone = wp_timezone();
		$filter = $this->dateRange( $timezone );
		$daily = null;
		$report_error = false;
		$currency_code = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : '';
		$currency_supported = 'IRT' === $currency_code && function_exists( 'wc_price' );

		if ( null === $filter['error'] ) {
			$days = $this->calendarDays( $filter['start'], $filter['end_exclusive'] );
			try {
				$daily = $this->reports->dailyBuyerPayments( $vendor_id, $days );
			} catch ( Throwable ) {
				$report_error = true;
			}
		}

		$dashboard_url = function_exists( 'dokan_get_navigation_url' ) ? dokan_get_navigation_url( '' ) : home_url( '/' );
		$reports_url = $this->endpointUrl();
		$clear_url = remove_query_arg( array( 'from', 'to' ), $reports_url );
		ob_start();
		include ZBM_CORE_DIR . 'templates/vendor/reports.php';
		return (string) ob_get_clean();
	}

	/** @return array{start:?DateTimeImmutable,end_exclusive:?DateTimeImmutable,from_value:string,to_value:string,error:?string} */
	private function dateRange( DateTimeZone $timezone ): array {
		$from_raw = $this->queryDate( 'from' );
		$to_raw = $this->queryDate( 'to' );
		if ( null === $from_raw && null === $to_raw ) {
			// A rolling 30-day window includes today, so an empty today follows yesterday's activity.
			$today = new DateTimeImmutable( 'today', $timezone );
			$from_raw = JalaliDate::format( $today->modify( '-29 days' ), '/', false );
			$to_raw = JalaliDate::format( $today, '/', false );
		} elseif ( null === $from_raw || null === $to_raw || '' === $from_raw || '' === $to_raw ) {
			return $this->invalidRange( __( 'تاریخ شروع و پایان را هر دو وارد کنید.', 'zarbalman-core' ) );
		}

		try {
			$start = JalaliDate::parse( $this->reportDate( $from_raw ), $timezone );
			$end = JalaliDate::parse( $this->reportDate( $to_raw ), $timezone );
		} catch ( InvalidArgumentException ) {
			return $this->invalidRange( __( 'تاریخ‌ها را فقط با عدد و در قالب سال/ماه/روز وارد کنید؛ مثلاً ۱۴۰۵/۰۷/۰۲.', 'zarbalman-core' ), $from_raw, $to_raw );
		}

		if ( $start >= $end ) {
			return $this->invalidRange( __( 'تاریخ پایان باید بعد از تاریخ شروع باشد؛ لطفاً بازهٔ زمانی را اصلاح کنید.', 'zarbalman-core' ), $from_raw, $to_raw );
		}

		$days = 0;
		for ( $day = $start; $day <= $end; $day = $day->modify( '+1 day' ) ) {
			++$days;
			if ( $days > self::MAX_RANGE_DAYS ) {
				return $this->invalidRange( __( 'بازهٔ گزارش حداکثر ۳۶۶ روز است.', 'zarbalman-core' ), $from_raw, $to_raw );
			}
		}

		return array(
			'start'         => $start,
			'end_exclusive' => $end->modify( '+1 day' ),
			'from_value'    => JalaliDate::format( $start ),
			'to_value'      => JalaliDate::format( $end ),
			'error'         => null,
		);
	}

	/** @return array{start:null,end_exclusive:null,from_value:string,to_value:string,error:string} */
	private function invalidRange( string $message, string $from = '', string $to = '' ): array {
		return array( 'start' => null, 'end_exclusive' => null, 'from_value' => $from, 'to_value' => $to, 'error' => $message );
	}

	private function queryDate( string $key ): ?string {
		if ( ! array_key_exists( $key, $_GET ) ) {
			return null;
		}
		$value = $_GET[ $key ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, owner-gated date filter.
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( sanitize_text_field( wp_unslash( (string) $value ) ) );
		// Ten Persian/Arabic numerals use more bytes than ten Latin numerals.
		return strlen( $value ) <= 32 ? $value : '';
	}

	/** Leave the shared Jalali parser unchanged for purchase requests. */
	private function reportDate( string $value ): string {
		$value = strtr( trim( $value ), array(
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
		) );
		if ( 1 !== preg_match( '/\A[0-9]{4}\/[0-9]{2}\/[0-9]{2}\z/', $value ) ) {
			throw new InvalidArgumentException( 'Invalid vendor report date.' );
		}
		return $value;
	}

	/** @param list<array{date:string,start_gmt:string,end_gmt:string}> $days */
	private function calendarDays( DateTimeImmutable $start, DateTimeImmutable $end_exclusive ): array {
		$utc = new DateTimeZone( 'UTC' );
		$days = array();
		for ( $day = $start; $day < $end_exclusive; $day = $day->modify( '+1 day' ) ) {
			$next = $day->modify( '+1 day' );
			$days[] = array(
				'date'      => JalaliDate::format( $day ),
				'start_gmt' => $day->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'end_gmt'   => $next->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			);
		}
		return $days;
	}

	private function endpointUrl(): string {
		return function_exists( 'dokan_get_navigation_url' )
			? dokan_get_navigation_url( self::ENDPOINT )
			: home_url( '/approval-by-supplier/' . self::ENDPOINT . '/' );
	}
}

<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use ReflectionClass;
use Throwable;
use WP_Error;

final class DependencyHealth {
	private const DOKAN_LITE_VERSION = '5.0.10';
	private const DOKAN_PRO_VERSION  = '5.0.8';
	private const YITH_VERSION       = '2.41.0';
	private const ZARINPAL_VERSION   = '5.0.17';
	private const PWSMS_VERSION      = '7.2.2';

	/** @var array<string,array{label:string,detected:string,expected:string,ok:bool,scope:string}>|null */
	private ?array $cached_report = null;
	public function __construct( private readonly ?OnlinePaymentAvailability $availability = null ) {}

	/** Gateway health only: a failed SMS integration does not select manual payment. */
	public function onlineAvailableForNewPayment(): bool {
		return ( null === $this->availability || $this->availability->permitsNewLink() )
			&& null === $this->errorForKeys( array( 'currency', 'zarinpal_version', 'zarinpal_settings' ), 'zbm_gateway_unavailable', '' );
	}

	/** @return array<string,array{label:string,detected:string,expected:string,ok:bool,scope:string}> */
	public function report(): array {
		if ( null !== $this->cached_report ) {
			return $this->cached_report;
		}

		// Dokan's public constants are stale in the supplied releases (the
		// internal class properties still advertise 4.x).  The plugin header is
		// the WordPress source of truth and is also what the Plugins screen shows.
		$dokan_lite = $this->isDokanLiteLoaded()
			? $this->pluginHeaderVersion( array( 'dokan/dokan.php', 'dokan-lite/dokan.php' ), 'DOKAN_PLUGIN_VERSION', 'DOKAN_FILE' )
			: '';
		$dokan_pro  = $this->isDokanProLoaded()
			? $this->pluginHeaderVersion( array( 'dokan-pro1/dokan-pro.php', 'dokan-pro/dokan-pro.php' ), 'DOKAN_PRO_PLUGIN_VERSION', 'DOKAN_PRO_FILE' )
			: '';
		$yith       = class_exists( 'YITH_WCDP' ) && defined( 'YITH_WCDP::YITH_WCDP_VERSION' )
			? (string) constant( 'YITH_WCDP::YITH_WCDP_VERSION' )
			: '';
		$pwsms      = defined( 'PWSMS_VERSION' ) ? (string) PWSMS_VERSION : '';
		$sms_gateway = $this->smsGatewayStatus();
		$zarinpal   = $this->zarinpalVersion();
		$settings   = get_option( 'woocommerce_WC_ZPal_settings', array() );
		$settings   = is_array( $settings ) ? $settings : array();
		$gateway_ok = class_exists( 'WC_ZPal' )
			&& 'yes' === (string) ( $settings['enabled'] ?? '' )
			&& '' !== trim( (string) ( $settings['merchantcode'] ?? '' ) )
			&& 'merchant' === (string) ( $settings['fee_payer'] ?? '' )
			&& 'yes' !== (string) ( $settings['sandbox'] ?? '' );
		$gateway_status = $this->zarinpalSettingsStatus( $settings, $gateway_ok );

		$this->cached_report = array(
			'woocommerce' => $this->row(
				__( 'WooCommerce', 'zarbalman-core' ),
				defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
				'>= 10.0.0',
				defined( 'WC_VERSION' ) && version_compare( (string) WC_VERSION, '10.0.0', '>=' ),
				'request'
			),
			'dokan_lite' => $this->row( __( 'Dokan Lite', 'zarbalman-core' ), $dokan_lite, self::DOKAN_LITE_VERSION, self::DOKAN_LITE_VERSION === $dokan_lite, 'request' ),
			'dokan_pro'  => $this->row( __( 'Dokan Pro', 'zarbalman-core' ), $dokan_pro, self::DOKAN_PRO_VERSION, self::DOKAN_PRO_VERSION === $dokan_pro, 'request' ),
			'pwsms'      => $this->row(
				__( 'SMS Professional WooCommerce', 'zarbalman-core' ),
				$pwsms,
				self::PWSMS_VERSION,
				self::PWSMS_VERSION === $pwsms && function_exists( 'PWSMS' ),
				'request'
			),
			'pwsms_gateway' => $this->row(
				__( 'درگاه ارسال پیامک', 'zarbalman-core' ),
				$sms_gateway['detected'],
				__( 'درگاه واقعی و قابل ارسال', 'zarbalman-core' ),
				$sms_gateway['ok'],
				'request'
			),
			'action_scheduler' => $this->row(
				__( 'Action Scheduler', 'zarbalman-core' ),
				function_exists( 'as_schedule_single_action' ) ? __( 'در دسترس', 'zarbalman-core' ) : '',
				__( 'در دسترس', 'zarbalman-core' ),
				function_exists( 'as_schedule_single_action' ) && function_exists( 'as_schedule_recurring_action' ),
				'request'
			),
			'currency' => $this->row(
				__( 'واحد پول WooCommerce', 'zarbalman-core' ),
				function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
				'IRT',
				function_exists( 'get_woocommerce_currency' ) && 'IRT' === get_woocommerce_currency(),
				'request'
			),
			'weight' => $this->row(
				__( 'واحد وزن WooCommerce', 'zarbalman-core' ),
				(string) get_option( 'woocommerce_weight_unit', '' ),
				'kg',
				'kg' === get_option( 'woocommerce_weight_unit', '' ),
				'request'
			),
			'yith' => $this->row( __( 'YITH Deposits Premium', 'zarbalman-core' ), $yith, self::YITH_VERSION, self::YITH_VERSION === $yith, 'payment' ),
			'zarinpal_version' => $this->row( __( 'Secure Zarinpal', 'zarbalman-core' ), $zarinpal, self::ZARINPAL_VERSION, self::ZARINPAL_VERSION === $zarinpal, 'payment' ),
			'zarinpal_settings' => $this->row(
				__( 'تنظیمات درگاه زرین‌پال', 'zarbalman-core' ),
				$gateway_status,
				__( 'فعال + Merchant ID + fee_payer=merchant + محیط واقعی', 'zarbalman-core' ),
				$gateway_ok,
				'payment'
			),
		);

		return $this->cached_report;
	}

	public function canReadRequests(): bool {
		return true;
	}

	public function canCreateRequest(): bool {
		return ! $this->failed( array( 'request' ) );
	}

	public function canCreateDepositOrder(): bool {
		return $this->onlineAvailableForNewPayment() && null === $this->manualOrderError();
	}

	public function canCreateTestManagedOrder(): bool {
		return null === $this->errorForKeys(
			array( 'woocommerce', 'dokan_lite', 'dokan_pro', 'pwsms', 'pwsms_gateway', 'action_scheduler', 'currency', 'weight', 'yith' ),
			'zbm_test_order_dependencies_unavailable',
			__( 'پیش‌نیازهای غیرمالی سفارش آزمایشی کامل نیست.', 'zarbalman-core' )
		);
	}

	public function testManagedOrderError(): ?WP_Error {
		return $this->errorForKeys(
			array( 'woocommerce', 'dokan_lite', 'dokan_pro', 'pwsms', 'pwsms_gateway', 'action_scheduler', 'currency', 'weight', 'yith' ),
			'zbm_test_order_dependencies_unavailable',
			__( 'پیش‌نیازهای غیرمالی سفارش آزمایشی کامل نیست.', 'zarbalman-core' )
		);
	}

	/** Real manual orders deliberately do not depend on Zarinpal or an SMS send attempt. */
	public function manualOrderError(): ?WP_Error {
		return $this->errorForKeys(
			array( 'woocommerce', 'dokan_lite', 'dokan_pro', 'action_scheduler', 'currency', 'weight', 'yith' ),
			'zbm_manual_order_dependencies_unavailable',
			__( 'پیش‌نیازهای سفارش پرداخت دستی واقعی کامل نیست.', 'zarbalman-core' )
		);
	}

	public function requestCreationError(): ?WP_Error {
		return $this->errorForScopes(
			array( 'request' ),
			'zbm_request_dependencies_unavailable',
			__( 'ثبت درخواست تا رفع ناسازگاری تنظیمات پایه متوقف است.', 'zarbalman-core' )
		);
	}

	public function depositOrderError(): ?WP_Error {
		if ( ! $this->onlineAvailableForNewPayment() ) {
			return new WP_Error( 'zbm_payment_dependencies_unavailable', __( 'ایجاد لینک جدید زرین‌پال موقتاً در دسترس نیست.', 'zarbalman-core' ) );
		}
		return $this->manualOrderError();
	}

	/**
	 * Keep capture verification independent from Dokan, YITH and SMS health.
	 * Those integrations may block a new pay link, but must never strand cash
	 * that Zarinpal has already captured.
	 */
	public function paymentVerificationError(): ?WP_Error {
		return $this->errorForKeys(
			array( 'currency', 'zarinpal_version', 'zarinpal_settings' ),
			'zbm_payment_verification_unavailable',
			__( 'استعلام قطعی پرداخت تا بازگرداندن تنظیمات تأییدشده زرین‌پال متوقف است.', 'zarbalman-core' )
		);
	}

	public function notificationError(): ?WP_Error {
		return $this->errorForKeys(
			array( 'pwsms', 'pwsms_gateway' ),
			'zbm_sms_dependencies_unavailable',
			__( 'ارسال پیامک تا بازگرداندن نسخه و تنظیمات تأییدشده متوقف است.', 'zarbalman-core' )
		);
	}

	public function canUseDokanBridge(): bool {
		return null === $this->errorForKeys(
			array( 'dokan_lite', 'dokan_pro' ),
			'zbm_dokan_dependencies_unavailable',
			__( 'نمای فروشنده تا بازگرداندن نسخه تأییدشده دکان متوقف است.', 'zarbalman-core' )
		);
	}

	/** @param list<string> $scopes */
	private function failed( array $scopes ): bool {
		foreach ( $this->report() as $row ) {
			if ( in_array( $row['scope'], $scopes, true ) && ! $row['ok'] ) {
				return true;
			}
		}
		return false;
	}

	/** @param list<string> $scopes */
	private function errorForScopes( array $scopes, string $code, string $message ): ?WP_Error {
		$failed = array();
		foreach ( $this->report() as $row ) {
			if ( in_array( $row['scope'], $scopes, true ) && ! $row['ok'] ) {
				$failed[] = $row['label'];
			}
		}
		return $failed ? new WP_Error( $code, $message . ' ' . implode( '، ', $failed ) ) : null;
	}

	/** @param list<string> $keys */
	private function errorForKeys( array $keys, string $code, string $message ): ?WP_Error {
		$failed = array();
		foreach ( $keys as $key ) {
			$row = $this->report()[ $key ] ?? null;
			if ( is_array( $row ) && ! $row['ok'] ) {
				$failed[] = $row['label'];
			}
		}
		return $failed ? new WP_Error( $code, $message . ' ' . implode( '، ', $failed ) ) : null;
	}

	/** @return array{label:string,detected:string,expected:string,ok:bool,scope:string} */
	private function row( string $label, string $detected, string $expected, bool $ok, string $scope ): array {
		return compact( 'label', 'detected', 'expected', 'ok', 'scope' );
	}

	/** @param array<string,mixed> $settings */
	private function zarinpalSettingsStatus( array $settings, bool $gateway_ok ): string {
		if ( $gateway_ok ) {
			return __( 'فعال، Merchant ID ثبت‌شده، کارمزد با پذیرنده', 'zarbalman-core' );
		}
		if ( ! class_exists( 'WC_ZPal' ) ) {
			return __( 'افزونه زرین‌پال فعال یا قابل شناسایی نیست', 'zarbalman-core' );
		}
		if ( 'yes' !== (string) ( $settings['enabled'] ?? '' ) ) {
			return __( 'درگاه زرین‌پال غیرفعال است', 'zarbalman-core' );
		}
		if ( '' === trim( (string) ( $settings['merchantcode'] ?? '' ) ) ) {
			return __( 'درگاه فعال است؛ Merchant ID وارد نشده', 'zarbalman-core' );
		}
		if ( 'merchant' !== (string) ( $settings['fee_payer'] ?? '' ) ) {
			return __( 'درگاه فعال است؛ پرداخت کارمزد با پذیرنده تنظیم نشده', 'zarbalman-core' );
		}
		if ( 'yes' === (string) ( $settings['sandbox'] ?? '' ) ) {
			return __( 'حالت sandbox برای پرونده واقعی مجاز نیست', 'zarbalman-core' );
		}
		return __( 'تنظیمات درگاه با معیار Core همخوان نیست', 'zarbalman-core' );
	}

	private function zarinpalVersion(): string {
		if ( ! class_exists( 'WC_ZPal' ) ) {
			return '';
		}
		try {
			$file = ( new ReflectionClass( 'WC_ZPal' ) )->getFileName();
			$main = is_string( $file ) ? dirname( $file ) . '/index.php' : '';
			if ( '' === $main || ! is_readable( $main ) ) {
				return '';
			}
			if ( ! function_exists( 'get_file_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$data = get_file_data( $main, array( 'Version' => 'Version' ), 'plugin' );
			return isset( $data['Version'] ) ? trim( (string) $data['Version'] ) : '';
		} catch ( Throwable ) {
			return '';
		}
	}

	/**
	 * Read an installed plugin's official header before falling back to a
	 * runtime constant.  Some Dokan builds expose an outdated constant, while
	 * their plugin header (and WordPress' own plugin list) is correct.
	 *
	 * @param list<string> $basenames
	 */
	private function pluginHeaderVersion( array $basenames, string $runtime_constant, string $file_constant ): string {
		$paths = array();
		if ( defined( $file_constant ) && is_string( constant( $file_constant ) ) ) {
			$paths[] = (string) constant( $file_constant );
		}
		foreach ( $basenames as $basename ) {
			if ( defined( 'WP_PLUGIN_DIR' ) ) {
				$paths[] = trailingslashit( WP_PLUGIN_DIR ) . ltrim( $basename, '/' );
			}
		}

		if ( function_exists( 'get_plugins' ) ) {
			$plugins = get_plugins();
			foreach ( $basenames as $basename ) {
				$version = $plugins[ $basename ]['Version'] ?? '';
				if ( '' !== trim( (string) $version ) ) {
					return trim( (string) $version );
				}
			}
		}

		if ( ! function_exists( 'get_file_data' ) && defined( 'ABSPATH' ) ) {
			$plugin_loader = trailingslashit( ABSPATH ) . 'wp-admin/includes/plugin.php';
			if ( is_readable( $plugin_loader ) ) {
				require_once $plugin_loader;
			}
		}
		if ( function_exists( 'get_file_data' ) ) {
			foreach ( $paths as $path ) {
				if ( ! is_readable( $path ) ) {
					continue;
				}
				$data = get_file_data( $path, array( 'Version' => 'Version' ), 'plugin' );
				$version = $data['Version'] ?? '';
				if ( '' !== trim( (string) $version ) ) {
					return trim( (string) $version );
				}
			}
		}

		return defined( $runtime_constant ) ? trim( (string) constant( $runtime_constant ) ) : '';
	}

	private function isDokanLiteLoaded(): bool {
		return defined( 'DOKAN_FILE' ) || class_exists( 'WeDevs_Dokan' ) || function_exists( 'dokan' );
	}

	private function isDokanProLoaded(): bool {
		return defined( 'DOKAN_PRO_FILE' ) || class_exists( 'Dokan_Pro' ) || function_exists( 'dokan_pro' );
	}

	/** @return array{detected:string,ok:bool} */
	private function smsGatewayStatus(): array {
		if ( ! function_exists( 'PWSMS' ) ) {
			return array( 'detected' => '', 'ok' => false );
		}
		try {
			$instance = PWSMS();
			if ( ! is_object( $instance ) || ! method_exists( $instance, 'get_sms_gateway' ) ) {
				return array( 'detected' => '', 'ok' => false );
			}
			$gateway = $instance->get_sms_gateway();
			$class = is_object( $gateway ) ? get_class( $gateway ) : '';
			$ok = '' !== $class && method_exists( $gateway, 'send' ) && ! str_ends_with( strtolower( $class ), 'logger' );
			return array( 'detected' => '' !== $class ? $class : __( 'تنظیم نشده', 'zarbalman-core' ), 'ok' => $ok );
		} catch ( Throwable ) {
			return array( 'detected' => __( 'خطا در پیکربندی', 'zarbalman-core' ), 'ok' => false );
		}
	}
}

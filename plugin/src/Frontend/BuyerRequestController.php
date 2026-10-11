<?php

declare(strict_types=1);

namespace Zarbalman\Core\Frontend;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use WC_Product;
use WC_Product_Variation;
use WP_Error;
use Zarbalman\Core\Application\PurchaseRequestInput;
use Zarbalman\Core\Application\PurchaseRequestService;
use Zarbalman\Core\Domain\Order\WeightValue;
use Zarbalman\Core\Domain\PurchaseRequest\PricingSnapshot;
use Zarbalman\Core\Domain\PurchaseRequest\RequestLines;
use Zarbalman\Core\Domain\PurchaseRequest\RequestPricingPolicy;
use Zarbalman\Core\Domain\ManagedFlow\Amounts;
use Zarbalman\Core\Domain\PurchaseRequest\DeliveryMethod;
use Zarbalman\Core\Domain\PurchaseRequest\DeliveryWindow;
use Zarbalman\Core\Domain\PurchaseRequest\ProductWeightPolicy;
use Zarbalman\Core\Domain\PurchaseRequest\ShippingQuote;
use Zarbalman\Core\Domain\Shipping\IranProvinceCatalog;
use Zarbalman\Core\Infrastructure\VendorShippingRateRepository;
use Zarbalman\Core\Support\JalaliDate;
use Zarbalman\Core\Integration\DokanVendorEligibility;
use Zarbalman\Core\Integration\DokanStoreAddressAdapter;

final class BuyerRequestController {
	public const POST_ACTION = 'zbm_create_purchase_request';
	private const PREPARE_AUTH_ACTION = 'zbm_prepare_request_auth';

	public function __construct(
		private readonly PurchaseRequestService $service,
		private readonly DokanVendorEligibility $vendor_eligibility,
		private readonly VendorShippingRateRepository $shipping_rates,
		private readonly DokanStoreAddressAdapter $pickup_address
	) {}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'prepareFeedbackResponse' ), 0 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'renderProductRequestForm' ), 31 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'blockManagedCart' ), PHP_INT_MAX, 6 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'replaceLoopCartLink' ), PHP_INT_MAX, 3 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'blockManagedItemsAlreadyInCart' ), PHP_INT_MAX );
		add_action( 'admin_post_' . self::POST_ACTION, array( $this, 'handleCreate' ) );
		add_action( 'admin_post_nopriv_' . self::POST_ACTION, array( $this, 'requireLogin' ) );
		add_action( 'admin_post_' . self::PREPARE_AUTH_ACTION, array( $this, 'prepareRequestAuth' ) );
		add_action( 'admin_post_nopriv_' . self::PREPARE_AUTH_ACTION, array( $this, 'prepareRequestAuth' ) );
		add_action( 'wp_ajax_zbm_request_auth_state', array( $this, 'authStateProbe' ) );
		add_action( 'wp_ajax_nopriv_zbm_request_auth_state', array( $this, 'authStateProbe' ) );
		add_action( 'wp_ajax_zbm_request_pricing_preview', array( $this, 'pricingPreview' ) );
		add_action( 'wp_ajax_nopriv_zbm_request_pricing_preview', array( $this, 'pricingPreview' ) );
		add_action( 'wp_ajax_zbm_request_vendor_products', array( $this, 'vendorProducts' ) );
		add_filter( 'woocommerce_login_redirect', array( $this, 'woocommerceLoginRedirect' ), 10, 2 );
		add_filter( 'woocommerce_registration_redirect', array( $this, 'woocommerceRegistrationRedirect' ), 10, 1 );
		add_filter( 'login_redirect', array( $this, 'wordpressLoginRedirect' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function renderProductRequestForm(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		self::printRequestNotice();
		if ( ! $this->isManagedProduct( $product ) ) {
			return;
		}
		$vendor_id = $this->vendorIdForProduct( $product );
		if ( ! $this->vendor_eligibility->isApprovedVendor( $vendor_id ) ) {
			echo '<div class="zbm-alert zbm-alert-warning zbm-vendor-pending" role="status"><strong>';
			echo esc_html__( 'ثبت درخواست برای این فروشنده موقتاً فعال نیست.', 'zarbalman-core' );
			echo '</strong><p>';
			echo esc_html__( 'حساب فروشنده هنوز در انتظار تأیید مدیریت است. پس از فعال‌شدن فروش، امکان ثبت درخواست از همین صفحه فراهم می‌شود.', 'zarbalman-core' );
			echo '</p></div>';
			return;
		}
		$weight_tenths = $this->productWeightTenths( $product );
		if ( is_wp_error( $weight_tenths ) && ! $product->is_type( 'variable' ) ) {
			echo '<div class="zbm-alert zbm-alert-warning zbm-product-weight-missing" role="status"><strong>';
			echo esc_html__( 'ثبت درخواست برای این محصول موقتاً فعال نیست.', 'zarbalman-core' );
			echo '</strong><p>';
			echo esc_html( $weight_tenths->get_error_message() );
			echo '</p></div>';
			return;
		}
		$product_weight = is_wp_error( $weight_tenths ) ? '' : WeightValue::format_tenths( $weight_tenths );
		$provinces = IranProvinceCatalog::all();
		$province_catalog_complete = IranProvinceCatalog::isComplete( $provinces );
		$supported_provinces = $province_catalog_complete ? $this->shipping_rates->supportedProvinces( $vendor_id, $provinces ) : array();
		$address_delivery_available = $province_catalog_complete && ! empty( $supported_provinces );
		$pickup_address = $this->pickup_address->snapshot( $vendor_id );
		$pickup_available = is_array( $pickup_address );
		$pickup_address_token = $pickup_available ? $this->pickupAddressToken( $vendor_id, $pickup_address ) : '';
		$pickup_available = $pickup_available && '' !== $pickup_address_token;
		if ( ! $pickup_available ) {
			$pickup_address = array();
			$pickup_address_token = '';
		}
		if ( ! $province_catalog_complete ) {
			echo '<div class="zbm-alert zbm-alert-warning zbm-request-province-missing" role="status"><strong>';
			echo esc_html__( 'ثبت درخواست ارسال به نشانی موقتاً فعال نیست.', 'zarbalman-core' );
			echo '</strong><p>' . esc_html__( 'فهرست ۳۱ استان ایران کامل بارگذاری نشده است؛ همچنان می‌توانید درخواست دریافت حضوری را در فرم ثبت کنید.', 'zarbalman-core' ) . '</p></div>';
		} elseif ( ! $address_delivery_available ) {
			echo '<div class="zbm-alert zbm-alert-warning zbm-request-province-missing" role="status"><strong>';
			echo esc_html__( 'ثبت درخواست ارسال به نشانی موقتاً فعال نیست.', 'zarbalman-core' );
			echo '</strong><p>' . esc_html__( 'فروشنده برای هیچ استانی نرخ حمل ثبت نکرده است؛ همچنان می‌توانید درخواست دریافت حضوری را در فرم ثبت کنید.', 'zarbalman-core' ) . '</p></div>';
		}
		if ( is_user_logged_in() ) {
			$contact_error = $this->service->contactError( get_current_user_id(), $vendor_id );
			if ( $contact_error ) {
				echo '<div class="zbm-alert zbm-alert-warning zbm-request-contact-required" role="status"><strong>';
				echo esc_html__( 'پیش از ثبت درخواست', 'zarbalman-core' );
				echo '</strong><p>' . esc_html( $contact_error->get_error_message() ) . '</p>';
				if ( 'zbm_buyer_mobile_required' === $contact_error->get_error_code() && function_exists( 'wc_get_account_endpoint_url' ) ) {
					echo '<a class="button zbm-back-link" href="' . esc_url( RequestResponse::freshUrl( wc_get_account_endpoint_url( 'edit-account' ) ) ) . '">';
					echo esc_html__( 'تکمیل شمارهٔ همراه در حساب من', 'zarbalman-core' );
					echo '</a>';
				}
				echo '<p class="zbm-request-error-code">' . esc_html__( 'کد وضعیت:', 'zarbalman-core' ) . ' <code dir="ltr">' . esc_html( (string) $contact_error->get_error_code() ) . '</code></p></div>';
				return;
			}
		}
		$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$timezone = wp_timezone();
		$min_local = ( new DateTimeImmutable( 'now', $timezone ) )->modify( '+1 day' )->setTime( 0, 0, 0 );
		$max_local = ( new DateTimeImmutable( 'now', $timezone ) )->modify( '+30 days' )->setTime( 0, 0, 0 );
		$min_date_iso = $min_local->format( 'Y-m-d' );
		$max_date_iso = $max_local->format( 'Y-m-d' );
		$min_jalali = JalaliDate::format( $min_local, '/', true );
		$max_jalali = JalaliDate::format( $max_local, '/', true );
		$min_jalali_ascii = JalaliDate::format( $min_local, '/', false );
		$max_jalali_ascii = JalaliDate::format( $max_local, '/', false );
		$action = admin_url( 'admin-post.php' );
		$product_url = get_permalink( $product_id );
		$product_url = is_string( $product_url ) && '' !== $product_url ? $product_url : home_url( '/' );
		$login_url = add_query_arg(
			array(
				'action'    => self::PREPARE_AUTH_ACTION,
				'return_to' => $product_url,
			),
			$action
		);
		$is_logged_in = is_user_logged_in();
		$auto_open = isset( $_GET['zbm_resume_request'] ) && '1' === (string) $_GET['zbm_resume_request']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Ephemeral UI resume flag after the authenticated redirect; JavaScript still requires a live auth probe.
		include ZBM_CORE_DIR . 'templates/buyer/request-form.php';
	}

	public function prepareFeedbackResponse(): void {
		$marker = $_GET['zbm_request_feedback'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A display-only cache-bypass marker; it performs no state change.
		if (
			! is_scalar( $marker )
			|| '1' !== (string) wp_unslash( $marker )
			|| ! function_exists( 'is_product' )
			|| ! is_product()
			|| ! is_user_logged_in()
		) {
			return;
		}
		RequestResponse::preventCaching();
	}

	public function blockManagedCart( bool $passed, int $product_id, int $quantity, int $variation_id = 0, array $variations = array(), array $cart_item_data = array() ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$product = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
		if ( $product instanceof WC_Product && $this->isManagedProduct( $product ) && ! defined( 'ZBM_CORE_INTERNAL_CART' ) ) {
			wc_add_notice( __( 'برای این محصول ابتدا درخواست خرید ثبت کنید تا فروشنده موجودی و زمان تحویل را تأیید کند.', 'zarbalman-core' ), 'notice' );
			return false;
		}
		return $passed;
	}

	public function replaceLoopCartLink( string $html, WC_Product $product, array $args ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $this->isManagedProduct( $product ) ) {
			return $html;
		}
		return sprintf(
			'<a class="button zbm-request-product" href="%s">%s</a>',
			esc_url( get_permalink( $product->get_id() ) ),
			esc_html__( 'ثبت درخواست خرید', 'zarbalman-core' )
		);
	}

	public function blockManagedItemsAlreadyInCart(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( $product instanceof WC_Product && $this->isManagedProduct( $product ) ) {
				wc_add_notice( __( 'حداقل یک کالای فروشنده در سبد قدیمی شماست. آن را حذف و از صفحه محصول «ثبت درخواست خرید» را انتخاب کنید.', 'zarbalman-core' ), 'error' );
				return;
			}
		}
	}

	public function handleCreate(): never {
		RequestResponse::preventCaching();
		if ( ! is_user_logged_in() ) {
			$this->requireLogin();
		}
		$buyer_id = get_current_user_id();
		if ( ! RequestResponse::verifyFormNonce( $_POST['_zbm_nonce'] ?? null, self::POST_ACTION ) ) {
			$this->redirectWithNotice( __( 'اعتبار فرم پایان یافته یا حساب ورود تغییر کرده است. صفحهٔ تازه بارگذاری شد؛ درخواست را دوباره از همین صفحه ثبت کنید.', 'zarbalman-core' ), 'error', 'zbm_request_nonce_invalid' );
		}
		if ( $this->rateLimited( $buyer_id ) ) {
			$this->redirectWithNotice( __( 'تعداد درخواست‌های شما در چند دقیقهٔ اخیر زیاد بوده است؛ کمی بعد دوباره تلاش کنید.', 'zarbalman-core' ), 'error', 'zbm_request_rate_limited' );
		}

		try {
			$input = $this->validatedInput( wp_unslash( $_POST ) );
		} catch ( InvalidArgumentException | \OverflowException $error ) {
			$this->redirectWithNotice( $error->getMessage(), 'error', 'zbm_request_input_invalid' );
		}
		$idempotency = isset( $_POST['idempotency_key'] ) && is_string( $_POST['idempotency_key'] ) ? sanitize_text_field( wp_unslash( $_POST['idempotency_key'] ) ) : '';
		$result = $this->service->create( $input, $buyer_id, $idempotency, wp_generate_uuid4() );
		if ( is_wp_error( $result ) ) {
			$this->redirectWithNotice( $result->get_error_message(), 'error', (string) $result->get_error_code() );
		}
		$this->incrementRateLimit( $buyer_id );
		$url = function_exists( 'wc_get_account_endpoint_url' )
			? wc_get_account_endpoint_url( 'purchase-requests' )
			: home_url( '/order-review-by-buyer/' );
		RequestResponse::redirect( add_query_arg( array( 'request' => $result->id(), 'zbm_created' => 1 ), $url ) );
	}

	/** @param array<string,mixed> $payload */
	public function validatedInput( array $payload ): PurchaseRequestInput {
		foreach ( $payload as $value ) {
			if ( ! is_scalar( $value ) && null !== $value ) {
				throw new InvalidArgumentException( __( 'ساختار اطلاعات فرم معتبر نیست؛ صفحه را تازه کنید.', 'zarbalman-core' ) );
			}
		}
		$context = $this->requestPricingContext( $payload );
		$parent_id = $context['parent_id'];
		$variation_id = $context['variation_id'];
		$vendor_id = $context['vendor_id'];
		$quantity = $context['quantity'];
		$price = $context['price'];
		$weight_tenths = $context['weight_tenths'];
		$method = sanitize_key( (string) ( $payload['delivery_method'] ?? '' ) );
		DeliveryMethod::assertValid( $method );
		$window = $this->deliveryWindow( $payload );
		$recipient = sanitize_text_field( (string) ( $payload['recipient_name'] ?? '' ) );
		$pickup_note = null;
		$pickup_visit_confirmed = false;
		$pickup_address = null;
		$shipping = array(
			'province_code'              => null,
			'province_name'              => null,
			'shipping_rate_per_kg_toman' => null,
			'estimated_shipping_toman'   => null,
			'shipping_rate_version'      => null,
		);
		if ( DeliveryMethod::DELIVERY_TO_BUYER_ADDRESS === $method ) {
			if ( $this->hasSubmittedValue( $payload, 'pickup_note' ) || $this->hasSubmittedValue( $payload, 'pickup_visit_confirmed' ) || $this->hasSubmittedValue( $payload, 'pickup_address_token' ) ) {
				throw new InvalidArgumentException( __( 'اطلاعات دریافت حضوری نباید همراه با تحویل در نشانی ارسال شود.', 'zarbalman-core' ) );
			}
			$postal = preg_replace( '/[^0-9]+/', '', $this->asciiDigits( (string) ( $payload['postal_code'] ?? '' ) ) );
			$address = sanitize_textarea_field( (string) ( $payload['address'] ?? '' ) );
			$shipping = $this->shippingSnapshot( $vendor_id, 1, $context['total_weight_tenths'], (string) ( $payload['province_code'] ?? '' ) );
		} else {
			if ( $this->hasSubmittedValue( $payload, 'postal_code' ) || $this->hasSubmittedValue( $payload, 'address' ) || $this->hasSubmittedValue( $payload, 'province_code' ) ) {
				throw new InvalidArgumentException( __( 'برای دریافت حضوری اطلاعات نشانی یا استان نباید ارسال شود.', 'zarbalman-core' ) );
			}
			$postal = null;
			$address = null;
			$pickup_note_value = sanitize_textarea_field( (string) ( $payload['pickup_note'] ?? '' ) );
			$pickup_note = '' === trim( $pickup_note_value ) ? null : trim( $pickup_note_value );
			$pickup_visit_confirmed = isset( $payload['pickup_visit_confirmed'] )
				&& is_scalar( $payload['pickup_visit_confirmed'] )
				&& '1' === trim( (string) $payload['pickup_visit_confirmed'] );
			if ( ! $pickup_visit_confirmed ) {
				throw new InvalidArgumentException( __( 'برای دریافت حضوری باید توانایی مراجعه به نشانی فروشنده را تأیید کنید.', 'zarbalman-core' ) );
			}
			$pickup_address_token = sanitize_text_field( (string) ( $payload['pickup_address_token'] ?? '' ) );
			$pickup_address = $this->pickupAddressSnapshot( $vendor_id, $pickup_address_token );
		}

		return new PurchaseRequestInput(
			$parent_id, $variation_id, $vendor_id, $quantity, $price, $weight_tenths,
			$method, $window, $recipient, $postal, $address,
			$shipping['province_code'], $shipping['province_name'], $shipping['shipping_rate_per_kg_toman'],
			$shipping['estimated_shipping_toman'], $shipping['shipping_rate_version'],
			$pickup_note, $pickup_visit_confirmed, $pickup_address, $context['lines']
		);
	}

	public function assets(): void {
		wp_register_script( 'zbm-payment-clock', plugins_url( 'assets/js/payment-clock.js', ZBM_CORE_FILE ), array(), ZBM_CORE_VERSION, true );
		wp_localize_script( 'zbm-payment-clock', 'zbmPaymentClock', array( 'now' => time() ) );
		wp_register_style( 'zbm-purchase-requests', plugins_url( 'assets/css/purchase-requests.css', ZBM_CORE_FILE ), array(), ZBM_CORE_VERSION );
		wp_register_script( 'zbm-purchase-requests', plugins_url( 'assets/js/purchase-requests.js', ZBM_CORE_FILE ), array( 'jquery' ), ZBM_CORE_VERSION, true );
		wp_register_script( 'zbm-request-response', plugins_url( 'assets/js/request-response.js', ZBM_CORE_FILE ), array(), ZBM_CORE_VERSION, true );
		wp_register_style( 'zbm-managed-case', plugins_url( 'assets/css/managed-case.css', ZBM_CORE_FILE ), array(), ZBM_CORE_VERSION );
		wp_register_script( 'zbm-managed-case', plugins_url( 'assets/js/managed-case.js', ZBM_CORE_FILE ), array( 'zbm-payment-clock' ), ZBM_CORE_VERSION, true );
		wp_enqueue_style( 'zbm-purchase-requests' );
		wp_enqueue_style( 'zbm-managed-case' );
		wp_enqueue_script( 'zbm-purchase-requests' );
		wp_enqueue_script( 'zbm-request-lines', plugins_url( 'assets/js/request-lines.js', ZBM_CORE_FILE ), array( 'zbm-purchase-requests' ), ZBM_CORE_VERSION, true );
		wp_localize_script(
			'zbm-purchase-requests',
			'zbmPurchaseRequestsConfig',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'authAction'    => 'zbm_request_auth_state',
				'pricingAction' => 'zbm_request_pricing_preview',
			)
		);
		wp_enqueue_script( 'zbm-request-response' );
		wp_enqueue_script( 'zbm-managed-case' );
	}

	public function authStateProbe(): never {
		RequestResponse::preventCaching();
		$authenticated = is_user_logged_in();
		$data = array( 'authenticated' => $authenticated );
		if ( $authenticated ) {
			$data['create_nonce'] = wp_create_nonce( self::POST_ACTION );
			$data['idempotency_key'] = wp_generate_uuid4();
		}
		wp_send_json_success( $data );
	}

	public function pricingPreview(): never {
		RequestResponse::preventCaching();
		$payload = array(
			'product_id'   => $this->ajaxScalar( 'product_id' ),
			'variation_id' => $this->ajaxScalar( 'variation_id' ),
			'quantity'     => $this->ajaxScalar( 'quantity' ),
			'province_code' => $this->ajaxScalar( 'province_code' ),
			'lines_json' => $this->ajaxScalar( 'lines_json' ),
		);
		try {
			$context = $this->requestPricingContext( $payload );
			$pricing = PricingSnapshot::fromLines( $context['lines'] );
			$catalog = IranProvinceCatalog::all();
			$catalog_complete = IranProvinceCatalog::isComplete( $catalog );
			$supported_provinces = $catalog_complete ? $this->shipping_rates->supportedProvinces( $context['vendor_id'], $catalog ) : array();
			$supported_province_message = ! empty( $supported_provinces )
				? __( 'این فروشنده به استان‌های زیر ارسال دارد؛ استان مقصدتان را از فهرست انتخاب کنید.', 'zarbalman-core' )
				: __( 'فروشنده در حال حاضر برای هیچ استانی ارسال فعال ندارد. برای ادامه، دریافت حضوری را انتخاب کنید.', 'zarbalman-core' );
			$shipping = null;
			$province_supported = null;
			$province_message = null;
			$province_code = is_scalar( $payload['province_code'] ?? null ) ? trim( (string) $payload['province_code'] ) : '';
			if ( '' !== $province_code ) {
				if ( ! $catalog_complete ) {
					throw new InvalidArgumentException( __( 'فهرست ۳۱ استان ایران کامل نیست؛ در حال حاضر فقط دریافت حضوری در دسترس است.', 'zarbalman-core' ) );
				}
				$province = IranProvinceCatalog::find( $catalog, $province_code );
				if ( null === $province ) {
					throw new InvalidArgumentException( __( 'لطفاً یک استان معتبر را انتخاب کنید.', 'zarbalman-core' ) );
				}
				$rate_snapshot = $this->shipping_rates->snapshotFor( $context['vendor_id'], $province['code'] );
				$shipping = array(
					'province_code' => $province['code'],
					'province_name' => $province['name'],
				);
				if ( null === $rate_snapshot ) {
					$province_supported = false;
					$province_message = sprintf(
						/* translators: %s: province name */
						__( 'فروشنده در حال حاضر ارسال به استان %s را فعال نکرده است. برای ادامه، دریافت حضوری را انتخاب کنید یا استان دیگری برگزینید.', 'zarbalman-core' ),
						$province['name']
					);
				} else {
					$province_supported = true;
					$shipping['shipping_rate_per_kg_toman'] = $rate_snapshot['rate'];
					$shipping['shipping_rate_version'] = $rate_snapshot['version'];
					$shipping['estimated_shipping_toman'] = ShippingQuote::estimateToman( $context['total_weight_tenths'], $rate_snapshot['rate'] );
					$province_message = sprintf(
						/* translators: %s: province name */
						0 === $rate_snapshot['rate']
							? __( 'ارسال به استان %s پشتیبانی می‌شود و نرخ فعلی حمل رایگان است.', 'zarbalman-core' )
							: __( 'ارسال به استان %s پشتیبانی می‌شود؛ هزینهٔ تقریبی با نرخ فعلی فروشنده محاسبه شد.', 'zarbalman-core' ),
						$province['name']
					);
				}
			}
			$total_with_shipping = true === $province_supported ? $this->checkedAdd( $pricing->estimatedTotalToman(), (int) $shipping['estimated_shipping_toman'] ) : null;
			if ( true === $province_supported ) {
				$pricing = PricingSnapshot::fromLines( $context['lines'], $shipping['shipping_rate_per_kg_toman'] );
			}
			wp_send_json_success(
				array(
					'product_id'                   => $context['parent_id'],
					'total_quantity'               => $pricing->quantity(),
					'product_count'                => count( $context['lines'] ),
					'lines'                        => array_values( $context['lines'] ),
					'vendor_id'                    => $context['vendor_id'],
					'province_code'                => $shipping['province_code'] ?? null,
					'province_name'                => $shipping['province_name'] ?? null,
					'province_supported'           => $province_supported,
					'province_message'             => $province_message,
					'supported_provinces'          => array_values(
						array_map(
							static fn ( string $code, string $name ): array => array(
								'code' => $code,
								'name' => $name,
							),
							array_keys( $supported_provinces ),
							array_values( $supported_provinces )
						)
					),
					'supported_province_message'   => $supported_province_message,
					'shipping_rate_per_kg_toman'   => $shipping['shipping_rate_per_kg_toman'] ?? null,
					'shipping_rate_version'        => $shipping['shipping_rate_version'] ?? null,
					'reference_weight_tenths'      => $pricing->referenceWeightTenths(),
					'estimated_product_toman'      => $pricing->estimatedTotalToman(),
					'estimated_shipping_toman'     => $shipping['estimated_shipping_toman'] ?? null,
					'estimated_total_with_shipping' => $total_with_shipping,
					'deposit_toman'                => $pricing->depositToman(),
					'estimated_gross_toman'        => $pricing->estimatedGrossToman(),
					'estimated_balance_toman'      => $pricing->estimatedGrossToman() - $pricing->depositToman(),
					'pricing_version'              => 2,
					'shipping_is_outside_deposit'  => false,
				)
			);
		} catch ( InvalidArgumentException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
		} catch ( \Throwable ) {
			wp_send_json_error( array( 'message' => __( 'پیش‌نمایش قیمت فعلاً در دسترس نیست.', 'zarbalman-core' ) ), 400 );
		}
	}

	/** Resolve IDs and quantities afresh; no client prices/vendor/weights are trusted. */
	private function requestPricingContext( array $payload ): array {
		$first = $this->productPricingContext( $payload );
		$raw = $payload['lines_json'] ?? '';
		if ( null === $raw ) { $raw = ''; }
		if ( ! is_string( $raw ) || strlen( $raw ) > 12000 ) { throw new InvalidArgumentException( __( 'فهرست محصولات معتبر نیست.', 'zarbalman-core' ) ); }
		$extra = '' === $raw ? array() : json_decode( $raw, true, 8 );
		if ( ! is_array( $extra ) || ! array_is_list( $extra ) || count( $extra ) >= RequestLines::MAX_LINES ) { throw new InvalidArgumentException( __( 'حداکثر ۲۰ محصول از یک فروشنده در هر درخواست قابل انتخاب است.', 'zarbalman-core' ) ); }
		$selections = array( $first['parent_id'] . ':' . $first['variation_id'] => array( 'product_id' => $first['parent_id'], 'variation_id' => $first['variation_id'], 'quantity' => $first['quantity'] ) );
		foreach ( $extra as $index => $item ) {
			if ( ! is_array( $item ) || array_diff( array_keys( $item ), array( 'product_id', 'variation_id', 'quantity' ) ) ) { throw new InvalidArgumentException( __( 'ساختار ردیف محصول معتبر نیست.', 'zarbalman-core' ) ); }
			if ( ! is_int( $item['product_id'] ?? null ) || $item['product_id'] < 1 || ! is_int( $item['variation_id'] ?? null ) || $item['variation_id'] < 0 || ! is_int( $item['quantity'] ?? null ) || $item['quantity'] < 1 ) { throw new InvalidArgumentException( __( 'شناسه و تعداد ردیف محصول باید عدد صحیح معتبر باشد.', 'zarbalman-core' ) ); }
			try { $context = $this->productPricingContext( $item ); }
			catch ( InvalidArgumentException $error ) { throw new InvalidArgumentException( sprintf( __( 'محصول ردیف %1$d: %2$s', 'zarbalman-core' ), $index + 2, $error->getMessage() ) ); }
			if ( $context['vendor_id'] !== $first['vendor_id'] ) { throw new InvalidArgumentException( __( 'تمام محصولات یک درخواست باید از همان فروشنده باشند.', 'zarbalman-core' ) ); }
			$key = $context['parent_id'] . ':' . $context['variation_id'];
			$count = RequestPricingPolicy::add( $selections[ $key ]['quantity'] ?? 0, $context['quantity'] );
			$selections[ $key ] = array( 'product_id' => $context['parent_id'], 'variation_id' => $context['variation_id'], 'quantity' => $count );
		}
		$lines = array();
		foreach ( $selections as $item ) {
			$context = $this->productPricingContext( $item ); // Combined quantities must also pass stock checks.
			if ( $context['vendor_id'] !== $first['vendor_id'] ) { throw new InvalidArgumentException( __( 'فروشندهٔ محصول تغییر کرده است؛ انتخاب‌ها را بررسی کنید.', 'zarbalman-core' ) ); }
			$lines[ 'line:' . wp_generate_uuid4() ] = array( 'product_id' => $context['parent_id'], 'variation_id' => $context['variation_id'], 'quantity' => $context['quantity'], 'package_price_toman' => $context['price'], 'nominal_weight_tenths' => $context['weight_tenths'], 'reference_tenths' => Amounts::multiply( $context['quantity'], $context['weight_tenths'] ), 'estimated_total' => Amounts::multiply( $context['quantity'], $context['price'] ), 'name' => sanitize_text_field( $context['product']->get_name() ) );
		}
		$primary = reset( $lines );
		$first['quantity'] = $primary['quantity'];
		$first['lines'] = $lines;
		$first['total_weight_tenths'] = RequestLines::totals( $lines )['reference_tenths'];
		return $first;
	}

	/** Authenticated, bounded catalogue search scoped by the server-side anchor seller. */
	public function vendorProducts(): never {
		RequestResponse::preventCaching();
		try {
			$anchor = $this->productPricingContext( array( 'product_id' => $this->ajaxScalar( 'product_id' ), 'variation_id' => $this->ajaxScalar( 'variation_id' ), 'quantity' => 1 ) );
			$page = min( 500, max( 1, absint( $this->ajaxScalar( 'page' ) ) ) );
			$variable_id = absint( $this->ajaxScalar( 'variable_id' ) );
			$search = sanitize_text_field( (string) $this->ajaxScalar( 'search' ) );
			if ( strlen( $search ) > 200 ) { throw new InvalidArgumentException( __( 'متن جست‌وجو طولانی است.', 'zarbalman-core' ) ); }
			if ( $variable_id > 0 ) {
				$parent = wc_get_product( $variable_id );
				if ( ! $parent instanceof WC_Product || ! $parent->is_type( 'variable' ) || 'publish' !== $parent->get_status() || ! $parent->is_visible() || (int) get_post_field( 'post_author', $variable_id ) !== $anchor['vendor_id'] ) { throw new InvalidArgumentException( 'Invalid catalogue product.' ); }
				$query = new \WP_Query( array( 'post_type' => 'product_variation', 'post_parent' => $variable_id, 'post_status' => 'publish', 'posts_per_page' => 20, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'no_found_rows' => false ) );
			} else {
				$query = new \WP_Query( array( 'post_type' => 'product', 'post_status' => 'publish', 'author' => $anchor['vendor_id'], 's' => $search, 'posts_per_page' => 8, 'paged' => $page, 'orderby' => 'ID', 'order' => 'DESC', 'fields' => 'ids', 'no_found_rows' => false ) );
			}
			$results = array();
			foreach ( $query->posts as $id ) {
				$product = wc_get_product( $id );
				if ( ! $product instanceof WC_Product || ( 0 === $variable_id && ! $product->is_visible() ) ) { continue; }
				if ( 0 === $variable_id && $product->is_type( 'variable' ) ) {
					$results[] = array( 'product_id' => (int) $id, 'variation_id' => 0, 'name' => sanitize_text_field( $product->get_name() ), 'type' => 'variable' );
					continue;
				}
				try {
					$context = $this->productPricingContext( array( 'product_id' => $variable_id ?: $id, 'variation_id' => $variable_id > 0 ? $id : 0, 'quantity' => 1 ) );
					if ( $context['vendor_id'] !== $anchor['vendor_id'] ) { continue; }
					$results[] = array( 'product_id' => $context['parent_id'], 'variation_id' => $context['variation_id'], 'name' => sanitize_text_field( $context['product']->get_name() ), 'price' => $context['price'], 'weight_tenths' => $context['weight_tenths'], 'type' => 'product' );
				} catch ( \Throwable ) { continue; }
			}
			wp_send_json_success( array( 'items' => $results, 'has_more' => $page < min( 500, (int) $query->max_num_pages ) ) );
		} catch ( \Throwable ) { wp_send_json_error( array( 'message' => __( 'فهرست محصولات در دسترس نیست؛ مشخصات محصول فعلی را انتخاب و دوباره تلاش کنید.', 'zarbalman-core' ) ), 400 ); }
	}

	public function requireLogin(): never {
		$return_to = wp_get_referer() ?: home_url( '/' );
		if ( isset( $_POST['product_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The authenticated create handler performs nonce validation; this branch only prepares a safe login return.
			$product = wc_get_product( absint( wp_unslash( $_POST['product_id'] ) ) );
			if ( $product instanceof WC_Product ) {
				$product_url = get_permalink( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id() );
				if ( is_string( $product_url ) && '' !== $product_url ) {
					$return_to = $product_url;
				}
			}
		}
		$this->redirectToAccountAfterAuthPreparation( (string) $return_to );
	}

	public function prepareRequestAuth(): never {
		$return_to = isset( $_GET['return_to'] ) ? (string) wp_unslash( $_GET['return_to'] ) : home_url( '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a same-site return URL validated below; no privileged action occurs.
		$this->redirectToAccountAfterAuthPreparation( $return_to );
	}

	public function woocommerceLoginRedirect( string $redirect_to, mixed $user = null ): string {
		return $this->consumeRequestReturn( $redirect_to );
	}

	public function woocommerceRegistrationRedirect( string $redirect_to ): string {
		return $this->consumeRequestReturn( $redirect_to );
	}

	public function wordpressLoginRedirect( string $redirect_to, string $requested_redirect_to, mixed $user ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress supplies all three filter arguments.
		if ( $user instanceof WP_Error ) {
			return $redirect_to;
		}
		return $this->consumeRequestReturn( $redirect_to );
	}

	private function redirectToAccountAfterAuthPreparation( string $return_to ): never {
		$safe_return = wp_validate_redirect( $return_to, '' );
		if ( '' === $safe_return ) {
			$safe_return = home_url( '/' );
		}
		$this->setRequestReturnCookie( $safe_return, time() + 30 * MINUTE_IN_SECONDS );
		$account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_registration_url();
		if ( ! is_string( $account_url ) || '' === $account_url ) {
			$account_url = wp_registration_url();
		}
		wp_safe_redirect( add_query_arg( 'zbm_register', '1', $account_url ) );
		exit;
	}

	private function consumeRequestReturn( string $fallback ): string {
		if ( ! isset( $_COOKIE['zbm_request_return'] ) ) {
			return $fallback;
		}
		$encoded = (string) wp_unslash( $_COOKIE['zbm_request_return'] );
		$return_to = wp_validate_redirect( rawurldecode( $encoded ), '' );
		if ( '' === $return_to ) {
			return $fallback;
		}
		$this->setRequestReturnCookie( '', time() - HOUR_IN_SECONDS );
		return add_query_arg( 'zbm_resume_request', '1', $return_to );
	}

	private function setRequestReturnCookie( string $value, int $expires ): void {
		$path = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
		setcookie( 'zbm_request_return', rawurlencode( $value ), $expires, $path, $domain, is_ssl(), true );
	}

	/** @return array{parent_id:int,variation_id:int,vendor_id:int,quantity:int,price:int,weight_tenths:int,product:WC_Product} */
	private function productPricingContext( array $payload ): array {
		$product_id = absint( $payload['product_id'] ?? 0 );
		$variation_id = absint( $payload['variation_id'] ?? 0 );
		$quantity = $this->positiveInteger( $payload['quantity'] ?? null, __( 'تعداد بسته باید یک عدد صحیح مثبت باشد.', 'zarbalman-core' ) );
		$product = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
		if ( ! $product instanceof WC_Product || ! $this->isManagedProduct( $product ) || $quantity < 1 ) {
			throw new InvalidArgumentException( __( 'محصول یا تعداد درخواست معتبر نیست.', 'zarbalman-core' ) );
		}
		if ( 0 === $variation_id && ( $product->is_type( 'variation' ) || $product->is_type( 'variable' ) ) ) {
			throw new InvalidArgumentException( __( 'محصول یا تعداد درخواست معتبر نیست.', 'zarbalman-core' ) );
		}
		if (
			$variation_id > 0
			&& (
				! $product instanceof WC_Product_Variation
				|| $product->get_parent_id() !== $product_id
				|| 'publish' !== $product->get_status()
				|| ! $product->variation_is_active()
				|| ! $product->is_purchasable()
			)
		) {
			throw new InvalidArgumentException( __( 'تنوع انتخاب‌شده متعلق به این محصول نیست.', 'zarbalman-core' ) );
		}
		$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$parent = $product->is_type( 'variation' ) ? wc_get_product( $parent_id ) : $product;
		if (
			! $parent instanceof WC_Product
			|| 'publish' !== $parent->get_status()
			|| ! function_exists( 'is_post_publicly_viewable' )
			|| ! is_post_publicly_viewable( $parent_id )
		) {
			throw new InvalidArgumentException( __( 'محصول یا تعداد درخواست معتبر نیست.', 'zarbalman-core' ) );
		}
		$vendor_id = function_exists( 'dokan_get_vendor_by_product' )
			? absint( dokan_get_vendor_by_product( $parent_id, true ) )
			: absint( get_post_field( 'post_author', $parent_id ) );
		if ( ! $this->vendor_eligibility->isApprovedVendor( $vendor_id ) ) {
			throw new InvalidArgumentException( __( 'حساب این فروشنده هنوز برای دریافت درخواست خرید تأیید نشده است.', 'zarbalman-core' ) );
		}
		if ( ! $product->is_in_stock() || ( $product->managing_stock() && ! $product->has_enough_stock( $quantity ) && ! $product->backorders_allowed() ) ) {
			throw new InvalidArgumentException( __( 'موجودی انتخاب‌شده در دسترس نیست.', 'zarbalman-core' ) );
		}
		$price = $this->integerDecimal( (string) $product->get_price(), __( 'قیمت محصول باید به تومان و بدون اعشار باشد.', 'zarbalman-core' ) );
		if ( 'kg' !== get_option( 'woocommerce_weight_unit', 'kg' ) ) {
			throw new InvalidArgumentException( __( 'واحد وزن فروشگاه باید کیلوگرم باشد.', 'zarbalman-core' ) );
		}
		$weight_tenths = $this->productWeightTenths( $product );
		if ( is_wp_error( $weight_tenths ) ) {
			throw new InvalidArgumentException( $weight_tenths->get_error_message() );
		}

		return array(
			'parent_id'     => $parent_id,
			'variation_id'  => $variation_id,
			'vendor_id'     => $vendor_id,
			'quantity'      => $quantity,
			'price'         => $price,
			'weight_tenths' => $weight_tenths,
			'product'       => $product,
		);
	}

	/** @return array{province_code:string,province_name:string,shipping_rate_per_kg_toman:int,estimated_shipping_toman:int,shipping_rate_version:string} */
	private function shippingSnapshot( int $vendor_id, int $quantity, int $weight_tenths, string $province_code ): array {
		$catalog = IranProvinceCatalog::all();
		if ( ! IranProvinceCatalog::isComplete( $catalog ) ) {
			throw new InvalidArgumentException( __( 'فهرست استان‌ها در حال حاضر کامل نیست.', 'zarbalman-core' ) );
		}
		$province = IranProvinceCatalog::find( $catalog, $province_code );
		if ( null === $province ) {
			throw new InvalidArgumentException( __( 'لطفاً استان مقصد را انتخاب کنید.', 'zarbalman-core' ) );
		}
		$rate_snapshot = $this->shipping_rates->snapshotFor( $vendor_id, $province['code'] );
		if ( null === $rate_snapshot ) {
			throw new InvalidArgumentException( __( 'فروشنده برای استان انتخاب‌شده نرخ حمل ثبت نکرده است.', 'zarbalman-core' ) );
		}

		return array(
			'province_code'              => $province['code'],
			'province_name'              => $province['name'],
			'shipping_rate_per_kg_toman' => $rate_snapshot['rate'],
			'estimated_shipping_toman'   => ShippingQuote::estimateForQuantity( $quantity, $weight_tenths, $rate_snapshot['rate'] ),
			'shipping_rate_version'      => $rate_snapshot['version'],
		);
	}

	/** @return array{store_name:string,street_1:string,street_2:string,city:string,zip:string,state:string,country:string} */
	private function pickupAddressSnapshot( int $vendor_id, string $submitted_token ): array {
		$snapshot = $this->pickup_address->snapshot( $vendor_id );
		if ( is_wp_error( $snapshot ) ) {
			throw new InvalidArgumentException( $snapshot->get_error_message() );
		}
		$expected_token = $this->pickupAddressToken( $vendor_id, $snapshot );
		if (
			1 !== preg_match( '/\A[a-f0-9]{64}\z/i', $submitted_token )
			|| '' === $expected_token
			|| ! hash_equals( $expected_token, strtolower( $submitted_token ) )
		) {
			throw new InvalidArgumentException( __( 'نشانی فروشنده پس از نمایش فرم تغییر کرده یا اعتبار تأیید آن پایان یافته است؛ صفحه را تازه کنید، نشانی جدید را بررسی و دوباره تأیید کنید.', 'zarbalman-core' ) );
		}

		return $snapshot;
	}

	/** @param array<string,string> $snapshot */
	private function pickupAddressToken( int $vendor_id, array $snapshot ): string {
		$address = array();
		foreach ( array( 'store_name', 'street_1', 'street_2', 'city', 'zip', 'state', 'country' ) as $field ) {
			if ( ! array_key_exists( $field, $snapshot ) || ! is_string( $snapshot[ $field ] ) ) {
				return '';
			}
			$address[ $field ] = $snapshot[ $field ];
		}
		$canonical = wp_json_encode(
			array(
				'context'   => 'zbm-pickup-address-v1',
				'vendor_id' => $vendor_id,
				'address'   => $address,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $canonical ) ) {
			return '';
		}

		return hash_hmac( 'sha256', $canonical, wp_salt( 'nonce' ) );
	}

	private function hasSubmittedValue( array $payload, string $key ): bool {
		return array_key_exists( $key, $payload ) && null !== $payload[ $key ] && '' !== trim( (string) $payload[ $key ] );
	}

	private function ajaxScalar( string $key ): mixed {
		$value = $_REQUEST[ $key ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public pricing preview; all values are revalidated server-side.
		// WordPress slashes request strings, including the JSON product list.
		return is_string( $value ) ? wp_unslash( $value ) : ( is_scalar( $value ) || null === $value ? $value : null );
	}

	private function checkedAdd( int $left, int $right ): int {
		if ( $left < 0 || $right < 0 || $left > PHP_INT_MAX - $right ) {
			throw new \OverflowException( 'Pricing addition overflow.' );
		}

		return $left + $right;
	}

	private function isManagedProduct( WC_Product $product ): bool {
		$vendor_id = $this->vendorIdForProduct( $product );
		return (bool) apply_filters( 'zbm_is_managed_product', $vendor_id > 0, $product, $vendor_id );
	}

	private function vendorIdForProduct( WC_Product $product ): int {
		$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		return function_exists( 'dokan_get_vendor_by_product' )
			? absint( dokan_get_vendor_by_product( $parent_id, true ) )
			: 0;
	}

	private function integerDecimal( string $value, string $message ): int {
		if ( 1 !== preg_match( '/\A([0-9]+)(?:\.0+)?\z/', trim( $value ), $matches ) ) {
			throw new InvalidArgumentException( $message );
		}
		$canonical = ltrim( $matches[1], '0' );
		$maximum = (string) PHP_INT_MAX;
		if ( '' === $canonical || strlen( $canonical ) > strlen( $maximum ) || ( strlen( $canonical ) === strlen( $maximum ) && strcmp( $canonical, $maximum ) > 0 ) ) {
			throw new InvalidArgumentException( $message );
		}
		return (int) $canonical;
	}

	private function positiveInteger( mixed $value, string $message ): int {
		$digits = $this->asciiDigits( trim( (string) $value ) );
		if ( 1 !== preg_match( '/\A0*([1-9][0-9]*)\z/', $digits, $matches ) ) {
			throw new InvalidArgumentException( $message );
		}
		$canonical = $matches[1];
		$maximum = (string) PHP_INT_MAX;
		if ( strlen( $canonical ) > strlen( $maximum ) || ( strlen( $canonical ) === strlen( $maximum ) && strcmp( $canonical, $maximum ) > 0 ) ) {
			throw new InvalidArgumentException( $message );
		}
		return (int) $canonical;
	}

	/** @return int|WP_Error */
	private function productWeightTenths( WC_Product $product ): int|WP_Error {
		$weight_text = (string) $product->get_weight();
		$parent_weight = '';
		if ( '' === trim( $weight_text ) && $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$parent_weight = $parent instanceof WC_Product ? (string) $parent->get_weight() : '';
		}

		return ProductWeightPolicy::resolve_tenths( $weight_text, $parent_weight );
	}

	private function asciiDigits( string $value ): string {
		return strtr(
			$value,
			array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' )
		);
	}

	/** @param array<string,mixed> $payload */
	private function deliveryWindow( array $payload ): DeliveryWindow {
		$type = sanitize_key( (string) ( $payload['delivery_window_type'] ?? '' ) );
		$timezone = wp_timezone();
		if ( 'exact' === $type ) {
			$start = $this->requestDateTime( $payload, 'exact_date_display', 'exact_date', 'start_time', $timezone );
			$end = $this->requestDateTime( $payload, 'exact_date_display', 'exact_date', 'end_time', $timezone );
			return DeliveryWindow::exact( $this->gmt( $start ), $this->gmt( $end ), current_time( 'mysql', true ), $timezone->getName() );
		}
		if ( 'flexible' === $type ) {
			$start = $this->requestDate( $payload, 'flex_start_date_display', 'flex_start_date', $timezone )->setTime( 0, 0, 0 );
			$end = $this->requestDate( $payload, 'flex_end_date_display', 'flex_end_date', $timezone )->setTime( 23, 59, 59 );
			return DeliveryWindow::flexible( $this->gmt( $start ), $this->gmt( $end ), current_time( 'mysql', true ), $timezone->getName() );
		}
		throw new InvalidArgumentException( __( 'نوع زمان دریافت معتبر نیست.', 'zarbalman-core' ) );
	}

	/** @param array<string,mixed> $payload */
	private function requestDateTime( array $payload, string $display_key, string $canonical_key, string $time_key, DateTimeZone $timezone ): DateTimeImmutable {
		$date = $this->requestDate( $payload, $display_key, $canonical_key, $timezone );
		$time = $this->asciiDigits( trim( (string) ( $payload[ $time_key ] ?? '' ) ) );
		if ( 1 !== preg_match( '/\A([01][0-9]|2[0-3]):([0-5][0-9])\z/', $time, $matches ) ) {
			throw new InvalidArgumentException( __( 'تاریخ یا ساعت انتخاب‌شده معتبر نیست.', 'zarbalman-core' ) );
		}
		return $date->setTime( (int) $matches[1], (int) $matches[2] );
	}

	/** @param array<string,mixed> $payload */
	private function requestDate( array $payload, string $display_key, string $canonical_key, DateTimeZone $timezone ): DateTimeImmutable {
		$display = trim( (string) ( $payload[ $display_key ] ?? '' ) );
		$canonical = $this->asciiDigits( trim( (string) ( $payload[ $canonical_key ] ?? '' ) ) );
		if ( '' === $display ) {
			throw new InvalidArgumentException( __( 'تاریخ شمسی انتخاب‌شده معتبر نیست.', 'zarbalman-core' ) );
		}
		try {
			$date = JalaliDate::parse( $display, $timezone );
		} catch ( InvalidArgumentException ) {
			throw new InvalidArgumentException( __( 'تاریخ شمسی انتخاب‌شده معتبر نیست.', 'zarbalman-core' ) );
		}
		if ( '' !== $canonical ) {
			$canonical_date = $this->localDate( $canonical, '!Y-m-d', $timezone );
			if ( $date->format( 'Y-m-d' ) !== $canonical_date->format( 'Y-m-d' ) ) {
				throw new InvalidArgumentException( __( 'تاریخ نمایش‌داده‌شده با مقدار استاندارد همخوان نیست.', 'zarbalman-core' ) );
			}
		}
		return $date;
	}

	private function localDate( string $value, string $format, DateTimeZone $timezone ): DateTimeImmutable {
		$date = DateTimeImmutable::createFromFormat( $format, trim( $value ), $timezone );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			throw new InvalidArgumentException( __( 'تاریخ یا ساعت انتخاب‌شده معتبر نیست.', 'zarbalman-core' ) );
		}
		return $date;
	}

	private function gmt( DateTimeImmutable $date ): string {
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	private function rateLimited( int $buyer_id ): bool {
		return (int) get_transient( 'zbm_request_rate_' . $buyer_id ) >= 10;
	}

	private function incrementRateLimit( int $buyer_id ): void {
		$key = 'zbm_request_rate_' . $buyer_id;
		set_transient( $key, (int) get_transient( $key ) + 1, 5 * MINUTE_IN_SECONDS );
	}

	public static function printRequestNotice(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$key = 'zbm_request_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		$message = isset( $notice['message'] ) ? (string) $notice['message'] : '';
		$type = isset( $notice['type'] ) ? (string) $notice['type'] : 'notice';
		if ( '' === $message ) {
			return;
		}
		if ( ! in_array( $type, array( 'error', 'success', 'notice' ), true ) ) {
			$type = 'notice';
		}
		$code = isset( $notice['code'] ) && is_string( $notice['code'] ) ? sanitize_key( $notice['code'] ) : '';
		echo '<div id="zbm-request-feedback" class="zbm-request-server-feedback" tabindex="-1" data-zbm-request-feedback>';
		wc_print_notice( esc_html( $message ), $type );
		if ( '' !== $code ) {
			echo '<p class="zbm-request-error-code">' . esc_html__( 'کد وضعیت:', 'zarbalman-core' ) . ' <code dir="ltr">' . esc_html( substr( $code, 0, 100 ) ) . '</code></p>';
		}
		echo '</div>';
	}

	private function redirectWithNotice( string $message, string $type, string $code = '' ): never {
		set_transient( 'zbm_request_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type, 'code' => $code ), 5 * MINUTE_IN_SECONDS );
		// Always choose a Core surface which actually consumes the notice; an
		// arbitrary referrer can silently lose it in a theme or login redirect.
		$target = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'purchase-requests' ) : home_url( '/order-review-by-buyer/' );
		$product_id = isset( $_POST['product_id'] ) && is_scalar( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Display-only error destination, including an invalid nonce response.
		$product = $product_id > 0 ? wc_get_product( $product_id ) : false;
		if ( $product instanceof WC_Product ) {
			$product_url = get_permalink( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id() );
			if ( is_string( $product_url ) && '' !== $product_url ) {
				$target = $product_url;
			}
		}
		$target = add_query_arg( 'zbm_request_feedback', '1', remove_query_arg( 'zbm_request_feedback', $target ) );
		$target = explode( '#', $target, 2 )[0] . '#zbm-request-feedback';
		RequestResponse::redirect( $target );
	}
}

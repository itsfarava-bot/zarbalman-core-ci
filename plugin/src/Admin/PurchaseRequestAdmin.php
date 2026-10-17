<?php

declare(strict_types=1);

namespace Zarbalman\Core\Admin;

use Throwable;
use Zarbalman\Core\Application\AdminTestAdvanceService;
use Zarbalman\Core\Application\AdminTestModeManager;
use Zarbalman\Core\Application\PurchaseRequestService;
use Zarbalman\Core\Domain\PurchaseRequest\DeliveryMethod;
use Zarbalman\Core\Domain\PurchaseRequest\ExecutionMode;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Domain\PurchaseRequest\RequestNumber;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;
use Zarbalman\Core\Frontend\RequestPresenter;
use Zarbalman\Core\Infrastructure\DeliveryDataCrypto;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;
use Zarbalman\Core\Security\AdminCapabilities;

final class PurchaseRequestAdmin {
	private const PAGE_SLUG  = 'zbm-core-requests';
	private const ACTION     = 'zbm_admin_request_control';
	private const NOTICE_KEY = 'zbm_admin_request_notice_';

	public function __construct(
		private readonly PurchaseRequestRepository $repository,
		private readonly PurchaseRequestService $service,
		private readonly RequestPresenter $presenter,
		private readonly AdminTestAdvanceService $test_advance,
		private readonly AdminTestModeManager $test_mode,
		private readonly DeliveryDataCrypto $delivery_crypto
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 61 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function assets(): void {
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Asset routing only.
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}
		wp_enqueue_style( 'zbm-admin-specs', plugins_url( 'assets/css/admin-specs.css', ZBM_CORE_FILE ), array(), ZBM_CORE_VERSION );
	}

	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'درخواست‌های زربال‌من', 'zarbalman-core' ),
			__( 'درخواست‌های زربال‌من', 'zarbalman-core' ),
			AdminCapabilities::MANAGE_OPERATIONS,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function handle(): never {
		if ( ! AdminCapabilities::currentUserCanOperate() ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز است.', 'zarbalman-core' ) );
		}
		$request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $request_id, '_zbm_nonce' );
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$version   = isset( $_POST['version'] ) ? absint( $_POST['version'] ) : -1;
		$reason    = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$seconds   = isset( $_POST['hours'] ) ? absint( $_POST['hours'] ) * HOUR_IN_SECONDS : 0;
		$idempotency = isset( $_POST['idempotency_key'] ) ? sanitize_text_field( wp_unslash( $_POST['idempotency_key'] ) ) : '';

		try {
			$result = match ( $operation ) {
				'pause'           => $this->service->setPaused( $request_id, get_current_user_id(), $version, true, $reason ),
				'resume'          => $this->service->setPaused( $request_id, get_current_user_id(), $version, false, $reason ),
				'reopen_supplier' => $this->service->reopenSupplierDeadline( $request_id, get_current_user_id(), $seconds, $reason ),
				'reopen_deposit'  => $this->service->reopenDepositDeadline( $request_id, get_current_user_id(), $seconds, $reason ),
				'accept_late'     => $this->service->acceptLatePayment( $request_id, get_current_user_id(), $reason ),
				'cancel_review'   => $this->service->cancelUnpaidAdminReview( $request_id, get_current_user_id(), $version, $reason ),
				'test_advance'    => $this->recoverLegacyTest( $request_id, $version, $reason, $idempotency ),
				default           => new \WP_Error( 'zbm_admin_operation_invalid', __( 'عملیات مدیریت معتبر نیست.', 'zarbalman-core' ) ),
			};
		} catch ( Throwable ) {
			$result = new \WP_Error( 'zbm_admin_operation_failed', __( 'عملیات مدیریت به‌صورت امن متوقف شد؛ وضعیت پرونده را بررسی کنید.', 'zarbalman-core' ) );
		}

		if ( is_wp_error( $result ) ) {
			$this->redirect( $result->get_error_message(), 'error' );
		}
		$this->redirect( __( 'عملیات مدیریت همراه با دلیل و سابقه حسابرسی ثبت شد.', 'zarbalman-core' ), 'success' );
	}

	public function render(): void {
		if ( ! AdminCapabilities::currentUserCanOperate() ) {
			return;
		}
		$notice = get_transient( self::NOTICE_KEY . get_current_user_id() );
		delete_transient( self::NOTICE_KEY . get_current_user_id() );
		$raw_request_number = isset( $_GET['request_number'] ) && is_scalar( $_GET['request_number'] )
			? sanitize_text_field( wp_unslash( (string) $_GET['request_number'] ) )
			: '';
		$request_number = RequestNumber::normalize( $raw_request_number );
		$searching = '' !== trim( $raw_request_number );
		$search_error = $searching && ! RequestNumber::isValid( $request_number );
		$requests = $search_error
			? array()
			: $this->repository->recentForAdmin( 100, RequestNumber::isValid( $request_number ) ? $request_number : null );
		?>
		<div class="wrap zbm-admin-wrap" dir="rtl">
			<h1><?php echo esc_html__( 'کنترل درخواست‌های خرید زربال‌من', 'zarbalman-core' ); ?></h1>
			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( 'success' === ( $notice['type'] ?? '' ) ? 'success' : 'error' ); ?> is-dismissible"><p><?php echo esc_html( (string) ( $notice['message'] ?? '' ) ); ?></p></div>
			<?php endif; ?>
			<p><?php echo esc_html__( 'توقف، ادامه و بازگشایی فقط زمان مجاز همان مرحله را تغییر می‌دهد و هرگز پرداخت را موفق یا سفارش را تسویه‌شده اعلام نمی‌کند.', 'zarbalman-core' ); ?></p>
			<form method="get" class="zbm-admin-request-search" aria-label="<?php esc_attr_e( 'جست‌وجوی درخواست', 'zarbalman-core' ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<label for="zbm-admin-request-number"><?php esc_html_e( 'جست‌وجوی شناسه درخواست', 'zarbalman-core' ); ?></label>
				<input id="zbm-admin-request-number" name="request_number" type="text" inputmode="numeric" autocomplete="off" maxlength="6" value="<?php echo esc_attr( $raw_request_number ); ?>" placeholder="<?php esc_attr_e( 'مثلاً ۶۳۲۹۳۴', 'zarbalman-core' ); ?>">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'جست‌وجو', 'zarbalman-core' ); ?></button>
				<?php if ( $searching ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'پاک‌کردن', 'zarbalman-core' ); ?></a><?php endif; ?>
				<p class="description"><?php esc_html_e( 'شناسه عمومی هر درخواست شش رقم است. ارقام فارسی و انگلیسی هر دو پذیرفته می‌شوند.', 'zarbalman-core' ); ?></p>
			</form>
			<?php if ( $search_error ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'شناسه درخواست باید دقیقاً شش رقم باشد.', 'zarbalman-core' ); ?></p></div><?php endif; ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php echo esc_html__( 'درخواست', 'zarbalman-core' ); ?></th>
					<th><?php echo esc_html__( 'کالا / طرفین', 'zarbalman-core' ); ?></th>
					<th><?php echo esc_html__( 'وضعیت و مهلت', 'zarbalman-core' ); ?></th>
					<th><?php echo esc_html__( 'استان / حمل', 'zarbalman-core' ); ?></th>
					<th><?php echo esc_html__( 'سفارش', 'zarbalman-core' ); ?></th>
					<th><?php echo esc_html__( 'کنترل مدیریت', 'zarbalman-core' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $requests ) : ?>
					<tr><td colspan="6"><?php echo esc_html( $searching && ! $search_error ? __( 'درخواستی با این شناسه پیدا نشد.', 'zarbalman-core' ) : __( 'هنوز درخواست مستقلی ثبت نشده است.', 'zarbalman-core' ) ); ?></td></tr>
				<?php else : foreach ( $requests as $request ) : $view = $this->presenter->present( $request ); ?>
					<tr>
						<td><strong>#<?php echo esc_html( $request->requestNumber() ); ?></strong></td>
						<td><?php echo esc_html( (string) $view['product_name'] ); ?><?php if ( $request->isMultiline() ) : ?><details><summary><?php esc_html_e( 'مشاهدهٔ همهٔ محصولات', 'zarbalman-core' ); ?></summary><?php include ZBM_CORE_DIR . 'templates/request-items.php'; ?></details><?php endif; ?><br><?php echo esc_html( sprintf( __( 'خریدار: %1$d — فروشنده: %2$d', 'zarbalman-core' ), $request->buyerId(), $request->vendorId() ) ); ?></td>
						<td><strong><?php echo esc_html( (string) $view['state_label'] ); ?></strong><?php echo $request->isTest() ? '<br><span class="zbm-admin-test-badge">' . esc_html__( 'آزمایشی؛ بدون دریافت وجه', 'zarbalman-core' ) . '</span>' : ''; ?><?php echo $request->isPaused() ? '<br>⏸ ' . esc_html__( 'متوقف توسط مدیریت', 'zarbalman-core' ) : ''; ?><br><?php echo esc_html( (string) ( $view['deadline_label'] ?? '—' ) ); ?></td>
						<td><?php $this->renderShippingSummary( $request, $view ); ?></td>
						<td><?php echo $request->wcOrderId() ? '<a href="' . esc_url( admin_url( 'post.php?post=' . $request->wcOrderId() . '&action=edit' ) ) . '">#' . absint( $request->wcOrderId() ) . '</a>' : '—'; ?></td>
						<td><?php $this->controlForm( $request ); ?></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** @param array<string,mixed> $view */
	private function renderShippingSummary( PurchaseRequest $request, array $view ): void {
		try {
			$delivery = $this->delivery_crypto->decrypt( $request->encryptedDeliveryPayload() );
		} catch ( Throwable ) {
			echo esc_html__( '—', 'zarbalman-core' );
			return;
		}
		$window = trim( (string) ( $view['delivery_window'] ?? '' ) );
		if ( DeliveryMethod::PICKUP_FROM_VENDOR === $request->deliveryMethod() ) {
			echo '<strong>' . esc_html__( 'دریافت حضوری از فروشنده', 'zarbalman-core' ) . '</strong>';
			if ( '' !== $window ) {
				echo '<br>' . esc_html( sprintf( __( 'زمان: %s', 'zarbalman-core' ), $window ) );
			}
			$recipient = trim( (string) ( $delivery['recipient_name'] ?? '' ) );
			if ( '' !== $recipient ) {
				echo '<br>' . esc_html( sprintf( __( 'دریافت‌کننده: %s', 'zarbalman-core' ), $recipient ) );
			}
			$pickup_address = is_array( $delivery['pickup_address'] ?? null ) ? $delivery['pickup_address'] : array();
			foreach ( array( 'store_name' => __( 'فروشگاه', 'zarbalman-core' ), 'street_1' => __( 'نشانی', 'zarbalman-core' ), 'street_2' => __( 'ادامه نشانی', 'zarbalman-core' ), 'city' => __( 'شهر فروشگاه', 'zarbalman-core' ), 'state' => __( 'استان فروشگاه', 'zarbalman-core' ), 'zip' => __( 'کدپستی فروشگاه', 'zarbalman-core' ), 'country' => __( 'کشور', 'zarbalman-core' ) ) as $field => $label ) {
				$value = is_string( $pickup_address[ $field ] ?? null ) ? trim( $pickup_address[ $field ] ) : '';
				if ( '' !== $value ) {
					echo '<br>' . esc_html( $label . ': ' . $value );
				}
			}
			$pickup_note = is_string( $delivery['pickup_note'] ?? null ) ? trim( $delivery['pickup_note'] ) : '';
			echo '<br>' . esc_html( sprintf( __( 'توضیحات مراجعه: %s', 'zarbalman-core' ), '' !== $pickup_note ? $pickup_note : __( 'بدون توضیح', 'zarbalman-core' ) ) );
			if ( true === ( $delivery['pickup_visit_confirmed'] ?? null ) ) {
				echo '<br>' . esc_html__( 'تأیید مراجعه: ثبت شده', 'zarbalman-core' );
			}
			return;
		}
		echo '<strong>' . esc_html__( 'تحویل در نشانی خریدار', 'zarbalman-core' ) . '</strong>';
		if ( '' !== $window ) {
			echo '<br>' . esc_html( sprintf( __( 'زمان: %s', 'zarbalman-core' ), $window ) );
		}
		$recipient = trim( (string) ( $delivery['recipient_name'] ?? '' ) );
		if ( '' !== $recipient ) {
			echo '<br>' . esc_html( sprintf( __( 'دریافت‌کننده: %s', 'zarbalman-core' ), $recipient ) );
		}
		$province = trim( (string) ( $delivery['province_name'] ?? '' ) );
		if ( '' === $province ) {
			echo '<br>' . esc_html__( 'اطلاعات استان در درخواست قدیمی ثبت نشده است', 'zarbalman-core' );
			return;
		}
		echo '<br>' . esc_html( sprintf( __( 'استان: %s', 'zarbalman-core' ), $province ) );
		$postal = trim( (string) ( $delivery['postal_code'] ?? '' ) );
		if ( '' !== $postal ) {
			echo '<br>' . esc_html( sprintf( __( 'کدپستی: %s', 'zarbalman-core' ), $postal ) );
		}
		$address = trim( (string) ( $delivery['address'] ?? '' ) );
		if ( '' !== $address ) {
			echo '<br>' . esc_html( sprintf( __( 'نشانی: %s', 'zarbalman-core' ), $address ) );
		}
		if ( isset( $delivery['shipping_rate_per_kg_toman'] ) ) {
			echo '<br>' . esc_html( number_format_i18n( (int) $delivery['shipping_rate_per_kg_toman'] ) . ' ' . __( 'تومان/کیلو', 'zarbalman-core' ) );
		}
		if ( isset( $delivery['estimated_shipping_toman'] ) ) {
			echo '<br>' . esc_html( 0 === (int) $delivery['estimated_shipping_toman'] ? __( 'حمل رایگان', 'zarbalman-core' ) : number_format_i18n( (int) $delivery['estimated_shipping_toman'] ) . ' ' . ( 2 === $request->pricingVersion() ? __( 'تومان؛ مشمول پیش‌پرداخت', 'zarbalman-core' ) : __( 'تومان؛ خارج از پیش‌پرداخت', 'zarbalman-core' ) ) );
		}
		$grand_total = $this->presenter->estimatedGrandTotal( $request, $delivery );
		if ( is_string( $grand_total ) ) {
			echo '<br>' . esc_html( sprintf( __( 'جمع تقریبی نمایشی: %s', 'zarbalman-core' ), $grand_total ) );
		}
		$rate_version = $delivery['shipping_rate_version'] ?? null;
		if ( is_string( $rate_version ) ) {
			$rate_version = trim( $rate_version );
			if ( 1 === preg_match( '/\A[a-f0-9]{64}\z/i', $rate_version ) ) {
				$short_version = substr( $rate_version, 0, 16 ) . '…';
				echo '<br><span>' . esc_html__( 'نسخه snapshot نرخ:', 'zarbalman-core' ) . ' <code dir="ltr" title="' . esc_attr( $rate_version ) . '">' . esc_html( $short_version ) . '</code></span>';
			}
		}
	}

	private function controlForm( PurchaseRequest $request ): void {
		$request_id = $request->id();
		$version = $request->version();
		$state = $request->state();
		$paused = $request->isPaused();
		if ( in_array( $state, array( RequestState::SUPPLIER_REJECTED, RequestState::CANCELLED, RequestState::DEPOSIT_PAID ), true ) ) {
			echo $request->isTest()
				? esc_html__( 'پرونده آزمایشی آمادهٔ مرحله بعد است؛ وجهی دریافت نشده.', 'zarbalman-core' )
				: esc_html__( 'اقدام عملیاتی ندارد', 'zarbalman-core' );
			return;
		}
		if ( RequestState::SUPPLIER_ACCEPTED === $state ) {
			if (
				AdminCapabilities::currentUserCanManageTestMode()
				&& ExecutionMode::TEST_PENDING === $request->executionMode()
			) {
				$this->testAdvanceForm( $request );
			} else {
				echo esc_html__( 'درخواست پذیرفته شده است؛ ساخت لینک واقعی پس از آماده‌شدن وابستگی‌های پرداخت بازیابی می‌شود.', 'zarbalman-core' );
			}
			return;
		}
		$operation = $paused ? 'resume' : 'pause';
		$label     = $paused ? __( 'ادامه زمان', 'zarbalman-core' ) : __( 'توقف زمان', 'zarbalman-core' );
		if ( RequestState::SUPPLIER_RESPONSE_EXPIRED === $state ) {
			$operation = 'reopen_supplier';
			$label = __( 'بازگشایی مهلت فروشنده', 'zarbalman-core' );
		} elseif ( RequestState::DEPOSIT_EXPIRED === $state ) {
			$operation = 'reopen_deposit';
			$label = __( 'بازگشایی مهلت پیش‌پرداخت', 'zarbalman-core' );
		} elseif ( RequestState::LATE_PAYMENT_REVIEW === $state ) {
			$operation = 'accept_late';
			$label = __( 'پذیرش پرداخت قطعی و ادامه', 'zarbalman-core' );
		} elseif ( RequestState::ADMIN_REVIEW === $state ) {
			$operation = 'cancel_review';
			$label = __( 'لغو امن پروندهٔ پرداخت‌نشده', 'zarbalman-core' );
		}
		$is_reopen = str_starts_with( $operation, 'reopen_' );
		$reopen_max_hours = 'reopen_deposit' === $operation ? 24 : 1;
		$show_unverified_cancel = RequestState::LATE_PAYMENT_REVIEW === $state;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="zbm-admin-control">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="request_id" value="<?php echo absint( $request_id ); ?>">
			<input type="hidden" name="version" value="<?php echo absint( $version ); ?>">
			<input type="hidden" name="operation" value="<?php echo esc_attr( $operation ); ?>">
			<?php wp_nonce_field( self::ACTION . '_' . $request_id, '_zbm_nonce' ); ?>
			<?php if ( $is_reopen ) : ?>
				<label><?php echo esc_html__( 'ساعت جدید', 'zarbalman-core' ); ?> <input type="number" min="1" max="<?php echo absint( $reopen_max_hours ); ?>" value="<?php echo absint( $reopen_max_hours ); ?>" name="hours" required></label><br>
			<?php endif; ?>
			<textarea name="reason" rows="2" maxlength="1000" required placeholder="<?php echo esc_attr__( 'دلیل اجباری مدیریت', 'zarbalman-core' ); ?>"></textarea><br>
			<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'این اقدام با نام شما در حسابرسی ثبت می‌شود. ادامه می‌دهید؟', 'zarbalman-core' ) ); ?>')"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php if ( $show_unverified_cancel ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="zbm-admin-control">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="request_id" value="<?php echo absint( $request_id ); ?>">
				<input type="hidden" name="version" value="<?php echo absint( $version ); ?>">
				<input type="hidden" name="operation" value="cancel_review">
				<?php wp_nonce_field( self::ACTION . '_' . $request_id, '_zbm_nonce' ); ?>
				<textarea name="reason" rows="2" maxlength="1000" required placeholder="<?php echo esc_attr__( 'دلیل لغو پس از استعلام ناموفق زرین‌پال', 'zarbalman-core' ); ?>"></textarea><br>
				<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Core ابتدا مستقیماً زرین‌پال را استعلام می‌کند؛ فقط اگر پرداخت قطعی نباشد لغو می‌شود. ادامه می‌دهید؟', 'zarbalman-core' ) ); ?>')"><?php echo esc_html__( 'استعلام و لغو پرداخت‌نشده', 'zarbalman-core' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

	private function testAdvanceForm( PurchaseRequest $request ): void {
		?>
		<div class="zbm-admin-test-action">
			<strong><?php esc_html_e( 'بازیابی پروندهٔ آزمایشی قدیمی', 'zarbalman-core' ); ?></strong>
			<p><?php echo esc_html__( 'این اقدام فقط سفارش آزمایشی و رزرو موجودی می‌سازد؛ پرداخت، ledger و اعتبار Dokan ایجاد نمی‌شود.', 'zarbalman-core' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="zbm-admin-control">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="request_id" value="<?php echo absint( $request->id() ); ?>">
				<input type="hidden" name="version" value="<?php echo absint( $request->version() ); ?>">
				<input type="hidden" name="operation" value="test_advance">
				<input type="hidden" name="idempotency_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
				<?php wp_nonce_field( self::ACTION . '_' . $request->id(), '_zbm_nonce' ); ?>
				<textarea name="reason" rows="3" maxlength="1000" required placeholder="<?php echo esc_attr__( 'دلیل اجباری؛ بدون تلفن یا ایمیل', 'zarbalman-core' ); ?>"></textarea><br>
				<button type="submit" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'هیچ وجهی ثبت نمی‌شود و پرونده با برچسب آزمایشی قرنطینه می‌ماند. ادامه می‌دهید؟', 'zarbalman-core' ) ); ?>')"><?php esc_html_e( 'تکمیل بازیابی آزمایشی', 'zarbalman-core' ); ?></button>
			</form>
		</div>
		<?php
	}

	/** @return PurchaseRequest|\WP_Error Legacy TEST_PENDING only; no new test execution can start. */
	private function recoverLegacyTest( int $request_id, int $version, string $reason, string $idempotency ): PurchaseRequest|\WP_Error {
		$request = $this->repository->find( $request_id );
		if ( ! $request || ExecutionMode::TEST_PENDING !== $request->executionMode() ) {
			return new \WP_Error( 'zbm_legacy_test_only', __( 'شروع پروندهٔ آزمایشی جدید غیرفعال است؛ فقط بازیابی پروندهٔ قدیمی مجاز است.', 'zarbalman-core' ) );
		}
		return $this->test_advance->advanceDeposit( $request_id, get_current_user_id(), $version, $reason, $idempotency, wp_generate_uuid4() );
	}

	private function redirect( string $message, string $type ): never {
		set_transient( self::NOTICE_KEY . get_current_user_id(), array( 'message' => $message, 'type' => $type ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}
}

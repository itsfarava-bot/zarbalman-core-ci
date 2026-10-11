<?php

declare(strict_types=1);

namespace Zarbalman\Core\Admin;

use Throwable;
use Zarbalman\Core\Application\ManualPaymentSettings;
use Zarbalman\Core\Domain\ManagedFlow\FlowState;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;
use Zarbalman\Core\Frontend\ManagedCasePanel;
use Zarbalman\Core\Frontend\RequestPresenter;
use Zarbalman\Core\Frontend\RequestResponse;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;
use Zarbalman\Core\Infrastructure\ManagedCaseRepository;
use Zarbalman\Core\Infrastructure\PaymentRepository;
use Zarbalman\Core\Infrastructure\DependencyHealth;
use Zarbalman\Core\Infrastructure\OnlinePaymentAvailability;
use Zarbalman\Core\Security\AdminCapabilities;

/** Finance settings and role-safe request review; financial mutations stay in the shared controller. */
final class ManualPaymentsPage {
	public const PAGE_SLUG = 'zbm-core-manual-payments';
	public const REPORT_SLUG = 'zbm-core-manual-ledger';
	public const OPERATIONS_SLUG = 'zbm-core-operations';
	private const SETTINGS_ACTION = 'zbm_manual_payment_settings';
	private const ONLINE_ACTION = 'zbm_online_payment_control';
	private const NOTICE_KEY = 'zbm_manual_settings_notice_';

	public function __construct(
		private readonly PurchaseRequestRepository $requests,
		private readonly RequestPresenter $presenter,
		private readonly ManagedCasePanel $panel,
		private readonly ManualPaymentSettings $settings,
		private readonly ManagedCaseRepository $cases,
		private readonly PaymentRepository $payments,
		private readonly DependencyHealth $health,
		private readonly ?OnlinePaymentAvailability $availability = null
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 63 );
		add_action( 'admin_post_' . self::SETTINGS_ACTION, array( $this, 'saveSettings' ) );
		add_action( 'admin_post_' . self::ONLINE_ACTION, array( $this, 'saveOnlineControl' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu(): void {
		add_submenu_page( 'woocommerce', __( 'مرکز عملیات درخواست‌ها', 'zarbalman-core' ), __( 'عملیات درخواست‌ها', 'zarbalman-core' ), AdminCapabilities::MANAGE_OPERATIONS, self::OPERATIONS_SLUG, array( $this, 'renderOperations' ) );
		add_submenu_page(
			'woocommerce',
			__( 'پرداخت دستی واقعی و تحویل', 'zarbalman-core' ),
			__( 'پرداخت دستی واقعی', 'zarbalman-core' ),
			AdminCapabilities::MANAGE_FINANCE,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
		add_submenu_page(
			'woocommerce',
			__( 'گزارش تأییدهای پرداخت دستی', 'zarbalman-core' ),
			__( 'گزارش پرداخت‌های دستی', 'zarbalman-core' ),
			AdminCapabilities::MANAGE_FINANCE,
			self::REPORT_SLUG,
			array( $this, 'renderLedger' )
		);
	}

	public function assets(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only asset routing.
		if ( ! in_array( $page, array( self::PAGE_SLUG, self::REPORT_SLUG, self::OPERATIONS_SLUG, Spec4FinancePage::SLUG ), true ) ) {
			return;
		}
		wp_enqueue_style( 'zbm-admin-specs', plugins_url( 'assets/css/admin-specs.css', ZBM_CORE_FILE ), array(), ZBM_CORE_VERSION );
		wp_enqueue_style( 'zbm-managed-case', plugins_url( 'assets/css/managed-case.css', ZBM_CORE_FILE ), array(), ZBM_CORE_VERSION );
		wp_register_script( 'zbm-payment-clock', plugins_url( 'assets/js/payment-clock.js', ZBM_CORE_FILE ), array(), ZBM_CORE_VERSION, true );
		wp_localize_script( 'zbm-payment-clock', 'zbmPaymentClock', array( 'now' => time() ) );
		wp_enqueue_script( 'zbm-managed-case', plugins_url( 'assets/js/managed-case.js', ZBM_CORE_FILE ), array( 'zbm-payment-clock' ), ZBM_CORE_VERSION, true );
	}

	public function saveSettings(): never {
		RequestResponse::preventCaching();
		if ( ! AdminCapabilities::currentUserCanManageFinance() ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز است.', 'zarbalman-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::SETTINGS_ACTION, '_zbm_nonce' );
		$values = array(
			'enabled' => isset( $_POST['enabled'] ) ? sanitize_key( wp_unslash( $_POST['enabled'] ) ) : '0',
			'card_number' => isset( $_POST['card_number'] ) ? sanitize_text_field( wp_unslash( $_POST['card_number'] ) ) : '',
			'holder_name' => isset( $_POST['holder_name'] ) ? sanitize_text_field( wp_unslash( $_POST['holder_name'] ) ) : '',
		);
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		try {
			$result = $this->settings->save( get_current_user_id(), $values, $reason );
		} catch ( Throwable ) {
			$result = new \WP_Error( 'zbm_manual_settings_unavailable', __( 'تنظیمات به‌صورت امن ذخیره نشد.', 'zarbalman-core' ) );
		}
		set_transient(
			self::NOTICE_KEY . get_current_user_id(),
			is_wp_error( $result )
				? array( 'type' => 'error', 'message' => $result->get_error_message() )
				: array( 'type' => 'success', 'message' => __( 'تنظیمات پرداخت دستی واقعی همراه با سابقهٔ حسابرسی ذخیره شد.', 'zarbalman-core' ) ),
			MINUTE_IN_SECONDS
		);
		RequestResponse::redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
	}

	public function saveOnlineControl(): never {
		RequestResponse::preventCaching();
		if ( ! AdminCapabilities::currentUserCanManageFinance() || null === $this->availability ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز است.', 'zarbalman-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ONLINE_ACTION, '_zbm_nonce' );
		$revision = isset( $_POST['revision'] ) && is_string( $_POST['revision'] ) && ctype_digit( $_POST['revision'] ) ? (int) $_POST['revision'] : -1;
		$reason = isset( $_POST['reason'] ) && is_string( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$result = $this->availability->setStopped( get_current_user_id(), isset( $_POST['stopped'] ) && '1' === $_POST['stopped'], $revision, $reason );
		set_transient( self::NOTICE_KEY . get_current_user_id(), is_wp_error( $result )
			? array( 'type' => 'error', 'message' => $result->get_error_message() )
			: array( 'type' => 'success', 'message' => __( 'وضعیت ایجاد لینک جدید همراه با نام مدیر و دلیل تغییر ثبت شد.', 'zarbalman-core' ) ), MINUTE_IN_SECONDS );
		RequestResponse::redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
	}

	public function render(): void {
		if ( ! AdminCapabilities::currentUserCanManageFinance() ) {
			return;
		}
		RequestResponse::preventCaching();
		$notice = get_transient( self::NOTICE_KEY . get_current_user_id() );
		delete_transient( self::NOTICE_KEY . get_current_user_id() );
		$account = $this->settings->publicAccount();
		$enabled = $this->settings->enabled();
		$requests = array_filter( $this->requests->recentForAdmin( 200 ), fn ( PurchaseRequest $item ): bool => $this->isManualCandidate( $item, $enabled ) );
		$selected_id = isset( $_GET['request'] ) ? absint( $_GET['request'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only capability-gated detail.
		$selected = null;
		if ( $selected_id > 0 ) {
			try {
				$selected = $this->requests->find( $selected_id );
			} catch ( Throwable ) {
				$selected = null;
			}
		}
		?>
		<div class="wrap zbm-admin-wrap zbm-manual-admin" dir="rtl">
			<div class="zbm-admin-hero">
				<span class="zbm-admin-kicker"><?php echo esc_html( sprintf( __( 'Zarbalman Core %s', 'zarbalman-core' ), ZBM_CORE_VERSION ) ); ?></span>
				<h1><?php esc_html_e( 'پرداخت دستی واقعی و گردش تحویل', 'zarbalman-core' ); ?></h1>
				<p><?php esc_html_e( 'آپلود رسید به‌تنهایی پرداخت نیست. فقط پس از تطبیق بانکی و تأیید صریح مدیر مالی، وجه قطعی و مرحلهٔ بعد ثبت می‌شود.', 'zarbalman-core' ); ?></p>
			</div>
			<?php if ( is_array( $notice ) ) : ?><div class="notice notice-<?php echo 'success' === ( $notice['type'] ?? '' ) ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( (string) ( $notice['message'] ?? '' ) ); ?></p></div><?php endif; ?>
			<?php if ( null !== $this->availability ) : $online_control = $this->availability->control(); ?>
			<section class="zbm-admin-card zbm-admin-form-card">
				<h2><?php esc_html_e( 'انتخاب روش پرداخت', 'zarbalman-core' ); ?></h2>
				<p><?php esc_html_e( 'برای پیش‌پرداخت و مانده، هنگام آماده‌بودن زرین‌پال فقط لینک آنلاین نمایش داده می‌شود. هنگام اختلال یا توقف موقت توسط مدیر، پرداخت دستیِ فعال نمایش داده می‌شود. رسید تعهدهای دستی قبلی همچنان قابل پیگیری است.', 'zarbalman-core' ); ?></p>
				<p><strong><?php echo esc_html( $this->health->onlineAvailableForNewPayment() ? __( 'ایجاد لینک جدید زرین‌پال مجاز است.', 'zarbalman-core' ) : __( 'ایجاد لینک جدید زرین‌پال اکنون متوقف است.', 'zarbalman-core' ) ); ?></strong></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="zbm-manual-settings-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ONLINE_ACTION ); ?>">
					<input type="hidden" name="revision" value="<?php echo esc_attr( (string) $online_control['revision'] ); ?>">
					<?php wp_nonce_field( self::ONLINE_ACTION, '_zbm_nonce' ); ?>
					<label><input type="checkbox" name="stopped" value="1" <?php checked( $online_control['stopped'] ); ?>> <?php esc_html_e( 'ایجاد لینک جدید زرین‌پال را موقتاً متوقف کن', 'zarbalman-core' ); ?></label>
					<p><?php esc_html_e( 'استعلام و ثبت پرداخت‌های قبلی ادامه دارد. پس از رفع اختلال، این تیک را بردارید؛ خطای اتصال اخیر ممکن است تا پنج دقیقه در وضعیت درگاه باقی بماند.', 'zarbalman-core' ); ?></p>
					<label><?php esc_html_e( 'دلیل تغییر', 'zarbalman-core' ); ?><textarea name="reason" rows="2" maxlength="1000" required></textarea></label>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'ثبت وضعیت درگاه', 'zarbalman-core' ); ?></button>
				</form>
				<?php if ( ! empty( $online_control['actor_id'] ) ) : $actor = get_userdata( (int) $online_control['actor_id'] ); ?>
					<p><?php echo esc_html( sprintf( __( 'آخرین تغییر: %1$s؛ %2$s (UTC)', 'zarbalman-core' ), $actor ? $actor->display_name : (string) $online_control['actor_id'], (string) $online_control['at_gmt'] ) ); ?></p>
					<p><?php echo esc_html( (string) $online_control['reason'] ); ?></p>
				<?php endif; ?>
			</section>
			<?php endif; ?>

			<section class="zbm-admin-card zbm-admin-form-card">
				<h2><?php esc_html_e( 'تنظیمات پرداخت دستی واقعی', 'zarbalman-core' ); ?></h2>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::REPORT_SLUG ) ); ?>"><?php esc_html_e( 'مشاهده گزارش قطعی پرداخت‌های دستی', 'zarbalman-core' ); ?></a></p>
				<p><?php esc_html_e( 'این حالت پیش‌فرض خاموش است. هر تعهد، شماره کارت همین لحظه را به‌صورت رمز‌شده snapshot می‌کند؛ تغییر بعدی تنظیمات، تعهد قبلی را عوض نمی‌کند.', 'zarbalman-core' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="zbm-manual-settings-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SETTINGS_ACTION ); ?>"><?php wp_nonce_field( self::SETTINGS_ACTION, '_zbm_nonce' ); ?>
					<div class="zbm-manual-toggle-row">
						<label class="zbm-manual-toggle"><input type="checkbox" name="enabled" value="1" data-zbm-manual-toggle aria-describedby="zbm-manual-toggle-help" <?php checked( $enabled ); ?>> <span><?php esc_html_e( 'پرداخت دستی واقعی برای مرحله‌های پرداخت‌نشدهٔ واجدشرایط فعال باشد', 'zarbalman-core' ); ?></span></label>
						<span class="zbm-manual-toggle-status <?php echo $enabled ? 'is-enabled' : 'is-disabled'; ?>" data-zbm-manual-status><?php echo esc_html( $enabled ? __( 'فعال', 'zarbalman-core' ) : __( 'غیرفعال', 'zarbalman-core' ) ); ?></span>
					</div>
					<p id="zbm-manual-toggle-help" class="zbm-manual-toggle-help"><?php esc_html_e( 'هنگام خرابی یا توقف موقت زرین‌پال، پرداخت دستی برای پیش‌پرداخت و ماندهٔ واجدشرایط نمایش داده می‌شود. رسیدهای در انتظار بررسی و تعهدهای دستی قبلی تا تعیین نتیجه حفظ می‌شوند.', 'zarbalman-core' ); ?></p>
					<label><?php esc_html_e( 'شماره کارت ۱۶ رقمی', 'zarbalman-core' ); ?><input type="text" name="card_number" inputmode="numeric" maxlength="25" placeholder="<?php echo array() === $account ? '' : esc_attr__( 'برای حفظ شماره فعلی خالی بگذارید', 'zarbalman-core' ); ?>"></label>
					<label><?php esc_html_e( 'نام دارنده کارت', 'zarbalman-core' ); ?><input type="text" name="holder_name" maxlength="200" placeholder="<?php echo array() === $account ? '' : esc_attr( $account['holder_name'] ); ?>"></label>
					<?php if ( array() !== $account ) : ?><p><strong><?php esc_html_e( 'کارت فعلی:', 'zarbalman-core' ); ?></strong> <span dir="ltr"><?php echo esc_html( '**** **** **** ' . substr( $account['card_number'], -4 ) ); ?></span></p><?php endif; ?>
					<label><?php esc_html_e( 'دلیل اجباری تغییر تنظیمات', 'zarbalman-core' ); ?><textarea name="reason" rows="3" maxlength="1000" required></textarea></label>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'ذخیره امن تنظیمات', 'zarbalman-core' ); ?></button>
				</form>
			</section>

			<?php if ( $selected ) : ?>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">← <?php esc_html_e( 'بازگشت به فهرست', 'zarbalman-core' ); ?></a></p>
				<?php echo $this->panel->render( $selected, 'admin', get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Role-safe panel escapes template data. ?>
			<?php else : ?>
				<section class="zbm-admin-card">
					<h2><?php esc_html_e( 'پرونده‌های واجدشرایط اخیر برای پیش‌پرداخت یا مانده', 'zarbalman-core' ); ?></h2>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'درخواست', 'zarbalman-core' ); ?></th><th><?php esc_html_e( 'کالا و طرفین', 'zarbalman-core' ); ?></th><th><?php esc_html_e( 'وضعیت درخواست', 'zarbalman-core' ); ?></th><th><?php esc_html_e( 'بررسی', 'zarbalman-core' ); ?></th></tr></thead><tbody>
					<?php if ( array() === $requests ) : ?><tr><td colspan="4"><?php esc_html_e( 'درخواستی وجود ندارد.', 'zarbalman-core' ); ?></td></tr><?php else : foreach ( $requests as $request ) : $view = $this->presenter->present( $request ); ?>
						<tr><td><strong>#<?php echo esc_html( $request->requestNumber() ); ?></strong></td><td><?php echo esc_html( (string) $view['product_name'] ); ?><br><?php echo esc_html( sprintf( __( 'خریدار %1$d — فروشنده %2$d', 'zarbalman-core' ), $request->buyerId(), $request->vendorId() ) ); ?></td><td><?php echo esc_html( (string) $view['state_label'] ); ?></td><td><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'request' => $request->id() ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'بازکردن پرونده', 'zarbalman-core' ); ?></a></td></tr>
					<?php endforeach; endif; ?>
					</tbody></table>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}

	/** This list is operational; settled payments remain in the independent finance ledger. */
	private function isManualCandidate( PurchaseRequest $request, bool $enabled ): bool {
		try {
			$case = $this->cases->find( $request->id() );
		} catch ( Throwable ) {
			return false;
		}
		$deposit = $case['data']['manual']['deposit'] ?? null;
		$balance = $case['data']['manual']['balance'] ?? null;
		if ( \Zarbalman\Core\Domain\PurchaseRequest\RequestPricingPolicy::OPERATIONAL_LOCK === ( $case['data']['final']['lock_version'] ?? null ) && ! isset( $case['data']['spec4'] ) ) { return false; }
		if ( in_array( $request->state(), array( RequestState::SUPPLIER_ACCEPTED, RequestState::DEPOSIT_PENDING ), true ) ) {
			return is_array( $deposit )
				? in_array( $deposit['status'] ?? '', array( 'offered', 'submitted', 'rejected' ), true )
					: $enabled && ! $this->health->onlineAvailableForNewPayment();
		}
		if ( RequestState::DEPOSIT_PAID === $request->state() && in_array( $case['state'] ?? null, array( FlowState::FINAL_WEIGHT_LOCKED, FlowState::BALANCE_DUE ), true ) ) {
			return is_array( $balance )
				? in_array( $balance['status'] ?? '', array( 'offered', 'submitted', 'rejected' ), true )
				: $enabled;
		}
		return false;
	}

	/** Operational queues for all payment methods; no new settlement workflow. */
	public function renderOperations(): void {
		if ( ! AdminCapabilities::currentUserCanOperate() ) { return; }
		RequestResponse::preventCaching();
		$queues = array( 'all' => 'همه درخواست‌ها', 'supplier' => 'پاسخ فروشنده', 'deposit' => 'پیش‌پرداخت', 'receipts' => 'رسید در انتظار بررسی', 'preparing' => 'آماده‌سازی', 'delivery' => 'منتظر تحویل', 'buyer' => 'وزن خریدار', 'vendor' => 'بررسی فروشنده', 'review' => 'بررسی مدیریت', 'sync' => 'مغایرت و هماهنگی', 'final' => 'وزن نهایی' );
		$queue = isset( $_GET['queue'] ) && is_string( $_GET['queue'] ) ? sanitize_key( wp_unslash( $_GET['queue'] ) ) : 'all';
		$queue = isset( $queues[ $queue ] ) ? $queue : 'all';
		$before = isset( $_GET['before'] ) && is_scalar( $_GET['before'] ) ? absint( $_GET['before'] ) : 0;
		$selected_id = isset( $_GET['request'] ) && is_scalar( $_GET['request'] ) ? absint( $_GET['request'] ) : 0;
		$history_before = isset( $_GET['history_before'] ) && is_scalar( $_GET['history_before'] ) ? absint( $_GET['history_before'] ) : 0;
		$url = admin_url( 'admin.php?page=' . self::OPERATIONS_SLUG );
		$labels = array( 'supplier_review' => 'در انتظار فروشنده', 'supplier_accepted' => 'در انتظار پیش‌پرداخت', 'supplier_rejected' => 'رد فروشنده', 'supplier_response_expired' => 'پایان مهلت فروشنده', 'deposit_pending' => 'در انتظار پیش‌پرداخت', 'deposit_paid' => 'پیش‌پرداخت ثبت شد', 'deposit_expired' => 'پایان مهلت پیش‌پرداخت', 'late_payment_review' => 'بررسی پرداخت دیرهنگام', 'awaiting_deposit' => 'پیش‌پرداخت', 'preparing' => 'آماده‌سازی', 'ready_for_delivery' => 'آمادهٔ ارسال', 'ready_for_pickup' => 'آمادهٔ دریافت حضوری', 'in_transit' => 'در مسیر تحویل', 'buyer_review' => 'وزن و عکس خریدار', 'seller_review' => 'بررسی فروشنده', 'admin_review' => 'بررسی مدیریت', 'final_weight_locked' => 'وزن نهایی', 'balance_due' => 'ماندهٔ پروندهٔ قبلی', 'completed' => 'تکمیل‌شده', 'return_review' => 'بررسی بازگشت', 'cancelled' => 'لغوشده' );
		$format_time = static function ( mixed $value ): string {
			try { return is_string( $value ) && '' !== $value ? \Zarbalman\Core\Support\JalaliDate::formatGmt( $value, wp_timezone(), true ) : '—'; } catch ( Throwable ) { return '—'; }
		};
		?>
		<div class="wrap zbm-admin-wrap zbm-manual-admin" dir="rtl">
			<div class="zbm-admin-hero"><span class="zbm-admin-kicker"><?php esc_html_e( 'از درخواست تا وزن نهایی', 'zarbalman-core' ); ?></span><h1><?php esc_html_e( 'مرکز عملیات درخواست‌ها', 'zarbalman-core' ); ?></h1><p><?php esc_html_e( 'مرحلهٔ جاری، مهلت‌ها و موارد نیازمند رسیدگی را پیگیری کنید. تغییر هر پرونده با دسترسی مجاز و دلیل ثبت می‌شود.', 'zarbalman-core' ); ?></p></div>
			<nav class="zbm-operations-queues" aria-label="<?php esc_attr_e( 'صف‌های عملیات', 'zarbalman-core' ); ?>">
				<?php foreach ( $queues as $key => $label ) : ?><a class="button <?php echo $key === $queue ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'queue', $key, $url ) ); ?>" <?php if ( $key === $queue ) { echo 'aria-current="page"'; } ?>><?php echo esc_html( $label ); ?></a><?php endforeach; ?>
			</nav>
			<?php
			try {
				if ( $selected_id > 0 ) {
					$request = $this->requests->find( $selected_id );
					if ( ! $request ) { throw new \RuntimeException( 'Request missing.' ); }
					echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'بازگشت به درخواست‌ها', 'zarbalman-core' ) . '</a></p>';
					echo '<h2>#' . esc_html( $request->requestNumber() ) . '</h2>';
					if ( RequestState::DEPOSIT_PAID !== $request->state() ) {
						echo '<p><a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'zbm-core-requests', 'request_number' => $request->requestNumber() ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'کنترل درخواست و مهلت پیش‌پرداخت', 'zarbalman-core' ) . '</a></p>';
					}
					echo $this->panel->render( $request, 'admin', get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped role-safe template.
					$history = $this->cases->historyForAdmin( $selected_id, $history_before );
					$more = count( $history ) > 50; $history = array_slice( $history, 0, 50 );
					?><section class="zbm-admin-card"><h2><?php esc_html_e( 'سابقهٔ اقدامات', 'zarbalman-core' ); ?></h2><div class="zbm-manual-ledger-table"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'زمان', 'zarbalman-core' ); ?></th><th><?php esc_html_e( 'رویداد', 'zarbalman-core' ); ?></th><th><?php esc_html_e( 'از / به', 'zarbalman-core' ); ?></th><th><?php esc_html_e( 'انجام‌دهنده', 'zarbalman-core' ); ?></th></tr></thead><tbody>
					<?php foreach ( $history as $entry ) : ?><tr><td><?php echo esc_html( $format_time( $entry['created_at_gmt'] ) ); ?></td><td><code><?php echo esc_html( $entry['event_key'] ); ?></code></td><td><?php echo esc_html( ( $labels[ $entry['from_state'] ] ?? $entry['from_state'] ?? '—' ) . ' / ' . ( $labels[ $entry['to_state'] ] ?? $entry['to_state'] ?? '—' ) ); ?></td><td><?php echo esc_html( $entry['actor_type'] . ' #' . $entry['actor_user_id'] ); ?></td></tr><?php endforeach; ?>
					</tbody></table></div><?php if ( $more ) : $last = end( $history ); ?><p><a class="button" href="<?php echo esc_url( add_query_arg( array( 'request' => $selected_id, 'history_before' => (int) $last['id'] ), $url ) ); ?>"><?php esc_html_e( 'سوابق قدیمی‌تر', 'zarbalman-core' ); ?></a></p><?php endif; ?></section><?php
				} else {
					$rows = $this->cases->operationsPage( $queue, $before );
					$more = count( $rows ) > 30; $rows = array_slice( $rows, 0, 30 );
					?><section class="zbm-admin-card"><h2><?php echo esc_html( $queues[ $queue ] ); ?></h2><div class="zbm-manual-ledger-table"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'درخواست / طرفین', 'zarbalman-core' ); ?></th><th><?php esc_html_e( 'مرحلهٔ جاری', 'zarbalman-core' ); ?></th><th><?php esc_html_e( 'پایان مهلت بررسی', 'zarbalman-core' ); ?></th><th><?php esc_html_e( 'رسیدگی', 'zarbalman-core' ); ?></th></tr></thead><tbody>
					<?php foreach ( $rows as $row ) : $current = $row['operation_state'] ?? $row['current_state']; ?><tr><td><strong>#<?php echo esc_html( $row['request_number'] ); ?></strong><br><?php echo esc_html( sprintf( __( 'خریدار %1$d / فروشنده %2$d', 'zarbalman-core' ), $row['buyer_id'], $row['vendor_id'] ) ); ?></td><td><?php echo esc_html( $labels[ $current ] ?? $current ); ?><?php if ( 1 === (int) $row['sync_pending'] ) : ?><br><span class="zbm-ops-sync-badge"><?php esc_html_e( 'هماهنگی فنی در انتظار', 'zarbalman-core' ); ?></span><?php endif; ?></td><td><?php echo esc_html( $format_time( $row['deadline_gmt'] ) ); ?></td><td><a class="button" href="<?php echo esc_url( add_query_arg( 'request', (int) $row['id'], $url ) ); ?>"><?php esc_html_e( 'بررسی پرونده', 'zarbalman-core' ); ?></a></td></tr><?php endforeach; ?>
					<?php if ( array() === $rows ) : ?><tr><td colspan="4"><?php esc_html_e( 'در این صفحه موردی وجود ندارد.', 'zarbalman-core' ); ?></td></tr><?php endif; ?></tbody></table></div>
					<div class="zbm-manual-ledger-pager"><?php if ( $before > 0 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'queue', $queue, $url ) ); ?>"><?php esc_html_e( 'جدیدترین درخواست‌ها', 'zarbalman-core' ); ?></a><?php endif; ?><?php if ( $more ) : $last = end( $rows ); ?><a class="button" href="<?php echo esc_url( add_query_arg( array( 'queue' => $queue, 'before' => (int) $last['id'] ), $url ) ); ?>"><?php esc_html_e( 'درخواست‌های قدیمی‌تر', 'zarbalman-core' ); ?></a><?php endif; ?></div></section><?php
				}
			} catch ( Throwable ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'خواندن پرونده‌ها ممکن نشد. این پیام به معنی خالی‌بودن صف یا ثبت‌نشدن پرداخت نیست.', 'zarbalman-core' ) . '</p></div>';
			}
			?>
		</div>
		<?php
	}

	/** Read-only, paginated confirmed-payment accounting. */
	public function renderLedger(): void {
		if ( ! AdminCapabilities::currentUserCanManageFinance() ) {
			return;
		}
		RequestResponse::preventCaching();
		$page = isset( $_GET['ledger_page'] ) ? absint( $_GET['ledger_page'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging.
		$page = max( 1, min( 100000, $page ) );
		try {
			$rows = $this->payments->capturedManualForFinance( $page, 50 );
			$error = false;
		} catch ( Throwable ) {
			$rows = array();
			$error = true;
		}
		?>
		<div class="wrap zbm-admin-wrap zbm-manual-admin" dir="rtl">
			<div class="zbm-admin-hero">
				<h1><?php esc_html_e( 'گزارش پرداخت‌های دستی تأییدشده', 'zarbalman-core' ); ?></h1>
				<p><?php esc_html_e( 'فقط وجوهی که در بانک تطبیق یافته و در دفتر پرداخت قطعی ثبت شده‌اند. رسید ارسالی به‌تنهایی در این گزارش نمی‌آید.', 'zarbalman-core' ); ?></p>
			</div>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'بازگشت به پرونده‌های پرداخت دستی', 'zarbalman-core' ); ?></a></p>
			<?php if ( $error ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'خواندن گزارش مالی ممکن نشد؛ نبود رکورد به معنی نبود پرداخت نیست.', 'zarbalman-core' ); ?></p></div><?php else : ?>
				<div class="zbm-manual-ledger-table">
					<table class="widefat striped"><thead><tr>
						<th><?php esc_html_e( 'درخواست', 'zarbalman-core' ); ?></th>
						<th><?php esc_html_e( 'مرحله', 'zarbalman-core' ); ?></th>
						<th><?php esc_html_e( 'مبلغ تأییدشده (تومان)', 'zarbalman-core' ); ?></th>
						<th><?php esc_html_e( 'زمان تأیید (GMT)', 'zarbalman-core' ); ?></th>
						<th><?php esc_html_e( 'شناسه ثبت پرداخت', 'zarbalman-core' ); ?></th>
						<th><?php esc_html_e( 'مدیر تأییدکننده', 'zarbalman-core' ); ?></th>
					</tr></thead><tbody>
					<?php foreach ( $rows as $payment ) :
						try {
							$request = $this->requests->find( (int) $payment['request_id'] );
							$case = $this->cases->find( (int) $payment['request_id'] );
						} catch ( Throwable ) {
							$request = null;
							$case = null;
						}
						$phase = (string) $payment['phase'];
						$offer = $case['data']['manual'][ $phase ] ?? null;
						$fund = $case['data']['funds'][ $phase ] ?? null;
						$verified = $request && is_array( $offer ) && is_array( $fund ) && 'approved' === ( $offer['status'] ?? null ) && (int) ( $fund['payment_id'] ?? 0 ) === (int) $payment['id'] && (int) ( $fund['amount'] ?? 0 ) === (int) $payment['paid_amount_toman'] && (int) ( $offer['reviewed_by'] ?? 0 ) > 0;
						$admin = $verified ? get_userdata( (int) $offer['reviewed_by'] ) : false;
						?>
						<tr>
							<td><?php if ( $request ) : ?><a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'request' => $request->id() ), admin_url( 'admin.php' ) ) ); ?>">#<?php echo esc_html( $request->requestNumber() ); ?></a><?php else : ?><?php esc_html_e( 'نیازمند بررسی', 'zarbalman-core' ); ?><?php endif; ?></td>
							<td><?php echo esc_html( 'deposit' === $phase ? __( 'پیش‌پرداخت ۱۰٪', 'zarbalman-core' ) : __( 'ماندهٔ نهایی', 'zarbalman-core' ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $payment['paid_amount_toman'] ) ); ?></td>
							<td dir="ltr"><?php echo esc_html( (string) $payment['paid_at_gmt'] ); ?></td>
							<td><?php echo esc_html( (string) $payment['id'] ); ?></td>
							<td><?php echo $admin ? esc_html( $admin->user_login . ' (#' . $admin->ID . ')' ) : esc_html__( 'نیازمند بررسی تطابق سوابق', 'zarbalman-core' ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( array() === $rows ) : ?><tr><td colspan="6"><?php esc_html_e( 'در این صفحه پرداخت تأییدشده‌ای نیست.', 'zarbalman-core' ); ?></td></tr><?php endif; ?>
					</tbody></table>
				</div>
				<div class="zbm-manual-ledger-pager">
					<?php if ( $page > 1 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::REPORT_SLUG, 'ledger_page' => $page - 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'صفحه قبل', 'zarbalman-core' ); ?></a><?php endif; ?>
					<?php if ( count( $rows ) === 50 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::REPORT_SLUG, 'ledger_page' => $page + 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'صفحه بعد', 'zarbalman-core' ); ?></a><?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}

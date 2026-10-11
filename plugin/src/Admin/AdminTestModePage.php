<?php

declare(strict_types=1);

namespace Zarbalman\Core\Admin;

use Zarbalman\Core\Application\AdminTestModeManager;
use Zarbalman\Core\Infrastructure\DependencyHealth;
use Zarbalman\Core\Security\AdminCapabilities;

final class AdminTestModePage {
	private const PAGE_SLUG  = 'zbm-core-test-mode';
	private const ACTION     = 'zbm_core_test_mode';
	private const NOTICE_KEY = 'zbm_core_test_mode_notice_';

	public function __construct(
		private readonly AdminTestModeManager $manager,
		private readonly DependencyHealth $health
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 63 );
		add_action( 'admin_init', array( $this->manager, 'synchronizeWithGateway' ), 1 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'بازیابی پرونده‌های آزمایشی قدیمی', 'zarbalman-core' ),
			__( 'بازیابی آزمایشی قدیمی', 'zarbalman-core' ),
			AdminCapabilities::MANAGE_TEST_MODE,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function assets(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Asset routing only.
		if ( ! str_starts_with( $page, 'zbm-core' ) ) {
			return;
		}
		wp_enqueue_style( 'zbm-admin-specs', plugins_url( 'assets/css/admin-specs.css', ZBM_CORE_FILE ), array(), ZBM_CORE_VERSION );
	}

	public function handle(): never {
		if ( ! AdminCapabilities::currentUserCanManageTestMode() ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز است.', 'zarbalman-core' ) );
		}
		check_admin_referer( self::ACTION, '_zbm_nonce' );
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		if ( 'disable' === $operation ) {
			$result = $this->manager->disable( get_current_user_id(), $reason );
		} else {
			$result = new \WP_Error( 'zbm_test_mode_legacy_only', __( 'شروع حالت آزمایشی جدید غیرفعال است؛ از پرداخت دستی واقعی استفاده کنید.', 'zarbalman-core' ) );
		}

		$notice = is_wp_error( $result )
			? array( 'type' => 'error', 'message' => $result->get_error_message() )
			: array( 'type' => 'success', 'message' => __( 'وضعیت حالت آزمایشی همراه با حسابرسی ثبت شد.', 'zarbalman-core' ) );
		set_transient( self::NOTICE_KEY . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	public function render(): void {
		if ( ! AdminCapabilities::currentUserCanManageTestMode() ) {
			return;
		}
		$notice = get_transient( self::NOTICE_KEY . get_current_user_id() );
		delete_transient( self::NOTICE_KEY . get_current_user_id() );
		$configured = $this->manager->isConfigured();
		$enabled = $this->manager->isEnabled();
		$gateway_ready = $this->health->canCreateDepositOrder();
		?>
		<div class="wrap zbm-admin-wrap" dir="rtl">
			<div class="zbm-admin-hero">
				<span class="zbm-admin-kicker"><?php echo esc_html( sprintf( __( 'Zarbalman Core %s', 'zarbalman-core' ), ZBM_CORE_VERSION ) ); ?></span>
				<h1><?php echo esc_html__( 'بازیابی پرونده‌های آزمایشی قدیمی', 'zarbalman-core' ); ?></h1>
				<p><?php echo esc_html__( 'شروع پروندهٔ آزمایشی جدید متوقف شده است. این صفحه فقط تنظیم قدیمی را خاموش و رکوردهای test_pending موجود را از صفحهٔ درخواست‌ها بازیابی می‌کند.', 'zarbalman-core' ); ?></p>
			</div>
			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( 'success' === ( $notice['type'] ?? '' ) ? 'success' : 'error' ); ?> is-dismissible"><p><?php echo esc_html( (string) ( $notice['message'] ?? '' ) ); ?></p></div>
			<?php endif; ?>
			<div class="zbm-admin-grid">
				<div class="zbm-admin-card"><span><?php echo esc_html__( 'درگاه واقعی', 'zarbalman-core' ); ?></span><strong><?php echo $gateway_ready ? esc_html__( 'آماده و فعال', 'zarbalman-core' ) : esc_html__( 'آماده نیست', 'zarbalman-core' ); ?></strong></div>
				<div class="zbm-admin-card <?php echo $enabled ? 'is-test-active' : ''; ?>"><span><?php echo esc_html__( 'تنظیم قدیمی', 'zarbalman-core' ); ?></span><strong><?php echo $enabled ? esc_html__( 'نیازمند غیرفعال‌سازی', 'zarbalman-core' ) : esc_html__( 'غیرفعال', 'zarbalman-core' ); ?></strong></div>
			</div>
			<div class="zbm-admin-callout is-warning"><strong><?php echo esc_html__( 'این مسیر مالی واقعی نیست.', 'zarbalman-core' ); ?></strong> <?php echo esc_html__( 'پرونده‌های test و test_pending هرگز به پرداخت دستی واقعی، رسید بانکی یا اعتبار Dokan تبدیل نمی‌شوند.', 'zarbalman-core' ); ?></div>

			<?php if ( $configured ) : ?><div class="zbm-admin-card zbm-admin-form-card">
				<h2><?php esc_html_e( 'غیرفعال‌کردن تنظیم آزمایشی قدیمی', 'zarbalman-core' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="operation" value="disable">
					<?php wp_nonce_field( self::ACTION, '_zbm_nonce' ); ?>
					<label for="zbm-test-mode-reason"><?php echo esc_html__( 'دلیل اجباری مدیریت', 'zarbalman-core' ); ?></label>
					<textarea id="zbm-test-mode-reason" name="reason" rows="3" maxlength="1000" required></textarea>
					<button class="button" type="submit"><?php esc_html_e( 'غیرفعال‌کردن تنظیم قدیمی', 'zarbalman-core' ); ?></button>
				</form>
			</div><?php else : ?><div class="zbm-admin-callout is-success"><?php esc_html_e( 'تنظیم آزمایشی قدیمی غیرفعال است و امکان فعال‌کردن دوباره ندارد.', 'zarbalman-core' ); ?></div><?php endif; ?>
		</div>
		<?php
	}
}

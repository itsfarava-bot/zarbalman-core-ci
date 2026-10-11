<?php

declare(strict_types=1);

namespace Zarbalman\Core\Admin;

use Zarbalman\Core\Infrastructure\DependencyHealth;
use Zarbalman\Core\Notification\MessageCatalog;
use Zarbalman\Core\Security\AdminCapabilities;

final class Spec3Settings {
	private const PAGE_SLUG = 'zbm-core-spec3-settings';

	public function __construct(
		private readonly DependencyHealth $health,
		private readonly MessageCatalog $messages
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 62 );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'admin_notices', array( $this, 'dependencyNotice' ) );
		// The option-group key is historical and must stay stable across upgrades.
		add_filter( 'option_page_capability_zbm_core_spec3', static fn (): string => 'manage_options' );
	}

	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'تنظیمات گردش درخواست و پیش‌پرداخت', 'zarbalman-core' ),
			__( 'تنظیمات پیامک و وابستگی‌ها', 'zarbalman-core' ),
			AdminCapabilities::MANAGE_OPERATIONS,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function settings(): void {
		register_setting( 'zbm_core_spec3', 'zbm_core_delivery_reminder_minutes', array( 'type' => 'integer', 'default' => 60, 'sanitize_callback' => array( $this, 'sanitizeReminder' ) ) );
		register_setting(
			'zbm_core_spec3',
			'zbm_core_sms_templates',
			array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitizeTemplates' ), 'default' => array() )
		);
	}

	public function sanitizeReminder( mixed $value ): int {
		$old = (int) get_option( 'zbm_core_delivery_reminder_minutes', 60 );
		if ( ! AdminCapabilities::currentUserCanOperate() ) { return $old; }
		if ( ( ! is_string( $value ) && ! is_int( $value ) ) || ! preg_match( '/\A(?:0|[1-9][0-9]{0,3})\z/', (string) $value ) || ( 0 !== (int) $value && ( (int) $value < 5 || (int) $value > 1440 ) ) ) {
			add_settings_error( 'zbm_core_delivery_reminder_minutes', 'invalid_reminder', __( 'یادآوری باید صفر (خاموش) یا بین ۵ تا ۱۴۴۰ دقیقه باشد.', 'zarbalman-core' ) );
			return $old;
		}
		return (int) $value;
	}

	/** @return array<string,string> */
	public function sanitizeTemplates( mixed $value ): array {
		if ( ! AdminCapabilities::currentUserCanOperate() ) {
			$existing = get_option( 'zbm_core_sms_templates', array() );
			return is_array( $existing ) ? $existing : array();
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$allowed = array_keys( $this->messages->defaults() );
		$clean = array();
		foreach ( $value as $key => $template ) {
			$key = sanitize_key( (string) $key );
			if ( ! in_array( $key, $allowed, true ) || ! is_string( $template ) ) {
				continue;
			}
			$template = trim( sanitize_textarea_field( $template ) );
			if ( '' !== $template ) {
				$clean[ $key ] = $this->byteCut( $template, 1000 );
			}
		}
		return $clean;
	}

	private function byteCut( string $value, int $bytes ): string {
		if ( strlen( $value ) <= $bytes ) {
			return $value;
		}
		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $value, 0, $bytes, 'UTF-8' );
		}
		$cut = substr( $value, 0, $bytes );
		while ( '' !== $cut && 1 !== preg_match( '//u', $cut ) ) {
			$cut = substr( $cut, 0, -1 );
		}
		return $cut;
	}

	public function dependencyNotice(): void {
		if ( ! AdminCapabilities::currentUserCanOperate() || $this->health->canCreateDepositOrder() ) {
			return;
		}
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		echo '<div class="notice notice-error"><p>';
		echo wp_kses_post( sprintf( __( 'عملیات مالی واقعی زربال‌من تا رفع ناسازگاری متوقف است. <a href="%s">گزارش دقیق وابستگی‌ها</a>', 'zarbalman-core' ), esc_url( $url ) ) );
		echo '</p></div>';
	}

	public function render(): void {
		if ( ! AdminCapabilities::currentUserCanOperate() ) {
			return;
		}
		$saved = get_option( 'zbm_core_sms_templates', array() );
		$saved = is_array( $saved ) ? $saved : array();
		?>
		<div class="wrap zbm-admin-wrap" dir="rtl">
			<div class="zbm-admin-hero">
				<span class="zbm-admin-kicker"><?php echo esc_html__( 'تنظیمات پیامک و وابستگی‌ها', 'zarbalman-core' ); ?></span>
				<h1><?php echo esc_html__( 'سلامت وابستگی‌ها و پیامک‌ها', 'zarbalman-core' ); ?></h1>
				<p><?php echo esc_html__( 'این صفحه برای سازگاری با تنظیمات قبلی نگهداری شده است؛ متن پیامک‌ها و سلامت اتصال‌های ضروری را از همین‌جا بررسی کنید.', 'zarbalman-core' ); ?></p>
			</div>
			<h2><?php echo esc_html__( 'وابستگی‌های قفل‌شده', 'zarbalman-core' ); ?></h2>
			<table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'بخش', 'zarbalman-core' ); ?></th><th><?php echo esc_html__( 'تشخیص', 'zarbalman-core' ); ?></th><th><?php echo esc_html__( 'انتظار', 'zarbalman-core' ); ?></th><th><?php echo esc_html__( 'نتیجه', 'zarbalman-core' ); ?></th></tr></thead><tbody>
			<?php foreach ( $this->health->report() as $row ) : ?>
				<tr><td><?php echo esc_html( $row['label'] ); ?></td><td><code><?php echo esc_html( '' !== $row['detected'] ? $row['detected'] : '—' ); ?></code></td><td><code><?php echo esc_html( $row['expected'] ); ?></code></td><td><?php echo $row['ok'] ? '✅' : '❌'; ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<p><?php echo esc_html__( 'در حالت خطا، مشاهده درخواست‌ها باز می‌ماند ولی ایجاد درخواست، نمایش لینک و هر جهش مالی callback/استعلام به‌صورت امن متوقف می‌شود.', 'zarbalman-core' ); ?></p>

			<h2><?php echo esc_html__( 'متن پیامک‌های خودکار', 'zarbalman-core' ); ?></h2>
			<p><?php echo esc_html__( 'خالی‌گذاشتن هر مورد، متن امن پیش‌فرض افزونه را فعال نگه می‌دارد. متغیرهای مجاز: {request}، {product}، {link}، {amount}، {deadline} و {reason}.', 'zarbalman-core' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'zbm_core_spec3' ); ?>
				<?php settings_errors(); ?>
				<p><label for="zbm-delivery-reminder"><strong><?php esc_html_e( 'یادآوری پیش از شروع بازهٔ تحویل (دقیقه)', 'zarbalman-core' ); ?></strong></label> <input id="zbm-delivery-reminder" type="number" name="zbm_core_delivery_reminder_minutes" min="0" max="1440" value="<?php echo esc_attr( (string) get_option( 'zbm_core_delivery_reminder_minutes', 60 ) ); ?>"></p>
				<p class="description"><?php esc_html_e( 'صفر: بدون پیامک یادآوری. مقدار فعال: ۵ تا ۱۴۴۰ دقیقه. برای هر بازه و هر طرف حداکثر یک یادآوری ثبت می‌شود؛ پیام دیررس ارسال نمی‌شود. بررسی پایان بازه با خاموش‌کردن پیامک غیرفعال نمی‌شود.', 'zarbalman-core' ); ?></p>
				<p class="description"><?php esc_html_e( 'کد امن در پیامک قرار نمی‌گیرد؛ پیام آمادگی یا ارسال، لینک پنل خریدار را دارد. قالب‌های ادغام‌شدهٔ قبلی برای حفظ تنظیمات نگهداری شده‌اند و ارسال تازه ندارند.', 'zarbalman-core' ); ?></p>
				<table class="form-table"><tbody>
				<?php foreach ( $this->messages->defaults() as $key => $default ) : ?>
					<tr><th scope="row"><label for="zbm-sms-<?php echo esc_attr( $key ); ?>"><code><?php echo esc_html( $key ); ?></code></label><?php if ( in_array( $key, \Zarbalman\Core\Domain\ManagedFlow\OperationsNotificationPolicy::RETIRED, true ) ) : ?><p class="description"><?php esc_html_e( 'ادغام شده؛ بدون ارسال تازه', 'zarbalman-core' ); ?></p><?php endif; ?></th><td>
						<textarea class="large-text" rows="2" maxlength="1000" id="zbm-sms-<?php echo esc_attr( $key ); ?>" name="zbm_core_sms_templates[<?php echo esc_attr( $key ); ?>]" placeholder="<?php echo esc_attr( $default ); ?>"><?php echo esc_textarea( (string) ( $saved[ $key ] ?? '' ) ); ?></textarea>
					</td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<?php submit_button( __( 'ذخیره متن پیامک‌ها', 'zarbalman-core' ) ); ?>
			</form>
		</div>
		<?php
	}
}

<?php

declare(strict_types=1);

namespace Zarbalman\Core\Admin;

use Zarbalman\Core\Domain\Order\WeightUnitPolicy;
use Zarbalman\Core\Infrastructure\AuditLogRepository;
use Zarbalman\Core\Infrastructure\EvidenceCrypto;
use Zarbalman\Core\Infrastructure\EvidenceLegacyMigrator;
use Zarbalman\Core\Infrastructure\Scheduler;
use Zarbalman\Core\Infrastructure\SelfTestRunner;
use Zarbalman\Core\Infrastructure\StatusRegistryInspector;
use Zarbalman\Core\Security\AdminCapabilities;

final class AdminPage {
	private const PAGE_SLUG = 'zbm-core';

	public function __construct(
		private readonly AuditLogRepository $audit,
		private readonly StatusRegistryInspector $inspector,
		private readonly SelfTestRunner $self_test,
		private readonly WeightUnitPolicy $weight_unit,
		private readonly EvidenceCrypto $evidence_crypto,
		private readonly EvidenceLegacyMigrator $evidence_migrator
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_post_zbm_core_self_test', array( $this, 'handle_self_test' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Zarbalman Core', 'zarbalman-core' ),
			__( 'زربال‌من Core', 'zarbalman-core' ),
			AdminCapabilities::MANAGE_OPERATIONS,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function handle_self_test(): void {
		if ( ! AdminCapabilities::currentUserCanOperate() ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز است.', 'zarbalman-core' ) );
		}

		check_admin_referer( 'zbm_core_self_test' );
		set_transient( 'zbm_core_self_test_' . get_current_user_id(), $this->self_test->run(), 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&self_test=1' ) );
		exit;
	}

	public function render(): void {
		if ( ! AdminCapabilities::currentUserCanOperate() ) {
			return;
		}

		$results = get_transient( 'zbm_core_self_test_' . get_current_user_id() );
		?>
		<div class="wrap zbm-admin-wrap" dir="rtl">
			<div class="zbm-admin-hero">
				<span class="zbm-admin-kicker"><?php echo esc_html( sprintf( __( 'Zarbalman Core %s', 'zarbalman-core' ), ZBM_CORE_VERSION ) ); ?></span>
				<h1><?php echo esc_html__( 'هستهٔ زربال‌من — وضعیت سامانه', 'zarbalman-core' ); ?></h1>
				<p><?php echo esc_html__( 'زیرساخت درخواست‌ها حفظ شده و پرداخت دستی، آماده‌سازی، تحویل امن، وزن نهایی، اختلاف و تسویهٔ فروشنده به‌صورت کنترل‌شده در دسترس است.', 'zarbalman-core' ); ?></p>
			</div>

			<div class="zbm-admin-roadmap" aria-label="<?php echo esc_attr__( 'وضعیت قابلیت‌های سامانه', 'zarbalman-core' ); ?>">
				<div class="zbm-admin-spec is-baseline"><span><?php esc_html_e( 'هسته', 'zarbalman-core' ); ?></span><strong><?php echo esc_html__( 'درخواست و دسترسی', 'zarbalman-core' ); ?></strong><small><?php echo esc_html__( 'ثبت درخواست، نقش‌ها، سابقهٔ حسابرسی و کنترل دسترسی فعال است.', 'zarbalman-core' ); ?></small></div>
				<div class="zbm-admin-spec is-baseline"><span><?php esc_html_e( 'پرداخت', 'zarbalman-core' ); ?></span><strong><?php echo esc_html__( 'پیش‌پرداخت و تأیید مالی', 'zarbalman-core' ); ?></strong><small><?php echo esc_html__( 'پرداخت آنلاین یا دستی، بررسی رسید و ثبت امن واقعیت مالی.', 'zarbalman-core' ); ?></small></div>
				<div class="zbm-admin-spec is-current"><span><?php esc_html_e( 'تحویل', 'zarbalman-core' ); ?></span><strong><?php echo esc_html__( 'تحویل، وزن و اختلاف', 'zarbalman-core' ); ?></strong><small><?php echo esc_html__( 'آماده‌سازی، کد تحویل، وزن نهایی و بررسی اختلاف در گردش کنترل‌شده.', 'zarbalman-core' ); ?></small></div>
				<div class="zbm-admin-spec is-planned"><span><?php esc_html_e( 'کنترل', 'zarbalman-core' ); ?></span><strong><?php echo esc_html__( 'سلامت و بازیابی', 'zarbalman-core' ); ?></strong><small><?php echo esc_html__( 'گزارش سلامت، صف اعلان‌ها و بازیابی خطاهای عملیاتی در همین بخش قابل مشاهده است.', 'zarbalman-core' ); ?></small></div>
			</div>

			<?php if ( is_array( $results ) ) : ?>
				<h2><?php echo esc_html__( 'نتیجه آزمون داخلی', 'zarbalman-core' ); ?></h2>
				<table class="widefat striped"><thead><tr>
					<th><?php echo esc_html__( 'آزمون', 'zarbalman-core' ); ?></th>
					<th><?php echo esc_html__( 'نتیجه', 'zarbalman-core' ); ?></th>
					<th><?php echo esc_html__( 'توضیح', 'zarbalman-core' ); ?></th>
				</tr></thead><tbody>
				<?php foreach ( $results as $result ) : ?>
					<tr><td><?php echo esc_html( (string) $result['name'] ); ?></td>
					<td><?php echo $result['passed'] ? '✅' : '❌'; ?></td>
					<td><?php echo esc_html( (string) $result['message'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'محیط اجرا', 'zarbalman-core' ); ?></h2>
			<table class="widefat striped"><tbody>
				<?php $this->row( 'نسخه افزونه', ZBM_CORE_VERSION ); ?>
				<?php $this->row( 'WordPress', (string) get_bloginfo( 'version' ) ); ?>
				<?php $this->row( 'WooCommerce', defined( 'WC_VERSION' ) ? WC_VERSION : '—' ); ?>
				<?php $this->row( 'PHP', PHP_VERSION ); ?>
				<?php $this->row( 'واحد وزن ووکامرس', $this->weight_unit->current_unit() ?: '—' ); ?>
				<?php $this->row( 'رمزنگاری مدارک', $this->evidence_crypto->is_available() && $this->evidence_crypto->has_key() ? 'فعال' : 'نیازمند بررسی' ); ?>
				<?php $this->row( 'مدارک قدیمی ایمن‌سازی‌نشده', $this->evidence_migrator->has_legacy_evidence() ? 'وجود دارد' : 'وجود ندارد' ); ?>
				<?php $this->row( 'HPOS', $this->hpos_enabled() ? 'فعال' : 'غیرفعال' ); ?>
				<?php $this->row( 'Action Scheduler', function_exists( 'as_schedule_single_action' ) ? 'در دسترس' : 'در دسترس نیست' ); ?>
				<?php $this->row( 'پاک‌سازی زمان‌بندی‌شده', $this->cleanup_scheduled() ? 'ثبت شده' : 'ثبت نشده' ); ?>
				<?php $this->row( 'بازیابی مهلت‌های عملیاتی', $this->deadline_recovery_scheduled() ? 'ثبت شده' : 'ثبت نشده' ); ?>
			</tbody></table>

			<h2><?php echo esc_html__( 'سازگاری وضعیت‌های سفارش', 'zarbalman-core' ); ?></h2>
			<table class="widefat striped"><thead><tr>
				<th><?php echo esc_html__( 'عنوان', 'zarbalman-core' ); ?></th>
				<th><?php echo esc_html__( 'شناسه تنظیم‌شده', 'zarbalman-core' ); ?></th>
				<th><?php echo esc_html__( 'کلید ثبت‌شده', 'zarbalman-core' ); ?></th>
				<th><?php echo esc_html__( 'ثبت در ووکامرس', 'zarbalman-core' ); ?></th>
				<th><?php echo esc_html__( 'حد ۲۰ کاراکتر', 'zarbalman-core' ); ?></th>
			</tr></thead><tbody>
			<?php foreach ( $this->inspector->inspect() as $status ) : ?>
				<tr>
					<td><?php echo esc_html( $status['label'] ); ?></td>
					<td><code><?php echo esc_html( $status['slug'] ); ?></code></td>
					<td><code><?php echo esc_html( $status['key'] ); ?></code></td>
					<td><?php echo $status['registered'] ? '✅' : '❌'; ?></td>
					<td><?php echo $status['length_safe'] ? '✅' : '⚠️'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>

			<h2><?php echo esc_html__( 'ثبت رویدادهای اخیر', 'zarbalman-core' ); ?></h2>
			<table class="widefat striped"><thead><tr>
				<th>ID</th><th><?php echo esc_html__( 'سفارش', 'zarbalman-core' ); ?></th>
				<th><?php echo esc_html__( 'رویداد', 'zarbalman-core' ); ?></th>
				<th><?php echo esc_html__( 'از', 'zarbalman-core' ); ?></th>
				<th><?php echo esc_html__( 'به', 'zarbalman-core' ); ?></th>
				<th><?php echo esc_html__( 'اقدام‌کننده', 'zarbalman-core' ); ?></th>
				<th><?php echo esc_html__( 'زمان GMT', 'zarbalman-core' ); ?></th>
			</tr></thead><tbody>
			<?php $logs = $this->audit->recent( 20 ); ?>
			<?php if ( ! $logs ) : ?>
				<tr><td colspan="7"><?php echo esc_html__( 'هنوز رویدادی ثبت نشده است.', 'zarbalman-core' ); ?></td></tr>
			<?php else : foreach ( $logs as $log ) : ?>
				<tr>
					<td><?php echo absint( $log->id ); ?></td>
					<td><?php echo $log->order_id ? absint( $log->order_id ) : '—'; ?></td>
					<td><code><?php echo esc_html( $log->event_key ); ?></code></td>
					<td><?php echo esc_html( $log->from_state ?: '—' ); ?></td>
					<td><?php echo esc_html( $log->to_state ?: '—' ); ?></td>
					<td><?php echo esc_html( $log->actor_type . ':' . $log->actor_user_id ); ?></td>
					<td><?php echo esc_html( $log->created_at_gmt ); ?></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody></table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
				<input type="hidden" name="action" value="zbm_core_self_test">
				<?php wp_nonce_field( 'zbm_core_self_test' ); ?>
				<?php submit_button( __( 'اجرای آزمون داخلی امن', 'zarbalman-core' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private function row( string $label, string $value ): void {
		echo '<tr><th style="width:220px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	private function hpos_enabled(): bool {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	private function cleanup_scheduled(): bool {
		return function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( Scheduler::CLEANUP_HOOK, array(), Scheduler::GROUP );
	}

	private function deadline_recovery_scheduled(): bool {
		return function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( \Zarbalman\Core\Infrastructure\DeadlineScheduler::RECOVERY_HOOK, array(), Scheduler::GROUP );
	}
}

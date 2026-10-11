<?php
/** @var Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest $request */
/** @var array<string,mixed> $view */
/** @var array<string,mixed> $delivery */
/** @var bool $can_decide */
/** @var string $back_url */
/** @var string $dashboard_url */
/** @var string $accept_key */
/** @var string $reject_key */
/** @var string $managed_panel */
defined( 'ABSPATH' ) || exit;
$pickup_address = is_array( $delivery['pickup_address'] ?? null ) ? $delivery['pickup_address'] : array();
$pickup_note = is_string( $delivery['pickup_note'] ?? null ) ? trim( $delivery['pickup_note'] ) : '';
?>
<div class="zbm-dashboard zbm-detail dokan-dashboard-wrap" dir="rtl">
	<div class="zbm-detail-toolbar">
		<a class="zbm-back-link zbm-back-link-primary" href="<?php echo esc_url( $back_url ); ?>" aria-label="<?php esc_attr_e( 'بازگشت به فهرست درخواست‌های خرید', 'zarbalman-core' ); ?>"><span aria-hidden="true">←</span> <?php esc_html_e( 'بازگشت به درخواست‌های خرید', 'zarbalman-core' ); ?></a>
		<a class="dokan-btn dokan-btn-theme zbm-back-link zbm-back-link-dashboard" href="<?php echo esc_url( $dashboard_url ); ?>" aria-label="<?php esc_attr_e( 'بازگشت به داشبورد فروشنده', 'zarbalman-core' ); ?>"><span aria-hidden="true">←</span> <?php esc_html_e( 'بازگشت به داشبورد فروشنده', 'zarbalman-core' ); ?></a>
	</div>
	<section class="zbm-detail-hero" aria-labelledby="zbm-vendor-detail-title">
		<div class="zbm-detail-media zbm-product-image">
			<?php if ( '' !== trim( (string) $view['product_image'] ) ) : ?>
				<?php echo wp_kses_post( (string) $view['product_image'] ); ?>
			<?php else : ?>
				<span class="zbm-product-image-placeholder" aria-hidden="true"><?php esc_html_e( 'محصول', 'zarbalman-core' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="zbm-detail-hero-body">
			<span class="zbm-dashboard-kicker"><?php esc_html_e( 'بررسی درخواست خرید', 'zarbalman-core' ); ?></span>
			<h2 id="zbm-vendor-detail-title"><?php echo esc_html( (string) $view['product_name'] ); ?></h2>
			<div class="zbm-detail-meta">
				<span class="zbm-state zbm-state-<?php echo esc_attr( (string) $view['state_tone'] ); ?>"><?php echo esc_html( (string) $view['state_label'] ); ?></span>
				<span class="zbm-card-id"><?php esc_html_e( 'شناسه', 'zarbalman-core' ); ?>: <?php echo esc_html( (string) $view['request_number'] ); ?></span>
			</div>
		</div>
	</section>
	<?php if ( ! empty( $view['is_test'] ) ) : ?><div class="zbm-alert zbm-alert-test" role="status"><strong><?php esc_html_e( 'این پرونده آزمایشی است.', 'zarbalman-core' ); ?></strong> <?php esc_html_e( 'هیچ پیش‌پرداختی از خریدار دریافت نشده و هیچ سهم مالی برای فروشنده ثبت نشده است.', 'zarbalman-core' ); ?></div><?php endif; ?>
	<?php if ( ! empty( $view['is_paused'] ) ) : ?><div class="zbm-alert zbm-alert-warning"><?php esc_html_e( 'زمان این مرحله توسط مدیریت متوقف شده است.', 'zarbalman-core' ); ?></div><?php endif; ?>
	<?php if ( ! empty( $view['deadline_iso'] ) ) : ?><p class="zbm-countdown" data-deadline="<?php echo esc_attr( (string) $view['deadline_iso'] ); ?>"></p><?php elseif ( ! empty( $view['deadline_notice'] ) ) : ?><p class="zbm-countdown zbm-countdown-notice" role="status"><?php echo esc_html( (string) $view['deadline_notice'] ); ?></p><?php endif; ?>
	<div class="zbm-detail-grid">
		<?php include ZBM_CORE_DIR . 'templates/request-items.php'; ?>
		<section class="zbm-detail-section" aria-labelledby="zbm-vendor-summary-title">
			<div class="zbm-section-heading"><span class="zbm-section-icon" aria-hidden="true">◈</span><div><span class="zbm-dashboard-kicker"><?php esc_html_e( 'خلاصه سفارش', 'zarbalman-core' ); ?></span><h3 id="zbm-vendor-summary-title"><?php esc_html_e( 'محصول و مقدار درخواستی', 'zarbalman-core' ); ?></h3></div></div>
			<dl class="zbm-facts">
				<dt><?php esc_html_e( 'تعداد بسته', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( (string) $view['quantity'] ); ?></dd>
				<dt><?php esc_html_e( 'وزن هر بسته', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( (string) $view['package_weight'] ); ?></dd>
				<dt><?php esc_html_e( 'وزن تقریبی کل', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( (string) $view['reference_weight'] ); ?></dd>
			</dl>
		</section>
		<section class="zbm-detail-section" aria-labelledby="zbm-vendor-delivery-title">
			<div class="zbm-section-heading"><span class="zbm-section-icon" aria-hidden="true">⌖</span><div><span class="zbm-dashboard-kicker"><?php esc_html_e( 'هماهنگی دریافت', 'zarbalman-core' ); ?></span><h3 id="zbm-vendor-delivery-title"><?php esc_html_e( 'روش و زمان دریافت', 'zarbalman-core' ); ?></h3></div></div>
			<dl class="zbm-facts">
				<dt><?php esc_html_e( 'روش دریافت', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( (string) $view['delivery_method'] ); ?></dd>
				<dt><?php esc_html_e( 'بازهٔ اولیهٔ درخواست', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( (string) $view['delivery_window'] ); ?></dd>
				<dt><?php esc_html_e( 'نام دریافت‌کننده', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $delivery['recipient_name'] ); ?></dd>
				<?php if ( $pickup_address ) : ?>
					<?php foreach ( array( 'store_name' => __( 'فروشگاه محل دریافت', 'zarbalman-core' ), 'street_1' => __( 'نشانی فروشنده', 'zarbalman-core' ), 'zip' => __( 'کدپستی فروشگاه', 'zarbalman-core' ) ) as $field => $label ) : ?>
						<?php if ( is_string( $pickup_address[ $field ] ?? null ) && '' !== trim( $pickup_address[ $field ] ) ) : ?><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $pickup_address[ $field ] ); ?></dd><?php endif; ?>
					<?php endforeach; ?>
					<dt><?php esc_html_e( 'تأیید مراجعه خریدار', 'zarbalman-core' ); ?></dt><dd><?php esc_html_e( 'نشانی و توانایی مراجعه تأیید شده است.', 'zarbalman-core' ); ?></dd>
					<dt><?php esc_html_e( 'توضیحات مراجعه', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( '' !== $pickup_note ? $pickup_note : __( 'بدون توضیح', 'zarbalman-core' ) ); ?></dd>
				<?php endif; ?>
				<?php if ( ! empty( $delivery['province_name'] ) ) : ?><dt><?php esc_html_e( 'استان مقصد', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( (string) $delivery['province_name'] ); ?></dd><?php endif; ?>
				<?php if ( null !== $delivery['postal_code'] ) : ?><dt><?php esc_html_e( 'کدپستی تحویل', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $delivery['postal_code'] ); ?></dd><?php endif; ?>
				<?php if ( null !== $delivery['address'] ) : ?><dt><?php esc_html_e( 'نشانی تحویل', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $delivery['address'] ); ?></dd><?php endif; ?>
			</dl>
		</section>
	</div>
	<p class="zbm-privacy-note"><?php esc_html_e( 'شماره موبایل، ایمیل و IP خریدار برای جلوگیری از ارتباط خارج از سایت نمایش داده نمی‌شود.', 'zarbalman-core' ); ?></p>
	<?php if ( $can_decide ) : ?>
		<div class="zbm-vendor-actions">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php esc_attr_e( 'از تأیید درخواست مطمئن هستید؟ این تصمیم قابل برگشت نیست.', 'zarbalman-core' ); ?>">
				<input type="hidden" name="action" value="zbm_vendor_request_decision"><input type="hidden" name="decision" value="accept">
				<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $request->id() ); ?>"><input type="hidden" name="version" value="<?php echo esc_attr( (string) $request->version() ); ?>">
				<input type="hidden" name="idempotency_key" value="<?php echo esc_attr( $accept_key ); ?>"><?php wp_nonce_field( 'zbm_vendor_request_' . $request->id(), '_zbm_nonce' ); ?>
				<button class="dokan-btn dokan-btn-theme" type="submit"><?php esc_html_e( 'تأیید درخواست', 'zarbalman-core' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php esc_attr_e( 'از رد درخواست مطمئن هستید؟ این تصمیم قابل برگشت نیست.', 'zarbalman-core' ); ?>">
				<input type="hidden" name="action" value="zbm_vendor_request_decision"><input type="hidden" name="decision" value="reject">
				<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $request->id() ); ?>"><input type="hidden" name="version" value="<?php echo esc_attr( (string) $request->version() ); ?>">
				<input type="hidden" name="idempotency_key" value="<?php echo esc_attr( $reject_key ); ?>"><?php wp_nonce_field( 'zbm_vendor_request_' . $request->id(), '_zbm_nonce' ); ?>
				<label><?php esc_html_e( 'دلیل رد', 'zarbalman-core' ); ?><textarea name="reason" maxlength="1000" required></textarea></label>
				<button class="dokan-btn dokan-btn-danger" type="submit"><?php esc_html_e( 'رد درخواست', 'zarbalman-core' ); ?></button>
			</form>
		</div>
	<?php endif; ?>
	<?php echo $managed_panel; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Role-safe managed template escapes all dynamic data. ?>
	<div class="zbm-detail-actions zbm-vendor-detail-actions">
		<a class="zbm-back-link zbm-back-link-secondary" href="<?php echo esc_url( $back_url ); ?>"><span aria-hidden="true">←</span> <?php esc_html_e( 'بازگشت به درخواست‌های خرید', 'zarbalman-core' ); ?></a>
		<a class="dokan-btn dokan-btn-theme zbm-back-link zbm-back-link-dashboard" href="<?php echo esc_url( $dashboard_url ); ?>"><span aria-hidden="true">←</span> <?php esc_html_e( 'بازگشت به داشبورد فروشنده', 'zarbalman-core' ); ?></a>
	</div>
</div>

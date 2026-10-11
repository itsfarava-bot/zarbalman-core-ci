<?php
/** @var array<string,mixed> $view */
/** @var array<string,mixed> $delivery */
/** @var string|null $payment_url */
/** @var string $managed_panel */
/** @var string $back_url */
defined( 'ABSPATH' ) || exit;
$pickup_address = is_array( $delivery['pickup_address'] ?? null ) ? $delivery['pickup_address'] : array();
$pickup_note = is_string( $delivery['pickup_note'] ?? null ) ? trim( $delivery['pickup_note'] ) : '';
$shipping_in_deposit = 2 === ( $view['pricing_version'] ?? 1 );
?>
<div class="zbm-dashboard zbm-detail" dir="rtl">
	<div class="zbm-detail-toolbar">
		<a class="zbm-back-link zbm-back-link-primary" href="<?php echo esc_url( $back_url ); ?>" aria-label="<?php esc_attr_e( 'بازگشت به فهرست درخواست‌های خریدار', 'zarbalman-core' ); ?>"><span aria-hidden="true">←</span> <?php esc_html_e( 'بازگشت به درخواست‌ها', 'zarbalman-core' ); ?></a>
	</div>
	<section class="zbm-detail-hero" aria-labelledby="zbm-buyer-detail-title">
		<div class="zbm-detail-media zbm-product-image">
			<?php if ( '' !== trim( (string) $view['product_image'] ) ) : ?>
				<?php echo wp_kses_post( (string) $view['product_image'] ); ?>
			<?php else : ?>
				<span class="zbm-product-image-placeholder" aria-hidden="true"><?php esc_html_e( 'محصول', 'zarbalman-core' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="zbm-detail-hero-body">
			<span class="zbm-dashboard-kicker"><?php esc_html_e( 'جزئیات درخواست خرید', 'zarbalman-core' ); ?></span>
			<h2 id="zbm-buyer-detail-title"><?php echo esc_html( (string) $view['product_name'] ); ?></h2>
			<div class="zbm-detail-meta">
				<span class="zbm-state zbm-state-<?php echo esc_attr( (string) $view['state_tone'] ); ?>"><?php echo esc_html( (string) $view['state_label'] ); ?></span>
				<span class="zbm-card-id"><?php esc_html_e( 'شناسه', 'zarbalman-core' ); ?>: <?php echo esc_html( (string) $view['request_number'] ); ?></span>
			</div>
			<?php if ( ! empty( $view['product_url'] ) ) : ?><a class="zbm-detail-product-link" href="<?php echo esc_url( (string) $view['product_url'] ); ?>"><?php esc_html_e( 'مشاهده صفحه محصول', 'zarbalman-core' ); ?> <span aria-hidden="true">←</span></a><?php endif; ?>
		</div>
	</section>
	<?php if ( ! empty( $view['is_test'] ) ) : ?><div class="zbm-alert zbm-alert-test" role="status"><strong><?php esc_html_e( 'پرونده آزمایشی است.', 'zarbalman-core' ); ?></strong> <?php esc_html_e( 'مدیر فقط برای ادامهٔ توسعه، مرحلهٔ پیش‌پرداخت را بدون دریافت پول عبور داده است؛ این وضعیت رسید پرداخت یا طلب مالی ایجاد نمی‌کند.', 'zarbalman-core' ); ?></div><?php endif; ?>
	<?php if ( ! empty( $view['is_paused'] ) ) : ?><div class="zbm-alert zbm-alert-warning"><?php esc_html_e( 'زمان این مرحله توسط مدیریت متوقف شده و پس از بررسی دوباره ادامه داده می‌شود.', 'zarbalman-core' ); ?></div><?php endif; ?>
	<?php if ( ! empty( $view['deadline_iso'] ) ) : ?><p class="zbm-countdown" data-deadline="<?php echo esc_attr( (string) $view['deadline_iso'] ); ?>"></p><?php elseif ( ! empty( $view['deadline_notice'] ) ) : ?><p class="zbm-countdown zbm-countdown-notice" role="status"><?php echo esc_html( (string) $view['deadline_notice'] ); ?></p><?php endif; ?>
	<div class="zbm-detail-grid">
		<?php include ZBM_CORE_DIR . 'templates/request-items.php'; ?>
		<section class="zbm-detail-section" aria-labelledby="zbm-buyer-summary-title">
			<div class="zbm-section-heading"><span class="zbm-section-icon" aria-hidden="true">◈</span><div><span class="zbm-dashboard-kicker"><?php esc_html_e( 'خلاصه سفارش', 'zarbalman-core' ); ?></span><h3 id="zbm-buyer-summary-title"><?php esc_html_e( 'مقدار و مبلغ', 'zarbalman-core' ); ?></h3></div></div>
			<dl class="zbm-facts">
				<dt><?php esc_html_e( 'تعداد بسته', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( (string) $view['quantity'] ); ?></dd>
				<dt><?php esc_html_e( 'وزن هر بسته', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( (string) $view['package_weight'] ); ?></dd>
				<dt><?php esc_html_e( 'وزن تقریبی کل', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( (string) $view['reference_weight'] ); ?></dd>
				<dt><?php esc_html_e( 'قیمت هر بسته', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( (string) $view['package_price'] ); ?></dd>
				<dt><?php esc_html_e( 'مبلغ تقریبی محصول', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( (string) $view['estimated_total'] ); ?></dd>
				<?php if ( $shipping_in_deposit ) : ?>
					<dt><?php esc_html_e( 'حمل تخمینی ثبت‌شده', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( (string) $view['estimated_shipping'] ); ?></dd>
					<dt><?php esc_html_e( 'جمع تخمینی محصول و حمل', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( (string) $view['estimated_gross'] ); ?></dd>
				<?php endif; ?>
				<dt><?php echo esc_html( $shipping_in_deposit ? __( 'پیش‌پرداخت ۱۰٪ جمع تخمینی', 'zarbalman-core' ) : __( 'پیش‌پرداخت ثبت‌شدهٔ محصول', 'zarbalman-core' ) ); ?></dt><dd><?php echo esc_html( (string) $view['deposit'] ); ?></dd>
			</dl>
		</section>
		<section class="zbm-detail-section" aria-labelledby="zbm-buyer-delivery-title">
			<div class="zbm-section-heading"><span class="zbm-section-icon" aria-hidden="true">⌖</span><div><span class="zbm-dashboard-kicker"><?php esc_html_e( 'هماهنگی دریافت', 'zarbalman-core' ); ?></span><h3 id="zbm-buyer-delivery-title"><?php esc_html_e( 'روش و زمان دریافت', 'zarbalman-core' ); ?></h3></div></div>
			<dl class="zbm-facts">
				<dt><?php esc_html_e( 'روش دریافت', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( (string) $view['delivery_method'] ); ?></dd>
				<dt><?php esc_html_e( 'بازهٔ اولیهٔ درخواست', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( (string) $view['delivery_window'] ); ?></dd>
				<dt><?php esc_html_e( 'دریافت‌کننده', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $delivery['recipient_name'] ); ?></dd>
				<?php if ( $pickup_address ) : ?>
					<?php foreach ( array( 'store_name' => __( 'فروشگاه محل دریافت', 'zarbalman-core' ), 'street_1' => __( 'نشانی فروشنده', 'zarbalman-core' ), 'zip' => __( 'کدپستی فروشگاه', 'zarbalman-core' ) ) as $field => $label ) : ?>
						<?php if ( is_string( $pickup_address[ $field ] ?? null ) && '' !== trim( $pickup_address[ $field ] ) ) : ?><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $pickup_address[ $field ] ); ?></dd><?php endif; ?>
					<?php endforeach; ?>
					<dt><?php esc_html_e( 'تأیید مراجعه', 'zarbalman-core' ); ?></dt><dd><?php esc_html_e( 'نشانی بررسی و توانایی مراجعه تأیید شده است.', 'zarbalman-core' ); ?></dd>
					<dt><?php esc_html_e( 'توضیحات مراجعه', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( '' !== $pickup_note ? $pickup_note : __( 'بدون توضیح', 'zarbalman-core' ) ); ?></dd>
				<?php endif; ?>
				<?php if ( ! empty( $delivery['province_name'] ) ) : ?><dt><?php esc_html_e( 'استان مقصد', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( (string) $delivery['province_name'] ); ?></dd><?php endif; ?>
				<?php if ( null !== $delivery['postal_code'] ) : ?><dt><?php esc_html_e( 'کدپستی', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $delivery['postal_code'] ); ?></dd><?php endif; ?>
				<?php if ( null !== $delivery['address'] ) : ?><dt><?php esc_html_e( 'نشانی', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $delivery['address'] ); ?></dd><?php endif; ?>
				<?php if ( isset( $delivery['shipping_rate_per_kg_toman'] ) ) : ?><dt><?php esc_html_e( 'نرخ حمل هر کیلو', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( number_format_i18n( (int) $delivery['shipping_rate_per_kg_toman'] ) . ' ' . __( 'تومان', 'zarbalman-core' ) ); ?></dd><?php endif; ?>
				<?php if ( ! $shipping_in_deposit && isset( $delivery['estimated_shipping_toman'] ) ) : ?><dt><?php esc_html_e( 'حمل تخمینی درخواست قدیمی', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( number_format_i18n( (int) $delivery['estimated_shipping_toman'] ) . ' ' . __( 'تومان؛ در پیش‌پرداخت قبلی لحاظ نشده', 'zarbalman-core' ) ); ?></dd><?php endif; ?>
				<?php if ( ! $shipping_in_deposit && is_string( $view['estimated_total_with_shipping'] ?? null ) ) : ?><dt><?php esc_html_e( 'برآورد قبلی محصول و حمل', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $view['estimated_total_with_shipping'] ); ?> <small><?php esc_html_e( '(اطلاع‌رسانی؛ بدون تغییر پرداخت قبلی)', 'zarbalman-core' ); ?></small></dd><?php endif; ?>
			</dl>
		</section>
	</div>
	<?php if ( ! empty( $view['rejection_reason'] ) ) : ?><div class="zbm-alert zbm-alert-danger"><strong><?php esc_html_e( 'دلیل رد:', 'zarbalman-core' ); ?></strong> <?php echo esc_html( (string) $view['rejection_reason'] ); ?></div><?php endif; ?>
	<?php echo $managed_panel; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Role-safe managed template escapes all dynamic data. ?>
	<div class="zbm-detail-actions">
		<a class="zbm-back-link zbm-back-link-secondary" href="<?php echo esc_url( $back_url ); ?>"><span aria-hidden="true">←</span> <?php esc_html_e( 'بازگشت به درخواست‌ها', 'zarbalman-core' ); ?></a>
	</div>
</div>

<?php
/**
 * @var Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest $request
 * @var string $role
 * @var int $actor_id
 * @var array<string,mixed> $flow
 * @var array<string,mixed> $manual
 * @var array<string,mixed> $line
 * @var array<string,mixed> $delivery
 * @var array<string,mixed> $address
 * @var array<string,mixed> $final
 * @var array<string,mixed> $funds
 * @var int $version
 * @var string $state
 * @var string $state_label
 * @var bool $paused
 * @var bool $sync_pending
 * @var bool $show_online_payment
 * @var bool $show_gateway_unavailable
 * @var bool $show_gateway_preparing
 * @var bool $show_managed_details
 * @var bool $deposit_receipt_ready
 * @var bool $show_deposit_preparing_notice
 * @var string|null $deposit_deadline_iso
 * @var string|null $deposit_deadline_label
 */
defined( 'ABSPATH' ) || exit;

$money = static fn ( mixed $amount ): string => is_int( $amount ) ? number_format_i18n( $amount ) . ' ' . __( 'تومان', 'zarbalman-core' ) : '—';
$weight = static function ( mixed $tenths ): string {
	if ( ! is_int( $tenths ) ) {
		return '—';
	}
	return number_format_i18n( intdiv( $tenths, 10 ) ) . ( 0 !== $tenths % 10 ? '٫' . abs( $tenths % 10 ) : '' ) . ' ' . __( 'کیلوگرم', 'zarbalman-core' );
};
$shipping_rate_version = is_string( $address['shipping_rate_version'] ?? null ) ? trim( $address['shipping_rate_version'] ) : '';
$shipping_rate_version = 1 === preg_match( '/\A[a-f0-9]{64}\z/i', $shipping_rate_version ) ? $shipping_rate_version : '';
$command_fields = static function ( string $operation ) use ( $request, $version, $nonce_action, &$line, $role, $flow ): void {
	?>
	<input type="hidden" name="action" value="zbm_managed_case_action">
	<input type="hidden" name="operation" value="<?php echo esc_attr( $operation ); ?>">
	<?php if ( 'admin' === $role && count( $flow['lines'] ?? array() ) > 1 && in_array( $operation, array( 'flow_set_final_weight', 'flow_reopen_buyer', 'flow_reopen_seller', 'flow_reopen_preparation' ), true ) ) : ?>
		<label><?php esc_html_e( 'محصول مورد بررسی', 'zarbalman-core' ); ?><select name="line_id" required><option value=""><?php esc_html_e( 'انتخاب محصول', 'zarbalman-core' ); ?></option><?php foreach ( $flow['lines'] as $row ) : if ( null !== $row['final_tenths'] ) { continue; } ?><option value="<?php echo esc_attr( $row['line_id'] ); ?>"><?php echo esc_html( $row['name'] ); ?></option><?php endforeach; ?></select></label>
	<?php else : ?><input type="hidden" name="line_id" value="<?php echo esc_attr( (string) ( $line['line_id'] ?? '' ) ); ?>"><?php endif; ?>
	<input type="hidden" name="request_id" value="<?php echo absint( $request->id() ); ?>">
	<input type="hidden" name="version" value="<?php echo absint( $version ); ?>">
	<input type="hidden" name="idempotency_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
	<?php wp_nonce_field( $nonce_action, '_zbm_nonce' ); ?>
	<?php
};
$evidence_links = static function ( mixed $links, string $label, bool $show_preview = true ): void {
	if ( ! is_array( $links ) || array() === $links ) {
		return;
	}
	?>
	<div class="zbm-managed-evidence"><span><?php echo esc_html( $label ); ?></span>
		<?php foreach ( $links as $index => $link ) : if ( ! is_array( $link ) || ! is_string( $link['url'] ?? null ) ) { continue; } ?>
			<a href="<?php echo esc_url( $link['preview_url'] ?? $link['url'] ); ?>" target="_blank" rel="noopener noreferrer">
				<?php if ( $show_preview && isset( $link['preview_url'] ) ) : ?><img src="<?php echo esc_url( $link['preview_url'] ); ?>" alt="<?php echo esc_attr( sprintf( __( '%1$s، عکس %2$d', 'zarbalman-core' ), $label, $index + 1 ) ); ?>" loading="lazy" decoding="async" width="160" height="120"><?php endif; ?>
				<?php echo esc_html( sprintf( __( 'مشاهده مدرک %d', 'zarbalman-core' ), $index + 1 ) ); ?>
			</a>
		<?php endforeach; ?>
	</div>
	<?php
};
$receipt_images = static function ( mixed $links, string $label ) use ( $is_finance_admin, $evidence_links ): void {
	if ( ! $is_finance_admin ) { $evidence_links( $links, $label ); return; }
	foreach ( is_array( $links ) ? $links : array() as $link ) {
		if ( ! is_array( $link ) || ! is_string( $link['url'] ?? null ) ) { continue; }
		?>
		<figure class="zbm-manual-receipt-preview">
			<figcaption><?php echo esc_html( $label ); ?></figcaption>
			<?php if ( is_string( $link['preview_url'] ?? null ) ) : ?>
				<img src="<?php echo esc_url( $link['preview_url'] ); ?>" alt="<?php esc_attr_e( 'تصویر رسید برای تطبیق با گردش حساب بانکی', 'zarbalman-core' ); ?>" loading="lazy" decoding="async" referrerpolicy="no-referrer" data-zbm-receipt-image>
				<button type="button" class="button" data-zbm-receipt-expand aria-expanded="false"><?php esc_html_e( 'نمایش تصویر در اندازهٔ بزرگ‌تر', 'zarbalman-core' ); ?></button>
				<p data-zbm-receipt-image-error hidden><?php esc_html_e( 'پیش‌نمایش این فایل در دسترس نیست؛ اصل مدرک را بررسی کنید.', 'zarbalman-core' ); ?></p>
			<?php else : ?><p><?php esc_html_e( 'این مدرک در قالب تصویر قابل نمایش ثبت نشده است.', 'zarbalman-core' ); ?></p><?php endif; ?>
			<details><summary><?php esc_html_e( 'دسترسی به اصل مدرک', 'zarbalman-core' ); ?></summary><a href="<?php echo esc_url( $link['url'] ); ?>" rel="noopener noreferrer"><?php esc_html_e( 'دریافت اصل مدرک ثبت‌شده', 'zarbalman-core' ); ?></a></details>
		</figure>
		<?php
	}
};
?>
<section class="zbm-managed-panel" data-zbm-case="<?php echo absint( $request->id() ); ?>" data-zbm-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" dir="rtl" aria-labelledby="zbm-managed-title-<?php echo absint( $request->id() ); ?>">
	<header class="zbm-managed-header">
		<div>
			<span class="zbm-managed-kicker"><?php esc_html_e( 'پیگیری درخواست شما', 'zarbalman-core' ); ?></span>
			<h3 id="zbm-managed-title-<?php echo absint( $request->id() ); ?>"><?php echo esc_html( $state_label ); ?></h3>
		</div>
	</header>

	<?php if ( is_array( $notice ) ) : ?>
		<div class="zbm-managed-alert <?php echo 'success' === $notice['type'] ? 'is-success' : 'is-error'; ?>" role="status" tabindex="-1" data-zbm-request-feedback><?php echo esc_html( $notice['message'] ); ?></div>
	<?php endif; ?>
	<?php if ( $request->isTest() ) : ?>
		<div class="zbm-managed-alert is-test" role="status"><strong><?php esc_html_e( 'پروندهٔ آزمایشی قدیمی', 'zarbalman-core' ); ?></strong> <?php esc_html_e( 'این پرونده به پرداخت دستی واقعی یا اعتبار مالی فروشنده تبدیل نمی‌شود.', 'zarbalman-core' ); ?></div>
	<?php endif; ?>
	<?php if ( $paused ) : ?><div class="zbm-managed-alert is-warning" role="status"><?php esc_html_e( 'عملیات موقتاً توسط مدیریت متوقف شده است. در صورت تغییر زمان تحویل، بازهٔ جدید باید توسط مدیر ثبت شود.', 'zarbalman-core' ); ?></div><?php endif; ?>
	<?php if ( $show_managed_details && $operational_blocked ) : ?><div class="zbm-managed-alert is-warning" role="status"><?php esc_html_e( 'ادامهٔ این پرونده به بررسی اطلاعات ثبت‌شده نیاز دارد. مدیریت باید وضعیت را بررسی کند؛ پرداخت یا رسید را دوباره ارسال نکنید.', 'zarbalman-core' ); ?></div><?php endif; ?>
	<?php if ( $sync_pending && 'admin' === $role ) : ?><div class="zbm-managed-alert is-warning" role="status"><?php echo esc_html( true === ( $manual['receipt_projection_safe'] ?? false ) ? __( 'ثبت رسید خریدار فعال است. هماهنگی سفارش ووکامرس در صف بررسی قرار دارد؛ رسید به‌تنهایی تأیید دریافت وجه نیست.', 'zarbalman-core' ) : ( $operational_blocked ? __( 'مغایرت نیازمند بررسی است؛ عملیات بسته مانده است.', 'zarbalman-core' ) : __( 'پرداخت و دفتر مالی تطبیق دارند. عملیات باز است و هماهنگی ووکامرس جداگانه پیگیری می‌شود.', 'zarbalman-core' ) ) ); ?></div><?php endif; ?>
	<?php if ( $show_deposit_preparing_notice ) : ?><div class="zbm-managed-alert is-info" role="status"><?php esc_html_e( 'اطلاعات پرداخت پیش‌پرداخت در حال آماده‌سازی است؛ مهلت یک‌روزه پس از فعال‌شدن روش پرداخت شروع می‌شود و شمارش معکوس در همین صفحه نمایش داده خواهد شد.', 'zarbalman-core' ); ?></div><?php endif; ?>

	<?php include ZBM_CORE_DIR . 'templates/managed/payment.php'; ?>

	<?php if ( $show_managed_details ) : ?>
		<?php include ZBM_CORE_DIR . 'templates/managed/progress.php'; ?>
		<?php
		$is_pickup_delivery = \Zarbalman\Core\Domain\PurchaseRequest\DeliveryMethod::PICKUP_FROM_VENDOR === $request->deliveryMethod();
		$pickup_fields = 'admin' === $role
			? array( 'store_name' => __( 'فروشگاه', 'zarbalman-core' ), 'street_1' => __( 'نشانی', 'zarbalman-core' ), 'street_2' => __( 'ادامه نشانی', 'zarbalman-core' ), 'city' => __( 'شهر فروشگاه', 'zarbalman-core' ), 'state' => __( 'استان فروشگاه', 'zarbalman-core' ), 'zip' => __( 'کدپستی فروشگاه', 'zarbalman-core' ), 'country' => __( 'کشور', 'zarbalman-core' ) )
			: array( 'store_name' => __( 'فروشگاه', 'zarbalman-core' ), 'street_1' => __( 'نشانی', 'zarbalman-core' ), 'zip' => __( 'کدپستی', 'zarbalman-core' ) );
		$delivery_fields = array( 'store_name' => __( 'فروشگاه', 'zarbalman-core' ), 'recipient_name' => __( 'دریافت‌کننده', 'zarbalman-core' ), 'province_name' => __( 'استان مقصد', 'zarbalman-core' ), 'street_1' => __( 'نشانی', 'zarbalman-core' ), 'street_2' => __( 'ادامه نشانی', 'zarbalman-core' ), 'address' => __( 'نشانی', 'zarbalman-core' ), 'city' => __( 'شهر', 'zarbalman-core' ), 'state' => __( 'استان', 'zarbalman-core' ), 'zip' => __( 'کدپستی', 'zarbalman-core' ), 'postal_code' => __( 'کدپستی', 'zarbalman-core' ), 'country' => __( 'کشور', 'zarbalman-core' ), 'pickup_note' => __( 'توضیحات مراجعه', 'zarbalman-core' ), 'shipping_rate_per_kg_toman' => __( 'نرخ حمل هر کیلو', 'zarbalman-core' ), 'estimated_shipping_toman' => __( 'هزینهٔ حمل تقریبی', 'zarbalman-core' ), 'final_shipping_toman' => __( 'هزینهٔ حمل نهایی', 'zarbalman-core' ) );
		$address_fields = $is_pickup_delivery ? $pickup_fields : $delivery_fields;
		?>
		<div class="zbm-managed-grid">
			<?php if ( count( $flow['lines'] ?? array() ) < 2 ) : ?>
			<article class="zbm-managed-card">
				<h4><?php esc_html_e( 'وزن‌ها و مدارک درخواست', 'zarbalman-core' ); ?></h4>
				<dl class="zbm-managed-facts">
					<dt><?php esc_html_e( 'وزن مرجع', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $weight( $line['reference_tenths'] ?? null ) ); ?></dd>
					<dt><?php esc_html_e( 'وزن ثبت‌شده فروشنده', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $weight( $line['vendor_tenths'] ?? null ) ); ?></dd>
					<dt><?php esc_html_e( 'وزن دریافتی خریدار', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $weight( $line['buyer_tenths'] ?? null ) ); ?></dd>
					<dt><?php esc_html_e( 'وزن نهایی', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $weight( $line['final_tenths'] ?? null ) ); ?></dd>
				</dl>
				<?php $evidence_links( $line['vendor_evidence'] ?? array(), __( 'مدارک وزن فروشنده', 'zarbalman-core' ) ); ?>
				<?php $evidence_links( $line['buyer_evidence'] ?? array(), __( 'مدارک وزن خریدار', 'zarbalman-core' ) ); ?>
				<?php $evidence_links( $line['buyer_objection'] ?? array(), __( 'مدارک اعتراض خریدار', 'zarbalman-core' ) ); ?>
				<?php $evidence_links( $line['vendor_objection'] ?? array(), __( 'مدارک اعتراض فروشنده', 'zarbalman-core' ) ); ?>
				<?php foreach ( array( 'buyer_reason' => __( 'توضیحات خریدار', 'zarbalman-core' ), 'vendor_reason' => __( 'توضیحات فروشنده', 'zarbalman-core' ) ) as $key => $label ) : if ( empty( $line[ $key ] ) ) { continue; } ?>
					<p class="zbm-managed-buyer-note"><strong><?php echo esc_html( $label ); ?>:</strong> <?php echo esc_html( $line[ $key ] ); ?></p>
				<?php endforeach; ?>
			</article>

			<?php endif; ?>
			<article class="zbm-managed-card">
				<h4><?php esc_html_e( 'تحویل امن', 'zarbalman-core' ); ?></h4>
				<?php if ( array() !== $address ) : ?>
					<dl class="zbm-managed-facts">
						<?php foreach ( $address_fields as $field => $label ) : ?>
			<?php $address_value = $address[ $field ] ?? null; ?>
			<?php if ( is_string( $address_value ) && '' !== trim( $address_value ) ) : ?><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $address_value ); ?></dd><?php elseif ( is_int( $address_value ) ) : ?><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( 0 === $address_value ? __( 'رایگان', 'zarbalman-core' ) : number_format_i18n( $address_value ) . ' ' . __( 'تومان', 'zarbalman-core' ) ); ?></dd><?php endif; ?>
						<?php endforeach; ?>
						<?php if ( true === ( $address['pickup_visit_confirmed'] ?? null ) ) : ?>
							<dt><?php esc_html_e( 'آگاهی از نشانی', 'zarbalman-core' ); ?></dt><dd><?php esc_html_e( 'نشانی و توانایی مراجعه توسط خریدار تأیید شده است.', 'zarbalman-core' ); ?></dd>
						<?php endif; ?>
						<?php if ( 'admin' === $role && '' !== $shipping_rate_version ) : ?>
							<dt><?php esc_html_e( 'نسخه snapshot نرخ حمل', 'zarbalman-core' ); ?></dt><dd><code dir="ltr"><?php echo esc_html( $shipping_rate_version ); ?></code></dd>
						<?php endif; ?>
					</dl>
				<?php else : ?><p class="zbm-managed-muted"><?php esc_html_e( 'نشانی مرحله هنوز قطعی نشده است.', 'zarbalman-core' ); ?></p><?php endif; ?>
				<?php if ( 'buyer' === $role && is_string( $delivery['code'] ?? null ) && '' !== $delivery['code'] ) : ?>
					<?php if ( count( $flow['lines'] ?? array() ) > 1 ) : ?><p><?php esc_html_e( 'این کد برای تحویل همهٔ محصولات است. ابتدا همهٔ ردیف‌ها و تعداد بسته‌ها را بررسی کنید؛ اگر قلمی نرسیده است، کد را اعلام نکنید و از پشتیبانی بخواهید موضوع را بررسی کند.', 'zarbalman-core' ); ?></p><?php endif; ?>
					<div class="zbm-delivery-code"><span><?php esc_html_e( 'کد شش‌رقمی تحویل', 'zarbalman-core' ); ?></span><strong dir="ltr"><?php echo esc_html( $delivery['code'] ); ?></strong><small><?php esc_html_e( 'این کد را فقط هنگام تحویل واقعی به فروشنده اعلام کنید.', 'zarbalman-core' ); ?></small></div>
				<?php endif; ?>
				<?php if ( isset( $delivery['attempts'] ) ) : ?><p class="zbm-managed-muted"><?php echo esc_html( sprintf( __( 'تلاش‌های ناموفق ثبت کد: %d از ۵', 'zarbalman-core' ), (int) $delivery['attempts'] ) ); ?></p><?php endif; ?>
			</article>
		</div>

		<?php include ZBM_CORE_DIR . ( count( $flow['lines'] ?? array() ) > 1 ? 'templates/managed/line-cards.php' : 'templates/managed/weights.php' ); ?>
		<?php include ZBM_CORE_DIR . 'templates/managed/evidence-history.php'; ?>
		<?php if ( $final_locked ) : ?>
		<article class="zbm-managed-card zbm-ops-final"><h4><?php esc_html_e( 'محاسبه بر اساس وزن نهایی', 'zarbalman-core' ); ?></h4><dl class="zbm-managed-facts">
			<?php if ( isset( $final['product'], $final['shipping'] ) ) : ?>
			<dt><?php esc_html_e( 'مبلغ محصول', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $money( $final['product'] ) ); ?></dd>
			<dt><?php esc_html_e( 'حمل نهایی', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $money( $final['shipping'] ) ); ?></dd>
			<?php endif; ?>
			<dt><?php esc_html_e( 'جمع نهایی', 'zarbalman-core' ); ?></dt><dd><strong><?php echo esc_html( $money( $final['total'] ) ); ?></strong></dd>
			<dt><?php esc_html_e( 'پیش‌پرداخت تأییدشده', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $money( $funds['deposit'] ?? null ) ); ?></dd>
			<?php if ( ( $funds['balance'] ?? 0 ) > 0 ) : ?><dt><?php esc_html_e( 'ماندهٔ پرداخت‌شده', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $money( $funds['balance'] ) ); ?></dd><?php endif; ?>
			<?php if ( empty( $finance_view['trigger'] ) ) : ?><dt><?php echo esc_html( ( $final['remaining'] ?? 0 ) < 0 ? __( 'اضافه‌پرداخت نیازمند بررسی', 'zarbalman-core' ) : __( 'ماندهٔ محاسبه‌شده', 'zarbalman-core' ) ); ?></dt><dd><?php echo esc_html( $money( is_int( $final['remaining'] ?? null ) ? abs( $final['remaining'] ) : null ) ); ?></dd><?php endif; ?>
			<?php if ( 'admin' === $role && empty( $finance_view['trigger'] ) ) : ?><dt><?php esc_html_e( 'کمیسیون کل', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $money( $final['commission'] ?? null ) ); ?></dd><dt><?php esc_html_e( 'خالص فروشنده', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $money( $final['net'] ?? null ) ); ?></dd><?php endif; ?>
		</dl><?php if ( $operations_only ) : ?><p class="zbm-managed-muted"><?php esc_html_e( 'محاسبه ثبت شده است؛ پرداخت مانده در این مرحله فعال نیست.', 'zarbalman-core' ); ?></p><?php endif; ?></article>
		<?php endif; ?>
		<?php if ( $is_operations_admin || $is_finance_admin ) : include ZBM_CORE_DIR . 'templates/managed/admin.php'; endif; ?>
		<?php if ( ! empty( $finance_view ) || $can_create_refund ) : include ZBM_CORE_DIR . 'templates/managed/finance.php'; endif; ?>
	<?php elseif ( \Zarbalman\Core\Domain\PurchaseRequest\RequestState::DEPOSIT_PAID === $request->state() ) : ?>
		<div class="zbm-managed-alert is-info" role="status"><?php esc_html_e( 'پیش‌پرداخت ثبت شده و ساخت پروندهٔ تحویل در صف بازیابی است.', 'zarbalman-core' ); ?></div>
	<?php endif; ?>
</section>

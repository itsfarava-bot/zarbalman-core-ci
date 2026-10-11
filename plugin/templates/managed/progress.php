<?php
/** Shared operational-only facts; safe for buyer and vendor projections. */
defined( 'ABSPATH' ) || exit;
$format_time = static function ( mixed $value ): string {
	if ( ! is_string( $value ) || '' === $value ) { return '—'; }
	try { return \Zarbalman\Core\Support\JalaliDate::formatGmt( $value, wp_timezone(), true ); } catch ( \Throwable ) { return '—'; }
};
$window = is_array( $flow['window'] ?? null ) ? $flow['window'] : array();
$step_states = array( 'preparing' => 0, 'ready_for_delivery' => 1, 'ready_for_pickup' => 1, 'in_transit' => 1, 'buyer_review' => 2, 'seller_review' => 3, 'final_weight_locked' => 4, 'balance_due' => 4, 'completed' => 4 );
$step = $step_states[ $state ] ?? null;
$next_text = match ( $state ) {
	'preparing' => 'vendor' === $role ? __( 'وزن و عکس کالای آماده‌شده را ثبت کنید؛ سپس آماده‌بودن آن را اعلام کنید.', 'zarbalman-core' ) : __( 'پیش‌پرداخت ثبت شده است. فروشنده سفارش را برای بازهٔ توافق‌شده آماده می‌کند.', 'zarbalman-core' ),
	'ready_for_delivery' => 'vendor' === $role ? __( 'کالا آماده است؛ پس از ارسال واقعی، ارسال را ثبت کنید.', 'zarbalman-core' ) : __( 'سفارش آمادهٔ ارسال است. پس از آغاز ارسال، کد تحویل در همین صفحه نمایش داده می‌شود.', 'zarbalman-core' ),
	'ready_for_pickup' => __( 'کالا برای دریافت حضوری آماده است. طبق بازهٔ توافق‌شده مراجعه کنید؛ کد فقط هنگام تحویل واقعی ثبت می‌شود.', 'zarbalman-core' ),
	'in_transit' => __( 'سفارش در مسیر تحویل است. هنگام دریافت کالا، خریدار کد امن را به تحویل‌دهنده اعلام می‌کند.', 'zarbalman-core' ),
	'buyer_review' => 'buyer' === $role ? __( 'کالا تحویل شده است. تا پایان مهلت، وزن دریافتی و عکس واضح ترازو را ثبت کنید.', 'zarbalman-core' ) : __( 'کالا تحویل شده است. منتظر وزن و عکس خریدار باشید؛ پس از ثبت، برای بررسی شما نمایش داده می‌شود.', 'zarbalman-core' ),
	'seller_review' => 'vendor' === $role ? __( 'وزن و عکس خریدار آمادهٔ بررسی است. تا پایان مهلت تأیید کنید یا اعتراض مستند بفرستید.', 'zarbalman-core' ) : __( 'وزن و عکس شما ثبت شده و منتظر بررسی فروشنده است. نیازی به ارسال دوباره نیست.', 'zarbalman-core' ),
	'admin_review' => __( 'پرونده برای بررسی و هماهنگی در اختیار مدیریت است. نتیجه و اقدام بعدی در همین صفحه اعلام می‌شود.', 'zarbalman-core' ),
	'final_weight_locked' => __( 'وزن نهایی ثبت و مدارک در پرونده نگهداری شد. در این مرحله اقدام تازه‌ای برای شما فعال نیست.', 'zarbalman-core' ),
	'completed' => __( 'این درخواست تکمیل شده است. اطلاعات و مدارک ثبت‌شده را می‌توانید مرور کنید.', 'zarbalman-core' ),
	default => __( 'وضعیت و سوابق درخواست در این صفحه قابل پیگیری است.', 'zarbalman-core' ),
};
?>
<?php if ( is_int( $step ) ) : ?>
<ol class="zbm-ops-steps" aria-label="<?php esc_attr_e( 'پیشرفت درخواست', 'zarbalman-core' ); ?>">
	<?php foreach ( array( __( 'آماده‌سازی', 'zarbalman-core' ), __( 'تحویل', 'zarbalman-core' ), __( 'وزن خریدار', 'zarbalman-core' ), __( 'بررسی فروشنده', 'zarbalman-core' ), __( 'وزن نهایی', 'zarbalman-core' ) ) as $index => $label ) : ?>
	<li class="<?php echo $index < $step ? 'is-done' : ( $index === $step ? 'is-current' : '' ); ?>" <?php if ( $index === $step ) { echo 'aria-current="step"'; } ?>><span aria-hidden="true"><?php echo esc_html( number_format_i18n( $index + 1 ) ); ?></span><?php echo esc_html( $label ); ?></li>
	<?php endforeach; ?>
</ol>
<?php endif; ?>
<div class="zbm-ops-next"><strong><?php esc_html_e( 'وضعیت اکنون', 'zarbalman-core' ); ?></strong><p><?php echo esc_html( $next_text ); ?></p></div>
<?php foreach ( $flow['public_notes'] ?? array() as $note ) : ?><div class="zbm-managed-buyer-note"><strong><?php esc_html_e( 'پیام مدیریت', 'zarbalman-core' ); ?></strong><p><?php echo esc_html( $note['text'] ); ?></p><small><?php echo esc_html( $format_time( $note['at_gmt'] ) ); ?></small></div><?php endforeach; ?>
<div class="zbm-ops-window">
	<strong><?php esc_html_e( 'بازهٔ توافق‌شده برای تحویل', 'zarbalman-core' ); ?></strong>
	<span><?php echo esc_html( $format_time( $window['start_gmt'] ?? null ) ); ?> — <?php echo esc_html( $format_time( $window['end_gmt'] ?? null ) ); ?></span>
	<small><?php esc_html_e( 'مبنای ساعت: زمان سایت. ثبت تحویل با کد امن در زمان دریافت کالا انجام می‌شود.', 'zarbalman-core' ); ?></small>
</div>
<?php if ( ! empty( $flow['delivery_expired'] ) || ! empty( $window['overdue_at'] ) ) : ?><div class="zbm-managed-alert is-warning" role="status"><?php esc_html_e( 'بازهٔ تحویل پایان یافته است. مدیریت باید زمان ادامه را هماهنگ کند؛ کالا خودکار تحویل‌شده یا لغوشده محسوب نمی‌شود.', 'zarbalman-core' ); ?></div><?php endif; ?>
<?php if ( ! $paused && is_string( $flow['deadline_gmt'] ?? null ) ) : ?>
<p class="zbm-managed-deadline" data-zbm-review-deadline="<?php echo esc_attr( str_replace( ' ', 'T', $flow['deadline_gmt'] ) . 'Z' ); ?>"><?php echo esc_html( sprintf( __( 'مهلت بررسی تا %s', 'zarbalman-core' ), $format_time( $flow['deadline_gmt'] ) ) ); ?></p>
<?php endif; ?>
<?php if ( ! empty( array_filter( $flow['times'] ?? array() ) ) ) : ?>
<details class="zbm-managed-disclosure"><summary><?php esc_html_e( 'زمان‌های ثبت‌شده', 'zarbalman-core' ); ?></summary><dl class="zbm-managed-facts">
	<?php foreach ( array( 'prepared_at' => __( 'ثبت آماده‌سازی', 'zarbalman-core' ), 'dispatched_at' => __( 'ارسال', 'zarbalman-core' ), 'delivered_at' => __( 'تحویل', 'zarbalman-core' ), 'buyer_submitted_at' => __( 'ثبت وزن خریدار', 'zarbalman-core' ) ) as $key => $label ) : if ( empty( $flow['times'][ $key ] ) ) { continue; } ?>
	<dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $format_time( $flow['times'][ $key ] ) ); ?></dd>
	<?php endforeach; ?>
</dl></details>
<?php endif; ?>

<?php
defined( 'ABSPATH' ) || exit;
$phase_labels = array( 'deposit' => __( 'پیش‌پرداخت ۱۰٪', 'zarbalman-core' ), 'balance' => __( 'ماندهٔ نهایی', 'zarbalman-core' ) );
$status_labels = array( 'offered' => __( 'در انتظار واریز و رسید خریدار', 'zarbalman-core' ), 'submitted' => __( 'رسید ارسال شده؛ در انتظار بررسی مدیریت', 'zarbalman-core' ), 'approved' => __( 'دریافت وجه توسط مدیریت تأیید شد', 'zarbalman-core' ), 'rejected' => __( 'رسید رد شد؛ امکان ارسال دوباره وجود دارد', 'zarbalman-core' ) );
?>
<div class="zbm-managed-payment">
	<?php if ( is_string( $deposit_deadline_iso ) && '' !== trim( $deposit_deadline_iso ) ) : ?>
		<div class="zbm-managed-deposit-deadline" role="group" aria-label="<?php esc_attr_e( 'مهلت پرداخت پیش‌پرداخت ۱۰٪', 'zarbalman-core' ); ?>">
			<strong><?php esc_html_e( 'مهلت پرداخت پیش‌پرداخت ۱۰٪', 'zarbalman-core' ); ?></strong>
			<span class="zbm-countdown" data-deadline="<?php echo esc_attr( $deposit_deadline_iso ); ?>"><?php echo esc_html( is_string( $deposit_deadline_label ) && '' !== trim( $deposit_deadline_label ) ? sprintf( __( 'مهلت تا %s', 'zarbalman-core' ), $deposit_deadline_label ) : __( 'مهلت پرداخت فعال است؛ برای مشاهدهٔ زمان باقی‌مانده صفحه را تازه کنید.', 'zarbalman-core' ) ); ?></span>
			<small><?php esc_html_e( 'با پایان مهلت، ادامهٔ پرداخت فقط پس از بررسی وضعیت از سرور امکان‌پذیر است.', 'zarbalman-core' ); ?></small>
		</div>
	<?php endif; ?>
	<?php if ( $show_online_payment ) : ?>
		<div class="zbm-managed-alert is-success"><strong><?php esc_html_e( 'درگاه آنلاین آماده است.', 'zarbalman-core' ); ?></strong><a class="button alt zbm-managed-primary" href="<?php echo esc_url( $online_payment_url ); ?>"><?php esc_html_e( 'پرداخت پیش‌پرداخت با زرین‌پال', 'zarbalman-core' ); ?></a></div>
	<?php elseif ( $show_gateway_unavailable ) : ?>
		<div class="zbm-managed-alert is-warning" role="status"><?php echo esc_html( $can_offer_manual ? __( 'زرین‌پال اکنون در دسترس نیست. از بخش پرداخت دستی، اطلاعات واریز و فرم ارسال رسید را دریافت کنید.', 'zarbalman-core' ) : __( 'روش پرداخت این درخواست در حال بررسی است. اگر وجهی پرداخت کرده‌اید، دوباره پرداخت نکنید و با پشتیبانی تماس بگیرید.', 'zarbalman-core' ) ); ?></div>
	<?php elseif ( $show_gateway_preparing ) : ?>
		<div class="zbm-managed-alert is-info" role="status"><?php esc_html_e( 'درگاه آماده است و لینک امن پیش‌پرداخت در حال بازیابی است؛ صفحه را کمی بعد تازه کنید.', 'zarbalman-core' ); ?></div>
	<?php endif; ?>

	<?php if ( $can_offer_manual ) : ?>
		<article class="zbm-managed-card is-manual">
			<h4><?php esc_html_e( 'پرداخت دستی واقعی', 'zarbalman-core' ); ?></h4>
			<p><?php echo esc_html( 'balance' === $manual_phase ? __( 'برای دریافت شماره کارت و ثبت رسید ماندهٔ قطعی، این مرحله را آغاز کنید.', 'zarbalman-core' ) : __( 'برای دریافت شماره کارت و ثبت رسید پیش‌پرداخت، این مرحله را آغاز کنید.', 'zarbalman-core' ) ); ?></p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="zbm-managed-form" data-confirm="<?php esc_attr_e( 'پرداخت دستی واقعی برای این پرونده فعال شود؟', 'zarbalman-core' ); ?>">
				<?php $command_fields( 'manual_offer' ); ?>
				<button class="button zbm-managed-primary" type="submit"><?php esc_html_e( 'نمایش اطلاعات پرداخت دستی', 'zarbalman-core' ); ?></button>
			</form>
		</article>
	<?php endif; ?>

	<?php foreach ( $phase_labels as $phase => $phase_label ) : $offer = is_array( $manual['phases'][ $phase ] ?? null ) ? $manual['phases'][ $phase ] : null; if ( null === $offer ) { continue; } ?>
		<?php $historical_offer = 'cancelled' === $state || ! empty( $finance_view['trigger'] ); $collapsed_offer = $historical_offer || 'approved' === ( $offer['status'] ?? '' ); ?>
		<?php if ( $collapsed_offer ) : ?><details class="zbm-managed-disclosure"><summary><?php echo esc_html( $phase_label . ' — ' . ( $historical_offer ? __( 'مشاهدهٔ سابقهٔ پرداخت', 'zarbalman-core' ) : __( 'پرداخت تأییدشده؛ مشاهدهٔ سابقه', 'zarbalman-core' ) ) ); ?></summary><?php endif; ?>
		<article class="zbm-managed-card is-manual">
			<div class="zbm-managed-card-heading"><h4><?php echo esc_html( $phase_label ); ?></h4><span class="zbm-managed-status"><?php echo esc_html( $historical_offer && in_array( $offer['status'] ?? '', array( 'offered', 'rejected' ), true ) ? __( 'اطلاعات تاریخی؛ برای این مبلغ واریز نکنید', 'zarbalman-core' ) : ( $status_labels[ $offer['status'] ?? '' ] ?? __( 'وضعیت نامشخص', 'zarbalman-core' ) ) ); ?></span></div>
			<p class="zbm-managed-amount"><?php echo esc_html( $money( $offer['amount'] ?? null ) ); ?></p>
			<?php if ( ! $historical_offer && in_array( $offer['status'] ?? '', array( 'offered', 'rejected' ), true ) ) : ?><div class="zbm-payment-reference">
				<span><?php esc_html_e( 'شناسه مرجع واریز', 'zarbalman-core' ); ?></span>
				<strong dir="ltr"><?php echo esc_html( $request->requestNumber() ); ?></strong>
				<small><?php esc_html_e( 'این شناسه را در توضیحات انتقال بانکی وارد کنید تا مبلغ و رسید بدون ابهام تطبیق داده شود.', 'zarbalman-core' ); ?></small>
			</div><?php endif; ?>
			<?php if ( ! $receipt_blocked && ! $paused && ( 'deposit' === $phase ? $deposit_receipt_ready : $balance_receipt_ready ) && in_array( $offer['status'] ?? '', array( 'offered', 'rejected' ), true ) && is_array( $offer['account'] ?? null ) && is_string( $offer['account']['card_number'] ?? null ) ) : ?>
				<div class="zbm-bank-card"><span><?php esc_html_e( 'شماره کارت مدیر سایت', 'zarbalman-core' ); ?></span><strong dir="ltr"><?php echo esc_html( trim( chunk_split( $offer['account']['card_number'], 4, ' ' ) ) ); ?></strong><small><?php echo esc_html( (string) ( $offer['account']['holder_name'] ?? '' ) ); ?></small></div>
			<?php endif; ?>
			<?php if ( is_string( $offer['reason'] ?? null ) && '' !== trim( $offer['reason'] ) ) : ?><div class="zbm-managed-alert is-warning"><strong><?php esc_html_e( 'نظر مدیریت:', 'zarbalman-core' ); ?></strong> <?php echo esc_html( $offer['reason'] ); ?></div><?php endif; ?>
			<?php $receipt_images( $offer['receipt_links'] ?? array(), __( 'رسید فعلی', 'zarbalman-core' ) ); ?>
			<?php if ( in_array( $role, array( 'buyer', 'admin' ), true ) && is_string( $offer['buyer_note'] ?? null ) && '' !== trim( $offer['buyer_note'] ) ) : ?><p class="zbm-managed-buyer-note"><strong><?php esc_html_e( 'توضیح خریدار:', 'zarbalman-core' ); ?></strong> <?php echo esc_html( $offer['buyer_note'] ); ?></p><?php endif; ?>
			<?php if ( 'buyer' === $role && 'submitted' === ( $offer['status'] ?? '' ) ) : ?><div class="zbm-managed-alert is-success" role="status"><?php esc_html_e( 'رسید شما برای بررسی به مدیریت رسید. پس از تطبیق با تراکنش بانکی، نتیجه در همین صفحه اعلام می‌شود؛ نیازی به ارسال دوباره نیست.', 'zarbalman-core' ); ?></div><?php endif; ?>
			<?php if ( in_array( $role, array( 'buyer', 'admin' ), true ) && is_array( $offer['receipt_history'] ?? null ) && array() !== $offer['receipt_history'] ) : ?>
				<section class="zbm-managed-history" aria-label="<?php echo esc_attr__( 'تاریخچه رسیدهای پیشین', 'zarbalman-core' ); ?>">
					<h5><?php esc_html_e( 'تاریخچهٔ رسیدهای پیشین', 'zarbalman-core' ); ?></h5>
					<ol>
						<?php foreach ( $offer['receipt_history'] as $history_index => $history ) : if ( ! is_array( $history ) ) { continue; } ?>
							<li>
								<strong><?php echo esc_html( sprintf( __( 'ارسال شماره %d', 'zarbalman-core' ), $history_index + 1 ) ); ?></strong>
								<?php if ( is_string( $history['buyer_note'] ?? null ) && '' !== trim( $history['buyer_note'] ) ) : ?><p><?php echo esc_html( $history['buyer_note'] ); ?></p><?php endif; ?>
								<?php if ( is_string( $history['submitted_at'] ?? null ) && '' !== $history['submitted_at'] ) : ?><span><?php echo esc_html( sprintf( __( 'ارسال: %s (GMT)', 'zarbalman-core' ), $history['submitted_at'] ) ); ?></span><?php endif; ?>
								<?php if ( is_string( $history['reviewed_at'] ?? null ) && '' !== $history['reviewed_at'] ) : ?><span><?php echo esc_html( sprintf( __( 'بررسی: %s (GMT)', 'zarbalman-core' ), $history['reviewed_at'] ) ); ?></span><?php endif; ?>
								<?php if ( is_string( $history['reason'] ?? null ) && '' !== trim( $history['reason'] ) ) : ?><p><strong><?php esc_html_e( 'نتیجه مدیریت:', 'zarbalman-core' ); ?></strong> <?php echo esc_html( $history['reason'] ); ?></p><?php endif; ?>
							<?php $receipt_images( $history['links'] ?? array(), __( 'رسید بایگانی‌شده', 'zarbalman-core' ) ); ?>
							</li>
						<?php endforeach; ?>
					</ol>
				</section>
			<?php endif; ?>

			<?php $receipt_submission_ready = ! $receipt_blocked && ! $paused && ( 'deposit' === $phase ? $deposit_receipt_ready : $balance_receipt_ready ); ?>
			<?php if ( 'buyer' === $role && in_array( $offer['status'] ?? '', array( 'offered', 'rejected' ), true ) && is_string( $offer['offer_id'] ?? null ) && $receipt_submission_ready ) : ?>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $action_url ); ?>" class="zbm-managed-form" data-zbm-receipt-form data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
					<?php $command_fields( 'manual_receipt' ); ?>
					<input type="hidden" name="offer_id" value="<?php echo esc_attr( $offer['offer_id'] ); ?>">
					<label><?php esc_html_e( 'فایل رسید بانکی (هر قالب، حداکثر ۵ مگابایت)', 'zarbalman-core' ); ?><input type="file" name="receipt" required></label>
					<label><?php esc_html_e( 'توضیحات (اختیاری)', 'zarbalman-core' ); ?><textarea name="buyer_note" rows="3" maxlength="1000" placeholder="<?php esc_attr_e( 'مثلاً شماره پیگیری یا توضیح واریز', 'zarbalman-core' ); ?>"></textarea></label>
					<div class="zbm-managed-receipt-feedback" role="status" aria-live="polite" hidden></div>
					<button class="button zbm-managed-primary" type="submit"><?php esc_html_e( 'ارسال امن رسید برای بررسی', 'zarbalman-core' ); ?></button>
				</form>
			<?php elseif ( ! $historical_offer && 'buyer' === $role && $receipt_blocked && in_array( $offer['status'] ?? '', array( 'offered', 'rejected' ), true ) ) : ?>
				<div class="zbm-managed-alert is-info" role="status"><?php esc_html_e( 'وضعیت این پرداخت به بررسی مدیریت نیاز دارد. اگر وجهی واریز کرده‌اید، دوباره پرداخت نکنید و با پشتیبانی تماس بگیرید.', 'zarbalman-core' ); ?></div>
			<?php endif; ?>

			<?php if ( $is_finance_admin && 'submitted' === ( $offer['status'] ?? '' ) ) : ?>
				<div class="zbm-managed-review-grid">
					<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="zbm-managed-form" data-confirm="<?php esc_attr_e( 'دریافت مبلغ دقیق در حساب بانکی را شخصاً بررسی کرده‌اید؟', 'zarbalman-core' ); ?>">
						<?php $command_fields( 'manual_approve' ); ?><input type="hidden" name="phase" value="<?php echo esc_attr( $phase ); ?>">
						<label class="zbm-managed-check"><input type="checkbox" name="confirmed" value="1" required> <?php esc_html_e( 'مبلغ و شناسه درخواست را در گردش حساب بانکی تطبیق دادم.', 'zarbalman-core' ); ?></label>
						<label><?php esc_html_e( 'مبلغ واقعاً وصول‌شده در بانک (تومان)', 'zarbalman-core' ); ?><input type="text" name="confirmed_amount_toman" inputmode="numeric" autocomplete="off" required placeholder="<?php esc_attr_e( 'مبلغ تراکنش را وارد کنید', 'zarbalman-core' ); ?>"><small><?php echo esc_html( sprintf( __( 'مبلغ این مرحله: %s؛ فقط پس از تطبیق دقیق تأیید کنید.', 'zarbalman-core' ), $money( $offer['amount'] ?? null ) ) ); ?></small></label>
						<label><?php esc_html_e( 'دلیل/مرجع اجباری تأیید', 'zarbalman-core' ); ?><textarea name="reason" maxlength="1000" required></textarea></label>
						<button class="button button-primary" type="submit"><?php esc_html_e( 'تأیید دریافت وجه و ادامه', 'zarbalman-core' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="zbm-managed-form">
						<?php $command_fields( 'manual_reject' ); ?><input type="hidden" name="phase" value="<?php echo esc_attr( $phase ); ?>">
						<label><?php esc_html_e( 'دلیل اجباری رد رسید', 'zarbalman-core' ); ?><textarea name="reason" maxlength="1000" required></textarea></label>
						<button class="button" type="submit"><?php esc_html_e( 'رد رسید و درخواست ارسال مجدد', 'zarbalman-core' ); ?></button>
					</form>
				</div>
			<?php endif; ?>
		</article>
		<?php if ( $collapsed_offer ) : ?></details><?php endif; ?>
	<?php endforeach; ?>
</div>

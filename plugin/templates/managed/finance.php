<?php
defined( 'ABSPATH' ) || exit;
$finance_labels = array( 'ready' => 'در انتظار آماده‌شدن روش پرداخت', 'balance_due' => 'پرداخت مانده', 'receipt_review' => 'رسید مانده در بررسی مدیریت', 'contact_due' => 'مهلت پایان یافت؛ در انتظار تماس پشتیبانی', 'warning' => 'فرصت تکمیلی پس از تماس پشتیبانی', 'warning_elapsed' => 'در انتظار تصمیم مدیریت و تأیید وضعیت بار', 'return_confirmed' => 'بازگشت بار تأیید شد؛ ثبت تخصیص مالی', 'refund_pending' => 'بازپرداخت در حال پیگیری', 'payment_review' => 'پرداخت در بررسی مالی', 'completed' => 'پرداخت سفارش کامل شد', 'closed' => 'پرونده مالی بسته شد' );
$substate = $finance_view['substate'] ?? '';
$financial_confirmation = static function (): void {
	?><label class="zbm-managed-check"><input type="checkbox" name="finance_confirmed" value="1" required> <?php esc_html_e( 'مبلغ، مدارک و نتیجهٔ واقعی این اقدام را بررسی و تأیید می‌کنم.', 'zarbalman-core' ); ?></label><?php
};
$action_labels = array( 'sale_site' => 'کمیسیون سفارش تکمیل‌شده', 'sale_vendor' => 'اعتبار فروشنده', 'nonpayment_site' => 'سهم سایت از پیش‌پرداخت بار برگشتی', 'nonpayment_vendor' => 'سهم فروشنده از پیش‌پرداخت بار برگشتی', 'incapacity_site' => 'سهم سایت؛ ناتوانی فروشنده', 'incapacity_refund' => 'استرداد به خریدار؛ ناتوانی فروشنده', 'cancellation_site' => 'سهم سایت؛ لغو پرداخت‌شده', 'cancellation_refund' => 'استرداد به خریدار؛ لغو پرداخت‌شده' );
$action_statuses = array( 'pending' => 'در انتظار تأیید', 'approved' => 'تأییدشده؛ در انتظار اجرا', 'executed' => 'اجراشده؛ در انتظار تطبیق', 'failed' => 'اجرای ناموفق', 'reconciled' => 'تطبیق و نهایی‌شده' );
?>
<section class="zbm-spec4-section" aria-label="<?php esc_attr_e( 'پیگیری مالی سفارش', 'zarbalman-core' ); ?>">
	<?php if ( '' !== $substate ) : ?>
	<article class="zbm-managed-card zbm-spec4-summary">
		<div class="zbm-managed-card-heading"><h4><?php echo esc_html( $finance_labels[ $substate ] ?? 'پیگیری مالی سفارش' ); ?></h4></div>
		<?php if ( in_array( $substate, array( 'balance_due', 'warning' ), true ) ) : ?>
			<p class="zbm-managed-amount"><?php echo esc_html( $money( $finance_view['remaining_toman'] ) ); ?></p>
			<?php if ( $deadline_iso ) : ?><p class="zbm-spec4-clock"><span><?php esc_html_e( 'زمان باقی‌مانده برای پرداخت', 'zarbalman-core' ); ?></span><strong class="zbm-countdown" dir="ltr" data-deadline="<?php echo esc_attr( $deadline_iso ); ?>"><?php esc_html_e( 'در حال محاسبه…', 'zarbalman-core' ); ?></strong></p><?php endif; ?>
			<p><?php esc_html_e( 'مبلغ بالا بر اساس وزن نهایی و حمل محاسبه شده و پیش‌پرداخت تأییدشده از آن کم شده است.', 'zarbalman-core' ); ?></p>
		<?php elseif ( 'payment_review' === $substate ) : ?>
			<p><?php esc_html_e( 'نتیجهٔ پرداخت در حال بررسی است. اگر وجه از حسابتان کم شده، دوباره پرداخت نکنید؛ پشتیبانی نتیجه را در همین پرونده ثبت می‌کند.', 'zarbalman-core' ); ?></p>
		<?php elseif ( in_array( $substate, array( 'contact_due', 'warning_elapsed' ), true ) ) : ?>
			<p><?php esc_html_e( 'پایان مهلت به معنی برگشت کالا نیست. پشتیبانی برای هماهنگی ادامهٔ سفارش با شما تماس می‌گیرد.', 'zarbalman-core' ); ?></p>
		<?php elseif ( 'refund_pending' === $substate ) : ?>
			<p><?php esc_html_e( 'مبلغ قابل‌استرداد ثبت شده است. بازپرداخت پس از انتقال واقعی و تطبیق بانکی نهایی می‌شود.', 'zarbalman-core' ); ?></p>
		<?php endif; ?>
		<?php if ( isset( $finance_view['refund_toman'] ) ) : ?><dl class="zbm-spec4-facts"><dt><?php esc_html_e( 'مبلغ استرداد ثبت‌شده', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $money( $finance_view['refund_toman'] ) ); ?></dd><dt><?php esc_html_e( 'سهم سایت طبق تصمیم ثبت‌شده', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $money( $finance_view['retained_toman'] ) ); ?></dd></dl><?php endif; ?>
		<?php if ( ! empty( $balance_view['can_start'] ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="zbm_balance_start"><input type="hidden" name="request_id" value="<?php echo absint( $request->id() ); ?>"><input type="hidden" name="version" value="<?php echo absint( $version ); ?>"><input type="hidden" name="idempotency_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>"><?php wp_nonce_field( $nonce_action, '_zbm_nonce' ); ?>
			<button type="submit" class="button zbm-managed-primary"><?php esc_html_e( 'پرداخت مانده با زرین‌پال', 'zarbalman-core' ); ?></button>
		</form>
		<?php endif; ?>
	</article>
	<?php endif; ?>
	<?php if ( $is_finance_admin ) : ?>
		<?php if ( ! empty( $balance_view['payment_status'] ) ) : $payment_labels = array( 'expected' => 'لینک بانکی صادر نشده', 'issuance_pending' => 'در انتظار پاسخ صدور لینک', 'issuance_unknown' => 'نتیجهٔ صدور نامشخص؛ پیگیری مستقیم بانک لازم است', 'authority_issued' => 'لینک صادر شده؛ نتیجه پرداخت هنوز قطعی نیست', 'verification_pending' => 'در انتظار استعلام قطعی از زرین‌پال', 'late_captured' => 'وجه وصول شده؛ پذیرش دیرهنگام نیازمند تصمیم است', 'late_accepted' => 'پرداخت دیرهنگام پذیرفته شد', 'captured' => 'پرداخت با استعلام مستقل تأیید شد' ); ?><p class="zbm-managed-alert is-info"><?php echo esc_html( 'پرداخت #' . $balance_view['payment_id'] . ' — ' . ( $payment_labels[ $balance_view['payment_status'] ] ?? 'نیازمند بررسی مالی' ) ); ?></p><?php endif; ?>
		<?php if ( ! empty( $balance_view['can_verify'] ) || ! empty( $balance_view['can_accept_late'] ) ) : ?>
		<article class="zbm-managed-card"><h4><?php esc_html_e( 'بررسی پرداخت آنلاین مانده', 'zarbalman-core' ); ?></h4>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="zbm-managed-form">
				<?php $command_fields( ! empty( $balance_view['can_accept_late'] ) ? 'spec4_accept_late' : 'spec4_reverify' ); if ( ! empty( $balance_view['can_accept_late'] ) ) { $financial_confirmation(); } ?>
				<label><?php esc_html_e( 'دلیل بررسی و تصمیم', 'zarbalman-core' ); ?><textarea name="reason" maxlength="1000" required></textarea></label>
				<button class="button zbm-managed-primary" type="submit"><?php echo esc_html( ! empty( $balance_view['can_accept_late'] ) ? 'پذیرش پرداخت دیرهنگامِ تأییدشده' : 'استعلام مجدد از زرین‌پال' ); ?></button>
			</form>
		</article>
		<?php endif; ?>
		<?php if ( in_array( $substate, array( 'contact_due', 'warning_elapsed' ), true ) && ! $paused ) : ?>
		<article class="zbm-managed-card"><h4><?php esc_html_e( 'ثبت تماس و فرصت تکمیلی', 'zarbalman-core' ); ?></h4>
			<p><?php esc_html_e( 'بعد از تماس واقعی، نتیجه را ثبت کنید. خریدار از همین لحظه ۳۰ دقیقه فرصت تکمیلی می‌گیرد.', 'zarbalman-core' ); ?></p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="zbm-managed-form"><?php $command_fields( 'spec4_contact' ); ?><label><?php esc_html_e( 'نتیجهٔ تماس و هماهنگی', 'zarbalman-core' ); ?><textarea name="reason" maxlength="1000" required></textarea></label><button class="button zbm-managed-primary" type="submit"><?php esc_html_e( 'ثبت تماس و آغاز ۳۰ دقیقه', 'zarbalman-core' ); ?></button></form>
		</article>
		<?php endif; ?>
		<?php if ( 'warning_elapsed' === $substate && ! $paused && $return_quote ) : ?>
		<details class="zbm-managed-disclosure"><summary><?php esc_html_e( 'تأیید بازگشت واقعی بار', 'zarbalman-core' ); ?></summary>
			<dl class="zbm-spec4-facts"><dt><?php esc_html_e( 'پیش‌پرداخت قطعی', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $money( $return_quote['base'] ) ); ?></dd><dt><?php esc_html_e( 'سهم سایت', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $money( $return_quote['site'] ) ); ?></dd><dt><?php esc_html_e( 'اعتبار فروشنده', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $money( $return_quote['vendor'] ) ); ?></dd></dl>
			<?php if ( 1 === $return_quote['base'] % 2 ) : ?><p class="zbm-managed-alert is-info"><?php esc_html_e( 'مبلغ پیش‌پرداخت فرد است؛ برای ثبت تومان صحیح، سهم فروشنده یک تومان بیشتر از سهم سایت محاسبه شده است.', 'zarbalman-core' ); ?></p><?php endif; ?>
			<p><?php esc_html_e( 'فقط پس از برگشت واقعی بار تأیید کنید. نصف پیش‌پرداخت قطعی برای فروشنده و نصف دیگر برای سایت ثبت می‌شود. پرداخت نامشخص یا رسید بررسی‌نشده مانع این اقدام است.', 'zarbalman-core' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $action_url ); ?>" class="zbm-managed-form" data-confirm="<?php esc_attr_e( 'بازگشت واقعی کالا و عدم پرداخت مانده را تأیید می‌کنید؟', 'zarbalman-core' ); ?>"><?php $command_fields( 'spec4_confirm_return' ); $financial_confirmation(); ?><label><?php esc_html_e( 'دلیل و مشخصات بازگشت', 'zarbalman-core' ); ?><textarea name="reason" maxlength="1000" required></textarea></label><label><?php esc_html_e( 'عکس مدرک بازگشت (۱ تا ۴ عکس)', 'zarbalman-core' ); ?><input type="file" name="evidence[]" accept="image/jpeg,image/png,image/webp" multiple required></label><button class="button" type="submit"><?php esc_html_e( 'تأیید بازگشت و ثبت تخصیص', 'zarbalman-core' ); ?></button></form>
		</details>
		<?php endif; ?>
		<?php if ( $can_create_refund ) : ?>
		<details class="zbm-managed-disclosure"><summary><?php esc_html_e( 'ثبت درخواست بازپرداخت', 'zarbalman-core' ); ?></summary>
			<dl class="zbm-spec4-facts"><dt><?php esc_html_e( 'پایهٔ ثابت محاسبه', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $money( $refund_quote['base'] ) ); ?></dd><dt><?php esc_html_e( 'سهم سایت', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $money( $refund_quote['site'] ) ); ?></dd><dt><?php esc_html_e( 'قابل‌استرداد به خریدار', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $money( $refund_quote['refund'] ) ); ?></dd></dl>
			<p><?php esc_html_e( 'سهم سایت یک درصد پایهٔ معتبر پرونده است؛ بقیهٔ وجه وصول‌شده برای استرداد ثبت می‌شود. مبلغ و مقصد پس از ثبت قابل ویرایش نیستند. مالکیت شبا را با خریدار تطبیق دهید.', 'zarbalman-core' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $action_url ); ?>" class="zbm-managed-form" data-confirm="<?php esc_attr_e( 'پایهٔ مبلغ و شبای خریدار را بررسی کرده‌اید؟', 'zarbalman-core' ); ?>">
				<?php $command_fields( 'spec4_create_refund' ); $financial_confirmation(); ?><label><?php esc_html_e( 'علت بازپرداخت', 'zarbalman-core' ); ?><select name="trigger" required><option value="seller_incapacity"><?php esc_html_e( 'ناتوانی جدی یا فوت فروشنده', 'zarbalman-core' ); ?></option><option value="paid_cancellation"><?php esc_html_e( 'لغو پس از پرداخت', 'zarbalman-core' ); ?></option></select></label>
				<label><?php esc_html_e( 'شبای تأییدشدهٔ خریدار', 'zarbalman-core' ); ?><input name="iban" dir="ltr" autocomplete="off" maxlength="32" placeholder="IR…" required></label>
				<label><?php esc_html_e( 'دلیل و تأیید پایهٔ محاسبه', 'zarbalman-core' ); ?><textarea name="reason" maxlength="1000" required></textarea></label>
				<label><?php esc_html_e( 'عکس مدرک تصمیم', 'zarbalman-core' ); ?><input type="file" name="evidence[]" accept="image/jpeg,image/png,image/webp" multiple required></label>
				<button class="button" type="submit"><?php esc_html_e( 'ثبت محاسبه و درخواست بازپرداخت', 'zarbalman-core' ); ?></button>
			</form>
		</details>
		<?php endif; ?>
		<?php foreach ( $finance_view['actions'] ?? array() as $financial_action ) : $kind = $financial_action['action_type']; $action_status = $financial_action['status']; ?>
		<article class="zbm-managed-card zbm-financial-action">
			<div class="zbm-managed-card-heading"><h4><?php echo esc_html( $action_labels[ $kind ] ?? $kind ); ?></h4><span class="zbm-managed-status"><?php echo esc_html( $action_statuses[ $action_status ] ?? $action_status ); ?></span></div>
			<p class="zbm-managed-amount"><?php echo esc_html( $money( \Zarbalman\Core\Infrastructure\FinancialActionRepository::amount( $financial_action ) ) ); ?></p>
			<p class="zbm-managed-muted"><?php echo esc_html( 'پایهٔ محاسبه: ' . $money( (int) $financial_action['base_amount_toman'] ) ); ?></p>
			<dl class="zbm-spec4-facts"><dt><?php esc_html_e( 'شناسه اقدام', 'zarbalman-core' ); ?></dt><dd>#<?php echo absint( $financial_action['id'] ); ?></dd>
			<?php if ( $financial_action['destination_last4'] ) : ?><dt><?php esc_html_e( 'انتهای شبای مقصد', 'zarbalman-core' ); ?></dt><dd dir="ltr">…<?php echo esc_html( $financial_action['destination_last4'] ); ?></dd><?php endif; ?>
			<?php if ( $financial_action['external_reference'] ) : ?><dt><?php esc_html_e( 'مرجع اجرا', 'zarbalman-core' ); ?></dt><dd class="zbm-spec4-reference"><?php echo esc_html( $financial_action['external_reference'] ); ?></dd><?php endif; ?>
			<?php foreach ( array( 'created' => 'ثبت‌کننده', 'approved' => 'تأییدکننده', 'executed' => 'اجراکننده', 'reconciled' => 'تطبیق‌دهنده', 'failed' => 'ثبت‌کنندهٔ شکست' ) as $step => $label ) : if ( null === ( $financial_action[ $step . '_at_gmt' ] ?? null ) ) { continue; } $admin = get_userdata( (int) ( $financial_action[ $step . '_by' ] ?? 0 ) ); ?><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $admin ? $admin->display_name . ' (#' . $admin->ID . ')' : 'ثبت خودکار سیستم' ); ?><small><?php echo esc_html( \Zarbalman\Core\Support\JalaliDate::formatGmt( $financial_action[ $step . '_at_gmt' ], wp_timezone(), true ) ); ?></small></dd><?php endforeach; ?>
			</dl>
			<?php if ( $financial_action['destination_last4'] ) : ?>
			<details class="zbm-managed-disclosure"><summary><?php esc_html_e( 'مشاهدهٔ امن شبای کامل', 'zarbalman-core' ); ?></summary><form method="post" action="<?php echo esc_url( $action_url ); ?>" target="_blank" rel="noopener">
				<input type="hidden" name="action" value="zbm_finance_destination"><input type="hidden" name="request_id" value="<?php echo absint( $request->id() ); ?>"><input type="hidden" name="version" value="<?php echo absint( $version ); ?>"><input type="hidden" name="financial_action_id" value="<?php echo absint( $financial_action['id'] ); ?>"><input type="hidden" name="idempotency_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>"><?php wp_nonce_field( 'zbm_finance_destination_' . $request->id() . '_' . $financial_action['id'], '_zbm_nonce' ); ?>
				<label><?php esc_html_e( 'علت مشاهدهٔ مقصد', 'zarbalman-core' ); ?><input name="reason" maxlength="1000" required></label><button class="button" type="submit"><?php esc_html_e( 'ثبت مشاهده و نمایش شبا', 'zarbalman-core' ); ?></button>
			</form></details>
			<?php endif; ?>
			<?php if ( 'refund_pending' === $substate && ! $paused && ! in_array( $action_status, array( 'reconciled' ), true ) ) :
				$operation = match ( $action_status ) { 'pending', 'failed' => 'spec4_approve_action', 'approved' => 'spec4_execute_action', 'executed' => 'spec4_reconcile_action', default => '' };
				$is_refund = str_ends_with( $kind, '_refund' ); ?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $action_url ); ?>" class="zbm-managed-form" data-confirm="<?php esc_attr_e( 'مبلغ، مقصد و مرحلهٔ اقدام را بررسی کرده‌اید؟', 'zarbalman-core' ); ?>">
				<?php $command_fields( $operation ); if ( in_array( $operation, array( 'spec4_execute_action', 'spec4_reconcile_action' ), true ) ) { $financial_confirmation(); } ?><input type="hidden" name="financial_action_id" value="<?php echo absint( $financial_action['id'] ); ?>">
				<?php if ( 'approved' === $action_status && $is_refund ) : ?><label><?php esc_html_e( 'شماره پیگیری انتقال بانکیِ انجام‌شده', 'zarbalman-core' ); ?><input name="reference" maxlength="190" required autocomplete="off"></label><label><?php esc_html_e( 'عکس رسید انتقال', 'zarbalman-core' ); ?><input type="file" name="evidence[]" accept="image/jpeg,image/png,image/webp" multiple required></label><?php endif; ?>
				<label><?php esc_html_e( 'توضیح اجباری اقدام', 'zarbalman-core' ); ?><textarea name="reason" maxlength="1000" required></textarea></label>
				<button class="button zbm-managed-primary" type="submit"><?php echo esc_html( match ( $action_status ) { 'pending', 'failed' => 'تأیید اقدام مالی', 'approved' => $is_refund ? 'ثبت انتقال واقعیِ انجام‌شده' : 'ثبت سهم سایت در دفتر مالی', default => 'تأیید تطبیق با گردش حساب' } ); ?></button>
			</form>
			<?php if ( 'approved' === $action_status ) : ?><form method="post" action="<?php echo esc_url( $action_url ); ?>" class="zbm-managed-form"><?php $command_fields( 'spec4_fail_action' ); ?><input type="hidden" name="financial_action_id" value="<?php echo absint( $financial_action['id'] ); ?>"><label><?php esc_html_e( 'اگر انتقال قطعاً انجام نشده، دلیل شکست را ثبت کنید', 'zarbalman-core' ); ?><textarea name="reason" maxlength="1000" required></textarea></label><button type="submit" class="button"><?php esc_html_e( 'ثبت اجرای ناموفق', 'zarbalman-core' ); ?></button></form><?php endif; ?>
			<?php endif; ?>
		</article>
		<?php endforeach; ?>
		<?php if ( ! empty( $finance_view['contacts'] ) ) : ?><details class="zbm-managed-disclosure"><summary><?php esc_html_e( 'سابقهٔ تماس و تصمیم مدیریت', 'zarbalman-core' ); ?></summary><?php foreach ( $finance_view['contacts'] as $contact ) : $admin = get_userdata( $contact['actor_id'] ); ?><p><strong><?php echo esc_html( $admin ? $admin->display_name : (string) $contact['actor_id'] ); ?></strong> — <?php echo esc_html( \Zarbalman\Core\Support\JalaliDate::formatGmt( $contact['at_gmt'], wp_timezone(), true ) ); ?></p><p><?php echo esc_html( $contact['reason'] ); ?></p><?php endforeach; ?></details><?php endif; ?>
		<?php $receipt_images( $finance_view['evidence'] ?? array(), __( 'مدارک خصوصی تصمیم مالی', 'zarbalman-core' ) ); ?>
		<?php if ( ! empty( $finance_view['decisions'] ) ) : $decision_labels = array( 'contact' => 'ثبت تماس', 'confirm_return' => 'تأیید بازگشت', 'create_refund' => 'ایجاد بازپرداخت', 'approve_action' => 'تأیید اقدام', 'execute_action' => 'ثبت اجرا', 'fail_action' => 'ثبت شکست', 'reconcile_action' => 'تطبیق مالی', 'destination_viewed' => 'مشاهده شبا', 'accept_late' => 'پذیرش پرداخت دیرهنگام', 'reverify_payment' => 'استعلام مجدد' ); ?>
		<details class="zbm-managed-disclosure"><summary><?php esc_html_e( 'تاریخچهٔ اقدامات مالی ادمین‌ها', 'zarbalman-core' ); ?></summary><ol class="zbm-spec4-history"><?php foreach ( $finance_view['decisions'] as $decision ) : $admin = get_userdata( $decision['actor_id'] ); ?><li><strong><?php echo esc_html( $decision_labels[ $decision['action'] ] ?? $decision['action'] ); ?></strong> — <?php echo esc_html( $admin ? $admin->display_name . ' (#' . $admin->ID . ')' : (string) $decision['actor_id'] ); ?><small><?php echo esc_html( \Zarbalman\Core\Support\JalaliDate::formatGmt( $decision['at_gmt'], wp_timezone(), true ) ); ?></small><p><?php echo esc_html( $decision['reason'] ); ?></p><?php if ( $decision['financial_action_id'] ) : ?><span><?php echo esc_html( 'اقدام مالی #' . $decision['financial_action_id'] ); ?></span><?php endif; ?><?php $receipt_images( $decision['evidence'] ?? array(), __( 'مدرک همین اقدام', 'zarbalman-core' ) ); ?></li><?php endforeach; ?></ol></details>
		<?php endif; ?>
	<?php endif; ?>
</section>

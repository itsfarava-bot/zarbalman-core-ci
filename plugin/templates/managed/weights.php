<?php
defined( 'ABSPATH' ) || exit;
$review_expired = is_string( $flow['deadline_gmt'] ?? null ) && $flow['deadline_gmt'] <= current_time( 'mysql', true );
$flow_actions_locked = $paused || $operational_blocked || ! empty( $flow['delivery_expired'] ) || $review_expired;
$vendor_recorded = is_int( $line['vendor_tenths'] ?? null ) && ! empty( $line['vendor_evidence'] );
$show_shared_actions = ! isset( $line_index ) || 0 === $line_index;
$row_final = is_int( $line['final_tenths'] ?? null );
$weight_field = static function (): void { ?>
	<label><?php esc_html_e( 'وزن کل این محصول (کیلوگرم)', 'zarbalman-core' ); ?><input type="text" name="weight" inputmode="decimal" pattern="[0-9۰-۹٠-٩]+([٫.][0-9۰-۹٠-٩])?" maxlength="30" placeholder="مثلاً ۲۲٫۴" required><small><?php esc_html_e( 'مجموع وزن تعداد سفارش‌داده‌شده؛ حداکثر یک رقم اعشار.', 'zarbalman-core' ); ?></small></label>
<?php };
$photos_field = static function (): void { ?>
	<label><?php esc_html_e( 'عکس واضح از وزن کالا — اجباری', 'zarbalman-core' ); ?><input type="file" name="evidence[]" accept="image/jpeg,image/png,image/webp" multiple required><small><?php esc_html_e( '۱ تا ۴ عکس JPG، PNG یا WebP؛ هر عکس حداکثر ۵ مگابایت. عدد ترازو خوانا باشد.', 'zarbalman-core' ); ?></small></label>
<?php };
$warning_field = static function (): void { ?>
	<label class="zbm-managed-check"><input type="checkbox" name="acknowledged" value="1"> <?php esc_html_e( 'اگر اختلاف با وزن مرجع بیشتر از ۲۵٪ است، عدد واردشده را بررسی و این اختلاف را تأیید می‌کنم.', 'zarbalman-core' ); ?></label>
<?php };
?>
<?php if ( 'vendor' === $role && \Zarbalman\Core\Domain\ManagedFlow\FlowState::PREPARING === $state && ! $flow_actions_locked ) : ?>
<div class="zbm-managed-actions zbm-managed-actions-single">
	<?php if ( ! $vendor_recorded ) : ?>
	<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $action_url ); ?>" class="zbm-managed-form">
		<?php $command_fields( 'flow_record_weight' ); ?>
		<h4><?php esc_html_e( 'وزن و عکس کالای آماده‌شده', 'zarbalman-core' ); ?></h4>
		<p><?php esc_html_e( 'این اطلاعات در پرونده باقی می‌ماند. اصلاح بعدی فقط با بازگشایی مدیریت و حفظ سابقه ممکن است.', 'zarbalman-core' ); ?></p>
		<?php $weight_field(); $photos_field(); $warning_field(); ?>
		<button class="button zbm-managed-primary" type="submit"><?php esc_html_e( 'ثبت وزن و عکس', 'zarbalman-core' ); ?></button>
	</form>
	<?php elseif ( $show_shared_actions && ( $all_vendor_recorded ?? true ) ) : $pickup = 'pickup_from_vendor' === ( $flow['delivery_method'] ?? '' ); ?>
	<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="zbm-managed-form" data-confirm="<?php esc_attr_e( 'کالا با وزن و عکس ثبت‌شده آماده است؟', 'zarbalman-core' ); ?>">
		<?php $command_fields( $pickup ? 'flow_ready_pickup' : 'flow_ready_delivery' ); ?>
		<h4><?php esc_html_e( 'اعلام آماده‌بودن کالا', 'zarbalman-core' ); ?></h4>
		<p><?php esc_html_e( 'زمان توافق‌شده همچنان معتبر است. هنگام تحویل واقعی، کد خریدار را در همین صفحه وارد می‌کنید.', 'zarbalman-core' ); ?></p>
		<button class="button zbm-managed-primary" type="submit"><?php echo esc_html( $pickup ? __( 'آمادهٔ دریافت حضوری است', 'zarbalman-core' ) : __( 'آمادهٔ ارسال است', 'zarbalman-core' ) ); ?></button>
	</form>
	<?php endif; ?>
</div>
<?php endif; ?>
<?php if ( $show_shared_actions && 'vendor' === $role && \Zarbalman\Core\Domain\ManagedFlow\FlowState::READY_FOR_DELIVERY === $state && ! $flow_actions_locked ) : ?>
<div class="zbm-managed-actions zbm-managed-actions-single">
	<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="zbm-managed-form" data-confirm="<?php esc_attr_e( 'کالا واقعاً ارسال شده است؟', 'zarbalman-core' ); ?>">
		<?php $command_fields( 'flow_dispatch' ); ?><h4><?php esc_html_e( 'ثبت ارسال', 'zarbalman-core' ); ?></h4>
		<p><?php esc_html_e( 'پس از تحویل کالا به نیروی ارسال این دکمه را بزنید. کد امن تحویل در اختیار خریدار قرار می‌گیرد.', 'zarbalman-core' ); ?></p>
		<button class="button zbm-managed-primary" type="submit"><?php esc_html_e( 'کالا ارسال شد', 'zarbalman-core' ); ?></button>
	</form>
</div>
<?php endif; ?>
<?php if ( $show_shared_actions && 'vendor' === $role && in_array( $state, array( \Zarbalman\Core\Domain\ManagedFlow\FlowState::READY_FOR_PICKUP, \Zarbalman\Core\Domain\ManagedFlow\FlowState::IN_TRANSIT ), true ) && ! $flow_actions_locked ) : ?>
<div class="zbm-managed-actions zbm-managed-actions-single">
	<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="zbm-managed-form">
		<?php $command_fields( 'flow_deliver' ); ?><h4><?php esc_html_e( 'ثبت تحویل با کد خریدار', 'zarbalman-core' ); ?></h4>
		<p><?php esc_html_e( 'کد را فقط هنگام تحویل واقعی از خریدار بگیرید. ثبت کد صحیح، مهلت بررسی وزن خریدار را آغاز می‌کند.', 'zarbalman-core' ); ?></p>
		<label><?php esc_html_e( 'کد شش‌رقمی خریدار', 'zarbalman-core' ); ?><input class="zbm-code-input" type="text" name="code" dir="ltr" inputmode="numeric" pattern="[0-9۰-۹٠-٩]{6}" maxlength="6" autocomplete="one-time-code" required></label>
		<button class="button zbm-managed-primary" type="submit"><?php esc_html_e( 'بررسی کد و ثبت تحویل', 'zarbalman-core' ); ?></button>
	</form>
</div>
<?php endif; ?>
<?php if ( ! $row_final && ! is_int( $line['buyer_tenths'] ?? null ) && 'buyer' === $role && \Zarbalman\Core\Domain\ManagedFlow\FlowState::BUYER_REVIEW === $state && ! $flow_actions_locked ) : ?>
<div class="zbm-managed-actions zbm-managed-actions-single">
	<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $action_url ); ?>" class="zbm-managed-form">
		<?php $command_fields( 'flow_propose_weight' ); ?><h4><?php esc_html_e( 'وزن کالای دریافتی را ثبت کنید', 'zarbalman-core' ); ?></h4>
		<p><?php esc_html_e( 'حتی اگر وزن با عدد فروشنده برابر است، وزن دریافتی و عکس ترازو را بفرستید. نتیجه برای بررسی فروشنده ارسال می‌شود.', 'zarbalman-core' ); ?></p>
		<?php $weight_field(); $photos_field(); ?>
		<label><?php esc_html_e( 'توضیحات — اختیاری', 'zarbalman-core' ); ?><textarea name="reason" maxlength="1000" rows="3"></textarea></label>
		<?php $warning_field(); ?>
		<button class="button zbm-managed-primary" type="submit"><?php esc_html_e( 'ارسال وزن و عکس برای فروشنده', 'zarbalman-core' ); ?></button>
	</form>
</div>
<?php endif; ?>
<?php if ( ! $row_final && 'vendor' === $role && \Zarbalman\Core\Domain\ManagedFlow\FlowState::SELLER_REVIEW === $state && ! $flow_actions_locked && is_int( $line['buyer_tenths'] ?? null ) && ! empty( $line['buyer_evidence'] ) ) : ?>
<div class="zbm-managed-actions zbm-managed-actions-single">
	<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="zbm-managed-form" data-confirm="<?php esc_attr_e( 'وزن دریافتی خریدار و عکس‌های او را بررسی و تأیید کرده‌اید؟', 'zarbalman-core' ); ?>">
		<?php $command_fields( 'flow_accept_weight' ); ?><h4><?php esc_html_e( 'بررسی وزن خریدار', 'zarbalman-core' ); ?></h4>
		<p><?php esc_html_e( 'تأیید شما این وزن را نهایی می‌کند. اگر اختلافی وجود دارد، از فرم اعتراض استفاده کنید.', 'zarbalman-core' ); ?></p>
		<?php $warning_field(); ?><button class="button zbm-managed-primary" type="submit"><?php esc_html_e( 'وزن و عکس خریدار را تأیید می‌کنم', 'zarbalman-core' ); ?></button>
	</form>
</div>
<?php endif; ?>
<?php if ( ! $row_final && ! $flow_actions_locked && ( ( 'buyer' === $role && \Zarbalman\Core\Domain\ManagedFlow\FlowState::BUYER_REVIEW === $state ) || ( 'vendor' === $role && \Zarbalman\Core\Domain\ManagedFlow\FlowState::SELLER_REVIEW === $state ) ) ) : ?>
<details class="zbm-managed-disclosure">
	<summary><?php esc_html_e( 'اعتراض دارید؟ درخواست بررسی مدیریت', 'zarbalman-core' ); ?></summary>
	<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $action_url ); ?>" class="zbm-managed-form">
		<?php $command_fields( 'flow_object' ); ?>
		<label><?php esc_html_e( 'دلیل اعتراض — اجباری', 'zarbalman-core' ); ?><textarea name="reason" maxlength="1000" required></textarea></label>
		<?php $photos_field(); ?>
		<button class="button" type="submit"><?php esc_html_e( 'ارسال اعتراض و مدرک به مدیر', 'zarbalman-core' ); ?></button>
	</form>
</details>
<?php endif; ?>

<?php
/** @var array<string,string> $catalog */
/** @var array<string,string> $unregistered */
/** @var bool $catalog_complete */
/** @var array<string,string> $supported */
/** @var array<string,int> $stored */
/** @var array<string,mixed>|false $notice */
/** @var string $dashboard_url */
defined( 'ABSPATH' ) || exit;
?>
<div class="zbm-dashboard zbm-vendor-shipping-rates dokan-dashboard-wrap" dir="rtl">
	<header class="zbm-dashboard-head">
		<div>
			<span class="zbm-dashboard-kicker"><?php esc_html_e( 'تنظیمات ارسال', 'zarbalman-core' ); ?></span>
			<h2><?php esc_html_e( 'نرخ ارسال استانی', 'zarbalman-core' ); ?></h2>
			<p class="zbm-dashboard-subtitle"><?php esc_html_e( 'برای هر استان، نرخ مستقل به تومان برای هر کیلوگرم تعیین کنید. صفر یعنی ارسال رایگان.', 'zarbalman-core' ); ?></p>
		</div>
		<a class="dokan-btn dokan-btn-theme zbm-back-link zbm-back-link-dashboard" href="<?php echo esc_url( $dashboard_url ); ?>"><span aria-hidden="true">←</span> <?php esc_html_e( 'بازگشت به داشبورد', 'zarbalman-core' ); ?></a>
	</header>

	<?php if ( is_array( $notice ) ) : ?>
		<div class="zbm-alert zbm-alert-<?php echo esc_attr( 'success' === ( $notice['type'] ?? '' ) ? 'success' : 'danger' ); ?>" role="status"><?php echo esc_html( (string) ( $notice['message'] ?? '' ) ); ?></div>
	<?php endif; ?>

	<?php if ( ! $catalog_complete ) : ?>
		<div class="zbm-alert zbm-alert-warning" role="status"><?php esc_html_e( 'فهرست ۳۱ استان ایران کامل در دسترس نیست؛ تغییر نرخ تا بازگشت فهرست متوقف است.', 'zarbalman-core' ); ?></div>
	<?php else : ?>
		<section class="zbm-vendor-shipping-add" aria-labelledby="zbm-vendor-shipping-add-title">
			<h3 id="zbm-vendor-shipping-add-title"><?php esc_html_e( 'افزودن نرخ استان', 'zarbalman-core' ); ?></h3>
			<?php if ( empty( $unregistered ) ) : ?>
				<p role="status"><?php esc_html_e( 'نرخ هر ۳۱ استان ثبت شده است؛ برای تغییر یا حذف، از فرم همان استان در فهرست زیر استفاده کنید.', 'zarbalman-core' ); ?></p>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="zbm-vendor-shipping-form">
				<input type="hidden" name="action" value="zbm_save_vendor_shipping_rate">
				<input type="hidden" name="operation" value="add">
				<?php wp_nonce_field( 'zbm_save_vendor_shipping_rate', '_zbm_shipping_rate_nonce' ); ?>
				<label><span><?php esc_html_e( 'استان', 'zarbalman-core' ); ?></span><select name="province_code" required><?php foreach ( $unregistered as $code => $name ) : ?><option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select></label>
				<label><span><?php esc_html_e( 'نرخ', 'zarbalman-core' ); ?></span><input type="text" name="rate" inputmode="numeric" pattern="[0-9۰-۹٠-٩,٬ ]+" maxlength="12" required data-zbm-rate-input></label>
				<span class="zbm-vendor-rate-unit"><?php esc_html_e( 'تومان / کیلوگرم', 'zarbalman-core' ); ?></span>
				<output data-zbm-rate-equivalent></output>
				<button type="submit" class="dokan-btn dokan-btn-theme"><?php esc_html_e( 'افزودن', 'zarbalman-core' ); ?></button>
			</form>
			<?php endif; ?>
		</section>

		<?php if ( empty( $supported ) ) : ?>
			<div class="zbm-empty-state zbm-vendor-shipping-empty" role="status"><span class="zbm-empty-state-icon" aria-hidden="true">⌁</span><h3><?php esc_html_e( 'هنوز نرخی ثبت نشده است', 'zarbalman-core' ); ?></h3><p><?php esc_html_e( 'یک استان و نرخ آن را از فرم بالا اضافه کنید. خریدار فقط استان‌های دارای نرخ را می‌بیند.', 'zarbalman-core' ); ?></p></div>
		<?php else : ?>
			<section class="zbm-vendor-shipping-list" aria-label="<?php esc_attr_e( 'نرخ‌های ثبت‌شده', 'zarbalman-core' ); ?>">
				<?php foreach ( $supported as $code => $name ) : ?>
					<?php
					$heading_id = 'zbm-shipping-' . $code;
					$rate_label_id = $heading_id . '-rate-label';
					$update_label_id = $heading_id . '-update-label';
					$delete_label_id = $heading_id . '-delete-label';
					?>
					<article class="zbm-vendor-shipping-row">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="zbm-vendor-shipping-form">
							<input type="hidden" name="action" value="zbm_save_vendor_shipping_rate"><input type="hidden" name="operation" value="update"><input type="hidden" name="province_code" value="<?php echo esc_attr( $code ); ?>"><?php wp_nonce_field( 'zbm_save_vendor_shipping_rate', '_zbm_shipping_rate_nonce' ); ?>
							<h4 id="<?php echo esc_attr( $heading_id ); ?>"><?php echo esc_html( $name ); ?></h4>
							<label><span class="screen-reader-text" id="<?php echo esc_attr( $rate_label_id ); ?>"><?php esc_html_e( 'نرخ', 'zarbalman-core' ); ?></span><input type="text" name="rate" aria-labelledby="<?php echo esc_attr( $rate_label_id . ' ' . $heading_id ); ?>" value="<?php echo esc_attr( (string) $stored[ $code ] ); ?>" inputmode="numeric" pattern="[0-9۰-۹٠-٩,٬ ]+" maxlength="12" required data-zbm-rate-input></label>
							<span class="zbm-vendor-rate-unit"><?php esc_html_e( 'تومان / کیلوگرم', 'zarbalman-core' ); ?></span><output data-zbm-rate-equivalent></output>
							<button type="submit" class="dokan-btn dokan-btn-default" aria-labelledby="<?php echo esc_attr( $update_label_id . ' ' . $heading_id ); ?>"><span id="<?php echo esc_attr( $update_label_id ); ?>"><?php esc_html_e( 'به‌روزرسانی', 'zarbalman-core' ); ?></span></button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="zbm-vendor-shipping-delete-form">
							<input type="hidden" name="action" value="zbm_save_vendor_shipping_rate"><input type="hidden" name="operation" value="delete"><input type="hidden" name="province_code" value="<?php echo esc_attr( $code ); ?>"><?php wp_nonce_field( 'zbm_save_vendor_shipping_rate', '_zbm_shipping_rate_nonce' ); ?>
							<button type="submit" class="dokan-btn dokan-btn-danger" aria-labelledby="<?php echo esc_attr( $delete_label_id . ' ' . $heading_id ); ?>"><span id="<?php echo esc_attr( $delete_label_id ); ?>"><?php esc_html_e( 'حذف', 'zarbalman-core' ); ?></span></button>
						</form>
					</article>
				<?php endforeach; ?>
			</section>
		<?php endif; ?>
	<?php endif; ?>
</div>

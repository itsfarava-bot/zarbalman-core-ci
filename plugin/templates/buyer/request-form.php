<?php
/** @var WC_Product $product */
/** @var int $product_id */
/** @var string $min_date_iso */
/** @var string $max_date_iso */
/** @var string $min_jalali */
/** @var string $max_jalali */
/** @var string $min_jalali_ascii */
/** @var string $max_jalali_ascii */
/** @var string $action */
/** @var string $login_url */
/** @var bool $is_logged_in */
/** @var bool $auto_open */
/** @var string $product_weight */
/** @var array<string,string> $provinces */
/** @var array<string,string> $supported_provinces */
/** @var bool $province_catalog_complete */
/** @var bool $address_delivery_available */
/** @var array<string,string> $pickup_address */
/** @var bool $pickup_available */
/** @var string $pickup_address_token */
defined( 'ABSPATH' ) || exit;
$form_id        = 'zbm-request-form-' . (int) $product_id;
$modal_id       = 'zbm-request-modal-' . (int) $product_id;
$product_price  = (string) $product->get_price();
$product_name   = $product->get_name();
$pickup_selected = ! $address_delivery_available && $pickup_available;
$pickup_address_parts = array_filter(
	array(
		$pickup_address['store_name'] ?? '',
		$pickup_address['street_1'] ?? '',
		$pickup_address['zip'] ?? '',
	),
	static fn ( mixed $value ): bool => is_string( $value ) && '' !== trim( $value )
);
$pickup_address_display = implode( '، ', array_unique( array_map( 'trim', $pickup_address_parts ) ) );
?>
<section class="zbm-request-box" aria-labelledby="<?php echo esc_attr( $form_id . '-title' ); ?>">
	<div class="zbm-request-intro">
		<span class="zbm-request-kicker"><?php esc_html_e( 'خرید مطمئن و مرحله‌ای', 'zarbalman-core' ); ?></span>
		<h2 id="<?php echo esc_attr( $form_id . '-title' ); ?>"><?php esc_html_e( 'ثبت درخواست خرید', 'zarbalman-core' ); ?></h2>
		<p><?php esc_html_e( 'فروشنده ابتدا موجودی و زمان دریافت را بررسی می‌کند. پس از تأیید او، پیش‌پرداخت ۱۰٪ جمع تقریبی محصول و حمل فعال می‌شود. مبلغ نهایی بر اساس وزن تأییدشده محاسبه خواهد شد.', 'zarbalman-core' ); ?></p>
	</div>
	<button type="button" class="button alt zbm-open-request" aria-haspopup="dialog" aria-controls="<?php echo esc_attr( $modal_id ); ?>" data-zbm-login-url="<?php echo esc_attr( $login_url ); ?>" data-zbm-authenticated="<?php echo $is_logged_in ? '1' : '0'; ?>" data-zbm-auto-open="<?php echo $auto_open ? '1' : '0'; ?>">
		<?php esc_html_e( 'شروع ثبت درخواست', 'zarbalman-core' ); ?>
	</button>
	<div class="zbm-alert zbm-alert-danger zbm-request-auth-feedback" role="alert" aria-live="assertive" tabindex="-1" data-zbm-auth-feedback hidden></div>

	<div id="<?php echo esc_attr( $modal_id ); ?>" class="zbm-modal" hidden data-product-id="<?php echo esc_attr( (string) $product_id ); ?>" data-product-price="<?php echo esc_attr( $product_price ); ?>" data-package-weight="<?php echo esc_attr( $product_weight ); ?>" data-product-name="<?php echo esc_attr( $product_name ); ?>">
		<div class="zbm-modal-backdrop" data-zbm-close="1"></div>
		<section class="zbm-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $form_id . '-modal-title' ); ?>" tabindex="-1">
			<header class="zbm-modal-header">
				<div>
					<span class="zbm-request-kicker"><?php esc_html_e( 'درخواست خرید', 'zarbalman-core' ); ?></span>
					<h2 id="<?php echo esc_attr( $form_id . '-modal-title' ); ?>"><?php echo esc_html( $product_name ); ?></h2>
				</div>
				<button type="button" class="zbm-modal-close" data-zbm-close="1" aria-label="<?php esc_attr_e( 'بستن پنجره', 'zarbalman-core' ); ?>">×</button>
			</header>
			<ol class="zbm-stepper" aria-label="<?php esc_attr_e( 'مراحل ثبت درخواست', 'zarbalman-core' ); ?>">
				<li class="is-active" data-zbm-progress="1"><span>۱</span><?php esc_html_e( 'تعداد', 'zarbalman-core' ); ?></li>
				<li data-zbm-progress="2"><span>۲</span><?php esc_html_e( 'نحوه دریافت', 'zarbalman-core' ); ?></li>
				<li data-zbm-progress="3"><span>۳</span><?php esc_html_e( 'اطلاعات', 'zarbalman-core' ); ?></li>
				<li data-zbm-progress="4"><span>۴</span><?php esc_html_e( 'زمان و تأیید', 'zarbalman-core' ); ?></li>
			</ol>
			<div class="zbm-form-feedback" role="alert" aria-live="assertive" hidden></div>
			<form id="<?php echo esc_attr( $form_id ); ?>" class="zbm-request-form" method="post" action="<?php echo esc_url( $action ); ?>" novalidate>
				<input type="hidden" name="action" value="zbm_create_purchase_request">
				<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $product_id ); ?>">
				<input type="hidden" name="variation_id" class="zbm-variation-id" value="0">
				<input type="hidden" name="lines_json" value="[]">
				<input type="hidden" name="idempotency_key" value="">
				<input type="hidden" name="_zbm_nonce" value="">

				<div class="zbm-modal-body">
					<section class="zbm-step is-active" data-zbm-step="1" aria-labelledby="<?php echo esc_attr( $form_id . '-step-1' ); ?>">
						<h3 id="<?php echo esc_attr( $form_id . '-step-1' ); ?>"><?php esc_html_e( 'چه مقدار می‌خواهید سفارش دهید؟', 'zarbalman-core' ); ?></h3>
						<p class="zbm-step-help"><?php esc_html_e( 'تعداد بسته‌های موردنظر را وارد کنید. مبلغ نهایی بعد از ثبت وزن قطعی محاسبه می‌شود.', 'zarbalman-core' ); ?></p>
						<label class="zbm-field zbm-quantity-field" for="<?php echo esc_attr( $form_id . '-quantity' ); ?>">
							<span><?php esc_html_e( 'تعداد بسته', 'zarbalman-core' ); ?> <em>*</em></span>
							<input id="<?php echo esc_attr( $form_id . '-quantity' ); ?>" type="number" name="quantity" min="1" step="1" value="1" inputmode="numeric" required aria-required="true">
						</label>
						<div class="zbm-order-preview" aria-live="polite">
							<div class="zbm-request-lines" data-zbm-lines>
								<strong><?php esc_html_e( 'محصولات دیگر همین فروشنده', 'zarbalman-core' ); ?></strong>
								<p><?php esc_html_e( 'اختیاری؛ همهٔ محصولات با یک درخواست، یک هزینهٔ حمل بر اساس مجموع وزن و یک کد تحویل ثبت می‌شوند.', 'zarbalman-core' ); ?></p>
								<div data-zbm-selected-lines></div>
								<button class="button" type="button" data-zbm-open-products><?php esc_html_e( 'افزودن محصول دیگر', 'zarbalman-core' ); ?></button>
								<div data-zbm-product-picker hidden>
									<button class="button" type="button" data-zbm-back-products hidden><?php esc_html_e( 'بازگشت به محصولات', 'zarbalman-core' ); ?></button>
									<label><?php esc_html_e( 'جست‌وجوی محصول', 'zarbalman-core' ); ?><input type="search" maxlength="100" data-zbm-product-search autocomplete="off"></label>
									<button class="button" type="button" data-zbm-search-products><?php esc_html_e( 'جست‌وجو', 'zarbalman-core' ); ?></button>
									<p role="status" aria-live="polite" data-zbm-product-message></p>
									<div data-zbm-product-results></div>
									<button class="button" type="button" data-zbm-more-products hidden><?php esc_html_e( 'محصولات بیشتر', 'zarbalman-core' ); ?></button>
								</div>
							</div>
							<div><span><?php esc_html_e( 'محصول', 'zarbalman-core' ); ?></span><strong data-zbm-summary="product"><?php echo esc_html( $product_name ); ?></strong></div>
							<div><span><?php esc_html_e( 'وزن کل تقریبی', 'zarbalman-core' ); ?></span><strong data-zbm-summary="weight">—</strong></div>
								<div><span><?php esc_html_e( 'قیمت هر بسته', 'zarbalman-core' ); ?></span><strong data-zbm-summary="package-price">—</strong></div>
								<div><span><?php esc_html_e( 'مبلغ تقریبی محصول', 'zarbalman-core' ); ?></span><strong data-zbm-summary="estimated-total">—</strong></div>
								<div><span><?php esc_html_e( 'پیش‌پرداخت ۱۰٪ جمع کل', 'zarbalman-core' ); ?></span><strong data-zbm-summary="deposit">—</strong></div>
								<div><span><?php esc_html_e( 'نرخ حمل هر کیلو', 'zarbalman-core' ); ?></span><strong data-zbm-summary="shipping-rate">—</strong></div>
								<div><span><?php esc_html_e( 'هزینهٔ حمل تقریبی', 'zarbalman-core' ); ?></span><strong data-zbm-summary="shipping">—</strong></div>
								<div><span><?php esc_html_e( 'جمع تقریبی محصول و حمل', 'zarbalman-core' ); ?></span><strong data-zbm-summary="total-with-shipping">—</strong></div>
								<div><span><?php esc_html_e( 'ماندهٔ تقریبی پس از پیش‌پرداخت', 'zarbalman-core' ); ?></span><strong data-zbm-summary="balance">—</strong></div>
						</div>
					</section>

					<section class="zbm-step" data-zbm-step="2" aria-labelledby="<?php echo esc_attr( $form_id . '-step-2' ); ?>" hidden>
						<h3 id="<?php echo esc_attr( $form_id . '-step-2' ); ?>"><?php esc_html_e( 'محصول را چگونه تحویل می‌گیرید؟', 'zarbalman-core' ); ?></h3>
						<p class="zbm-step-help"><?php esc_html_e( 'یکی از دو روش را انتخاب کنید تا اطلاعات لازم نمایش داده شود.', 'zarbalman-core' ); ?></p>
						<fieldset class="zbm-choice-group">
							<legend class="screen-reader-text"><?php esc_html_e( 'روش دریافت', 'zarbalman-core' ); ?></legend>
							<label class="zbm-choice-card<?php echo $address_delivery_available ? '' : ' is-disabled'; ?>" aria-disabled="<?php echo $address_delivery_available ? 'false' : 'true'; ?>">
								<input type="radio" name="delivery_method" value="delivery_to_buyer_address"<?php checked( $address_delivery_available ); ?><?php disabled( ! $address_delivery_available ); ?> required>
								<span class="zbm-choice-icon" aria-hidden="true">⌂</span>
								<span><strong><?php esc_html_e( 'تحویل در نشانی من', 'zarbalman-core' ); ?></strong><small><?php esc_html_e( 'فروشنده در بازه انتخابی به نشانی شما ارسال می‌کند.', 'zarbalman-core' ); ?></small><small class="zbm-choice-unavailable zbm-supported-provinces--empty" data-zbm-delivery-availability<?php echo $address_delivery_available ? ' hidden' : ''; ?>><?php echo esc_html( $province_catalog_complete ? __( 'موقتاً غیرفعال؛ فروشنده برای هیچ استانی نرخ حمل ثبت نکرده است.', 'zarbalman-core' ) : __( 'موقتاً غیرفعال؛ فهرست استان‌ها کامل در دسترس نیست.', 'zarbalman-core' ) ); ?></small></span>
							</label>
							<label class="zbm-choice-card<?php echo $pickup_available ? '' : ' is-disabled'; ?>" aria-disabled="<?php echo $pickup_available ? 'false' : 'true'; ?>">
								<input type="radio" name="delivery_method" value="pickup_from_vendor"<?php checked( $pickup_selected ); ?><?php disabled( ! $pickup_available ); ?> required>
								<span class="zbm-choice-icon" aria-hidden="true">▣</span>
								<span><strong><?php esc_html_e( 'دریافت حضوری از فروشنده', 'zarbalman-core' ); ?></strong><small><?php esc_html_e( 'در زمان توافق‌شده به محل فروشنده مراجعه می‌کنید.', 'zarbalman-core' ); ?></small><?php if ( ! $pickup_available ) : ?><small class="zbm-choice-unavailable"><?php esc_html_e( 'موقتاً غیرفعال؛ نشانی کامل فروشگاه در Dokan ثبت نشده است.', 'zarbalman-core' ); ?></small><?php endif; ?></span>
							</label>
						</fieldset>
						<div class="zbm-privacy-note"><strong><?php esc_html_e( 'حریم خصوصی شما حفظ می‌شود', 'zarbalman-core' ); ?></strong><span><?php esc_html_e( 'شماره موبایل نزد سایت می‌ماند و برای پیامک استفاده می‌شود؛ به فروشنده نمایش داده نمی‌شود.', 'zarbalman-core' ); ?></span></div>
					</section>

					<section class="zbm-step" data-zbm-step="3" aria-labelledby="<?php echo esc_attr( $form_id . '-step-3' ); ?>" hidden>
						<h3 id="<?php echo esc_attr( $form_id . '-step-3' ); ?>"><?php esc_html_e( 'اطلاعات دریافت را وارد کنید', 'zarbalman-core' ); ?></h3>
						<p class="zbm-step-help" data-zbm-delivery-help><?php esc_html_e( 'این اطلاعات فقط برای هماهنگی همین درخواست استفاده می‌شود.', 'zarbalman-core' ); ?></p>
						<label class="zbm-field" for="<?php echo esc_attr( $form_id . '-recipient' ); ?>">
							<span><?php esc_html_e( 'نام و نام خانوادگی دریافت‌کننده', 'zarbalman-core' ); ?> <em>*</em></span>
							<input id="<?php echo esc_attr( $form_id . '-recipient' ); ?>" type="text" name="recipient_name" maxlength="120" autocomplete="name" required aria-required="true">
						</label>
						<div class="zbm-address-fields"<?php echo $address_delivery_available ? '' : ' hidden'; ?>>
							<label class="zbm-field" for="<?php echo esc_attr( $form_id . '-province' ); ?>">
								<span><?php esc_html_e( 'استان مقصد', 'zarbalman-core' ); ?> <em>*</em></span>
								<select id="<?php echo esc_attr( $form_id . '-province' ); ?>" name="province_code" autocomplete="address-level1"<?php disabled( ! $address_delivery_available ); ?> required aria-required="<?php echo $address_delivery_available ? 'true' : 'false'; ?>" aria-describedby="<?php echo esc_attr( $form_id . '-province-status' ); ?>">
									<option value=""><?php esc_html_e( 'استان را انتخاب کنید', 'zarbalman-core' ); ?></option>
									<?php foreach ( $supported_provinces as $province_code => $province_name ) : ?>
										<option value="<?php echo esc_attr( $province_code ); ?>"><?php echo esc_html( $province_name ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<div class="zbm-supported-provinces" data-zbm-supported-provinces>
								<strong><?php esc_html_e( 'این فروشنده به استان‌های زیر ارسال دارد؛ استان مقصدتان را از فهرست انتخاب کنید.', 'zarbalman-core' ); ?></strong>
								<ul>
									<?php foreach ( $supported_provinces as $province_name ) : ?>
										<li><?php echo esc_html( $province_name ); ?></li>
									<?php endforeach; ?>
								</ul>
							</div>
							<div id="<?php echo esc_attr( $form_id . '-province-status' ); ?>" class="zbm-province-support" role="status" aria-live="polite" data-state="idle" data-zbm-province-support>
								<span class="zbm-province-support-icon" aria-hidden="true">i</span>
								<span data-zbm-province-support-message><?php esc_html_e( 'برای بررسی امکان ارسال و برآورد هزینه، استان مقصد را انتخاب کنید.', 'zarbalman-core' ); ?></span>
							</div>
							<label class="zbm-field" for="<?php echo esc_attr( $form_id . '-postal' ); ?>">
								<span><?php esc_html_e( 'کدپستی ۱۰ رقمی', 'zarbalman-core' ); ?> <em>*</em></span>
								<input id="<?php echo esc_attr( $form_id . '-postal' ); ?>" type="text" name="postal_code" inputmode="numeric" pattern="[0-9۰-۹٠-٩]{10}" maxlength="10" autocomplete="postal-code"<?php disabled( ! $address_delivery_available ); ?> required aria-required="<?php echo $address_delivery_available ? 'true' : 'false'; ?>">
							</label>
							<label class="zbm-field" for="<?php echo esc_attr( $form_id . '-address' ); ?>">
								<span><?php esc_html_e( 'نشانی کامل تحویل', 'zarbalman-core' ); ?> <em>*</em></span>
								<textarea id="<?php echo esc_attr( $form_id . '-address' ); ?>" name="address" rows="3" maxlength="1000" autocomplete="street-address"<?php disabled( ! $address_delivery_available ); ?> required aria-required="<?php echo $address_delivery_available ? 'true' : 'false'; ?>"></textarea>
							</label>
						</div>
						<div class="zbm-pickup-fields"<?php echo $pickup_selected ? '' : ' hidden'; ?>>
							<input type="hidden" name="pickup_address_token" value="<?php echo esc_attr( $pickup_address_token ); ?>"<?php disabled( ! $pickup_selected ); ?>>
							<div class="zbm-pickup-address" data-zbm-pickup-address data-zbm-address-text="<?php echo esc_attr( $pickup_address_display ); ?>">
								<strong><?php esc_html_e( 'نشانی دریافت حضوری', 'zarbalman-core' ); ?></strong>
								<dl>
					<?php foreach ( array( 'store_name' => __( 'فروشگاه', 'zarbalman-core' ), 'street_1' => __( 'نشانی', 'zarbalman-core' ), 'zip' => __( 'کدپستی فروشگاه', 'zarbalman-core' ) ) as $pickup_field => $pickup_label ) : ?>
										<?php if ( isset( $pickup_address[ $pickup_field ] ) && '' !== trim( (string) $pickup_address[ $pickup_field ] ) ) : ?><dt><?php echo esc_html( $pickup_label ); ?></dt><dd><?php echo esc_html( (string) $pickup_address[ $pickup_field ] ); ?></dd><?php endif; ?>
									<?php endforeach; ?>
								</dl>
								<small><?php esc_html_e( 'این نشانی از پروفایل تأییدشدهٔ فروشنده خوانده می‌شود و قابل ویرایش توسط خریدار نیست.', 'zarbalman-core' ); ?></small>
							</div>
							<label class="zbm-field" for="<?php echo esc_attr( $form_id . '-pickup-note' ); ?>">
								<span><?php esc_html_e( 'توضیحات مراجعه (اختیاری)', 'zarbalman-core' ); ?></span>
								<textarea id="<?php echo esc_attr( $form_id . '-pickup-note' ); ?>" name="pickup_note" rows="2" maxlength="500"<?php disabled( ! $pickup_selected ); ?> placeholder="<?php esc_attr_e( 'مثلاً نوع خودرو یا توضیح محل مراجعه؛ اطلاعات تماس وارد نکنید.', 'zarbalman-core' ); ?>"></textarea>
							</label>
							<div class="zbm-pickup-warning" role="note">
								<strong><?php esc_html_e( 'پیش از ادامه دقت کنید', 'zarbalman-core' ); ?></strong>
								<p><?php esc_html_e( 'در روش دریافت حضوری، شما باید در تاریخ و بازهٔ ساعت انتخابی به همین نشانی فروشنده مراجعه کنید.', 'zarbalman-core' ); ?></p>
								<label class="zbm-pickup-confirmation">
									<input type="checkbox" name="pickup_visit_confirmed" value="1"<?php disabled( ! $pickup_selected ); ?><?php echo $pickup_selected ? ' required aria-required="true"' : ' aria-required="false"'; ?>>
									<span><?php esc_html_e( 'نشانی را بررسی کردم و توانایی مراجعه در بازهٔ انتخابی را دارم.', 'zarbalman-core' ); ?></span>
								</label>
							</div>
						</div>
					</section>

					<section class="zbm-step" data-zbm-step="4" aria-labelledby="<?php echo esc_attr( $form_id . '-step-4' ); ?>" hidden>
						<h3 id="<?php echo esc_attr( $form_id . '-step-4' ); ?>"><?php esc_html_e( 'زمان دریافت و مرور درخواست', 'zarbalman-core' ); ?></h3>
						<p class="zbm-step-help"><?php esc_html_e( 'فروشنده این بازه را هنگام بررسی درخواست می‌بیند و پس از تأیید، لینک پرداخت برای شما ارسال می‌شود.', 'zarbalman-core' ); ?></p>
						<fieldset class="zbm-window-choice">
							<legend><?php esc_html_e( 'نوع بازه زمانی', 'zarbalman-core' ); ?></legend>
							<label><input type="radio" name="delivery_window_type" value="exact" checked required> <?php esc_html_e( 'تاریخ دقیق و بازه ساعت', 'zarbalman-core' ); ?></label>
							<label><input type="radio" name="delivery_window_type" value="flexible"> <?php esc_html_e( 'بازه انعطاف‌پذیر چندروزه', 'zarbalman-core' ); ?></label>
						</fieldset>
						<div class="zbm-exact-window zbm-grid-3">
							<label class="zbm-field" for="<?php echo esc_attr( $form_id . '-exact-date-display' ); ?>"><span><?php esc_html_e( 'تاریخ شمسی', 'zarbalman-core' ); ?> <em>*</em></span><span class="zbm-jalali-date-wrap"><input id="<?php echo esc_attr( $form_id . '-exact-date-display' ); ?>" class="zbm-jalali-date" type="text" name="exact_date_display" data-jalali-min="<?php echo esc_attr( $min_jalali_ascii ); ?>" data-jalali-max="<?php echo esc_attr( $max_jalali_ascii ); ?>" data-iso-min="<?php echo esc_attr( $min_date_iso ); ?>" data-iso-max="<?php echo esc_attr( $max_date_iso ); ?>" placeholder="<?php echo esc_attr( $min_jalali ); ?>" inputmode="numeric" maxlength="10" pattern="[0-9۰-۹٠-٩]{4}/[0-9۰-۹٠-٩]{2}/[0-9۰-۹٠-٩]{2}" autocomplete="off" required aria-required="true" aria-label="<?php esc_attr_e( 'تاریخ شمسی', 'zarbalman-core' ); ?>"><button type="button" class="zbm-jalali-trigger" aria-label="<?php esc_attr_e( 'باز کردن تقویم شمسی', 'zarbalman-core' ); ?>" aria-haspopup="dialog">▦</button><input type="hidden" name="exact_date" value=""></span></label>
							<label class="zbm-field" for="<?php echo esc_attr( $form_id . '-start-time' ); ?>"><span><?php esc_html_e( 'از ساعت (۲۴ساعته)', 'zarbalman-core' ); ?> <em>*</em></span><input id="<?php echo esc_attr( $form_id . '-start-time' ); ?>" class="zbm-iranian-time" type="text" name="start_time" inputmode="numeric" maxlength="5" pattern="[0-9۰-۹٠-٩]{2}:[0-9۰-۹٠-٩]{2}" placeholder="۱۴:۳۰" autocomplete="off" dir="ltr" required aria-required="true" aria-label="<?php esc_attr_e( 'ساعت شروع به‌صورت ۲۴ساعته', 'zarbalman-core' ); ?>"></label>
							<label class="zbm-field" for="<?php echo esc_attr( $form_id . '-end-time' ); ?>"><span><?php esc_html_e( 'تا ساعت (۲۴ساعته)', 'zarbalman-core' ); ?> <em>*</em></span><input id="<?php echo esc_attr( $form_id . '-end-time' ); ?>" class="zbm-iranian-time" type="text" name="end_time" inputmode="numeric" maxlength="5" pattern="[0-9۰-۹٠-٩]{2}:[0-9۰-۹٠-٩]{2}" placeholder="۱۸:۰۰" autocomplete="off" dir="ltr" required aria-required="true" aria-label="<?php esc_attr_e( 'ساعت پایان به‌صورت ۲۴ساعته', 'zarbalman-core' ); ?>"></label>
						</div>
						<div class="zbm-flex-window zbm-grid-2" hidden>
							<label class="zbm-field" for="<?php echo esc_attr( $form_id . '-flex-start-display' ); ?>"><span><?php esc_html_e( 'از تاریخ شمسی', 'zarbalman-core' ); ?> <em>*</em></span><span class="zbm-jalali-date-wrap"><input id="<?php echo esc_attr( $form_id . '-flex-start-display' ); ?>" class="zbm-jalali-date" type="text" name="flex_start_date_display" data-jalali-min="<?php echo esc_attr( $min_jalali_ascii ); ?>" data-jalali-max="<?php echo esc_attr( $max_jalali_ascii ); ?>" data-iso-min="<?php echo esc_attr( $min_date_iso ); ?>" data-iso-max="<?php echo esc_attr( $max_date_iso ); ?>" placeholder="<?php echo esc_attr( $min_jalali ); ?>" inputmode="numeric" maxlength="10" pattern="[0-9۰-۹٠-٩]{4}/[0-9۰-۹٠-٩]{2}/[0-9۰-۹٠-٩]{2}" autocomplete="off" required aria-required="true" aria-label="<?php esc_attr_e( 'تاریخ شروع شمسی', 'zarbalman-core' ); ?>"><button type="button" class="zbm-jalali-trigger" aria-label="<?php esc_attr_e( 'باز کردن تقویم شمسی', 'zarbalman-core' ); ?>" aria-haspopup="dialog">▦</button><input type="hidden" name="flex_start_date" value=""></span></label>
							<label class="zbm-field" for="<?php echo esc_attr( $form_id . '-flex-end-display' ); ?>"><span><?php esc_html_e( 'تا تاریخ شمسی', 'zarbalman-core' ); ?> <em>*</em></span><span class="zbm-jalali-date-wrap"><input id="<?php echo esc_attr( $form_id . '-flex-end-display' ); ?>" class="zbm-jalali-date" type="text" name="flex_end_date_display" data-jalali-min="<?php echo esc_attr( $min_jalali_ascii ); ?>" data-jalali-max="<?php echo esc_attr( $max_jalali_ascii ); ?>" data-iso-min="<?php echo esc_attr( $min_date_iso ); ?>" data-iso-max="<?php echo esc_attr( $max_date_iso ); ?>" placeholder="<?php echo esc_attr( $min_jalali ); ?>" inputmode="numeric" maxlength="10" pattern="[0-9۰-۹٠-٩]{4}/[0-9۰-۹٠-٩]{2}/[0-9۰-۹٠-٩]{2}" autocomplete="off" required aria-required="true" aria-label="<?php esc_attr_e( 'تاریخ پایان شمسی', 'zarbalman-core' ); ?>"><button type="button" class="zbm-jalali-trigger" aria-label="<?php esc_attr_e( 'باز کردن تقویم شمسی', 'zarbalman-core' ); ?>" aria-haspopup="dialog">▦</button><input type="hidden" name="flex_end_date" value=""></span></label>
						</div>
						<div class="zbm-final-review" aria-live="polite">
							<strong><?php esc_html_e( 'خلاصه درخواست', 'zarbalman-core' ); ?></strong>
							<div class="zbm-review-items" data-zbm-review-items></div>
							<p><span><?php esc_html_e( 'تعداد و وزن:', 'zarbalman-core' ); ?></span> <b data-zbm-summary="final-quantity">۱</b> <span data-zbm-summary="final-weight">—</span></p>
							<p><span><?php esc_html_e( 'قیمت هر بسته:', 'zarbalman-core' ); ?></span> <b data-zbm-summary="package-price">—</b></p>
								<p><span><?php esc_html_e( 'مبلغ تقریبی محصول:', 'zarbalman-core' ); ?></span> <b data-zbm-summary="estimated-total">—</b></p>
								<p><span><?php esc_html_e( 'نرخ حمل هر کیلو:', 'zarbalman-core' ); ?></span> <b data-zbm-summary="shipping-rate">—</b></p>
								<p><span><?php esc_html_e( 'هزینهٔ حمل تقریبی:', 'zarbalman-core' ); ?></span> <b data-zbm-summary="shipping">—</b></p>
							<p><span><?php esc_html_e( 'جمع تقریبی محصول و حمل:', 'zarbalman-core' ); ?></span> <b data-zbm-summary="total-with-shipping">—</b></p>
							<p><span><?php esc_html_e( 'پیش‌پرداخت ۱۰٪ جمع کل:', 'zarbalman-core' ); ?></span> <b data-zbm-summary="deposit">—</b></p>
							<p><span><?php esc_html_e( 'ماندهٔ تقریبی پس از پیش‌پرداخت:', 'zarbalman-core' ); ?></span> <b data-zbm-summary="balance">—</b></p>
							<p><span><?php esc_html_e( 'روش دریافت:', 'zarbalman-core' ); ?></span> <b data-zbm-summary="method">—</b></p>
							<p><span><?php esc_html_e( 'نشانی دریافت حضوری:', 'zarbalman-core' ); ?></span> <b data-zbm-summary="pickup-address">—</b></p>
							<p><span><?php esc_html_e( 'توضیحات مراجعه:', 'zarbalman-core' ); ?></span> <b data-zbm-summary="pickup-note">—</b></p>
							<p><span><?php esc_html_e( 'زمان انتخابی:', 'zarbalman-core' ); ?></span> <b data-zbm-summary="window">—</b></p>
						</div>
					</section>
				</div>
				<footer class="zbm-modal-footer">
					<button type="button" class="button zbm-modal-back" data-zbm-back hidden><?php esc_html_e( 'مرحله قبل', 'zarbalman-core' ); ?></button>
					<span class="zbm-modal-footer-spacer"></span>
					<button type="button" class="button alt zbm-modal-next" data-zbm-next data-next-label="<?php esc_attr_e( 'ادامه', 'zarbalman-core' ); ?>" data-final-label="<?php esc_attr_e( 'ادامه و ارسال درخواست', 'zarbalman-core' ); ?>"><?php esc_html_e( 'ادامه', 'zarbalman-core' ); ?></button>
				</footer>
			</form>
		</section>
	</div>
</section>

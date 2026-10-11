<?php

declare(strict_types=1);

namespace Zarbalman\Core\Notification;

use InvalidArgumentException;

final class MessageCatalog {
	/** @return array<string,string> */
	public function defaults(): array {
		return array(
			'spec4_balance_ready' => 'زربال‌من: وزن نهایی درخواست {request} ثبت شد. مانده {amount} تومان تا {deadline} از پنل پرداخت شود. {link}',
			'spec4_contact_due' => 'زربال‌من مدیریت: مهلت پرداخت درخواست {request} پایان یافت. تماس و هماهنگی را در پرونده ثبت کنید. {link}',
			'spec4_warning' => 'زربال‌من: پس از هماهنگی پشتیبانی، مهلت تکمیلی درخواست {request} آغاز شد. وضعیت را در پنل پیگیری کنید. {link}',
			'spec4_payment_review' => 'زربال‌من مدیریت: نتیجهٔ پرداخت مانده درخواست {request} نیازمند بررسی مالی است. {link}',
			'spec4_payment_completed' => 'زربال‌من: پرداخت سفارش {request} تکمیل شد. نتیجه در پنل شما ثبت شده است. {link}',
			'spec4_refund_completed' => 'زربال‌من: بازپرداخت درخواست {request} توسط مدیریت ثبت و با گردش حساب تطبیق داده شد. {link}',
			'spec4_return_confirmed' => 'زربال‌من: بازگشت بار درخواست {request} توسط مدیریت تأیید شد. نتیجه در پنل ثبت شده است. {link}',
			'spec4_allocation_review' => 'زربال‌من مدیریت: ثبت اعتبار فروشنده درخواست {request} نیازمند بررسی و بازیابی است. {link}',
			'request_created_buyer'                => 'زربال‌من: درخواست {request} ثبت شد. فروشنده تا {deadline} پاسخ می‌دهد. {link}',
			'request_created_vendor'               => 'زربال‌من: درخواست جدید {request} برای {product} ثبت شد. تا {deadline} پاسخ دهید. {link}',
			'supplier_response_reminder_vendor'    => 'زربال‌من: کمتر از ۱۰ دقیقه تا پایان مهلت بررسی درخواست {request} باقی است. تا {deadline} تأیید یا رد کنید. {link}',
			'request_accepted_vendor'              => 'زربال‌من: پذیرش درخواست {request} ثبت شد و لینک پیش‌پرداخت برای خریدار آماده است.',
			'request_accepted_payment_waiting'      => 'زربال‌من: درخواست {request} توسط فروشنده پذیرفته شد. زیرساخت پرداخت موقتاً آماده نیست و هنوز هیچ وجهی دریافت نشده است. {link}',
			'request_accepted_payment_waiting_vendor' => 'زربال‌من: پذیرش درخواست {request} ثبت شد. زیرساخت پرداخت موقتاً آماده نیست و هنوز وجهی از خریدار دریافت نشده است.',
			'request_rejected'                     => 'زربال‌من: درخواست {request} رد شد. دلیل: {reason}. {link}',
			'request_rejected_vendor'              => 'زربال‌من: رد درخواست {request} با موفقیت ثبت شد.',
			'supplier_response_expired'            => 'زربال‌من: مهلت پاسخ فروشنده برای درخواست {request} پایان یافت. مبلغی پرداخت نشده است.',
			'supplier_response_expired_vendor'     => 'زربال‌من: مهلت پاسخ شما برای درخواست {request} پایان یافت.',
			'deposit_link_created'                 => 'زربال‌من: درخواست {request} پذیرفته شد. مبلغ پیش‌پرداخت {amount} تومان تا {deadline}: {link}',
			'deposit_paid_buyer'                   => 'زربال‌من: پیش‌پرداخت {amount} تومان برای درخواست {request} با موفقیت ثبت شد. {link}',
			'deposit_paid_vendor'                  => 'زربال‌من: پیش‌پرداخت درخواست {request} ثبت شد. برای ادامه آماده‌سازی منتظر پیام بعدی بمانید.',
			'deposit_expired'                      => 'زربال‌من: مهلت پیش‌پرداخت درخواست {request} پایان یافت و رزرو موجودی آزاد شد.',
			'deposit_expired_vendor'               => 'زربال‌من: خریدار پیش‌پرداخت درخواست {request} را در مهلت انجام نداد؛ رزرو آزاد شد.',
			'request_paused'                       => 'زربال‌من: زمان درخواست {request} با دلیل ثبت‌شده توسط مدیریت متوقف شد. {link}',
			'request_paused_vendor'                => 'زربال‌من: زمان درخواست {request} توسط مدیریت متوقف شد. {link}',
			'request_resumed'                      => 'زربال‌من: درخواست {request} توسط مدیریت باز شد. {link}',
			'request_resumed_vendor'               => 'زربال‌من: درخواست {request} توسط مدیریت باز شد. {link}',
			'supplier_deadline_reopened'           => 'زربال‌من: مهلت فروشنده برای درخواست {request} تا {deadline} باز شد.',
			'supplier_deadline_reopened_vendor'    => 'زربال‌من: مهلت پاسخ درخواست {request} تا {deadline} باز شد. {link}',
			'deposit_deadline_reopened'            => 'زربال‌من: مهلت پیش‌پرداخت {amount} تومان درخواست {request} تا {deadline} باز شد. {link}',
			'deposit_deadline_reopened_vendor'     => 'زربال‌من: مهلت پیش‌پرداخت درخواست {request} تا {deadline} باز شد.',
			'late_payment_review'                  => 'زربال‌من مدیریت: پرداخت دیرهنگام {amount} تومان برای درخواست {request} نیازمند بررسی است. {link}',
			'late_payment_review_buyer'            => 'زربال‌من: پرداخت {amount} تومان درخواست {request} به‌دلیل زمان یا وضعیت فنی در بررسی مدیریت است. {link}',
			'late_payment_review_vendor'           => 'زربال‌من: پرداخت درخواست {request} در بررسی مدیریت است؛ تا اعلام نتیجه اقدامی انجام ندهید. {link}',
			'late_payment_accepted_buyer'          => 'زربال‌من: پرداخت دیرهنگام {amount} تومان درخواست {request} پس از کنترل موجودی توسط مدیریت پذیرفته شد. {link}',
			'late_payment_accepted_vendor'         => 'زربال‌من: پرداخت درخواست {request} پس از بررسی مدیریت پذیرفته شد. {link}',
			'managed_order_creation_failed'        => 'زربال‌من مدیریت: ساخت سفارش درخواست {request} ناموفق و پرونده متوقف شد. {link}',
			'payment_enable_failed'                => 'زربال‌من مدیریت: فعال‌سازی پرداخت درخواست {request} ناموفق است. {link}',
			'payment_disable_failed'               => 'زربال‌من مدیریت: غیرفعال‌سازی پرداخت درخواست {request} نیازمند بررسی است. {link}',
			'payment_identity_mismatch'            => 'زربال‌من مدیریت: شناسه پرداخت درخواست {request} ناسازگار است. {link}',
			'payment_gateway_mismatch'             => 'زربال‌من مدیریت: درگاه ثبت‌شده برای پرداخت درخواست {request} ناسازگار است. {link}',
			'payment_snapshot_mismatch'            => 'زربال‌من مدیریت: مبلغ یا اقلام سفارش پرداخت درخواست {request} با داده قفل‌شده ناسازگار است. {link}',
			'untrusted_payment_completion'          => 'زربال‌من مدیریت: تلاش تأیید پرداخت درخواست {request} خارج از callback معتبر متوقف شد. {link}',
			'payment_recording_failed'             => 'زربال‌من مدیریت: ثبت داخلی پرداخت درخواست {request} ناموفق است. {link}',
			'stock_release_failed'                 => 'زربال‌من مدیریت: آزادسازی موجودی درخواست {request} ناموفق است. {link}',
			'admin_review_cancelled_buyer'         => 'زربال‌من: پرونده پرداخت‌نشده درخواست {request} پس از بررسی مدیریت لغو شد. {link}',
			'admin_review_cancelled_vendor'        => 'زربال‌من: پرونده پرداخت‌نشده درخواست {request} پس از بررسی مدیریت لغو شد. {link}',
			'request_admin_review_buyer'           => 'زربال‌من: درخواست {request} برای جلوگیری از خطا موقتاً در بررسی مدیریت متوقف شد. {link}',
			'request_admin_review_vendor'          => 'زربال‌من: درخواست {request} موقتاً در بررسی مدیریت است؛ تا اعلام نتیجه اقدامی انجام ندهید. {link}',
			'manual_payment_offered'               => 'زربال‌من: دستور پرداخت دستی واقعی درخواست {request} به مبلغ {amount} تومان آماده است. اطلاعات کارت و بارگذاری رسید فقط در پنل امن شماست. {link}',
			'manual_receipt_review'                => 'زربال‌من مدیریت: رسید پرداخت دستی درخواست {request} به مبلغ {amount} تومان منتظر تطبیق با حساب بانکی است. {link}',
			'manual_payment_approved_buyer'        => 'زربال‌من: دریافت دستی مبلغ {amount} تومان برای درخواست {request} پس از کنترل مدیریت تأیید شد. {link}',
			'manual_payment_approved_vendor'       => 'زربال‌من: پرداخت دستی درخواست {request} توسط مدیریت تأیید شد؛ وضعیت بعدی را در پنل ببینید. {link}',
			'manual_receipt_rejected'              => 'زربال‌من: رسید پرداخت دستی درخواست {request} تأیید نشد. دلیل و امکان ارسال رسید جایگزین در پنل امن نمایش داده می‌شود. {link}',
			'manual_seller_credited'               => 'زربال‌من: خالص مبلغ {amount} تومان درخواست {request} با تأیید مستقل مدیریت به اعتبار Dokan شما افزوده شد. {link}',
			'managed_deposit_received_buyer'       => 'زربال‌من: پیش‌پرداخت درخواست {request} ثبت شد. سفارش برای بازه توافق‌شده آماده می‌شود. پیگیری: {link}',
			'managed_preparation_started'          => 'زربال‌من: درخواست {request} آماده انجام است. وزن و عکس کالای آماده‌شده را ثبت کنید. {link}',
			'managed_preparation_reopened'         => 'زربال‌من: ثبت وزن و عکس درخواست {request} با تصمیم مدیریت بازگشایی شد. مدارک قبلی محفوظ است. {link}',
			'managed_pickup_available'             => 'زربال‌من: درخواست {request} برای دریافت حضوری آماده است. نشانی، بازه مراجعه و کد امن را فقط در پنل خود ببینید. {link}',
			'managed_dispatch_available'           => 'زربال‌من: درخواست {request} ارسال شده و کد امن در پنل شما در دسترس است. کد را فقط هنگام دریافت کالا اعلام کنید. {link}',
			'managed_received_weight_due'          => 'زربال‌من: تحویل درخواست {request} ثبت شد. تا {deadline} وزن دریافتی و عکس ترازو را ثبت کنید. {link}',
			'managed_received_weight_review'       => 'زربال‌من: وزن و عکس خریدار برای درخواست {request} ثبت شد. تا {deadline} تأیید یا اعتراض مستند خود را ارسال کنید. {link}',
			'managed_weight_final_buyer'           => 'زربال‌من: وزن نهایی درخواست {request} ثبت شد. محاسبه و مدارک در پنل شما قابل مشاهده است. {link}',
			'managed_weight_final_vendor'          => 'زربال‌من: وزن نهایی درخواست {request} ثبت شد. مدارک و نتیجه در پنل شما محفوظ است. {link}',
			'managed_delivery_reminder'            => 'زربال‌من: بازه تحویل درخواست {request} از {deadline} آغاز می‌شود. جزئیات هماهنگی را در پنل ببینید. {link}',
			'managed_delivery_overdue'             => 'زربال‌من: بازه تحویل درخواست {request} پایان یافته و تحویل هنوز ثبت نشده است. پرونده برای هماهنگی به مدیریت ارجاع شد؛ وجهی جابه‌جا نشده است. {link}',
			'managed_delivery_extended'            => 'زربال‌من: فرصت تحویل درخواست {request} با هماهنگی مدیریت تا {deadline} تمدید شد. بازه جدید در پنل ثبت شده است. {link}',
			'managed_ready_buyer'                  => 'زربال‌من: سفارش درخواست {request} برای دریافت آماده شده است. جزئیات امن تحویل را در پنل ببینید. {link}',
			'managed_delivery_code'                => 'زربال‌من: کد تحویل درخواست {request}: {code}. کد را فقط هنگام دریافت صحیح کالا به فروشنده اعلام کنید. {link}',
			'managed_dispatched_buyer'             => 'زربال‌من: سفارش درخواست {request} ارسال شد. کد تحویل و وضعیت را در پنل امن دنبال کنید. {link}',
			'managed_buyer_review'                 => 'زربال‌من: تحویل درخواست {request} ثبت شد. تا {deadline} وزن را تأیید یا اختلاف را همراه مدرک ثبت کنید. {link}',
			'managed_vendor_review'                => 'زربال‌من: خریدار برای درخواست {request} وزن متفاوتی ثبت کرده است. تا {deadline} پاسخ دهید. {link}',
			'managed_code_locked'                  => 'زربال‌من: ورود کد تحویل درخواست {request} پس از پنج تلاش ناموفق متوقف و برای بررسی مدیریت ارسال شد. {link}',
			'managed_objection_review'             => 'زربال‌من: اعتراض درخواست {request} با مدرک ثبت شد و اکنون در بررسی مدیریت است. {link}',
			'managed_silence_review'               => 'زربال‌من: مهلت پاسخ درخواست {request} بدون تأیید خودکار پایان یافت و پرونده به مدیریت ارجاع شد. {link}',
			'managed_paused'                       => 'زربال‌من: زمان پرونده درخواست {request} با دلیل ثبت‌شده توسط مدیریت متوقف شد. {link}',
			'managed_resumed'                      => 'زربال‌من: پرونده درخواست {request} توسط مدیریت ادامه یافت. {link}',
			'managed_deadline_extended'            => 'زربال‌من: مهلت پرونده درخواست {request} تا {deadline} با دلیل مدیریتی تمدید شد. {link}',
			'managed_review_reopened'              => 'زربال‌من: مرحله بررسی درخواست {request} تا {deadline} با دلیل مدیریتی بازگشایی شد. {link}',
			'managed_final_weight_locked'          => 'زربال‌من: وزن و مبلغ نهایی درخواست {request} روی {amount} تومان قفل شد. وضعیت مانده را در پنل ببینید. {link}',
			'managed_return_review'                => 'زربال‌من: درخواست {request} برای بررسی لغو/برگشت به مدیریت ارجاع شد؛ هیچ جابه‌جایی خودکار وجه انجام نمی‌شود. {link}',
		);
	}

	/** @param array<string,int|string> $variables */
	public function render( string $template_key, array $variables ): string {
		$defaults = $this->defaults();
		$options = get_option( 'zbm_core_sms_templates', array() );
		$template = is_array( $options ) && isset( $options[ $template_key ] ) && is_string( $options[ $template_key ] )
			? trim( $options[ $template_key ] )
			: ( $defaults[ $template_key ] ?? '' );
		if ( '' === $template ) {
			throw new InvalidArgumentException( 'SMS template is unavailable.' );
		}
		$replacements = array();
		foreach ( $variables as $key => $value ) {
			$replacements[ '{' . sanitize_key( $key ) . '}' ] = sanitize_text_field( (string) $value );
		}
		$message = strtr( $template, $replacements );
		$message = preg_replace( '/\{[a-z0-9_-]+\}/', '', $message ) ?: '';
		return trim( $message );
	}
}

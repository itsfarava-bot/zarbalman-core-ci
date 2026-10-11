<?php
/** @var array{start:?DateTimeImmutable,end_exclusive:?DateTimeImmutable,from_value:string,to_value:string,error:?string} $filter */
/** @var list<array{date:string,deposit_toman:int,balance_toman:int,request_count:int}>|null $daily */
/** @var bool $report_error */
/** @var bool $currency_supported */
/** @var string $currency_code */
/** @var string $dashboard_url */
/** @var string $reports_url */
/** @var string $clear_url */
defined( 'ABSPATH' ) || exit;

$chart_rows = array();
$deposit_total = 0;
$balance_total = 0;
$requests_total = 0;
if ( is_array( $daily ) ) {
	foreach ( $daily as $day ) {
		$deposit = max( 0, (int) $day['deposit_toman'] );
		$balance = max( 0, (int) $day['balance_toman'] );
		$requests = max( 0, (int) ( $day['request_count'] ?? 0 ) );
		$deposit_total += $deposit;
		$balance_total += $balance;
		$requests_total += $requests;
		$chart_rows[] = array(
			'date'     => (string) $day['date'],
			'deposit'  => $deposit,
			'balance'  => $balance,
			'requests' => $requests,
		);
	}
}
?>
<div class="zbm-dashboard zbm-vendor-reports dokan-dashboard-wrap" dir="rtl">
	<header class="zbm-reports-head">
		<div class="zbm-reports-heading">
			<span class="zbm-reports-kicker"><?php esc_html_e( 'گزارش سادهٔ فروش', 'zarbalman-core' ); ?></span>
			<h2><?php esc_html_e( 'گزارش فروش', 'zarbalman-core' ); ?></h2>
			<p><?php esc_html_e( 'تعداد درخواست‌ها و پرداخت‌های خریداران را در بازهٔ انتخابی، خلاصه و خوانا ببینید.', 'zarbalman-core' ); ?></p>
		</div>
		<a class="dokan-btn dokan-btn-theme zbm-reports-back" href="<?php echo esc_url( $dashboard_url ); ?>"><span aria-hidden="true">←</span> <?php esc_html_e( 'بازگشت به داشبورد', 'zarbalman-core' ); ?></a>
	</header>

	<section class="zbm-reports-filter" aria-labelledby="zbm-reports-filter-title">
		<h3 id="zbm-reports-filter-title"><?php esc_html_e( 'انتخاب بازهٔ زمانی', 'zarbalman-core' ); ?></h3>
		<p class="zbm-reports-help"><?php esc_html_e( 'تاریخ شروع و پایان را فقط با عدد وارد کنید یا با کلیک روی تقویم شمسی انتخاب کنید. تاریخ پایان باید بعد از تاریخ شروع باشد.', 'zarbalman-core' ); ?></p>
		<?php if ( null !== $filter['error'] ) : ?>
			<div class="zbm-reports-alert" role="alert"><?php echo esc_html( $filter['error'] ); ?></div>
		<?php endif; ?>
		<form class="zbm-reports-filter-form" method="get" action="<?php echo esc_url( $reports_url ); ?>">
			<label for="zbm-reports-from"><span><?php esc_html_e( 'از تاریخ', 'zarbalman-core' ); ?></span>
				<span class="zbm-vendor-report-jalali-date-wrap">
					<input id="zbm-reports-from" class="zbm-vendor-report-jalali-date" type="text" name="from" value="<?php echo esc_attr( $filter['from_value'] ); ?>" placeholder="سال/ماه/روز" inputmode="numeric" autocomplete="off" maxlength="10" pattern="[0-9۰-۹٠-٩]{4}/[0-9۰-۹٠-٩]{2}/[0-9۰-۹٠-٩]{2}" data-range-role="start" aria-controls="zbm-reports-from-picker" aria-expanded="false" required>
					<button type="button" class="zbm-vendor-report-jalali-trigger" aria-label="<?php esc_attr_e( 'باز کردن تقویم تاریخ شروع', 'zarbalman-core' ); ?>" aria-haspopup="dialog" aria-expanded="false">▦</button>
				</span>
			</label>
			<label for="zbm-reports-to"><span><?php esc_html_e( 'تا تاریخ', 'zarbalman-core' ); ?></span>
				<span class="zbm-vendor-report-jalali-date-wrap">
					<input id="zbm-reports-to" class="zbm-vendor-report-jalali-date" type="text" name="to" value="<?php echo esc_attr( $filter['to_value'] ); ?>" placeholder="سال/ماه/روز" inputmode="numeric" autocomplete="off" maxlength="10" pattern="[0-9۰-۹٠-٩]{4}/[0-9۰-۹٠-٩]{2}/[0-9۰-۹٠-٩]{2}" data-range-role="end" aria-controls="zbm-reports-to-picker" aria-expanded="false" required>
					<button type="button" class="zbm-vendor-report-jalali-trigger" aria-label="<?php esc_attr_e( 'باز کردن تقویم تاریخ پایان', 'zarbalman-core' ); ?>" aria-haspopup="dialog" aria-expanded="false">▦</button>
				</span>
			</label>
			<div class="zbm-reports-filter-error" data-zbm-range-error role="alert" hidden></div>
			<div class="zbm-reports-filter-actions">
				<button class="dokan-btn dokan-btn-theme zbm-reports-apply" type="submit"><?php esc_html_e( 'اعمال فیلتر', 'zarbalman-core' ); ?></button>
				<a class="dokan-btn dokan-btn-default zbm-reports-clear" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'پاک‌کردن فیلتر', 'zarbalman-core' ); ?></a>
			</div>
		</form>
	</section>

	<?php if ( null === $filter['error'] ) : ?>
		<?php if ( ! $currency_supported ) : ?>
			<div class="zbm-reports-alert" role="status"><?php esc_html_e( 'مبلغ‌های دفتر مالی این افزونه با واحد تومان (IRT) ثبت شده‌اند. چون واحد پول فعلی سایت یا ابزار نمایش پول در دسترس نیست، برای جلوگیری از نمایش با واحد نادرست، بخش مبلغ‌ها نمایش داده نمی‌شود.', 'zarbalman-core' ); ?></div>
		<?php endif; ?>

		<?php if ( $report_error ) : ?>
			<div class="zbm-reports-alert" role="status"><?php esc_html_e( 'داده‌های گزارش در حال حاضر خوانده نشد. لطفاً بعداً دوباره مراجعه کنید.', 'zarbalman-core' ); ?></div>
		<?php endif; ?>

		<section class="zbm-reports-daily" aria-labelledby="zbm-reports-daily-title">
			<div class="zbm-reports-section-head">
				<div>
					<h3 id="zbm-reports-daily-title"><?php esc_html_e( 'پرداخت‌های ثبت‌شده در بازهٔ انتخاب‌شده', 'zarbalman-core' ); ?></h3>
					<p><?php esc_html_e( 'روند روزانهٔ پیش‌پرداخت، پرداخت مانده و درخواست‌ها را در یک نگاه بررسی کنید؛ اعداد با انتخاب هر باکس روی نمودار روشن یا خاموش می‌شوند.', 'zarbalman-core' ); ?></p>
				</div>
			</div>
			<?php if ( ! $currency_supported ) : ?>
				<p class="zbm-reports-empty" role="status"><?php esc_html_e( 'مبالغ روزانه در واحد فعلی سایت قابل نمایش نیستند.', 'zarbalman-core' ); ?></p>
			<?php elseif ( $report_error || null === $daily ) : ?>
				<p class="zbm-reports-empty" role="status"><?php esc_html_e( 'نمودار گزارش در حال حاضر خوانده نشد.', 'zarbalman-core' ); ?></p>
			<?php elseif ( empty( $chart_rows ) ) : ?>
				<p class="zbm-reports-empty" role="status"><?php esc_html_e( 'در این بازه، داده‌ای برای نمایش وجود ندارد.', 'zarbalman-core' ); ?></p>
			<?php else : ?>
				<div class="zbm-reports-metrics" role="group" aria-label="<?php esc_attr_e( 'خلاصهٔ پرداخت‌ها و درخواست‌ها', 'zarbalman-core' ); ?>">
					<button type="button" class="zbm-report-metric zbm-report-metric-balance is-active" data-series="balance" aria-pressed="true">
						<span class="zbm-report-metric-label"><span class="zbm-report-metric-dot" aria-hidden="true"></span><?php esc_html_e( 'کل پرداخت‌های مانده', 'zarbalman-core' ); ?></span>
						<strong><?php echo wp_kses_post( wc_price( $balance_total, array( 'currency' => $currency_code ) ) ); ?></strong>
						<small><?php esc_html_e( 'تسویه‌های باقی‌ماندهٔ ثبت‌شده', 'zarbalman-core' ); ?></small>
					</button>
					<button type="button" class="zbm-report-metric zbm-report-metric-deposit is-active" data-series="deposit" aria-pressed="true">
						<span class="zbm-report-metric-label"><span class="zbm-report-metric-dot" aria-hidden="true"></span><?php esc_html_e( 'کل پیش‌پرداخت‌ها', 'zarbalman-core' ); ?></span>
						<strong><?php echo wp_kses_post( wc_price( $deposit_total, array( 'currency' => $currency_code ) ) ); ?></strong>
						<small><?php esc_html_e( 'پرداخت‌های اولیهٔ ثبت‌شده', 'zarbalman-core' ); ?></small>
					</button>
					<button type="button" class="zbm-report-metric zbm-report-metric-requests is-active" data-series="requests" aria-pressed="true">
						<span class="zbm-report-metric-label"><span class="zbm-report-metric-dot" aria-hidden="true"></span><?php esc_html_e( 'کل درخواست‌ها', 'zarbalman-core' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( $requests_total, 0 ) ); ?></strong>
						<small><?php esc_html_e( 'درخواست‌های ثبت‌شده در بازه', 'zarbalman-core' ); ?></small>
					</button>
				</div>

				<div class="zbm-reports-chart" data-zbm-report-chart data-chart="<?php echo esc_attr( wp_json_encode( array( 'rows' => $chart_rows ) ) ); ?>">
					<div class="zbm-reports-chart-head">
						<div>
							<strong><?php esc_html_e( 'روند روزانه', 'zarbalman-core' ); ?></strong>
							<span><?php esc_html_e( 'برای دیدن مقدار دقیق هر روز، نشانگر را روی نمودار حرکت دهید.', 'zarbalman-core' ); ?></span>
						</div>
						<span class="zbm-reports-chart-range"><?php echo esc_html( $filter['from_value'] . ' تا ' . $filter['to_value'] ); ?></span>
					</div>
					<noscript>
						<style>.zbm-vendor-reports .zbm-reports-chart-viewport { display: none; }</style>
						<p class="zbm-reports-empty" role="status"><?php esc_html_e( 'نمودار به جاوااسکریپت نیاز دارد؛ فیلتر تاریخ و خلاصهٔ گزارش بدون آن نیز کار می‌کنند.', 'zarbalman-core' ); ?></p>
					</noscript>
					<div class="zbm-reports-chart-viewport" tabindex="0" aria-label="<?php esc_attr_e( 'نمودار روزانه؛ در موبایل برای مشاهدهٔ همهٔ روزها افقی حرکت کنید', 'zarbalman-core' ); ?>">
						<div class="zbm-reports-chart-plot">
							<svg class="zbm-reports-chart-svg" viewBox="0 0 960 360" role="img" aria-label="<?php esc_attr_e( 'نمودار روند روزانهٔ پرداخت‌ها و درخواست‌ها', 'zarbalman-core' ); ?>" preserveAspectRatio="none">
								<g data-chart-grid aria-hidden="true"></g>
								<g data-chart-axis aria-hidden="true"></g>
								<g data-chart-series></g>
								<g data-chart-points></g>
							</svg>
							<div class="zbm-reports-chart-tooltip" data-chart-tooltip hidden></div>
						</div>
					</div>
					<p class="zbm-reports-chart-note"><?php esc_html_e( 'مبالغ این نمودار پرداخت خریدار به سایت هستند و به‌معنای درآمد یا تسویهٔ فروشنده نیستند.', 'zarbalman-core' ); ?></p>
				</div>
			<?php endif; ?>
		</section>
	<?php endif; ?>
</div>

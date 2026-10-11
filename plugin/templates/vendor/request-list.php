<?php
/** @var list<array<string,mixed>> $requests */
/** @var string $detail_base_url */
/** @var string $products_url */
/** @var string $dashboard_url */
defined( 'ABSPATH' ) || exit;
?>
<div class="zbm-dashboard dokan-dashboard-wrap" dir="rtl">
	<header class="zbm-dashboard-head">
		<div>
			<span class="zbm-dashboard-kicker"><?php esc_html_e( 'مدیریت درخواست‌ها', 'zarbalman-core' ); ?></span>
			<h2><?php esc_html_e( 'درخواست‌های خرید', 'zarbalman-core' ); ?></h2>
			<p class="zbm-dashboard-subtitle"><?php esc_html_e( 'درخواست‌های خریداران را بررسی کنید، جزئیات دریافت را ببینید و در مهلت تعیین‌شده پاسخ دهید.', 'zarbalman-core' ); ?></p>
		</div>
		<div class="zbm-dashboard-head-actions">
			<?php if ( ! empty( $requests ) ) : ?><span class="zbm-dashboard-count"><?php echo esc_html( number_format_i18n( count( $requests ) ) ); ?> <?php esc_html_e( 'درخواست', 'zarbalman-core' ); ?></span><?php endif; ?>
			<a class="zbm-back-link zbm-back-link-secondary zbm-guide-link" href="<?php echo esc_url( 'https://example.invalid/buyer-and-seller-guide/' ); ?>"><?php esc_html_e( 'راهنمای خریدار و فروشنده', 'zarbalman-core' ); ?> <span aria-hidden="true">?</span></a>
			<a class="dokan-btn dokan-btn-theme zbm-back-link zbm-back-link-dashboard" href="<?php echo esc_url( $dashboard_url ); ?>"><span aria-hidden="true">←</span> <?php esc_html_e( 'بازگشت به داشبورد فروشنده', 'zarbalman-core' ); ?></a>
		</div>
	</header>
	<?php if ( empty( $requests ) ) : ?>
		<div class="zbm-empty-state zbm-vendor-empty" role="status">
			<span class="zbm-empty-state-icon" aria-hidden="true">⌁</span>
			<h3><?php esc_html_e( 'فعلاً خبری نیست', 'zarbalman-core' ); ?></h3>
			<p><?php esc_html_e( 'در حال حاضر درخواستی برای محصولات شما وجود ندارد. درخواست جدید و مهلت پاسخ آن در همین بخش نمایش داده می‌شود.', 'zarbalman-core' ); ?></p>
			<a class="dokan-btn dokan-btn-theme" href="<?php echo esc_url( $products_url ); ?>"><?php esc_html_e( 'مدیریت محصولات', 'zarbalman-core' ); ?></a>
		</div>
	<?php else : ?>
		<div class="zbm-card-grid" aria-label="<?php esc_attr_e( 'فهرست درخواست‌های خرید', 'zarbalman-core' ); ?>">
			<?php foreach ( $requests as $row ) : ?>
				<article class="zbm-card zbm-request-card">
					<div class="zbm-product-image zbm-card-media">
						<?php if ( '' !== trim( (string) $row['product_image'] ) ) : ?>
							<?php echo wp_kses_post( (string) $row['product_image'] ); ?>
						<?php else : ?>
							<span class="zbm-product-image-placeholder" aria-hidden="true"><?php esc_html_e( 'محصول', 'zarbalman-core' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="zbm-card-body">
						<div class="zbm-card-topline">
							<span class="zbm-state zbm-state-<?php echo esc_attr( (string) $row['state_tone'] ); ?>"><?php echo esc_html( (string) $row['state_label'] ); ?></span>
							<?php if ( ! empty( $row['is_test'] ) ) : ?><span class="zbm-test-badge"><?php esc_html_e( 'آزمایشی؛ بدون سهم مالی', 'zarbalman-core' ); ?></span><?php endif; ?>
							<span class="zbm-card-id">#<?php echo esc_html( (string) $row['request_number'] ); ?></span>
						</div>
						<h3><?php echo esc_html( (string) $row['product_name'] ); ?></h3>
						<div class="zbm-card-summary">
							<div><span><?php esc_html_e( 'تعداد بسته', 'zarbalman-core' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $row['quantity'] ) ); ?></strong></div>
							<div><span><?php esc_html_e( 'وزن کل تقریبی', 'zarbalman-core' ); ?></span><strong><?php echo esc_html( (string) $row['reference_weight'] ); ?></strong></div>
						</div>
						<p class="zbm-card-delivery"><span><?php esc_html_e( 'روش دریافت', 'zarbalman-core' ); ?></span><strong><?php echo esc_html( (string) $row['delivery_method'] ); ?></strong></p>
						<div class="zbm-card-footer">
							<?php if ( ! empty( $row['deadline_iso'] ) ) : ?><p class="zbm-countdown" data-deadline="<?php echo esc_attr( (string) $row['deadline_iso'] ); ?>"></p><?php endif; ?>
							<?php if ( empty( $row['deadline_iso'] ) && ! empty( $row['deadline_notice'] ) ) : ?><p class="zbm-countdown zbm-countdown-notice" role="status"><?php echo esc_html( (string) $row['deadline_notice'] ); ?></p><?php endif; ?>
							<a class="dokan-btn dokan-btn-theme zbm-card-action" href="<?php echo esc_url( add_query_arg( 'request', (int) $row['id'], $detail_base_url ) ); ?>"><?php esc_html_e( 'مشاهده جزئیات', 'zarbalman-core' ); ?><span aria-hidden="true">←</span></a>
						</div>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>

<?php
/**
 * @var Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest $request
 * @var array<string,mixed> $flow
 * @var array<string,mixed> $line
 * @var int $version
 * @var string $state
 * @var string $state_label
 * @var bool $paused
 * @var bool $sync_pending
 * @var array{type:string,message:string}|null $notice
 * @var string $action_url
 * @var string $nonce_action
 * @var Closure(string):void $command_fields
 */
defined( 'ABSPATH' ) || exit;

$weight = static function ( mixed $tenths ): string {
	if ( ! is_int( $tenths ) ) {
		return '—';
	}
	return number_format_i18n( intdiv( $tenths, 10 ) ) . ( 0 !== $tenths % 10 ? '٫' . abs( $tenths % 10 ) : '' ) . ' ' . __( 'کیلوگرم', 'zarbalman-core' );
};
$evidence_links = static function ( mixed $links, string $label ): void {
	if ( ! is_array( $links ) || array() === $links ) {
		return;
	}
	?>
	<div class="zbm-managed-evidence"><span><?php echo esc_html( $label ); ?></span>
		<?php foreach ( $links as $index => $link ) : if ( ! is_array( $link ) || ! is_string( $link['url'] ?? null ) ) { continue; } ?>
			<a href="<?php echo esc_url( $link['preview_url'] ?? $link['url'] ); ?>" target="_blank" rel="noopener noreferrer">
				<?php if ( isset( $link['preview_url'] ) ) : ?><img src="<?php echo esc_url( $link['preview_url'] ); ?>" alt="<?php echo esc_attr( sprintf( __( '%1$s، عکس %2$d', 'zarbalman-core' ), $label, $index + 1 ) ); ?>" loading="lazy" decoding="async" width="160" height="120"><?php endif; ?>
				<?php echo esc_html( sprintf( __( 'مشاهده مدرک %d', 'zarbalman-core' ), $index + 1 ) ); ?>
			</a>
		<?php endforeach; ?>
	</div>
	<?php
};
?>
<section class="zbm-managed-panel zbm-vendor-operations-panel" data-zbm-case="<?php echo absint( $request->id() ); ?>" data-zbm-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" dir="rtl" aria-labelledby="zbm-vendor-operations-title-<?php echo absint( $request->id() ); ?>">
	<header class="zbm-managed-header">
		<div>
			<span class="zbm-managed-kicker"><?php esc_html_e( 'عملیات سفارش', 'zarbalman-core' ); ?></span>
			<h3 id="zbm-vendor-operations-title-<?php echo absint( $request->id() ); ?>"><?php esc_html_e( 'اقدام‌های لازم برای انجام درخواست', 'zarbalman-core' ); ?></h3>
		</div>
		<span class="zbm-managed-status"><?php echo esc_html( $state_label ); ?></span>
	</header>

	<?php if ( is_array( $notice ) ) : ?>
		<div class="zbm-managed-alert <?php echo 'success' === $notice['type'] ? 'is-success' : 'is-error'; ?>" role="status" tabindex="-1" data-zbm-request-feedback><?php echo esc_html( $notice['message'] ); ?></div>
	<?php endif; ?>
	<?php if ( $paused ) : ?><div class="zbm-managed-alert is-warning" role="status"><?php esc_html_e( 'این درخواست موقتاً توسط مدیریت متوقف است و تا رفع توقف امکان ثبت اقدام وجود ندارد.', 'zarbalman-core' ); ?></div><?php endif; ?>
	<?php if ( $operational_blocked ) : ?><div class="zbm-managed-alert is-warning" role="status"><?php esc_html_e( 'اطلاعات این درخواست به بررسی مدیریت نیاز دارد؛ پس از رفع مشکل، اقدام بعدی در همین صفحه فعال می‌شود.', 'zarbalman-core' ); ?></div><?php endif; ?>
	<?php include ZBM_CORE_DIR . 'templates/managed/progress.php'; ?>

	<?php if ( count( $flow['lines'] ?? array() ) < 2 ) : ?>
	<div class="zbm-managed-card zbm-vendor-operational-summary">
		<h4><?php esc_html_e( 'اطلاعات لازم برای عملیات', 'zarbalman-core' ); ?></h4>
		<dl class="zbm-managed-facts">
			<dt><?php esc_html_e( 'وزن مرجع', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $weight( $line['reference_tenths'] ?? null ) ); ?></dd>
			<dt><?php esc_html_e( 'وزن ثبت‌شده شما', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $weight( $line['vendor_tenths'] ?? null ) ); ?></dd>
			<dt><?php esc_html_e( 'وزن دریافتی خریدار', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $weight( $line['buyer_tenths'] ?? null ) ); ?></dd>
			<dt><?php esc_html_e( 'وزن نهایی', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $weight( $line['final_tenths'] ?? null ) ); ?></dd>
		</dl>
		<?php $evidence_links( $line['vendor_evidence'] ?? array(), __( 'مدارک وزن شما', 'zarbalman-core' ) ); ?>
		<?php $evidence_links( $line['buyer_evidence'] ?? array(), __( 'مدارک وزن خریدار', 'zarbalman-core' ) ); ?>
		<?php $evidence_links( $line['buyer_objection'] ?? array(), __( 'مدارک اعتراض خریدار', 'zarbalman-core' ) ); ?>
		<?php $evidence_links( $line['vendor_objection'] ?? array(), __( 'مدارک اعتراض شما', 'zarbalman-core' ) ); ?>
		<?php foreach ( array( 'buyer_reason' => __( 'توضیحات خریدار', 'zarbalman-core' ), 'vendor_reason' => __( 'توضیحات شما', 'zarbalman-core' ) ) as $key => $label ) : if ( empty( $line[ $key ] ) ) { continue; } ?>
			<p class="zbm-managed-buyer-note"><strong><?php echo esc_html( $label ); ?>:</strong> <?php echo esc_html( $line[ $key ] ); ?></p>
		<?php endforeach; ?>
	</div>

	<?php endif; ?>
	<?php include ZBM_CORE_DIR . ( count( $flow['lines'] ?? array() ) > 1 ? 'templates/managed/line-cards.php' : 'templates/managed/weights.php' ); ?>
	<?php include ZBM_CORE_DIR . 'templates/managed/evidence-history.php'; ?>
</section>

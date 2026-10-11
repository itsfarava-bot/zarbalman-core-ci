<?php
defined( 'ABSPATH' ) || exit;
$saved_line = $line;
$all_vendor_recorded = true;
$vendor_count = 0; $buyer_count = 0; $final_count = 0;
foreach ( $flow['lines'] as $row ) {
	if ( ! is_int( $row['vendor_tenths'] ) || empty( $row['vendor_evidence'] ) ) { $all_vendor_recorded = false; } else { ++$vendor_count; }
	if ( is_int( $row['buyer_tenths'] ) && ! empty( $row['buyer_evidence'] ) ) { ++$buyer_count; }
	if ( is_int( $row['final_tenths'] ) ) { ++$final_count; }
}
?>
<div class="zbm-managed-lines">
	<p class="zbm-managed-muted"><?php esc_html_e( 'وزن و عکس هر محصول مربوط به مجموع بسته‌های همان ردیف است. مراحل تحویل و پرداخت برای کل درخواست مشترک‌اند.', 'zarbalman-core' ); ?></p>
	<div class="zbm-line-progress" role="status"><?php echo esc_html( sprintf( __( 'آماده‌سازی: %1$s از %4$s · وزن خریدار: %2$s از %4$s · وزن نهایی: %3$s از %4$s', 'zarbalman-core' ), number_format_i18n( $vendor_count ), number_format_i18n( $buyer_count ), number_format_i18n( $final_count ), number_format_i18n( count( $flow['lines'] ) ) ) ); ?></div>
	<?php foreach ( $flow['lines'] as $line_index => $line ) : ?>
	<article class="zbm-managed-card zbm-managed-line">
		<header><h4><?php echo esc_html( $line['name'] ); ?></h4><span class="zbm-managed-status"><?php echo esc_html( number_format_i18n( $line['quantity'] ) . ' ' . __( 'بسته', 'zarbalman-core' ) ); ?></span></header>
		<dl class="zbm-managed-facts">
			<?php foreach ( array( 'reference_tenths' => __( 'وزن مرجع', 'zarbalman-core' ), 'vendor_tenths' => __( 'وزن فروشنده', 'zarbalman-core' ), 'buyer_tenths' => __( 'وزن خریدار', 'zarbalman-core' ), 'final_tenths' => __( 'وزن نهایی', 'zarbalman-core' ) ) as $field => $label ) : ?>
			<dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $weight( $line[ $field ] ) ); ?></dd>
			<?php endforeach; ?>
		</dl>
		<?php foreach ( array( 'vendor_evidence' => __( 'عکس فروشنده', 'zarbalman-core' ), 'buyer_evidence' => __( 'عکس خریدار', 'zarbalman-core' ), 'vendor_objection' => __( 'مدرک اعتراض فروشنده', 'zarbalman-core' ), 'buyer_objection' => __( 'مدرک اعتراض خریدار', 'zarbalman-core' ) ) as $field => $label ) { $evidence_links( $line[ $field ], $label ); } ?>
		<?php foreach ( array( 'buyer_reason', 'vendor_reason' ) as $field ) : if ( empty( $line[ $field ] ) ) { continue; } ?><p><?php echo esc_html( $line[ $field ] ); ?></p><?php endforeach; ?>
		<?php include ZBM_CORE_DIR . 'templates/managed/weights.php'; ?>
	</article>
	<?php endforeach; ?>
</div>
<?php $line = $saved_line; unset( $line_index, $all_vendor_recorded ); ?>

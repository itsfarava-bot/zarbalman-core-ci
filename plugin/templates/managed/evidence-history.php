<?php
defined( 'ABSPATH' ) || exit;
$purposes = array( 'vendor_weight' => __( 'وزن قبلی فروشنده', 'zarbalman-core' ), 'buyer_weight' => __( 'وزن قبلی خریدار', 'zarbalman-core' ), 'vendor_objection' => __( 'اعتراض قبلی فروشنده', 'zarbalman-core' ), 'buyer_objection' => __( 'اعتراض قبلی خریدار', 'zarbalman-core' ) );
?>
<?php if ( ! empty( $flow['evidence_history'] ) ) : ?>
<details class="zbm-managed-disclosure"><summary><?php esc_html_e( 'مدارک مراحل بازگشایی‌شده', 'zarbalman-core' ); ?></summary>
	<p><?php esc_html_e( 'این سوابق حفظ شده‌اند و جایگزین آخرین وزن ثبت‌شده نیستند.', 'zarbalman-core' ); ?></p>
	<?php foreach ( $flow['evidence_history'] as $entry ) : ?>
	<article class="zbm-managed-history"><strong><?php echo esc_html( $purposes[ $entry['purpose'] ] ?? '' ); ?></strong>
		<?php if ( ! empty( $entry['name'] ) ) : ?><p><?php echo esc_html( $entry['name'] ); ?></p><?php endif; ?>
		<p><?php echo esc_html( $weight( $entry['weight_tenths'] ) ); ?> · <?php echo esc_html( $format_time( $entry['archived_at'] ) ); ?></p>
		<?php if ( '' !== $entry['reason'] ) : ?><p><?php echo esc_html( $entry['reason'] ); ?></p><?php endif; ?>
		<?php $evidence_links( $entry['evidence'], __( 'مدارک محفوظ', 'zarbalman-core' ) ); ?>
	</article>
	<?php endforeach; ?>
</details>
<?php endif; ?>

<?php defined( 'ABSPATH' ) || exit; ?>
<?php if ( ! empty( $view['lines'] ) ) : ?>
<section class="zbm-request-items" aria-label="<?php esc_attr_e( 'محصولات این درخواست', 'zarbalman-core' ); ?>">
	<?php foreach ( $view['lines'] as $item_view ) : ?>
	<article class="zbm-request-item"><h4><?php echo esc_html( $item_view['name'] ); ?></h4><dl class="zbm-facts">
		<dt><?php esc_html_e( 'تعداد بسته', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $item_view['quantity'] ) ); ?></dd>
		<dt><?php esc_html_e( 'وزن تقریبی ردیف', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $item_view['reference_weight'] ); ?></dd>
		<?php if ( isset( $item_view['package_price'] ) ) : ?><dt><?php esc_html_e( 'قیمت هر بسته', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $item_view['package_price'] ); ?></dd><dt><?php esc_html_e( 'جمع تقریبی ردیف', 'zarbalman-core' ); ?></dt><dd><?php echo esc_html( $item_view['estimated_total'] ); ?></dd><?php endif; ?>
	</dl></article>
	<?php endforeach; ?>
</section>
<?php endif; ?>

<?php

declare(strict_types=1);

namespace Zarbalman\Core\Frontend;

final class RequestPageBridge {
	public function register(): void {
		add_filter( 'the_content', array( $this, 'appendDashboardToApprovedPages' ), 20 );
	}

	public function appendDashboardToApprovedPages( string $content ): string {
		if ( is_admin() || ! is_singular( 'page' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}
		$map = array(
			'order-review-by-buyer' => 'zbm_buyer_requests',
			'approval-by-supplier'  => 'zbm_supplier_requests',
		);
		$shortcode = $map[ $post->post_name ] ?? '';
		if ( '' === $shortcode ) {
			return $content;
		}
		if ( has_shortcode( $content, $shortcode ) ) {
			return $content;
		}

		// These two pages are the approved Core surfaces.  Replacing the old
		// Elementor/Dokan placeholder body at render time prevents a duplicate
		// dashboard and leaves the database page content untouched and reversible.
		return '<div class="zbm-page-view zbm-page-view-' . esc_attr( $post->post_name ) . '">' . do_shortcode( '[' . $shortcode . ']' ) . '</div>';
	}
}

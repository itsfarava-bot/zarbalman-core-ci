<?php

declare(strict_types=1);

namespace Zarbalman\Core\Http;

use Zarbalman\Core\Domain\Workflow\AuthorizationPolicy;
use Zarbalman\Core\Infrastructure\EvidenceCrypto;
use Zarbalman\Core\Infrastructure\ManagedEvidence;
use Zarbalman\Core\Security\AdminCapabilities;

final class EvidenceDownloadController {
	private const ALLOWED_MIMES = array( 'image/jpeg', 'image/png', 'image/webp' );

	public function __construct(
		private readonly EvidenceCrypto $crypto,
		private readonly AuthorizationPolicy $authorization,
		private readonly ?ManagedEvidence $managed = null
	) {}

	public function register(): void {
		add_action( 'admin_post_zbm_core_download_evidence', array( $this, 'download' ) );
		add_action( 'admin_post_zbm_core_download_managed_evidence', array( $this, 'downloadManaged' ) );
	}

	public function url( int $attachment_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'        => 'zbm_core_download_evidence',
					'attachment_id' => $attachment_id,
				),
				admin_url( 'admin-post.php' )
			),
			'zbm_core_evidence_' . $attachment_id
		);
	}

	public function download(): never {
		$attachment_id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0;
		if ( $attachment_id < 1 ) {
			$this->fail( 400, __( 'شناسه مدرک معتبر نیست.', 'zarbalman-core' ) );
		}

		check_admin_referer( 'zbm_core_evidence_' . $attachment_id );
		// Never downgrade a managed receipt/weight image to legacy order visibility.
		if ( metadata_exists( 'post', $attachment_id, '_zbm_managed_request_id' ) || metadata_exists( 'post', $attachment_id, '_zbm_managed_purpose' ) ) {
			$this->fail( 403, __( 'مدرک مدیریت‌شده فقط از مسیر خصوصی درخواست قابل مشاهده است.', 'zarbalman-core' ) );
		}

		$order_id = absint( get_post_meta( $attachment_id, '_zbm_evidence_order_id', true ) );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;
		$user_id  = get_current_user_id();
		if (
			1 !== (int) get_post_meta( $attachment_id, '_zbm_evidence_private', true )
			|| ! $order
			|| ! $this->authorization->can_view( $order, $user_id )
		) {
			$this->fail( 403, __( 'اجازه مشاهده این مدرک را ندارید.', 'zarbalman-core' ) );
		}

		$path = get_attached_file( $attachment_id, true );
		if ( ! is_string( $path ) || ! is_readable( $path ) ) {
			$this->fail( 404, __( 'فایل مدرک پیدا نشد.', 'zarbalman-core' ) );
		}
		$uploads      = wp_upload_dir( null, false );
		$real_path    = realpath( $path );
		$real_base    = ! empty( $uploads['basedir'] ) ? realpath( (string) $uploads['basedir'] ) : false;
		$base_prefix  = is_string( $real_base ) ? trailingslashit( wp_normalize_path( $real_base ) ) : '';
		$normalized   = is_string( $real_path ) ? wp_normalize_path( $real_path ) : '';
		if ( '' === $base_prefix || '' === $normalized || ! str_starts_with( $normalized, $base_prefix ) ) {
			$this->fail( 403, __( 'مسیر فایل مدرک معتبر نیست.', 'zarbalman-core' ) );
		}

		$plaintext = $this->crypto->decrypt_file( $path );
		if ( is_wp_error( $plaintext ) ) {
			$this->fail( 500, $plaintext->get_error_message() );
		}

		$expected_hash = (string) get_post_meta( $attachment_id, '_zbm_evidence_sha256', true );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_hash ) || ! hash_equals( $expected_hash, hash( 'sha256', $plaintext ) ) ) {
			$this->fail( 409, __( 'سلامت فایل مدرک تأیید نشد.', 'zarbalman-core' ) );
		}

		$mime = (string) get_post_meta( $attachment_id, '_zbm_evidence_original_mime', true );
		if ( ! in_array( $mime, self::ALLOWED_MIMES, true ) ) {
			$this->fail( 415, __( 'نوع فایل مدرک معتبر نیست.', 'zarbalman-core' ) );
		}

		$extension = array_search( $mime, array( 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ), true );
		$filename  = 'evidence-' . $order_id . '-' . $attachment_id . '.' . ( is_string( $extension ) ? $extension : 'bin' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . strlen( $plaintext ) );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );
		echo $plaintext; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Verified binary image response.
		exit;
	}

	private function fail( int $status, string $message ): never {
		wp_die( esc_html( $message ), esc_html__( 'دسترسی به مدرک', 'zarbalman-core' ), array( 'response' => $status ) );
	}

	/** Authenticated private managed download; no order-visibility fallback. */
	public function downloadManaged(): never {
		$id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0;
		$request_id = isset( $_GET['request_id'] ) ? absint( $_GET['request_id'] ) : 0;
		if ( $id < 1 || $request_id < 1 || null === $this->managed ) {
			$this->fail( 404, __( 'مدرک خصوصی در دسترس نیست.', 'zarbalman-core' ) );
		}
		check_admin_referer( 'zbm_core_managed_evidence_' . $request_id . '_' . $id );
		$is_preview = isset( $_GET['preview'] ) && '1' === (string) $_GET['preview'];
		$result = $this->managed->read( $id, $request_id, get_current_user_id(), $is_preview );
		if ( is_wp_error( $result ) ) {
			$this->fail( 403, $result->get_error_message() );
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Content-Type: ' . $result['mime'] );
		header( 'Content-Length: ' . strlen( $result['contents'] ) );
		$preview = $is_preview
			&& in_array( $result['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true );
		header( 'Content-Disposition: ' . ( $preview ? 'inline' : 'attachment' ) . '; filename="' . sanitize_file_name( $result['filename'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );
		echo $result['contents']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authenticated verified binary.
		exit;
	}
}

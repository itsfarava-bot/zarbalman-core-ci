<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use WP_Error;

final class EvidenceUploader {
	private const MAX_FILES = 4;
	private const MAX_BYTES = 5242880;
	private const ALLOWED_MIMES = array(
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'webp'     => 'image/webp',
	);

	public function __construct( private readonly EvidenceCrypto $crypto ) {}

	/** @return list<int>|WP_Error */
	public function upload( int $order_id, int $actor_user_id, mixed $raw_files, int $managed_request_id = 0, string $purpose = '' ): array|WP_Error {
		if ( $actor_user_id < 1 || $order_id < 0 || $managed_request_id < 0 || ( $managed_request_id > 0 && ! in_array( $purpose, ManagedEvidence::PURPOSES, true ) ) ) {
			return new WP_Error( 'zbm_evidence_scope', __( 'مالک و هدف مدرک معتبر نیست.', 'zarbalman-core' ) );
		}
		if (
			is_array( $raw_files )
			&& isset( $raw_files['name'] )
			&& is_array( $raw_files['name'] )
			&& count( $raw_files['name'] ) > self::MAX_FILES
		) {
			return new WP_Error( 'zbm_evidence_count', __( 'حداکثر چهار فایل مدرک مجاز است.', 'zarbalman-core' ) );
		}

		$files = $this->normalize_files( $raw_files );
		if ( count( $files ) < 1 || count( $files ) > self::MAX_FILES ) {
			return new WP_Error( 'zbm_evidence_count', __( 'یک تا چهار فایل مدرک انتخاب کنید.', 'zarbalman-core' ) );
		}
		$is_receipt = self::isReceiptPurpose( $purpose );
		if ( $is_receipt && 1 !== count( $files ) ) {
			return new WP_Error( 'zbm_evidence_count', __( 'برای رسید پرداخت فقط یک فایل انتخاب کنید.', 'zarbalman-core' ) );
		}

		$attachment_ids = array();
		foreach ( $files as $file ) {
			$validated = $this->validate_file( $file, $is_receipt );
			if ( is_wp_error( $validated ) ) {
				$this->delete( $attachment_ids );
				return $validated;
			}
			$mime = $validated['mime'];
			$original_name = $validated['filename'];

			$destination = $this->destination();
			if ( is_wp_error( $destination ) ) {
				$this->delete( $attachment_ids );
				return $destination;
			}

			$encrypted = $this->crypto->encrypt_file( (string) $file['tmp_name'], $destination );
			if ( is_wp_error( $encrypted ) ) {
				$this->delete( $attachment_ids );
				return $encrypted;
			}

			$attachment_id = wp_insert_attachment(
				array(
					'post_mime_type' => 'application/octet-stream',
					'post_title'     => __( 'مدرک رمز‌شده زربال‌من', 'zarbalman-core' ),
					'post_content'   => '',
					'post_status'    => 'private',
					'post_author'    => $actor_user_id,
					// Set the managed marker at creation, before an attachment can be
					// reached through a legacy URL even if later metadata writes fail.
					'meta_input'     => $managed_request_id > 0 ? array( '_zbm_managed_request_id' => $managed_request_id, '_zbm_managed_purpose' => $purpose ) : array(),
				),
				$destination,
				0,
				true
			);

			if ( is_wp_error( $attachment_id ) || ! is_int( $attachment_id ) || $attachment_id < 1 ) {
				wp_delete_file( $destination );
				$this->delete( $attachment_ids );
				return new WP_Error( 'zbm_evidence_attachment_failed', __( 'ثبت امن مدرک انجام نشد.', 'zarbalman-core' ) );
			}

			// wp_insert_attachment() already stores the file path. A second
			// same-value update returns false in WordPress, even on success.
			// Verify the persisted, unfiltered private path instead; a missing
			// path or a filter redirecting it to another file must fail closed.
			$stored_path = ManagedEvidence::privatePath( $attachment_id );
			if ( null === $stored_path || realpath( $destination ) !== $stored_path ) {
				$this->discard_created_attachment( $attachment_id, $destination );
				$this->delete( $attachment_ids );
				return new WP_Error( 'zbm_evidence_attachment_path', __( 'اتصال امن فایل مدرک انجام نشد.', 'zarbalman-core' ) );
			}
			update_post_meta( $attachment_id, '_zbm_evidence_order_id', $order_id );
			update_post_meta( $attachment_id, '_zbm_evidence_actor_id', $actor_user_id );
			update_post_meta( $attachment_id, '_zbm_evidence_private', 1 );
			update_post_meta( $attachment_id, '_zbm_evidence_encrypted', 1 );
			update_post_meta( $attachment_id, '_zbm_evidence_original_mime', $mime );
			update_post_meta( $attachment_id, '_zbm_evidence_original_name', $original_name );
			update_post_meta( $attachment_id, '_zbm_evidence_original_size', $encrypted['size'] );
			update_post_meta( $attachment_id, '_zbm_evidence_sha256', $encrypted['sha256'] );
			if (
				$order_id !== absint( get_post_meta( $attachment_id, '_zbm_evidence_order_id', true ) )
				|| $actor_user_id !== absint( get_post_meta( $attachment_id, '_zbm_evidence_actor_id', true ) )
				|| 1 !== (int) get_post_meta( $attachment_id, '_zbm_evidence_private', true )
				|| 1 !== (int) get_post_meta( $attachment_id, '_zbm_evidence_encrypted', true )
				|| $mime !== (string) get_post_meta( $attachment_id, '_zbm_evidence_original_mime', true )
				|| $original_name !== (string) get_post_meta( $attachment_id, '_zbm_evidence_original_name', true )
				|| $encrypted['size'] !== (int) get_post_meta( $attachment_id, '_zbm_evidence_original_size', true )
				|| ! hash_equals( $encrypted['sha256'], (string) get_post_meta( $attachment_id, '_zbm_evidence_sha256', true ) )
				|| ( $managed_request_id > 0 && ( $managed_request_id !== (int) get_post_meta( $attachment_id, '_zbm_managed_request_id', true ) || $purpose !== get_post_meta( $attachment_id, '_zbm_managed_purpose', true ) ) )
			) {
				$this->discard_created_attachment( $attachment_id, $destination );
				$this->delete( $attachment_ids );
				return new WP_Error( 'zbm_evidence_metadata_failed', __( 'فراداده امنیتی مدرک ثبت نشد.', 'zarbalman-core' ) );
			}
			$attachment_ids[] = (int) $attachment_id;
		}

		return $attachment_ids;
	}

	/** Roll back this upload without trusting a failed attachment's file path. */
	private function discard_created_attachment( int $attachment_id, string $destination ): void {
		$delete_only_created_file = static function ( string $file ) use ( $destination ): string {
			return wp_normalize_path( $file ) === wp_normalize_path( $destination ) ? $file : '';
		};
		add_filter( 'wp_delete_file', $delete_only_created_file, PHP_INT_MAX );
		try {
			wp_delete_attachment( $attachment_id, true );
			// Keep the fallback guarded too: a deletion filter must never
			// redirect this failed upload's cleanup to an unrelated file.
			wp_delete_file( $destination );
		} finally {
			remove_filter( 'wp_delete_file', $delete_only_created_file, PHP_INT_MAX );
		}
	}

	/** @param list<int> $attachment_ids */
	public function delete( array $attachment_ids ): void {
		foreach ( $attachment_ids as $attachment_id ) {
			if ( 1 === (int) get_post_meta( $attachment_id, '_zbm_evidence_private', true ) ) {
				wp_delete_attachment( $attachment_id, true );
			}
		}
	}

	/** @return list<array<string,mixed>> */
	private function normalize_files( mixed $raw_files ): array {
		if ( ! is_array( $raw_files ) || ! isset( $raw_files['name'] ) ) {
			return array();
		}

		if ( ! is_array( $raw_files['name'] ) ) {
			return array( $raw_files );
		}

		$files = array();
		foreach ( array_keys( $raw_files['name'] ) as $index ) {
			if ( '' === (string) $raw_files['name'][ $index ] ) {
				continue;
			}
			$files[] = array(
				'name'     => $raw_files['name'][ $index ] ?? '',
				'type'     => $raw_files['type'][ $index ] ?? '',
				'tmp_name' => $raw_files['tmp_name'][ $index ] ?? '',
				'error'    => $raw_files['error'][ $index ] ?? UPLOAD_ERR_NO_FILE,
				'size'     => $raw_files['size'][ $index ] ?? 0,
			);
		}
		return $files;
	}

	/** @return array{mime:string,filename:string}|WP_Error */
	private function validate_file( array $file, bool $allow_any_type = false ): array|WP_Error {
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new WP_Error( 'zbm_evidence_upload_error', __( 'یکی از فایل‌های مدرک کامل بارگذاری نشد.', 'zarbalman-core' ) );
		}
		if ( (int) ( $file['size'] ?? 0 ) < 1 || (int) $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'zbm_evidence_size', __( 'حجم هر فایل مدرک باید حداکثر ۵ مگابایت باشد.', 'zarbalman-core' ) );
		}
		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			return new WP_Error( 'zbm_evidence_source', __( 'منبع فایل بارگذاری‌شده معتبر نیست.', 'zarbalman-core' ) );
		}

		$size = filesize( $tmp_name );
		if ( false === $size || $size < 1 || $size > self::MAX_BYTES || $size !== (int) $file['size'] ) {
			return new WP_Error( 'zbm_evidence_content', __( 'محتوا یا اندازه واقعی فایل معتبر نیست.', 'zarbalman-core' ) );
		}

		if ( $allow_any_type ) {
			return array( 'mime' => $this->detect_mime( $tmp_name ), 'filename' => $this->original_filename( $file ) );
		}

		$checked = wp_check_filetype_and_ext( $tmp_name, (string) $file['name'], self::ALLOWED_MIMES );
		if ( empty( $checked['type'] ) || ! in_array( $checked['type'], self::ALLOWED_MIMES, true ) ) {
			return new WP_Error( 'zbm_evidence_type', __( 'برای مدارک وزن و اختلاف فقط تصویر JPG، PNG یا WebP مجاز است.', 'zarbalman-core' ) );
		}
		$image = @getimagesize( $tmp_name );
		$actual_mime = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $tmp_name ) : false;
		if ( false === $size || $size < 1 || $size > self::MAX_BYTES || $size !== (int) $file['size'] || ! is_array( $image ) || ( $image[0] ?? 0 ) < 1 || ( $image[1] ?? 0 ) < 1 || ( $image['mime'] ?? '' ) !== $checked['type'] || $actual_mime !== $checked['type'] ) {
			return new WP_Error( 'zbm_evidence_content', __( 'محتوا یا اندازه واقعی تصویر معتبر نیست.', 'zarbalman-core' ) );
		}
		return array( 'mime' => (string) $checked['type'], 'filename' => $this->original_filename( $file ) );
	}

	private function detect_mime( string $path ): string {
		$mime = '';
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = @finfo_open( FILEINFO_MIME_TYPE );
			if ( false !== $finfo ) {
				$detected = @finfo_file( $finfo, $path );
				finfo_close( $finfo );
				$mime = is_string( $detected ) ? strtolower( trim( $detected ) ) : '';
			}
		}
		if ( '' === $mime && function_exists( 'mime_content_type' ) ) {
			$detected = @mime_content_type( $path );
			$mime = is_string( $detected ) ? strtolower( trim( $detected ) ) : '';
		}
		return 1 === preg_match( '/\A[a-z0-9][a-z0-9!#$&^_.+\-]*\/[a-z0-9][a-z0-9!#$&^_.+\-]*\z/i', $mime ) ? $mime : 'application/octet-stream';
	}

	private function original_filename( array $file ): string {
		$name = sanitize_file_name( wp_basename( (string) ( $file['name'] ?? '' ) ) );
		$name = substr( $name, 0, 180 );
		return '' !== $name ? $name : 'receipt.bin';
	}

	private static function isReceiptPurpose( string $purpose ): bool {
		return in_array( $purpose, array( 'manual_deposit_receipt', 'manual_balance_receipt' ), true );
	}

	/** @return string|WP_Error */
	private function destination(): string|WP_Error {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return new WP_Error( 'zbm_evidence_directory', __( 'مسیر امن ذخیره مدرک در دسترس نیست.', 'zarbalman-core' ) );
		}

		$directory = trailingslashit( (string) $uploads['basedir'] ) . 'zbm-private-evidence/' . gmdate( 'Y/m' );
		if ( ! wp_mkdir_p( $directory ) || ! is_writable( $directory ) ) {
			return new WP_Error( 'zbm_evidence_directory', __( 'مسیر امن ذخیره مدرک قابل نوشتن نیست.', 'zarbalman-core' ) );
		}
		$this->protect_directory( dirname( dirname( $directory ) ) );

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$filename = 'zbm-' . str_replace( '-', '', wp_generate_uuid4() ) . '.zbm';
			$path     = trailingslashit( $directory ) . $filename;
			if ( ! file_exists( $path ) ) {
				return $path;
			}
		}

		return new WP_Error( 'zbm_evidence_filename', __( 'نام امن و یکتای مدرک ساخته نشد.', 'zarbalman-core' ) );
	}

	private function protect_directory( string $root ): void {
		$files = array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>\n",
		);
		foreach ( $files as $filename => $contents ) {
			$path = trailingslashit( $root ) . $filename;
			if ( ! file_exists( $path ) ) {
				@file_put_contents( $path, $contents, LOCK_EX );
			}
		}
	}
}

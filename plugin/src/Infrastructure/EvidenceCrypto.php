<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use WP_Error;

/**
 * Authenticated encryption for evidence stored below the public uploads URL.
 * Direct HTTP access only exposes ciphertext; authorized downloads are
 * decrypted by EvidenceDownloadController.
 */
final class EvidenceCrypto {
	private const KEY_OPTION = 'zbm_core_evidence_key';
	private const MAGIC      = "ZBME1";
	private const SODIUM     = 'S';
	private const OPENSSL    = 'O';
	private const MAX_PLAINTEXT_BYTES = 5242880;
	private const MAX_ENCRYPTED_BYTES = 5243008;

	public function is_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			|| (
				function_exists( 'openssl_encrypt' )
				&& function_exists( 'openssl_decrypt' )
				&& function_exists( 'openssl_get_cipher_methods' )
				&& in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true )
			);
	}

	public function ensure_key(): bool {
		if ( $this->key() ) {
			return true;
		}
		if ( false !== get_option( self::KEY_OPTION, false ) || get_option( 'zbm_core_evidence_key_issued', false ) || $this->has_encrypted_evidence() ) {
			// Never replace a lost key: doing so would make existing evidence
			// permanently undecryptable while hiding the real operational fault.
			return false;
		}

		try {
			$encoded = base64_encode( random_bytes( 32 ) );
		} catch ( \Throwable ) {
			return false;
		}

		if ( add_option( self::KEY_OPTION, $encoded, '', false ) ) {
			update_option( 'zbm_core_evidence_key_issued', 1, false );
			return true;
		}

		return null !== $this->key();
	}

	public function has_key(): bool {
		return null !== $this->key();
	}

	/** @return array{sha256:string,size:int}|WP_Error */
	public function encrypt_file( string $source, string $destination ): array|WP_Error {
		$key = $this->key();
		if ( null === $key || ! $this->is_available() ) {
			return new WP_Error( 'zbm_evidence_crypto_unavailable', __( 'زیرساخت رمزنگاری امن مدرک در دسترس نیست.', 'zarbalman-core' ) );
		}

		$size = @filesize( $source );
		if ( false === $size || $size < 1 || $size > self::MAX_PLAINTEXT_BYTES ) {
			return new WP_Error( 'zbm_evidence_size', __( 'حجم هر فایل مدرک باید حداکثر ۵ مگابایت باشد.', 'zarbalman-core' ) );
		}

		$plaintext = file_get_contents( $source );
		if ( false === $plaintext ) {
			return new WP_Error( 'zbm_evidence_read_failed', __( 'خواندن امن فایل مدرک انجام نشد.', 'zarbalman-core' ) );
		}
		if ( strlen( $plaintext ) !== $size || strlen( $plaintext ) > self::MAX_PLAINTEXT_BYTES ) {
			return new WP_Error( 'zbm_evidence_size_changed', __( 'اندازه فایل مدرک حین خواندن تغییر کرد.', 'zarbalman-core' ) );
		}

		try {
			if ( function_exists( 'sodium_crypto_secretbox' ) ) {
				$nonce   = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$payload = self::MAGIC . self::SODIUM . $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $key );
			} else {
				$iv  = random_bytes( 12 );
				$tag = '';
				$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
				if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
					throw new \RuntimeException( 'OpenSSL evidence encryption failed.' );
				}
				$payload = self::MAGIC . self::OPENSSL . $iv . $tag . $ciphertext;
			}
		} catch ( \Throwable ) {
			return new WP_Error( 'zbm_evidence_encrypt_failed', __( 'رمزنگاری فایل مدرک انجام نشد.', 'zarbalman-core' ) );
		}

		if ( ! $this->write_exclusive( $destination, $payload ) ) {
			return new WP_Error( 'zbm_evidence_store_failed', __( 'ذخیره امن فایل مدرک انجام نشد.', 'zarbalman-core' ) );
		}

		return array(
			'sha256' => hash( 'sha256', $plaintext ),
			'size'   => strlen( $plaintext ),
		);
	}

	/** @return string|WP_Error */
	public function decrypt_file( string $path ): string|WP_Error {
		$key = $this->key();
		if ( null === $key ) {
			return new WP_Error( 'zbm_evidence_key_missing', __( 'کلید رمزگشایی مدرک در دسترس نیست.', 'zarbalman-core' ) );
		}

		$size = @filesize( $path );
		if ( false === $size || $size < 1 || $size > self::MAX_ENCRYPTED_BYTES ) {
			return new WP_Error( 'zbm_evidence_encrypted_size', __( 'حجم فایل رمز‌شده معتبر نیست.', 'zarbalman-core' ) );
		}

		$payload = file_get_contents( $path );
		if ( false === $payload || ! str_starts_with( $payload, self::MAGIC ) ) {
			return new WP_Error( 'zbm_evidence_not_encrypted', __( 'ساختار فایل مدرک امن نیست.', 'zarbalman-core' ) );
		}

		$algorithm = substr( $payload, strlen( self::MAGIC ), 1 );
		$body      = substr( $payload, strlen( self::MAGIC ) + 1 );

		if ( self::SODIUM === $algorithm ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) || strlen( $body ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return new WP_Error( 'zbm_evidence_decrypt_unavailable', __( 'رمزگشایی این مدرک در دسترس نیست.', 'zarbalman-core' ) );
			}
			$nonce      = substr( $body, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = substr( $body, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plaintext  = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
		} elseif ( self::OPENSSL === $algorithm ) {
			if ( ! function_exists( 'openssl_decrypt' ) || strlen( $body ) <= 28 ) {
				return new WP_Error( 'zbm_evidence_decrypt_unavailable', __( 'رمزگشایی این مدرک در دسترس نیست.', 'zarbalman-core' ) );
			}
			$iv         = substr( $body, 0, 12 );
			$tag        = substr( $body, 12, 16 );
			$ciphertext = substr( $body, 28 );
			$plaintext  = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		} else {
			return new WP_Error( 'zbm_evidence_algorithm_unknown', __( 'الگوریتم رمزنگاری مدرک شناخته‌شده نیست.', 'zarbalman-core' ) );
		}

		return is_string( $plaintext )
			? $plaintext
			: new WP_Error( 'zbm_evidence_integrity_failed', __( 'اصالت یا سلامت فایل مدرک تأیید نشد.', 'zarbalman-core' ) );
	}

	public function is_encrypted_file( string $path ): bool {
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		$prefix = fread( $handle, strlen( self::MAGIC ) );
		fclose( $handle );
		return self::MAGIC === $prefix;
	}

	private function key(): ?string {
		$encoded = get_option( self::KEY_OPTION, '' );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return null;
		}
		$key = base64_decode( $encoded, true );
		return is_string( $key ) && 32 === strlen( $key ) ? $key : null;
	}

	private function has_encrypted_evidence(): bool {
		$ids = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_key'               => '_zbm_evidence_encrypted', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exceptional missing-key safety check.
				'meta_value'             => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Exceptional missing-key safety check.
			)
		);
		if ( is_array( $ids ) && array() !== $ids ) {
			return true;
		}
		// Preserve original attachment detection, and fail closed for managed
		// attachments even if encrypted metadata was damaged or removed.
		$managed = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_key' => '_zbm_managed_request_id' ) );
		if ( is_array( $managed ) && $managed ) {
			return true;
		}
		global $wpdb;
		if ( $wpdb instanceof \wpdb ) {
			$table = ( new Schema( $wpdb ) )->managed_cases_table();
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $exists === $table ) {
				$has_case = $wpdb->get_var( $wpdb->prepare( 'SELECT request_id FROM %i LIMIT 1', $table ) );
				return null !== $has_case || '' !== $wpdb->last_error;
			}
			return '' !== $wpdb->last_error;
		}
		return false;
	}

	private function write_exclusive( string $path, string $contents ): bool {
		$handle = @fopen( $path, 'xb' );
		if ( false === $handle ) {
			return false;
		}

		$written = 0;
		$length  = strlen( $contents );
		while ( $written < $length ) {
			$chunk = fwrite( $handle, substr( $contents, $written ) );
			if ( false === $chunk || 0 === $chunk ) {
				fclose( $handle );
				wp_delete_file( $path );
				return false;
			}
			$written += $chunk;
		}
		fflush( $handle );
		fclose( $handle );
		if ( defined( 'FS_CHMOD_FILE' ) ) {
			@chmod( $path, FS_CHMOD_FILE );
		}
		return true;
	}
}

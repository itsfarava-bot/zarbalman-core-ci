<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use WP_Error;

final class EvidenceLegacyMigrator {
	private const ALLOWED_MIMES = array( 'image/jpeg', 'image/png', 'image/webp' );

	public function __construct( private readonly EvidenceCrypto $crypto ) {}

	/** @return array{migrated:int,failed:int,remaining:bool} */
	public function migrate_batch( int $limit = 20 ): array {
		$ids      = $this->legacy_ids( max( 1, min( 100, $limit ) ), true );
		$migrated = 0;
		$failed   = 0;

		foreach ( $ids as $attachment_id ) {
			$result = $this->migrate_attachment( $attachment_id );
			if ( is_wp_error( $result ) ) {
				update_post_meta( $attachment_id, '_zbm_evidence_migration_failed', $result->get_error_code() );
				$failed++;
			} else {
				delete_post_meta( $attachment_id, '_zbm_evidence_migration_failed' );
				$migrated++;
			}
		}

		return array(
			'migrated'  => $migrated,
			'failed'    => $failed,
			'remaining' => array() !== $this->legacy_ids( 1, true ),
		);
	}

	public function has_legacy_evidence(): bool {
		return array() !== $this->legacy_ids( 1, false );
	}

	public function has_migratable_evidence(): bool {
		return array() !== $this->legacy_ids( 1, true );
	}

	private function migrate_attachment( int $attachment_id ): bool|WP_Error {
		$path = get_attached_file( $attachment_id, true );
		if ( ! is_string( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'zbm_legacy_evidence_missing', __( 'فایل مدرک قدیمی پیدا نشد.', 'zarbalman-core' ) );
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::ALLOWED_MIMES, true ) ) {
			return new WP_Error( 'zbm_legacy_evidence_type', __( 'نوع مدرک قدیمی قابل مهاجرت نیست.', 'zarbalman-core' ) );
		}

		if ( $this->crypto->is_encrypted_file( $path ) ) {
			$plaintext = $this->crypto->decrypt_file( $path );
			if ( is_wp_error( $plaintext ) ) {
				return $plaintext;
			}
			$encrypted = array( 'sha256' => hash( 'sha256', $plaintext ), 'size' => strlen( $plaintext ) );
		} else {
			$temp      = $path . '.' . str_replace( '-', '', wp_generate_uuid4() ) . '.tmp';
			$encrypted = $this->crypto->encrypt_file( $path, $temp );
			if ( is_wp_error( $encrypted ) ) {
				return $encrypted;
			}
			if ( ! @rename( $temp, $path ) ) {
				wp_delete_file( $temp );
				return new WP_Error( 'zbm_legacy_evidence_replace', __( 'جایگزینی امن مدرک قدیمی انجام نشد.', 'zarbalman-core' ) );
			}
		}

		$derivatives = $this->remove_public_derivatives( $attachment_id, $path );
		if ( is_wp_error( $derivatives ) ) {
			return $derivatives;
		}

		update_post_meta( $attachment_id, '_zbm_evidence_original_mime', $mime );
		update_post_meta( $attachment_id, '_zbm_evidence_original_size', $encrypted['size'] );
		update_post_meta( $attachment_id, '_zbm_evidence_sha256', $encrypted['sha256'] );
		update_post_meta( $attachment_id, '_zbm_evidence_encrypted', 1 );
		if (
			$mime !== (string) get_post_meta( $attachment_id, '_zbm_evidence_original_mime', true )
			|| 1 !== (int) get_post_meta( $attachment_id, '_zbm_evidence_encrypted', true )
			|| ! hash_equals( $encrypted['sha256'], (string) get_post_meta( $attachment_id, '_zbm_evidence_sha256', true ) )
		) {
			delete_post_meta( $attachment_id, '_zbm_evidence_encrypted' );
			return new WP_Error( 'zbm_legacy_evidence_metadata', __( 'فراداده امنیتی مدرک قدیمی ثبت نشد.', 'zarbalman-core' ) );
		}
		wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => 'application/octet-stream',
				'post_title'     => __( 'مدرک رمز‌شده زربال‌من', 'zarbalman-core' ),
			),
			true
		);
		return true;
	}

	private function remove_public_derivatives( int $attachment_id, string $attached_path ): bool|WP_Error {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $metadata ) ) {
			return true;
		}

		$filenames = array();
		foreach ( is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array() as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$filenames[] = (string) $size['file'];
			}
		}
		if ( ! empty( $metadata['original_image'] ) ) {
			$filenames[] = (string) $metadata['original_image'];
		}

		$directory = realpath( dirname( $attached_path ) );
		if ( is_string( $directory ) ) {
			$prefix = trailingslashit( wp_normalize_path( $directory ) );
			foreach ( array_unique( $filenames ) as $filename ) {
				$candidate = realpath( $directory . DIRECTORY_SEPARATOR . wp_basename( $filename ) );
				$normalized = is_string( $candidate ) ? wp_normalize_path( $candidate ) : '';
				if ( '' !== $normalized && str_starts_with( $normalized, $prefix ) && $normalized !== wp_normalize_path( $attached_path ) ) {
					wp_delete_file( $candidate );
					if ( file_exists( $candidate ) ) {
						return new WP_Error( 'zbm_legacy_derivative_delete', __( 'حذف نسخه عمومی یکی از مدارک قدیمی انجام نشد.', 'zarbalman-core' ) );
					}
				}
			}
		}

		wp_update_attachment_metadata( $attachment_id, array() );
		return true;
	}

	/** @return list<int> */
	private function legacy_ids( int $limit, bool $exclude_failed ): array {
		$meta_query = array(
			'relation' => 'AND',
			array( 'key' => '_zbm_evidence_private', 'value' => '1' ),
			array(
				'relation' => 'OR',
				array( 'key' => '_zbm_evidence_encrypted', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_zbm_evidence_encrypted', 'value' => '1', 'compare' => '!=' ),
			),
		);
		if ( $exclude_failed ) {
			$meta_query[] = array( 'key' => '_zbm_evidence_migration_failed', 'compare' => 'NOT EXISTS' );
		}

		$ids = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'any',
				'posts_per_page'         => $limit,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded one-time security migration.
			)
		);

		return array_values( array_map( 'absint', is_array( $ids ) ? $ids : array() ) );
	}
}

<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use Throwable;
use WP_Error;
use Zarbalman\Core\Domain\ManagedFlow\FlowState;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Integration\DokanVendorEligibility;
use Zarbalman\Core\Security\AdminCapabilities;

/** Private request/purpose-bound evidence. Upload is not payment or aggregate binding. */
final class ManagedEvidence {
	public const PURPOSES = array( 'manual_deposit_receipt', 'manual_balance_receipt', 'vendor_weight', 'buyer_weight', 'buyer_objection', 'vendor_objection', 'finance_return', 'finance_refund', 'finance_execution' );

	/** Use the same request operation lock instance as ManagedCaseRepository. */
	public function __construct(
		private readonly EvidenceUploader $uploader,
		private readonly EvidenceCrypto $crypto,
		private readonly PurchaseRequestRepository $requests,
		private readonly ManagedCaseRepository $cases,
		private readonly PurchaseRequestOperationLockPort $lock
	) {}

	/**
	 * @return list<int>|WP_Error Upload before mutate; callback must assertOwned and put IDs in canonical data.
	 * order_id=0 is valid before order creation. Receipt uploader is always the request buyer.
	 */
	public function upload( int $request_id, int $order_id, int $actor_id, string $purpose, mixed $files, int $max = 4 ): array|WP_Error {
		if ( $request_id < 1 || $actor_id < 1 || ! in_array( $purpose, self::PURPOSES, true ) || $max < 1 || $max > 4 ) {
			return $this->error( 'scope' );
		}
		$max = self::isReceipt( $purpose ) ? 1 : $max;
		$count = is_array( $files ) && isset( $files['name'] ) ? ( is_array( $files['name'] ) ? count( $files['name'] ) : 1 ) : 0;
		if ( $count < 1 || $count > $max ) {
			return $this->error( 'count' );
		}
		$held = false;
		try {
			$this->lock->acquire( $request_id, 10 );
			$held = true;
			$request = $this->requests->find( $request_id );
			if ( ! $request || ! $this->uploadOwner( $request, $actor_id, $purpose ) || ( $request->wcOrderId() ?? 0 ) !== $order_id ) {
				return $this->error( 'owner' );
			}
			$case = $this->cases->find( $request_id );
			if ( $request->isPaused() || ( $case && ( ( FlowState::isClosed( $case['state'] ) && ! str_starts_with( $purpose, 'finance_' ) ) || $case['data']['pause']['active'] ) ) ) {
				return $this->error( 'closed' );
			}
			$ids = $this->uploader->upload( $order_id, $actor_id, $files, $request_id, $purpose );
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}
			if ( ! $this->assertOwned( $ids, $request_id, $actor_id, $purpose, 1, $max ) ) {
				$this->uploader->delete( $ids );
				return $this->error( 'binding' );
			}
			return $ids;
		} catch ( Throwable ) {
			return $this->error( 'unavailable' );
		} finally {
			if ( $held ) {
				$this->lock->release( $request_id );
			}
		}
	}

	/** Check owner, request, exact purpose, count and current order; invoke inside mutate callback. */
	public function assertOwned( array $ids, int $request_id, int $actor_id, string $purpose, int $min, int $max ): bool {
		if ( ! self::validSelection( $ids, $purpose, $min, $max ) ) {
			return false;
		}
		$request = $this->requests->find( $request_id );
		if ( ! $request || ! $this->uploadOwner( $request, $actor_id, $purpose ) ) {
			return false;
		}
		foreach ( $ids as $id ) {
			if ( ! self::matchesOwner( self::metadata( $id ), $request_id, $actor_id, $purpose, $request->wcOrderId() ?? 0 ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return list<array{id:int,url:string,preview_url?:string}> Authorized nonce-protected controller links, never storage URLs. */
	public function links( array $ids, int $request_id, int $viewer_id ): array {
		if ( count( $ids ) > 20 ) {
			return array();
		}
		$result = array();
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || is_wp_error( $this->authorizeRead( $id, $request_id, $viewer_id ) ) ) {
				continue;
			}
			$arguments = array( 'action' => 'zbm_core_download_managed_evidence', 'request_id' => $request_id, 'attachment_id' => $id );
			$row = array( 'id' => $id, 'url' => wp_nonce_url( add_query_arg( $arguments, admin_url( 'admin-post.php' ) ), 'zbm_core_managed_evidence_' . $request_id . '_' . $id ) );
			$meta = self::metadata( $id );
			if ( ( ! self::isReceipt( $meta['purpose'] ) || AdminCapabilities::userCanManageFinance( $viewer_id ) ) && in_array( $meta['mime'], array( 'image/jpeg', 'image/png', 'image/webp', 'application/octet-stream' ), true ) ) {
				$row['preview_url'] = wp_nonce_url( add_query_arg( array_merge( $arguments, array( 'preview' => 1 ) ), admin_url( 'admin-post.php' ) ), 'zbm_core_managed_evidence_' . $request_id . '_' . $id );
			}
			$result[] = $row;
		}
		return $result;
	}

	/** @return array{contents:string,mime:string,filename:string}|WP_Error Privileged binary result, not public view data. */
	public function read( int $id, int $request_id, int $viewer_id, bool $preview = false ): array|WP_Error {
		$meta = $this->authorizeRead( $id, $request_id, $viewer_id );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		$path = self::privatePath( $id );
		if ( null === $path ) {
			return $this->error( 'path' );
		}
		$contents = $this->crypto->decrypt_file( $path );
		if ( is_wp_error( $contents ) ) {
			return $contents;
		}
		if ( strlen( $contents ) !== $meta['size'] || strlen( $contents ) > 5242880 || ! hash_equals( $meta['sha256'], hash( 'sha256', $contents ) ) ) {
			return $this->error( 'integrity' );
		}

		if ( self::isReceipt( $meta['purpose'] ) ) {
			$mime = self::safeMime( $meta['mime'] );
			if ( $preview ) {
				// Never trust a browser extension or stored MIME to enable inline content.
				$image = @getimagesizefromstring( $contents );
				if ( ! is_array( $image ) || ! in_array( $image['mime'] ?? '', array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
					return $this->error( 'preview_type' );
				}
				$mime = $image['mime'];
			}
			$filename = sanitize_file_name( wp_basename( (string) ( $meta['filename'] ?? '' ) ) );
			if ( '' === $filename ) {
				$extension = array_search( $mime, array( 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf' ), true );
				$filename = 'receipt-' . $request_id . '-' . $id . '.' . ( is_string( $extension ) ? $extension : 'bin' );
			}
			return array( 'contents' => $contents, 'mime' => $mime, 'filename' => substr( $filename, 0, 180 ) );
		}

		$image = @getimagesizefromstring( $contents );
		$extensions = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' );
		if ( ! isset( $extensions[ $meta['mime'] ] ) || ! is_array( $image ) || ( $image['mime'] ?? '' ) !== $meta['mime'] ) {
			return $this->error( 'integrity' );
		}
		return array( 'contents' => $contents, 'mime' => $meta['mime'], 'filename' => 'evidence-' . $request_id . '-' . $id . '.' . $extensions[ $meta['mime'] ] );
	}

	/**
	 * Delete only ciphertext files for this closed, settled request after 30 days (max25 per call).
	 * Retains attachment identity, hash and ownership metadata. Caller separately handles address retention.
	 * @return int|WP_Error Number of files removed; open/review/unresolved cases are preserved.
	 */
	public function cleanup( int $request_id, int $limit = 25 ): int|WP_Error {
		if ( $request_id < 1 ) {
			return $this->error( 'scope' );
		}
		$held = false;
		try {
			$this->lock->acquire( $request_id, 10 );
			$held = true;
			$request = $this->requests->find( $request_id );
			$case = $this->cases->find( $request_id );
			if ( ! $request || ! $case || isset( $case['data']['spec4'] ) || ! FlowState::isClosed( $case['state'] ) || $case['sync_pending'] || $request->needsReconciliation() || $request->isPaused() || $case['data']['pause']['active'] || ! is_string( $case['closed_at_gmt'] ) || $case['closed_at_gmt'] > gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 ) || ! in_array( $case['data']['final']['settlement'], array( 'credited', 'not_required' ), true ) ) {
				return 0;
			}
			$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'private', 'posts_per_page' => max( 1, min( 25, $limit ) ), 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true, 'meta_query' => array( array( 'key' => '_zbm_managed_request_id', 'value' => $request_id ), array( 'key' => '_zbm_evidence_purged_at', 'compare' => 'NOT EXISTS' ) ) ) );
			$removed = 0;
			foreach ( is_array( $ids ) ? $ids : array() as $id ) {
				$id = (int) $id;
				$meta = self::metadata( $id );
				$owner = str_starts_with( $meta['purpose'], 'vendor_' ) ? $request->vendorId() : $request->buyerId();
				if ( ! self::matchesOwner( $meta, $request_id, $owner, $meta['purpose'], $request->wcOrderId() ?? 0 ) ) {
					continue;
				}
				$path = self::privatePath( $id );
				if ( null !== $path ) {
					wp_delete_file( $path );
					clearstatcache( true, $path );
					if ( ! file_exists( $path ) ) {
						update_post_meta( $id, '_zbm_evidence_purged_at', current_time( 'mysql', true ) );
						++$removed;
					}
				}
			}
			return $removed;
		} catch ( Throwable ) {
			return $this->error( 'cleanup' );
		} finally {
			if ( $held ) {
				$this->lock->release( $request_id );
			}
		}
	}

	/**
	 * Remove old upload-stage files that were never atomically bound to a case.
	 * Every candidate is rechecked under its request lock; malformed ownership is
	 * preserved for administrator inspection rather than deleted speculatively.
	 *
	 * @return int|WP_Error Number of orphan attachments removed.
	 */
	public function cleanupOrphans( int $limit = 25, int $grace_seconds = 86400 ): int|WP_Error {
		$limit = max( 1, min( 25, $limit ) );
		$grace_seconds = max( 3600, min( 7 * 86400, $grace_seconds ) );
		try {
			$ids = get_posts( array(
				'post_type' => 'attachment',
				'post_status' => 'private',
				'posts_per_page' => $limit,
				'fields' => 'ids',
				'orderby' => 'ID',
				'order' => 'ASC',
				'no_found_rows' => true,
				'date_query' => array( array( 'column' => 'post_date_gmt', 'before' => gmdate( 'Y-m-d H:i:s', time() - $grace_seconds ), 'inclusive' => true ) ),
				'meta_query' => array(
					'relation' => 'AND',
					array( 'key' => '_zbm_managed_request_id', 'compare' => 'EXISTS' ),
					array( 'key' => '_zbm_managed_purpose', 'compare' => 'EXISTS' ),
					array( 'key' => '_zbm_evidence_private', 'value' => 1 ),
					array( 'key' => '_zbm_evidence_encrypted', 'value' => 1 ),
					array( 'key' => '_zbm_evidence_purged_at', 'compare' => 'NOT EXISTS' ),
				),
			) );
			$removed = 0;
			foreach ( is_array( $ids ) ? $ids : array() as $candidate ) {
				$id = (int) $candidate;
				$meta = self::metadata( $id );
				$request_id = $meta['request_id'];
				if ( $id < 1 || $request_id < 1 || ! in_array( $meta['purpose'], self::PURPOSES, true ) ) {
					continue;
				}
				$held = false;
				try {
					$this->lock->acquire( $request_id, 10 );
					$held = true;
					$request = $this->requests->find( $request_id );
					$case = $this->cases->find( $request_id );
					if ( ! $request ) {
						continue;
					}
					$owner = str_starts_with( $meta['purpose'], 'vendor_' ) ? $request->vendorId() : $request->buyerId();
					if ( ! self::matchesOwner( $meta, $request_id, $owner, $meta['purpose'], $request->wcOrderId() ?? 0 ) || ( is_array( $case ) && $this->isBound( $case, $id, $meta['purpose'] ) ) ) {
						continue;
					}
					$path = self::privatePath( $id );
					if ( null === $path ) {
						continue;
					}
					wp_delete_attachment( $id, true );
					clearstatcache( true, $path );
					if ( ! get_post( $id ) && ! file_exists( $path ) ) {
						++$removed;
					}
				} finally {
					if ( $held ) {
						$this->lock->release( $request_id );
					}
				}
			}
			return $removed;
		} catch ( Throwable ) {
			return $this->error( 'orphan_cleanup' );
		}
	}

	/** Pure count/ID/purpose contract. Receipts always exactly1; all other groups1–4. */
	public static function validSelection( array $ids, string $purpose, int $min, int $max ): bool {
		if ( ! in_array( $purpose, self::PURPOSES, true ) || $min < 1 || $max > 4 || $min > $max || ! array_is_list( $ids ) || count( $ids ) < $min || count( $ids ) > $max || ( self::isReceipt( $purpose ) && count( $ids ) !== 1 ) ) {
			return false;
		}
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				return false;
			}
		}
		return count( array_unique( $ids ) ) === count( $ids );
	}

	/** Pure visibility policy. authorized MUST mean verified membership/capability, not a client flag. */
	public static function canRead( array $meta, int $request_id, int $viewer_id, string $role, bool $authorized ): bool {
		if ( ! $authorized || $viewer_id < 1 || $request_id < 1 || ( $meta['request_id'] ?? null ) !== $request_id || true !== ( $meta['bound'] ?? false ) || ! in_array( $meta['purpose'] ?? '', self::PURPOSES, true ) ) {
			return false;
		}
		if ( self::isReceipt( $meta['purpose'] ) ) {
			return 'finance_admin' === $role || ( 'buyer' === $role && $viewer_id === ( $meta['actor_id'] ?? 0 ) );
		}
		if ( str_starts_with( $meta['purpose'], 'finance_' ) ) { return 'finance_admin' === $role; }
		return in_array( $role, array( 'buyer', 'vendor', 'admin', 'finance_admin' ), true );
	}

	/** Pure immutable-upload scope check. order_id0 uploads remain request-bound when a later order is created. */
	public static function matchesOwner( array $meta, int $request_id, int $actor_id, string $purpose, int $order_id ): bool {
		return $request_id > 0 && $actor_id > 0 && in_array( $purpose, self::PURPOSES, true )
			&& ( $meta['request_id'] ?? null ) === $request_id && ( $meta['actor_id'] ?? null ) === $actor_id
			&& ( $meta['author_id'] ?? null ) === $actor_id && ( $meta['purpose'] ?? null ) === $purpose
			&& true === ( $meta['private'] ?? false ) && true === ( $meta['encrypted'] ?? false )
			&& ( 0 === ( $meta['order_id'] ?? null ) || $order_id === ( $meta['order_id'] ?? null ) )
			&& is_int( $meta['size'] ?? null ) && $meta['size'] > 0 && $meta['size'] <= 5242880
			&& is_string( $meta['sha256'] ?? null ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/', $meta['sha256'] );
	}

	/** @return array{request_id:int,actor_id:int,author_id:int,order_id:int,purpose:string,private:bool,encrypted:bool,mime:string,filename:string,size:int,sha256:string,bound:bool} Immutable upload metadata; bound starts false. */
	public static function metadata( int $id ): array {
		return array( 'request_id' => (int) get_post_meta( $id, '_zbm_managed_request_id', true ), 'actor_id' => (int) get_post_meta( $id, '_zbm_evidence_actor_id', true ), 'author_id' => (int) get_post_field( 'post_author', $id ), 'order_id' => (int) get_post_meta( $id, '_zbm_evidence_order_id', true ), 'purpose' => (string) get_post_meta( $id, '_zbm_managed_purpose', true ), 'private' => 'attachment' === get_post_type( $id ) && 'private' === get_post_status( $id ) && 1 === (int) get_post_meta( $id, '_zbm_evidence_private', true ), 'encrypted' => 1 === (int) get_post_meta( $id, '_zbm_evidence_encrypted', true ), 'mime' => (string) get_post_meta( $id, '_zbm_evidence_original_mime', true ), 'filename' => (string) get_post_meta( $id, '_zbm_evidence_original_name', true ), 'size' => (int) get_post_meta( $id, '_zbm_evidence_original_size', true ), 'sha256' => (string) get_post_meta( $id, '_zbm_evidence_sha256', true ), 'bound' => false );
	}

	/** Verified canonical private-evidence path only; never accepts a caller-supplied path. */
	public static function privatePath( int $id ): ?string {
		$path = get_attached_file( $id, true );
		$uploads = wp_upload_dir( null, false );
		$base = empty( $uploads['basedir'] ) ? false : realpath( (string) $uploads['basedir'] );
		$root = empty( $uploads['basedir'] ) ? false : realpath( (string) $uploads['basedir'] . '/zbm-private-evidence' );
		$real = is_string( $path ) ? realpath( $path ) : false;
		if ( ! is_string( $base ) || ! is_string( $root ) || wp_normalize_path( $root ) !== trailingslashit( wp_normalize_path( $base ) ) . 'zbm-private-evidence' || ! is_string( $real ) || ! is_readable( $real ) ) {
			return null;
		}
		$prefix = trailingslashit( wp_normalize_path( $root ) );
		$normalized = wp_normalize_path( $real );
		if ( ! str_starts_with( $normalized, $prefix ) || 1 !== preg_match( '#\A[0-9]{4}/[0-9]{2}/zbm-[a-f0-9]{32}\.zbm\z#', substr( $normalized, strlen( $prefix ) ) ) ) {
			return null;
		}
		return $real;
	}

	private function uploadOwner( PurchaseRequest $request, int $actor, string $purpose ): bool {
		if ( str_starts_with( $purpose, 'finance_' ) ) { return 'live' === $request->executionMode() && AdminCapabilities::userCanManageFinance( $actor ); }
		if ( ! in_array( $request->executionMode(), array( 'live', 'test' ), true ) || ( self::isReceipt( $purpose ) && 'live' !== $request->executionMode() ) ) {
			return false;
		}
		return str_starts_with( $purpose, 'vendor_' )
			? $actor === $request->vendorId() && ( new DokanVendorEligibility() )->isApprovedVendor( $actor )
			: $actor === $request->buyerId();
	}

	private function authorizeRead( int $id, int $request_id, int $viewer_id ): array|WP_Error {
		if ( min( $id, $request_id, $viewer_id ) < 1 ) {
			return $this->error( 'forbidden' );
		}
		$request = $this->requests->find( $request_id );
		$case = $this->cases->find( $request_id );
		$meta = self::metadata( $id );
		if ( ! $request || ! $case ) {
			return $this->error( 'forbidden' );
		}
		$owner = str_starts_with( $meta['purpose'], 'vendor_' ) ? $request->vendorId() : $request->buyerId();
		if ( str_starts_with( $meta['purpose'], 'finance_' ) ) { $owner = $meta['actor_id']; }
		if ( ! self::matchesOwner( $meta, $request_id, $owner, $meta['purpose'], $request->wcOrderId() ?? 0 ) ) {
			return $this->error( 'forbidden' );
		}
		$role = AdminCapabilities::userCanManageFinance( $viewer_id ) ? 'finance_admin' : ( AdminCapabilities::userCanOperate( $viewer_id ) ? 'admin' : ( $viewer_id === $request->buyerId() ? 'buyer' : ( $viewer_id === $request->vendorId() && ( new DokanVendorEligibility() )->isApprovedVendor( $viewer_id ) ? 'vendor' : '' ) ) );
		$meta['bound'] = $this->isBound( $case, $id, $meta['purpose'] );
		return self::canRead( $meta, $request_id, $viewer_id, $role, '' !== $role ) ? $meta : $this->error( 'forbidden' );
	}

	private function isBound( array $case, int $id, string $purpose ): bool {
		if ( str_starts_with( $purpose, 'finance_' ) ) {
			foreach ( $case['data']['spec4']['evidence'] ?? array() as $entry ) { if ( $id === $entry['id'] && $purpose === $entry['purpose'] ) { return true; } }
			return false;
		}
		if ( self::isReceipt( $purpose ) ) {
			$phase = 'manual_deposit_receipt' === $purpose ? 'deposit' : 'balance';
			$offer = $case['data']['manual'][ $phase ] ?? null;
			if ( ! is_array( $offer ) ) {
				return false;
			}
			if ( in_array( $id, $offer['receipt_ids'] ?? array(), true ) ) {
				return true;
			}
			foreach ( $offer['receipt_history'] ?? array() as $history ) {
				if ( in_array( $id, $history['receipt_ids'] ?? array(), true ) ) {
					return true;
				}
			}
			return false;
		}
		$field = array( 'vendor_weight' => 'vendor_evidence', 'buyer_weight' => 'buyer_evidence', 'buyer_objection' => 'buyer_objection', 'vendor_objection' => 'vendor_objection' )[ $purpose ] ?? '';
		foreach ( $case['data']['lines'] as $line ) {
			if ( in_array( $id, $line[ $field ] ?? array(), true ) ) {
				return true;
			}
		}
		foreach ( $case['data']['operations']['evidence_history'] ?? array() as $entry ) {
			if ( $purpose === $entry['purpose'] && in_array( $id, $entry['evidence'], true ) ) { return true; }
		}
		return false;
	}

	private static function isReceipt( string $purpose ): bool {
		return in_array( $purpose, array( 'manual_deposit_receipt', 'manual_balance_receipt' ), true );
	}

	private static function safeMime( string $mime ): string {
		$mime = strtolower( trim( $mime ) );
		return 1 === preg_match( '/\A[a-z0-9][a-z0-9!#$&^_.+\-]*\/[a-z0-9][a-z0-9!#$&^_.+\-]*\z/i', $mime ) ? $mime : 'application/octet-stream';
	}

	private function error( string $code ): WP_Error {
		return new WP_Error( 'zbm_managed_evidence_' . $code, __( 'مدرک خصوصی در دسترس نیست یا مالک و هدف آن معتبر نیست.', 'zarbalman-core' ) );
	}
}

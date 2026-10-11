<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use wpdb;

/** Serializes vendor shipping mutations, independently of purchase-request locks. */
final class VendorShippingRateLock {
	public function __construct( private readonly wpdb $db ) {}

	public function synchronized( int $vendor_id, callable $operation ): mixed {
		if ( $vendor_id < 1 ) {
			throw new \RuntimeException( 'A valid vendor ID is required for serialization.' );
		}
		// User metadata is shared across multisite blogs: use the network table scope.
		$scope = ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . ( $this->db->base_prefix ?? $this->db->prefix );
		$name = 'zbm-vship:' . substr( hash( 'sha256', $scope ), 0, 24 ) . ':' . $vendor_id;
		$acquired = $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 10 ) );
		if ( 1 !== (int) $acquired ) {
			throw new \RuntimeException( 'Vendor shipping rates are busy; retry the operation.' );
		}
		try {
			return $operation();
		} finally {
			try {
				$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			} catch ( \Throwable ) {
				// A lost database connection also releases its connection-scoped lock.
			}
		}
	}
}

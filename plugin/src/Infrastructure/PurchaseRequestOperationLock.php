<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use RuntimeException;
use Throwable;
use wpdb;

/**
 * Serializes Core database transitions with their WooCommerce side effects.
 *
 * MySQL advisory locks are connection-scoped, so a terminated PHP worker
 * cannot leave a permanent lock behind. The in-process depth counter avoids
 * re-acquiring the same lock when one application service calls another.
 */
final class PurchaseRequestOperationLock implements PurchaseRequestOperationLockPort {
	/** @var array<int,int> */
	private array $depth = array();

	public function __construct( private readonly wpdb $db ) {}

	public function synchronized( int $request_id, callable $operation ): mixed {
		$this->acquire( $request_id );
		try {
			return $operation();
		} finally {
			$this->release( $request_id );
		}
	}

	public function acquire( int $request_id, int $timeout_seconds = 10 ): void {
		if ( $request_id < 1 ) {
			throw new RuntimeException( 'A valid request ID is required for serialization.' );
		}
		if ( isset( $this->depth[ $request_id ] ) ) {
			++$this->depth[ $request_id ];
			return;
		}

		$name = $this->name( $request_id );
		$timeout_seconds = max( 1, min( 30, $timeout_seconds ) );
		$acquired = $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout_seconds ) );
		if ( 1 !== (int) $acquired ) {
			throw new RuntimeException( 'The purchase request is busy.' );
		}
		$this->depth[ $request_id ] = 1;
	}

	public function release( int $request_id ): void {
		if ( ! isset( $this->depth[ $request_id ] ) ) {
			return;
		}
		if ( $this->depth[ $request_id ] > 1 ) {
			--$this->depth[ $request_id ];
			return;
		}
		unset( $this->depth[ $request_id ] );
		try {
			$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name( $request_id ) ) );
		} catch ( Throwable ) {
			// The database connection releases this lock automatically on close.
		}
	}

	private function name( int $request_id ): string {
		$scope = defined( 'DB_NAME' ) ? (string) DB_NAME : $this->db->prefix;
		return 'zbm:' . substr( hash( 'sha256', $scope . '|' . $this->db->prefix ), 0, 36 ) . ':' . $request_id;
	}
}

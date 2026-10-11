<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use RuntimeException;
use Throwable;
use wpdb;

/** Serializes legacy order-workflow actions before evidence files are stored. */
final class WorkflowOperationLock {
	/** @var array<int,int> */
	private array $depth = array();

	public function __construct( private readonly wpdb $db ) {}

	public function synchronized( int $order_id, callable $operation ): mixed {
		$this->acquire( $order_id );
		try {
			return $operation();
		} finally {
			$this->release( $order_id );
		}
	}

	private function acquire( int $order_id ): void {
		if ( $order_id < 1 ) {
			throw new RuntimeException( 'A valid order ID is required for workflow serialization.' );
		}
		if ( isset( $this->depth[ $order_id ] ) ) {
			++$this->depth[ $order_id ];
			return;
		}
		$acquired = $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, %d)', $this->name( $order_id ), 10 ) );
		if ( 1 !== (int) $acquired ) {
			throw new RuntimeException( 'The order workflow is busy.' );
		}
		$this->depth[ $order_id ] = 1;
	}

	private function release( int $order_id ): void {
		if ( ! isset( $this->depth[ $order_id ] ) ) {
			return;
		}
		if ( $this->depth[ $order_id ] > 1 ) {
			--$this->depth[ $order_id ];
			return;
		}
		unset( $this->depth[ $order_id ] );
		try {
			$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name( $order_id ) ) );
		} catch ( Throwable ) {
			// MySQL releases connection-scoped locks when the worker connection closes.
		}
	}

	private function name( int $order_id ): string {
		$scope = defined( 'DB_NAME' ) ? (string) DB_NAME : $this->db->prefix;
		return 'zbm:wf:' . substr( hash( 'sha256', $scope . '|' . $this->db->prefix ), 0, 32 ) . ':' . $order_id;
	}
}

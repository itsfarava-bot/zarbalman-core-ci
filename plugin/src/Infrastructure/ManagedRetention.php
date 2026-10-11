<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use Throwable;
use WP_Error;
use Zarbalman\Core\Domain\ManagedFlow\RetentionPolicy;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;

/** Bounded, auditable removal of private payloads after a final resolved closure. */
final class ManagedRetention {
	public const HOOK = 'zbm_core_cleanup_managed_private_data';
	private const SWEEP_OPTION = 'zbm_core_managed_retention_sweep';
	private const PAGE_SIZE = 10;

	public function __construct(
		private readonly PurchaseRequestRepository $requests,
		private readonly ManagedCaseRepository $cases,
		private readonly ManagedEvidence $evidence
	) {}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensureScheduled' ), 45 );
		add_action( 'action_scheduler_init', array( $this, 'ensureScheduled' ) );
		add_action( 'action_scheduler_ensure_recurring_actions', array( $this, 'ensureScheduled' ) );
	}

	public function ensureScheduled(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK, array(), Scheduler::GROUP ) ) {
				as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, self::HOOK, array(), Scheduler::GROUP, true );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'zbm_every_five_minutes', self::HOOK );
		}
	}

	public function run(): void {
		// Upload staging is independent from closed-case retention and receives its
		// own small budget so it cannot starve final cleanup.
		$this->evidence->cleanupOrphans( 10, DAY_IN_SECONDS );
		try {
			[ $cursor, $through ] = $this->sweep();
			$page = $this->cases->scanClosedIds( $cursor, $through, self::PAGE_SIZE );
			$this->saveSweep( $page['next_cursor'], $through );
			foreach ( $page['ids'] as $request_id ) {
				$this->cleanupCase( $request_id );
			}
		} catch ( Throwable ) {
			// Retain the last durable cursor; private data is safer preserved than guessed away.
		}
	}

	private function cleanupCase( int $request_id ): void {
		try {
			$request = $this->requests->find( $request_id );
			$case = $this->cases->find( $request_id );
		} catch ( Throwable ) {
			return;
		}
		$now = current_time( 'mysql', true );
		if ( ! $request || ! $case || ! $this->eligible( $request, $case, $now ) ) {
			return;
		}
		$removed = $this->evidence->cleanup( $request_id, 25 );
		if ( is_wp_error( $removed ) ) {
			return;
		}
		try {
			$request = $this->requests->find( $request_id );
			$case = $this->cases->find( $request_id );
		} catch ( Throwable ) {
			return;
		}
		if ( ! $request || ! $case || ! $this->eligible( $request, $case, $now ) ) {
			return;
		}
		$needs_private_cleanup = null === $case['data']['retention']['private_cleaned_at'];
		if ( ! $needs_private_cleanup && 0 === $removed ) {
			return;
		}
		$key = $this->stableUuid( implode( '|', array( 'managed-cleanup', $request->publicId(), $case['version'], $removed, $needs_private_cleanup ? 1 : 0 ) ) );
		$this->cases->mutate(
			$request_id,
			$case['version'],
			'system',
			0,
			$key,
			'managed_cleanup',
			array( 'request_id' => $request_id, 'count' => $removed ),
			function ( PurchaseRequest $locked, array $current ) use ( $removed, $now ): array|WP_Error {
				if ( ! $this->eligible( $locked, $current, $now ) ) {
					return new WP_Error( 'zbm_managed_retention_stale', __( 'شرایط پاک‌سازی خصوصی دیگر برقرار نیست.', 'zarbalman-core' ) );
				}
				foreach ( array( 'deposit', 'balance' ) as $phase ) {
					if ( is_array( $current['data']['manual'][ $phase ] ) ) {
						$current['data']['manual'][ $phase ]['account_encrypted'] = '';
					}
				}
				$current['data']['delivery']['hmac'] = null;
				$current['data']['delivery']['encrypted_code'] = null;
				$current['data']['delivery']['code_issued_at'] = null;
				$current['data']['delivery']['address_encrypted'] = null;
				$current['data']['retention']['private_cleaned_at'] ??= $now;
				$current['data']['retention']['evidence_files_purged'] = min( 1000000, $current['data']['retention']['evidence_files_purged'] + $removed );
				$fields = '' === $locked->encryptedDeliveryPayload() ? array() : array( 'delivery_payload_encrypted' => '' );
				return array(
					'case' => $current,
					'request_fields' => $fields,
					'audit' => array( 'count' => $removed ),
					'notifications' => array(),
				);
			}
		);
	}

	private function eligible( PurchaseRequest $request, array $case, string $now ): bool {
		// Financial evidence is retained until an explicit financial-retention policy is approved.
		if ( isset( $case['data']['spec4'] ) ) { return false; }
		return RetentionPolicy::eligible(
			array(
				'state' => $case['state'],
				'closed_at' => $case['closed_at_gmt'],
				'settlement' => $case['data']['final']['settlement'],
				'sync_pending' => 1 === $case['sync_pending'],
				'reconciliation' => $request->needsReconciliation(),
				'paused' => $request->isPaused() || $case['data']['pause']['active'],
			),
			$now
		);
	}

	/** @return array{0:int,1:int} */
	private function sweep(): array {
		$stored = get_option( self::SWEEP_OPTION, array() );
		$cursor = is_array( $stored ) && is_int( $stored['cursor'] ?? null ) ? $stored['cursor'] : 0;
		$through = is_array( $stored ) && is_int( $stored['through'] ?? null ) ? $stored['through'] : 0;
		if ( $cursor < 0 || $through < 0 || $cursor > $through ) {
			$cursor = 0;
			$through = 0;
		}
		if ( $cursor >= $through ) {
			$cursor = 0;
			$through = $this->cases->scanUpperBound();
			$this->saveSweep( $cursor, $through );
		}
		return array( $cursor, $through );
	}

	private function saveSweep( int $cursor, int $through ): void {
		$value = array( 'cursor' => $cursor, 'through' => $through );
		if ( $cursor < 0 || $through < $cursor || ( ! update_option( self::SWEEP_OPTION, $value, false ) && get_option( self::SWEEP_OPTION ) !== $value ) ) {
			throw new \RuntimeException( 'Managed retention progress could not be persisted.' );
		}
	}

	private function stableUuid( string $value ): string {
		$hex = hash( 'sha256', $value );
		$hex[12] = '5';
		$hex[16] = dechex( ( hexdec( $hex[16] ) & 0x3 ) | 0x8 );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}
}

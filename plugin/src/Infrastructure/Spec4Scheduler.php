<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use Throwable;
use wpdb;
use Zarbalman\Core\Application\Spec4FinanceService;
use Zarbalman\Core\Application\BalancePaymentService;

/** Bounded recovery, independent of page views and Woo projection success. */
final class Spec4Scheduler {
	public const TICK = 'zbm_spec4_tick';
	public const SWEEP = 'zbm_spec4_recovery';
	private Schema $schema;
	public function __construct( private readonly wpdb $db, private readonly ManagedCaseRepository $cases, private readonly Spec4FinanceService $finance, private readonly BalancePaymentService $balance ) { $this->schema = new Schema( $db ); }
	public function register(): void {
		add_action( 'zbm_spec4_changed', array( $this, 'changed' ) );
		add_action( self::TICK, array( $this, 'tick' ) );
		add_action( self::SWEEP, array( $this, 'sweep' ) );
		add_action( 'init', array( $this, 'ensure' ), 45 );
		add_action( 'action_scheduler_ensure_recurring_actions', array( $this, 'ensure' ) );
	}
	public function ensure(): void {
		if ( null !== Migrator::financeAvailabilityError() ) { return; }
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::SWEEP, array(), 'zarbalman-core' ) ) { as_schedule_recurring_action( time() + 60, 300, self::SWEEP, array(), 'zarbalman-core', true ); }
		} elseif ( ! wp_next_scheduled( self::SWEEP ) ) { wp_schedule_event( time() + 60, 'zbm_every_five_minutes', self::SWEEP ); }
	}
	public function changed( int $request_id ): void {
		if ( $request_id < 1 || null !== Migrator::financeAvailabilityError() ) { return; }
		try {
			$case = $this->cases->find( $request_id );
			if ( $case && ! isset( $case['data']['spec4'] ) && 'final_weight_locked' === $case['state'] ) { $this->finance->open( $request_id ); }
			$this->schedule( $request_id, time() + 1 );
			$this->deadline( $request_id );
		} catch ( Throwable ) { /* Durable scan retries without changing an already committed operation. */ }
	}
	public function tick( int $request_id ): void {
		if ( $request_id < 1 || null !== Migrator::financeAvailabilityError() ) { return; }
		try {
			$result = $this->finance->tick( $request_id );
			$this->balance->retryVerification( $request_id );
			if ( is_wp_error( $result ) ) {
				$key = 'zbm_spec4_retry_' . $request_id; $attempt = min( 8, (int) get_transient( $key ) + 1 ); set_transient( $key, $attempt, DAY_IN_SECONDS );
				if ( $attempt >= 3 ) { $this->finance->recordCreditIssue( $request_id ); }
				$this->schedule( $request_id, time() + min( 3600, 30 * ( 2 ** $attempt ) ) );
			} else { delete_transient( 'zbm_spec4_retry_' . $request_id ); }
			$this->deadline( $request_id );
		} catch ( Throwable ) { $this->schedule( $request_id, time() + 300 ); }
	}
	public function sweep(): void {
		if ( null !== Migrator::financeAvailabilityError() ) { return; }
		$cursor = max( 0, (int) get_option( 'zbm_spec4_scan_cursor', 0 ) );
		$ids = $this->db->get_col( $this->db->prepare( "SELECT request_id FROM %i WHERE mode = 'live' AND request_id > %d AND state IN ('final_weight_locked','balance_due','admin_review','return_review','completed') AND (state <> 'completed' OR JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.final.settlement')) <> 'credited') ORDER BY request_id ASC LIMIT 25", $this->schema->managed_cases_table(), $cursor ) );
		if ( ! is_array( $ids ) || '' !== $this->db->last_error ) { return; }
		foreach ( $ids as $id ) {
			try {
				$case = $this->cases->find( (int) $id );
				if ( $case && ( isset( $case['data']['spec4'] ) || 'final_weight_locked' === $case['state'] ) ) { $this->schedule( (int) $id, time() + 1 ); }
			} catch ( Throwable ) { /* Keep scanning independent cases. */ }
		}
		update_option( 'zbm_spec4_scan_cursor', count( $ids ) < 25 ? 0 : (int) end( $ids ), false );
	}
	private function deadline( int $id ): void {
		$case = $this->cases->find( $id );
		if ( ! $case || ! in_array( $case['data']['spec4']['substate'] ?? '', array( 'balance_due', 'warning' ), true ) || ! is_string( $case['deadline_gmt'] ) ) { return; }
		$time = strtotime( $case['deadline_gmt'] . ' UTC' );
		if ( false !== $time ) { $this->schedule( $id, max( time() + 1, $time ) ); }
	}
	private function schedule( int $id, int $time ): void {
		// Timestamp in args allows the deadline and bounded recovery job to coexist.
		$args = array( $id, $time );
		if ( function_exists( 'as_schedule_single_action' ) ) { as_schedule_single_action( $time, self::TICK, $args, 'zarbalman-core', true ); }
		elseif ( ! wp_next_scheduled( self::TICK, $args ) ) { wp_schedule_single_event( $time, self::TICK, $args ); }
	}
}

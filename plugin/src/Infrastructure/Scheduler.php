<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use wpdb;

final class Scheduler {
	public const GROUP = 'zarbalman-core';
	public const CLEANUP_HOOK = 'zbm_core_cleanup_audit_data';

	public function __construct(
		private readonly wpdb $db,
		private readonly AuditLogRepository $audit
	) {}

	public function register(): void {
		add_action( 'action_scheduler_init', array( $this, 'ensure_recurring_action' ) );
		add_action( 'action_scheduler_ensure_recurring_actions', array( $this, 'ensure_recurring_action' ) );
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup' ) );
	}

	public function ensure_recurring_action(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::CLEANUP_HOOK, array(), self::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + HOUR_IN_SECONDS,
			DAY_IN_SECONDS,
			self::CLEANUP_HOOK,
			array(),
			self::GROUP,
			true
		);
	}

	public function cleanup(): void {
		$retention_days = (int) apply_filters( 'zbm_core_audit_retention_days', 0 );
		if ( $retention_days > 0 ) {
			$this->audit->purge_older_than( $retention_days );
		}

		$schema = new Schema( $this->db );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$sql    = $this->db->prepare(
			'DELETE FROM %i WHERE status = %s AND updated_at_gmt < %s',
			$schema->idempotency_table(),
			'completed',
			$cutoff
		);
		$this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
	}
}

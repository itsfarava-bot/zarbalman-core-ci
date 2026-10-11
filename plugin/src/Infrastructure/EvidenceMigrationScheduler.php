<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

final class EvidenceMigrationScheduler {
	public const HOOK = 'zbm_core_encrypt_legacy_evidence';
	public const STATUS_OPTION = 'zbm_core_evidence_migration_status';

	public function __construct( private readonly EvidenceLegacyMigrator $migrator ) {}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'action_scheduler_init', array( $this, 'ensure_scheduled' ) );
		add_action( 'action_scheduler_ensure_recurring_actions', array( $this, 'ensure_scheduled' ) );
	}

	public function ensure_scheduled(): void {
		$status = (string) get_option( self::STATUS_OPTION, '' );
		if ( in_array( $status, array( 'complete', 'blocked' ), true ) ) {
			return;
		}
		if ( ! $this->migrator->has_migratable_evidence() ) {
			update_option( self::STATUS_OPTION, $this->migrator->has_legacy_evidence() ? 'blocked' : 'complete', false );
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK, array(), Scheduler::GROUP ) ) {
				as_enqueue_async_action( self::HOOK, array(), Scheduler::GROUP, true );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	public function run(): void {
		$result = $this->migrator->migrate_batch( 20 );
		if ( $result['remaining'] ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK, array(), Scheduler::GROUP, false );
			} elseif ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
			}
			return;
		}

		update_option( self::STATUS_OPTION, $this->migrator->has_legacy_evidence() ? 'blocked' : 'complete', false );
	}
}

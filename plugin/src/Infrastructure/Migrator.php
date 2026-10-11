<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use wpdb;
use Zarbalman\Core\Domain\PurchaseRequest\RequestNumber;

final class Migrator {
	public const FAILURE_OPTION = 'zbm_core_migration_failure';

	public function __construct( private readonly wpdb $db ) {}

	public function maybe_migrate(): void {
		if ( version_compare( (string) get_option( 'zbm_core_db_version', '0' ), (string) ZBM_CORE_DB_VERSION, '>=' ) ) {
			return;
		}

		$this->migrate();
	}

	public function migrate(): void {
		try {
			$this->migrateSchema();
			delete_option( self::FAILURE_OPTION );
		} catch ( \Throwable ) {
			// Existing Plugin calls this during construction without a catch. Keep
			// legacy reads available and never publish a partially applied version.
			update_option( self::FAILURE_OPTION, array( 'code' => 'critical_schema_unavailable', 'target' => (string) ZBM_CORE_DB_VERSION, 'at_gmt' => current_time( 'mysql', true ) ), false );
		}
	}

	/** Task4 must show this error and disable managed writes, not synthesize a workflow. */
	public static function financeAvailabilityError(): ?\WP_Error {
		return null !== self::managedAvailabilityError() || (string) ZBM_CORE_DB_VERSION !== (string) get_option( 'zbm_core_db_version', '0' )
			? new \WP_Error( 'zbm_finance_schema', __( 'ساختار مالی هنوز آماده نیست؛ مدیر باید نتیجهٔ ارتقای افزونه را بررسی کند.', 'zarbalman-core' ) ) : null;
	}

	public static function managedAvailabilityError(): ?\WP_Error {
		if ( get_option( self::FAILURE_OPTION, false ) || version_compare( (string) get_option( 'zbm_core_db_version', '0' ), '10', '<' ) || version_compare( (string) get_option( 'zbm_core_db_version', '0' ), (string) ZBM_CORE_DB_VERSION, '>' ) ) {
			return new \WP_Error( 'zbm_managed_schema_unavailable', __( 'زیرساخت پرونده مدیریت‌شده آماده نیست؛ ثبت عملیات تا تکمیل ارتقای پایگاه داده متوقف است.', 'zarbalman-core' ) );
		}
		return null;
	}

	/** New pricing writes require all DB12 columns verified by migrateSchema(). */
	public static function pricingAvailabilityError(): ?\WP_Error {
		$version = (string) get_option( 'zbm_core_db_version', '0' );
		if ( get_option( self::FAILURE_OPTION, false ) || $version !== (string) ZBM_CORE_DB_VERSION ) {
			return new \WP_Error( 'zbm_pricing_schema_unavailable', __( 'ثبت قیمت شامل حمل تا تکمیل ارتقای پایگاه داده متوقف است.', 'zarbalman-core' ) );
		}
		return null;
	}

	private function migrateSchema(): void {
		if ( version_compare( (string) get_option( 'zbm_core_db_version', '0' ), (string) ZBM_CORE_DB_VERSION, '>' ) ) {
			throw new \RuntimeException( 'Refusing to downgrade the Core database.' );
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$schema = new Schema( $this->db );
		foreach ( $schema->creation_sql() as $sql ) {
			dbDelta( $sql );
		}
		// dbDelta does not guarantee success or transactional engines. Never publish
		// a migration version until every declared column and key actually exists.
		$this->verifySchema( $schema );
		$this->backfillRequestNumbers( $schema );

		$crypto = new EvidenceCrypto();
		if ( $crypto->is_available() && $crypto->ensure_key() ) {
			$evidence_migrator = new EvidenceLegacyMigrator( $crypto );
			$migration = $evidence_migrator->migrate_batch( 20 );
			if ( ! $migration['remaining'] ) {
				update_option(
					EvidenceMigrationScheduler::STATUS_OPTION,
					$evidence_migrator->has_legacy_evidence() ? 'blocked' : 'complete',
					false
				);
			} else {
				delete_option( EvidenceMigrationScheduler::STATUS_OPTION );
			}
		}

		update_option( 'zbm_core_db_version', ZBM_CORE_DB_VERSION, false );
		update_option( 'zbm_core_installed_version', ZBM_CORE_VERSION, false );
	}

	/** Fill the additive display reference without touching the immutable UUID. */
	private function backfillRequestNumbers( Schema $schema ): void {
		$table = $schema->purchase_requests_table();
		$total = (int) $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		if ( '' !== $this->db->last_error ) {
			throw new \RuntimeException( 'Cannot count purchase requests for reference migration.' );
		}
		if ( $total > ( RequestNumber::MAX - RequestNumber::MIN + 1 ) ) {
			throw new \RuntimeException( 'The six-digit request reference space is exhausted.' );
		}

		$iterations = 0;
		while ( true ) {
			if ( ++$iterations > 10000 ) {
				throw new \RuntimeException( 'Request reference migration did not converge.' );
			}
			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT id FROM %i WHERE request_number IS NULL OR request_number = '' OR request_number NOT REGEXP '^[1-9][0-9]{5}$' ORDER BY id ASC LIMIT %d",
					$table,
					100
				),
				ARRAY_A
			);
			if ( '' !== $this->db->last_error ) {
				throw new \RuntimeException( 'Cannot read requests for reference migration.' );
			}
			if ( ! is_array( $rows ) || array() === $rows ) {
				return;
			}

			foreach ( $rows as $row ) {
				$id = absint( $row['id'] ?? 0 );
				if ( $id < 1 ) {
					throw new \RuntimeException( 'Request reference migration returned an invalid ID.' );
				}
				$number = RequestNumber::fromInternalId( $id );
				for ( $attempt = 0; $attempt < 25; $attempt++ ) {
					$occupied = $this->db->get_var( $this->db->prepare( 'SELECT id FROM %i WHERE request_number = %s AND id <> %d LIMIT 1', $table, $number, $id ) );
					if ( '' !== $this->db->last_error ) {
						throw new \RuntimeException( 'Cannot reserve a request reference.' );
					}
					if ( null === $occupied ) {
						break;
					}
					$number = RequestNumber::generate();
					if ( 24 === $attempt ) {
						throw new \RuntimeException( 'Could not reserve a unique request reference.' );
					}
				}
				$updated = $this->db->query(
					$this->db->prepare(
						"UPDATE %i SET request_number = %s WHERE id = %d AND (request_number IS NULL OR request_number = '' OR request_number NOT REGEXP '^[1-9][0-9]{5}$')",
						$table,
						$number,
						$id
					)
				);
				if ( false === $updated ) {
					if ( str_contains( strtolower( (string) $this->db->last_error ), 'request_number' ) && $attempt < 24 ) {
						$number = RequestNumber::generate();
						continue;
					}
					throw new \RuntimeException( 'Could not write a request reference.' );
				}
				break;
			}
		}
	}

	private function verifySchema( Schema $schema ): void {
		foreach ( $schema->creation_sql() as $sql ) {
			if ( 1 !== preg_match( '/CREATE TABLE ([^ ]+) \(/', $sql, $match ) ) {
				throw new \RuntimeException( 'Unrecognized Core schema declaration.' );
			}
			$table = $match[1];
			$status = $this->db->get_row( $this->db->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
			if ( ! is_array( $status ) || 'innodb' !== strtolower( (string) ( $status['Engine'] ?? '' ) ) ) {
				throw new \RuntimeException( 'Core migration requires InnoDB: ' . $table );
			}
			$columns = $this->db->get_results( $this->db->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
			$names = is_array( $columns ) ? array_column( $columns, 'Field' ) : array();
			preg_match_all( '/^\s+([a-z_]+)\s+(?:bigint|tinyint|varchar|char|datetime|longtext)\b/m', $sql, $declared );
			if ( array_diff( $declared[1], $names ) ) {
				throw new \RuntimeException( 'Core migration is missing critical columns: ' . $table );
			}
			$indexes = $this->db->get_results( $this->db->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
			$actual = array();
			foreach ( is_array( $indexes ) ? $indexes : array() as $index ) {
				$actual[ $index['Key_name'] ][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
				if ( 0 === (int) $index['Non_unique'] ) {
					$actual[ $index['Key_name'] ][0] = 'unique';
				}
			}
			preg_match_all( '/(?:PRIMARY KEY\s+\(([^)]+)\)|(UNIQUE )?KEY ([a-z_]+) \(([^)]+)\))/', $sql, $keys, PREG_SET_ORDER );
			foreach ( $keys as $key ) {
				$primary = '' !== $key[1];
				$name = $primary ? 'PRIMARY' : $key[3];
				$unique = $primary || 'UNIQUE ' === $key[2];
				$expected = array_merge( $unique ? array( 'unique' ) : array(), array_map( 'trim', explode( ',', $primary ? $key[1] : $key[4] ) ) );
				$found = $actual[ $name ] ?? array();
				ksort( $found );
				if ( $expected !== array_values( $found ) ) {
					throw new \RuntimeException( 'Core migration is missing a critical index: ' . $table );
				}
			}
		}
	}
}

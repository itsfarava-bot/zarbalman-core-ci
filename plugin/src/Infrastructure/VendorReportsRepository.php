<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use InvalidArgumentException;
use RuntimeException;
use wpdb;

/** Read-only, vendor-scoped aggregate queries for the Dokan reports page. */
final class VendorReportsRepository {
	private Schema $schema;

	public function __construct( private readonly wpdb $db ) {
		$this->schema = new Schema( $db );
	}

	/** Called only during an administrator request; never from a seller's read-only view. */
	public function ensureReportIndexes(): void {
		$this->ensureIndex( $this->schema->purchase_requests_table(), 'vendor_report_created', array( 'vendor_id', 'execution_mode', 'created_at_gmt' ) );
		$this->ensureIndex( $this->schema->payments_table(), 'report_paid_at_gmt', array( 'paid_at_gmt', 'request_id' ) );
	}

	/** @param list<string> $columns */
	private function ensureIndex( string $table, string $index_name, array $columns ): void {
		$show = $this->db->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, $index_name );
		$rows = $this->db->get_results( $show, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		if ( ! is_array( $rows ) || '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Cannot inspect report index.' );
		}
		if ( empty( $rows ) ) {
			// Names and columns are fixed in this class, not derived from user input.
			$alter = 'vendor_report_created' === $index_name
				? 'ALTER TABLE %i ADD INDEX vendor_report_created (vendor_id, execution_mode, created_at_gmt)'
				: 'ALTER TABLE %i ADD INDEX report_paid_at_gmt (paid_at_gmt, request_id)';
			$this->db->query( $this->db->prepare( $alter, $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared table identifier above.
			// Another admin request can win the race; the final schema check is authoritative.
			$rows = $this->db->get_results( $show, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		}
		if ( ! is_array( $rows ) || '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Cannot create report index.' );
		}
		$actual = array();
		foreach ( $rows as $row ) {
			$actual[ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
		}
		ksort( $actual );
		if ( $columns !== array_values( $actual ) ) {
			throw new RuntimeException( 'Report index definition does not match.' );
		}
	}

	/**
	 * @param list<array{date:string,start_gmt:string,end_gmt:string}> $days
	 * @return list<array{date:string,deposit_toman:int,balance_toman:int,request_count:int}>
	 */
	public function dailyBuyerPayments( int $vendor_id, array $days ): array {
		if ( $vendor_id < 1 || empty( $days ) || count( $days ) > 366 || ! array_is_list( $days ) ) {
			throw new InvalidArgumentException( 'Invalid vendor report calendar.' );
		}

		foreach ( $days as $index => $day ) {
			if (
				! isset( $day['date'], $day['start_gmt'], $day['end_gmt'] )
				|| ! $this->validGmt( $day['start_gmt'] )
				|| ! $this->validGmt( $day['end_gmt'] )
				|| $day['start_gmt'] >= $day['end_gmt']
				|| ( $index > 0 && $days[ $index - 1 ]['end_gmt'] !== $day['start_gmt'] )
			) {
				throw new InvalidArgumentException( 'Invalid vendor report day.' );
			}
		}
		$calendar_json = wp_json_encode( $days );
		if ( ! is_string( $calendar_json ) ) {
			throw new RuntimeException( 'Vendor report calendar could not be encoded.' );
		}

		// The supplied local-day GMT boundaries keep DST and Jalali year boundaries exact.
		// Separate daily aggregates prevent payment rows from multiplying request counts.
		$sql = "WITH calendar AS (
				SELECT local_day, starts_at_gmt, ends_at_gmt
				FROM JSON_TABLE(%s, '$[*]' COLUMNS (
					local_day VARCHAR(20) PATH '$.date',
					starts_at_gmt DATETIME PATH '$.start_gmt',
					ends_at_gmt DATETIME PATH '$.end_gmt'
				)) AS report_days
			), request_totals AS (
				SELECT c.local_day, COUNT(r.id) AS request_count
				FROM calendar AS c
				INNER JOIN %i AS r ON r.vendor_id = %d AND r.execution_mode = 'live'
					AND r.created_at_gmt >= c.starts_at_gmt AND r.created_at_gmt < c.ends_at_gmt
				GROUP BY c.local_day
			), payment_totals AS (
				SELECT c.local_day,
					SUM(CASE WHEN p.phase = 'deposit' THEN p.paid_amount_toman ELSE 0 END) AS deposit_toman,
					SUM(CASE WHEN p.phase = 'balance' THEN p.paid_amount_toman ELSE 0 END) AS balance_toman
				FROM calendar AS c
				INNER JOIN %i AS p ON p.paid_at_gmt >= c.starts_at_gmt AND p.paid_at_gmt < c.ends_at_gmt
				INNER JOIN %i AS r ON r.id = p.request_id AND r.vendor_id = %d AND r.execution_mode = 'live'
				WHERE p.status IN ('captured', 'late_captured', 'late_accepted')
					AND p.phase IN ('deposit', 'balance') AND p.paid_amount_toman IS NOT NULL
				GROUP BY c.local_day
			)
			SELECT c.local_day,
				COALESCE(pay.deposit_toman, 0) AS deposit_toman,
				COALESCE(pay.balance_toman, 0) AS balance_toman,
				COALESCE(req.request_count, 0) AS request_count
			FROM calendar AS c
			LEFT JOIN request_totals AS req ON req.local_day = c.local_day
			LEFT JOIN payment_totals AS pay ON pay.local_day = c.local_day
			ORDER BY c.starts_at_gmt";
		$prepared = $this->db->prepare(
			$sql,
			$calendar_json,
			$this->schema->purchase_requests_table(),
			$vendor_id,
			$this->schema->payments_table(),
			$this->schema->purchase_requests_table(),
			$vendor_id
		);
		$rows = $this->db->get_results( $prepared, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- All values and table names were prepared above.
		if ( ! is_array( $rows ) || '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Vendor payment report query failed.' );
		}

		$result = array();
		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) || ! isset( $days[ $index ] ) || (string) ( $row['local_day'] ?? '' ) !== $days[ $index ]['date'] ) {
				throw new RuntimeException( 'Vendor payment report result was invalid.' );
			}
			$result[] = array(
				'date'           => (string) $row['local_day'],
				'deposit_toman'  => max( 0, (int) $row['deposit_toman'] ),
				'balance_toman'  => max( 0, (int) $row['balance_toman'] ),
				'request_count'  => max( 0, (int) $row['request_count'] ),
			);
		}
		if ( count( $result ) !== count( $days ) ) {
			throw new RuntimeException( 'Vendor payment report calendar was incomplete.' );
		}
		return $result;
	}

	/**
	 * Sum gross final sale bases for completed, reconciled seller allocations.
	 * The linked IRT ledger row is checked; this is not the seller's net payout.
	 */
	public function finalSalesToman( int $vendor_id, string $start_gmt, string $end_gmt_exclusive ): int {
		$this->assertRange( $vendor_id, $start_gmt, $end_gmt_exclusive );
		$sql = $this->db->prepare(
			"SELECT COALESCE(SUM(a.base_amount_toman), 0)
			FROM %i AS a
			INNER JOIN %i AS r ON r.id = a.request_id AND r.vendor_id = %d AND r.execution_mode = 'live'
			INNER JOIN %i AS l ON l.id = a.ledger_entry_id
				AND l.request_id = a.request_id AND l.order_id = a.order_id
				AND l.vendor_id = r.vendor_id AND l.owner_type = 'vendor'
				AND l.entry_type = 'sale_vendor_allocation' AND l.currency = 'IRT' AND l.status = 'allocated'
			WHERE a.action_type = 'sale_vendor' AND a.status = 'reconciled' AND a.currency = 'IRT'
				AND a.reconciled_at_gmt >= %s AND a.reconciled_at_gmt < %s",
			$this->schema->financial_actions_table(),
			$this->schema->purchase_requests_table(),
			$vendor_id,
			$this->schema->ledger_table(),
			$start_gmt,
			$end_gmt_exclusive
		);
		$value = $this->db->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above with the repository's fixed schema identifiers.
		if ( '' !== $this->db->last_error || null === $value || ! is_numeric( $value ) ) {
			throw new RuntimeException( 'Vendor final sales report query failed.' );
		}
		return max( 0, (int) $value );
	}

	private function assertRange( int $vendor_id, string $start_gmt, string $end_gmt_exclusive ): void {
		if ( $vendor_id < 1 || ! $this->validGmt( $start_gmt ) || ! $this->validGmt( $end_gmt_exclusive ) || $start_gmt >= $end_gmt_exclusive ) {
			throw new InvalidArgumentException( 'Invalid vendor report range.' );
		}
	}

	private function validGmt( string $value ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value;
	}
}

<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use wpdb;

final class AuditLogRepository {
	private Schema $schema;
	private AuditPayloadSanitizer $sanitizer;

	public function __construct( private readonly wpdb $db ) {
		$this->schema    = new Schema( $db );
		$this->sanitizer = new AuditPayloadSanitizer();
	}

	/**
	 * @param array<string,mixed> $metadata
	 */
	public function append(
		?int $order_id,
		string $event_key,
		?string $from_state,
		?string $to_state,
		string $actor_type,
		int $actor_user_id,
		string $correlation_id,
		array $metadata = array(),
		?string $event_uuid = null
	): string|false {
		return $this->append_resource(
			'order',
			$order_id,
			$order_id,
			$event_key,
			$from_state,
			$to_state,
			$actor_type,
			$actor_user_id,
			$correlation_id,
			$metadata,
			$event_uuid
		);
	}

	/**
	 * @param array<string,mixed> $metadata
	 */
	public function append_resource(
		string $resource_type,
		?int $resource_id,
		?int $order_id,
		string $event_key,
		?string $from_state,
		?string $to_state,
		string $actor_type,
		int $actor_user_id,
		string $correlation_id,
		array $metadata = array(),
		?string $event_uuid = null
	): string|false {
		$event_uuid = $event_uuid ?: wp_generate_uuid4();
		$clean      = $this->sanitizer->sanitize( $metadata );
		$encoded    = $clean ? wp_json_encode( $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null;
		$json       = is_string( $encoded ) ? $encoded : null;

		$result = $this->db->insert(
			$this->schema->audit_table(),
			array(
				'event_uuid'      => $event_uuid,
				'order_id'        => $order_id,
				'resource_type'   => sanitize_key( $resource_type ),
				'resource_id'     => $resource_id,
				'event_key'       => sanitize_key( $event_key ),
				'from_state'      => null === $from_state ? null : sanitize_key( $from_state ),
				'to_state'        => null === $to_state ? null : sanitize_key( $to_state ),
				'actor_type'      => sanitize_key( $actor_type ),
				'actor_user_id'   => max( 0, $actor_user_id ),
				'correlation_id'  => wp_is_uuid( $correlation_id ) ? $correlation_id : wp_generate_uuid4(),
				'metadata_json'   => $json,
				'created_at_gmt'  => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return false === $result ? false : $event_uuid;
	}

	/** @return list<object> */
	public function recent( int $limit = 20 ): array {
		$limit = max( 1, min( 100, $limit ) );
		$sql   = $this->db->prepare(
			'SELECT id, event_uuid, order_id, event_key, from_state, to_state, actor_type, actor_user_id, correlation_id, created_at_gmt
			FROM %i ORDER BY id DESC LIMIT %d',
			$this->schema->audit_table(),
			$limit
		);

		$rows = $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		return is_array( $rows ) ? $rows : array();
	}

	/** @return list<object> */
	public function for_order( int $order_id, int $limit = 100 ): array {
		$limit = max( 1, min( 200, $limit ) );
		$sql   = $this->db->prepare(
			'SELECT id, event_uuid, event_key, from_state, to_state, actor_type, actor_user_id,
				correlation_id, metadata_json, created_at_gmt
			FROM %i WHERE order_id = %d ORDER BY id DESC LIMIT %d',
			$this->schema->audit_table(),
			$order_id,
			$limit
		);
		$rows = $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		return is_array( $rows ) ? $rows : array();
	}

	public function purge_older_than( int $days ): int {
		$days   = max( 30, $days );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
		$sql    = $this->db->prepare(
			'DELETE FROM %i WHERE created_at_gmt < %s',
			$this->schema->audit_table(),
			$cutoff
		);

		$result = $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		return false === $result ? 0 : (int) $result;
	}
}

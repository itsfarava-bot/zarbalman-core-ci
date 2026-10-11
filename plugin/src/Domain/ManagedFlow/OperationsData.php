<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\ManagedFlow;

use InvalidArgumentException;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;

/** Additive operational data. Absent on legacy records; never infer financial facts. */
final class OperationsData {
	public const PRE_DELIVERY = array( FlowState::PREPARING, FlowState::READY_FOR_DELIVERY, FlowState::READY_FOR_PICKUP, FlowState::IN_TRANSIT );

	public static function initial( PurchaseRequest $request, string $bootstrapped_at ): array {
		return array( 'bootstrapped_at' => $bootstrapped_at, 'prepared_at' => null, 'dispatched_at' => null, 'buyer_submitted_at' => null,
			'evidence_history' => array(),
			'window' => array( 'generation' => 1, 'start_gmt' => $request->deliveryStartGmt(), 'end_gmt' => $request->deliveryEndGmt(),
				'reminded_at' => null, 'overdue_at' => null, 'resume_state' => null, 'history' => array() ) );
	}

	public static function validate( array $data, PurchaseRequest $request ): void {
		self::keys( $data, array( 'bootstrapped_at', 'prepared_at', 'dispatched_at', 'buyer_submitted_at', 'evidence_history', 'window' ) );
		if ( ! is_string( $data['bootstrapped_at'] ) ) { throw new InvalidArgumentException( 'Missing operational bootstrap.' ); }
		foreach ( array( 'bootstrapped_at', 'prepared_at', 'dispatched_at', 'buyer_submitted_at' ) as $key ) { self::date( $data[ $key ] ); }
		$window = $data['window'];
		self::keys( $window, array( 'generation', 'start_gmt', 'end_gmt', 'reminded_at', 'overdue_at', 'resume_state', 'history' ) );
		self::integer( $window['generation'], 1, 21 );
		self::dates( $window['start_gmt'], $window['end_gmt'] );
		self::date( $window['reminded_at'] );
		self::date( $window['overdue_at'] );
		if ( null !== $window['resume_state'] && ! in_array( $window['resume_state'], self::PRE_DELIVERY, true ) ) { throw new InvalidArgumentException( 'Invalid delivery recovery stage.' ); }
		if ( ( null === $window['overdue_at'] ) !== ( null === $window['resume_state'] ) ) { throw new InvalidArgumentException( 'Incomplete overdue delivery context.' ); }
		self::boundedList( $window['history'], 20 );
		if ( count( $window['history'] ) !== $window['generation'] - 1 ) { throw new InvalidArgumentException( 'Delivery extension history is incomplete.' ); }
		$start = $request->deliveryStartGmt();
		$end = $request->deliveryEndGmt();
		foreach ( $window['history'] as $entry ) {
			self::keys( $entry, array( 'previous_start', 'previous_end', 'start_gmt', 'end_gmt', 'actor_id', 'at_gmt', 'reason' ) );
			if ( $entry['previous_start'] !== $start || $entry['previous_end'] !== $end ) { throw new InvalidArgumentException( 'Delivery window history chain changed.' ); }
			self::dates( $entry['start_gmt'], $entry['end_gmt'] );
			self::integer( $entry['actor_id'], 1 );
			if ( ! is_string( $entry['at_gmt'] ) ) { throw new InvalidArgumentException( 'Missing extension time.' ); }
			self::date( $entry['at_gmt'] );
			self::text( $entry['reason'], true );
			$start = $entry['start_gmt']; $end = $entry['end_gmt'];
		}
		if ( $window['start_gmt'] !== $start || $window['end_gmt'] !== $end ) { throw new InvalidArgumentException( 'Delivery window lacks an audited extension.' ); }
		self::boundedList( $data['evidence_history'], $request->isMultiline() ? 400 : 80 );
		$request_lines = $request->lines();
		foreach ( $data['evidence_history'] as $entry ) {
			$keys = array( 'purpose', 'weight_tenths', 'evidence', 'reason', 'submitted_at', 'archived_at', 'archived_by' );
			if ( $request->isMultiline() ) { $keys[] = 'line_id'; if ( ! is_string( $entry['line_id'] ?? null ) || ! array_key_exists( $entry['line_id'], $request_lines ) ) { throw new InvalidArgumentException( 'Invalid archived line.' ); } }
			self::keys( $entry, $keys );
			if ( ! in_array( $entry['purpose'], array( 'vendor_weight', 'buyer_weight', 'buyer_objection', 'vendor_objection' ), true ) ) { throw new InvalidArgumentException( 'Invalid evidence archive purpose.' ); }
			if ( null !== $entry['weight_tenths'] ) { self::integer( $entry['weight_tenths'], 1 ); }
			self::boundedList( $entry['evidence'], 4 );
			foreach ( $entry['evidence'] as $id ) { self::integer( $id, 1 ); }
			if ( null === $entry['weight_tenths'] && array() === $entry['evidence'] ) { throw new InvalidArgumentException( 'Empty evidence archive.' ); }
			self::text( $entry['reason'], false );
			self::date( $entry['submitted_at'] );
			if ( ! is_string( $entry['archived_at'] ) ) { throw new InvalidArgumentException( 'Missing archive time.' ); }
			self::date( $entry['archived_at'] );
			self::integer( $entry['archived_by'], 1 );
		}
	}

	public static function isPrefix( array $before, array $after ): bool {
		return count( $after ) >= count( $before ) && array_slice( $after, 0, count( $before ) ) === $before;
	}

	private static function dates( mixed $start, mixed $end ): void {
		if ( ! is_string( $start ) || ! is_string( $end ) || $start >= $end ) { throw new InvalidArgumentException( 'Invalid delivery interval.' ); }
		self::date( $start ); self::date( $end );
	}
	private static function date( mixed $value ): void {
		if ( null !== $value && ! is_string( $value ) ) { throw new InvalidArgumentException( 'Invalid operational datetime.' ); }
		Deadline::timestamp( $value );
	}
	private static function integer( mixed $value, int $minimum, int $maximum = PHP_INT_MAX ): void {
		if ( ! is_int( $value ) || $value < $minimum || $value > $maximum ) { throw new InvalidArgumentException( 'Invalid operational integer.' ); }
	}
	private static function text( mixed $value, bool $required ): void {
		if ( ! is_string( $value ) || strlen( $value ) > 1000 || ( $required && '' === trim( $value ) ) ) { throw new InvalidArgumentException( 'Invalid operational explanation.' ); }
	}
	private static function boundedList( mixed $value, int $maximum ): void {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > $maximum ) { throw new InvalidArgumentException( 'Operational history limit exceeded.' ); }
	}
	private static function keys( mixed $value, array $keys ): void {
		if ( ! is_array( $value ) || count( $value ) !== count( $keys ) || array_diff( $keys, array_keys( $value ) ) ) { throw new InvalidArgumentException( 'Invalid operational record shape.' ); }
	}
}

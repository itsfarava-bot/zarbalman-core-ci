<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Finance;

use InvalidArgumentException;
use Zarbalman\Core\Domain\ManagedFlow\Amounts;
use Zarbalman\Core\Domain\ManagedFlow\FlowState;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;

/** Additive finance state; historic final weights/pricing components are never replaced. */
final class Spec4Data {
	public const FORMAT = 1;
	public const BALANCE_SECONDS = 7200;
	public const WARNING_SECONDS = 1800;
	public const SUBSTATES = array( 'ready', 'balance_due', 'receipt_review', 'contact_due', 'contact_started', 'warning', 'warning_elapsed', 'return_confirmed', 'refund_pending', 'payment_review', 'completed', 'closed' );

	public static function initial( PurchaseRequest $request, array $case, string $now, string $offer_id, string $basis_kind = 'final' ): array {
		$basis = 'final' === $basis_kind ? $case['data']['final']['total'] : $request->estimatedGrossToman();
		if ( ! is_int( $basis ) || $basis < 1 || ! in_array( $basis_kind, array( 'final', 'estimate' ), true ) ) { throw new InvalidArgumentException( 'Missing financial basis.' ); }
		return array(
			'format' => self::FORMAT, 'basis_kind' => $basis_kind, 'basis_toman' => $basis,
			'basis_hash' => self::basisHash( $request, $case, $basis_kind ),
			'opened_at_gmt' => $now, 'offer_id' => $offer_id, 'substate' => 'ready',
			'payment_id' => null, 'remaining_toman' => Amounts::remaining( $basis, $case['data']['funds']['deposit']['amount'], $case['data']['funds']['balance']['amount'] ),
			'contact_history' => array(), 'decision_history' => array(), 'warning_started_at_gmt' => null, 'warning_deadline_gmt' => null,
			'return_confirmed_at_gmt' => null, 'return_evidence_ids' => array(),
			'finance_action_ids' => array(), 'evidence' => array(), 'trigger' => null, 'reconciliation_status' => 'pending',
		);
	}

	public static function basisHash( PurchaseRequest $request, array $case, string $kind ): string {
		if ( $request->isMultiline() ) {
			$weights = array();
			foreach ( $case['data']['lines'] as $key => $line ) { $weights[ $key ] = 'final' === $kind ? $line['final_tenths'] : $line['reference_tenths']; }
			if ( 'final' === $kind && in_array( null, $weights, true ) ) { throw new InvalidArgumentException( 'Every line requires a final weight.' ); }
			return hash( 'sha256', json_encode( array( 'spec4-multiline-v1', $request->publicId(), $request->buyerId(), $request->vendorId(), $request->pricingVersion(), $request->lines(), $kind, $weights,
				'final' === $kind ? $case['data']['final']['total'] : $request->estimatedGrossToman(), 'final' === $kind ? $case['data']['final']['locked_at'] : 'request-estimate-v1', $case['data']['funds']['deposit']['payment_id'], $case['data']['funds']['deposit']['amount'] ), JSON_THROW_ON_ERROR ) );
		}
		$line = reset( $case['data']['lines'] );
		return hash( 'sha256', json_encode( array( 'spec4-v1', $request->publicId(), $request->buyerId(), $request->vendorId(),
			$request->pricingVersion(), $kind, 'final' === $kind ? $case['data']['final']['total'] : $request->estimatedGrossToman(),
			'final' === $kind ? $line['final_tenths'] : $request->referenceWeightTenths(),
			'final' === $kind ? $case['data']['final']['locked_at'] : 'request-estimate-v1',
			$case['data']['funds']['deposit']['payment_id'], $case['data']['funds']['deposit']['amount'] ), JSON_THROW_ON_ERROR ) );
	}

	public static function validate( mixed $value, PurchaseRequest $request, array $case ): void {
		$keys = array( 'format', 'basis_kind', 'basis_toman', 'basis_hash', 'opened_at_gmt', 'offer_id', 'substate', 'payment_id', 'remaining_toman',
			'contact_history', 'decision_history', 'warning_started_at_gmt', 'warning_deadline_gmt', 'return_confirmed_at_gmt', 'return_evidence_ids', 'finance_action_ids', 'evidence', 'trigger', 'reconciliation_status' );
		if ( ! is_array( $value ) || array_diff( array_keys( $value ), $keys ) || array_diff( $keys, array_keys( $value ) )
			|| self::FORMAT !== $value['format'] || 'live' !== $request->executionMode() || ! in_array( $value['basis_kind'], array( 'final', 'estimate' ), true )
			|| ! is_int( $value['basis_toman'] ) || $value['basis_toman'] < 1 || ! is_string( $value['basis_hash'] )
			|| ! hash_equals( self::basisHash( $request, $case, $value['basis_kind'] ), $value['basis_hash'] )
			|| ! is_string( $value['offer_id'] ) || 1 !== preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $value['offer_id'] )
			|| ! in_array( $value['substate'], self::SUBSTATES, true )
			|| ! in_array( $value['trigger'], array( null, 'nonpayment_return', 'seller_incapacity', 'paid_cancellation' ), true )
			|| ! in_array( $value['reconciliation_status'], array( 'pending', 'review', 'reconciled' ), true ) ) { throw new InvalidArgumentException( 'Invalid financial snapshot.' ); }
		$basis = 'final' === $value['basis_kind'] ? $case['data']['final']['total'] : $request->estimatedGrossToman();
		if ( $basis !== $value['basis_toman'] || $value['remaining_toman'] !== Amounts::remaining( $basis, $case['data']['funds']['deposit']['amount'], $case['data']['funds']['balance']['amount'] )
			|| ( null !== $value['payment_id'] && ( ! is_int( $value['payment_id'] ) || $value['payment_id'] < 1 ) ) ) { throw new InvalidArgumentException( 'Financial totals or intent differ.' ); }
		foreach ( array( 'opened_at_gmt', 'warning_started_at_gmt', 'warning_deadline_gmt', 'return_confirmed_at_gmt' ) as $field ) {
			if ( ( 'opened_at_gmt' === $field || null !== $value[ $field ] ) && ! self::validDate( $value[ $field ] ) ) { throw new InvalidArgumentException( 'Invalid financial timestamp.' ); }
		}
		foreach ( array( 'return_evidence_ids', 'finance_action_ids' ) as $field ) {
			if ( ! is_array( $value[ $field ] ) || ! array_is_list( $value[ $field ] ) || count( $value[ $field ] ) > 30 || count( $value[ $field ] ) !== count( array_unique( $value[ $field ] ) ) ) { throw new InvalidArgumentException( 'Invalid financial relations.' ); }
			foreach ( $value[ $field ] as $id ) { if ( ! is_int( $id ) || $id < 1 ) { throw new InvalidArgumentException( 'Invalid financial relation ID.' ); } }
		}
		if ( ! is_array( $value['contact_history'] ) || ! array_is_list( $value['contact_history'] ) || count( $value['contact_history'] ) > 50 ) { throw new InvalidArgumentException( 'Invalid contact history.' ); }
		foreach ( $value['contact_history'] as $entry ) {
			if ( ! is_array( $entry ) || array_keys( $entry ) !== array( 'actor_id', 'at_gmt', 'reason_encrypted' ) || ! is_int( $entry['actor_id'] ) || $entry['actor_id'] < 1
				|| ! self::validDate( $entry['at_gmt'] ) || ! is_string( $entry['reason_encrypted'] ) || '' === $entry['reason_encrypted'] || strlen( $entry['reason_encrypted'] ) > 16000 ) { throw new InvalidArgumentException( 'Invalid contact record.' ); }
		}
		if ( ! is_array( $value['decision_history'] ) || ! array_is_list( $value['decision_history'] ) || count( $value['decision_history'] ) > 200 ) { throw new InvalidArgumentException( 'Invalid financial decision history.' ); }
		foreach ( $value['decision_history'] as $entry ) {
			if ( ! is_array( $entry ) || array_keys( $entry ) !== array( 'actor_id', 'at_gmt', 'action', 'financial_action_id', 'reason_encrypted', 'evidence_ids' ) || ! is_int( $entry['actor_id'] ) || $entry['actor_id'] < 1 || ! self::validDate( $entry['at_gmt'] )
				|| ! in_array( $entry['action'], array( 'contact', 'confirm_return', 'create_refund', 'approve_action', 'execute_action', 'fail_action', 'reconcile_action', 'destination_viewed', 'accept_late', 'reverify_payment' ), true ) || ! is_int( $entry['financial_action_id'] ) || $entry['financial_action_id'] < 0
				|| ! is_string( $entry['reason_encrypted'] ) || '' === $entry['reason_encrypted'] || strlen( $entry['reason_encrypted'] ) > 16000 ) { throw new InvalidArgumentException( 'Invalid financial decision.' ); }
			if ( ! is_array( $entry['evidence_ids'] ) || ! array_is_list( $entry['evidence_ids'] ) || count( $entry['evidence_ids'] ) > 4 ) { throw new InvalidArgumentException( 'Invalid decision evidence.' ); }
			foreach ( $entry['evidence_ids'] as $id ) { if ( ! is_int( $id ) || $id < 1 || ! in_array( $id, array_column( $value['evidence'], 'id' ), true ) ) { throw new InvalidArgumentException( 'Decision evidence must be bound to this case.' ); } }
		}
		if ( ! is_array( $value['evidence'] ) || ! array_is_list( $value['evidence'] ) || count( $value['evidence'] ) > 30 ) { throw new InvalidArgumentException( 'Invalid finance evidence.' ); }
		foreach ( $value['evidence'] as $entry ) {
			if ( ! is_array( $entry ) || array_keys( $entry ) !== array( 'id', 'actor_id', 'purpose' ) || ! is_int( $entry['id'] ) || $entry['id'] < 1 || ! is_int( $entry['actor_id'] ) || $entry['actor_id'] < 1
				|| ! in_array( $entry['purpose'], array( 'finance_return', 'finance_refund', 'finance_execution' ), true ) ) { throw new InvalidArgumentException( 'Invalid finance evidence binding.' ); }
		}
		if ( in_array( $value['substate'], array( 'balance_due', 'receipt_review', 'warning' ), true ) && ( 'final' !== $value['basis_kind'] || $value['remaining_toman'] < 1
			|| null === $case['data']['delivery']['delivered_at'] || FlowState::BALANCE_DUE !== $case['state'] ) ) { throw new InvalidArgumentException( 'Balance requires a delivered final snapshot.' ); }
		if ( 'completed' === $value['substate'] && ( 'final' !== $value['basis_kind'] || null === $case['data']['final']['locked_at'] || null === $case['data']['delivery']['delivered_at'] || null !== $value['trigger'] || FlowState::COMPLETED !== $case['state'] || 0 !== $value['remaining_toman'] ) ) { throw new InvalidArgumentException( 'Completion does not match paid funds.' ); }
		if ( 'warning' === $value['substate'] && ( ! $value['contact_history'] || null === $value['warning_started_at_gmt'] || null === $value['warning_deadline_gmt'] || $value['warning_started_at_gmt'] >= $value['warning_deadline_gmt'] ) ) { throw new InvalidArgumentException( 'Warning requires a recorded contact and deadline.' ); }
		if ( 'nonpayment_return' === $value['trigger'] && ( null === $value['return_confirmed_at_gmt'] || ! $value['return_evidence_ids'] || ! $value['contact_history'] || 0 !== $case['data']['funds']['balance']['amount'] ) ) { throw new InvalidArgumentException( 'Return allocation requires an evidenced unpaid return.' ); }
	}

	public static function validateChanges( ?array $old, array $new ): void {
		if ( null === $old ) { return; }
		foreach ( array( 'format', 'basis_kind', 'basis_toman', 'basis_hash', 'opened_at_gmt', 'offer_id' ) as $field ) {
			if ( $old[ $field ] !== $new[ $field ] ) { throw new InvalidArgumentException( 'Financial basis is immutable.' ); }
		}
		foreach ( array( 'contact_history', 'decision_history', 'return_evidence_ids', 'finance_action_ids', 'evidence' ) as $field ) {
			if ( $old[ $field ] !== array_slice( $new[ $field ], 0, count( $old[ $field ] ) ) ) { throw new InvalidArgumentException( 'Financial history is append-only.' ); }
		}
		foreach ( array( 'return_confirmed_at_gmt', 'trigger' ) as $field ) {
			if ( null !== $old[ $field ] && $old[ $field ] !== $new[ $field ] ) { throw new InvalidArgumentException( 'Financial decision cannot be replaced.' ); }
		}
	}
	private static function validDate( mixed $value ): bool {
		if ( ! is_string( $value ) ) { return false; }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value;
	}
	private function __construct() {}
}

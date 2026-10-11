<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Workflow;

use WC_Order;
use WP_Error;
use Zarbalman\Core\Domain\Order\WeightValue;
use Zarbalman\Core\Domain\Order\WeightUnitPolicy;

final class TransitionPayloadValidator {
	public function __construct( private readonly WeightUnitPolicy $weight_unit ) {}

	/** @return TransitionRequest|WP_Error */
	public function validate( WC_Order $order, TransitionRequest $request ): TransitionRequest|WP_Error {
		return $this->validateInternal( $order, $request, true );
	}

	/** @return TransitionRequest|WP_Error */
	public function validateBeforeEvidence( WC_Order $order, TransitionRequest $request ): TransitionRequest|WP_Error {
		return $this->validateInternal( $order, $request, false );
	}

	/** @return TransitionRequest|WP_Error */
	private function validateInternal( WC_Order $order, TransitionRequest $request, bool $validate_evidence ): TransitionRequest|WP_Error {
		$metadata = is_array( $request->metadata ) ? $request->metadata : array();
		$clean    = array();
		if (
			in_array(
				$request->to_state,
				array( WorkflowDefinition::IN_TRANSIT, WorkflowDefinition::BUYER_WEIGHT_RECORDED, WorkflowDefinition::SELLER_WEIGHT_CONFIRMED ),
				true
			)
			&& ! $this->weight_unit->is_satisfied()
		) {
			return $this->weight_unit->error();
		}

		if ( isset( $metadata['note'] ) ) {
			$note = $this->clean_text( $metadata['note'], 1000 );
			if ( is_wp_error( $note ) ) {
				return $note;
			}
			if ( '' !== $note ) {
				$clean['note'] = $note;
			}
		}

		if ( in_array( $request->to_state, array( WorkflowDefinition::IN_TRANSIT, WorkflowDefinition::BUYER_WEIGHT_RECORDED ), true ) ) {
			$weights = $this->validate_weights( $order, $metadata['weights'] ?? null );
			if ( is_wp_error( $weights ) ) {
				return $weights;
			}
			$clean['weights_tenths'] = $weights;
		}

		if (
			WorkflowDefinition::SELLER_WEIGHT_CONFIRMED === $request->to_state
			&& Actor::ADMIN === $request->actor_type
			&& isset( $metadata['weights'] )
		) {
			$weights = $this->validate_weights( $order, $metadata['weights'] );
			if ( is_wp_error( $weights ) ) {
				return $weights;
			}
			$clean['weights_tenths'] = $weights;
		}

		if ( $this->requires_reason( $request ) ) {
			$reason = $this->required_reason( $metadata['reason'] ?? '' );
			if ( is_wp_error( $reason ) ) {
				return $reason;
			}
			$clean['reason'] = $reason;
		}

		if ( $validate_evidence && $this->is_evidenced_dispute( $request ) ) {
			$evidence = $this->validate_evidence(
				$order->get_id(),
				$request->actor_user_id,
				$metadata['evidence_attachment_ids'] ?? array()
			);
			if ( is_wp_error( $evidence ) ) {
				return $evidence;
			}
			$clean['evidence_attachment_ids'] = $evidence;
			$clean['evidence_hashes'] = array_map(
				static fn ( int $attachment_id ): string => (string) get_post_meta( $attachment_id, '_zbm_evidence_sha256', true ),
				$evidence
			);
		}

		if ( WorkflowDefinition::ADMIN_REVIEW === $request->to_state && Actor::SYSTEM === $request->actor_type ) {
			$timeout_type = sanitize_key( (string) ( $metadata['timeout_type'] ?? '' ) );
			if ( ! in_array( $timeout_type, array( 'buyer_review', 'vendor_weight' ), true ) ) {
				return new WP_Error( 'zbm_invalid_timeout', __( 'نوع پایان مهلت معتبر نیست.', 'zarbalman-core' ) );
			}
			$clean['timeout_type'] = $timeout_type;
			$clean['reason']       = 'buyer_review' === $timeout_type
				? __( 'مهلت بررسی خریدار پایان یافته است.', 'zarbalman-core' )
				: __( 'مهلت پاسخ تأمین‌کننده به وزن خریدار پایان یافته است.', 'zarbalman-core' );
		}

		$clean['contract_version'] = 2;
		return $request->with_metadata( $clean );
	}

	/** @return array<int,int>|WP_Error */
	private function validate_weights( WC_Order $order, mixed $raw_weights ): array|WP_Error {
		if ( ! $this->weight_unit->is_satisfied() ) {
			return $this->weight_unit->error();
		}

		if ( ! is_array( $raw_weights ) ) {
			return new WP_Error( 'zbm_weights_required', __( 'وزن واقعی همه اقلام سفارش لازم است.', 'zarbalman-core' ) );
		}

		$line_item_ids = array_map( 'intval', array_keys( $order->get_items( 'line_item' ) ) );
		if ( array() === $line_item_ids ) {
			return new WP_Error( 'zbm_order_has_no_items', __( 'سفارش قلم قابل وزن‌کشی ندارد.', 'zarbalman-core' ) );
		}
		if ( count( $raw_weights ) !== count( $line_item_ids ) ) {
			return new WP_Error( 'zbm_incomplete_weights', __( 'وزن واقعی باید دقیقاً برای تمام اقلام سفارش ثبت شود.', 'zarbalman-core' ) );
		}

		$normalized = array();
		foreach ( $raw_weights as $item_id => $raw_weight ) {
			$item_id = absint( $item_id );
			if ( ! in_array( $item_id, $line_item_ids, true ) || isset( $normalized[ $item_id ] ) ) {
				return new WP_Error( 'zbm_invalid_order_item', __( 'یکی از اقلام وزن‌کشی متعلق به این سفارش نیست.', 'zarbalman-core' ) );
			}

			$tenths = WeightValue::parse_tenths( $raw_weight );
			if ( is_wp_error( $tenths ) ) {
				return $tenths;
			}
			$normalized[ $item_id ] = $tenths;
		}

		sort( $line_item_ids, SORT_NUMERIC );
		$submitted_ids = array_keys( $normalized );
		sort( $submitted_ids, SORT_NUMERIC );
		if ( $line_item_ids !== $submitted_ids ) {
			return new WP_Error( 'zbm_incomplete_weights', __( 'وزن واقعی باید برای تمام اقلام سفارش ثبت شود.', 'zarbalman-core' ) );
		}

		ksort( $normalized, SORT_NUMERIC );
		return $normalized;
	}

	private function requires_reason( TransitionRequest $request ): bool {
		if ( WorkflowDefinition::ADMIN_REVIEW === $request->to_state && Actor::SYSTEM === $request->actor_type ) {
			return false;
		}
		if ( Actor::ADMIN === $request->actor_type ) {
			return true;
		}

		return in_array(
			$request->to_state,
			array(
				WorkflowDefinition::WEIGHT_DISPUTED,
				WorkflowDefinition::DISPUTE_OPEN,
				WorkflowDefinition::CANCELLATION_REQUESTED,
				WorkflowDefinition::CANCELLED,
				WorkflowDefinition::ADMIN_REVIEW,
			),
			true
		);
	}

	private function is_evidenced_dispute( TransitionRequest $request ): bool {
		return in_array(
			$request->to_state,
			array( WorkflowDefinition::WEIGHT_DISPUTED, WorkflowDefinition::DISPUTE_OPEN ),
			true
		) && Actor::ADMIN !== $request->actor_type;
	}

	/** @return string|WP_Error */
	private function required_reason( mixed $raw ): string|WP_Error {
		$reason = $this->clean_text( $raw, 1000 );
		if ( is_wp_error( $reason ) ) {
			return $reason;
		}
		if ( $this->length( $reason ) < 10 ) {
			return new WP_Error( 'zbm_reason_too_short', __( 'لطفاً دلیل را حداقل در ۱۰ نویسه توضیح دهید.', 'zarbalman-core' ) );
		}
		return $reason;
	}

	/** @return list<int>|WP_Error */
	private function validate_evidence( int $order_id, int $actor_user_id, mixed $raw_ids ): array|WP_Error {
		if ( ! is_array( $raw_ids ) ) {
			$raw_ids = array_filter( array_map( 'trim', explode( ',', (string) $raw_ids ) ) );
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );
		if ( count( $ids ) < 1 || count( $ids ) > 4 ) {
			return new WP_Error( 'zbm_evidence_required', __( 'برای اعتراض، یک تا چهار تصویر مرتبط لازم است.', 'zarbalman-core' ) );
		}

		foreach ( $ids as $attachment_id ) {
			$mime = (string) get_post_meta( $attachment_id, '_zbm_evidence_original_mime', true );
			$sha256 = (string) get_post_meta( $attachment_id, '_zbm_evidence_sha256', true );
			$path = get_attached_file( $attachment_id, true );
			if (
				'attachment' !== get_post_type( $attachment_id )
					|| ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true )
					|| $order_id !== absint( get_post_meta( $attachment_id, '_zbm_evidence_order_id', true ) )
					|| $actor_user_id !== absint( get_post_meta( $attachment_id, '_zbm_evidence_actor_id', true ) )
					|| 1 !== (int) get_post_meta( $attachment_id, '_zbm_evidence_encrypted', true )
					|| ! is_string( $path )
					|| ! is_readable( $path )
					|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha256 )
			) {
				return new WP_Error( 'zbm_invalid_evidence', __( 'یکی از تصاویر مدرک معتبر یا متعلق به این سفارش نیست.', 'zarbalman-core' ) );
			}
		}

		return $ids;
	}

	/** @return string|WP_Error */
	private function clean_text( mixed $raw, int $max_length ): string|WP_Error {
		if ( ! is_scalar( $raw ) ) {
			return new WP_Error( 'zbm_invalid_text', __( 'متن واردشده معتبر نیست.', 'zarbalman-core' ) );
		}
		$value = trim( sanitize_textarea_field( (string) $raw ) );
		if ( $this->length( $value ) > $max_length ) {
			return new WP_Error( 'zbm_text_too_long', __( 'متن واردشده بیش از حد طولانی است.', 'zarbalman-core' ) );
		}
		return $value;
	}

	private function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}
}

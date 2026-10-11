<?php

declare(strict_types=1);

namespace Zarbalman\Core\Http;

use Throwable;
use Zarbalman\Core\Domain\Workflow\AuthorizationPolicy;
use Zarbalman\Core\Domain\Workflow\TransitionRequest;
use Zarbalman\Core\Domain\Workflow\WorkflowControlService;
use Zarbalman\Core\Domain\Workflow\WorkflowDefinition;
use Zarbalman\Core\Domain\Workflow\WorkflowService;
use Zarbalman\Core\Infrastructure\EvidenceUploader;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;
use Zarbalman\Core\Security\AdminCapabilities;
use Zarbalman\Core\Infrastructure\WorkflowOperationLock;

final class WorkflowActionHandler {
	public function __construct(
		private readonly WorkflowService $service,
		private readonly WorkflowControlService $control,
		private readonly AuthorizationPolicy $authorization,
		private readonly EvidenceUploader $uploader,
		private readonly WorkflowOperationLock $operation_lock,
		private readonly PurchaseRequestRepository $requests
	) {}

	public function register(): void {
		add_action( 'admin_post_zbm_core_transition', array( $this, 'transition' ) );
		add_action( 'admin_post_zbm_core_control', array( $this, 'control' ) );
	}

	public function transition(): void {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$this->verify_request( $order_id );

		$order   = wc_get_order( $order_id );
		$user_id = get_current_user_id();
		if ( $order && $this->isManagedOrder( $order ) ) {
			$this->redirect( 'managed_request_only' );
		}
		$actor   = $order ? $this->authorization->actor_type_for( $order, $user_id ) : null;
		if ( ! $order || null === $actor ) {
			$this->redirect( 'forbidden' );
		}

		$to_state = isset( $_POST['to_state'] ) ? sanitize_key( wp_unslash( $_POST['to_state'] ) ) : '';
		$metadata = array(
			'reason' => isset( $_POST['reason'] ) ? wp_unslash( $_POST['reason'] ) : '',
			'note'   => isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : '',
		);
		if ( isset( $_POST['weights'] ) && is_array( $_POST['weights'] ) ) {
			$metadata['weights'] = wp_unslash( $_POST['weights'] );
		}
		$idempotency_key = isset( $_POST['idempotency_key'] ) ? sanitize_text_field( wp_unslash( $_POST['idempotency_key'] ) ) : '';
		$correlation_id = isset( $_POST['correlation_id'] ) ? sanitize_text_field( wp_unslash( $_POST['correlation_id'] ) ) : '';
		$transition_request = new TransitionRequest(
			$order_id,
			$to_state,
			$actor,
			$user_id,
			$idempotency_key,
			$correlation_id,
			$metadata
		);
		try {
			$result_code = $this->operation_lock->synchronized(
				$order_id,
				function () use ( $transition_request, $to_state, $order_id, $user_id, $metadata ): string {
					$uploaded_ids = array();
					try {
						$preflight = $this->service->preflight( $transition_request );
						if ( is_wp_error( $preflight ) ) {
							return $preflight->get_error_code();
						}
						if ( is_array( $preflight ) && ! empty( $preflight['duplicate'] ) ) {
							return 'success';
						}
						$validated_metadata = $metadata;
						if ( in_array( $to_state, array( WorkflowDefinition::WEIGHT_DISPUTED, WorkflowDefinition::DISPUTE_OPEN ), true ) ) {
							$files = $_FILES['zbm_evidence'] ?? array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by EvidenceUploader after the serialized preflight.
							$uploaded = $this->uploader->upload( $order_id, $user_id, $files );
							if ( is_wp_error( $uploaded ) ) {
								return $uploaded->get_error_code();
							}
							$uploaded_ids = $uploaded;
							$validated_metadata['evidence_attachment_ids'] = $uploaded_ids;
						}
						$result = $this->service->transition( $transition_request->with_metadata( $validated_metadata ) );
						if ( is_wp_error( $result ) ) {
							$this->uploader->delete( $uploaded_ids );
							return $result->get_error_code();
						}
						if ( ! empty( $result['duplicate'] ) ) {
							$this->uploader->delete( $uploaded_ids );
						}
						return 'success';
					} catch ( Throwable $error ) {
						$this->uploader->delete( $uploaded_ids );
						throw $error;
					}
				}
			);
		} catch ( Throwable ) {
			$result_code = 'zbm_operation_in_progress';
		}
		$this->redirect( $result_code );
	}

	public function control(): void {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$this->verify_request( $order_id );
		$order = wc_get_order( $order_id );
		if ( $order && $this->isManagedOrder( $order ) ) {
			$this->redirect( 'managed_request_only' );
		}
		if ( ! AdminCapabilities::currentUserCanOperate() ) {
			$this->redirect( 'forbidden' );
		}

		$result = $this->control->set_paused(
			$order_id,
			isset( $_POST['paused'] ) && '1' === (string) $_POST['paused'],
			get_current_user_id(),
			isset( $_POST['reason'] ) ? (string) wp_unslash( $_POST['reason'] ) : '',
			isset( $_POST['correlation_id'] ) ? sanitize_text_field( wp_unslash( $_POST['correlation_id'] ) ) : ''
		);
		$this->redirect( is_wp_error( $result ) ? $result->get_error_code() : 'success' );
	}

	private function verify_request( int $order_id ): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || $order_id < 1 ) {
			wp_die( esc_html__( 'درخواست معتبر نیست.', 'zarbalman-core' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( 'zbm_core_order_' . $order_id );
	}

	private function isManagedOrder( \WC_Order $order ): bool {
		return 'yes' === $order->get_meta( '_zbm_managed_order', true )
			|| 'zarbalman-core-spec3' === $order->get_created_via()
			|| null !== $this->requests->findByOrderId( $order->get_id() );
	}

	private function redirect( string $result ): never {
		$fallback = wc_get_account_endpoint_url( 'orders' );
		$referer  = wp_get_referer();
		$url      = wp_validate_redirect( is_string( $referer ) ? $referer : '', $fallback );
		$url      = remove_query_arg( 'zbm_result', $url );
		wp_safe_redirect( add_query_arg( 'zbm_result', sanitize_key( $result ), $url ) );
		exit;
	}
}

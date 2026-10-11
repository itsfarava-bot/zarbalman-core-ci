<?php

declare(strict_types=1);

namespace Zarbalman\Core\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use Zarbalman\Core\Domain\Workflow\AuthorizationPolicy;
use Zarbalman\Core\Domain\Workflow\TransitionRequest;
use Zarbalman\Core\Domain\Workflow\WorkflowControlService;
use Zarbalman\Core\Domain\Workflow\WorkflowDefinition;
use Zarbalman\Core\Domain\Workflow\WorkflowService;
use Zarbalman\Core\Infrastructure\WorkflowStore;
use Zarbalman\Core\Infrastructure\WorkflowOperationLock;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;
use Zarbalman\Core\Security\AdminCapabilities;

final class WorkflowController {
	private const NAMESPACE = 'zarbalman/v1';

	public function __construct(
		private readonly WorkflowStore $store,
		private readonly WorkflowService $service,
		private readonly WorkflowControlService $control,
		private readonly AuthorizationPolicy $authorization,
		private readonly PurchaseRequestRepository $requests,
		private readonly WorkflowOperationLock $operation_lock
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<id>\d+)/workflow',
			array(
				array(
					'methods' => 'GET',
					'callback' => array( $this, 'get_workflow' ),
					'permission_callback' => array( $this, 'logged_in' ),
				),
				array(
					'methods' => 'POST',
					'callback' => array( $this, 'transition' ),
					'permission_callback' => array( $this, 'logged_in' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<id>\d+)/workflow/control',
			array(
				'methods' => 'POST',
				'callback' => array( $this, 'control' ),
				'permission_callback' => static fn (): bool => AdminCapabilities::currentUserCanOperate(),
			)
		);
	}

	public function logged_in(): bool {
		return is_user_logged_in();
	}

	/** @return WP_REST_Response|WP_Error */
	public function get_workflow( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$order_id = absint( $request['id'] );
		$order    = wc_get_order( $order_id );
		$user_id  = get_current_user_id();
		if ( ! $order || $this->isManagedOrder( $order ) || ! $this->authorization->can_view( $order, $user_id ) ) {
			return $this->not_found();
		}

		$snapshot = $this->store->snapshot( $order_id );
		if ( ! AdminCapabilities::userCanOperate( $user_id ) ) {
			unset( $snapshot['pause_reason'] );
		}
		if (
			(int) $order->get_customer_id() === $user_id
			&& in_array(
				$snapshot['state'],
				array(
					WorkflowDefinition::CREATED,
					WorkflowDefinition::DEPOSIT_PENDING,
					WorkflowDefinition::DEPOSIT_PAID,
					WorkflowDefinition::SUPPLIER_REVIEW,
					WorkflowDefinition::PREPARING,
					WorkflowDefinition::IN_TRANSIT,
					WorkflowDefinition::DELIVERED,
				),
				true
			)
		) {
			foreach ( $snapshot['weights'] as &$weight ) {
				$weight['vendor_weight_tenths'] = null;
			}
			unset( $weight );
		}
		return rest_ensure_response( $snapshot );
	}

	/** @return WP_REST_Response|WP_Error */
	public function transition( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$order_id = absint( $request['id'] );
		$order    = wc_get_order( $order_id );
		$user_id  = get_current_user_id();
		if ( ! $order || $this->isManagedOrder( $order ) ) {
			return $this->not_found();
		}

		$actor = $this->authorization->actor_type_for( $order, $user_id );
		if ( null === $actor ) {
			return $this->not_found();
		}

		$metadata = $request->get_param( 'metadata' );
		try {
			$result = $this->operation_lock->synchronized(
				$order_id,
				fn () => $this->service->transition(
					new TransitionRequest(
						$order_id,
						sanitize_key( (string) $request->get_param( 'to_state' ) ),
						$actor,
						$user_id,
						(string) $request->get_param( 'idempotency_key' ),
						$this->correlation_id( $request ),
						is_array( $metadata ) ? $metadata : array()
					)
				)
			);
		} catch ( \Throwable ) {
			$result = new WP_Error( 'zbm_workflow_busy', __( 'گردش سفارش هم‌زمان در حال پردازش است.', 'zarbalman-core' ) );
		}

		return is_wp_error( $result ) ? $this->with_http_status( $result ) : rest_ensure_response( $result );
	}

	/** @return WP_REST_Response|WP_Error */
	public function control( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order || $this->isManagedOrder( $order ) ) {
			return $this->not_found();
		}
		$result = $this->control->set_paused(
			absint( $request['id'] ),
			rest_sanitize_boolean( $request->get_param( 'paused' ) ),
			get_current_user_id(),
			(string) $request->get_param( 'reason' ),
			$this->correlation_id( $request )
		);
		return is_wp_error( $result ) ? $this->with_http_status( $result ) : rest_ensure_response( $result );
	}

	private function correlation_id( WP_REST_Request $request ): string {
		$value = (string) $request->get_param( 'correlation_id' );
		return wp_is_uuid( $value ) ? $value : wp_generate_uuid4();
	}

	private function isManagedOrder( \WC_Order $order ): bool {
		return 'yes' === $order->get_meta( '_zbm_managed_order', true )
			|| 'zarbalman-core-spec3' === $order->get_created_via()
			|| null !== $this->requests->findByOrderId( $order->get_id() );
	}

	private function with_http_status( WP_Error $error ): WP_Error {
		$data = $error->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			return $error;
		}

		$code = $error->get_error_code();
		if ( str_contains( $code, 'forbidden' ) ) {
			$status = 403;
		} elseif ( str_contains( $code, 'not_found' ) ) {
			$status = 404;
		} elseif (
			str_contains( $code, 'paused' )
			|| str_contains( $code, 'deadline' )
			|| str_contains( $code, 'stale' )
			|| str_contains( $code, 'idempotency' )
			|| str_contains( $code, 'in_progress' )
			|| str_contains( $code, 'terminal' )
		) {
			$status = 409;
		} else {
			$status = 422;
		}
		$error->add_data( array( 'status' => $status ), $code );
		return $error;
	}

	private function not_found(): WP_Error {
		return new WP_Error(
			'zbm_resource_not_found',
			__( 'منبع در دسترس نیست.', 'zarbalman-core' ),
			array( 'status' => 404 )
		);
	}
}

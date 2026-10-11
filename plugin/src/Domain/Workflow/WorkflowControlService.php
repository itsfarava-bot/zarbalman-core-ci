<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Workflow;

use WP_Error;
use Zarbalman\Core\Security\AdminCapabilities;
use Zarbalman\Core\Infrastructure\WorkflowStore;

final class WorkflowControlService {
	public function __construct( private readonly WorkflowStore $store ) {}

	/** @return array{duplicate:bool,is_paused:bool,version:int,event_uuid?:string}|WP_Error */
	public function set_paused( int $order_id, bool $paused, int $actor_user_id, string $reason, string $correlation_id ): array|WP_Error {
		if ( $order_id < 1 || ! wc_get_order( $order_id ) ) {
			return new WP_Error( 'zbm_order_not_found', __( 'سفارش پیدا نشد.', 'zarbalman-core' ) );
		}
		if ( ! AdminCapabilities::userCanOperate( $actor_user_id ) ) {
			return new WP_Error( 'zbm_admin_forbidden', __( 'کاربر اجازه مدیریت سفارش‌ها را ندارد.', 'zarbalman-core' ) );
		}
		if ( ! wp_is_uuid( $correlation_id ) ) {
			return new WP_Error( 'zbm_invalid_correlation_id', __( 'شناسه رهگیری معتبر نیست.', 'zarbalman-core' ) );
		}

		$reason = trim( sanitize_textarea_field( $reason ) );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $reason, 'UTF-8' ) : strlen( $reason );
		if ( $length < 10 || $length > 1000 ) {
			return new WP_Error( 'zbm_invalid_pause_reason', __( 'دلیل مدیریت باید بین ۱۰ تا ۱۰۰۰ نویسه باشد.', 'zarbalman-core' ) );
		}

		$state = $this->store->current_state( $order_id );
		if ( in_array( $state, array( WorkflowDefinition::COMPLETED, WorkflowDefinition::CANCELLED ), true ) ) {
			return new WP_Error( 'zbm_terminal_workflow', __( 'سفارش نهایی‌شده قابل توقف یا بازگشایی نیست.', 'zarbalman-core' ) );
		}

		$result = $this->store->set_paused( $order_id, $paused, $actor_user_id, $reason, $correlation_id );
		if ( is_array( $result ) && ! $result['duplicate'] ) {
			do_action( 'zbm_core_workflow_controlled', $result, $order_id, $paused );
		}
		return $result;
	}
}

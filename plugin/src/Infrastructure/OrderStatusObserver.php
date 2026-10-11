<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use WC_Order;
use Zarbalman\Core\Security\AdminCapabilities;

final class OrderStatusObserver {
	public function __construct( private readonly AuditLogRepository $audit ) {}

	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'record' ), 20, 4 );
	}

	public function record( int $order_id, string $from, string $to, WC_Order $order ): void {
		$user_id = get_current_user_id();
		$type    = 0 === $user_id ? 'system' : ( AdminCapabilities::userCanOperate( $user_id ) ? 'admin' : 'user' );

		$this->audit->append(
			$order_id,
			'woocommerce_status_changed',
			$from,
			$to,
			$type,
			$user_id,
			wp_generate_uuid4(),
			array( 'source' => 'woocommerce' )
		);
	}
}

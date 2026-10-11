<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use RuntimeException;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;

final class DokanManagedOrderGuard {
	public function __construct(
		private readonly WooCommerceOrderAdapter $orders,
		private readonly PurchaseRequestRepository $requests,
		private readonly DokanManualSettlementAdapter $manual_settlements
	) {}

	public function register(): void {
		add_action( 'woocommerce_before_order_object_save', array( $this, 'guardStatusBeforeSave' ), PHP_INT_MIN, 2 );
		add_action( 'woocommerce_before_order_object_save', array( $this, 'guardStatusBeforeSave' ), PHP_INT_MAX, 2 );
		add_action( 'woocommerce_before_order_item_object_save', array( $this, 'preventManagedOrderItemMutation' ), PHP_INT_MIN, 2 );
		add_action( 'woocommerce_before_order_item_object_save', array( $this, 'preventManagedOrderItemMutation' ), PHP_INT_MAX, 2 );
		add_action( 'woocommerce_before_trash_order', array( $this, 'preventManagedOrderDeletion' ), PHP_INT_MIN, 2 );
		add_action( 'woocommerce_before_delete_order', array( $this, 'preventManagedOrderDeletion' ), PHP_INT_MIN, 2 );
		add_action( 'woocommerce_before_delete_order_item', array( $this, 'preventManagedOrderItemDeletion' ), PHP_INT_MIN, 1 );
		foreach ( array( 'order', 'post' ) as $meta_type ) {
			add_filter( "add_{$meta_type}_metadata", array( $this, 'guardOrderMetadataWrite' ), PHP_INT_MIN, 5 );
			add_filter( "update_{$meta_type}_metadata", array( $this, 'guardOrderMetadataWrite' ), PHP_INT_MIN, 5 );
			add_filter( "delete_{$meta_type}_metadata", array( $this, 'guardOrderMetadataWrite' ), PHP_INT_MIN, 5 );
		}
		add_filter( 'add_order_item_metadata', array( $this, 'guardOrderItemMetadataWrite' ), PHP_INT_MIN, 5 );
		add_filter( 'update_order_item_metadata', array( $this, 'guardOrderItemMetadataWrite' ), PHP_INT_MIN, 5 );
		add_filter( 'delete_order_item_metadata', array( $this, 'guardOrderItemMetadataWrite' ), PHP_INT_MIN, 5 );
		add_filter( 'rest_pre_dispatch', array( $this, 'blockManagedDokanRest' ), PHP_INT_MIN, 3 );
		add_filter( 'dokan_order_net_amount', array( $this, 'zeroEarlyDokanEarning' ), PHP_INT_MAX, 2 );
		add_filter( 'dokan_get_seller_amount_from_order', array( $this, 'zeroEarlyDokanEarning' ), PHP_INT_MAX, 2 );
		add_filter( 'dokan_get_vendor_orders_args', array( $this, 'excludeManagedVendorOrders' ), PHP_INT_MAX, 3 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'preventAdminDokanSync' ), 1 );
		add_action( 'woocommerce_update_order', array( $this, 'removeAutomaticDokanEarning' ), PHP_INT_MAX, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'removeAutomaticDokanEarningAfterStatus' ), PHP_INT_MAX, 4 );
		add_action( 'template_redirect', array( $this, 'redirectNativeVendorDetail' ), 0 );
	}

	public function isManagedOrder( int|WC_Order $order ): bool {
		$order = is_int( $order ) ? wc_get_order( $order ) : $order;
		return $order instanceof WC_Order
			&& (
				'yes' === $order->get_meta( '_zbm_managed_order', true )
				|| 'zarbalman-core-spec3' === $order->get_created_via()
				|| ( $order->get_id() > 0 && null !== $this->requests->findByOrderId( $order->get_id() ) )
			);
	}

	public function guardStatusBeforeSave( mixed $order, mixed $data_store ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $order instanceof WC_Order || ! $this->isManagedOrder( $order ) ) {
			return;
		}
		$request = $this->requestForOrder( $order );
		if ( $this->orders->isInternalSnapshotChange( $order->get_id() ) && ! $request ) {
			return;
		}
		$internal = $this->orders->isInternalStatusChange( $order->get_id() );
		$authoritative = (string) $order->get_meta( '_zbm_authoritative_wc_status', true );
		if ( '' === $authoritative ) {
			if ( ! $internal && $request ) {
				throw new RuntimeException( 'Removing the authoritative managed-order status is forbidden.' );
			}
			$order->update_meta_data( '_zbm_authoritative_wc_status', $order->get_status() );
		}
		if ( $order->get_status() !== $authoritative ) {
			if ( $internal ) {
				$order->update_meta_data( '_zbm_authoritative_wc_status', $order->get_status() );
				$authoritative = $order->get_status();
			} else {
				$order->set_status( $authoritative );
				$order->add_order_note( __( 'تغییر مستقیم وضعیت سفارش مدیریت‌شده توسط Core مسدود شد.', 'zarbalman-core' ) );
			}
		}
		// Woo calls this hook before saving staged line items. Only Core's
		// order-scoped snapshot write may temporarily lack a persisted item;
		// status checks above and the adapter's post-save verification still run.
		if ( $request && ! $this->orders->isInternalSnapshotChange( $order->get_id() ) && ! ManagedOrderSnapshot::matches( $order, $request ) ) {
			throw new RuntimeException( 'Direct mutation of a managed order snapshot is forbidden.' );
		}
	}

	public function preventManagedOrderItemMutation( mixed $item, mixed $data_store ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WooCommerce hook contract.
		if ( ! $item instanceof \WC_Order_Item ) {
			return;
		}
		$order_id = (int) $item->get_order_id();
		if ( $this->orders->isInternalSnapshotChange( $order_id ) ) {
			return;
		}
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		$request = $order instanceof WC_Order ? $this->requestForOrder( $order ) : null;
		if (
			$this->orders->isInternalStockChange( $order_id )
			&& $item instanceof \WC_Order_Item_Product
			&& $request
			&& array() === $item->get_changes()
			&& ManagedOrderSnapshot::itemMatches( $item, $request )
		) {
			return;
		}
		if ( $order_id > 0 && $this->isManagedOrder( $order_id ) && $request ) {
			throw new RuntimeException( 'Direct mutation of a managed order item is forbidden.' );
		}
	}

	public function guardOrderMetadataWrite( mixed $check, int $object_id, string $meta_key, mixed $meta_value = null, mixed $extra = null ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress metadata filter contracts vary by operation.
		if ( null !== $check ) {
			return $check;
		}
		$payment_identity_keys = array( '_zarinpal_authority', '_zarinpal_authority_history', '_zarinpal_fee_data' );
		if ( ! str_starts_with( $meta_key, '_zbm_' ) && ! in_array( $meta_key, array_merge( array( '_order_stock_reduced', '_dokan_vendor_id' ), $payment_identity_keys ), true ) ) {
			return null;
		}
		if ( $this->orders->isInternalSnapshotChange( $object_id ) || $this->orders->isInternalDeletion( $object_id ) ) {
			return $check;
		}
		if ( $this->orders->isInternalStatusChange( $object_id ) && '_zbm_authoritative_wc_status' === $meta_key ) {
			return $check;
		}
		if ( $this->orders->isInternalStockChange( $object_id ) && '_order_stock_reduced' === $meta_key ) {
			return $check;
		}
		if ( $this->orders->isInternalControlChange( $object_id ) && in_array( $meta_key, array( '_zbm_stock_released', '_zbm_payment_enabled', '_zbm_execution_mode', '_zbm_test_advance' ), true ) ) {
			return $check;
		}
		if ( $this->orders->isInternalPaymentIdentityChange( $object_id ) && in_array( $meta_key, $payment_identity_keys, true ) ) {
			return $check;
		}
		return $this->isManagedOrder( $object_id ) ? false : null;
	}

	public function guardOrderItemMetadataWrite( mixed $check, int $item_id, string $meta_key, mixed $meta_value = null, mixed $extra = null ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress metadata filter contracts vary by operation.
		if ( null !== $check ) {
			return $check;
		}
		$item = \WC_Order_Factory::get_order_item( $item_id );
		if ( ! $item instanceof \WC_Order_Item ) {
			return null;
		}
		$order_id = (int) $item->get_order_id();
		if ( $this->orders->isInternalSnapshotChange( $order_id ) || $this->orders->isInternalDeletion( $order_id ) ) {
			return $check;
		}
		if ( $this->orders->isInternalStockChange( $order_id ) && '_reduced_stock' === $meta_key ) {
			return $check;
		}
		return $order_id > 0 && $this->isManagedOrder( $order_id ) ? false : null;
	}

	public function blockManagedDokanRest( mixed $result, mixed $server, WP_REST_Request $request ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$route = $request->get_route();
		$is_dokan_order = 1 === preg_match( '#\A/dokan/v[12]/orders(?:/|\z)#', $route );
		$is_mutation = ! in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true );
		$is_dokan_order_mutation = $is_mutation && $is_dokan_order;
		$is_wc_order_mutation = $is_mutation
			&& 1 === preg_match( '#\A/wc/v[1-3]/orders(?:/|\z)#', $route );
		if ( ! $is_dokan_order && ! $is_wc_order_mutation ) {
			return $result;
		}
		$order_ids = array();
		if ( $request->get_param( 'id' ) ) {
			$order_ids[] = absint( $request->get_param( 'id' ) );
		}
		foreach ( (array) $request->get_param( 'order_ids' ) as $order_id ) {
			$order_ids[] = absint( $order_id );
		}
		foreach ( (array) $request->get_param( 'delete' ) as $order_id ) {
			$order_ids[] = absint( $order_id );
		}
		foreach ( (array) $request->get_param( 'update' ) as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) ) {
				$order_ids[] = absint( $row['id'] );
			}
		}
		foreach ( array_unique( array_filter( $order_ids ) ) as $order_id ) {
			if ( $this->isManagedOrder( $order_id ) ) {
				return new WP_Error( 'zbm_managed_order_not_found', __( 'سفارش در دسترس این مسیر نیست.', 'zarbalman-core' ), array( 'status' => 404 ) );
			}
		}
		if ( ( $is_dokan_order_mutation || $is_wc_order_mutation ) && ( $this->containsReservedMeta( $request->get_json_params() ) || $this->containsReservedMeta( $request->get_body_params() ) ) ) {
			return new WP_Error( 'zbm_reserved_order_meta', __( 'نوشتن نشانگرهای داخلی زربال‌من از API مجاز نیست.', 'zarbalman-core' ), array( 'status' => 403 ) );
		}
		return $result;
	}

	public function preventManagedOrderDeletion( int $order_id, WC_Order $order ): void {
		if (
			$this->isManagedOrder( $order )
			&& $this->requestForOrder( $order )
			&& ! $this->orders->isInternalDeletion( $order_id )
		) {
			throw new RuntimeException( 'Deleting or trashing a managed order is forbidden.' );
		}
	}

	public function preventManagedOrderItemDeletion( int $item_id ): void {
		$item = \WC_Order_Factory::get_order_item( $item_id );
		if ( ! $item instanceof \WC_Order_Item ) {
			return;
		}
		$order_id = (int) $item->get_order_id();
		if ( $this->orders->isInternalDeletion( $order_id ) ) {
			return;
		}
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( $order instanceof WC_Order && $this->isManagedOrder( $order ) && $this->requestForOrder( $order ) ) {
			throw new RuntimeException( 'Deleting an item from a managed order is forbidden.' );
		}
	}

	private function containsReservedMeta( mixed $payload ): bool {
		if ( ! is_array( $payload ) ) {
			return false;
		}
		foreach ( $payload as $key => $value ) {
			if ( is_string( $key ) && ( str_starts_with( $key, '_zbm_' ) || in_array( $key, array( '_dokan_vendor_id', '_zarinpal_authority', '_zarinpal_authority_history', '_zarinpal_fee_data' ), true ) ) ) {
				return true;
			}
			if ( 'key' === $key && is_string( $value ) && ( str_starts_with( $value, '_zbm_' ) || in_array( $value, array( '_dokan_vendor_id', '_zarinpal_authority', '_zarinpal_authority_history', '_zarinpal_fee_data' ), true ) ) ) {
				return true;
			}
			if ( is_array( $value ) && $this->containsReservedMeta( $value ) ) {
				return true;
			}
		}
		return false;
	}

	private function requestForOrder( WC_Order $order ): ?\Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest {
		$request = $order->get_id() > 0 ? $this->requests->findByOrderId( $order->get_id() ) : null;
		if ( $request ) {
			return $request;
		}
		$request_id = absint( $order->get_meta( '_zbm_request_id', true ) );
		return $request_id > 0 ? $this->requests->find( $request_id ) : null;
	}

	public function zeroEarlyDokanEarning( mixed $amount, mixed $order ): mixed {
		return ( $order instanceof WC_Order || is_int( $order ) ) && $this->isManagedOrder( $order ) ? 0 : $amount;
	}

	/** @param array<string,mixed> $args
	 *  @return array<string,mixed>
	 */
	public function excludeManagedVendorOrders( array $args, mixed $original_args = array(), mixed $context = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Dokan's own paginated vendor query.
		$meta_query[] = array(
			'relation' => 'OR',
			array( 'key' => '_zbm_managed_order', 'compare' => 'NOT EXISTS' ),
			array( 'key' => '_zbm_managed_order', 'value' => 'yes', 'compare' => '!=' ),
		);
		$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- See above.
		return $args;
	}

	public function removeAutomaticDokanEarning( int $order_id, mixed $order ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $this->isManagedOrder( $order ) ) {
			return;
		}
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fixed Dokan compatibility guard for the pinned version.
			$wpdb->prefix . 'dokan_orders',
			array( 'net_amount' => 0 ),
			array( 'order_id' => $order_id ),
			array( '%d' ),
			array( '%d' )
		);
		$allowed = $this->manual_settlements->authorizedBalanceIds( $order_id );
		if ( array() === $allowed ) {
			$sql = $wpdb->prepare( "UPDATE {$wpdb->prefix}dokan_vendor_balance SET debit = 0 WHERE trn_id = %d AND trn_type = 'dokan_orders'", $order_id );
		} else {
			$placeholders = implode( ',', array_fill( 0, count( $allowed ), '%d' ) );
			$sql = $wpdb->prepare( "UPDATE {$wpdb->prefix}dokan_vendor_balance SET debit = 0 WHERE trn_id = %d AND trn_type = 'dokan_orders' AND id NOT IN ({$placeholders})", $order_id, ...$allowed );
		}
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders contain only verified integer IDs.
	}

	public function removeAutomaticDokanEarningAfterStatus( int $order_id, string $old_status, string $new_status, WC_Order $order ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->removeAutomaticDokanEarning( $order_id, $order );
	}

	public function redirectNativeVendorDetail(): void {
		if ( false === get_query_var( 'orders', false ) || ! isset( $_GET['order_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route interception.
			return;
		}
		$order_id = absint( wp_unslash( $_GET['order_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order || ! $this->isManagedOrder( $order ) ) {
			return;
		}
		$vendor_id = get_current_user_id();
		if ( $vendor_id < 1 || (int) $order->get_meta( '_zbm_vendor_id', true ) !== $vendor_id ) {
			status_header( 404 );
			wp_die( esc_html__( 'سفارش در دسترس نیست.', 'zarbalman-core' ), '', array( 'response' => 404 ) );
		}
		$request_id = absint( $order->get_meta( '_zbm_request_id', true ) );
		$url = function_exists( 'dokan_get_navigation_url' )
			? dokan_get_navigation_url( 'purchase-requests' )
			: home_url( '/approval-by-supplier/' );
		wp_safe_redirect( $request_id > 0 ? add_query_arg( 'request', $request_id, $url ) : $url );
		exit;
	}

	public function preventAdminDokanSync( int $order_id ): void {
		if ( $this->isManagedOrder( $order_id ) ) {
			remove_action( 'woocommerce_process_shop_order_meta', 'dokan_sync_insert_order', 60 );
		}
	}
}

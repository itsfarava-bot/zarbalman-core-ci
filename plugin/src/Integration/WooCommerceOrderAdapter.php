<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use RuntimeException;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;
use Zarbalman\Core\Domain\PurchaseRequest\ExecutionMode;
use Zarbalman\Core\Infrastructure\DependencyHealth;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;
use Zarbalman\Core\Infrastructure\PaymentRepository;
use Zarbalman\Core\Infrastructure\LedgerRepository;

final class WooCommerceOrderAdapter implements ManagedOrderCreator {
	/** @var array<int,int> */
	private array $internal_status_depth = array();
	/** @var array<int,int> */
	private array $internal_stock_depth = array();
	/** @var array<int,int> */
	private array $internal_control_depth = array();
	/** @var array<int,int> */
	private array $internal_snapshot_depth = array();
	/** @var array<int,int> */
	private array $internal_deletion_depth = array();
	/** @var array<int,int> */
	private array $internal_payment_identity_depth = array();

	public function __construct(
		private readonly YithDepositAdapter $yith,
		private readonly PurchaseRequestRepository $requests,
		private readonly DependencyHealth $health,
		private readonly PaymentRepository $payments,
		private readonly LedgerRepository $ledger
	) {}

	public function register(): void {
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'restrictManagedGateways' ), PHP_INT_MAX );
		add_filter( 'woocommerce_valid_order_statuses_for_payment', array( $this, 'managedPayableStatuses' ), 10, 2 );
		add_filter( 'woocommerce_my_account_my_orders_query', array( $this, 'excludeManagedFromNativeAccountOrders' ) );
		add_filter( 'woocommerce_can_reduce_order_stock', array( $this, 'guardAutomaticStockReduction' ), PHP_INT_MAX, 2 );
		add_action( 'template_redirect', array( $this, 'enforceManagedPayOwner' ), 1 );
	}

	public function createForAcceptedRequest( PurchaseRequest $request ): int {
		if ( ExecutionMode::LIVE !== $request->executionMode() ) {
			throw new RuntimeException( 'A test request cannot enter live order creation.' );
		}
		return $this->createManagedOrderForAcceptedRequest( $request, 'online' );
	}

	/** Creates/recover a real, payment-disabled deposit order for a manual-bank obligation. */
	public function createManualForAcceptedRequest( PurchaseRequest $request ): int {
		if ( ExecutionMode::LIVE !== $request->executionMode() ) {
			throw new RuntimeException( 'A test request cannot enter real manual order creation.' );
		}
		return $this->createManagedOrderForAcceptedRequest( $request, 'manual_bank' );
	}

	/**
	 * Recover/create the manual order, or retire a never-issued online route.
	 * The caller must hold the request operation lock or have already persisted a
	 * manual intent that the authority-issuance guard can observe under that lock.
	 */
	public function ensureManualOrder( PurchaseRequest $request ): int {
		if ( ExecutionMode::LIVE !== $request->executionMode() ) {
			throw new RuntimeException( 'Only a live request can own a manual-bank order.' );
		}
		$order_id = $request->wcOrderId();
		$order = null === $order_id ? null : wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return $this->createManualForAcceptedRequest( $request );
		}
		if ( ! $this->isManagedOrder( $order ) || ! ManagedOrderSnapshot::matches( $order, $request ) || $order->is_paid() ) {
			throw new RuntimeException( 'Existing order is not eligible for a manual-bank route.' );
		}
		$route = ManagedOrderSnapshot::route( $order );
		if ( 'manual_bank' === $route ) {
			$this->disablePayment( $order->get_id() );
			return $order->get_id();
		}
		if ( 'online' !== $route || $this->hasZarinpalPaymentIdentity( $order->get_id() ) ) {
			throw new RuntimeException( 'An issued or non-live payment route cannot be converted.' );
		}
		$order->set_payment_method( 'zbm_manual_bank' );
		$order->update_meta_data( '_zbm_payment_route', 'manual_bank' );
		$order->update_meta_data( '_zbm_payment_enabled', 'no' );
		$this->withInternalSnapshotChange(
			$order->get_id(),
			fn (): int => $this->withInternalControlChange( $order->get_id(), static fn (): int => $order->save() )
		);
		$fresh = wc_get_order( $order->get_id() );
		if ( ! $fresh instanceof WC_Order || 'manual_bank' !== ManagedOrderSnapshot::route( $fresh ) || ! ManagedOrderSnapshot::matches( $fresh, $request ) ) {
			throw new RuntimeException( 'Manual-bank order conversion could not be verified.' );
		}
		try {
			$fresh->add_order_note( __( 'مسیر اینترنتیِ بدون Authority برای پرداخت دستی واقعی بسته شد؛ رسید خریدار باید جداگانه توسط مدیریت مالی تأیید شود.', 'zarbalman-core' ) );
		} catch ( \Throwable $note_error ) {
			$this->logProjectionFailure( $request, 'manual_bank', $note_error, 'conversion_note' );
		}
		return $fresh->get_id();
	}

	/** Project confirmed real manual deposit without payment_complete/Authority/RefID. */
	public function projectManualDepositConfirmed( PurchaseRequest $request ): void {
		$this->projectManualStatus( $request, 'processing', __( 'وصول پیش‌پرداخت دستی توسط مدیریت مالی تأیید شد؛ هیچ Authority یا RefID زرین‌پال ساخته نشد.', 'zarbalman-core' ) );
	}

	/**
	 * Woo can return an order ID after rejecting the initial staged snapshot.
	 * Rebuild ONLY its empty, pending order when immutable manual payment and
	 * ledger rows are already committed for this same request and offer.
	 */
	public function repairConfirmedManualDepositOrder( PurchaseRequest $request, array $case ): void {
		$order_id = $request->wcOrderId() ?? 0;
		$funds = $case['data']['funds']['deposit'] ?? null;
		$offer = $case['data']['manual']['deposit'] ?? null;
		if (
			ExecutionMode::LIVE !== $request->executionMode()
			|| RequestState::DEPOSIT_PAID !== $request->state()
			|| $request->needsReconciliation()
			|| $order_id < 1
			|| (int) ( $case['request_id'] ?? 0 ) !== $request->id()
			|| ( $case['mode'] ?? null ) !== $request->executionMode()
			|| ! in_array( $case['state'] ?? null, array_merge( \Zarbalman\Core\Domain\ManagedFlow\OperationsData::PRE_DELIVERY,
				array( 'buyer_review', 'seller_review', 'admin_review', 'final_weight_locked', 'balance_due', 'completed' ) ), true )
			|| 1 !== (int) ( $case['sync_pending'] ?? 0 )
			|| array_keys( $request->lines() ) !== array_keys( (array) ( $case['data']['lines'] ?? array() ) )
			|| ( $case['data']['recovery']['operation'] ?? null ) !== 'manual_deposit_projection'
			|| ( $offer['status'] ?? null ) !== 'approved'
			|| ! is_string( $offer['offer_id'] ?? null )
			|| (int) ( $offer['amount'] ?? 0 ) !== $request->depositToman()
			|| ( $funds['provider'] ?? null ) !== 'manual_bank'
			|| (int) ( $funds['amount'] ?? 0 ) !== $request->depositToman()
			|| ! $this->payments->confirmedManualDepositMatches( $request, $order_id, (int) ( $funds['payment_id'] ?? 0 ), $request->depositToman(), $offer['offer_id'] ?? '' )
			|| ! $this->ledger->confirmedManualDepositMatches( $request->id(), $order_id, (int) ( $funds['payment_id'] ?? 0 ), $request->depositToman(), $offer['offer_id'] ?? '' )
		) {
			throw new RuntimeException( 'Confirmed manual-deposit proof does not match the request, case and ledger.' );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order->get_id() !== $order_id || $this->requests->findByOrderId( $order_id )?->id() !== $request->id() ) {
			throw new RuntimeException( 'Referenced managed order is not uniquely owned by this request.' );
		}
		if ( ManagedOrderSnapshot::matches( $order, $request ) ) {
			$this->assertPersistedSnapshot( $order_id, $request );
			return;
		}
		if ( ! $this->isEmptyUncommittedManualOrder( $order, $request ) ) {
			throw new RuntimeException( 'Non-empty or conflicting managed order needs administrator reconciliation.' );
		}
		$product = $this->productForRequest( $request );
		$this->assertVendorStillOwnsProduct( $product, $request->vendorId() );
		if ( $request->isMultiline() ) { $this->assertRequestProductsAvailable( $request ); }
		else { $this->assertStockAvailable( $product, $request->quantity() ); }
		$this->withInternalSnapshotChange( $order_id, function () use ( $order, $request, $product ): void {
			$this->applyManagedOrderSnapshot( $order, $request, 'manual_bank', $product );
			$order->save();
		} );
		// Woo's save() may catch exceptions internally and still return the ID.
		// Never accept a memory-only snapshot or create financial records here.
		$this->assertPersistedSnapshot( $order_id, $request );
		$this->ensureReservedStock( wc_get_order( $order_id ) );
	}

	private function isEmptyUncommittedManualOrder( WC_Order $order, PurchaseRequest $request ): bool {
		$empty_or_expected = static fn ( mixed $actual, mixed $expected ): bool => '' === (string) $actual || (string) $actual === (string) $expected;
		return $order->get_id() === $request->wcOrderId()
			&& 'pending' === $order->get_status()
			&& 0 === (int) $order->get_parent_id()
			&& ! $order->is_paid()
			&& 'IRT' === strtoupper( $order->get_currency() )
			&& (int) $order->get_customer_id() === $request->buyerId()
			&& $empty_or_expected( $order->get_created_via(), 'zarbalman-core-spec3' )
			&& $empty_or_expected( $order->get_payment_method(), 'zbm_manual_bank' )
			&& $empty_or_expected( $order->get_meta( '_zbm_managed_order', true ), 'yes' )
			&& $empty_or_expected( $order->get_meta( '_zbm_request_id', true ), $request->id() )
			&& $empty_or_expected( $order->get_meta( '_zbm_request_public_id', true ), $request->publicId() )
			&& $empty_or_expected( $order->get_meta( '_zbm_vendor_id', true ), $request->vendorId() )
			&& $empty_or_expected( $order->get_meta( '_dokan_vendor_id', true ), $request->vendorId() )
			&& $empty_or_expected( $order->get_meta( '_zbm_execution_mode', true ), ExecutionMode::LIVE )
			&& $empty_or_expected( $order->get_meta( '_zbm_test_advance', true ), 'no' )
			&& $empty_or_expected( $order->get_meta( '_zbm_payment_route', true ), 'manual_bank' )
			&& $empty_or_expected( $order->get_meta( '_zbm_estimated_total_toman', true ), $request->estimatedTotalToman() )
			&& $empty_or_expected( $order->get_meta( '_zbm_deposit_toman', true ), $request->depositToman() )
			&& $empty_or_expected( $order->get_meta( '_zbm_reference_weight_tenths', true ), $request->referenceWeightTenths() )
			&& ManagedOrderSnapshot::pricingMetadataMatches( $order, $request, true )
			&& $empty_or_expected( $order->get_meta( '_zbm_authoritative_wc_status', true ), 'pending' )
			&& $empty_or_expected( $order->get_meta( '_zbm_payment_enabled', true ), 'no' )
			&& $empty_or_expected( $order->get_meta( '_zbm_stock_released', true ), 'no' )
			&& ! $this->orderStockReduced( $order )
			&& '' === trim( (string) $order->get_transaction_id() )
			&& ! $order->get_date_paid()
			&& '' === trim( (string) $order->get_meta( '_zarinpal_authority', true ) )
			&& in_array( $order->get_meta( '_zarinpal_authority_history', true ), array( '', null, array() ), true )
			&& in_array( $order->get_meta( '_zarinpal_fee_data', true ), array( '', null, array() ), true )
			&& ! ManagedOrderSnapshot::containsPrivateCustomerData( $order, true )
			&& 1 === preg_match( '/\A0(?:\.0+)?\z/', (string) $order->get_total() )
			&& 0 === count( $order->get_items( 'line_item' ) )
			&& 0 === count( $order->get_items( 'shipping' ) )
			&& 0 === count( $order->get_items( 'fee' ) )
			&& 0 === count( $order->get_items( 'tax' ) )
			&& 0 === count( $order->get_items( 'coupon' ) );
	}

	/** Project final completion after exact confirmed funds; historical order total remains the deposit. */
	public function projectManualCompleted( PurchaseRequest $request ): void {
		$this->projectManualStatus( $request, 'completed', __( 'ماندهٔ دستی تأیید و پرونده تکمیل شد؛ مبلغ تاریخی سفارش ووکامرس همچنان فقط پیش‌پرداخت است.', 'zarbalman-core' ) );
	}

	/** Complete either live route without rewriting its authoritative payment identity. */
	public function projectManagedCompleted( PurchaseRequest $request ): void {
		$order_id = $request->wcOrderId();
		$order = null === $order_id ? null : wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ExecutionMode::LIVE !== $request->executionMode() || ! ManagedOrderSnapshot::matches( $order, $request ) ) {
			throw new RuntimeException( 'Managed completion projection does not match its immutable snapshot.' );
		}
		$route = ManagedOrderSnapshot::route( $order );
		if ( 'manual_bank' === $route ) {
			$this->projectManualCompleted( $request );
			return;
		}
		if ( 'online' !== $route || ! $order->is_paid() ) {
			throw new RuntimeException( 'Online completion requires an authoritative paid deposit order.' );
		}
		if ( 'completed' === $order->get_status() ) {
			return;
		}
		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) {
			throw new RuntimeException( 'Managed completion cannot rewrite a terminal order.' );
		}
		$this->withInternalStatusChange(
			$order->get_id(),
			static function () use ( $order ): void {
				$order->set_status( 'completed', __( 'وزن نهایی ثبت شد و وجه قطعی موجود کل مبلغ نهایی را پوشش داد؛ هویت پرداخت آنلاین پیش‌پرداخت حفظ شد.', 'zarbalman-core' ) );
				$order->save();
			}
		);
		if ( 'completed' !== $this->assertPersistedSnapshot( $order->get_id(), $request )->get_status() ) {
			throw new RuntimeException( 'Managed completion status was not persisted.' );
		}
	}

	public function createTestForAcceptedRequest( PurchaseRequest $request ): int {
		if ( ExecutionMode::TEST_PENDING !== $request->executionMode() ) {
			throw new RuntimeException( 'A managed test order requires a durable test intent.' );
		}
		return $this->createManagedOrderForAcceptedRequest( $request, 'test' );
	}

	private function createManagedOrderForAcceptedRequest( PurchaseRequest $request, string $route ): int {
		if ( ! in_array( $route, array( 'online', 'manual_bank', 'test' ), true ) ) {
			throw new RuntimeException( 'Unknown managed order payment route.' );
		}
		$test_mode = 'test' === $route;
		if ( null !== $request->wcOrderId() ) {
			$order = wc_get_order( $request->wcOrderId() );
			if ( $order instanceof WC_Order && $this->isManagedOrder( $order ) && ManagedOrderSnapshot::route( $order ) === $route ) {
				$this->assertPersistedSnapshot( $order->get_id(), $request );
				return $order->get_id();
			}
			throw new RuntimeException( 'Request references an invalid managed order.' );
		}
		$existing = $this->findManagedOrderForRequest( $request );
		if ( $existing instanceof WC_Order ) {
			if (
				$existing->is_paid()
				|| in_array( $existing->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true )
				|| ManagedOrderSnapshot::route( $existing ) !== $route
				|| ! ManagedOrderSnapshot::matches( $existing, $request )
			) {
				throw new RuntimeException( 'Recovered managed order does not match the immutable request snapshot.' );
			}
			try {
				$this->ensureReservedStock( $existing );
			} catch ( \Throwable $error ) {
				try {
					$fresh = $this->reloadOrderForCompensation( $existing );
					$this->rollbackReducedStock( $fresh );
					$this->withInternalDeletion( $fresh->get_id(), static fn () => $fresh->delete( true ) );
				} catch ( \Throwable $compensation_error ) {
					throw new RuntimeException( 'Recovered managed order requires administrator reconciliation.', 0, $compensation_error );
				}
				throw new RuntimeException( 'Recovered managed order failed stock validation and was removed.', 0, $error );
			}
			return $existing->get_id();
		}
		if ( 'IRT' !== strtoupper( (string) get_woocommerce_currency() ) ) {
			throw new RuntimeException( 'Managed orders require IRT currency.' );
		}
		$product = $this->productForRequest( $request );
		$this->assertVendorStillOwnsProduct( $product, $request->vendorId() );
		if ( $request->isMultiline() ) { $this->assertRequestProductsAvailable( $request ); }
		else { $this->assertStockAvailable( $product, $request->quantity() ); }

		global $wpdb;
		$transaction_open = false;
		$order_committed = false;
		$order = null;
		try {
			if ( ! $wpdb instanceof \wpdb || false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'Managed order transaction could not start.' );
			}
			$transaction_open = true;
			$order = wc_create_order( array( 'customer_id' => $request->buyerId(), 'status' => 'pending' ) );
			if ( ! $order instanceof WC_Order ) {
				throw new RuntimeException( 'WooCommerce did not create an order.' );
			}
			$this->withInternalSnapshotChange(
				$order->get_id(),
				function () use ( $order, $request, $route, $product ): void {
					$this->applyManagedOrderSnapshot( $order, $request, $route, $product );
				}
			);
			$saved_order_id = $this->withInternalSnapshotChange( $order->get_id(), static fn (): int => $order->save() );
			if ( $order->get_id() !== $saved_order_id ) {
				throw new RuntimeException( 'Managed order snapshot could not be saved.' );
			}
			$this->assertPersistedSnapshot( $saved_order_id, $request );
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'Managed order transaction could not commit.' );
			}
			$transaction_open = false;
			$order_committed = true;

			$this->ensureReservedStock( $order );
			$note = $test_mode
				? __( 'سفارش آزمایشی پس از تأیید فروشنده ایجاد شد؛ پرداخت غیرفعال است و هیچ وجهی دریافت یا ثبت نشده است.', 'zarbalman-core' )
				: ( 'manual_bank' === $route
					? __( 'سفارش پیش‌پرداخت برای مسیر پرداخت دستی واقعی ایجاد شد؛ تا تأیید رسید توسط مدیریت مالی، وجهی قطعی ثبت نمی‌شود.', 'zarbalman-core' )
					: __( 'سفارش مدیریت‌شدهٔ زربال‌من پس از تأیید فروشنده ایجاد شد؛ مبلغ این سفارش فقط پیش‌پرداخت است.', 'zarbalman-core' ) );
			try {
				$order->add_order_note( $note );
			} catch ( \Throwable $note_error ) {
				// The order and stock reservation are already durable. A note is
				// diagnostic only and must never strand the receipt workflow.
				$this->logProjectionFailure( $request, $route, $note_error, 'order_note' );
			}
			return $order->get_id();
		} catch ( \Throwable $error ) {
			$this->logProjectionFailure( $request, $route, $error, 'create' );
			if ( $transaction_open && $wpdb instanceof \wpdb ) {
				$wpdb->query( 'ROLLBACK' );
			}
			if ( $order_committed && $order instanceof WC_Order && $order->get_id() > 0 ) {
				try {
					$fresh = $this->reloadOrderForCompensation( $order );
					$this->rollbackReducedStock( $fresh );
				} catch ( \Throwable $compensation_error ) {
					throw new RuntimeException( 'Order stock must be reconciled before retry; committed order was preserved.', 0, $compensation_error );
				}
				$this->withInternalDeletion( $fresh->get_id(), static fn () => $fresh->delete( true ) );
			}
			throw new RuntimeException( 'Managed WooCommerce order creation failed.', 0, $error );
		}
	}

	private function applyManagedOrderSnapshot( WC_Order $order, PurchaseRequest $request, string $route, WC_Product $product ): void {
		$test_mode = 'test' === $route;
		$order->set_currency( 'IRT' );
		$order->set_created_via( 'zarbalman-core-spec3' );
		$order->set_payment_method( 'manual_bank' === $route ? 'zbm_manual_bank' : ( $test_mode ? 'WC_ZPal' : $this->zarinpalGateway() ) );
		$order->set_customer_id( $request->buyerId() );
		$this->clearPrivateBuyerData( $order );
		$order->update_meta_data( '_zbm_managed_order', 'yes' );
		$order->update_meta_data( '_zbm_request_id', $request->id() );
		$order->update_meta_data( '_zbm_request_public_id', $request->publicId() );
		$order->update_meta_data( '_zbm_vendor_id', $request->vendorId() );
		$order->update_meta_data( '_dokan_vendor_id', $request->vendorId() );
		$order->update_meta_data( '_zbm_workflow_version', $request->version() );
		$order->update_meta_data( '_zbm_authoritative_wc_status', 'pending' );
		$order->update_meta_data( '_zbm_payment_enabled', 'no' );
		$order->update_meta_data( '_zbm_stock_released', 'no' );
		$order->update_meta_data( '_zbm_execution_mode', $test_mode ? ExecutionMode::TEST : ExecutionMode::LIVE );
		$order->update_meta_data( '_zbm_test_advance', $test_mode ? 'yes' : 'no' );
		$order->update_meta_data( '_zbm_payment_route', $route );
		$order->update_meta_data( '_zbm_estimated_total_toman', $request->estimatedTotalToman() );
		$order->update_meta_data( '_zbm_deposit_toman', $request->depositToman() );
		$order->update_meta_data( '_zbm_reference_weight_tenths', $request->referenceWeightTenths() );
		$order->update_meta_data( '_zbm_pricing_version', $request->pricingVersion() );
		$order->update_meta_data( '_zbm_estimated_shipping_toman', $request->estimatedShippingToman() );
		$order->update_meta_data( '_zbm_shipping_rate_per_kg_toman', $request->shippingRatePerKgToman() );
		$order->update_meta_data( '_zbm_estimated_gross_toman', $request->estimatedGrossToman() );
		if ( $request->isMultiline() ) {
			$order->update_meta_data( '_zbm_deposit_engine', 'core' );
			$order->update_meta_data( '_zbm_spec4_balance_deferred', 'yes' );
			foreach ( ManagedOrderSnapshot::multilineProjection( $request ) as $key => $line ) {
				$line_product = wc_get_product( $line['variation_id'] ?: $line['product_id'] );
				if ( ! $line_product instanceof WC_Product || ! $line_product->exists() ) { throw new RuntimeException( 'Request product is unavailable.' ); }
				$this->assertVendorStillOwnsProduct( $line_product, $request->vendorId() );
				$item = new WC_Order_Item_Product();
				$item->set_product( $line_product );
				$item->set_product_id( $line['product_id'] );
				$item->set_variation_id( $line['variation_id'] );
				$item->set_name( $line['name'] );
				$item->set_quantity( $line['quantity'] );
				$item->set_subtotal( (string) $line['subtotal'] );
				$item->set_total( (string) $line['deposit'] );
				$item->set_taxes( array( 'subtotal' => array(), 'total' => array() ) );
				$item->update_meta_data( '_zbm_request_line_id', $key );
				$item->update_meta_data( '_zbm_vendor_id', $request->vendorId() );
				$item->update_meta_data( '_zbm_package_price_toman', $line['package_price_toman'] );
				$item->update_meta_data( '_zbm_nominal_package_weight_tenths', $line['nominal_weight_tenths'] );
				$item->update_meta_data( '_zbm_reference_weight_tenths', $line['reference_tenths'] );
				$item->update_meta_data( '_zbm_authoritative_line_deposit_toman', $line['deposit'] );
				$order->add_item( $item );
			}
			$order->set_total( (string) $request->depositToman() );
			return;
		}
		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_product_id( $request->productId() );
		$item->set_variation_id( $request->variationId() );
		$item->set_quantity( $request->quantity() );
		// One payment line, with the original product component separately stored.
		$item->set_subtotal( (string) $request->estimatedGrossToman() );
		$item->set_total( (string) $request->depositToman() );
		$item->update_meta_data( '_zbm_package_price_toman', $request->packagePriceToman() );
		$item->update_meta_data( '_zbm_nominal_package_weight_tenths', $request->nominalPackageWeightTenths() );
		$item->update_meta_data( '_zbm_reference_weight_tenths', $request->referenceWeightTenths() );
		$this->yith->decorate( $order, $item, $request->pricingSnapshot(), $request->pricingVersion() );
		$order->add_item( $item );
		$order->set_total( (string) $request->depositToman() );
	}

	private function assertPersistedSnapshot( int $order_id, PurchaseRequest $request ): WC_Order {
		$fresh = wc_get_order( $order_id );
		if ( ! $fresh instanceof WC_Order || ! ManagedOrderSnapshot::matches( $fresh, $request ) ) {
			throw new RuntimeException( 'Managed WooCommerce order snapshot was not persisted.' );
		}
		$items = array_values( $fresh->get_items( 'line_item' ) );
		if ( count( $request->lines() ) !== count( $items ) ) { throw new RuntimeException( 'Managed order has missing lines.' ); }
		foreach ( $items as $item ) { if ( ! $item instanceof WC_Order_Item_Product || $item->get_id() < 1 ) { throw new RuntimeException( 'Managed order has no persisted WooCommerce item ID.' ); } }
		return $fresh;
	}

	public function releaseReservedStock( PurchaseRequest $request ): void {
		$order_id = $request->wcOrderId();
		if ( null === $order_id ) {
			throw new RuntimeException( 'Managed order ID is unavailable for stock release.' );
		}
		$this->releaseReservedStockByOrderId( $order_id );
	}

	public function releaseReservedStockByOrderId( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $this->isManagedOrder( $order ) || $order->is_paid() ) {
			throw new RuntimeException( 'Managed order is unavailable for stock release.' );
		}
		$this->rollbackReducedStock( $order );
		$this->withInternalStatusChange(
			$order->get_id(),
			static function () use ( $order ): void {
				$order->update_meta_data( '_zbm_stock_released', 'yes' );
				$order->update_meta_data( '_zbm_payment_enabled', 'no' );
				$order->set_status( 'cancelled', __( 'مهلت یک‌روزهٔ پیش‌پرداخت زربال‌من پایان یافت.', 'zarbalman-core' ) );
				$order->save();
			}
		);
	}

	public function enablePayment( int $order_id ): void {
		$order = wc_get_order( $order_id );
		$request = $this->requests->findByOrderId( $order_id );
		if (
			! $order instanceof WC_Order
			|| ! $request
			|| ExecutionMode::LIVE !== $request->executionMode()
			|| ! ManagedOrderSnapshot::matches( $order, $request )
			|| $order->is_paid()
		) {
			throw new RuntimeException( 'Managed order cannot be enabled for payment.' );
		}
		$order->update_meta_data( '_zbm_payment_enabled', 'yes' );
		$this->withInternalControlChange( $order->get_id(), static fn () => $order->save_meta_data() );
	}

	public function disablePayment( int $order_id ): void {
		$order = wc_get_order( $order_id );
		$request = $this->requests->findByOrderId( $order_id );
		if ( ! $order instanceof WC_Order || ! $request || ! ManagedOrderSnapshot::matches( $order, $request ) ) {
			throw new RuntimeException( 'Managed order cannot be disabled.' );
		}
		$order->update_meta_data( '_zbm_payment_enabled', 'no' );
		$this->withInternalControlChange( $order->get_id(), static fn () => $order->save_meta_data() );
	}

	public function reopenPaymentAndReserveStock( PurchaseRequest $request ): void {
		if ( ExecutionMode::LIVE !== $request->executionMode() ) {
			throw new RuntimeException( 'A test request cannot reopen live payment.' );
		}
		$order_id = $request->wcOrderId();
		$order = null === $order_id ? false : wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order->is_paid() || ! $this->isManagedOrder( $order ) ) {
			throw new RuntimeException( 'Managed order cannot be reopened.' );
		}
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
				throw new RuntimeException( 'Managed order product is unavailable.' );
			}
			$this->assertVendorStillOwnsProduct( $product, $request->vendorId() );
			if ( (int) $item->get_meta( '_reduced_stock', true ) < 1 ) {
				$this->assertStockAvailable( $product, (int) $item->get_quantity() );
			}
		}
		$reopen_order = $order;
		$reduced_now = false;
		try {
			if ( ! $this->orderStockReduced( $order ) ) {
				foreach ( $order->get_items( 'line_item' ) as $item ) {
					if ( (int) $item->get_meta( '_reduced_stock', true ) > 0 ) {
						throw new RuntimeException( 'Reopened order has unresolved prior stock reduction.' );
					}
				}
				$reduced_now = true; // Also compensate a partial reduction if the reservation throws.
				$this->ensureReservedStock( $order );
				$order = wc_get_order( $order->get_id() );
				if ( ! $order instanceof WC_Order ) {
					throw new RuntimeException( 'Reopened order could not be reloaded.' );
				}
				foreach ( $order->get_items( 'line_item' ) as $item ) {
					$product = $item->get_product();
					if ( ! $product instanceof WC_Product ) {
						throw new RuntimeException( 'Managed order product is unavailable.' );
					}
					$this->assertVendorStillOwnsProduct( $product, $request->vendorId() );
					$this->assertStockNotOversold( $product );
				}
			}
			$this->withInternalStatusAndControlChange(
				$order->get_id(),
				static function () use ( $order ): void {
					$order->set_status( 'pending', __( 'مهلت پیش‌پرداخت با دلیل ثبت‌شده توسط مدیریت باز شد.', 'zarbalman-core' ) );
					$order->update_meta_data( '_zbm_stock_released', 'no' );
					$order->update_meta_data( '_zbm_payment_enabled', 'yes' );
					$order->save();
				}
			);
		} catch ( \Throwable $error ) {
			if ( $reduced_now ) {
				try {
					$fresh = $this->reloadOrderForCompensation( $reopen_order );
					$this->rollbackReducedStock( $fresh );
				} catch ( \Throwable $compensation_error ) {
					throw new RuntimeException( 'Order stock must be reconciled before retry; committed order was preserved.', 0, $compensation_error );
				}
			}
			throw new RuntimeException( 'Managed order reopen failed.', 0, $error );
		}
	}

	public function reserveStockForVerifiedPayment( PurchaseRequest $request ): bool {
		if ( ExecutionMode::LIVE !== $request->executionMode() ) {
			throw new RuntimeException( 'A test request cannot reserve stock through payment verification.' );
		}
		$order_id = $request->wcOrderId();
		$order = null === $order_id ? false : wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $this->isManagedOrder( $order ) ) {
			throw new RuntimeException( 'Verified managed order is unavailable for stock reservation.' );
		}
		$was_reduced = $this->orderStockReduced( $order );
		if ( $was_reduced ) {
			if ( 'yes' === $order->get_meta( '_zbm_stock_released', true ) ) {
				if ( ! $request->stockReserved() || $request->stockReleased() ) {
					throw new RuntimeException( 'Managed stock markers conflict without a durable reservation intent.' );
				}
				// WooCommerce completed the decrement before Core could persist its
				// control marker. The durable request intent makes this repair safe.
				$order->update_meta_data( '_zbm_stock_released', 'no' );
				$order->update_meta_data( '_zbm_payment_enabled', 'no' );
				$this->withInternalControlChange( $order->get_id(), static fn () => $order->save_meta_data() );
			}
			$this->ensureReservedStock( $order );
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$product = $item->get_product();
				if ( ! $product instanceof WC_Product ) {
					throw new RuntimeException( 'Managed order product is unavailable.' );
				}
				$this->assertVendorStillOwnsProduct( $product, $request->vendorId() );
				$this->assertStockNotOversold( $product );
			}
			return false;
		}
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
				throw new RuntimeException( 'Managed order product is unavailable.' );
			}
			$this->assertVendorStillOwnsProduct( $product, $request->vendorId() );
			if ( (int) $item->get_meta( '_reduced_stock', true ) < 1 ) {
				$this->assertStockAvailable( $product, (int) $item->get_quantity() );
			}
		}
		$preexisting_reduction = false;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$preexisting_reduction = $preexisting_reduction || (int) $item->get_meta( '_reduced_stock', true ) > 0;
		}
		$reservation_order = $order;
		try {
			$this->ensureReservedStock( $order );
			$order = wc_get_order( $order->get_id() );
			if ( ! $order instanceof WC_Order ) {
				throw new RuntimeException( 'Paid managed order could not be reloaded.' );
			}
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$product = $item->get_product();
				if ( ! $product instanceof WC_Product ) {
					throw new RuntimeException( 'Managed order product is unavailable.' );
				}
				$this->assertVendorStillOwnsProduct( $product, $request->vendorId() );
				$this->assertStockNotOversold( $product );
			}
			$order->update_meta_data( '_zbm_stock_released', 'no' );
			$order->update_meta_data( '_zbm_payment_enabled', 'no' );
			$this->withInternalControlChange( $order->get_id(), static fn () => $order->save_meta_data() );
			return $this->orderStockReduced( $order );
		} catch ( \Throwable $error ) {
			if ( ! $preexisting_reduction ) {
				try {
					$fresh = $this->reloadOrderForCompensation( $reservation_order );
					$this->rollbackReducedStock( $fresh );
				} catch ( \Throwable $compensation_error ) {
					throw new RuntimeException( 'Order stock must be reconciled before retry; committed order was preserved.', 0, $compensation_error );
				}
			}
			throw new RuntimeException( 'Paid managed order stock reservation failed.', 0, $error );
		}
	}

	public function markRemotelyVerifiedPaidOrder( int $order_id, string $reference ): void {
		$order = wc_get_order( $order_id );
		$request = $this->requests->findByOrderId( $order_id );
		$reference = trim( $reference );
		if (
			! $order instanceof WC_Order
			|| ! $request
			|| ExecutionMode::LIVE !== $request->executionMode()
			|| ! $this->isManagedOrder( $order )
			|| ! ManagedOrderSnapshot::matches( $order, $request )
			|| 'WC_ZPal' !== $order->get_payment_method()
			|| '' === $reference
			|| strlen( $reference ) > 128
		) {
			throw new RuntimeException( 'Remotely verified order identity is invalid.' );
		}
		$current_reference = trim( (string) $order->get_transaction_id() );
		if ( '' !== $current_reference && ! hash_equals( $current_reference, $reference ) ) {
			throw new RuntimeException( 'Remotely verified order reference conflicts with WooCommerce.' );
		}
		if ( $order->is_paid() ) {
			return;
		}
		$this->withInternalStatusChange(
			$order->get_id(),
			static function () use ( $order, $reference ): void {
				$order->set_transaction_id( $reference );
				$order->set_date_paid( time() );
				$order->set_status( $order->needs_processing() ? 'processing' : 'completed' );
				$order->add_order_note( __( 'وضعیت پرداخت فقط پس از استعلام مستقل و قطعی Core از زرین‌پال بازیابی شد.', 'zarbalman-core' ) );
				$order->save();
			}
		);
	}

	public function pinCapturedZarinpalAuthority( int $order_id, string $authority ): void {
		$order = wc_get_order( $order_id );
		$request = $this->requests->findByOrderId( $order_id );
		$authority = trim( $authority );
		if (
			! $order instanceof WC_Order
			|| ! $request
			|| ExecutionMode::LIVE !== $request->executionMode()
			|| ! $this->isManagedOrder( $order )
			|| ! ManagedOrderSnapshot::matches( $order, $request )
			|| '' === $authority
			|| strlen( $authority ) > 128
			|| ! $this->authorityBelongsToOrder( $order, $authority )
		) {
			throw new RuntimeException( 'Captured Zarinpal Authority is not part of this managed order.' );
		}
		$this->withInternalPaymentIdentityChange(
			$order_id,
			static function () use ( $order, $authority ): void {
				$order->update_meta_data( '_zarinpal_authority', $authority );
				$order->save_meta_data();
			}
		);
	}

	public function releaseVerifiedPaymentStock( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $this->isManagedOrder( $order ) ) {
			throw new RuntimeException( 'Verified managed order is unavailable for stock release.' );
		}
		$this->rollbackReducedStock( $order );
		$this->withInternalStockChange( $order->get_id(), static function () use ( $order ): void {
			$order->update_meta_data( '_zbm_stock_released', 'yes' );
			$order->update_meta_data( '_zbm_payment_enabled', 'no' );
			$order->save_meta_data();
		} );
	}

	public function cancelUnpaidOrder( int $order_id, string $reason ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $this->isManagedOrder( $order ) || $order->is_paid() ) {
			throw new RuntimeException( 'A paid or invalid managed order cannot be cancelled.' );
		}
		$this->rollbackReducedStock( $order );
		$this->withInternalStatusAndControlChange( $order->get_id(), static function () use ( $order, $reason ): void {
			$order->update_meta_data( '_zbm_payment_enabled', 'no' );
			$order->update_meta_data( '_zbm_stock_released', 'yes' );
			if ( 'cancelled' !== $order->get_status() ) {
				$order->set_status( 'cancelled', $reason );
			}
			$order->save();
		} );
	}

	public function hasZarinpalPaymentIdentity( int $order_id ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $this->isManagedOrder( $order ) ) {
			throw new RuntimeException( 'Managed order is unavailable for payment identity inspection.' );
		}
		if ( 'online' !== ManagedOrderSnapshot::route( $order ) ) {
			return false;
		}
		if ( '' !== trim( (string) $order->get_transaction_id() ) || '' !== trim( (string) $order->get_meta( '_zarinpal_authority', true ) ) ) {
			return true;
		}
		$history = $order->get_meta( '_zarinpal_authority_history', true );
		if ( ! is_array( $history ) ) {
			return false;
		}
		foreach ( $history as $authority ) {
			if ( is_string( $authority ) && '' !== trim( $authority ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string,mixed> $gateways
	 *  @return array<string,mixed>
	 */
	public function restrictManagedGateways( array $gateways ): array {
		$order = $this->currentPayOrder();
		if ( ! $order instanceof WC_Order || ! $this->isManagedOrder( $order ) ) {
			return $gateways;
		}
		if ( ! $this->isAuthoritativePayable( $order ) ) {
			return array();
		}
		return isset( $gateways['WC_ZPal'] ) ? array( 'WC_ZPal' => $gateways['WC_ZPal'] ) : array();
	}

	/** @param list<string> $statuses
	 *  @return list<string>
	 */
	public function managedPayableStatuses( array $statuses, WC_Order $order ): array {
		if ( ! $this->isManagedOrder( $order ) ) {
			return $statuses;
		}
		return $this->isAuthoritativePayable( $order ) ? array( 'pending' ) : array();
	}

	/** @param array<string,mixed> $args
	 *  @return array<string,mixed>
	 */
	public function excludeManagedFromNativeAccountOrders( array $args ): array {
		$exclusion = array(
			'relation' => 'OR',
			array( 'key' => '_zbm_managed_order', 'compare' => 'NOT EXISTS' ),
			array( 'key' => '_zbm_managed_order', 'value' => 'yes', 'compare' => '!=' ),
		);
		$existing = $args['meta_query'] ?? array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Account page is user-scoped and paginated.
		$args['meta_query'] = is_array( $existing ) && $existing // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- See above.
			? array( 'relation' => 'AND', $existing, $exclusion )
			: $exclusion;
		return $args;
	}

	public function enforceManagedPayOwner(): void {
		$order = $this->currentPayOrder();
		if ( ! $order instanceof WC_Order || ! $this->isManagedOrder( $order ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id > 0 && $user_id === (int) $order->get_customer_id() ) {
			return;
		}
		if ( $user_id < 1 ) {
			wp_safe_redirect( wp_login_url( $order->get_checkout_payment_url() ) );
			exit;
		}
		status_header( 404 );
		wp_die( esc_html__( 'سفارش در دسترس نیست.', 'zarbalman-core' ), '', array( 'response' => 404 ) );
	}

	public function isInternalStatusChange( int $order_id ): bool {
		return $order_id > 0 && ( $this->internal_status_depth[ $order_id ] ?? 0 ) > 0;
	}

	public function isInternalStockChange( int $order_id ): bool {
		return $order_id > 0 && ( $this->internal_stock_depth[ $order_id ] ?? 0 ) > 0;
	}

	public function isInternalControlChange( int $order_id ): bool {
		return $order_id > 0 && ( $this->internal_control_depth[ $order_id ] ?? 0 ) > 0;
	}

	public function isInternalSnapshotChange( int $order_id ): bool {
		return $order_id > 0 && ( $this->internal_snapshot_depth[ $order_id ] ?? 0 ) > 0;
	}

	public function isInternalDeletion( int $order_id ): bool {
		return $order_id > 0 && ( $this->internal_deletion_depth[ $order_id ] ?? 0 ) > 0;
	}

	public function isInternalPaymentIdentityChange( int $order_id ): bool {
		return $order_id > 0 && ( $this->internal_payment_identity_depth[ $order_id ] ?? 0 ) > 0;
	}

	public function beginInternalPaymentIdentityChange( int $order_id ): void {
		$this->increaseDepth( $this->internal_payment_identity_depth, $order_id );
	}

	public function endInternalPaymentIdentityChange( int $order_id ): void {
		$this->decreaseDepth( $this->internal_payment_identity_depth, $order_id );
	}

	public function guardAutomaticStockReduction( bool $can_reduce, WC_Order $order ): bool {
		return $this->isManagedOrder( $order ) && ! $this->isInternalStockChange( $order->get_id() ) ? false : $can_reduce;
	}

	private function withInternalStockChange( int $order_id, callable $operation ): mixed {
		$this->increaseDepth( $this->internal_stock_depth, $order_id );
		$this->increaseDepth( $this->internal_control_depth, $order_id );
		try {
			return $operation();
		} finally {
			$this->decreaseDepth( $this->internal_stock_depth, $order_id );
			$this->decreaseDepth( $this->internal_control_depth, $order_id );
		}
	}

	public function beginExternalPaymentCompletion( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order && $this->isManagedOrder( $order ) && 'WC_ZPal' === $order->get_payment_method() ) {
			$this->increaseDepth( $this->internal_status_depth, $order_id );
		}
	}

	public function endExternalPaymentCompletion( int $order_id ): void {
		$this->decreaseDepth( $this->internal_status_depth, $order_id );
	}

	public function withInternalStatusChange( int $order_id, callable $operation ): mixed {
		$this->increaseDepth( $this->internal_status_depth, $order_id );
		try {
			return $operation();
		} finally {
			$this->decreaseDepth( $this->internal_status_depth, $order_id );
		}
	}

	private function withInternalControlChange( int $order_id, callable $operation ): mixed {
		$this->increaseDepth( $this->internal_control_depth, $order_id );
		try {
			return $operation();
		} finally {
			$this->decreaseDepth( $this->internal_control_depth, $order_id );
		}
	}

	private function withInternalStatusAndControlChange( int $order_id, callable $operation ): mixed {
		return $this->withInternalStatusChange(
			$order_id,
			fn () => $this->withInternalControlChange( $order_id, $operation )
		);
	}

	private function withInternalSnapshotChange( int $order_id, callable $operation ): mixed {
		$this->increaseDepth( $this->internal_snapshot_depth, $order_id );
		try {
			return $operation();
		} finally {
			$this->decreaseDepth( $this->internal_snapshot_depth, $order_id );
		}
	}

	private function withInternalDeletion( int $order_id, callable $operation ): mixed {
		$this->increaseDepth( $this->internal_deletion_depth, $order_id );
		try {
			return $operation();
		} finally {
			$this->decreaseDepth( $this->internal_deletion_depth, $order_id );
		}
	}

	private function withInternalPaymentIdentityChange( int $order_id, callable $operation ): mixed {
		$this->beginInternalPaymentIdentityChange( $order_id );
		try {
			return $operation();
		} finally {
			$this->endInternalPaymentIdentityChange( $order_id );
		}
	}

	private function projectManualStatus( PurchaseRequest $request, string $status, string $note ): void {
		$order_id = $request->wcOrderId();
		$order = null === $order_id ? null : wc_get_order( $order_id );
		if (
			! $order instanceof WC_Order
			|| ExecutionMode::LIVE !== $request->executionMode()
			|| 'manual_bank' !== ManagedOrderSnapshot::route( $order )
			|| ! ManagedOrderSnapshot::matches( $order, $request )
			|| ! in_array( $status, array( 'processing', 'completed' ), true )
		) {
			throw new RuntimeException( 'Manual order projection does not match its immutable snapshot.' );
		}
		if ( $status === $order->get_status() ) {
			return;
		}
		if ( 'completed' === $order->get_status() || in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) {
			throw new RuntimeException( 'Manual order projection cannot rewrite a terminal status.' );
		}
		$this->withInternalStatusChange(
			$order->get_id(),
			static function () use ( $order, $status, $note ): void {
				$order->set_status( $status, $note );
				$order->set_date_paid( null );
				$order->set_transaction_id( '' );
				$order->save();
			}
		);
		if ( $status !== $this->assertPersistedSnapshot( $order->get_id(), $request )->get_status() ) {
			throw new RuntimeException( 'Manual payment status was not persisted.' );
		}
	}

	private function increaseDepth( array &$depth, int $order_id ): void {
		if ( $order_id < 1 ) {
			throw new RuntimeException( 'An order-scoped internal operation requires an order ID.' );
		}
		$depth[ $order_id ] = ( $depth[ $order_id ] ?? 0 ) + 1;
	}

	private function decreaseDepth( array &$depth, int $order_id ): void {
		if ( ! isset( $depth[ $order_id ] ) ) {
			return;
		}
		if ( $depth[ $order_id ] > 1 ) {
			--$depth[ $order_id ];
			return;
		}
		unset( $depth[ $order_id ] );
	}

	private function assertRequestProductsAvailable( PurchaseRequest $request ): void {
		$stock = array();
		foreach ( $request->lines() as $line ) {
			$product = wc_get_product( $line['variation_id'] ?: $line['product_id'] );
			if ( ! $product instanceof WC_Product || ! $product->exists() || 'publish' !== get_post_status( $line['product_id'] ) || ( $line['variation_id'] > 0 && $product->get_parent_id() !== $line['product_id'] ) ) { throw new RuntimeException( 'Request product identity changed.' ); }
			$this->assertVendorStillOwnsProduct( $product, $request->vendorId() );
			$this->assertStockAvailable( $product, $line['quantity'] );
			if ( $product->managing_stock() && ! $product->backorders_allowed() ) {
				$id = $product->get_stock_managed_by_id();
				$stock[ $id ] = \Zarbalman\Core\Domain\PurchaseRequest\RequestPricingPolicy::add( $stock[ $id ] ?? 0, $line['quantity'] );
				$owner = wc_get_product( $id );
				if ( ! $owner instanceof WC_Product || ! $owner->has_enough_stock( $stock[ $id ] ) ) { throw new RuntimeException( 'Combined variation quantity exceeds shared stock.' ); }
			}
		}
	}

	private function productForRequest( PurchaseRequest $request ): WC_Product {
		$product_id = $request->variationId() > 0 ? $request->variationId() : $request->productId();
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || ! $product->exists() || 'publish' !== get_post_status( $request->productId() ) ) {
			throw new RuntimeException( 'Requested product is unavailable.' );
		}
		return $product;
	}

	private function assertVendorStillOwnsProduct( WC_Product $product, int $vendor_id ): void {
		$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$actual = function_exists( 'dokan_get_vendor_by_product' )
			? absint( dokan_get_vendor_by_product( $parent_id, true ) )
			: absint( get_post_field( 'post_author', $parent_id ) );
		if ( $actual < 1 || $actual !== $vendor_id ) {
			throw new RuntimeException( 'Product vendor changed.' );
		}
	}

	private function assertStockAvailable( WC_Product $product, int $quantity ): void {
		if ( ! $product->is_in_stock() || ( $product->managing_stock() && ! $product->has_enough_stock( $quantity ) && ! $product->backorders_allowed() ) ) {
			throw new RuntimeException( 'Requested stock is unavailable.' );
		}
	}

	private function assertStockNotOversold( WC_Product $product ): void {
		$stock_id = method_exists( $product, 'get_stock_managed_by_id' ) ? (int) $product->get_stock_managed_by_id() : $product->get_id();
		$stock_product = wc_get_product( $stock_id );
		if ( ! $stock_product instanceof WC_Product || ! $stock_product->managing_stock() || $stock_product->backorders_allowed() ) {
			return;
		}
		$remaining = $stock_product->get_stock_quantity();
		if ( null !== $remaining && $remaining < 0 ) {
			throw new RuntimeException( 'Concurrent stock reservation exceeded available stock.' );
		}
	}

	/**
	 * WooCommerce's stock-reduced state is stored by the order data store. Older
	 * installations used the legacy order meta as a compatibility fallback; the
	 * fallback is never treated as physical stock proof without item validation.
	 */
	private function orderStockReduced( WC_Order $order ): bool {
		$store = $order->get_data_store();
		if ( is_object( $store ) && method_exists( $store, 'get_stock_reduced' ) && method_exists( $store, 'set_stock_reduced' ) ) {
			return (bool) $store->get_stock_reduced( $order->get_id() );
		}
		return 'yes' === $order->get_meta( '_order_stock_reduced', true );
	}

	/** @param bool $reduced Whether the order-level stock state is reduced. */
	private function setOrderStockReduced( WC_Order $order, bool $reduced ): void {
		$store = $order->get_data_store();
		if ( is_object( $store ) && method_exists( $store, 'get_stock_reduced' ) && method_exists( $store, 'set_stock_reduced' ) ) {
			$store->set_stock_reduced( $order->get_id(), $reduced );
			return;
		}
		$order->update_meta_data( '_order_stock_reduced', $reduced ? 'yes' : 'no' );
		$this->withInternalControlChange( $order->get_id(), static fn () => $order->save_meta_data() );
	}

	private function reloadOrderForCompensation( WC_Order $order ): WC_Order {
		$fresh = wc_get_order( $order->get_id() );
		if ( ! $fresh instanceof WC_Order ) {
			throw new RuntimeException( 'Managed order could not be reloaded for stock compensation.' );
		}
		return $fresh;
	}

	private function ensureReservedStock( WC_Order $order ): void {
		$order_id = $order->get_id();
		$tracked = 'yes' === get_option( 'woocommerce_manage_stock', 'yes' );
		$already_reduced = $this->orderStockReduced( $order );
		if ( $already_reduced ) {
			if ( 'yes' === $order->get_meta( '_zbm_stock_released', true ) ) {
				throw new RuntimeException( 'Managed stock markers conflict.' );
			}
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$product = $item->get_product();
				if ( ! $product instanceof WC_Product ) {
					throw new RuntimeException( 'Managed order product is unavailable.' );
				}
				if ( (int) $item->get_meta( '_reduced_stock', true ) > 0 && ( ! $tracked || ! $product->managing_stock() ) ) {
					throw new RuntimeException( 'Stock settings changed after an order item was reduced.' );
				}
				if ( $tracked && $product->managing_stock() ) {
					if ( (int) $item->get_meta( '_reduced_stock', true ) !== (int) $item->get_quantity() ) {
						throw new RuntimeException( 'Managed stock flag has no matching item reduction.' );
					}
					$this->assertStockNotOversold( $product );
				}
			}
			return;
		}
		$managed_items = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
				throw new RuntimeException( 'Managed order product is unavailable.' );
			}
			if ( (int) $item->get_meta( '_reduced_stock', true ) > 0 && ( ! $tracked || ! $product->managing_stock() ) ) {
				throw new RuntimeException( 'Stock settings changed after an order item was reduced.' );
			}
			if ( $tracked && $product->managing_stock() ) {
				++$managed_items;
				$reduced = (int) $item->get_meta( '_reduced_stock', true );
				if ( $reduced && $reduced !== (int) $item->get_quantity() ) {
					throw new RuntimeException( 'Managed order stock quantity conflicts with its item reduction.' );
				}
				if ( ! $reduced ) {
					$this->assertStockAvailable( $product, (int) $item->get_quantity() );
				}
			}
		}
		if ( 0 === $managed_items ) {
			// WooCommerce cannot decrement stock for products that do not manage it.
			// Do not invent a physical stock reservation or fail the payment route.
			return;
		}
		$this->withInternalStockChange( $order->get_id(), static fn () => wc_reduce_stock_levels( $order->get_id() ) );
		$fresh = wc_get_order( $order->get_id() );
		if ( ! $fresh instanceof WC_Order ) {
			throw new RuntimeException( 'Managed order is unavailable after stock reduction.' );
		}
		foreach ( $fresh->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
				throw new RuntimeException( 'Managed order product is unavailable after stock reduction.' );
			}
			if ( $product->managing_stock() ) {
				if ( (int) $item->get_meta( '_reduced_stock', true ) !== (int) $item->get_quantity() ) {
					throw new RuntimeException( 'WooCommerce did not confirm the item stock reduction.' );
				}
				$this->assertStockNotOversold( $product );
			}
		}
		// wc_reduce_stock_levels() records per-item reductions but does NOT set
		// _order_stock_reduced. That flag is normally set by wc_maybe_reduce_stock_levels()
		// on payment_complete; pending manual orders never take that route.
		$this->withInternalStockChange(
			$order_id,
			fn () => $this->setOrderStockReduced( $fresh, true )
		);
		if ( ! $this->orderStockReduced( $fresh ) ) {
			throw new RuntimeException( 'Managed stock reduction flag was not persisted.' );
		}
	}

	/**
	 * Woo stores the physical reduction on the order item, not necessarily on
	 * the order flag. Never delete a failed order while a reduction remains.
	 */
	private function rollbackReducedStock( WC_Order $order ): void {
		$reduced = false;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( (int) $item->get_meta( '_reduced_stock', true ) > 0 ) {
				$reduced = true;
				break;
			}
		}
		if ( $reduced ) {
			if ( 'yes' !== get_option( 'woocommerce_manage_stock', 'yes' ) ) {
				throw new RuntimeException( 'Stock management changed; manual reconciliation is required before deleting the order.' );
			}
			$this->withInternalStockChange( $order->get_id(), static fn () => wc_increase_stock_levels( $order->get_id() ) );
			$reloaded = wc_get_order( $order->get_id() );
			if ( ! $reloaded instanceof WC_Order ) {
				throw new RuntimeException( 'Cannot verify stock compensation for the order.' );
			}
			foreach ( $reloaded->get_items( 'line_item' ) as $item ) {
				if ( (int) $item->get_meta( '_reduced_stock', true ) > 0 ) {
					throw new RuntimeException( 'Stock compensation was not verified for every order item.' );
				}
			}
		}
		$this->withInternalStockChange(
			$order->get_id(),
			fn () => $this->setOrderStockReduced( $order, false )
		);
	}

	private function logProjectionFailure( PurchaseRequest $request, string $route, \Throwable $error, string $stage ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		try {
			wc_get_logger()->error(
				'Managed WooCommerce order projection failed.',
				array(
					'source'         => 'zarbalman-core',
					'stage'          => sanitize_key( $stage ),
					'route'          => sanitize_key( $route ),
					'request_id'     => (string) $request->id(),
					'request_number' => $request->requestNumber(),
					'order_id'       => (string) ( $request->wcOrderId() ?? 0 ),
					'exception'      => get_class( $error ),
					'error'          => $error->getMessage(),
				)
			);
		} catch ( \Throwable ) {
			// Logging must never change the payment/recovery result.
		}
	}

	private function findManagedOrderForRequest( PurchaseRequest $request ): ?WC_Order {
		$orders = wc_get_orders(
			array(
				'type'       => 'shop_order',
				'limit'      => 2,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'return'     => 'objects',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Recovery lookup is bounded and keyed by one immutable request ID.
					array( 'key' => '_zbm_request_id', 'value' => (string) $request->id(), 'compare' => '=' ),
				),
			)
		);
		$orders = array_values( array_filter( is_array( $orders ) ? $orders : array(), static fn ( mixed $order ): bool => $order instanceof WC_Order ) );
		if ( count( $orders ) > 1 ) {
			throw new RuntimeException( 'More than one managed order exists for the request.' );
		}
		return $orders[0] ?? null;
	}

	private function clearPrivateBuyerData( WC_Order $order ): void {
		// Recipient/address data lives only in Core's encrypted request payload.
		// SMS resolves the mobile from protected user metadata, never this order.
		$order->set_billing_first_name( '' );
		$order->set_billing_last_name( '' );
		$order->set_billing_company( '' );
		$order->set_billing_address_1( '' );
		$order->set_billing_address_2( '' );
		$order->set_billing_city( '' );
		$order->set_billing_state( '' );
		$order->set_billing_postcode( '' );
		$order->set_billing_country( '' );
		$order->set_billing_phone( '' );
		$order->set_billing_email( '' );
		$order->set_shipping_first_name( '' );
		$order->set_shipping_last_name( '' );
		$order->set_shipping_company( '' );
		$order->set_shipping_address_1( '' );
		$order->set_shipping_address_2( '' );
		$order->set_shipping_city( '' );
		$order->set_shipping_state( '' );
		$order->set_shipping_postcode( '' );
		$order->set_shipping_country( '' );
		$order->set_shipping_phone( '' );
		$order->set_customer_ip_address( '' );
		$order->set_customer_user_agent( '' );
		$order->set_customer_note( '' );
	}

	private function zarinpalGateway(): \WC_Payment_Gateway {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			throw new RuntimeException( 'WooCommerce payment gateways are unavailable.' );
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway = $gateways['WC_ZPal'] ?? null;
		$settings = get_option( 'woocommerce_WC_ZPal_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		if ( ! $gateway instanceof \WC_Payment_Gateway || 'yes' !== $gateway->enabled || 'yes' === (string) ( $settings['sandbox'] ?? '' ) ) {
			throw new RuntimeException( 'Configured Zarinpal gateway is unavailable.' );
		}
		return $gateway;
	}

	private function currentPayOrder(): ?WC_Order {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
			return null;
		}
		$order_id = absint( get_query_var( 'order-pay' ) );
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		return $order instanceof WC_Order ? $order : null;
	}

	public function isAuthoritativePayable( WC_Order $order ): bool {
		if ( 'online' !== ManagedOrderSnapshot::route( $order ) || ! $this->health->canCreateDepositOrder() || 'yes' !== $order->get_meta( '_zbm_payment_enabled', true ) || $order->is_paid() ) {
			return false;
		}
		$request = $this->requests->findByOrderId( $order->get_id() );
		return null !== $request
			&& ExecutionMode::LIVE === $request->executionMode()
			&& ManagedOrderSnapshot::matches( $order, $request )
			&& RequestState::DEPOSIT_PENDING === $request->state()
			&& $request->paymentReady()
			&& ! $request->isPaused()
			&& null !== $request->depositDeadlineGmt()
			&& $request->depositDeadlineGmt() > current_time( 'mysql', true );
	}

	public function isManagedOrder( WC_Order $order ): bool {
		return 'yes' === $order->get_meta( '_zbm_managed_order', true )
			|| 'zarbalman-core-spec3' === $order->get_created_via()
			|| ( $order->get_id() > 0 && null !== $this->requests->findByOrderId( $order->get_id() ) );
	}

	private function authorityBelongsToOrder( WC_Order $order, string $authority ): bool {
		$current = trim( (string) $order->get_meta( '_zarinpal_authority', true ) );
		if ( '' !== $current && hash_equals( $current, $authority ) ) {
			return true;
		}
		$history = $order->get_meta( '_zarinpal_authority_history', true );
		if ( ! is_array( $history ) ) {
			return false;
		}
		foreach ( $history as $candidate ) {
			if ( is_string( $candidate ) && hash_equals( trim( $candidate ), $authority ) ) {
				return true;
			}
		}
		return false;
	}
}

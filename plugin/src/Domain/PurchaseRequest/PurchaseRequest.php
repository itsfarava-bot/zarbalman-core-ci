<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\PurchaseRequest;

use InvalidArgumentException;

final class PurchaseRequest {
	/** @param array<string,mixed> $row */
	public static function fromRow( array $row ): self {
		$required = array(
			'id', 'public_id', 'buyer_id', 'vendor_id', 'product_id', 'variation_id', 'quantity',
			'package_price_toman', 'nominal_package_weight_tenths', 'reference_weight_tenths',
			'estimated_total_toman', 'deposit_toman', 'delivery_method', 'delivery_window_type',
			'delivery_start_gmt', 'delivery_end_gmt', 'current_state', 'version', 'is_paused',
			'created_at_gmt', 'updated_at_gmt',
		);
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $row ) ) {
				throw new InvalidArgumentException( 'Incomplete purchase request row.' );
			}
		}

		$request = new self( $row );
		$policy = $request->pricingVersion();
		if ( $request->isMultiline() && RequestPricingPolicy::SHIPPING_INCLUSIVE !== $policy ) { throw new InvalidArgumentException( 'Multiline requests require versioned pricing.' ); }
		if ( RequestPricingPolicy::SHIPPING_INCLUSIVE === $policy ) {
			// Fail closed on incomplete or unrepresentable new financial facts;
			// historical rows deliberately retain their original loading semantics.
			foreach ( array( 'quantity', 'package_price_toman', 'nominal_package_weight_tenths', 'reference_weight_tenths', 'estimated_total_toman', 'deposit_toman', 'estimated_shipping_toman', 'shipping_rate_per_kg_toman' ) as $field ) {
				self::pricingInteger( $row[ $field ] ?? null );
			}
			$pricing = $request->pricingSnapshot();
			if ( $pricing->quantity() !== $request->quantity() || $pricing->referenceWeightTenths() !== $request->referenceWeightTenths()
				|| $pricing->estimatedTotalToman() !== $request->estimatedTotalToman()
				|| $pricing->estimatedShippingToman() !== $request->estimatedShippingToman()
				|| $pricing->depositToman() !== $request->depositToman()
				|| ( DeliveryMethod::PICKUP_FROM_VENDOR === $request->deliveryMethod() && 0 !== $request->shippingRatePerKgToman() )
			) {
				throw new InvalidArgumentException( 'Inconsistent versioned request pricing.' );
			}
			DeliveryMethod::assertValid( $request->deliveryMethod() );
		}
		return $request;
	}

	/** @param array<string,mixed> $row */
	private function __construct( private readonly array $row ) {}

	public function id(): int { return (int) $this->row['id']; }
	public function publicId(): string { return (string) $this->row['public_id']; }
	public function requestNumber(): string {
		$value = $this->row['request_number'] ?? null;
		return RequestNumber::isValid( $value ) ? (string) $value : RequestNumber::fromInternalId( $this->id() );
	}
	public function buyerId(): int { return (int) $this->row['buyer_id']; }
	public function vendorId(): int { return (int) $this->row['vendor_id']; }
	public function productId(): int { return (int) $this->row['product_id']; }
	public function variationId(): int { return (int) $this->row['variation_id']; }
	public function quantity(): int { return (int) $this->row['quantity']; }
	public function packagePriceToman(): int { return (int) $this->row['package_price_toman']; }
	public function nominalPackageWeightTenths(): int { return (int) $this->row['nominal_package_weight_tenths']; }
	public function referenceWeightTenths(): int { return (int) $this->row['reference_weight_tenths']; }
	public function estimatedTotalToman(): int { return (int) $this->row['estimated_total_toman']; }
	public function pricingVersion(): int {
		$version = array_key_exists( 'pricing_version', $this->row ) ? self::pricingInteger( $this->row['pricing_version'] ) : RequestPricingPolicy::LEGACY;
		if ( ! in_array( $version, array( RequestPricingPolicy::LEGACY, RequestPricingPolicy::SHIPPING_INCLUSIVE ), true ) ) {
			throw new InvalidArgumentException( 'Unsupported request pricing policy.' );
		}
		return $version;
	}
	public function estimatedShippingToman(): int { return RequestPricingPolicy::LEGACY === $this->pricingVersion() ? 0 : self::pricingInteger( $this->row['estimated_shipping_toman'] ?? null ); }
	public function shippingRatePerKgToman(): int { return RequestPricingPolicy::LEGACY === $this->pricingVersion() ? 0 : self::pricingInteger( $this->row['shipping_rate_per_kg_toman'] ?? null ); }
	public function estimatedGrossToman(): int {
		return RequestPricingPolicy::add( $this->estimatedTotalToman(), $this->estimatedShippingToman() );
	}
	public function depositToman(): int { return (int) $this->row['deposit_toman']; }
	public function deliveryMethod(): string { return (string) $this->row['delivery_method']; }
	public function deliveryWindowType(): string { return (string) $this->row['delivery_window_type']; }
	public function deliveryStartGmt(): string { return (string) $this->row['delivery_start_gmt']; }
	public function deliveryEndGmt(): string { return (string) $this->row['delivery_end_gmt']; }
	public function state(): string { return (string) $this->row['current_state']; }
	public function version(): int { return (int) $this->row['version']; }
	public function isPaused(): bool { return 1 === (int) $this->row['is_paused']; }
	public function pauseReason(): string { return (string) ( $this->row['pause_reason'] ?? '' ); }
	public function pausedRemainingSeconds(): ?int { return null === ( $this->row['paused_remaining_seconds'] ?? null ) ? null : (int) $this->row['paused_remaining_seconds']; }
	public function supplierDeadlineGmt(): ?string { return self::nullableString( $this->row['supplier_deadline_gmt'] ?? null ); }
	public function depositDeadlineGmt(): ?string { return self::nullableString( $this->row['deposit_deadline_gmt'] ?? null ); }
	public function wcOrderId(): ?int { return empty( $this->row['wc_order_id'] ) ? null : (int) $this->row['wc_order_id']; }
	public function paymentReady(): bool { return 1 === (int) ( $this->row['payment_ready'] ?? 0 ); }
	public function needsReconciliation(): bool { return 1 === (int) ( $this->row['needs_reconciliation'] ?? 0 ); }
	public function stockOperation(): string { return (string) ( $this->row['stock_operation'] ?? '' ); }
	public function stockReserved(): bool { return 1 === (int) ( $this->row['stock_reserved'] ?? 0 ); }
	public function stockReleased(): bool { return 1 === (int) ( $this->row['stock_released'] ?? 0 ); }
	public function executionMode(): string {
		$mode = (string) ( $this->row['execution_mode'] ?? ExecutionMode::LIVE );
		ExecutionMode::assertValid( $mode );
		return $mode;
	}
	public function isTest(): bool { return ExecutionMode::isTest( $this->executionMode() ); }
	public function testAdvanceReason(): string { return (string) ( $this->row['test_advance_reason'] ?? '' ); }
	public function testAdvancedBy(): ?int { return empty( $this->row['test_advanced_by'] ) ? null : (int) $this->row['test_advanced_by']; }
	public function testAdvanceStartedAtGmt(): ?string { return self::nullableString( $this->row['test_advance_started_at_gmt'] ?? null ); }
	public function testAdvancedAtGmt(): ?string { return self::nullableString( $this->row['test_advanced_at_gmt'] ?? null ); }
	public function rejectionReason(): string { return (string) ( $this->row['rejection_reason'] ?? '' ); }
	public function encryptedDeliveryPayload(): string { return (string) ( $this->row['delivery_payload_encrypted'] ?? '' ); }

	public function pricingSnapshot(): PricingSnapshot {
		if ( $this->isMultiline() ) { return PricingSnapshot::fromLines( $this->lines(), $this->shippingRatePerKgToman() ); }
		return PricingSnapshot::fromProduct(
			$this->quantity(),
			$this->packagePriceToman(),
			$this->nominalPackageWeightTenths(),
			$this->shippingRatePerKgToman()
		);
	}

	public function isMultiline(): bool {
		return isset( $this->row['lines_json'] ) && '' !== (string) $this->row['lines_json'];
	}

	public function lines(): array {
		if ( $this->isMultiline() ) {
			$lines = json_decode( (string) $this->row['lines_json'], true, 16, JSON_THROW_ON_ERROR );
			if ( ! is_array( $lines ) || count( $lines ) < 2 ) { throw new InvalidArgumentException( 'Invalid multiline request snapshot.' ); }
			RequestLines::validate( $lines );
			$first = reset( $lines );
			if ( $first['product_id'] !== $this->productId() || $first['variation_id'] !== $this->variationId() || $first['package_price_toman'] !== $this->packagePriceToman() || $first['nominal_weight_tenths'] !== $this->nominalPackageWeightTenths() ) { throw new InvalidArgumentException( 'Multiline header identity mismatch.' ); }
			return $lines;
		}
		return array( 'request:' . $this->id() => array( 'product_id' => $this->productId(), 'variation_id' => $this->variationId(), 'quantity' => $this->quantity(), 'package_price_toman' => $this->packagePriceToman(), 'nominal_weight_tenths' => $this->nominalPackageWeightTenths(), 'reference_tenths' => $this->referenceWeightTenths(), 'estimated_total' => $this->estimatedTotalToman(), 'name' => '' ) );
	}

	/** @return array<string,mixed> */
	public function toArray(): array { return $this->row; }

	private static function pricingInteger( mixed $value ): int {
		if ( ( ! is_int( $value ) && ! is_string( $value ) ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/', (string) $value ) ) {
			throw new InvalidArgumentException( 'Invalid request pricing integer.' );
		}
		$digits = (string) $value;
		$maximum = (string) PHP_INT_MAX;
		if ( strlen( $digits ) > strlen( $maximum ) || ( strlen( $digits ) === strlen( $maximum ) && strcmp( $digits, $maximum ) > 0 ) ) {
			throw new InvalidArgumentException( 'Request pricing integer exceeds capacity.' );
		}
		return (int) $digits;
	}

	private static function nullableString( mixed $value ): ?string {
		return is_string( $value ) && '' !== $value ? $value : null;
	}
}

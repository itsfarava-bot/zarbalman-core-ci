<?php

declare(strict_types=1);

namespace Zarbalman\Core\Application;

use InvalidArgumentException;
use Zarbalman\Core\Domain\PurchaseRequest\DeliveryMethod;
use Zarbalman\Core\Domain\PurchaseRequest\DeliveryWindow;
use Zarbalman\Core\Domain\PurchaseRequest\ShippingQuote;
use Zarbalman\Core\Domain\PurchaseRequest\PricingSnapshot;
use Zarbalman\Core\Domain\PurchaseRequest\RequestLines;

final class PurchaseRequestInput {
	public function __construct(
		private readonly int $product_id,
		private readonly int $variation_id,
		private readonly int $vendor_id,
		private readonly int $quantity,
		private readonly int $package_price_toman,
		private readonly int $nominal_weight_tenths,
		private readonly string $delivery_method,
		private readonly DeliveryWindow $delivery_window,
		private readonly string $recipient_name,
		private readonly ?string $postal_code,
		private readonly ?string $address,
		private readonly ?string $province_code = null,
		private readonly ?string $province_name = null,
		private readonly ?int $shipping_rate_per_kg_toman = null,
		private readonly ?int $estimated_shipping_toman = null,
		private readonly ?string $shipping_rate_version = null,
		private readonly ?string $pickup_note = null,
		private readonly bool $pickup_visit_confirmed = false,
		private readonly ?array $pickup_address = null,
		private readonly array $lines = array()
	) {
		if ( $lines ) {
			RequestLines::validate( $lines );
			$first = reset( $lines );
			if ( $first['product_id'] !== $product_id || $first['variation_id'] !== $variation_id || $first['quantity'] !== $quantity || $first['package_price_toman'] !== $package_price_toman || $first['nominal_weight_tenths'] !== $nominal_weight_tenths ) { throw new InvalidArgumentException( 'First product does not match request header.' ); }
		}
		DeliveryMethod::assertValid( $delivery_method );
		if ( $product_id < 1 || $vendor_id < 1 || $variation_id < 0 || $quantity < 1 ) {
			throw new InvalidArgumentException( 'Invalid product request identifiers.' );
		}
		if ( '' === trim( $recipient_name ) || self::containsContactData( $recipient_name ) ) {
			throw new InvalidArgumentException( 'A valid recipient name is required.' );
		}
		if ( DeliveryMethod::DELIVERY_TO_BUYER_ADDRESS === $delivery_method ) {
			if (
				null === $postal_code
				|| 1 !== preg_match( '/\A[0-9]{10}\z/', $postal_code )
				|| 1 === preg_match( '/\A9[0-9]{9}\z/', $postal_code )
			) {
				throw new InvalidArgumentException( 'A valid 10-digit postal code is required.' );
			}
			if ( null === $address || self::length( trim( $address ) ) < 10 || self::containsContactData( $address ) ) {
				throw new InvalidArgumentException( 'A complete delivery address is required.' );
			}
			if (
				null === $province_code
				|| ! preg_match( '/\A[a-z0-9_-]{1,64}\z/i', $province_code )
				|| null === $province_name
				|| '' === trim( $province_name )
				|| self::length( $province_name ) > 120
				|| null === $shipping_rate_per_kg_toman
				|| $shipping_rate_per_kg_toman < 0
				|| null === $estimated_shipping_toman
				|| $estimated_shipping_toman < 0
				|| null === $shipping_rate_version
				|| '' === trim( $shipping_rate_version )
				|| self::length( $shipping_rate_version ) > 128
			) {
				throw new InvalidArgumentException( 'A valid province shipping snapshot is required.' );
			}
			$expected_shipping = $lines ? ShippingQuote::estimateToman( RequestLines::totals( $lines )['reference_tenths'], $shipping_rate_per_kg_toman ) : ShippingQuote::estimateForQuantity( $quantity, $nominal_weight_tenths, $shipping_rate_per_kg_toman );
			if ( $expected_shipping !== $estimated_shipping_toman ) {
				throw new InvalidArgumentException( 'The shipping estimate does not match the server snapshot.' );
			}
			if ( null !== $pickup_note || $pickup_visit_confirmed || null !== $pickup_address ) {
				throw new InvalidArgumentException( 'Address deliveries must not contain pickup data.' );
			}
		} elseif ( null !== $postal_code || null !== $address ) {
			throw new InvalidArgumentException( 'Pickup requests must not contain a buyer address.' );
		} elseif ( null !== $province_code || null !== $province_name || null !== $shipping_rate_per_kg_toman || null !== $estimated_shipping_toman || null !== $shipping_rate_version ) {
			throw new InvalidArgumentException( 'Pickup requests must not contain shipping data.' );
		} elseif ( ! $pickup_visit_confirmed ) {
			throw new InvalidArgumentException( 'Pickup requests require an explicit visit confirmation.' );
		} elseif ( ! self::validPickupAddress( $pickup_address ) ) {
			throw new InvalidArgumentException( 'Pickup requests require a trusted vendor-address snapshot.' );
		} elseif (
			null !== $pickup_note
			&& ( '' === trim( $pickup_note ) || self::length( trim( $pickup_note ) ) > 500 || self::containsContactData( $pickup_note ) )
		) {
			throw new InvalidArgumentException( 'Pickup notes are invalid or contain contact data.' );
		}
		if ( self::length( $recipient_name ) > 120 || ( null !== $address && self::length( $address ) > 1000 ) ) {
			throw new InvalidArgumentException( 'Delivery data is too long.' );
		}
		if ( $package_price_toman < 1 || $nominal_weight_tenths < 1 ) {
			throw new InvalidArgumentException( 'Invalid pricing snapshot.' );
		}
	}

	public static function containsContactData( string $value ): bool {
		$normalized = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( class_exists( '\Normalizer' ) ) {
			$candidate = \Normalizer::normalize( $normalized, \Normalizer::FORM_KC );
			if ( is_string( $candidate ) ) {
				$normalized = $candidate;
			}
		}
		$normalized = strtr( $normalized, array( '＠' => '@', '﹫' => '@', '＋' => '+', '‐' => '-', '‑' => '-', '−' => '-' ) );
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $normalized, 'UTF-8' ) : strtolower( $normalized );
		if (
			false !== strpos( $lower, '@' )
			|| 1 === preg_match( '/\b[\p{L}0-9._%+\-]+\s*(?:\[|\(|\{)?\s*at\s*(?:\]|\)|\})?\s*[\p{L}0-9.-]+\s*(?:\[|\(|\{)?\s*dot\s*(?:\]|\)|\})?\s*[\p{L}]{2,}\b/iu', $lower )
			|| 1 === preg_match( '/[a-z0-9._%+\-]+\s*(?:\[|\(|\{)?\s*(?:at|ات|ا[َ]ت|آت)\s*(?:\]|\)|\})?\s*[a-z0-9.-]+\s*(?:\[|\(|\{)?\s*(?:dot|دات|نقطه)\s*(?:\]|\)|\})?\s*[a-z]{2,}/iu', $lower )
			|| 1 === preg_match( '/(?:https?:\/\/|www\.|t\.me\/|wa\.me\/|تلگرام|واتساپ|اینستاگرام|آیدی\s*(?:من)?)/iu', $lower )
		) {
			return true;
		}

		$phone_source = strtr(
			$normalized,
			array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' )
		);
		$compact = preg_replace( '/[^0-9+]/', '', $phone_source ) ?: '';
		if ( 1 === preg_match( '/(?:\+?98|0098|0)?9[0-9]{9}/', $compact ) ) {
			return true;
		}
		if ( 1 === preg_match( '/(?:\+?98|0098|0)?(?:21|26|31|34|35|41|44|45|51|54|56|58|61|66|71|74|76|77|81|83|84|86|87|88)[0-9]{8}/', $compact ) ) {
			return true;
		}
		if ( str_contains( $compact, '+' ) && 1 === preg_match( '/\+[0-9]{7,15}/', $compact ) ) {
			return true;
		}
		if ( 1 === preg_match( '/[0-9]{10,15}/', $compact ) ) {
			return true;
		}
		$number_word = '(?:صفر|یک|دو|سه|چهار|پنج|شش|هفت|هشت|نه)';
		if ( 1 === preg_match( '/' . $number_word . '(?:[\s،,؛;\-]+' . $number_word . '){6,}/u', $lower ) ) {
			return true;
		}
		$labelled = 1 === preg_match( '/(?:تلفن|موبایل|همراه|تماس|phone|mobile|tel)\s*[:：\-]?\s*[+0-9][0-9\s().\-]{5,}/iu', $phone_source );
		return $labelled;
	}

	public function productId(): int { return $this->product_id; }
	public function lines(): array { return $this->lines; }
	public function pricingSnapshot(): PricingSnapshot {
		return $this->lines ? PricingSnapshot::fromLines( $this->lines, $this->shippingRatePerKgToman() ?? 0 ) : PricingSnapshot::fromProduct( $this->quantity(), $this->packagePriceToman(), $this->nominalWeightTenths(), $this->shippingRatePerKgToman() ?? 0 );
	}
	public function variationId(): int { return $this->variation_id; }
	public function vendorId(): int { return $this->vendor_id; }
	public function quantity(): int { return $this->quantity; }
	public function packagePriceToman(): int { return $this->package_price_toman; }
	public function nominalWeightTenths(): int { return $this->nominal_weight_tenths; }
	public function deliveryMethod(): string { return $this->delivery_method; }
	public function deliveryWindow(): DeliveryWindow { return $this->delivery_window; }
	public function provinceCode(): ?string { return $this->province_code; }
	public function provinceName(): ?string { return $this->province_name; }
	public function shippingRatePerKgToman(): ?int { return $this->shipping_rate_per_kg_toman; }
	public function estimatedShippingToman(): ?int { return $this->estimated_shipping_toman; }
	public function shippingRateVersion(): ?string { return $this->shipping_rate_version; }

	/** @return array<string,mixed> */
	public function deliveryPayload(): array {
		$payload = array(
			'recipient_name' => trim( $this->recipient_name ),
			'postal_code'    => $this->postal_code,
			'address'        => null === $this->address ? null : trim( $this->address ),
		);
		if ( DeliveryMethod::DELIVERY_TO_BUYER_ADDRESS === $this->delivery_method ) {
			$payload['province_code']                = $this->province_code;
			$payload['province_name']                = null === $this->province_name ? null : trim( $this->province_name );
			$payload['shipping_rate_per_kg_toman']   = $this->shipping_rate_per_kg_toman;
			$payload['estimated_shipping_toman']     = $this->estimated_shipping_toman;
			$payload['shipping_rate_version']        = $this->shipping_rate_version;
		} else {
			$pickup_address = array();
			foreach ( array( 'store_name', 'street_1', 'street_2', 'city', 'zip', 'state', 'country' ) as $field ) {
				$pickup_address[ $field ] = trim( (string) ( $this->pickup_address[ $field ] ?? '' ) );
			}
			$payload['pickup_address']         = $pickup_address;
			$payload['pickup_note']            = null === $this->pickup_note ? null : trim( $this->pickup_note );
			$payload['pickup_visit_confirmed'] = true;
		}
		return $payload;
	}

	private static function validPickupAddress( ?array $address ): bool {
		if ( null === $address ) {
			return false;
		}
		$fields = array( 'store_name', 'street_1', 'street_2', 'city', 'zip', 'state', 'country' );
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $address ) || ! is_string( $address[ $field ] ) || self::length( $address[ $field ] ) > 300 ) {
				return false;
			}
		}
		foreach ( array( 'street_1', 'city', 'state', 'country' ) as $required ) {
			if ( '' === trim( $address[ $required ] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}
}

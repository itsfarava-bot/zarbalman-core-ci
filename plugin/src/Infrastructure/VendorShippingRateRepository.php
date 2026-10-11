<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

/** Persists Zarbalman per-kilogram rates separately from Dokan's native rates. */
final class VendorShippingRateRepository {
	public const RATES_META_KEY = '_zbm_shipping_rates_per_kg';
	public const VERSION_META_KEY = '_zbm_shipping_rates_version';
	public const MAX_RATE_TOMAN_PER_KG = 999999999;

	/** @return array<string,int> */
	public function allRates( int $vendor_id ): array {
		if ( $vendor_id < 1 || ! function_exists( 'get_user_meta' ) ) {
			return array();
		}

		$stored = get_user_meta( $vendor_id, self::RATES_META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$rates = array();
		foreach ( $stored as $raw_code => $raw_rate ) {
			if ( ! is_scalar( $raw_code ) ) {
				continue;
			}
			$rate = self::normalizeRate( $raw_rate );
			$code = self::normalizeCode( (string) $raw_code );
			if ( null !== $rate && '' !== $code ) {
				$rates[ $code ] = $rate;
			}
		}
		ksort( $rates );

		return $rates;
	}

	public function rateFor( int $vendor_id, string $province_code ): ?int {
		$code  = self::normalizeCode( $province_code );
		$rates = $this->allRates( $vendor_id );

		return array_key_exists( $code, $rates ) ? $rates[ $code ] : null;
	}

	/** @param array<string,string> $catalog @return array<string,string> */
	public function supportedProvinces( int $vendor_id, array $catalog ): array {
		$rates = $this->allRates( $vendor_id );
		$supported = array();
		foreach ( $catalog as $code => $name ) {
			if ( ! is_string( $name ) || ! array_key_exists( $code, $rates ) ) {
				continue;
			}
			$supported[ $code ] = $name;
		}

		return $supported;
	}

	/** @return array{rate:int,version:string}|null */
	public function snapshotFor( int $vendor_id, string $province_code ): ?array {
		$code  = self::normalizeCode( $province_code );
		$rates = $this->allRates( $vendor_id );
		if ( ! array_key_exists( $code, $rates ) ) {
			return null;
		}

		return array(
			'rate'    => $rates[ $code ],
			'version' => self::hashRates( $rates ),
		);
	}

	public function version( int $vendor_id ): string {
		$stored = function_exists( 'get_user_meta' ) ? get_user_meta( $vendor_id, self::VERSION_META_KEY, true ) : '';
		if ( is_string( $stored ) && '' !== trim( $stored ) ) {
			return trim( $stored );
		}

		return self::hashRates( $this->allRates( $vendor_id ) );
	}

	/** @param array<array-key,mixed> $rates @return string */
	public function saveRates( int $vendor_id, array $rates ): string {
		if ( $vendor_id < 1 || ! function_exists( 'update_user_meta' ) || ! function_exists( 'get_user_meta' ) ) {
			throw new \RuntimeException( 'Vendor shipping persistence is unavailable.' );
		}
		$normalized = array();
		foreach ( $rates as $raw_code => $raw_rate ) {
			if ( ! is_scalar( $raw_code ) ) {
				continue;
			}
			$rate = self::normalizeRate( $raw_rate );
			$code = self::normalizeCode( (string) $raw_code );
			if ( null !== $rate && '' !== $code ) {
				$normalized[ $code ] = $rate;
			}
		}
		ksort( $normalized );

		$version = self::hashRates( $normalized );
		wp_cache_delete( $vendor_id, 'user_meta' );
		$prior_rates = get_user_meta( $vendor_id, self::RATES_META_KEY, true );
		$prior_version = get_user_meta( $vendor_id, self::VERSION_META_KEY, true );
		$existed = array(
			self::RATES_META_KEY => metadata_exists( 'user', $vendor_id, self::RATES_META_KEY ),
			self::VERSION_META_KEY => metadata_exists( 'user', $vendor_id, self::VERSION_META_KEY ),
		);
		try {
			update_user_meta( $vendor_id, self::RATES_META_KEY, $normalized );
			update_user_meta( $vendor_id, self::VERSION_META_KEY, $version );
			// false can mean an unchanged value. Only a fresh read of BOTH writes proves success.
			wp_cache_delete( $vendor_id, 'user_meta' );
			if (
				get_user_meta( $vendor_id, self::RATES_META_KEY, true ) !== $normalized
				|| get_user_meta( $vendor_id, self::VERSION_META_KEY, true ) !== $version
			) {
				throw new \RuntimeException( 'Vendor shipping metadata read-back did not match.' );
			}
		} catch ( \Throwable $error ) {
			// Best effort: attempt BOTH restorations even if the first one throws.
			foreach ( array( self::RATES_META_KEY => $prior_rates, self::VERSION_META_KEY => $prior_version ) as $key => $value ) {
				try {
					if ( $existed[ $key ] ) {
						update_user_meta( $vendor_id, $key, $value );
					} else {
						delete_user_meta( $vendor_id, $key );
					}
				} catch ( \Throwable ) {
					// The caller still receives failure; restoration is never advertised as success.
				}
			}
			wp_cache_delete( $vendor_id, 'user_meta' );
			throw new \RuntimeException( 'Vendor shipping changes could not be verified; inspect stored rates and retry.', 0, $error );
		}

		return $version;
	}

	public static function normalizeCode( string $code ): string {
		return strtolower( trim( (string) preg_replace( '/[^a-z0-9_-]/', '', $code ) ) );
	}

	public static function normalizeRate( mixed $raw_rate ): ?int {
		if ( is_int( $raw_rate ) ) {
			return $raw_rate >= 0 && $raw_rate <= self::MAX_RATE_TOMAN_PER_KG ? $raw_rate : null;
		}
		if ( ! is_scalar( $raw_rate ) ) {
			return null;
		}

		$value = strtr( trim( (string) $raw_rate ), array(
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
			'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
			'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			',' => '', '٬' => '', ' ' => '',
		) );
		if ( '' === $value || ! preg_match( '/\A[0-9]+\z/', $value ) ) {
			return null;
		}

		$rate = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $rate || $rate > self::MAX_RATE_TOMAN_PER_KG ) {
			return null;
		}

		return (int) $rate;
	}

	/** @param array<string,int> $rates */
	private static function hashRates( array $rates ): string {
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $rates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : json_encode( $rates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return hash( 'sha256', is_string( $encoded ) ? $encoded : '' );
	}
}

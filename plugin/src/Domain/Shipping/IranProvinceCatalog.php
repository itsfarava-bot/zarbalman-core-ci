<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Shipping;

/**
 * Resolves the canonical WooCommerce state list for Iran without maintaining a
 * second, potentially divergent province list in the plugin.
 */
final class IranProvinceCatalog {
	public const COUNTRY_CODE = 'IR';
	public const EXPECTED_COUNT = 31;

	/** @return array<string,string> normalized code => display name */
	public static function all(): array {
		$states = array();
		if ( class_exists( '\\WC_Countries' ) ) {
			$countries = new \WC_Countries();
			$states = $countries->get_states( self::COUNTRY_CODE );
		} elseif ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && is_object( WC()->countries ) ) {
			$states = WC()->countries->get_states( self::COUNTRY_CODE );
		}

		return is_array( $states ) ? self::normalizeStates( $states ) : array();
	}

	/** @param array<array-key,mixed> $states @return array<string,string> */
	public static function normalizeStates( array $states ): array {
		$normalized = array();
		foreach ( $states as $raw_code => $raw_name ) {
			if ( ! is_scalar( $raw_code ) || ! is_scalar( $raw_name ) ) {
				continue;
			}

			$code = strtolower( trim( (string) $raw_code ) );
			$code = (string) preg_replace( '/[^a-z0-9_-]/', '', $code );
			$name = self::localizedDisplayName( trim( (string) $raw_name ) );
			if ( '' === $code || '' === $name || strlen( $code ) > 64 || self::length( $name ) > 120 ) {
				continue;
			}
			if ( isset( $normalized[ $code ] ) ) {
				continue;
			}

			$normalized[ $code ] = $name;
		}

		return $normalized;
	}

	/** @param array<string,string> $catalog @return array{code:string,name:string}|null */
	public static function find( array $catalog, string $code ): ?array {
		$normalized_code = strtolower( trim( (string) preg_replace( '/[^a-z0-9_-]/', '', $code ) ) );
		if ( '' === $normalized_code || ! array_key_exists( $normalized_code, $catalog ) ) {
			return null;
		}

		return array(
			'code' => $normalized_code,
			'name' => $catalog[ $normalized_code ],
		);
	}

	/** @param array<string,string> $catalog */
	public static function isComplete( array $catalog ): bool {
		return self::EXPECTED_COUNT === count( $catalog );
	}

	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	private static function localizedDisplayName( string $name ): string {
		if ( 1 !== preg_match( '/\(([^()]*)\)\s*\z/u', $name, $matches ) ) {
			return $name;
		}

		$localized = trim( $matches[1] );
		return '' !== $localized && 1 === preg_match( '/[\x{0600}-\x{06FF}\x{200C}\x{200D}]/u', $localized )
			? $localized
			: $name;
	}
}

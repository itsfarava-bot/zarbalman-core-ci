<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use WP_Error;
use Zarbalman\Core\Domain\Shipping\IranProvinceCatalog;

/** Read-only snapshot of Dokan's pinned Vendor address API; excludes contact data. */
final class DokanStoreAddressAdapter {
	/** @return array{store_name:string,street_1:string,street_2:string,city:string,zip:string,state:string,country:string}|WP_Error */
	public function snapshot( int $vendor_id ): array|WP_Error {
		if ( $vendor_id < 1 || ! function_exists( 'dokan' ) ) {
			return $this->error( 'dependency' );
		}
		try {
			$vendor = dokan()->vendor->get( $vendor_id );
			$address = is_object( $vendor ) && method_exists( $vendor, 'get_address' ) ? $vendor->get_address() : null;
			$name = is_object( $vendor ) && method_exists( $vendor, 'get_shop_name' ) ? $vendor->get_shop_name() : '';
		} catch ( \Throwable ) {
			return $this->error( 'unavailable' );
		}
		if ( ! is_array( $address ) ) {
			return $this->error( 'missing' );
		}
		$result = array( 'store_name' => $this->text( $name, 200 ) );
		foreach ( array( 'street_1', 'street_2', 'city', 'zip', 'state', 'country' ) as $field ) {
			$result[ $field ] = $this->text( $address[ $field ] ?? '', 300 );
		}
		if ( '' === $result['street_1'] || '' === $result['city'] || '' === $result['state'] || '' === $result['country'] ) {
			return $this->error( 'incomplete' );
		}
		if ( IranProvinceCatalog::COUNTRY_CODE === strtoupper( $result['country'] ) ) {
			$province = IranProvinceCatalog::find( IranProvinceCatalog::all(), $result['state'] );
			if ( null !== $province ) {
				$result['state'] = $province['name'];
			}
			$result['country'] = __( 'ایران', 'zarbalman-core' );
		}
		return $result;
	}

	private function text( mixed $value, int $max ): string {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	private function error( string $code ): WP_Error {
		return new WP_Error( 'zbm_pickup_address_' . $code, __( 'نشانی کامل فروشگاه در Dokan در دسترس نیست؛ فروشنده باید نشانی فروشگاه را تکمیل کند.', 'zarbalman-core' ) );
	}
}

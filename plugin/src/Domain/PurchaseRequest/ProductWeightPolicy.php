<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\PurchaseRequest;

use WP_Error;
use Zarbalman\Core\Domain\Order\WeightValue;

final class ProductWeightPolicy {
	/** @return int|WP_Error */
	public static function resolve_tenths( mixed $product_weight, mixed $parent_weight = '' ): int|WP_Error {
		$candidate = is_scalar( $product_weight ) ? trim( (string) $product_weight ) : '';
		if ( '' === $candidate ) {
			$candidate = is_scalar( $parent_weight ) ? trim( (string) $parent_weight ) : '';
		}

		$tenths = WeightValue::parse_tenths( $candidate );
		if ( is_wp_error( $tenths ) ) {
			return new WP_Error(
				'zbm_product_weight_unavailable',
				__( 'وزن هر بسته در اطلاعات محصول ثبت نشده یا معتبر نیست. فروشنده باید وزن واقعی هر بسته را به کیلوگرم و حداکثر با یک رقم اعشار وارد کند؛ مانند ۰٫۸ یا ۱.', 'zarbalman-core' )
			);
		}

		return $tenths;
	}
}

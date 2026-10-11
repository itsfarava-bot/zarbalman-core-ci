<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Order;

use WP_Error;

/**
 * Keeps the workflow's kilogram contract explicit at every write boundary.
 */
final class WeightUnitPolicy {
	public const REQUIRED_UNIT = 'kg';

	public function current_unit(): string {
		$unit = get_option( 'woocommerce_weight_unit', self::REQUIRED_UNIT );
		return is_string( $unit ) ? sanitize_key( $unit ) : '';
	}

	public function is_satisfied(): bool {
		return self::REQUIRED_UNIT === $this->current_unit();
	}

	public function error(): WP_Error {
		return new WP_Error(
			'zbm_weight_unit_mismatch',
			__( 'واحد وزن ووکامرس باید روی کیلوگرم تنظیم شود. تا اصلاح تنظیم، ثبت یا تأیید وزن متوقف است.', 'zarbalman-core' )
		);
	}
}

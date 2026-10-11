<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

final class StatusRegistryInspector {
	/** @return array<string,string> slug => label */
	public function expected(): array {
		return array(
			'pending_supplier'    => 'در انتظار تأمین‌کننده',
			'proforma_sent'       => 'پیش‌فاکتور ارسال‌شده',
			// The original slugs exceeded WooCommerce's 20-character status-key
			// limit when the `wc-` prefix is added. These are the replacement
			// slugs created in the Custom Order Status manager.
			'awaiting_deposit'   => 'در انتظار پیش‌پرداخت',
			'prepayment_done'     => 'پیش‌پرداخت انجام‌شده',
			'preparing'           => 'در حال آماده‌سازی',
			'shipping'            => 'در حال ارسال',
			'vendor_delivered'   => 'تحویل توسط فروشنده',
			'buyer_confirmed'     => 'تأیید خریدار',
			'settled'             => 'تسویه‌شده',
		);
	}

	/** @return list<array{slug:string,label:string,key:string,registered:bool,length_safe:bool,registered_label:string}> */
	public function inspect(): array {
		$registered = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$results    = array();

		foreach ( $this->expected() as $slug => $label ) {
			$key       = 'wc-' . $slug;
			$found_key = array_key_exists( $key, $registered ) ? $key : $this->find_by_label( $registered, $label );

			$results[] = array(
				'slug'             => $slug,
				'label'            => $label,
				'key'              => $found_key ?: $key,
				'registered'       => false !== $found_key,
				'length_safe'      => strlen( $key ) <= 20,
				'registered_label' => false !== $found_key ? (string) $registered[ $found_key ] : '',
			);
		}

		return $results;
	}

	/** @param array<string,string> $registered */
	private function find_by_label( array $registered, string $label ): string|false {
		foreach ( $registered as $key => $registered_label ) {
			if ( trim( wp_strip_all_tags( (string) $registered_label ) ) === $label ) {
				return (string) $key;
			}
		}

		return false;
	}
}

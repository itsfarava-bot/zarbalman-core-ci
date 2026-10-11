<?php

declare(strict_types=1);

namespace Zarbalman\Core\Application;

use WP_Error;
use Zarbalman\Core\Infrastructure\AuditLogRepository;
use Zarbalman\Core\Infrastructure\ProtectedText;
use Zarbalman\Core\Security\AdminCapabilities;

/** Encrypted, default-off administrator settings for real manual-bank payments. */
final class ManualPaymentSettings {
	private const OPTION = 'zbm_core_real_manual_payment';
	private const BINDING = 'global-manual-bank-account-v1';

	public function __construct(
		private readonly ProtectedText $protected,
		private readonly AuditLogRepository $audit
	) {}

	public function enabled(): bool {
		$settings = $this->stored();
		return true === ( $settings['enabled'] ?? false ) && array() !== $this->publicAccount();
	}

	/** @return array{card_number:string,holder_name:string}|array{} */
	public function publicAccount(): array {
		$settings = $this->stored();
		$encrypted = $settings['account_encrypted'] ?? '';
		if ( ! is_string( $encrypted ) || '' === $encrypted ) {
			return array();
		}
		try {
			$plain = $this->protected->decrypt( 'manual_account_settings', 0, self::BINDING, $encrypted );
			$data = json_decode( $plain, true, 4, JSON_THROW_ON_ERROR );
		} catch ( \Throwable ) {
			return array();
		}
		if (
			! is_array( $data )
			|| ! self::validCard( $data['card_number'] ?? null )
			|| ! is_string( $data['holder_name'] ?? null )
			|| '' === trim( $data['holder_name'] )
			|| strlen( $data['holder_name'] ) > 200
		) {
			return array();
		}
		return array( 'card_number' => $data['card_number'], 'holder_name' => $data['holder_name'] );
	}

	/** @param array<string,mixed> $values */
	public function save( int $admin_id, array $values, string $reason ): true|WP_Error {
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( ! AdminCapabilities::userCanManageFinance( $admin_id ) || '' === $reason || strlen( $reason ) > 1000 ) {
			return $this->error( 'permission' );
		}
		if ( ! $this->protected->ensureKey() ) {
			return $this->error( 'encryption' );
		}
		$enabled = true === ( $values['enabled'] ?? false ) || 'yes' === ( $values['enabled'] ?? null ) || '1' === ( $values['enabled'] ?? null );
		$raw_card = self::normalizeDigits( is_scalar( $values['card_number'] ?? null ) ? (string) $values['card_number'] : '' );
		if ( '' !== trim( $raw_card ) && 1 !== preg_match( '/\A[0-9\s-]+\z/u', $raw_card ) ) {
			return $this->error( 'account' );
		}
		$card = preg_replace( '/[\s-]+/u', '', $raw_card ) ?? '';
		$name = sanitize_text_field( is_scalar( $values['holder_name'] ?? null ) ? (string) $values['holder_name'] : '' );
		$existing = $this->publicAccount();
		if ( array() !== $existing ) {
			$card = '' === $card ? $existing['card_number'] : $card;
			$name = '' === trim( $name ) ? $existing['holder_name'] : $name;
		}
		if ( ( $enabled || '' !== $card || '' !== $name ) && ( ! self::validCard( $card ) || '' === trim( $name ) || strlen( $name ) > 200 ) ) {
			return $this->error( 'account' );
		}

		$encrypted = '';
		if ( '' !== $card ) {
			try {
				$plain = wp_json_encode( array( 'card_number' => $card, 'holder_name' => $name ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
				$encrypted = $this->protected->encrypt( 'manual_account_settings', 0, self::BINDING, $plain );
			} catch ( \Throwable ) {
				return $this->error( 'encryption' );
			}
		}
		$record = array(
			'format'            => 1,
			'enabled'           => $enabled,
			'account_encrypted' => $encrypted,
			'updated_at_gmt'    => current_time( 'mysql', true ),
			'updated_by'        => $admin_id,
		);
		$previous = get_option( self::OPTION, null );
		if ( false === update_option( self::OPTION, $record, false ) && $previous !== $record ) {
			return $this->error( 'storage' );
		}
		$event = wp_generate_uuid4();
		if ( false === $this->audit->append_resource(
			'setting', null, null, 'manual_payment_settings_saved', null, $enabled ? 'enabled' : 'disabled',
			'admin', $admin_id, $event,
			array( 'enabled' => $enabled, 'account_hash' => '' === $card ? null : hash( 'sha256', $card ), 'reason_hash' => hash( 'sha256', $reason ) ),
			$event
		) ) {
			if ( null === $previous ) {
				delete_option( self::OPTION );
			} else {
				update_option( self::OPTION, $previous, false );
			}
			return $this->error( 'audit' );
		}
		return true;
	}

	/** @return array<string,mixed> */
	private function stored(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) && 1 === ( $value['format'] ?? null ) ? $value : array();
	}

	private static function validCard( mixed $card ): bool {
		if ( ! is_string( $card ) || 1 !== preg_match( '/\A[0-9]{16}\z/', $card ) || 1 === preg_match( '/\A([0-9])\1{15}\z/', $card ) ) {
			return false;
		}
		$sum = 0;
		for ( $index = 0; $index < 16; ++$index ) {
			$digit = (int) $card[ $index ] * ( 0 === $index % 2 ? 2 : 1 );
			$sum += $digit > 9 ? $digit - 9 : $digit;
		}
		return 0 === $sum % 10;
	}

	private static function normalizeDigits( string $value ): string {
		return strtr( $value, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' ) );
	}

	private function error( string $code ): WP_Error {
		return new WP_Error( 'zbm_manual_settings_' . $code, __( 'تنظیمات پرداخت دستی ذخیره نشد؛ دسترسی، شماره کارت و نام دارنده را بررسی کنید.', 'zarbalman-core' ) );
	}
}

<?php

declare(strict_types=1);

namespace Zarbalman\Core\Integration;

use JsonException;

final class ZarinpalVerificationResponse {
	private const VERIFIED    = 'verified';
	private const REJECTED    = 'rejected';
	private const UNAVAILABLE = 'unavailable';

	private function __construct(
		private readonly string $result,
		private readonly ?string $reference = null
	) {}

	public static function fromHttp( int $http_status, string $body ): self {
		if ( $http_status < 200 || $http_status >= 300 || '' === trim( $body ) ) {
			return new self( self::UNAVAILABLE );
		}

		try {
			$decoded = json_decode( $body, true, 32, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			return new self( self::UNAVAILABLE );
		}
		if ( ! is_array( $decoded ) ) {
			return new self( self::UNAVAILABLE );
		}

		$data = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();
		if ( array_key_exists( 'code', $data ) && ( is_int( $data['code'] ) || ( is_string( $data['code'] ) && preg_match( '/\A-?[0-9]{1,6}\z/', $data['code'] ) ) ) ) {
			$code = (int) $data['code'];
			if ( in_array( $code, array( 100, 101 ), true ) ) {
				$reference = isset( $data['ref_id'] ) && ( is_string( $data['ref_id'] ) || is_int( $data['ref_id'] ) )
					? trim( (string) $data['ref_id'] )
					: '';
				return 1 === preg_match( '/\A[1-9][0-9]{0,127}\z/', $reference )
					? new self( self::VERIFIED, $reference )
					: new self( self::UNAVAILABLE );
			}
			return new self( self::REJECTED );
		}

		return new self( self::UNAVAILABLE );
	}

	public function isVerified(): bool {
		return self::VERIFIED === $this->result;
	}

	public function isRejected(): bool {
		return self::REJECTED === $this->result;
	}

	public function isUnavailable(): bool {
		return self::UNAVAILABLE === $this->result;
	}

	public function reference(): ?string {
		return $this->reference;
	}
}

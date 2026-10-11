<?php

declare(strict_types=1);

namespace Zarbalman\Core\Security;

final class AdminCapabilities {
	public const MANAGE_OPERATIONS = 'zbm_manage_operations';
	public const MANAGE_FINANCE    = 'zbm_manage_finance';
	public const MANAGE_TEST_MODE  = 'zbm_manage_test_mode';

	public static function install(): void {
		$administrator = get_role( 'administrator' );
		if ( ! $administrator instanceof \WP_Role ) {
			return;
		}
		foreach ( self::all() as $capability ) {
			if ( ! $administrator->has_cap( $capability ) ) {
				$administrator->add_cap( $capability, true );
			}
		}
	}

	/** @return list<string> */
	public static function all(): array {
		return array( self::MANAGE_OPERATIONS, self::MANAGE_FINANCE, self::MANAGE_TEST_MODE );
	}

	public static function currentUserCanOperate(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( self::MANAGE_OPERATIONS );
	}

	public static function currentUserCanManageTestMode(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( self::MANAGE_TEST_MODE );
	}

	public static function currentUserCanManageFinance(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( self::MANAGE_FINANCE );
	}

	public static function userCanOperate( int $user_id ): bool {
		return $user_id > 0
			&& user_can( $user_id, 'manage_options' )
			&& user_can( $user_id, self::MANAGE_OPERATIONS );
	}

	public static function userCanManageTestMode( int $user_id ): bool {
		return $user_id > 0
			&& user_can( $user_id, 'manage_options' )
			&& user_can( $user_id, self::MANAGE_TEST_MODE );
	}

	public static function userCanManageFinance( int $user_id ): bool {
		return $user_id > 0
			&& user_can( $user_id, 'manage_options' )
			&& user_can( $user_id, self::MANAGE_FINANCE );
	}

	private function __construct() {}
}

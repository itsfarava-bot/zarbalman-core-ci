<?php

declare(strict_types=1);

namespace Zarbalman\Core\Application;

use WP_Error;
use Zarbalman\Core\Infrastructure\AuditLogRepository;
use Zarbalman\Core\Infrastructure\DependencyHealth;
use Zarbalman\Core\Security\AdminCapabilities;

final class AdminTestModeManager {
	public const OPTION              = 'zbm_core_admin_test_mode_enabled';
	public const ENABLE_CONFIRMATION = 'حالت آزمایشی را فعال می‌کنم';

	public function __construct(
		private readonly DependencyHealth $health,
		private readonly AuditLogRepository $audit
	) {}

	public function isConfigured(): bool {
		return 'yes' === (string) get_option( self::OPTION, 'no' );
	}

	public function isEnabled(): bool {
		return $this->isConfigured()
			&& ! $this->health->canCreateDepositOrder()
			&& $this->health->canCreateTestManagedOrder();
	}

	public function enable( int $admin_id, string $reason, string $confirmation ): true|WP_Error {
		unset( $admin_id, $reason, $confirmation );

		// Spec 3 permanently retires creation of simulated-payment cases. Keep this
		// public method only as a fail-closed compatibility boundary for old callers;
		// persisted test_pending records are recovered through their dedicated path.
		return new WP_Error(
			'zbm_test_mode_retired',
			__( 'شروع پروندهٔ آزمایشی جدید متوقف شده است؛ برای قطعی‌کردن وجه واقعی از مسیر پرداخت دستی استفاده کنید.', 'zarbalman-core' )
		);
	}

	public function disable( int $admin_id, string $reason ): true|WP_Error {
		$reason = $this->validatedReason( $admin_id, $reason );
		if ( $reason instanceof WP_Error ) {
			return $reason;
		}
		return $this->write( false, $admin_id, $reason, 'admin_test_mode_disabled' );
	}

	public function synchronizeWithGateway(): void {
		if ( ! $this->isConfigured() || ! $this->health->canCreateDepositOrder() ) {
			return;
		}
		update_option( self::OPTION, 'no', false );
		$this->audit->append_resource(
			'system', null, null, 'admin_test_mode_auto_locked', null, null,
			'system', 0, wp_generate_uuid4(),
			array( 'reason' => 'live_gateway_health_restored' )
		);
	}

	private function write( bool $enabled, int $admin_id, string $reason, string $event_key ): true|WP_Error {
		$previous = $this->isConfigured();
		if ( $previous === $enabled ) {
			return true;
		}
		$updated = update_option( self::OPTION, $enabled ? 'yes' : 'no', false );
		if ( ! $updated ) {
			return new WP_Error( 'zbm_test_mode_store_failed', __( 'ذخیرهٔ وضعیت حالت آزمایشی انجام نشد.', 'zarbalman-core' ) );
		}
		$event = $this->audit->append_resource(
			'system', null, null, $event_key, null, null,
			'admin', $admin_id, wp_generate_uuid4(), array( 'reason' => $reason )
		);
		if ( false === $event ) {
			update_option( self::OPTION, $previous ? 'yes' : 'no', false );
			return new WP_Error( 'zbm_test_mode_audit_failed', __( 'حالت آزمایشی تغییر نکرد، چون ثبت حسابرسی آن ناموفق بود.', 'zarbalman-core' ) );
		}
		return true;
	}

	private function validatedReason( int $admin_id, string $reason ): string|WP_Error {
		$reason = trim( sanitize_textarea_field( $reason ) );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $reason, 'UTF-8' ) : strlen( $reason );
		if (
			! AdminCapabilities::userCanManageTestMode( $admin_id )
			|| '' === $reason
			|| $length > 1000
			|| PurchaseRequestInput::containsContactData( $reason )
		) {
			return new WP_Error( 'zbm_test_mode_admin_invalid', __( 'دسترسی یا دلیل مدیریت معتبر نیست؛ اطلاعات تماس نیز در دلیل ننویسید.', 'zarbalman-core' ) );
		}
		return $reason;
	}
}

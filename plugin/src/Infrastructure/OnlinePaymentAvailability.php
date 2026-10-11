<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use Throwable;
use WP_Error;
use wpdb;
use Zarbalman\Core\Security\AdminCapabilities;

/** New-link availability only. Never used to authorize or disable callbacks. */
final class OnlinePaymentAvailability {
	private const OPTION = 'zbm_core_online_payment_control';
	private const HEALTH = 'zbm_core_online_payment_observation';

	public function __construct( private readonly wpdb $db, private readonly AuditLogRepository $audit ) {}

	public function permitsNewLink(): bool {
		$control = $this->control();
		if ( $control['stopped'] ) { return false; }
		$observation = get_transient( self::HEALTH );
		return ! is_array( $observation ) || true !== ( $observation['failed'] ?? false );
	}

	/** Contains no gateway secrets or customer/payment identifiers. */
	public function control(): array {
		$value = get_option( self::OPTION, null );
		if ( null === $value ) { return array( 'revision' => 0, 'stopped' => false, 'actor_id' => 0, 'at_gmt' => null, 'reason' => '' ); }
		if ( ! is_array( $value ) || ! is_int( $value['revision'] ?? null ) || ! is_bool( $value['stopped'] ?? null ) ) {
			return array( 'revision' => -1, 'stopped' => true, 'actor_id' => 0, 'at_gmt' => null, 'reason' => '' );
		}
		return $value;
	}

	/** Observe real provider issuance only; no probe payments and no page-render requests. */
	public function observe( bool $success ): void {
		set_transient( self::HEALTH, array( 'failed' => ! $success, 'at_gmt' => current_time( 'mysql', true ) ), $success ? 60 : 300 );
	}

	/** Serializes admin edits with an audit record in the same DB transaction. */
	public function setStopped( int $actor_id, bool $stopped, int $revision, string $reason ): true|WP_Error {
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( ! AdminCapabilities::userCanManageFinance( $actor_id ) || $revision < 0 || '' === $reason || strlen( $reason ) > 1000 ) { return $this->error(); }
		$lock = 'zbm-online-setting-' . substr( hash( 'sha256', $this->db->options ), 0, 32 );
		$acquired = false;
		$transaction = false;
		try {
			$acquired = '1' === (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) );
			if ( ! $acquired ) { return $this->error(); }
			if ( false === $this->db->query( 'START TRANSACTION' ) ) { return $this->error(); }
			$transaction = true;
			$raw = $this->db->get_var( $this->db->prepare( 'SELECT option_value FROM %i WHERE option_name = %s FOR UPDATE', $this->db->options, self::OPTION ) );
			if ( '' !== $this->db->last_error ) { throw new \RuntimeException( 'Control read failed.' ); }
			$previous = null === $raw ? array( 'revision' => 0, 'stopped' => false ) : maybe_unserialize( $raw );
			if ( ! is_array( $previous ) || ( $previous['revision'] ?? null ) !== $revision || $revision >= PHP_INT_MAX ) { throw new \RuntimeException( 'Stale control edit.' ); }
			$next = array( 'revision' => $revision + 1, 'stopped' => $stopped, 'actor_id' => $actor_id, 'at_gmt' => current_time( 'mysql', true ), 'reason' => $reason );
			$written = null === $raw
				? $this->db->insert( $this->db->options, array( 'option_name' => self::OPTION, 'option_value' => maybe_serialize( $next ), 'autoload' => 'no' ) )
				: $this->db->update( $this->db->options, array( 'option_value' => maybe_serialize( $next ), 'autoload' => 'no' ), array( 'option_name' => self::OPTION ) );
			$uuid = wp_generate_uuid4();
			if ( 1 !== $written || false === $this->audit->append_resource( 'setting', null, null, 'online_payment_control_changed', $previous['stopped'] ? 'stopped' : 'automatic', $stopped ? 'stopped' : 'automatic', 'admin', $actor_id, $uuid, array( 'reason_hash' => hash( 'sha256', $reason ), 'revision' => $next['revision'] ), $uuid ) ) { throw new \RuntimeException( 'Control audit failed.' ); }
			if ( false === $this->db->query( 'COMMIT' ) ) { throw new \RuntimeException( 'Control commit unknown.' ); }
			$transaction = false;
			return true;
		} catch ( Throwable ) { return $this->error(); }
		finally {
			if ( $transaction ) { $this->db->query( 'ROLLBACK' ); }
			if ( $acquired ) {
				wp_cache_delete( self::OPTION, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}
	}

	private function error(): WP_Error {
		return new WP_Error( 'zbm_online_control', __( 'تغییر وضعیت درگاه تأیید نشد؛ صفحه را تازه کنید و آخرین وضعیت را بررسی کنید.', 'zarbalman-core' ) );
	}
}

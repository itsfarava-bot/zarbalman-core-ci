<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use wpdb;

final class Schema {
	public function __construct( private readonly wpdb $db ) {}

	public function workflows_table(): string {
		return $this->db->prefix . 'zbm_workflows';
	}

	public function audit_table(): string {
		return $this->db->prefix . 'zbm_audit_logs';
	}

	public function idempotency_table(): string {
		return $this->db->prefix . 'zbm_idempotency_keys';
	}

	public function weights_table(): string {
		return $this->db->prefix . 'zbm_order_weights';
	}

	public function purchase_requests_table(): string {
		return $this->db->prefix . 'zbm_purchase_requests';
	}

	public function payments_table(): string {
		return $this->db->prefix . 'zbm_payments';
	}

	public function ledger_table(): string {
		return $this->db->prefix . 'zbm_ledger_entries';
	}
	public function financial_actions_table(): string {
		return $this->db->prefix . 'zbm_financial_actions';
	}

	public function notification_outbox_table(): string {
		return $this->db->prefix . 'zbm_notification_outbox';
	}

	public function dokan_credit_mirrors_table(): string {
		return $this->db->prefix . 'zbm_dokan_credit_mirrors';
	}

	/** Canonical request-keyed managed aggregate (DB10, additive). */
	public function managed_cases_table(): string {
		return $this->db->prefix . 'zbm_managed_cases';
	}

	/** Durable command identities; never subject to transient idempotency expiry. */
	public function managed_case_commands_table(): string {
		return $this->db->prefix . 'zbm_managed_case_commands';
	}

	/** @return list<string> */
	public function tables(): array {
		return array(
			$this->workflows_table(),
			$this->audit_table(),
			$this->idempotency_table(),
			$this->weights_table(),
			$this->purchase_requests_table(),
			$this->payments_table(),
			$this->ledger_table(),
			$this->financial_actions_table(),
			$this->notification_outbox_table(),
			$this->dokan_credit_mirrors_table(),
			$this->managed_cases_table(),
			$this->managed_case_commands_table(),
		);
	}

	/** @return list<string> */
	public function creation_sql(): array {
		$collate = $this->db->get_charset_collate();

		return array(
			"CREATE TABLE {$this->financial_actions_table()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				request_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned DEFAULT NULL,
				phase varchar(40) NOT NULL,
				action_type varchar(60) NOT NULL,
				status varchar(24) NOT NULL,
				currency char(3) NOT NULL DEFAULT 'IRT',
				base_amount_toman bigint(20) unsigned NOT NULL,
				site_amount_toman bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_amount_toman bigint(20) unsigned NOT NULL DEFAULT 0,
				buyer_refund_amount_toman bigint(20) unsigned NOT NULL DEFAULT 0,
				payment_id bigint(20) unsigned DEFAULT NULL,
				ledger_entry_id bigint(20) unsigned DEFAULT NULL,
				allocation_key char(64) NOT NULL,
				idempotency_key char(64) NOT NULL,
				destination_ciphertext longtext DEFAULT NULL,
				destination_fingerprint char(64) DEFAULT NULL,
				destination_last4 varchar(8) DEFAULT NULL,
				reason_ciphertext longtext DEFAULT NULL,
				evidence_json longtext DEFAULT NULL,
				external_reference varchar(190) DEFAULT NULL,
				external_refund_hash char(64) DEFAULT NULL,
				failure_code varchar(80) DEFAULT NULL,
				failure_message_safe text DEFAULT NULL,
				created_by bigint(20) unsigned NOT NULL,
				approved_by bigint(20) unsigned DEFAULT NULL,
				executed_by bigint(20) unsigned DEFAULT NULL,
				reconciled_by bigint(20) unsigned DEFAULT NULL,
				failed_by bigint(20) unsigned DEFAULT NULL,
				created_at_gmt datetime NOT NULL,
				approved_at_gmt datetime DEFAULT NULL,
				executed_at_gmt datetime DEFAULT NULL,
				failed_at_gmt datetime DEFAULT NULL,
				reconciled_at_gmt datetime DEFAULT NULL,
				updated_at_gmt datetime NOT NULL,
				version bigint(20) unsigned NOT NULL DEFAULT 1,
				PRIMARY KEY  (id),
				UNIQUE KEY allocation_key (allocation_key),
				UNIQUE KEY idempotency_key (idempotency_key),
				UNIQUE KEY external_refund_hash (external_refund_hash),
				KEY request_status (request_id, status),
				KEY type_status (action_type, status),
				KEY deadline_queue (status, created_at_gmt)
			) ENGINE=InnoDB {$collate};",
			"CREATE TABLE {$this->managed_cases_table()} (
				request_id bigint(20) unsigned NOT NULL,
				version bigint(20) unsigned NOT NULL DEFAULT 0,
				state varchar(64) NOT NULL,
				mode varchar(16) NOT NULL,
				sync_pending tinyint(1) unsigned NOT NULL DEFAULT 0,
				deadline_gmt datetime DEFAULT NULL,
				closed_at_gmt datetime DEFAULT NULL,
				data_json longtext NOT NULL,
				created_at_gmt datetime NOT NULL,
				updated_at_gmt datetime NOT NULL,
				PRIMARY KEY  (request_id),
				KEY due_cases (deadline_gmt, state),
				KEY pending_sync (sync_pending, request_id),
				KEY closed_cases (closed_at_gmt, state)
			) ENGINE=InnoDB {$collate};",
			"CREATE TABLE {$this->managed_case_commands_table()} (
				request_id bigint(20) unsigned NOT NULL,
				key_hash char(64) NOT NULL,
				payload_hash char(64) NOT NULL,
				event_key varchar(100) NOT NULL,
				actor_type varchar(32) NOT NULL,
				actor_id bigint(20) unsigned NOT NULL,
				result_version bigint(20) unsigned NOT NULL,
				event_uuid char(36) NOT NULL,
				created_at_gmt datetime NOT NULL,
				PRIMARY KEY  (request_id, key_hash),
				UNIQUE KEY event_uuid (event_uuid)
			) ENGINE=InnoDB {$collate};",
			"CREATE TABLE {$this->workflows_table()} (
				order_id bigint(20) unsigned NOT NULL,
				current_state varchar(64) NOT NULL,
				version bigint(20) unsigned NOT NULL DEFAULT 0,
				is_paused tinyint(1) unsigned NOT NULL DEFAULT 0,
				pause_reason varchar(1000) DEFAULT NULL,
				paused_at_gmt datetime DEFAULT NULL,
				paused_remaining_seconds bigint(20) unsigned DEFAULT NULL,
				delivered_at_gmt datetime DEFAULT NULL,
				buyer_deadline_gmt datetime DEFAULT NULL,
				vendor_deadline_gmt datetime DEFAULT NULL,
				created_at_gmt datetime NOT NULL,
				updated_at_gmt datetime NOT NULL,
				PRIMARY KEY  (order_id),
				KEY current_state (current_state),
				KEY buyer_deadline (buyer_deadline_gmt, is_paused),
				KEY vendor_deadline (vendor_deadline_gmt, is_paused),
				KEY updated_at_gmt (updated_at_gmt)
			) ENGINE=InnoDB {$collate};",
			"CREATE TABLE {$this->audit_table()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_uuid char(36) NOT NULL,
				order_id bigint(20) unsigned DEFAULT NULL,
				resource_type varchar(32) NOT NULL DEFAULT 'order',
				resource_id bigint(20) unsigned DEFAULT NULL,
				event_key varchar(100) NOT NULL,
				from_state varchar(64) DEFAULT NULL,
				to_state varchar(64) DEFAULT NULL,
				actor_type varchar(32) NOT NULL,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				correlation_id char(36) NOT NULL,
				metadata_json longtext NULL,
				created_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_uuid (event_uuid),
				KEY order_id (order_id),
				KEY resource (resource_type, resource_id),
				KEY event_key (event_key),
				KEY correlation_id (correlation_id),
				KEY created_at_gmt (created_at_gmt)
			) ENGINE=InnoDB {$collate};",
			"CREATE TABLE {$this->idempotency_table()} (
				key_hash char(64) NOT NULL,
				namespace varchar(64) NOT NULL,
				payload_hash char(64) NOT NULL,
				status varchar(16) NOT NULL,
				result_event_uuid char(36) DEFAULT NULL,
				result_resource_id bigint(20) unsigned DEFAULT NULL,
				expires_at_gmt datetime NOT NULL,
				created_at_gmt datetime NOT NULL,
				updated_at_gmt datetime NOT NULL,
				PRIMARY KEY  (key_hash),
				KEY namespace (namespace),
				KEY result_resource (namespace, result_resource_id),
				KEY status_expires (status, expires_at_gmt)
			) ENGINE=InnoDB {$collate};",
			"CREATE TABLE {$this->weights_table()} (
				order_id bigint(20) unsigned NOT NULL,
				order_item_id bigint(20) unsigned NOT NULL,
				vendor_weight_tenths bigint(20) unsigned DEFAULT NULL,
				buyer_weight_tenths bigint(20) unsigned DEFAULT NULL,
				final_weight_tenths bigint(20) unsigned DEFAULT NULL,
				vendor_recorded_at_gmt datetime DEFAULT NULL,
				buyer_recorded_at_gmt datetime DEFAULT NULL,
				finalized_at_gmt datetime DEFAULT NULL,
				updated_at_gmt datetime NOT NULL,
				PRIMARY KEY  (order_id, order_item_id),
				KEY order_item_id (order_item_id),
				KEY updated_at_gmt (updated_at_gmt)
			) ENGINE=InnoDB {$collate};",
			"CREATE TABLE {$this->purchase_requests_table()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(36) NOT NULL,
				request_number char(6) DEFAULT NULL,
				buyer_id bigint(20) unsigned NOT NULL,
				vendor_id bigint(20) unsigned NOT NULL,
				product_id bigint(20) unsigned NOT NULL,
				variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
				quantity bigint(20) unsigned NOT NULL,
				package_price_toman bigint(20) unsigned NOT NULL,
				nominal_package_weight_tenths bigint(20) unsigned NOT NULL,
				reference_weight_tenths bigint(20) unsigned NOT NULL,
				estimated_total_toman bigint(20) unsigned NOT NULL,
				pricing_version tinyint(3) unsigned NOT NULL DEFAULT 1,
				lines_json longtext NULL,
				estimated_shipping_toman bigint(20) unsigned NOT NULL DEFAULT 0,
				shipping_rate_per_kg_toman bigint(20) unsigned NOT NULL DEFAULT 0,
				deposit_toman bigint(20) unsigned NOT NULL,
				delivery_method varchar(40) NOT NULL,
				delivery_window_type varchar(16) NOT NULL,
				delivery_start_gmt datetime NOT NULL,
				delivery_end_gmt datetime NOT NULL,
				delivery_payload_encrypted longtext NOT NULL,
				current_state varchar(64) NOT NULL,
				version bigint(20) unsigned NOT NULL DEFAULT 0,
				is_paused tinyint(1) unsigned NOT NULL DEFAULT 0,
				pause_reason varchar(1000) DEFAULT NULL,
				paused_at_gmt datetime DEFAULT NULL,
				paused_remaining_seconds bigint(20) unsigned DEFAULT NULL,
				supplier_deadline_gmt datetime DEFAULT NULL,
				deposit_deadline_gmt datetime DEFAULT NULL,
				wc_order_id bigint(20) unsigned DEFAULT NULL,
				payment_ready tinyint(1) unsigned NOT NULL DEFAULT 0,
				needs_reconciliation tinyint(1) unsigned NOT NULL DEFAULT 0,
				stock_operation varchar(32) DEFAULT NULL,
				stock_reserved tinyint(1) unsigned NOT NULL DEFAULT 0,
				stock_released tinyint(1) unsigned NOT NULL DEFAULT 0,
				execution_mode varchar(16) NOT NULL DEFAULT 'live',
				test_advance_reason varchar(1000) DEFAULT NULL,
				test_advanced_by bigint(20) unsigned DEFAULT NULL,
				test_advance_started_at_gmt datetime DEFAULT NULL,
				test_advanced_at_gmt datetime DEFAULT NULL,
				rejection_reason varchar(1000) DEFAULT NULL,
				accepted_at_gmt datetime DEFAULT NULL,
				rejected_at_gmt datetime DEFAULT NULL,
				created_at_gmt datetime NOT NULL,
				updated_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY request_number (request_number),
				UNIQUE KEY wc_order_id (wc_order_id),
				KEY buyer_state (buyer_id, current_state),
				KEY vendor_state (vendor_id, current_state),
				KEY supplier_deadline (supplier_deadline_gmt, is_paused, current_state),
				KEY deposit_deadline (deposit_deadline_gmt, is_paused, current_state),
				KEY execution_mode_state (execution_mode, current_state),
				KEY updated_at_gmt (updated_at_gmt)
			) ENGINE=InnoDB {$collate};",
			"CREATE TABLE {$this->payments_table()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				request_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				provider varchar(32) NOT NULL,
				phase varchar(32) NOT NULL,
				expected_amount_toman bigint(20) unsigned NOT NULL,
				paid_amount_toman bigint(20) unsigned DEFAULT NULL,
				status varchar(32) NOT NULL,
				authority varchar(128) DEFAULT NULL,
				reference varchar(128) DEFAULT NULL,
				merchant_fingerprint char(64) DEFAULT NULL,
				callback_at_gmt datetime DEFAULT NULL,
				intent_deadline_gmt datetime DEFAULT NULL,
				idempotency_hash char(64) NOT NULL,
				paid_at_gmt datetime DEFAULT NULL,
				created_at_gmt datetime NOT NULL,
				updated_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY idempotency_hash (idempotency_hash),
				UNIQUE KEY provider_authority (provider, authority),
				UNIQUE KEY provider_reference (provider, reference),
				KEY request_phase (request_id, phase),
				KEY order_id (order_id),
				KEY status (status)
			) ENGINE=InnoDB {$collate};",
			"CREATE TABLE {$this->ledger_table()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				request_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				payment_id bigint(20) unsigned DEFAULT NULL,
				entry_type varchar(64) NOT NULL,
				owner_type varchar(32) NOT NULL,
				vendor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				amount_toman bigint(20) NOT NULL,
				currency char(3) NOT NULL DEFAULT 'IRT',
				status varchar(32) NOT NULL,
				allocation_key char(64) NOT NULL,
				metadata_json longtext DEFAULT NULL,
				created_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY allocation_key (allocation_key),
				KEY request_id (request_id),
				KEY order_id (order_id),
				KEY payment_id (payment_id),
				KEY vendor_status (vendor_id, status),
				KEY created_at_gmt (created_at_gmt)
			) ENGINE=InnoDB {$collate};",
			"CREATE TABLE {$this->notification_outbox_table()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				dedupe_hash char(64) NOT NULL,
				event_uuid char(36) NOT NULL,
				request_id bigint(20) unsigned DEFAULT NULL,
				recipient_role varchar(24) NOT NULL,
				recipient_user_id bigint(20) unsigned NOT NULL,
				template_key varchar(64) NOT NULL,
				payload_json longtext DEFAULT NULL,
				status varchar(24) NOT NULL,
				attempt_count tinyint(3) unsigned NOT NULL DEFAULT 0,
				next_attempt_gmt datetime DEFAULT NULL,
				locked_at_gmt datetime DEFAULT NULL,
				provider_message_id varchar(191) DEFAULT NULL,
				last_error varchar(1000) DEFAULT NULL,
				created_at_gmt datetime NOT NULL,
				sent_at_gmt datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY dedupe_hash (dedupe_hash),
				KEY dispatchable (status, next_attempt_gmt, locked_at_gmt),
				KEY request_id (request_id),
				KEY event_uuid (event_uuid)
			) ENGINE=InnoDB {$collate};",
			"CREATE TABLE {$this->dokan_credit_mirrors_table()} (
				allocation_hash char(64) NOT NULL,
				vendor_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				amount_toman bigint(20) unsigned NOT NULL,
				status varchar(24) NOT NULL,
				dokan_balance_id bigint(20) unsigned DEFAULT NULL,
				created_at_gmt datetime NOT NULL,
				updated_at_gmt datetime NOT NULL,
				PRIMARY KEY  (allocation_hash),
				KEY order_id (order_id),
				KEY vendor_status (vendor_id, status)
			) ENGINE=InnoDB {$collate};",
		);
	}
}

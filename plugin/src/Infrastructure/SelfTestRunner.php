<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

use Zarbalman\Core\Domain\Order\WeightValue;
use Zarbalman\Core\Domain\Order\WeightUnitPolicy;
use Zarbalman\Core\Domain\Workflow\Actor;
use Zarbalman\Core\Domain\Workflow\StateMachine;
use Zarbalman\Core\Domain\Workflow\TransitionRequest;
use Zarbalman\Core\Domain\Workflow\WorkflowDefinition;
use wpdb;

final class SelfTestRunner {
	public function __construct(
		private readonly wpdb $db,
		private readonly StateMachine $machine,
		private readonly StatusRegistryInspector $inspector,
		private readonly WeightUnitPolicy $weight_unit,
		private readonly EvidenceCrypto $evidence_crypto,
		private readonly EvidenceLegacyMigrator $evidence_migrator
	) {}

	/** @return list<array{name:string,passed:bool,message:string}> */
	public function run(): array {
		$checks = array();
		$schema = new Schema( $this->db );

		foreach ( $schema->tables() as $table ) {
			$sql    = $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $table ) );
			$exists = $table === $this->db->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
			$checks[] = $this->result( 'جدول ' . $table, $exists, $exists ? 'موجود است.' : 'ساخته نشده است.' );
		}

		$engines_ok = true;
		foreach ( $schema->tables() as $table ) {
			$engine_sql = $this->db->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table );
			$status     = $this->db->get_row( $engine_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
			$engines_ok = $engines_ok && $status && 'InnoDB' === (string) $status->Engine;
		}
		$checks[] = $this->result( 'موتور تراکنشی جدول‌ها', $engines_ok, $engines_ok ? 'همه جدول‌ها InnoDB هستند.' : 'حداقل یک جدول InnoDB نیست.' );

		$valid = $this->machine->can_transition(
			WorkflowDefinition::CREATED,
			WorkflowDefinition::DEPOSIT_PENDING,
			Actor::SYSTEM
		);
		$checks[] = $this->result( 'گذار مجاز ماشین وضعیت', $valid, $valid ? 'صحیح است.' : 'ناموفق بود.' );

		$invalid = $this->machine->can_transition(
			WorkflowDefinition::CREATED,
			WorkflowDefinition::COMPLETED,
			Actor::BUYER
		);
		$checks[] = $this->result( 'جلوگیری از گذار غیرمجاز', ! $invalid, ! $invalid ? 'صحیح است.' : 'گذار غیرمجاز پذیرفته شد.' );

		$checks[] = $this->exact_weight_test();
		$checks[] = $this->result(
			'واحد وزن کیلوگرم',
			$this->weight_unit->is_satisfied(),
			$this->weight_unit->is_satisfied() ? 'واحد ووکامرس kg است.' : 'ثبت وزن تا تنظیم واحد ووکامرس روی kg متوقف است.'
		);
		$evidence_secure = $this->evidence_crypto->is_available()
			&& $this->evidence_crypto->has_key()
			&& ! $this->evidence_migrator->has_legacy_evidence();
		$checks[] = $this->result(
			'ذخیره امن مدارک',
			$evidence_secure,
			$evidence_secure ? 'رمزنگاری فعال است و مدرک قدیمی ناامن وجود ندارد.' : 'رمزنگاری یا مهاجرت مدارک نیاز به بررسی دارد.'
		);

		$statuses_ok = ! array_filter( $this->inspector->inspect(), static fn ( array $row ): bool => ! $row['registered'] );
		$checks[]    = $this->result( 'ثبت ۹ وضعیت ووکامرس', $statuses_ok, $statuses_ok ? 'همه پیدا شدند.' : 'حداقل یک وضعیت ثبت نشده است.' );

		$checks[] = $this->result(
			'Action Scheduler',
			function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' ),
			function_exists( 'as_schedule_single_action' ) ? 'API در دسترس است.' : 'API در دسترس نیست.'
		);

		$scheduled = function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( Scheduler::CLEANUP_HOOK, array(), Scheduler::GROUP );
		$checks[]  = $this->result( 'پاک‌سازی زمان‌بندی‌شده', $scheduled, $scheduled ? 'عمل یکتا ثبت شده است.' : 'عمل پاک‌سازی ثبت نشده است.' );

		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$checks[] = $this->result( 'سازگاری HPOS', $hpos, $hpos ? 'HPOS فعال و شناسایی شد.' : 'HPOS فعال تشخیص داده نشد.' );

		$checks[] = $engines_ok
			? $this->idempotency_constraint_test( $schema )
			: $this->result( 'قید یکتایی عملیات', false, 'به دلیل ناسازگاری موتور جدول، آزمون نوشتن اجرا نشد.' );

		if ( $engines_ok ) {
			$checks = array_merge( $checks, $this->workflow_store_test( $schema ) );
		}

		return $checks;
	}

	/** @return array{name:string,passed:bool,message:string} */
	private function exact_weight_test(): array {
		$ascii       = WeightValue::parse_tenths( '0.8' );
		$persian     = WeightValue::parse_tenths( '۰٫۸' );
		$package_sum = WeightValue::parse_tenths( '22.4' );
		$invalid     = WeightValue::parse_tenths( '0.85' );
		$passed      = 8 === $ascii
			&& 8 === $persian
			&& 224 === $package_sum
			&& 224 === ( $ascii * 28 )
			&& is_wp_error( $invalid );

		return $this->result(
			'وزن دقیق بسته‌ای',
			$passed,
			$passed
				? '۰٫۸ کیلوگرم و مجموع ۲۸ × ۰٫۸ = ۲۲٫۴ بدون اعشار شناور معتبر است.'
				: 'اعتبارسنجی وزن بسته‌ای ناموفق بود.'
		);
	}

	/** @return array{name:string,passed:bool,message:string} */
	private function idempotency_constraint_test( Schema $schema ): array {
		$key     = hash( 'sha256', 'zbm-self-test-' . wp_generate_uuid4() );
		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS );

		$this->db->query( 'START TRANSACTION' );
		$first = $this->db->insert(
			$schema->idempotency_table(),
			array(
				'key_hash'       => $key,
				'namespace'      => 'self_test',
				'payload_hash'   => hash( 'sha256', 'payload' ),
				'status'         => 'processing',
				'expires_at_gmt' => $expires,
				'created_at_gmt' => $now,
				'updated_at_gmt' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$second_sql = $this->db->prepare(
			'INSERT IGNORE INTO %i (key_hash, namespace, payload_hash, status, expires_at_gmt, created_at_gmt, updated_at_gmt)
			VALUES (%s, %s, %s, %s, %s, %s, %s)',
			$schema->idempotency_table(),
			$key,
			'self_test',
			hash( 'sha256', 'payload' ),
			'processing',
			$expires,
			$now,
			$now
		);
		$second = $this->db->query( $second_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		$this->db->query( 'ROLLBACK' );

		$passed = false !== $first && 0 === $second;
		return $this->result( 'قید یکتایی عملیات', $passed, $passed ? 'عملیات تکراری رد شد.' : 'قید یکتایی درست عمل نکرد.' );
	}

	/** @return list<array{name:string,passed:bool,message:string}> */
	private function workflow_store_test( Schema $schema ): array {
		$fake_order_id = PHP_INT_MAX - random_int( 10000, 999999 );
		$key            = 'self-test-' . wp_generate_uuid4();
		$stale_key      = 'self-test-stale-' . wp_generate_uuid4();
		$correlation    = wp_generate_uuid4();
		$request        = new TransitionRequest(
			$fake_order_id,
			WorkflowDefinition::DEPOSIT_PENDING,
			Actor::SYSTEM,
			0,
			$key,
			$correlation,
			array( 'self_test' => true )
		);
		$store          = new WorkflowStore( $this->db, new AuditLogRepository( $this->db ) );
		$checks         = array();

		try {
			$first  = $store->apply( $request, WorkflowDefinition::CREATED );
			$replay = $store->replay( $request );
			$stale  = $store->apply(
				new TransitionRequest(
					$fake_order_id,
					WorkflowDefinition::DEPOSIT_PAID,
					Actor::SYSTEM,
					0,
					$stale_key,
					wp_generate_uuid4()
				),
				WorkflowDefinition::CREATED
			);

			$atomic_ok = is_array( $first )
				&& WorkflowDefinition::DEPOSIT_PENDING === $store->current_state( $fake_order_id )
				&& ! $first['duplicate'];
			$replay_ok = is_array( $replay ) && true === $replay['duplicate']
				&& isset( $first['event_uuid'] ) && $first['event_uuid'] === $replay['event_uuid'];
			$stale_ok  = is_wp_error( $stale ) && 'zbm_stale_transition' === $stale->get_error_code();

			$checks[] = $this->result( 'تراکنش اتمیک گردش', $atomic_ok, $atomic_ok ? 'وضعیت و رویداد باهم ثبت شدند.' : 'ثبت اتمیک ناموفق بود.' );
			$checks[] = $this->result( 'بازپخش امن عملیات', $replay_ok, $replay_ok ? 'پاسخ اصلی بدون اجرای دوباره بازگردانده شد.' : 'بازپخش idempotent ناموفق بود.' );
			$checks[] = $this->result( 'کنترل هم‌زمانی', $stale_ok, $stale_ok ? 'نوشتن با نسخه قدیمی رد شد.' : 'نوشتن قدیمی به‌درستی رد نشد.' );
		} finally {
			$delete_idempotency = $this->db->prepare(
				'DELETE FROM %i WHERE key_hash IN (%s, %s)',
				$schema->idempotency_table(),
				hash( 'sha256', 'workflow|' . $key ),
				hash( 'sha256', 'workflow|' . $stale_key )
			);
			$this->db->query( $delete_idempotency ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

			$delete_audit = $this->db->prepare(
				'DELETE FROM %i WHERE order_id = %d',
				$schema->audit_table(),
				$fake_order_id
			);
			$this->db->query( $delete_audit ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

			$delete_workflow = $this->db->prepare(
				'DELETE FROM %i WHERE order_id = %d',
				$schema->workflows_table(),
				$fake_order_id
			);
			$this->db->query( $delete_workflow ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		}

		return $checks;
	}

	/** @return array{name:string,passed:bool,message:string} */
	private function result( string $name, bool $passed, string $message ): array {
		return compact( 'name', 'passed', 'message' );
	}
}

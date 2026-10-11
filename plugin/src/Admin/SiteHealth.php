<?php

declare(strict_types=1);

namespace Zarbalman\Core\Admin;

use Zarbalman\Core\Domain\Order\WeightUnitPolicy;
use Zarbalman\Core\Infrastructure\DeliveryDataCrypto;
use Zarbalman\Core\Infrastructure\EvidenceCrypto;
use Zarbalman\Core\Infrastructure\EvidenceLegacyMigrator;
use Zarbalman\Core\Infrastructure\NotificationOutboxRepository;
use Zarbalman\Core\Infrastructure\DependencyHealth;
use Zarbalman\Core\Infrastructure\Schema;
use Zarbalman\Core\Infrastructure\StatusRegistryInspector;
use wpdb;

final class SiteHealth {
	public function __construct(
		private readonly wpdb $db,
		private readonly StatusRegistryInspector $inspector,
		private readonly WeightUnitPolicy $weight_unit,
		private readonly EvidenceCrypto $evidence_crypto,
		private readonly EvidenceLegacyMigrator $evidence_migrator,
		private readonly DeliveryDataCrypto $delivery_crypto,
		private readonly NotificationOutboxRepository $notification_outbox,
		private readonly DependencyHealth $dependency_health
	) {}

	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'tests' ) );
	}

	/** @param array<string,mixed> $tests
	 *  @return array<string,mixed>
	 */
	public function tests( array $tests ): array {
		$tests['direct']['zbm_core_tables'] = array(
			'label' => __( 'جدول‌های Zarbalman Core', 'zarbalman-core' ),
			'test'  => array( $this, 'test_tables' ),
		);
		$tests['direct']['zbm_core_statuses'] = array(
			'label' => __( 'وضعیت‌های سفارش Zarbalman', 'zarbalman-core' ),
			'test'  => array( $this, 'test_statuses' ),
		);
		$tests['direct']['zbm_core_weight_unit'] = array(
			'label' => __( 'واحد وزن Zarbalman Core', 'zarbalman-core' ),
			'test'  => array( $this, 'test_weight_unit' ),
		);
		$tests['direct']['zbm_core_evidence_security'] = array(
			'label' => __( 'امنیت مدارک Zarbalman Core', 'zarbalman-core' ),
			'test'  => array( $this, 'test_evidence_security' ),
		);
		$tests['direct']['zbm_core_spec3_dependencies'] = array(
			'label' => __( 'وابستگی‌های درخواست و پیش‌پرداخت زربال‌من', 'zarbalman-core' ),
			'test'  => array( $this, 'test_spec3_dependencies' ),
		);
		$tests['direct']['zbm_core_delivery_crypto'] = array(
			'label' => __( 'رمزنگاری نشانی درخواست‌های زربال‌من', 'zarbalman-core' ),
			'test'  => array( $this, 'test_delivery_crypto' ),
		);
		$tests['direct']['zbm_core_sms_outbox'] = array(
			'label' => __( 'صف پیامک خودکار زربال‌من', 'zarbalman-core' ),
			'test'  => array( $this, 'test_sms_outbox' ),
		);

		return $tests;
	}

	/** @return array<string,mixed> */
	public function test_tables(): array {
		$missing = array();
		foreach ( ( new Schema( $this->db ) )->tables() as $table ) {
			$sql = $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $table ) );
			if ( $table !== $this->db->get_var( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
				$missing[] = $table;
			}
		}

		return $this->health_result(
			'zbm_core_tables',
			! $missing,
			$missing ? __( 'حداقل یک جدول هسته موجود نیست.', 'zarbalman-core' ) : __( 'همه جدول‌های هسته موجودند.', 'zarbalman-core' )
		);
	}

	/** @return array<string,mixed> */
	public function test_statuses(): array {
		$invalid = array_filter(
			$this->inspector->inspect(),
			static fn ( array $row ): bool => ! $row['registered'] || ! $row['length_safe']
		);

		return $this->health_result(
			'zbm_core_statuses',
			! $invalid,
			$invalid
				? __( 'بعضی وضعیت‌ها ثبت نشده‌اند یا شناسه آن‌ها از حد پایگاه‌داده طولانی‌تر است.', 'zarbalman-core' )
				: __( 'همه وضعیت‌ها ثبت و از نظر طول شناسه سازگارند.', 'zarbalman-core' )
		);
	}

	/** @return array<string,mixed> */
	public function test_weight_unit(): array {
		return $this->health_result(
			'zbm_core_weight_unit',
			$this->weight_unit->is_satisfied(),
			$this->weight_unit->is_satisfied()
				? __( 'واحد وزن ووکامرس روی کیلوگرم تنظیم شده است.', 'zarbalman-core' )
				: __( 'ثبت وزن متوقف است؛ واحد وزن ووکامرس را روی کیلوگرم تنظیم کنید.', 'zarbalman-core' )
		);
	}

	/** @return array<string,mixed> */
	public function test_evidence_security(): array {
		$passed = $this->evidence_crypto->is_available()
			&& $this->evidence_crypto->has_key()
			&& ! $this->evidence_migrator->has_legacy_evidence();
		return $this->health_result(
			'zbm_core_evidence_security',
			$passed,
			$passed
				? __( 'رمزنگاری مدارک فعال است و مدرک قدیمی ناامن وجود ندارد.', 'zarbalman-core' )
				: __( 'رمزنگاری یا مهاجرت مدارک کامل نیست؛ تا رفع هشدار از مدرک واقعی استفاده نکنید.', 'zarbalman-core' )
		);
	}

	/** @return array<string,mixed> */
	public function test_spec3_dependencies(): array {
		$passed = $this->dependency_health->canCreateDepositOrder();
		return $this->health_result(
			'zbm_core_spec3_dependencies',
			$passed,
			$passed
				? __( 'نسخه‌ها و تنظیمات لازم برای درخواست، پیامک و پیش‌پرداخت سازگارند.', 'zarbalman-core' )
				: __( 'حداقل یک وابستگی درخواست یا پرداخت ناسازگار است؛ عملیات وابسته به‌صورت امن متوقف می‌شود.', 'zarbalman-core' )
		);
	}

	/** @return array<string,mixed> */
	public function test_delivery_crypto(): array {
		$passed = $this->delivery_crypto->ensureKey();
		return $this->health_result(
			'zbm_core_delivery_crypto',
			$passed,
			$passed
				? __( 'کلید پایدار و کنترل یکپارچگی رمزگذاری نشانی در دسترس است.', 'zarbalman-core' )
				: __( 'کلید رمزگذاری نشانی مفقود یا ناسازگار است؛ ثبت درخواست جدید متوقف می‌شود.', 'zarbalman-core' )
		);
	}

	/** @return array<string,mixed> */
	public function test_sms_outbox(): array {
		$failed = $this->notification_outbox->failedCount();
		return $this->health_result(
			'zbm_core_sms_outbox',
			0 === $failed,
			0 === $failed
				? __( 'پیامک شکست‌خوردهٔ نهایی در صف وجود ندارد.', 'zarbalman-core' )
				: sprintf( __( '%d پیامک پس از سقف تلاش خودکار ناموفق مانده و باید توسط مدیریت بررسی شود.', 'zarbalman-core' ), $failed )
		);
	}

	/** @return array<string,mixed> */
	private function health_result( string $test, bool $passed, string $description ): array {
		return array(
			'label'       => $passed ? __( 'سازگاری هسته تأیید شد', 'zarbalman-core' ) : __( 'هسته به بررسی نیاز دارد', 'zarbalman-core' ),
			'status'      => $passed ? 'good' : 'recommended',
			'badge'       => array( 'label' => 'Zarbalman Core', 'color' => 'blue' ),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=zbm-core' ) ),
				esc_html__( 'مشاهده گزارش هسته', 'zarbalman-core' )
			),
			'test'        => $test,
		);
	}
}

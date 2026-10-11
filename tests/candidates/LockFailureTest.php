<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/CandidateFixture.php';
require_once __DIR__ . '/../support/FaultDb.php';
use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Infrastructure\{PurchaseRequestOperationLock, WorkflowOperationLock, VendorShippingRateLock, LedgerRepository, Schema, PurchaseRequestOperationLockPort};
use Zarbalman\Core\Application\{ManualPaymentService, DepositPaymentService};

final class LockFailureTest extends TestCase {
    public function test_failed_acquisition_never_executes_operation_or_changes_financial_data(): void {
        foreach ([PurchaseRequestOperationLock::class, WorkflowOperationLock::class, VendorShippingRateLock::class] as $class) {
            $db = CandidateFaultDb::connect(); $db->failAcquire = true;
            $before = CandidateFixture::financialSnapshot(); $called = false;
            try { (new $class($db))->synchronized(900001, function () use (&$called) { $called = true; throw new LogicException('Forbidden callback reached.'); }); self::fail('Failed acquisition must throw.'); }
            catch (RuntimeException $error) { self::assertNotSame('', $error->getMessage()); }
            self::assertFalse($called, $class);
            self::assertSame($before, CandidateFixture::financialSnapshot(), $class);
            $db->close();
        }
    }
    public function test_release_failure_preserves_original_operation_failure_and_connection_close_frees_lock(): void {
        foreach ([PurchaseRequestOperationLock::class, WorkflowOperationLock::class, VendorShippingRateLock::class] as $class) {
            $db = CandidateFaultDb::connect(); $db->failRelease = true;
            $before = CandidateFixture::financialSnapshot(); $original = new RuntimeException('Injected operation failure.');
            try { (new $class($db))->synchronized(900002, static function () use ($original) { throw $original; }); self::fail('Operation failure must propagate.'); }
            catch (RuntimeException $error) { self::assertSame($original, $error); }
            self::assertSame($before, CandidateFixture::financialSnapshot());
            $db->close();
            $other = CandidateFaultDb::connect();
            self::assertSame('reacquired', (new $class($other))->synchronized(900002, static fn() => 'reacquired'));
            $other->close();
        }
    }
    public function test_managed_transaction_rolls_back_real_cash_write_when_callback_fails(): void {
        global $wpdb;
        [$request, $r] = CandidateFixture::request();
        CandidateFixture::saveCase(CandidateFixture::caseFor($request));
        $admin = (int) get_user_by('login', 'zbm_test_admin')->ID;
        $before = CandidateFixture::financialSnapshot();
        $result = $r['cases']->mutate($request->id(), 0, 'admin', $admin, wp_generate_uuid4(), 'manual_receipt_submitted', [], function () use ($wpdb, $request) {
            (new LedgerRepository($wpdb))->append($request->id(), 123456, null, 'employer_cash_received', 'employer', 0, 100, 'recorded', 'MOCK-injected-failure-' . wp_generate_uuid4());
            throw new RuntimeException('Injected failure after actual ledger INSERT before commit.');
        });
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('zbm_managed_storage_failed', $result->get_error_code());
        self::assertSame($before, CandidateFixture::financialSnapshot());
    }
    public function test_manual_locked_wrapper_fails_closed_and_preserves_committed_result_on_release_failure(): void {
        $reflection = new ReflectionClass(ManualPaymentService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $lock = new class implements PurchaseRequestOperationLockPort {
            public bool $failAcquire = true;
            public function acquire(int $id, int $timeout_seconds = 10): void { if ($this->failAcquire) { throw new RuntimeException('Injected acquisition error.'); } }
            public function release(int $id): void { throw new RuntimeException('Injected release error.'); }
        };
        $reflection->getProperty('lock')->setValue($service, $lock);
        $method = $reflection->getMethod('locked');
        $before = CandidateFixture::financialSnapshot(); $called = false;
        $result = $method->invoke($service, 900003, function () use (&$called): array { $called = true; return []; });
        self::assertInstanceOf(WP_Error::class, $result); self::assertFalse($called);
        self::assertSame($before, CandidateFixture::financialSnapshot());
        $lock->failAcquire = false;
        $result = $method->invoke($service, 900003, static function (): array { throw new RuntimeException('Injected operation error.'); });
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame($before, CandidateFixture::financialSnapshot());
        self::assertSame(['committed' => true], $method->invoke($service, 900003, static fn(): array => ['committed' => true]));
    }
    public function test_post_commit_announcements_do_not_replace_financial_facts_on_hook_failure(): void {
        [$request, $r] = CandidateFixture::request(['current_state' => 'deposit_pending', 'payment_ready' => 1, 'deposit_deadline_gmt' => gmdate('Y-m-d H:i:s', time() + 3600)]);
        $before = CandidateFixture::financialSnapshot();
        foreach ([[DepositPaymentService::class, 'announceDepositPaid', 'zbm_purchase_request_deposit_paid', [$request->id(), 0]], [ManualPaymentService::class, 'announceDepositPaid', 'zbm_purchase_request_deposit_paid', [$request->id()]], [ManualPaymentService::class, 'announceDepositPending', 'zbm_purchase_request_deposit_pending', [$request->id()]], [ManualPaymentService::class, 'announceSyncRequested', 'zbm_managed_sync_requested', [$request->id()]]] as [$class, $method, $hook, $args]) {
            $count = 0;
            $failure = static function () use (&$count): void { ++$count; throw new RuntimeException('Injected post-commit subscriber failure.'); };
            add_action($hook, $failure, -1000, 2);
            try {
                $reflection = new ReflectionClass($class); $service = $reflection->newInstanceWithoutConstructor();
                if ($class === ManualPaymentService::class) { $reflection->getProperty('requests')->setValue($service, $r['requests']); }
                $reflection->getMethod($method)->invokeArgs($service, $args);
                self::assertSame(1, $count, $method);
                self::assertSame($before, CandidateFixture::financialSnapshot(), $method);
            } finally { remove_action($hook, $failure, -1000); }
        }
    }
}

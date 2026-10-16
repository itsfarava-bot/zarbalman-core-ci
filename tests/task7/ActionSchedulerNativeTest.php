<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/CandidateFixture.php';

use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Domain\PurchaseRequest\RequestNumber;
use Zarbalman\Core\Infrastructure\{NotificationWorker, PurchaseRequestScheduler, Scheduler, Schema};

/**
 * Native Woo-bundled AS store, runner, schedules and logger. Only the external
 * SMS transport is doubled. DISABLE_WP_CRON prevents a second queue consumer.
 */
final class ActionSchedulerNativeTest extends TestCase {
    private array $requests = [];
    private array $actions = [];
    private array $hooks = [];
    private array $savedSms = [];
    private bool $savedSmsFailure = false;
    private bool $transportReady = false;

    protected function setUp(): void {
        parent::setUp();
        self::assertTrue(defined('ZBM_TEST_SANDBOX') && ZBM_TEST_SANDBOX === true);
        self::assertTrue(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON === true);
        self::assertTrue(class_exists('ActionScheduler'));
        self::assertSame('3.9.3', ActionScheduler_Versions::instance()->latest_version());
        $runner = ActionScheduler::runner();
        self::assertInstanceOf(ActionScheduler_QueueRunner::class, $runner);
        $source = str_replace('\\', '/', (new ReflectionMethod($runner, 'process_action'))->getFileName());
        self::assertStringContainsString('/woocommerce/packages/action-scheduler/', $source, 'The runner must come from the installed real WooCommerce bundle.');
        if (!function_exists('PWSMS')) {
            require_once __DIR__ . '/../support/RecordingSms.php';
        }
        self::assertInstanceOf(ZbmRecordingSmsTransport::class, PWSMS(), 'These tests may only use the owned no-network SMS double.');
        $this->savedSms = PWSMS()->sent;
        $this->savedSmsFailure = PWSMS()->fail;
        $this->transportReady = true;
        PWSMS()->fail = false;
        // Scoped mobile resolution avoids changing the fixture users' metadata.
        $mobile = function ($value, $userId, $role) {
            foreach ($this->requests as $request) {
                if ((int) $userId === $request->buyerId() || (int) $userId === $request->vendorId()) { return '09120000000'; }
            }
            return $value;
        };
        add_filter('zbm_core_user_mobile', $mobile, PHP_INT_MAX, 3);
        $this->hooks[] = ['zbm_core_user_mobile', $mobile, PHP_INT_MAX];
    }

    protected function tearDown(): void {
        global $wpdb;
        foreach ($this->hooks as [$hook, $callback, $priority]) { remove_filter($hook, $callback, $priority); }
        foreach (array_unique($this->actions) as $id) {
            // Delete only IDs created/observed for this test's owned arguments.
            ActionScheduler::store()->delete_action($id);
        }
        $schema = new Schema($wpdb);
        foreach ($this->requests as $request) {
            $id = $request->id();
            $wpdb->delete($schema->notification_outbox_table(), ['request_id' => $id]);
            $wpdb->delete($schema->audit_table(), ['resource_type' => 'request', 'resource_id' => $id]);
            $wpdb->delete($schema->idempotency_table(), ['namespace' => 'purchase_request_transition', 'result_resource_id' => $id]);
            $wpdb->delete($schema->purchase_requests_table(), ['id' => $id]);
        }
        if ($this->transportReady) {
            PWSMS()->sent = $this->savedSms;
            PWSMS()->fail = $this->savedSmsFailure;
        }
        parent::tearDown();
    }

    public function test_real_recurring_recovery_and_notification_schedules_are_unique(): void {
        $scheduler = $this->registered(PurchaseRequestScheduler::RECOVERY_HOOK, PurchaseRequestScheduler::class, 'recover');
        $worker = $this->registered(NotificationWorker::HOOK, NotificationWorker::class, 'dispatch');
        foreach ([[$scheduler, 'ensureRecovery', PurchaseRequestScheduler::RECOVERY_HOOK], [$worker, 'ensureSchedule', NotificationWorker::HOOK]] as [$object, $method, $hook]) {
            $before = $this->pending($hook, []);
            $object->$method();
            $first = $this->pending($hook, []);
            foreach (array_diff($first, $before) as $id) { $this->actions[] = $id; }
            self::assertCount(1, $first, $hook);
            $object->$method();
            self::assertSame($first, $this->pending($hook, []), 'Repeated initialization must retain the same pending native action.');
            $action = ActionScheduler::store()->fetch_action($first[0]);
            self::assertSame(Scheduler::GROUP, $action->get_group());
            self::assertTrue($action->get_schedule()->is_recurring());
            self::assertSame(60, $action->get_schedule()->get_recurrence());
        }
    }

    public function test_supplier_and_deposit_expiry_execute_through_native_runner_and_replay_once(): void {
        $scheduler = $this->registered(PurchaseRequestScheduler::RECOVERY_HOOK, PurchaseRequestScheduler::class, 'recover');
        foreach ([['supplier_review', 'supplier_deadline_gmt', 'scheduleSupplier', PurchaseRequestScheduler::SUPPLIER_HOOK, 'supplier_response_expired'], ['deposit_pending', 'deposit_deadline_gmt', 'scheduleDeposit', PurchaseRequestScheduler::DEPOSIT_HOOK, 'deposit_expired']] as [$state, $deadline, $method, $hook, $expired]) {
            $request = $this->request(['current_state' => $state, $deadline => gmdate('Y-m-d H:i:s', time() - 120), 'payment_ready' => 1]);
            $args = [$request->id(), $request->version()];
            $scheduler->$method(...$args);
            $ids = $this->pending($hook, $args);
            $this->actions = array_merge($this->actions, $ids);
            self::assertCount(1, $ids);
            $scheduler->$method(...$args);
            self::assertSame($ids, $this->pending($hook, $args));
            $action = ActionScheduler::store()->fetch_action($ids[0]);
            self::assertSame($args, $action->get_args());
            $this->runOwnedAction($ids[0]);
            self::assertSame($expired, CandidateFixture::repositories()['requests']->find($request->id())->state());
            self::assertSame($request->version() + 1, CandidateFixture::repositories()['requests']->find($request->id())->version());
            $beforeReplay = $this->businessRows($request->id());
            // Same completed AS ID is ignored; a separately persisted replay of
            // the business command must also leave all request rows unchanged.
            $this->runOwnedAction($ids[0]);
            $this->runOwnedAction($this->single($hook, $args));
            self::assertSame($beforeReplay, $this->businessRows($request->id()));
            self::assertCount(1, $this->audit($request->id(), $expired));
            self::assertCount(2, $beforeReplay['outbox']);
        }
    }

    public function test_native_recovery_action_repairs_a_missed_expiry_without_a_per_request_job(): void {
        // Recovery deliberately scans all requests. Require a clean eligible
        // population rather than hiding or deleting unrelated business rows.
        $repos = CandidateFixture::repositories();
        self::assertSame([], $repos['requests']->recoverable(), 'Run native tests in the fresh disposable smoke database.');
        self::assertSame([], $repos['outbox']->missingSupplierReminderIds(), 'Recovery must not schedule unrelated reminders.');
        foreach ([['supplier_deadline_gmt', 'supplier_review'], ['deposit_deadline_gmt', 'deposit_pending']] as [$column, $state]) {
            self::assertSame([], $repos['requests']->due($column, $state, current_time('mysql', true)));
        }
        $request = $this->request(['current_state' => 'supplier_review', 'supplier_deadline_gmt' => gmdate('Y-m-d H:i:s', time() - 120)]);
        self::assertSame([], $this->pending(PurchaseRequestScheduler::SUPPLIER_HOOK, [$request->id(), 0]));
        $this->runOwnedAction($this->single(PurchaseRequestScheduler::RECOVERY_HOOK));
        self::assertSame('supplier_response_expired', $repos['requests']->find($request->id())->state());
        self::assertCount(1, $this->audit($request->id(), 'supplier_response_expired'));
        $beforeReplay = $this->businessRows($request->id());
        $this->runOwnedAction($this->single(PurchaseRequestScheduler::RECOVERY_HOOK));
        self::assertSame($beforeReplay, $this->businessRows($request->id()));
    }

    public function test_notification_action_sends_once_and_successful_replay_is_inert(): void {
        $request = $this->request(['current_state' => 'supplier_rejected']);
        $id = $this->enqueue($request, 'request_rejected');
        $before = count(PWSMS()->sent);
        $this->dispatch();
        self::assertCount($before + 1, PWSMS()->sent);
        self::assertSame('sent', $this->outbox($id)['status']);
        self::assertSame('0', $this->outbox($id)['attempt_count']);
        self::assertNotEmpty($this->outbox($id)['sent_at_gmt']);
        $rows = $this->businessRows($request->id());
        $this->dispatch();
        self::assertCount($before + 1, PWSMS()->sent);
        self::assertSame($rows, $this->businessRows($request->id()));
    }

    public function test_notification_retry_backoff_is_owned_by_outbox_and_terminal_failure_is_audited(): void {
        global $wpdb;
        $request = $this->request(['current_state' => 'supplier_rejected']);
        $id = $this->enqueue($request, 'request_rejected');
        $before = count(PWSMS()->sent);
        PWSMS()->fail = true;
        foreach ([60, 300, 1800, null] as $attempt => $delay) {
            $started = time();
            $this->dispatch(); // AS completes: the worker catches transport errors.
            $row = $this->outbox($id);
            self::assertSame((string) ($attempt + 1), $row['attempt_count']);
            self::assertSame('Automatic SMS delivery failed.', $row['last_error']);
            self::assertNull($row['locked_at_gmt']);
            if ($delay !== null) {
                self::assertSame('retry', $row['status']);
                $next = strtotime($row['next_attempt_gmt'] . ' UTC');
                self::assertGreaterThanOrEqual($started + $delay, $next);
                self::assertLessThanOrEqual(time() + $delay, $next);
                // A normal queue tick before the retry boundary must not send.
                $this->dispatch();
                self::assertSame($row, $this->outbox($id));
                // Advance only this fixture's persisted retry boundary; no
                // fake clock, sleep for 30 minutes, or direct worker calls.
                self::assertSame(1, $wpdb->update((new Schema($wpdb))->notification_outbox_table(), ['next_attempt_gmt' => gmdate('Y-m-d H:i:s', time() - 1)], ['id' => $id]));
            } else {
                self::assertSame('failed', $row['status']);
                self::assertNull($row['next_attempt_gmt']);
            }
        }
        self::assertCount($before, PWSMS()->sent);
        $audit = $this->audit($request->id(), 'notification_failed');
        self::assertCount(1, $audit);
        self::assertSame('system', $audit[0]['actor_type']);
        self::assertSame('0', $audit[0]['actor_user_id']);
        self::assertSame(['template' => 'request_rejected', 'recipient_role' => 'buyer'], json_decode($audit[0]['metadata_json'], true, 16, JSON_THROW_ON_ERROR));
        $rows = $this->businessRows($request->id());
        PWSMS()->fail = false;
        $this->dispatch();
        self::assertSame($rows, $this->businessRows($request->id()));
        self::assertCount($before, PWSMS()->sent, 'A terminal failure requires an explicit operational decision.');
    }

    public function test_retry_eligible_notification_succeeds_once_after_transport_recovers(): void {
        global $wpdb;
        $request = $this->request(['current_state' => 'supplier_rejected']);
        $id = $this->enqueue($request, 'request_rejected');
        $before = count(PWSMS()->sent);
        PWSMS()->fail = true;
        $this->dispatch();
        self::assertSame('retry', $this->outbox($id)['status']);
        PWSMS()->fail = false;
        self::assertSame(1, $wpdb->update((new Schema($wpdb))->notification_outbox_table(), ['next_attempt_gmt' => gmdate('Y-m-d H:i:s', time() - 1)], ['id' => $id]));
        $this->dispatch();
        self::assertSame('sent', $this->outbox($id)['status']);
        self::assertSame('1', $this->outbox($id)['attempt_count']);
        self::assertNull($this->outbox($id)['last_error']);
        self::assertSame([], $this->audit($request->id(), 'notification_failed'));
        self::assertCount($before + 1, PWSMS()->sent);
        $this->dispatch();
        self::assertCount($before + 1, PWSMS()->sent);
    }

    public function test_native_runner_logs_an_uncaught_hook_failure_and_requires_explicit_replay(): void {
        $request = $this->request(['current_state' => 'supplier_review', 'supplier_deadline_gmt' => gmdate('Y-m-d H:i:s', time() - 120)]);
        $failure = static function (int $id) use ($request): void {
            if ($id === $request->id()) { throw new RuntimeException('Task 7 controlled native callback failure'); }
        };
        add_action(PurchaseRequestScheduler::SUPPLIER_HOOK, $failure, 1);
        $this->hooks[] = [PurchaseRequestScheduler::SUPPLIER_HOOK, $failure, 1];
        $id = $this->single(PurchaseRequestScheduler::SUPPLIER_HOOK, [$request->id(), 0]);
        $this->runOwnedAction($id, ActionScheduler_Store::STATUS_FAILED);
        self::assertStringContainsString('Task 7 controlled native callback failure', $this->logs($id));
        self::assertSame('supplier_review', CandidateFixture::repositories()['requests']->find($request->id())->state());
        self::assertSame([], $this->pending(PurchaseRequestScheduler::SUPPLIER_HOOK, [$request->id(), 0]), 'A failed native one-time action is not automatically rescheduled.');
        remove_action(PurchaseRequestScheduler::SUPPLIER_HOOK, $failure, 1);
        $this->runOwnedAction($this->single(PurchaseRequestScheduler::SUPPLIER_HOOK, [$request->id(), 0]));
        self::assertCount(1, $this->audit($request->id(), 'supplier_response_expired'));
    }

    public function test_supplier_reminder_transport_failure_is_terminal_and_not_reenqueued(): void {
        global $wpdb;
        $deadline = gmdate('Y-m-d H:i:s', time() + 300);
        $request = $this->request(['current_state' => 'supplier_review', 'supplier_deadline_gmt' => $deadline]);
        $outbox = CandidateFixture::repositories()['outbox'];
        $event = wp_generate_uuid4();
        $template = \Zarbalman\Core\Infrastructure\NotificationOutboxRepository::SUPPLIER_REMINDER;
        self::assertTrue($outbox->enqueue($event, $request->id(), 'vendor', $request->vendorId(), $template, ['deadline' => $deadline]));
        $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE event_uuid = %s', (new Schema($wpdb))->notification_outbox_table(), $event));
        $before = count(PWSMS()->sent);
        PWSMS()->fail = true;
        $this->dispatch();
        $row = $this->outbox($id);
        self::assertSame('failed', $row['status']);
        self::assertSame('4', $row['attempt_count'], 'Reminder policy consumes the retry budget on its first transport failure.');
        self::assertNull($row['next_attempt_gmt']);
        self::assertSame('Automatic SMS delivery failed.', $row['last_error']);
        self::assertCount(1, $this->audit($request->id(), 'notification_failed'));
        // The deadline identity prevents recovery from creating another notice.
        self::assertTrue($outbox->enqueue(wp_generate_uuid4(), $request->id(), 'vendor', $request->vendorId(), $template, ['deadline' => $deadline]));
        self::assertCount(1, $this->businessRows($request->id())['outbox']);
        PWSMS()->fail = false;
        $this->dispatch();
        self::assertSame($row, $this->outbox($id));
        self::assertCount($before, PWSMS()->sent);
    }

    private function request(array $fields): object {
        [$request] = CandidateFixture::request(array_replace(['request_number' => RequestNumber::generate()], $fields));
        $this->requests[] = $request;
        return $request;
    }

    private function registered(string $hook, string $class, string $method): object {
        global $wp_filter;
        foreach (($wp_filter[$hook]->callbacks ?? []) as $callbacks) {
            foreach ($callbacks as $entry) {
                $callback = $entry['function'];
                if (is_array($callback) && $callback[0] instanceof $class && $callback[1] === $method) { return $callback[0]; }
            }
        }
        self::fail('Real plugin callback is not registered: ' . $hook);
    }

    private function pending(string $hook, array $args): array {
        return array_map('intval', as_get_scheduled_actions(['hook' => $hook, 'args' => $args, 'group' => Scheduler::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1, 'orderby' => 'action_id', 'order' => 'ASC'], 'ids'));
    }

    private function single(string $hook, array $args = []): int {
        $id = as_schedule_single_action(time() - 1, $hook, $args, Scheduler::GROUP);
        self::assertGreaterThan(0, $id);
        $this->actions[] = $id;
        return $id;
    }

    private function runOwnedAction(int $id, string $expected = ActionScheduler_Store::STATUS_COMPLETE): void {
        $action = ActionScheduler::store()->fetch_action($id);
        // scheduleOnce clamps elapsed deadlines to the next second. Wait only
        // for that actual native boundary and never execute a future action.
        $due = $action->get_schedule()->get_date()->getTimestamp();
        self::assertLessThanOrEqual(time() + 2, $due);
        while (time() < $due) { usleep(10000); }
        ActionScheduler::runner()->process_action($id, 'Task 7 Native');
        self::assertSame($expected, ActionScheduler::store()->get_status($id));
        self::assertNotSame('', $this->logs($id), 'Native AS logger must persist execution evidence.');
    }

    private function logs(int $id): string {
        return implode("\n", array_map(static fn($log): string => $log->get_message(), ActionScheduler::logger()->get_logs($id)));
    }

    private function enqueue(object $request, string $template): int {
        global $wpdb;
        $event = wp_generate_uuid4();
        self::assertTrue(CandidateFixture::repositories()['outbox']->enqueue($event, $request->id(), 'buyer', $request->buyerId(), $template, []));
        return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE event_uuid = %s', (new Schema($wpdb))->notification_outbox_table(), $event));
    }

    private function dispatch(): void {
        global $wpdb;
        $schema = new Schema($wpdb);
        $ids = array_map(static fn($request): int => $request->id(), $this->requests);
        $eligible = $wpdb->get_col($wpdb->prepare("SELECT request_id FROM %i WHERE (status IN ('pending','retry') AND next_attempt_gmt <= %s) OR (status = 'sending' AND locked_at_gmt < %s)", $schema->notification_outbox_table(), current_time('mysql', true), gmdate('Y-m-d H:i:s', time() - 600)));
        self::assertSame('', $wpdb->last_error);
        self::assertSame([], array_values(array_diff(array_map('intval', $eligible), $ids)), 'Refusing to process unrelated notification work.');
        $this->registered(NotificationWorker::HOOK, NotificationWorker::class, 'dispatch');
        $this->runOwnedAction($this->single(NotificationWorker::HOOK));
    }

    private function outbox(int $id): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', (new Schema($wpdb))->notification_outbox_table(), $id), ARRAY_A);
        self::assertSame('', $wpdb->last_error);
        self::assertIsArray($row);
        return $row;
    }

    private function audit(int $requestId, string $event): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE resource_type = %s AND resource_id = %d AND event_key = %s ORDER BY id', (new Schema($wpdb))->audit_table(), 'request', $requestId, $event), ARRAY_A);
        self::assertSame('', $wpdb->last_error);
        self::assertIsArray($rows);
        return $rows;
    }

    private function businessRows(int $requestId): array {
        global $wpdb;
        $schema = new Schema($wpdb);
        $rows = [
            'request' => $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $schema->purchase_requests_table(), $requestId), ARRAY_A),
            'audit' => $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE resource_type = %s AND resource_id = %d ORDER BY id', $schema->audit_table(), 'request', $requestId), ARRAY_A),
            'outbox' => $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE request_id = %d ORDER BY id', $schema->notification_outbox_table(), $requestId), ARRAY_A),
            'idempotency' => $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE namespace = %s AND result_resource_id = %d ORDER BY key_hash', $schema->idempotency_table(), 'purchase_request_transition', $requestId), ARRAY_A),
        ];
        self::assertSame('', $wpdb->last_error);
        foreach ($rows as $row) { self::assertIsArray($row); }
        return $rows;
    }
}

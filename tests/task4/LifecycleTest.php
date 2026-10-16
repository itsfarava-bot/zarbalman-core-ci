<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Infrastructure\{Installer,Schema,Scheduler};
final class LifecycleTest extends TestCase {
    public static function schedulerHooks(): array {
        $hooks=[];
        foreach (glob(__DIR__.'/../../plugin/src/Infrastructure/*.php') as $file) {
            if (!preg_match('/class (\w+)/',file_get_contents($file),$m)) { continue; }
            $class='Zarbalman\\Core\\Infrastructure\\'.$m[1];
            if (!class_exists($class)) { continue; }
            foreach ((new ReflectionClass($class))->getConstants() as $name=>$value) {
                if (is_string($value) && str_starts_with($value,'zbm_') && (str_ends_with($name,'HOOK') || in_array($name,['TICK','SWEEP'],true))) { $hooks[]=$value; }
            }
        }
        return array_values(array_unique($hooks));
    }
    public function test_deactivation_cancels_real_scheduler_jobs_with_arguments_and_preserves_foreign_jobs(): void {
        $ours=[]; $foreign=[];
        foreach (self::schedulerHooks() as $hook) {
            $ours[$hook]=as_schedule_single_action(time()+3600,$hook,[91234],Scheduler::GROUP);
            $foreign[$hook]=as_schedule_single_action(time()+3600,$hook,[91234],'foreign-test-group');
            self::assertGreaterThan(0,$ours[$hook]); self::assertGreaterThan(0,$foreign[$hook]);
            self::assertTrue(wp_schedule_single_event(time()+3600,$hook,[91234]));
        }
        $fn=new ReflectionFunction("as_unschedule_all_actions");
        file_put_contents(__DIR__."/../../evidence/task4-scheduler-api.txt",implode("",array_slice(file($fn->getFileName()),$fn->getStartLine()-1,$fn->getEndLine()-$fn->getStartLine()+1)));
        Installer::deactivate();
        foreach ($ours as $hook=>$id) {
            self::assertFalse(as_has_scheduled_action($hook,[91234],Scheduler::GROUP),'Core action remains: '.$hook);
            self::assertTrue(as_has_scheduled_action($hook,[91234],'foreign-test-group'),'Foreign action lost: '.$hook);
            self::assertFalse(wp_next_scheduled($hook,[91234]),'Cron remains: '.$hook);
        }
    }
    public function test_reactivation_processes_an_overdue_one_time_deadline_in_one_sweep(): void {
        require_once __DIR__ . '/../support/CandidateFixture.php';
        require_once __DIR__ . '/../support/HttpClient.php';
        require_once __DIR__ . '/../support/Task4EndpointHttp.php';
        require_once __DIR__ . '/../support/Task5LifecycleSnapshot.php';
        global $wpdb;
        $schema = new Schema($wpdb); $repos = CandidateFixture::repositories();
        $requestsTable = $schema->purchase_requests_table();
        // Exercise both legacy NULL backfill and preservation of an assigned reference,
        // including when this lifecycle test runs independently of earlier fixture tests.
        do {
            $assignedNumber = \Zarbalman\Core\Domain\PurchaseRequest\RequestNumber::generate();
            $occupied = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE request_number = %s LIMIT 1', $requestsTable, $assignedNumber));
            self::assertSame('', $wpdb->last_error);
        } while ($occupied !== null);
        [$assignedRequest] = CandidateFixture::request(['request_number' => $assignedNumber]);
        [$legacyRequest] = CandidateFixture::request(['request_number' => null]);
        $order = wc_create_order(); self::assertInstanceOf(WC_Order::class, $order); $id = $order->get_id();
        $now = current_time('mysql', true); $future = gmdate('Y-m-d H:i:s', time() + 3600);
        self::assertSame(1, $wpdb->insert($schema->workflows_table(), ['order_id' => $id, 'current_state' => 'delivered', 'version' => 0, 'is_paused' => 0, 'delivered_at_gmt' => $now, 'buyer_deadline_gmt' => $future, 'created_at_gmt' => $now, 'updated_at_gmt' => $now]));
        $hook = \Zarbalman\Core\Infrastructure\DeadlineScheduler::BUYER_HOOK;
        self::assertGreaterThan(0, as_schedule_single_action(time() + 3600, $hook, [$id], Scheduler::GROUP));
        $run = static function (string $operation): void {
            $process = proc_open(['wp', 'plugin', $operation, 'zarbalman-core', '--path=' . getenv('ZBM_TEST_WP_ROOT'), '--no-color'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process); $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $output . $errors);
        };
        $rows = static fn(): array => Task5LifecycleSnapshot::rows($wpdb, $schema);
        $beforeDeactivate = $rows(); $finance = CandidateFixture::financialSnapshot();
        $evidence = ['overdue_order_id' => $id, 'request_ids_before' => array_map('intval', array_keys($beforeDeactivate[$requestsTable])),
            'assigned_request_id' => $assignedRequest->id(), 'legacy_request_id' => $legacyRequest->id(), 'cycles' => []];
        $writeEvidence = static function () use (&$evidence): void {
            self::assertNotFalse(file_put_contents(__DIR__ . '/../../evidence/task4-lifecycle-columns.json', wp_json_encode($evidence, JSON_PRETTY_PRINT)));
        };
        $run('deactivate'); $afterDeactivate = $rows(); $financeAfterDeactivate = CandidateFixture::financialSnapshot();
        $evidence['deactivate'] = Task5LifecycleSnapshot::columns($beforeDeactivate[$requestsTable], $afterDeactivate[$requestsTable]);
        $evidence['deactivate_all_tables'] = Task5LifecycleSnapshot::tables($beforeDeactivate, $afterDeactivate); $writeEvidence();
        self::assertSame($beforeDeactivate, $afterDeactivate, 'Deactivation changed business rows.');
        self::assertSame($finance, $financeAfterDeactivate, 'Deactivation changed finance/WooCommerce.');
        self::assertFalse(as_has_scheduled_action($hook, [$id], Scheduler::GROUP));
        foreach ([\Zarbalman\Core\Infrastructure\PurchaseRequestScheduler::RECOVERY_HOOK, \Zarbalman\Core\Infrastructure\NotificationWorker::HOOK] as $recurringHook) {
            self::assertFalse(as_has_scheduled_action($recurringHook, [], Scheduler::GROUP), 'Deactivation must cancel native recovery and notification schedules.');
        }
        // Persist an elapsed boundary while inactive, without changing the system clock.
        $elapsed = gmdate('Y-m-d H:i:s', time() - 60);
        self::assertSame(1, $wpdb->update($schema->workflows_table(), ['buyer_deadline_gmt' => $elapsed], ['order_id' => $id]));
        $beforeActivate = $rows();
        $inactiveExpected = $afterDeactivate; $inactiveExpected[$schema->workflows_table()][$id]['buyer_deadline_gmt'] = $elapsed;
        self::assertSame($inactiveExpected, $beforeActivate, 'Only the explicit inactive deadline fixture may change.');
        $store = new \Zarbalman\Core\Infrastructure\WorkflowStore($wpdb, $repos['audit']);
        $before = $store->snapshot($id); self::assertSame('delivered', $before['state']);
        $run('activate'); $afterActivate = $rows(); $financeAfterActivate = CandidateFixture::financialSnapshot();
        $evidence['activate'] = Task5LifecycleSnapshot::columns($beforeActivate[$requestsTable], $afterActivate[$requestsTable]);
        $evidence['activate_all_tables'] = Task5LifecycleSnapshot::tables($beforeActivate, $afterActivate);
        $evidence['financial_snapshot_changed_tables'] = Task5LifecycleSnapshot::hashes($finance, $financeAfterActivate); $writeEvidence();
        self::assertSame(array_keys($beforeActivate[$requestsTable]), array_keys($afterActivate[$requestsTable]), 'Activation inserted/deleted a request.');
        $expectedAfterActivate = $beforeActivate; $backfilled = [];
        foreach ($beforeActivate[$requestsTable] as $requestId => $row) {
            if ($row['request_number'] === null) {
                $number = $afterActivate[$requestsTable][$requestId]['request_number'];
                self::assertTrue(\Zarbalman\Core\Domain\PurchaseRequest\RequestNumber::isValid($number), 'NULL reference was not backfilled: ' . $requestId);
                $expectedAfterActivate[$requestsTable][$requestId]['request_number'] = $number;
                $backfilled[] = (int) $requestId;
            } else {
                self::assertSame($row['request_number'], $afterActivate[$requestsTable][$requestId]['request_number'], 'Assigned request reference changed: ' . $requestId);
            }
        }
        self::assertContains($legacyRequest->id(), $backfilled);
        self::assertSame($assignedNumber, $afterActivate[$requestsTable][$assignedRequest->id()]['request_number']);
        $numbers = array_column($afterActivate[$requestsTable], 'request_number');
        self::assertCount(count($numbers), array_unique($numbers), 'Activation generated duplicate request references.');
        self::assertSame($expectedAfterActivate, $afterActivate, 'First activation may only backfill NULL request_number cells.');
        // Preserve the full financial snapshot assertion using the exact, validated
        // request-table migration as its only permitted first-activation difference.
        $financeExpected = $finance;
        $financeExpected[$requestsTable] = hash('sha256', wp_json_encode(array_values($expectedAfterActivate[$requestsTable])));
        self::assertSame($financeExpected, $financeAfterActivate, 'First activation changed finance/WooCommerce beyond legal NULL references.');
        // Activating an already active plugin skips its hook; perform real cycles.
        for ($cycle = 2; $cycle <= 3; $cycle++) {
            $cycleBefore = $rows(); $cycleFinance = CandidateFixture::financialSnapshot();
            $run('deactivate'); $cycleInactive = $rows();
            $run('activate'); $cycleAfter = $rows(); $cycleFinanceAfter = CandidateFixture::financialSnapshot();
            $evidence['cycles'][] = ['activation' => $cycle,
                'deactivate' => Task5LifecycleSnapshot::tables($cycleBefore, $cycleInactive),
                'activate' => Task5LifecycleSnapshot::tables($cycleInactive, $cycleAfter),
                'financial_snapshot_changed_tables' => Task5LifecycleSnapshot::hashes($cycleFinance, $cycleFinanceAfter)]; $writeEvidence();
            self::assertSame($afterActivate, $cycleBefore, 'Business rows changed between activations.');
            self::assertSame($cycleBefore, $cycleInactive, 'Repeat deactivation changed business rows.');
            self::assertSame($cycleInactive, $cycleAfter, 'Activation ' . $cycle . ' changed a business row.');
            self::assertSame($financeExpected, $cycleFinance); self::assertSame($cycleFinance, $cycleFinanceAfter);
        }
        $beforeSweep = $rows();
        $admin = get_user_by('login', 'zbm_test_admin'); self::assertInstanceOf(WP_User::class, $admin);
        [$cookie] = CandidateHttp::auth((int) $admin->ID, 'unused-sweep-read');
        $http = Task4EndpointHttp::get('task4-endpoint-harness.php', ['view' => 'deadline-sweep'], $cookie);
        self::assertSame(200, $http['status']); $result = json_decode($http['body'], true, 32, JSON_THROW_ON_ERROR);
        $afterSweep = $rows(); $financeAfterSweep = CandidateFixture::financialSnapshot();
        file_put_contents(__DIR__ . '/../../evidence/task4-reactivation.json', wp_json_encode(['before' => $before, 'after' => $store->snapshot($id), 'sweep' => $result]));
        $evidence['sweep'] = Task5LifecycleSnapshot::columns($beforeSweep[$requestsTable], $afterSweep[$requestsTable]);
        $evidence['sweep_all_tables'] = Task5LifecycleSnapshot::tables($beforeSweep, $afterSweep);
        $evidence['sweep_financial_snapshot_changed_tables'] = Task5LifecycleSnapshot::hashes($financeExpected, $financeAfterSweep); $writeEvidence();
        self::assertTrue($result['registered']); self::assertTrue($result['scheduled']);
        foreach ([\Zarbalman\Core\Infrastructure\PurchaseRequestScheduler::RECOVERY_HOOK, \Zarbalman\Core\Infrastructure\NotificationWorker::HOOK] as $recurringHook) {
            $actions = as_get_scheduled_actions(['hook' => $recurringHook, 'args' => [], 'group' => Scheduler::GROUP,
                'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 10], 'ids');
            self::assertCount(1, $actions, 'The actual cold boot after activation must recreate exactly one native schedule.');
            $schedule = ActionScheduler::store()->fetch_action((int) $actions[0])->get_schedule();
            self::assertTrue($schedule->is_recurring()); self::assertSame(60, $schedule->get_recurrence());
        }
        self::assertSame('admin_review', $store->snapshot($id)['state']); self::assertSame($before['version'] + 1, $store->snapshot($id)['version']);
        self::assertSame($financeExpected, $financeAfterSweep);
        $events = array_values(array_filter($repos['audit']->for_order($id, 20), static fn($event) => $event->event_key === 'workflow_transitioned' && $event->to_state === 'admin_review'));
        self::assertCount(1, $events); self::assertSame('system', $events[0]->actor_type); self::assertSame(0, (int) $events[0]->actor_user_id);
        $expectedAfterSweep = $beforeSweep;
        $workflow = $afterSweep[$schema->workflows_table()][$id];
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $workflow['updated_at_gmt']);
        self::assertGreaterThanOrEqual($beforeSweep[$schema->workflows_table()][$id]['updated_at_gmt'], $workflow['updated_at_gmt']);
        self::assertLessThanOrEqual(current_time('mysql', true), $workflow['updated_at_gmt']);
        $expectedAfterSweep[$schema->workflows_table()][$id] = array_replace($beforeSweep[$schema->workflows_table()][$id],
            ['current_state' => 'admin_review', 'version' => (string) ($before['version'] + 1), 'buyer_deadline_gmt' => null, 'vendor_deadline_gmt' => null, 'updated_at_gmt' => $workflow['updated_at_gmt']]);
        // The transition appends one audit row and one completed command identity.
        // Existing rows in both tables remain byte-for-byte identical.
        foreach ([$schema->audit_table(), $schema->idempotency_table()] as $table) {
            $added = array_diff_key($afterSweep[$table], $beforeSweep[$table]); self::assertCount(1, $added, $table);
            foreach ($added as $rowKey => $row) { $expectedAfterSweep[$table][$rowKey] = $row; }
            ksort($expectedAfterSweep[$table]);
        }
        $audit = array_values(array_diff_key($afterSweep[$schema->audit_table()], $beforeSweep[$schema->audit_table()]))[0];
        self::assertSame((string) $id, $audit['order_id']); self::assertSame('order', $audit['resource_type']); self::assertSame((string) $id, $audit['resource_id']);
        self::assertSame('workflow_transitioned', $audit['event_key']); self::assertSame('delivered', $audit['from_state']); self::assertSame('admin_review', $audit['to_state']);
        self::assertSame('system', $audit['actor_type']); self::assertSame('0', $audit['actor_user_id']);
        self::assertTrue(wp_is_uuid($audit['event_uuid'])); self::assertTrue(wp_is_uuid($audit['correlation_id']));
        self::assertGreaterThanOrEqual($workflow['updated_at_gmt'], $audit['created_at_gmt']); self::assertLessThanOrEqual(current_time('mysql', true), $audit['created_at_gmt']);
        self::assertSame(['timeout_type' => 'buyer_review', 'reason' => __('مهلت بررسی خریدار پایان یافته است.', 'zarbalman-core'), 'contract_version' => 2], json_decode($audit['metadata_json'], true, 32, JSON_THROW_ON_ERROR));
        $identity = array_values(array_diff_key($afterSweep[$schema->idempotency_table()], $beforeSweep[$schema->idempotency_table()]))[0];
        self::assertSame(hash('sha256', 'workflow|order|' . $id . '|deadline:buyer_review:' . $id . ':' . $elapsed), $identity['key_hash']);
        self::assertSame('workflow', $identity['namespace']); self::assertSame('completed', $identity['status']); self::assertSame($audit['event_uuid'], $identity['result_event_uuid']);
        self::assertNull($identity['result_resource_id']);
        self::assertSame($workflow['updated_at_gmt'], $identity['created_at_gmt']); self::assertSame($workflow['updated_at_gmt'], $identity['updated_at_gmt']);
        self::assertGreaterThan($identity['updated_at_gmt'], $identity['expires_at_gmt']);
        self::assertSame($expectedAfterSweep, $afterSweep, 'Sweep changed a row outside the single overdue workflow transition.');
    }

    public function test_explicit_uninstall_cannot_drop_partial_schema_or_encryption_keys(): void {
        global $wpdb;
        $file=var_export(__DIR__.'/../../plugin/uninstall.php',true);
        $script='define("WP_UNINSTALL_PLUGIN",true); define("ZBM_CORE_REMOVE_DATA",true); $drops=[];$options=[]; class TestDb {public $prefix="_ci"; function prepare($s,$t){return $t;} function query($s){$GLOBALS["drops"][]=$s;}} $wpdb=new TestDb; function get_posts($a){return [];} function delete_option($n){$GLOBALS["options"][]=$n;} function get_role($n){return null;} function trailingslashit($s){return rtrim($s,"/")."/";} function wp_clear_scheduled_hook($h){} function wp_unschedule_hook($h){} function flush_rewrite_rules($v){} require '.$file.'; echo json_encode([$drops,$options]);';
        $proc=proc_open([PHP_BINARY,'-r',$script],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($proc);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($proc),$err);[$drops,$options]=json_decode($out,true);
        $created=(new Schema($wpdb))->tables();sort($created);sort($drops);
        self::assertTrue([] === $drops || $created === $drops,'Partial uninstall schema: missing '.implode(',',array_diff($created,$drops)));
        // Task 4 retention decision: all business data AND its decryption keys survive.
        self::assertSame([],$drops);self::assertSame([],$options);
    }

    public function test_default_uninstall_cancels_actual_jobs_and_retains_entire_schema_and_keys(): void {
        global $wpdb;
        $schema=new Schema($wpdb); $before=[];
        $created=[];foreach ($schema->creation_sql() as $sql) { self::assertSame(1,preg_match('/CREATE TABLE\s+([a-zA-Z0-9_]+)/',$sql,$m));$created[]=$m[1]; }
        $tables=$schema->tables();sort($created);sort($tables);self::assertSame($tables,$created,'Migrator creation SQL and schema retention inventory must match.');
        $crypto=new \Zarbalman\Core\Infrastructure\DeliveryDataCrypto();self::assertTrue($crypto->ensureKey());
        $plaintext=['recipient_name'=>'Synthetic uninstall decrypt proof','postal_code'=>null,'address'=>null];$ciphertext=$crypto->encrypt($plaintext);
        foreach ($schema->tables() as $table) { $before[$table]=$wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY 1',$table),ARRAY_A); self::assertIsArray($before[$table]); self::assertSame('',$wpdb->last_error,$table); }
        $keys=[]; foreach (['zbm_core_evidence_key','zbm_core_delivery_key','zbm_core_delivery_code_secret'] as $key) { $keys[$key]=get_option($key); }
        $hook='zbm_spec4_tick'; $args=[765432];
        self::assertGreaterThan(0,as_schedule_single_action(time()+3600,$hook,$args,Scheduler::GROUP));
        self::assertGreaterThan(0,as_schedule_single_action(time()+3600,$hook,$args,'foreign-test-group'));
        self::assertTrue(wp_schedule_single_event(time()+3600,$hook,$args));
        if (!defined('WP_UNINSTALL_PLUGIN')) { define('WP_UNINSTALL_PLUGIN',true); }
        require __DIR__.'/../../plugin/uninstall.php';
        self::assertFalse(as_has_scheduled_action($hook,$args,Scheduler::GROUP),'Default uninstall left a core job pending.');
        self::assertTrue(as_has_scheduled_action($hook,$args,'foreign-test-group'));
        self::assertFalse(wp_next_scheduled($hook,$args));
        foreach ($before as $table=>$rows) { $after=$wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY 1',$table),ARRAY_A); self::assertIsArray($after); self::assertSame('',$wpdb->last_error,$table); self::assertSame($rows,$after,$table); }
        foreach ($keys as $key=>$value) { self::assertSame($value,get_option($key),$key); }
        self::assertSame($plaintext,$crypto->decrypt($ciphertext),'Retained ciphertext must remain decryptable after uninstall.');
    }
}

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
        global $wpdb;
        $schema = new Schema($wpdb); $repos = CandidateFixture::repositories();
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
        $requestRows = static function () use ($wpdb, $schema): array {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id', $schema->purchase_requests_table()), ARRAY_A);
            self::assertIsArray($rows); self::assertSame('', $wpdb->last_error);
            return array_column($rows, null, 'id');
        };
        $columns = static function (array $before, array $after): array {
            $changes = [];
            foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $rowId) {
                $old = $before[$rowId] ?? []; $new = $after[$rowId] ?? [];
                foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $column) {
                    $a = $old[$column] ?? null; $b = $new[$column] ?? null;
                    if ($a === $b) { continue; }
                    $private = preg_match('/email|phone|secret|key|cipher|encrypt|iban|card|payload/i', $column) === 1;
                    $changes[] = ['id' => (int) $rowId, 'column' => $column, 'before' => $private && $a !== null ? '[redacted]' : $a, 'after' => $private && $b !== null ? '[redacted]' : $b];
                }
            }
            return $changes;
        };
        $beforeDeactivateRows = $requestRows();
        $run('deactivate');
        $afterDeactivateRows = $requestRows();
        self::assertFalse(as_has_scheduled_action($hook, [$id], Scheduler::GROUP));
        // Persist an elapsed clock boundary while inactive, without changing the system clock.
        self::assertSame(1, $wpdb->update($schema->workflows_table(), ['buyer_deadline_gmt' => gmdate('Y-m-d H:i:s', time() - 60)], ['order_id' => $id]));
        $store = new \Zarbalman\Core\Infrastructure\WorkflowStore($wpdb, $repos['audit']);
        $before = $store->snapshot($id); self::assertSame('delivered', $before['state']);
        $finance = CandidateFixture::financialSnapshot();
        $beforeActivateRows = $requestRows();
        $run('activate');
        $afterActivateRows = $requestRows();
        $admin = get_user_by('login', 'zbm_test_admin'); self::assertInstanceOf(WP_User::class, $admin);
        [$cookie] = CandidateHttp::auth((int) $admin->ID, 'unused-sweep-read');
        $http = Task4EndpointHttp::get('task4-endpoint-harness.php', ['view' => 'deadline-sweep'], $cookie);
        self::assertSame(200, $http['status']); $result = json_decode($http['body'], true, 32, JSON_THROW_ON_ERROR);
        file_put_contents(__DIR__ . '/../../evidence/task4-reactivation.json', wp_json_encode(['before' => $before, 'after' => $store->snapshot($id), 'sweep' => $result]));
        $afterSweepRows = $requestRows();
        file_put_contents(__DIR__ . '/../../evidence/task4-lifecycle-columns.json', wp_json_encode([
            'overdue_order_id' => $id,
            'request_ids_before' => array_map('intval', array_keys($beforeDeactivateRows)),
            'deactivate' => $columns($beforeDeactivateRows, $afterDeactivateRows),
            'activate' => $columns($beforeActivateRows, $afterActivateRows),
            'sweep' => $columns($afterActivateRows, $afterSweepRows),
        ], JSON_PRETTY_PRINT));
        self::assertTrue($result['registered']); self::assertTrue($result['scheduled']);
        self::assertSame('admin_review', $store->snapshot($id)['state']); self::assertSame($before['version'] + 1, $store->snapshot($id)['version']);
        self::assertSame($finance, CandidateFixture::financialSnapshot());
        $events = array_values(array_filter($repos['audit']->for_order($id, 20), static fn($event) => $event->event_key === 'workflow_transitioned' && $event->to_state === 'admin_review'));
        self::assertCount(1, $events); self::assertSame('system', $events[0]->actor_type); self::assertSame(0, (int) $events[0]->actor_user_id);
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

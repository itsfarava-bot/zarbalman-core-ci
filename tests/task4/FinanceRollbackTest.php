<?php
declare(strict_types=1);
require_once __DIR__.'/../support/CandidateFixture.php';
require_once __DIR__.'/../candidates/FinanceDestinationTest.php';
use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Application\{Spec4FinanceService,ManualPaymentSettings};
use Zarbalman\Core\Infrastructure\{Schema,FinancialActionRepository,PaymentRepository,LedgerRepository,ManagedEvidence,EvidenceUploader,EvidenceCrypto,ProtectedText,DeliveryDataCrypto,DependencyHealth};
use Zarbalman\Core\Integration\DokanManualSettlementAdapter;
final class FinanceRollbackTest extends TestCase {
    private function snapshot(Schema $schema): array {
        global $wpdb;$result=[];
        foreach ($schema->tables() as $table) {
            $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY 1',$table),ARRAY_A);
            self::assertIsArray($rows);self::assertSame('',$wpdb->last_error);
            $result[$table]=hash('sha256',wp_json_encode($rows));
        }
        return $result;
    }
    public function test_exact_pre_fix_destination_failure_writes_no_business_rows_and_rolls_back(): void {
        global $wpdb;
        // Reuse the existing positive setup unchanged, then test its next valid version.
        (new FinanceDestinationTest())->test_authorized_destination_is_returned_only_after_durable_audit();
        $schema=new Schema($wpdb);$r=CandidateFixture::repositories();
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT 1',$schema->financial_actions_table()),ARRAY_A);
        self::assertIsArray($row);$id=(int)$row['request_id'];$admin=(int)get_user_by('login','zbm_test_admin')->ID;
        $protected=new ProtectedText(new DeliveryDataCrypto());$crypto=new EvidenceCrypto();$ledger=new LedgerRepository($wpdb);
        $args=[$r['requests'],$r['cases'],new FinancialActionRepository($wpdb,$r['cases']),new PaymentRepository($wpdb),$ledger,new DokanManualSettlementAdapter($wpdb,$ledger),new ManagedEvidence(new EvidenceUploader($crypto),$crypto,$r['requests'],$r['cases'],$r['lock']),$protected,new DependencyHealth(),new ManualPaymentSettings($protected,$r['audit'])];
        $source=file_get_contents(__DIR__.'/../../plugin/src/Application/Spec4FinanceService.php');
        $fixed="return \$this->result( \$case, array( 'actor_id' => \$admin_id, 'financial_action_id' => \$action_id ), array() );";
        self::assertSame(1,substr_count($source,$fixed),'Exact Task 3 repair must exist.');
        $source=str_replace($fixed,"return \$this->result( \$case, array( 'actor_id' => \$admin_id, 'financial_action_id' => \$action_id ) );",$source);
        $source=str_replace('final class Spec4FinanceService {','final class Spec4FinanceServicePreFix {',$source);
        eval(substr($source,5)); // Only repository source; exact old call, isolated test class.
        $old=new \Zarbalman\Core\Application\Spec4FinanceServicePreFix(...$args);
        $case=$r['cases']->find($id);$before=$this->snapshot($schema);
        $trace=[];
        $recorder=static function(string $sql) use (&$trace): string { $trace[]=$sql;return $sql; };
        add_filter('query',$recorder,PHP_INT_MAX);
        try { $result=$old->destination($id,$case['version'],(int)$row['id'],$admin,wp_generate_uuid4(),'Synthetic original failure'); }
        finally { remove_filter('query',$recorder,PHP_INT_MAX); }
        self::assertInstanceOf(WP_Error::class,$result);self::assertSame('zbm_managed_storage_failed',$result->get_error_code());
        self::assertContains('START TRANSACTION',$trace);self::assertContains('ROLLBACK',$trace);
        $writes=array_values(array_filter($trace,static fn($sql)=>1 === preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|COMMIT)\b/i',$sql)));
        self::assertSame([],$writes,'No durable-business write occurred before the original ArgumentCountError.');
        self::assertSame($before,$this->snapshot($schema));
        file_put_contents(__DIR__.'/../../evidence/task4-finance-rollback.json',wp_json_encode([
            'exact_original_call_restored'=>true,'rejection'=>$result->get_error_code(),
            'transaction_started'=>in_array('START TRANSACTION',$trace,true),
            'rollback'=>in_array('ROLLBACK',$trace,true),'durable_write_count'=>count($writes),
            'all_business_tables_unchanged'=>true,
        ],JSON_PRETTY_PRINT));
        $current=new Spec4FinanceService(...$args);
        self::assertSame('IR000000000000000000000000',$current->destination($id,$case['version'],(int)$row['id'],$admin,wp_generate_uuid4(),'Synthetic fixed positive control'));
        self::assertSame($case['version']+1,$r['cases']->find($id)['version']);
        wp_set_current_user(0);
    }
}

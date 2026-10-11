<?php
declare(strict_types=1);
require_once __DIR__.'/../support/Task4ManagedFixture.php';
require_once __DIR__.'/../support/Task4EndpointHttp.php';
use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Application\ManualPaymentSettings;
use Zarbalman\Core\Infrastructure\{ProtectedText,DeliveryDataCrypto,EvidenceCrypto};
use Zarbalman\Core\Security\AdminCapabilities;
final class EvidencePurposePrivacyTest extends TestCase {
    private mixed $previousUnit;
    protected function setUp(): void { parent::setUp();$this->previousUnit=get_option('woocommerce_weight_unit',null);update_option('woocommerce_weight_unit','kg'); }
    protected function tearDown(): void { $this->previousUnit===null?delete_option('woocommerce_weight_unit'):update_option('woocommerce_weight_unit',$this->previousUnit);wp_set_current_user(0);unset($_COOKIE[LOGGED_IN_COOKIE]);parent::tearDown(); }
    public function test_owner_vendor_weight_positive_and_receipt_finance_capability_separation(): void {
        $users=Task4ManagedFixture::users();$r=CandidateFixture::repositories();
        $protected=new ProtectedText(new DeliveryDataCrypto());self::assertTrue($protected->ensureKey());
        $settings=new ManualPaymentSettings($protected,$r['audit']);
        self::assertTrue($settings->save($users['admin'],['enabled'=>true,'card_number'=>'4242424242424242','holder_name'=>'Synthetic holder'],'Synthetic privacy fixture'));
        $id=wp_insert_user(['user_login'=>'task4_ops_'.str_replace('-','',wp_generate_uuid4()),'user_pass'=>wp_generate_password(),'role'=>'subscriber']);
        self::assertIsInt($id);$operations=new WP_User($id);$operations->add_cap('manage_options');$operations->add_cap('zbm_manage_operations');$operations->add_cap('zbm_manage_finance',false);
        self::assertTrue(AdminCapabilities::userCanOperate($id));self::assertFalse(AdminCapabilities::userCanManageFinance($id));
        $actors=['buyer'=>$users['buyer_1'],'vendor'=>$users['vendor_1'],'finance'=>$users['admin'],'operations'=>$id];
        $rows=[];
        foreach (['weight'=>'flow_accept_weight','receipt'=>'manual_approve','finance'=>'spec4_approve_action'] as $purpose=>$operation) {
            $fixture=new Task4ManagedFixture($operation);$case=$fixture->case();
            $attachment=match($purpose){'weight'=>reset($case['data']['lines'])['buyer_evidence'][0],'receipt'=>$case['data']['manual']['balance']['receipt_ids'][0],'finance'=>$case['data']['spec4']['evidence'][0]['id']};
            self::assertGreaterThan(0,$attachment);
            $binary=(new EvidenceCrypto())->decrypt_file(get_attached_file($attachment,true));self::assertIsString($binary);
            foreach ($actors as $role=>$actor) {
                [$cookie,$nonce]=CandidateHttp::auth($actor,'zbm_core_managed_evidence_'.$fixture->id.'_'.$attachment);
                $before=Task4ManagedFixture::snapshot();
                $http=Task4EndpointHttp::get('wp-admin/admin-post.php',['action'=>'zbm_core_download_managed_evidence','request_id'=>$fixture->id,'attachment_id'=>$attachment,'_wpnonce'=>$nonce],$cookie);
                $allowed=$purpose==='weight' || $role==='finance' || ($purpose==='receipt' && $role==='buyer');
                self::assertSame($allowed?200:403,$http['status'],$purpose.'/'.$role.': '.$http['body']);
                if ($allowed) { self::assertSame($binary,$http['body']); }
                else { self::assertStringContainsString('مدرک خصوصی در دسترس نیست یا مالک و هدف آن معتبر نیست.',html_entity_decode(strip_tags($http['body']),ENT_QUOTES|ENT_HTML5,'UTF-8')); }
                self::assertSame($before,Task4ManagedFixture::snapshot());
                $rows[$purpose.'/'.$role]=['http'=>$http['status'],'reason'=>$allowed?'verified bound binary':'exact purpose/capability denial'];
                file_put_contents(__DIR__.'/../../evidence/task4-evidence-privacy.json',wp_json_encode($rows,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
            }
        }
        wp_set_current_user(0);unset($_COOKIE[LOGGED_IN_COOKIE]);
    }
}

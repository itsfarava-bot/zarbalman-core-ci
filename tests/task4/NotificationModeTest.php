<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/CandidateFixture.php';
require_once __DIR__ . '/../support/RecordingSms.php';
use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Infrastructure\{NotificationWorker, DependencyHealth, Schema};
use Zarbalman\Core\Integration\PwSmsAdapter;
use Zarbalman\Core\Notification\{MessageCatalog, RecipientResolver};
final class NotificationModeTest extends TestCase {
    public function test_persisted_test_modes_cancel_notices_and_live_control_sends_once(): void {
        global $wpdb;
        PWSMS()->sent = [];
        foreach (['test_pending','test','live'] as $mode) {
            [$request,$r] = CandidateFixture::request(['execution_mode'=>$mode,'current_state'=>'supplier_rejected']);
            update_user_meta($request->buyerId(),'billing_phone','09120000000');
            $event = wp_generate_uuid4();
            // Insert a legacy queued row so enqueue's own guard cannot mask Worker behavior.
            self::assertSame(1,$wpdb->insert((new Schema($wpdb))->notification_outbox_table(),[
                'dedupe_hash'=>hash('sha256',$event),'event_uuid'=>$event,'request_id'=>$request->id(),
                'recipient_role'=>'buyer','recipient_user_id'=>$request->buyerId(),'template_key'=>'request_rejected',
                'payload_json'=>'{}','status'=>'pending','next_attempt_gmt'=>current_time('mysql',true),'created_at_gmt'=>current_time('mysql',true)]));
            $worker = new NotificationWorker($r['outbox'],$r['requests'],new RecipientResolver(),new MessageCatalog(),new PwSmsAdapter(new DependencyHealth()),$r['audit'],$r['cases']);
            $worker->dispatch();
            self::assertCount('live' === $mode ? 1 : 0,PWSMS()->sent,$mode);
            self::assertSame('live' === $mode ? 'sent' : 'cancelled',$wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE event_uuid=%s',(new Schema($wpdb))->notification_outbox_table(),$event)));
        }
        $worker->dispatch(); self::assertCount(1,PWSMS()->sent);
    }
}

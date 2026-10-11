<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Infrastructure\{Installer,Scheduler,Spec4Scheduler,ManagedFlowScheduler};
final class LifecycleActionCoverageTest extends TestCase {
    public function hookProvider(): array {
        return array_map(static fn($hook)=>[$hook],[Spec4Scheduler::TICK,Spec4Scheduler::SWEEP,ManagedFlowScheduler::DELIVERY_REMINDER_HOOK,ManagedFlowScheduler::DELIVERY_OVERDUE_HOOK]);
    }
    /** @dataProvider hookProvider */
    public function test_newer_core_scheduler_actions_are_cancelled_with_arguments(string $hook): void {
        $args=[random_int(100000,999999)];
        self::assertGreaterThan(0,as_schedule_single_action(time()+3600,$hook,$args,Scheduler::GROUP));
        self::assertGreaterThan(0,as_schedule_single_action(time()+3600,$hook,$args,'foreign-test-group'));
        Installer::deactivate();
        self::assertFalse(as_has_scheduled_action($hook,$args,Scheduler::GROUP),$hook);
        self::assertTrue(as_has_scheduled_action($hook,$args,'foreign-test-group'),$hook);
    }
}

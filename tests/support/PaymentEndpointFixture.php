<?php
declare(strict_types=1);

require_once __DIR__ . '/Task4ManagedFixture.php';

use PHPUnit\Framework\Assert;
use Zarbalman\Core\Application\BalancePaymentService;
use Zarbalman\Core\Domain\Finance\Spec4Data;
use Zarbalman\Core\Http\BalancePaymentController;
use Zarbalman\Core\Infrastructure\{BalancePaymentRepository, DependencyHealth, ManagedCaseRepository, Migrator};

/** Reads the registered objects. No dependency, provider, or eligibility replacement. */
final class PaymentEndpointFixture {
    public Task4ManagedFixture $managed;
    public BalancePaymentService $service;
    public BalancePaymentRepository $payments;
    public ManagedCaseRepository $cases;
    public DependencyHealth $health;

    public static function property(object $object, string $name): mixed {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }

    public static function controller(string $hook): BalancePaymentController {
        $registration = $GLOBALS['wp_filter'][$hook] ?? null;
        Assert::assertInstanceOf(WP_Hook::class, $registration);
        $matches = [];
        foreach ($registration->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                $target = $callback['function'];
                if (is_array($target) && ($target[0] ?? null) instanceof BalancePaymentController) { $matches[] = $target[0]; }
            }
        }
        Assert::assertCount(1, $matches, 'Exactly one real balance controller must be registered.');
        return $matches[0];
    }

    public function __construct() {
        $controller = self::controller('admin_post_zbm_balance_start');
        $this->service = self::property($controller, 'service');
        $this->payments = self::property($controller, 'payments');
        $this->cases = self::property($this->service, 'cases');
        $this->health = self::property($this->service, 'health');
        Assert::assertNull(Migrator::financeAvailabilityError(), 'Finance migrations must not explain not_payable.');
        $this->managed = new Task4ManagedFixture('spec4_create_refund');
        $this->contract();
    }

    /** Every financial precondition is asserted independently of the HTTP error text. */
    public function contract(): array {
        $request = $this->managed->request(); $case = $this->cases->find($request->id());
        Assert::assertIsArray($case);
        Assert::assertSame('live', $request->executionMode());
        Assert::assertSame($this->managed->users['buyer_1'], $request->buyerId());
        Assert::assertSame($this->managed->users['vendor_1'], $request->vendorId());
        Assert::assertGreaterThan(0, $request->wcOrderId());
        Assert::assertFalse($request->isPaused()); Assert::assertFalse($request->needsReconciliation());
        Assert::assertFalse($case['data']['pause']['active']);
        Assert::assertSame('balance_due', $case['state']); Assert::assertSame('balance_due', $case['data']['spec4']['substate']);
        Assert::assertGreaterThan(current_time('mysql', true), $case['deadline_gmt']);
        Assert::assertNotNull($case['data']['delivery']['delivered_at']); Assert::assertNotNull($case['data']['final']['locked_at']);
        Assert::assertSame(1000, $case['data']['final']['total']);
        Assert::assertSame(100, $case['data']['funds']['deposit']['amount']);
        Assert::assertSame(0, $case['data']['funds']['balance']['amount']);
        Assert::assertNull($case['data']['manual']['balance']); Assert::assertNull($case['data']['spec4']['trigger']);
        Assert::assertSame('final', $case['data']['spec4']['basis_kind']);
        Assert::assertSame(1000, $case['data']['spec4']['basis_toman']);
        Assert::assertSame(900, $case['data']['spec4']['remaining_toman']);
        Assert::assertTrue($this->cases->depositVerified($request, $case));
        Spec4Data::validate($case['data']['spec4'], $request, $case);
        $this->managed->validate($case);
        $payable = (new ReflectionMethod($this->service, 'payable'))->invoke($this->service, $request, $case);
        Assert::assertTrue($payable, 'Actual registered service payable predicate must accept the canonical fixture.');
        return ['live' => true, 'owned' => true, 'delivered' => true, 'final_locked' => true,
            'total_toman' => 1000, 'deposit_toman' => 100, 'balance_toman' => 0, 'remaining_toman' => 900,
            'paused' => false, 'reconciliation_required' => false, 'manual_balance' => false,
            'deposit_verified' => true, 'spec4_valid' => true, 'state' => $case['state'], 'substate' => $case['data']['spec4']['substate'],
            'payable' => $payable, 'version' => $case['version']];
    }

    /** Historical issued-obligation fixture, stored with the real repository transaction contract.
     * Synthetic Authority is never asserted to originate from a provider. The historical
     * fingerprint is an opaque stored fact; no merchant credential is created or changed.
     */
    public function issuedObligation(): array {
        $case = $this->managed->case();
        $fingerprint = hash('sha256', 'task4-historical-merchant-binding|' . wp_generate_uuid4());
        $authority = 'TASK4HISTORICAL' . str_replace('-', '', wp_generate_uuid4());
        $result = $this->cases->mutate($this->managed->id, $case['version'], 'buyer', $this->managed->users['buyer_1'], wp_generate_uuid4(),
            'spec4_create_intent', [], function ($request, array $current) use ($fingerprint, $authority): array {
                $row = $this->payments->prepare($request, $current, $fingerprint);
                $row = $this->payments->begin($row, $current['deadline_gmt']);
                $row = $this->payments->issued($row, $authority);
                $current['data']['spec4']['payment_id'] = (int) $row['id'];
                return ['case' => $current, 'request_fields' => [], 'audit' => ['payment_id' => (int) $row['id']], 'notifications' => []];
            });
        Assert::assertFalse(is_wp_error($result), 'Real repository must accept historical issued-obligation fixture.');
        Assert::assertIsArray($result);
        $row = $this->payments->get($result['data']['spec4']['payment_id']); Assert::assertIsArray($row);
        Assert::assertSame('authority_issued', $row['status']); Assert::assertSame($authority, $row['authority']);
        Assert::assertSame(900, (int) $row['expected_amount_toman']); Assert::assertNull($row['paid_amount_toman']);
        Assert::assertTrue((new ReflectionMethod($this->service, 'binding'))->invoke($this->service, $row, $this->managed->request(), $result));
        return ['payment' => (string) $row['id'], 'offer' => $result['data']['spec4']['offer_id'], 'Authority' => $authority];
    }
}

<?php
declare(strict_types=1);
use PHPUnit\Framework\Assert;
use Zarbalman\Core\Infrastructure\{Schema, DeliveryDataCrypto, ProtectedText, ManagedCaseRepository, VendorShippingRateRepository};
use Zarbalman\Core\Domain\Finance\Spec4Data;
use Zarbalman\Core\Domain\Shipping\IranProvinceCatalog;

final class Task4RemainingFixture {
    public static function product(int $vendor, string $name = 'Task4 synthetic catalogue product'): WC_Product {
        update_user_meta($vendor, 'dokan_enable_selling', 'yes');
        $product = new WC_Product_Simple(); $product->set_name($name); $product->set_status('publish');
        $product->set_regular_price('1000'); $product->set_weight('1'); $product->set_manage_stock(true);
        $product->set_stock_quantity(100); $product->set_catalog_visibility('visible'); $product->save();
        wp_update_post(['ID' => $product->get_id(), 'post_author' => $vendor]);
        clean_post_cache($product->get_id());
        Assert::assertSame($vendor, (int) dokan_get_vendor_by_product($product->get_id(), true));
        return wc_get_product($product->get_id());
    }
    public static function createFields(WC_Product $product, int $vendor): array {
        $catalog = IranProvinceCatalog::all(); Assert::assertTrue(IranProvinceCatalog::isComplete($catalog));
        $province = (string) array_key_first($catalog);
        (new VendorShippingRateRepository())->saveRates($vendor, [$province => 100]);
        $date = (new DateTimeImmutable('today', wp_timezone()))->modify('+5 days');
        return ['action' => 'zbm_create_purchase_request', 'product_id' => (string) $product->get_id(), 'variation_id' => '0', 'quantity' => '1',
            'delivery_method' => 'delivery_to_buyer_address', 'delivery_window_type' => 'exact',
            'exact_date_display' => Zarbalman\Core\Support\JalaliDate::format($date, '/', false), 'exact_date' => $date->format('Y-m-d'),
            'start_time' => '12:00', 'end_time' => '14:00', 'recipient_name' => 'Synthetic Buyer',
            'postal_code' => '1234567890', 'address' => 'Synthetic address for the disposable sandbox',
            'province_code' => $province, 'idempotency_key' => wp_generate_uuid4()];
    }
    public static function refund(int $buyer, int $admin): array {
        global $wpdb;
        $order = wc_create_order(['customer_id' => $buyer]);
        [$request, $repos] = CandidateFixture::request(['wc_order_id' => $order->get_id()]);
        $case = CandidateFixture::caseFor($request); $now = current_time('mysql', true); $binding = wp_generate_uuid4();
        $case['data']['spec4'] = Spec4Data::initial($request, $case, $now, $binding, 'estimate');
        $protected = new ProtectedText(new DeliveryDataCrypto()); Assert::assertTrue($protected->ensureKey());
        $iban = 'IR000000000000000000000000';
        $hash = hash('sha256', wp_generate_uuid4());
        Assert::assertSame(1, $wpdb->insert((new Schema($wpdb))->financial_actions_table(), [
            'request_id' => $request->id(), 'order_id' => $order->get_id(), 'phase' => 'allocation', 'action_type' => 'cancellation_refund',
            'status' => 'pending', 'base_amount_toman' => 1000, 'buyer_refund_amount_toman' => 100,
            'allocation_key' => $hash, 'idempotency_key' => $hash,
            'destination_ciphertext' => $protected->encrypt('refund_destination', $request->id(), $binding, $iban),
            'created_by' => $admin, 'created_at_gmt' => $now, 'updated_at_gmt' => $now]));
        $action = (int) $wpdb->insert_id; $case['data']['spec4']['finance_action_ids'] = [$action];
        (new ReflectionMethod(ManagedCaseRepository::class, 'validateCase'))->invoke($repos['cases'], $case, $request);
        CandidateFixture::saveCase($case);
        return [$request, $action, $iban, $repos];
    }
}

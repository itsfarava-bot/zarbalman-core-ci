<?php
declare(strict_types=1);
// Copy only into the disposable WordPress root. Production shortcode authorization remains in place.
require __DIR__ . '/wp-load.php';
if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true) { http_response_code(403); exit('Sandbox required'); }
$view = isset($_GET['view']) && is_string($_GET['view']) ? $_GET['view'] : '';
if ($view === 'health') {
    $health = new Zarbalman\Core\Infrastructure\DependencyHealth();
    $error = $health->requestCreationError();
    wp_send_json(['report' => $health->report(), 'request_creation_error' => is_wp_error($error) ? ['code' => $error->get_error_code(), 'message' => $error->get_error_message()] : null]);
}
if ($view === 'validate-input') {
    if (!current_user_can('manage_options')) { wp_send_json(['code' => 'sandbox_admin_required'], 403); }
    // Use the registered production controller. No service creation, fake health or provider substitution.
    $hook = $GLOBALS['wp_filter']['admin_post_zbm_create_purchase_request'] ?? null;
    foreach ($hook instanceof WP_Hook ? $hook->callbacks : [] as $callbacks) {
        foreach ($callbacks as $callback) {
            $target = $callback['function'];
            if (is_array($target) && ($target[0] ?? null) instanceof Zarbalman\Core\Frontend\BuyerRequestController) {
                try {
                    $input = $target[0]->validatedInput(wp_unslash($_POST));
                    wp_send_json(['product_id' => $input->productId(), 'vendor_id' => $input->vendorId(), 'quantity' => $input->quantity(), 'delivery_method' => $input->deliveryMethod(), 'province_code' => $input->provinceCode(), 'shipping_rate_toman' => $input->shippingRatePerKgToman()]);
                } catch (InvalidArgumentException | OverflowException $error) {
                    wp_send_json(['code' => 'zbm_request_input_invalid', 'message' => $error->getMessage()], 400);
                }
            }
        }
    }
    wp_send_json(['code' => 'registered_controller_missing'], 500);
}
$shortcode = isset($_GET['shortcode']) && is_string($_GET['shortcode']) ? $_GET['shortcode'] : '';
if (!in_array($shortcode, ['zbm_buyer_requests', 'zbm_supplier_requests', 'zbm_order_workflow'], true)) { http_response_code(400); exit('Unknown shortcode'); }
$order = absint($_GET['order_id'] ?? 0);
echo do_shortcode('[' . $shortcode . ($shortcode === 'zbm_order_workflow' ? ' order_id="' . $order . '"' : '') . ']');

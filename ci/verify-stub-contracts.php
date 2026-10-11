<?php
// Diagnostic only, not a replacement for existing tests or YITH/Dokan Pro.
if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true) {
    throw new RuntimeException('Disposable synthetic sandbox required');
}
$order = new WC_Order();
$order->update_meta_data('_zbm_ci_int_probe', 17);
$order->update_meta_data('_zbm_ci_bool_probe', true);
if ($order->get_meta('_zbm_ci_int_probe', true) !== 17 || $order->get_meta('_zbm_ci_bool_probe', true) !== true) {
    throw new RuntimeException('WC metadata int/bool assumption was not verified');
}
$item = new WC_Order_Item_Product();
$item->set_name('Synthetic line item');
$item->set_quantity(1);
$order->add_item($item);
$items = $order->get_items('line_item');
if (count($items) !== 1 || !reset($items) instanceof WC_Order_Item_Product) {
    throw new RuntimeException('WC line_item subtype assumption was not verified');
}
// No save(), payment, gateway, SMS, or source mutation in this diagnostic.
echo 'PASS: real WC_Data accepts int and bool metadata (in-memory, no persistent round-trip claim).' . PHP_EOL;
echo 'PASS: real get_items(line_item) returns WC_Order_Item_Product for a synthetic in-memory item.' . PHP_EOL;
echo 'Diagnostic assertions: 3; existing PHPUnit tests remain unchanged.' . PHP_EOL;

<?php
declare(strict_types=1);
/** Test double at the native WooCommerce gateway registry/class seam. Never contacts a provider. */
final class WC_ZPal extends WC_Payment_Gateway {
    public function __construct() {
        $this->id = 'WC_ZPal';
        $this->method_title = 'Task 5 double-backed gateway';
        $this->title = 'Task 5 double-backed gateway';
        $this->enabled = 'yes';
        $this->supports = ['products'];
    }
    public function process_payment($order_id) {
        throw new RuntimeException('The Task 5 registry double does not implement commercial payment issuance.');
    }
}
/** Native verifier tests require this class-presence marker; real transport remains wp_remote_post. */
final class ZarinpalHelperClass {}

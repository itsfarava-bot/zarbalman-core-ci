<?php
declare(strict_types=1);
if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true) {
    throw new RuntimeException('Synthetic fixtures require the disposable sandbox.');
}
foreach (['zbm_test_admin' => 'administrator', 'zbm_test_buyer_1' => 'customer', 'zbm_test_buyer_2' => 'customer', 'zbm_test_vendor_1' => 'seller', 'zbm_test_vendor_2' => 'seller'] as $login => $role) {
    if (!get_role($role)) {
        throw new RuntimeException('Required real role is missing: ' . $role);
    }
    $id = username_exists($login);
    if (!$id) {
        $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(32, true, true), 'user_email' => $login . '@example.invalid', 'role' => $role]);
    }
    if (is_wp_error($id)) {
        throw new RuntimeException('Unable to create a synthetic user.');
    }
}
// Guest is an unauthenticated HTTP session, not a stored WordPress account.
WP_CLI::success('Five synthetic users exist. No real users, orders or credentials imported.');

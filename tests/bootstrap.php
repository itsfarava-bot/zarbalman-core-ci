<?php
declare(strict_types=1);
$root = getenv('ZBM_TEST_WP_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) {
    throw new RuntimeException('Real disposable WordPress root is required.');
}
require $root . '/wp-load.php';
if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true) {
    throw new RuntimeException('Refusing to test a non-sandbox WordPress installation.');
}

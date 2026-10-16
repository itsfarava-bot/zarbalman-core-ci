<?php
declare(strict_types=1);

/** Owned test-only MU plugin. The normal runner and HTTP processes remain inert. */
$task7Scenario = PHP_SAPI === 'cli' ? getenv('ZBM_TASK7_DEPENDENCY_SCENARIO') : false;
if (!in_array($task7Scenario, ['missing_woocommerce', 'missing_dokan', 'missing_woo_and_scheduler', 'broken_currency', 'broken_weight', 'native_yith_absent'], true)) { return; }
if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true) { throw new RuntimeException('Disposable sandbox required.'); }
$task7Excluded = match ($task7Scenario) {
    'missing_woocommerce' => ['woocommerce/woocommerce.php'],
    'missing_dokan' => ['dokan-lite/dokan.php', 'dokan/dokan.php'],
    'missing_woo_and_scheduler' => ['woocommerce/woocommerce.php', 'dokan-lite/dokan.php', 'dokan/dokan.php'],
    default => [],
};
// Exercise WordPress' real plugin loading boundary, without uninstalling files,
// rewriting active_plugins, defining replacement classes, or replacing health.
add_filter('option_active_plugins', static fn (array $plugins): array => array_values(array_diff($plugins, $task7Excluded)), PHP_INT_MAX);
add_filter('site_option_active_sitewide_plugins', static fn (array $plugins): array => array_diff_key($plugins, array_flip($task7Excluded)), PHP_INT_MAX);
if ($task7Scenario === 'broken_currency') {
    add_filter('pre_option_woocommerce_currency', static fn (): string => 'USD', PHP_INT_MAX);
}
if ($task7Scenario === 'broken_weight') {
    add_filter('pre_option_woocommerce_weight_unit', static fn (): string => 'g', PHP_INT_MAX);
}

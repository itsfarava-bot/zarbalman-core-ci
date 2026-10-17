<?php
declare(strict_types=1);
// Copy only into .runtime/wp. Inspect the actual HTTP WordPress registration scope.
require __DIR__ . '/wp-load.php';
if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true) {
    http_response_code(403); exit('Sandbox required');
}
if (!current_user_can('manage_options')) { wp_send_json(['code' => 'sandbox_admin_required'], 403); }
// setup.sh copies the production plugin, while test support remains in the repository.
require_once dirname(__DIR__, 2) . '/tests/support/HttpSurfaceRegistry.php';
$rows = [];
foreach (HttpSurfaceRegistry::catalog()['rows'] as $row) {
    $rows[$row['id']] = ['registered' => HttpSurfaceRegistry::isRegistered($row),
        'expected_registered' => $row['expected_registered']];
}
wp_send_json(['schema_version' => 1, 'runtime' => 'authenticated-http', 'actual_http' => true, 'rows' => $rows]);

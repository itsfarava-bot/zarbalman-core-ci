<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require getenv('ZBM_TEST_WP_ROOT') . '/wp-load.php';
if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true) { exit(1); }
require_once __DIR__ . '/Task5FinancialFixture.php';

use Zarbalman\Core\Http\ManagedCaseActionController;
use Zarbalman\Core\Infrastructure\DependencyHealth;

$input = json_decode((string) stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$request = CandidateFixture::repositories()['requests']->find((int) $input['request_id']);
if (!$request) { throw new RuntimeException('Synthetic request required.'); }
$controller = Task5FinancialFixture::registered('wp_ajax_zbm_managed_case_action', ManagedCaseActionController::class);
$service = (new ReflectionProperty($controller, 'manual'))->getValue($controller);
wp_set_current_user($request->buyerId());
$result = match ($input['operation']) {
    'offer' => $service->offer($request->id(), 0, $request->buyerId(), wp_generate_uuid4()),
    'recover' => $service->recover($request->id()),
    default => throw new RuntimeException('Unsupported probe operation.'),
};
echo json_encode(['error' => is_wp_error($result) ? $result->get_error_code() : null,
    'yith_ok' => (new DependencyHealth())->report()['yith']['ok']], JSON_THROW_ON_ERROR);

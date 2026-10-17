<?php
declare(strict_types=1);

require_once __DIR__ . '/Task4EndpointHttp.php';
require_once __DIR__ . '/HttpClient.php';

/** Complete first-admin redirects through native HTTP before business fixtures. */
final class Task5NativeAdminBootstrap {
    public static function prime(): array {
        if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true) { throw new RuntimeException('Disposable sandbox required.'); }
        $admin = get_user_by('login', 'zbm_test_admin');
        if (!$admin instanceof WP_User || !user_can($admin, 'manage_options')) { throw new RuntimeException('Native sandbox administrator required.'); }
        $actor = get_current_user_id();
        $hadCookie = isset($_COOKIE[LOGGED_IN_COOKIE]);
        $oldCookie = $_COOKIE[LOGGED_IN_COOKIE] ?? null;
        $responses = [];
        try {
            [$cookie] = CandidateHttp::auth((int) $admin->ID, 'task5-admin-bootstrap');
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $http = Task4EndpointHttp::get('wp-admin/index.php', [], $cookie);
                $location = Task4EndpointHttp::header($http, 'Location');
                $query = [];
                parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
                $path = parse_url($location, PHP_URL_PATH);
                $responses[] = ['http' => $http['status'], 'path' => $path, 'page' => $query['page'] ?? null];
                if ($http['status'] === 200) { return ['actual_http' => true, 'final_http' => 200, 'responses' => $responses]; }
                if ($http['status'] !== 302 || parse_url(home_url('/'), PHP_URL_HOST) !== parse_url($location, PHP_URL_HOST)
                    || !in_array([$path, $query['page'] ?? null], [['/wp-admin/admin.php', 'wc-admin'], ['/wp-admin/index.php', 'dokan-setup']], true)) {
                    throw new RuntimeException('Unexpected native admin bootstrap response.');
                }
            }
            throw new RuntimeException('Native admin bootstrap did not complete.');
        } finally {
            wp_set_current_user($actor);
            if ($hadCookie) { $_COOKIE[LOGGED_IN_COOKIE] = $oldCookie; } else { unset($_COOKIE[LOGGED_IN_COOKIE]); }
        }
    }
}

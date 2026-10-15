<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/HttpSurfaceRegistry.php';
require_once __DIR__ . '/../support/Task4EndpointHttp.php';
require_once __DIR__ . '/../support/Task4ManagedFixture.php';

use PHPUnit\Framework\TestCase;

final class GuestStructureTest extends TestCase {
    private function snapshot(): array {
        global $wpdb;
        $snapshot = Task4ManagedFixture::snapshot();
        foreach ([$wpdb->users, $wpdb->usermeta] as $table) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY 1', $table), ARRAY_A);
            self::assertSame('', $wpdb->last_error); self::assertIsArray($rows);
            $snapshot[$table] = hash('sha256', wp_json_encode($rows, JSON_THROW_ON_ERROR));
        }
        $options = $wpdb->get_results($wpdb->prepare('SELECT option_name,option_value,autoload FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s ORDER BY option_name',
            $wpdb->options, $wpdb->esc_like('zbm_') . '%', $wpdb->esc_like('_transient_zbm_') . '%', $wpdb->esc_like('_transient_timeout_zbm_') . '%'), ARRAY_A);
        self::assertSame('', $wpdb->last_error); self::assertIsArray($options);
        $snapshot[$wpdb->prefix . 'protected_options'] = hash('sha256', wp_json_encode($options, JSON_THROW_ON_ERROR));
        return $snapshot;
    }

    public function test_private_dispatchers_have_no_guest_hook_and_preserve_state(): void {
        global $wpdb;
        $catalog = HttpSurfaceRegistry::catalog();
        $declared = array_column(array_column($catalog['rows'], 'target'), 'hook');
        $groups = [];
        foreach ($catalog['rows'] as $row) {
            $target = $row['target']; $hook = $target['hook'] ?? '';
            if (($target['kind'] ?? null) !== 'action' || preg_match('/\A(admin_post_|wp_ajax_)(?!nopriv_)([a-z][a-z0-9_]{0,99})\z/', $hook, $match) !== 1) { continue; }
            $guest = $match[1] . 'nopriv_' . $match[2];
            if (in_array($guest, $declared, true)) { continue; } // Public routes retain their existing explicit controls.
            $groups[$hook]['ids'][] = $row['id'];
            $groups[$hook]['guest'] = $guest;
            $groups[$hook]['action'] = $match[2];
            $groups[$hook]['path'] = 'wp-admin/' . ($match[1] === 'admin_post_' ? 'admin-post.php' : 'admin-ajax.php');
        }
        self::assertNotEmpty($groups);
        $fixture = new Task4ManagedFixture('flow_note');
        wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]);
        $records = ['_meta' => ['schema_version' => 1, 'run_id' => getenv('GITHUB_RUN_ID') ?: 'local',
            'run_attempt' => getenv('GITHUB_RUN_ATTEMPT') ?: 'local']];
        $file = __DIR__ . '/../../evidence/http-guest-structure.json';
        self::assertNotFalse(file_put_contents($file, wp_json_encode($records, JSON_THROW_ON_ERROR), LOCK_EX));
        foreach ($groups as $hook => $group) {
            $before = $this->snapshot();
            $http = CandidateHttp::post($group['path'], ['action' => $group['action'], '_zbm_guest_structure' => '1',
                'request_id' => (string) $fixture->id, 'order_id' => (string) $fixture->orderId, 'operation' => 'flow_note'], '');
            $after = $this->snapshot();
            file_put_contents(__DIR__ . '/../../evidence/guest-response-debug.json', wp_json_encode([
                'http' => $http['status'], 'scope_header' => Task4EndpointHttp::header($http, 'X-Zbm-Guest-Scope') !== '',
                'json_response' => str_contains(Task4EndpointHttp::header($http, 'Content-Type'), 'json'),
            ], JSON_THROW_ON_ERROR));
            self::assertSame('native-dispatcher-wp-die', Task4EndpointHttp::header($http, 'X-Zbm-Guest-Scope'));
            self::assertSame($group['guest'], Task4EndpointHttp::header($http, 'X-Zbm-Guest-Hook'));
            self::assertSame('0', Task4EndpointHttp::header($http, 'X-Zbm-Guest-Hook-Present'), 'Private action must have no guest callback: ' . $hook);
            self::assertSame('0', Task4EndpointHttp::header($http, 'X-Zbm-Guest-Actor'));
            self::assertSame('1', Task4EndpointHttp::header($http, 'X-Zbm-Guest-Empty-Cookie'));
            self::assertSame(400, $http['status']);
            self::assertSame($before, $after, 'Guest dispatcher must preserve all business, order, account and Core settings state.');
            foreach ($group['ids'] as $id) {
                $records[$id] = ['state' => 'passed', 'assertion_status' => 'passed', 'actual_http' => true,
                    'test' => __METHOD__, 'catalog_ids' => [$id], 'table_prefix' => $wpdb->prefix, 'scope' => 'native-dispatcher-wp-die',
                    'protected_hook' => $hook, 'guest_hook' => $group['guest'], 'guest_hook_registered' => false,
                    'guest_actor_id' => 0, 'empty_cookie' => true, 'http' => $http['status'], 'path' => $group['path'],
                    'before_snapshot' => $before, 'after_snapshot' => $after];
            }
            self::assertNotFalse(file_put_contents($file, wp_json_encode($records, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX));
        }
        self::assertGreaterThanOrEqual(60, count($records) - 1);
    }
}

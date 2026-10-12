<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/HttpSurfaceRegistry.php';
require_once __DIR__ . '/../support/HttpClient.php';
require_once __DIR__ . '/../support/Task4EndpointHttp.php';
use PHPUnit\Framework\TestCase;

/** Every source row must resolve to an actually registered production callback. */
final class RegisteredHttpSurfacesTest extends TestCase {
    private static array $httpRegistry = [];
    public static function surfaces(): array {
        $data = [];
        foreach (HttpSurfaceRegistry::catalog()['rows'] as $row) { $data[$row['id']] = [$row]; }
        return $data;
    }

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $directory = __DIR__ . '/../../evidence';
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) { throw new RuntimeException('Evidence directory unavailable.'); }
        file_put_contents($directory . '/http-surface-registration.json', wp_json_encode(['schema_version' => 1, 'rows' => []], JSON_PRETTY_PRINT));
        $admin = get_user_by('login', 'zbm_test_admin');
        self::assertInstanceOf(WP_User::class, $admin);
        [$cookie] = CandidateHttp::auth((int) $admin->ID, 'unused-registry-read');
        try {
            $http = Task4EndpointHttp::get('task4-registry-harness.php', [], $cookie);
            self::assertSame(200, $http['status'], 'Authenticated real HTTP registry must respond.');
            $data = json_decode($http['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('authenticated-http', $data['runtime']);
            self::assertTrue($data['actual_http']);
            self::assertCount(86, $data['rows']);
            self::$httpRegistry = $data['rows'];
        } finally {
            wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]);
        }
    }

    public function test_catalog_contains_exactly_the_declared_surface_rows(): void {
        $catalog = HttpSurfaceRegistry::catalog();
        self::assertCount(86, $catalog['rows']);
        self::assertSame(['action' => 28, 'form' => 51, 'shortcode' => 3, 'REST' => 3, 'Dokan view' => 1], $catalog['counts']);
        self::assertCount(86, array_unique(array_column($catalog['rows'], 'id')));
        foreach ($catalog['rows'] as $row) { self::assertTrue($row['expected_registered']); }
    }

    /** @dataProvider surfaces */
    public function test_production_surface_callback_is_actually_registered(array $row): void {
        $observed = self::$httpRegistry[$row['id']] ?? null;
        self::assertIsArray($observed, 'Actual HTTP registry row missing.');
        self::assertSame($row['expected_registered'], $observed['expected_registered']);
        $registered = $observed['registered'];
        self::assertIsBool($registered);
        $path = __DIR__ . '/../../evidence/http-surface-registration.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $data['rows'][$row['id']] = ['registered' => $registered, 'expected_registered' => $row['expected_registered'], 'actual_http' => true, 'runtime' => 'authenticated-http'];
        $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        self::assertSame(strlen($json), file_put_contents($path, $json, LOCK_EX));
        self::assertTrue($registered, $row['id'] . ': production callback must be registered for this declared row.');
    }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/CandidateFixture.php';
require_once __DIR__ . '/../support/Task4ManagedFixture.php';
require_once __DIR__ . '/../support/Task4EndpointHttp.php';
require_once __DIR__ . '/../support/Task4RemainingHttp.php';

use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Domain\PurchaseRequest\RequestNumber;
use Zarbalman\Core\Infrastructure\{Schema, WorkflowStore};

/** Real registered renderers and handlers; fixtures never simulate payment success. */
final class EndpointFormControlsTest extends TestCase {
    private const EVIDENCE = __DIR__ . '/../../evidence/http-form-controls.json';
    private const GATE_EVIDENCE = __DIR__ . '/../../evidence/http-settings-native-gate.json';
    private string $catalogId = '';
    private string $testMethod = '';

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::assertTrue(is_dir(dirname(self::EVIDENCE)));
        $rows = ['_meta' => ['schema_version' => 1, 'run_id' => getenv('GITHUB_RUN_ID') ?: 'local',
            'run_attempt' => getenv('GITHUB_RUN_ATTEMPT') ?: 'local', 'created_at_gmt' => gmdate('c'),
            'scope' => 'Only actual local HTTP assertions; no licensed dependency or payment success substitutes']];
        foreach (['http-012', 'http-013', 'http-016', 'http-031'] as $id) {
            $rows[$id] = ['state' => 'pending', 'assertion_status' => 'pending', 'actual_http' => false, 'controls' => []];
        }
        self::write($rows);
    }
    protected function tearDown(): void {
        wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]); parent::tearDown();
    }
    private static function write(array $rows): void {
        $json = wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertSame(strlen($json), file_put_contents(self::EVIDENCE, $json, LOCK_EX));
    }
    private function catalog(string $id, string $method, callable $test): void {
        $this->catalogId = $id; $this->testMethod = $method;
        try {
            $details = $test();
            $rows = json_decode((string) file_get_contents(self::EVIDENCE), true, 32, JSON_THROW_ON_ERROR);
            $rows[$id] = array_replace($rows[$id], ['state' => 'passed', 'assertion_status' => 'passed',
                'actual_http' => true, 'catalog_ids' => [$id], 'test' => $method], $details);
            self::write($rows);
        } catch (Throwable $error) {
            $rows = json_decode((string) file_get_contents(self::EVIDENCE), true, 32, JSON_THROW_ON_ERROR);
            $rows[$id]['state'] = 'failed'; $rows[$id]['assertion_status'] = 'failed';
            $rows[$id]['test'] = $method; $rows[$id]['reason'] = 'Assertions did not complete; see PHPUnit failure.';
            $rows[$id]['exception'] = get_class($error); self::write($rows); throw $error;
        }
    }
    private function record(string $scenario, array $details): void {
        $rows = json_decode((string) file_get_contents(self::EVIDENCE), true, 32, JSON_THROW_ON_ERROR);
        $rows[$this->catalogId]['controls'][$scenario] = ['state' => 'passed', 'assertion_status' => 'passed',
            'actual_http' => true, 'catalog_ids' => [$this->catalogId], 'test' => $this->testMethod] + $details;
        self::write($rows);
    }
    /** Persist only safe response metadata before assertions, including on failed controls. */
    private function diagnose(string $scenario, array $http, array $details): void {
        $location = Task4EndpointHttp::header($http, 'Location');
        $query = []; parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $rows = json_decode((string) file_get_contents(self::EVIDENCE), true, 32, JSON_THROW_ON_ERROR);
        $rows[$this->catalogId]['diagnostics'][$scenario] = ['actual_http' => true, 'http' => $http['status'],
            'redirect_path' => (string) parse_url($location, PHP_URL_PATH),
            'redirect_query_keys' => array_keys($query),
            'redirect_is_local_home' => $location !== '' && $location === home_url(),
            'body_bytes' => strlen($http['body'])] + $details;
        self::write($rows);
    }
    private function user(string $name): int {
        $user = get_user_by('login', 'zbm_test_' . $name); self::assertInstanceOf(WP_User::class, $user);
        return (int) $user->ID;
    }
    private function auth(string $actor, string $action): array {
        if ($actor === 'guest') { wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]); return ['', '']; }
        return CandidateHttp::auth($this->user($actor), $action);
    }
    private function primeAdmin(): void {
        [$cookie] = $this->auth('admin', 'unused-admin-bootstrap');
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $http = Task4EndpointHttp::get('wp-admin/index.php', [], $cookie);
            if ($http['status'] === 200) { break; }
            self::assertSame(302, $http['status']); $location = Task4EndpointHttp::header($http, 'Location');
            self::assertSame(parse_url(home_url('/'), PHP_URL_HOST), parse_url($location, PHP_URL_HOST));
            self::assertStringStartsWith('/wp-admin/', (string) parse_url($location, PHP_URL_PATH));
        }
        self::assertSame(200, Task4EndpointHttp::get('wp-admin/index.php', [], $cookie)['status']);
    }
    private function snapshot(): array {
        $snapshot = Task4ManagedFixture::snapshot();
        foreach (['zbm_core_delivery_reminder_minutes', 'zbm_core_sms_templates'] as $option) {
            $snapshot['option:' . $option] = hash('sha256', wp_json_encode(Task4EndpointHttp::option($option), JSON_THROW_ON_ERROR));
        }
        return $snapshot;
    }
    private function idempotencyRows(): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY key_hash', (new Schema($wpdb))->idempotency_table()), ARRAY_A);
        self::assertSame('', $wpdb->last_error); self::assertIsArray($rows);
        return array_column($rows, null, 'key_hash');
    }
    private function unchangedExcept(array $before, array $after, array $allowed): void {
        self::assertSame(array_keys($before), array_keys($after));
        foreach ($before as $table => $hash) {
            if (!in_array($table, $allowed, true)) { self::assertSame($hash, $after[$table], 'Unexpected durable change in ' . $table); }
        }
    }
    private function bodyMessage(array $http, int $status, string $message): void {
        self::assertSame($status, $http['status']);
        $plain = html_entity_decode(strip_tags($http['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertTrue(str_contains($plain, $message), 'Expected named response message: ' . $message);
    }
    private function loginRedirect(array $http): void {
        self::assertSame(302, $http['status']); $location = Task4EndpointHttp::header($http, 'Location');
        self::assertSame(parse_url(home_url('/'), PHP_URL_HOST), parse_url($location, PHP_URL_HOST));
        self::assertStringEndsWith('/wp-login.php', (string) parse_url($location, PHP_URL_PATH));
    }
    /** Observe the actual HTTP-process hooks without replacing or invoking either native gate. */
    private function installSettingsGateObserver(): string {
        $root = getenv('ZBM_TEST_WP_ROOT'); self::assertIsString($root); self::assertNotSame('', $root);
        $path = $root . '/wp-content/mu-plugins/zbm-task4-settings-gate-observer.php'; self::assertFileDoesNotExist($path);
        $code = <<<'PHP'
<?php
if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true) { return; }
add_action('admin_init', static function (): void {
    global $pagenow, $wp_filter;
    if ($pagenow !== 'options.php' || ($_POST['option_page'] ?? '') !== 'zbm_core_spec3') { return; }
    $callbacks = $wp_filter['admin_init']->callbacks; ksort($callbacks, SORT_NUMERIC); $order = 0; $gates = [];
    foreach ($callbacks as $priority => $entries) {
        foreach ($entries as $entry) {
            ++$order; $callback = $entry['function'];
            if (!is_array($callback) || !is_object($callback[0])) { continue; }
            $name = get_class($callback[0]) . '::' . $callback[1];
            if (!in_array($name, ['WC_Admin::prevent_admin_access', 'WeDevs\\Dokan\\Core::block_admin_access'], true)) { continue; }
            $method = new ReflectionMethod($callback[0], $callback[1]); $path = $method->getFileName(); $lines = file($path);
            $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
            $source = preg_replace('~/\*.*?\*/|//[^\r\n]*~s', '', $source); $compact = preg_replace('/\s+/', '', $source);
            $required = $name === 'WC_Admin::prevent_admin_access' ? [
                "\$exempted_paths=array('admin-post.php','admin-ajax.php');",
                "apply_filters('woocommerce_disable_admin_bar',true)&&isset(\$_SERVER['SCRIPT_FILENAME'])&&!in_array(basename(sanitize_text_field(wp_unslash(\$_SERVER['SCRIPT_FILENAME']))),\$exempted_paths,true)",
                "\$access_caps=array('edit_posts','manage_woocommerce','view_admin_dashboard');",
                'foreach($access_capsas$access_cap){if(current_user_can($access_cap)){$has_cap=true;break;}}',
                'if(!$has_cap){$prevent_access=true;}',
                "if(apply_filters('woocommerce_prevent_admin_access',\$prevent_access)){wp_safe_redirect(wc_get_page_permalink('myaccount'));exit;}"
            ] : [
                "\$no_access=dokan_get_option('admin_access','dokan_general','on');",
                "if(OrderUtil::is_hpos_enabled()){\$no_access='on';}",
                "\$valid_pages=['admin-ajax.php','admin-post.php','async-upload.php','media-upload.php'];",
                '$user_role=reset($current_user->roles);',
                "if(('on'===\$no_access)&&(!in_array(\$pagenow,\$valid_pages,true))&&in_array(\$user_role,['seller','customer','vendor_staff'],true)){wp_safe_redirect(home_url());exit;}"
            ];
            $verified = true; foreach ($required as $fragment) { $verified = $verified && str_contains($compact, $fragment); }
            $gates[$name] = ['priority' => $priority, 'callback_order' => $order, 'source_path' => $path, 'source_contract_verified' => $verified];
        }
    }
    $user = wp_get_current_user(); $caps = [];
    foreach (['manage_options', 'edit_posts', 'manage_woocommerce', 'view_admin_dashboard'] as $cap) { $caps[$cap] = current_user_can($cap); }
    $disableBar = apply_filters('woocommerce_disable_admin_bar', true);
    $script = isset($_SERVER['SCRIPT_FILENAME']) ? basename(sanitize_text_field(wp_unslash($_SERVER['SCRIPT_FILENAME']))) : '';
    $prevent = $disableBar && $script !== '' && !in_array($script, ['admin-post.php', 'admin-ajax.php'], true)
        && !$caps['edit_posts'] && !$caps['manage_woocommerce'] && !$caps['view_admin_dashboard'];
    $record = ['actor_user_id' => $user->ID, 'actor_roles' => $user->roles, 'gates' => $gates, 'caps' => $caps,
        'wc_version' => WC_VERSION, 'request_page' => $script, 'doing_ajax' => wp_doing_ajax(),
        'disable_admin_bar' => $disableBar, 'prevent_access' => apply_filters('woocommerce_prevent_admin_access', $prevent),
        'nonce_verified' => isset($_POST['_wpnonce']) && wp_verify_nonce((string) $_POST['_wpnonce'], 'zbm_core_spec3-options') !== false,
        'my_account_url' => wc_get_page_permalink('myaccount'), 'home_url' => home_url()];
    file_put_contents(__EVIDENCE_PATH__, wp_json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
}, 0);
PHP;
        $code = str_replace('__EVIDENCE_PATH__', var_export(self::GATE_EVIDENCE, true), $code);
        self::assertSame(strlen($code), file_put_contents($path, $code, LOCK_EX));
        return $path;
    }
    private function nativeSettingsGate(array $http, string $actor): array {
        $probe = json_decode((string) file_get_contents(self::GATE_EVIDENCE), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($this->user($actor), $probe['actor_user_id']); self::assertSame('options.php', $probe['request_page']);
        self::assertSame('10.9.4', $probe['wc_version']); self::assertFalse($probe['doing_ajax']); self::assertTrue($probe['nonce_verified']);
        $wc = $probe['gates']['WC_Admin::prevent_admin_access']; $dokan = $probe['gates']['WeDevs\\Dokan\\Core::block_admin_access'];
        self::assertSame(10, $wc['priority']); self::assertSame(10, $dokan['priority']);
        self::assertTrue($wc['source_contract_verified']); self::assertTrue($dokan['source_contract_verified']);
        self::assertStringEndsWith('/woocommerce/includes/admin/class-wc-admin.php', $wc['source_path']);
        self::assertGreaterThan($wc['callback_order'], $dokan['callback_order']);
        if ($actor === 'vendor_2') { self::assertFalse($probe['prevent_access']); return $probe; }
        self::assertSame(['customer'], $probe['actor_roles']);
        foreach ($probe['caps'] as $allowed) { self::assertFalse($allowed); }
        self::assertTrue($probe['disable_admin_bar']); self::assertTrue($probe['prevent_access']);
        self::assertSame(302, $http['status']); $location = Task4EndpointHttp::header($http, 'Location');
        self::assertSame($probe['my_account_url'], $location); self::assertSame($location, wp_validate_redirect($location, ''));
        self::assertSame(parse_url(home_url(), PHP_URL_HOST), parse_url($location, PHP_URL_HOST));
        return $probe;
    }
    private function wooOptionsDenial(array $http, array $probe): array {
        $wc = $probe['gates']['WC_Admin::prevent_admin_access']; $dokan = $probe['gates']['WeDevs\\Dokan\\Core::block_admin_access'];
        $location = Task4EndpointHttp::header($http, 'Location');
        return ['dependency_version' => $probe['wc_version'], 'hook' => 'admin_init', 'callback' => 'WC_Admin::prevent_admin_access',
            'registered' => true, 'priority' => $wc['priority'], 'source_path' => $wc['source_path'], 'source_contract_verified' => true,
            'callback_order' => $wc['callback_order'], 'dokan_callback_order' => $dokan['callback_order'], 'runs_before_dokan' => true,
            'actor_roles' => $probe['actor_roles'], 'primary_role' => 'customer', 'manage_options' => $probe['caps']['manage_options'],
            'edit_posts' => $probe['caps']['edit_posts'], 'manage_woocommerce' => $probe['caps']['manage_woocommerce'],
            'view_admin_dashboard' => $probe['caps']['view_admin_dashboard'], 'doing_ajax' => $probe['doing_ajax'],
            'disable_admin_bar' => $probe['disable_admin_bar'], 'prevent_access' => $probe['prevent_access'],
            'request_page' => $probe['request_page'], 'page_exempt' => false, 'nonce_verified' => $probe['nonce_verified'],
            'my_account_url' => $probe['my_account_url'], 'expected_url' => $probe['my_account_url'], 'redirect_url' => $location,
            'expected_path' => (string) parse_url($probe['my_account_url'], PHP_URL_PATH), 'redirect_path' => (string) parse_url($location, PHP_URL_PATH),
            'expected_query' => (string) parse_url($probe['my_account_url'], PHP_URL_QUERY), 'redirect_query' => (string) parse_url($location, PHP_URL_QUERY),
            'http_probe_verified' => true];
    }
    /** Verify the native pinned dependency gate rather than accepting an arbitrary redirect. */
    private function dokanOptionsDenial(array $http, string $actor, string $nonce, array $probe): array {
        self::assertTrue(defined('DOKAN_PLUGIN_VERSION')); self::assertSame('5.0.10', DOKAN_PLUGIN_VERSION);
        self::assertTrue(class_exists(\WeDevs\Dokan\Core::class));
        global $wp_filter;
        $priority = null;
        foreach ($wp_filter['admin_init']->callbacks as $hookPriority => $callbacks) {
            foreach ($callbacks as $entry) {
                $callback = $entry['function'];
                if (is_array($callback) && $callback[0] instanceof \WeDevs\Dokan\Core && $callback[1] === 'block_admin_access') { $priority = $hookPriority; }
            }
        }
        self::assertSame(10, $priority);
        $method = new ReflectionMethod(\WeDevs\Dokan\Core::class, 'block_admin_access'); $path = $method->getFileName();
        self::assertIsString($path); self::assertStringEndsWith('/dokan-lite/includes/Core.php', $path);
        self::assertSame($path, $probe['gates']['WeDevs\\Dokan\\Core::block_admin_access']['source_path']);
        $lines = file($path); self::assertIsArray($lines);
        self::assertStringContainsString('use WeDevs\\Dokan\\Utilities\\OrderUtil;', implode('', $lines));
        $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        $compact = preg_replace('/\s+/', '', $source); self::assertIsString($compact);
        self::assertStringContainsString("\$no_access=dokan_get_option('admin_access','dokan_general','on');", $compact);
        self::assertStringContainsString("if(OrderUtil::is_hpos_enabled()){\$no_access='on';}", $compact);
        self::assertStringContainsString("\$valid_pages=['admin-ajax.php','admin-post.php','async-upload.php','media-upload.php'];", $compact);
        self::assertStringContainsString('$user_role=reset($current_user->roles);', $compact);
        self::assertStringContainsString("if(('on'===\$no_access)&&(!in_array(\$pagenow,\$valid_pages,true))&&in_array(\$user_role,['seller','customer','vendor_staff'],true)){wp_safe_redirect(home_url());exit;}", $compact);
        self::assertTrue(method_exists(\WeDevs\Dokan\Utilities\OrderUtil::class, 'is_hpos_enabled'));
        $hpos = \WeDevs\Dokan\Utilities\OrderUtil::is_hpos_enabled(); self::assertTrue($hpos);
        $hposOption = Task4EndpointHttp::option('woocommerce_custom_orders_table_enabled'); self::assertSame('yes', $hposOption);
        $configured = dokan_get_option('admin_access', 'dokan_general', 'on'); $effective = $hpos ? 'on' : $configured;
        self::assertSame('on', $effective);
        $user = get_userdata($this->user($actor)); self::assertInstanceOf(WP_User::class, $user);
        $roles = $user->roles; $primary = reset($roles); self::assertContains($primary, ['customer', 'seller', 'vendor_staff']);
        self::assertSame($roles, $probe['actor_roles']); self::assertFalse($probe['caps']['manage_options']);
        self::assertFalse(user_can($user, 'manage_options')); self::assertNotFalse(wp_verify_nonce($nonce, 'zbm_core_spec3-options'));
        self::assertSame(302, $http['status']); $location = Task4EndpointHttp::header($http, 'Location');
        self::assertSame(home_url(), $location); self::assertSame($location, wp_validate_redirect($location, ''));
        return ['dependency_version' => DOKAN_PLUGIN_VERSION, 'hook' => 'admin_init',
            'callback' => 'WeDevs\\Dokan\\Core::block_admin_access', 'registered' => true, 'priority' => $priority,
            'source_path' => $path, 'source_contract_verified' => true, 'hpos_enabled' => $hpos,
            'hpos_helper_class' => \WeDevs\Dokan\Utilities\OrderUtil::class, 'hpos_option_enabled' => $hposOption,
            'configured_admin_access' => $configured, 'effective_admin_access' => $effective,
            'actor_roles' => $roles, 'primary_role' => $primary, 'manage_options' => false,
            'request_page' => 'options.php', 'page_exempt' => false, 'nonce_verified' => true,
            'redirect_is_local_home' => true, 'redirect_url' => $location, 'home_url' => home_url(),
            'home_path' => (string) parse_url(home_url(), PHP_URL_PATH), 'redirect_path' => (string) parse_url($location, PHP_URL_PATH),
            'http_probe_verified' => true, 'wc_prevent_access' => $probe['prevent_access'],
            'callback_order' => $probe['gates']['WeDevs\\Dokan\\Core::block_admin_access']['callback_order'],
            'woocommerce_callback_order' => $probe['gates']['WC_Admin::prevent_admin_access']['callback_order']];
    }
    private function result(array $http, string $expected): void {
        self::assertSame(302, $http['status']); $location = Task4EndpointHttp::header($http, 'Location');
        self::assertSame($location, wp_validate_redirect($location, ''));
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query); self::assertSame($expected, $query['zbm_result'] ?? null);
    }
    /** Extract the actual rendered form, including textarea and Settings API fields. */
    private function form(string $html, callable $matches): array {
        $dom = new DOMDocument(); $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        foreach ($dom->getElementsByTagName('form') as $form) {
            $fields = [];
            foreach ($form->getElementsByTagName('input') as $input) {
                $name = $input->getAttribute('name'); if ($name !== '') { $fields[$name] = $input->getAttribute('value'); }
            }
            foreach ($form->getElementsByTagName('textarea') as $textarea) {
                $name = $textarea->getAttribute('name'); if ($name !== '') { $fields[$name] = $textarea->textContent; }
            }
            if ($matches($form, $fields)) {
                return ['method' => strtolower($form->getAttribute('method')), 'action_url' => $form->getAttribute('action'), 'fields' => $fields];
            }
        }
        self::fail('Required real rendered form missing.');
    }
    private function request(array $overrides = []): array {
        global $wpdb; $schema = new Schema($wpdb);
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $number = RequestNumber::generate();
            $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE request_number=%s', $schema->purchase_requests_table(), $number));
            self::assertSame('', $wpdb->last_error); self::assertNotNull($count);
            if (0 === (int) $count) {
                self::assertTrue(RequestNumber::isValid($number));
                return CandidateFixture::request(['request_number' => $number] + $overrides);
            }
        }
        throw new RuntimeException('No unique synthetic request reference allocated.');
    }
    private function adminRows(string $html): array {
        $dom = new DOMDocument(); $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom); $numbers = [];
        foreach ($xpath->query('//table[contains(@class,"widefat")]/tbody/tr/td[1]/strong') as $node) {
            if (preg_match('/\A#([1-9][0-9]{5})\z/', trim($node->textContent), $match)) { $numbers[] = $match[1]; }
        }
        return $numbers;
    }

    public function test_admin_search_submits_actual_get_form_and_returns_only_exact_reference(): void {
        $this->catalog('http-012', __METHOD__, function (): array {
            $this->primeAdmin();
            foreach (['authorized_positive', 'persian_digits', 'no_nonce', 'invalid_nonce', 'invalid_reference',
                'missing_reference', 'wrong_role', 'other_buyer', 'other_vendor', 'guest'] as $scenario) {
                [$wanted] = $this->request(); [$other] = $this->request();
                $actor = match ($scenario) { 'wrong_role' => 'buyer_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', 'guest' => 'guest', default => 'admin' };
                [$cookie] = $this->auth($actor, 'unused-read-only-search');
                if ($actor === 'admin') {
                    $render = Task4EndpointHttp::get('wp-admin/admin.php', ['page' => 'zbm-core-requests'], $cookie);
                    self::assertSame(200, $render['status']);
                    $form = $this->form($render['body'], static fn($node, $fields) => $node->getAttribute('class') === 'zbm-admin-request-search');
                    self::assertSame('get', $form['method']); self::assertSame('zbm-core-requests', $form['fields']['page']);
                }
                $number = $wanted->requestNumber();
                $query = $actor === 'admin' ? $form['fields'] : ['page' => 'zbm-core-requests'];
                $query['request_number'] = $number;
                if ($scenario === 'persian_digits') { $query['request_number'] = strtr($number, array_combine(str_split('0123456789'), ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'])); }
                if ($scenario === 'invalid_nonce') { $query['_wpnonce'] = 'invalid'; }
                if ($scenario === 'invalid_reference') { $query['request_number'] = '12abc'; }
                if ($scenario === 'missing_reference') {
                    global $wpdb; $schema = new Schema($wpdb);
                    do { $missing = RequestNumber::generate(); $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE request_number=%s', $schema->purchase_requests_table(), $missing)); self::assertSame('', $wpdb->last_error); } while ((int) $count !== 0);
                    $query['request_number'] = $missing;
                }
                $before = $this->snapshot(); $http = Task4EndpointHttp::get('wp-admin/admin.php', $query, $cookie);
                $message = ''; $returned = [];
                if ($actor === 'guest') { $this->loginRedirect($http); $message = 'authentication_redirect_to_local_wp_login'; }
                elseif ($actor !== 'admin') { $message = __('Sorry, you are not allowed to access this page.'); $this->bodyMessage($http, 403, $message); }
                elseif ($scenario === 'invalid_reference') { $message = 'شناسه درخواست باید دقیقاً شش رقم باشد.'; $this->bodyMessage($http, 200, $message); self::assertSame([], $this->adminRows($http['body'])); }
                elseif ($scenario === 'missing_reference') { $message = 'درخواستی با این شناسه پیدا نشد.'; $this->bodyMessage($http, 200, $message); self::assertSame([], $this->adminRows($http['body'])); }
                else { self::assertSame(200, $http['status']); $returned = $this->adminRows($http['body']); self::assertSame([$number], $returned); self::assertNotContains($other->requestNumber(), $returned); }
                $after = $this->snapshot(); self::assertSame($before, $after);
                $this->record($scenario, ['type' => 'scoped-read', 'proof_type' => 'scoped-read', 'http' => $http['status'], 'actor' => $actor,
                    'actor_valid' => $actor === 'admin', 'nonce_contract' => 'N/A: registered admin GET search checks capability; nonce has no read-routing role',
                    'exact_scoped_data' => $returned === [$number], 'financial_unchanged' => true,
                    'before_snapshot' => $before, 'after_snapshot' => $after, 'observed_response' => ['message' => $message, 'returned_references' => $returned],
                    'message' => $message, 'effect' => $returned ? 'exact matching stored reference only' : 'named denial or empty scoped result']);
            }
            return ['type' => 'scoped-read', 'proof_type' => 'scoped-read', 'covered_operations' => ['request_number_search']];
        });
    }

    public function test_settings_actual_options_form_commits_sanitized_values_and_preserves_named_denials(): void {
        $admin = get_userdata($this->user('admin')); self::assertInstanceOf(WP_User::class, $admin);
        $originalManageOptions = $admin->caps['manage_options'] ?? null;
        $restoreCapability = static function () use ($admin, $originalManageOptions): void {
            if ($originalManageOptions === null) { $admin->remove_cap('manage_options'); }
            else { $admin->add_cap('manage_options', $originalManageOptions); }
        };
        $old = ['zbm_core_delivery_reminder_minutes' => Task4EndpointHttp::option('zbm_core_delivery_reminder_minutes'),
            'zbm_core_sms_templates' => Task4EndpointHttp::option('zbm_core_sms_templates')];
        $observer = null;
        try {
            $observer = $this->installSettingsGateObserver();
            $this->catalog('http-016', __METHOD__, function () use ($admin, $restoreCapability): array {
                $this->primeAdmin();
                foreach (['authorized_positive', 'zero_disabled', 'minimum_five', 'maximum_1440', 'wrong_role', 'other_buyer', 'other_vendor',
                    'guest', 'capability_denied', 'no_nonce', 'invalid_nonce', 'below_range', 'above_range', 'invalid_integer'] as $scenario) {
                    $this->auth('admin', 'unused-setting-fixture');
                    $this->restoreOption('zbm_core_delivery_reminder_minutes', 60); $this->restoreOption('zbm_core_sms_templates', []);
                    [$adminCookie] = $this->auth('admin', 'zbm_core_spec3-options');
                    $render = Task4EndpointHttp::get('wp-admin/admin.php', ['page' => 'zbm-core-spec3-settings'], $adminCookie);
                    self::assertSame(200, $render['status']);
                    $form = $this->form($render['body'], static fn($node, $fields) => ($fields['option_page'] ?? '') === 'zbm_core_spec3');
                    self::assertSame('post', $form['method']); self::assertSame('options.php', $form['action_url']);
                    self::assertSame('update', $form['fields']['action']); self::assertSame('60', $form['fields']['zbm_core_delivery_reminder_minutes']);
                    $actor = match ($scenario) { 'wrong_role' => 'buyer_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', 'guest' => 'guest', default => 'admin' };
                    // Keep the renderer session for positive/nonce controls; other actors receive a nonce valid for their own session.
                    if ($actor === 'admin') { $cookie = $adminCookie; $nonce = $form['fields']['_wpnonce']; }
                    else { [$cookie, $nonce] = $this->auth($actor, 'zbm_core_spec3-options'); }
                    if ($actor !== 'guest') { self::assertNotFalse(wp_verify_nonce($nonce, 'zbm_core_spec3-options')); }
                    $minutes = match ($scenario) { 'zero_disabled' => '0', 'minimum_five' => '5', 'maximum_1440' => '1440', 'below_range' => '4', 'above_range' => '1441', 'invalid_integer' => '5.5', default => '120' };
                    $fields = $form['fields']; $fields['_wpnonce'] = $nonce; $fields['zbm_core_delivery_reminder_minutes'] = $minutes;
                    $fields['zbm_core_sms_templates[request_rejected]'] = '  Sandbox {request} <b>message</b>  ';
                    $fields['zbm_core_sms_templates[unknown_synthetic_template]'] = 'must not persist';
                    if ($scenario === 'no_nonce') { unset($fields['_wpnonce']); }
                    if ($scenario === 'invalid_nonce') { $fields['_wpnonce'] = 'invalid'; }
                    $capabilityContract = null;
                    if ($scenario === 'capability_denied') {
                        $admin->add_cap('manage_options', false);
                        self::assertFalse(user_can($admin, 'manage_options')); self::assertTrue(user_can($admin, 'manage_woocommerce'));
                        self::assertSame(['administrator'], $admin->roles);
                        self::assertSame('manage_options', apply_filters('option_page_capability_zbm_core_spec3', 'manage_options'));
                        $capabilityContract = ['actor_roles' => $admin->roles, 'manage_options' => false, 'manage_woocommerce' => true,
                            'option_page_capability' => 'manage_options', 'nonce_verified' => wp_verify_nonce($nonce, 'zbm_core_spec3-options') !== false,
                            'per_user_cap_override' => true];
                        self::assertTrue($capabilityContract['nonce_verified']);
                    }
                    $before = $this->snapshot();
                    $beforeOptions = ['zbm_core_delivery_reminder_minutes' => Task4EndpointHttp::option('zbm_core_delivery_reminder_minutes'),
                        'zbm_core_sms_templates' => Task4EndpointHttp::option('zbm_core_sms_templates')];
                    if (is_file(self::GATE_EVIDENCE)) { self::assertTrue(unlink(self::GATE_EVIDENCE)); }
                    try { $http = Task4RemainingHttp::request('POST', 'wp-admin/options.php', $fields, $cookie); }
                    finally { if ($scenario === 'capability_denied') { $restoreCapability(); } }
                    if ($capabilityContract !== null) {
                        self::assertTrue(user_can(get_userdata($this->user('admin')), 'manage_options'));
                        $capabilityContract['capability_restored'] = true;
                    }
                    $this->diagnose($scenario, $http, ['actor' => $actor,
                        'actor_roles' => $actor === 'guest' ? [] : get_userdata($this->user($actor))->roles,
                        'manage_options' => $capabilityContract !== null ? false : ($actor !== 'guest' && user_can($this->user($actor), 'manage_options')),
                        'nonce_valid' => $actor !== 'guest' && !in_array($scenario, ['no_nonce', 'invalid_nonce'], true),
                        'before_snapshot' => $before, 'after_snapshot' => $this->snapshot(),
                        'before_options' => $beforeOptions,
                        'after_options' => ['zbm_core_delivery_reminder_minutes' => Task4EndpointHttp::option('zbm_core_delivery_reminder_minutes'),
                            'zbm_core_sms_templates' => Task4EndpointHttp::option('zbm_core_sms_templates')]]);
                    $notice = null; $message = ''; $upstreamContract = null; $denialLayer = null;
                    $invalid = in_array($scenario, ['below_range', 'above_range', 'invalid_integer'], true);
                    $denied = $actor !== 'admin' || in_array($scenario, ['capability_denied', 'no_nonce', 'invalid_nonce'], true);
                    if ($actor === 'guest') { $this->loginRedirect($http); $message = 'authentication_redirect_to_local_wp_login'; }
                    elseif ($actor !== 'admin') {
                        $probe = $this->nativeSettingsGate($http, $actor);
                        if ($actor === 'vendor_2') { $upstreamContract = $this->dokanOptionsDenial($http, $actor, $nonce, $probe); $message = 'dokan_block_admin_access_local_home_redirect'; $denialLayer = 'dokan_admin_init'; }
                        else { $upstreamContract = $this->wooOptionsDenial($http, $probe); $message = 'woocommerce_prevent_admin_access_local_account_redirect'; $denialLayer = 'woocommerce_admin_init'; }
                    }
                    elseif ($scenario === 'capability_denied') { $message = __('Sorry, you are not allowed to manage options for this site.'); $this->bodyMessage($http, 403, $message); $denialLayer = 'wordpress_options_capability'; }
                    elseif (in_array($scenario, ['no_nonce', 'invalid_nonce'], true)) { $message = __('The link you followed has expired.'); $this->bodyMessage($http, 403, $message); $denialLayer = 'wordpress_options_nonce'; }
                    else {
                        self::assertSame(302, $http['status']);
                        $location = Task4EndpointHttp::header($http, 'Location'); self::assertSame($location, wp_validate_redirect($location, ''));
                        parse_str((string) parse_url($location, PHP_URL_QUERY), $query); self::assertSame('true', $query['settings-updated'] ?? null);
                        $notice = Task4EndpointHttp::option('_transient_settings_errors'); self::assertIsArray($notice);
                        if ($invalid) {
                            $matching = array_values(array_filter($notice, static fn($row) => ($row['code'] ?? '') === 'invalid_reminder'));
                            self::assertCount(1, $matching); $message = 'یادآوری باید صفر (خاموش) یا بین ۵ تا ۱۴۴۰ دقیقه باشد.';
                            self::assertSame($message, $matching[0]['message']); self::assertSame('error', $matching[0]['type']);
                        } else { self::assertNotEmpty(array_filter($notice, static fn($row) => ($row['code'] ?? '') === 'settings_updated' && ($row['type'] ?? '') === 'success')); }
                    }
                    $storedMinutes = Task4EndpointHttp::option('zbm_core_delivery_reminder_minutes');
                    self::assertSame((string) ($denied || $invalid ? 60 : (int) $minutes), (string) $storedMinutes);
                    $templates = Task4EndpointHttp::option('zbm_core_sms_templates');
                    self::assertSame($denied ? [] : ['request_rejected' => 'Sandbox {request} message'], $templates);
                    $after = $this->snapshot();
                    if ($denied) { self::assertSame($before, $after); }
                    else { $this->unchangedExcept($before, $after, ['option:zbm_core_delivery_reminder_minutes', 'option:zbm_core_sms_templates']); self::assertNotSame($before, $after); }
                    $this->record($scenario, ['type' => $denied ? 'validation' : 'mutation', 'proof_type' => $denied ? 'validation' : 'mutation',
                        'http' => $http['status'], 'actor' => $actor, 'actor_valid' => $actor === 'admin' && $scenario !== 'capability_denied',
                        'nonce_valid' => $actor !== 'guest' && !in_array($scenario, ['no_nonce', 'invalid_nonce'], true),
                        'persisted_assertions' => true, 'financial_unchanged' => true, 'before_snapshot' => $before, 'after_snapshot' => $after,
                        'message' => $message, 'notice' => $notice, 'denial_layer' => $denialLayer,
                        'upstream_contract' => $upstreamContract, 'capability_contract' => $capabilityContract,
                        'observed_response' => ['message' => $message, 'notice' => $notice,
                            'redirect_path' => (string) parse_url(Task4EndpointHttp::header($http, 'Location'), PHP_URL_PATH),
                            'redirect_is_local_home' => $denialLayer === 'dokan_admin_init',
                            'redirect_is_local_account' => $denialLayer === 'woocommerce_admin_init'],
                        'stored_minutes' => (int) $storedMinutes, 'stored_templates' => $templates,
                        'before_options' => $beforeOptions, 'after_options' => ['zbm_core_delivery_reminder_minutes' => $storedMinutes, 'zbm_core_sms_templates' => $templates],
                        'verified_settings' => true,
                        'effect' => $denied ? 'named denial; both settings and all business/order rows unchanged' : ($invalid ? 'invalid reminder retained at60; independently valid template sanitized and saved' : 'real options.php saved exact sanitized settings')]);
                }
                return ['type' => 'mutation', 'proof_type' => 'mutation', 'covered_operations' => ['settings_save', 'reminder_0_5_1440', 'template_sanitization']];
            });
        } finally {
            $restoreCapability();
            if (is_string($observer) && is_file($observer)) { self::assertTrue(unlink($observer)); }
            $this->auth('admin', 'unused-setting-restore');
            foreach ($old as $option => $value) { $this->restoreOption($option, $value ?? ($option === 'zbm_core_sms_templates' ? [] : 60)); }
        }
    }
    private function restoreOption(string $name, mixed $value): void {
        wp_cache_delete($name, 'options'); wp_cache_delete('alloptions', 'options'); wp_cache_delete('notoptions', 'options'); update_option($name, $value, false);
    }

    public function test_rendered_request_pause_form_changes_only_control_state_and_has_exact_negatives(): void {
        $this->catalog('http-013', __METHOD__, function (): array {
            $this->primeAdmin();
            foreach (['authorized_positive', 'wrong_role', 'other_buyer', 'other_vendor', 'guest', 'no_nonce', 'invalid_nonce', 'missing_reason', 'stale_version'] as $scenario) {
                [$request, $repos] = $this->request(['current_state' => 'supplier_review', 'supplier_deadline_gmt' => gmdate('Y-m-d H:i:s', time() + 1200)]);
                $id = $request->id(); [$adminCookie] = $this->auth('admin', 'zbm_admin_request_control_' . $id);
                $render = Task4EndpointHttp::get('wp-admin/admin.php', ['page' => 'zbm-core-requests', 'request_number' => $request->requestNumber()], $adminCookie);
                self::assertSame(200, $render['status']);
                $form = $this->form($render['body'], static fn($node, $fields) => ($fields['action'] ?? '') === 'zbm_admin_request_control' && ($fields['request_id'] ?? '') === (string) $id);
                self::assertSame('post', $form['method']); self::assertSame('/wp-admin/admin-post.php', parse_url($form['action_url'], PHP_URL_PATH));
                self::assertSame('pause', $form['fields']['operation']); self::assertSame('0', $form['fields']['version']);
                $actor = match ($scenario) { 'wrong_role' => 'buyer_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', 'guest' => 'guest', default => 'admin' };
                if ($actor === 'admin') { $cookie = $adminCookie; $nonce = $form['fields']['_zbm_nonce']; }
                else { [$cookie, $nonce] = $this->auth($actor, 'zbm_admin_request_control_' . $id); }
                if ($actor !== 'guest') { self::assertNotFalse(wp_verify_nonce($nonce, 'zbm_admin_request_control_' . $id)); }
                $fields = array_replace($form['fields'], ['_zbm_nonce' => $nonce, 'reason' => 'Synthetic rendered request pause']);
                if ($scenario === 'no_nonce') { unset($fields['_zbm_nonce']); }
                if ($scenario === 'invalid_nonce') { $fields['_zbm_nonce'] = 'invalid'; }
                if ($scenario === 'missing_reason') { $fields['reason'] = ''; }
                if ($scenario === 'stale_version') { $fields['version'] = '9'; }
                $before = $this->snapshot(); $idempotencyBefore = $this->idempotencyRows(); $rendererDenial = null;
                if ($actor === 'guest') {
                    $guest = Task4EndpointHttp::get('wp-admin/admin.php', ['page' => 'zbm-core-requests'], ''); $this->loginRedirect($guest); $rendererDenial = 'local_wp_login_redirect';
                    $guestAfter = $this->snapshot(); self::assertSame($before, $guestAfter);
                    $this->record('guest_renderer', ['type' => 'scoped-read', 'http' => $guest['status'], 'actor' => 'guest',
                        'nonce_contract' => 'N/A: admin GET renderer requires authentication', 'message' => $rendererDenial,
                        'observed_response' => ['redirect_path' => parse_url(Task4EndpointHttp::header($guest, 'Location'), PHP_URL_PATH)],
                        'before_snapshot' => $before, 'after_snapshot' => $guestAfter, 'financial_unchanged' => true]);
                }
                $http = CandidateHttp::post('wp-admin/admin-post.php', $fields, $cookie); $message = ''; $notice = null;
                if ($actor === 'guest') { self::assertFalse(has_action('admin_post_nopriv_zbm_admin_request_control')); self::assertSame(400, $http['status']); self::assertTrue(str_contains($http['body'], 'wp-die-message')); $message = 'dispatcher_no_public_handler'; }
                elseif ($actor !== 'admin') { $message = 'دسترسی غیرمجاز است.'; $this->bodyMessage($http, 500, $message); }
                elseif (in_array($scenario, ['no_nonce', 'invalid_nonce'], true)) { $message = __('The link you followed has expired.'); $this->bodyMessage($http, 403, $message); }
                else {
                    self::assertSame(302, $http['status']); self::assertSame(admin_url('admin.php?page=zbm-core-requests'), Task4EndpointHttp::header($http, 'Location'));
                    $notice = Task4EndpointHttp::option('_transient_zbm_admin_request_notice_' . $this->user('admin')); self::assertIsArray($notice);
                    $message = match ($scenario) { 'missing_reason' => 'دسترسی، درخواست یا دلیل مدیریت معتبر نیست.', 'stale_version' => 'وضعیت کنترل مدیریت تغییر کرده است؛ صفحه را تازه کنید.', default => 'عملیات مدیریت همراه با دلیل و سابقه حسابرسی ثبت شد.' };
                    self::assertSame($message, $notice['message']); self::assertSame($scenario === 'authorized_positive' ? 'success' : 'error', $notice['type']);
                }
                $fresh = $repos['requests']->find($id); self::assertNotNull($fresh); $after = $this->snapshot(); $idempotencyProof = null;
                if ($scenario === 'authorized_positive') {
                    self::assertTrue($fresh->isPaused()); self::assertSame(1, $fresh->version()); self::assertSame('supplier_review', $fresh->state());
                    self::assertSame($request->requestNumber(), $fresh->requestNumber()); self::assertNull($fresh->supplierDeadlineGmt()); self::assertGreaterThan(0, $fresh->pausedRemainingSeconds());
                    global $wpdb; $schema = new Schema($wpdb);
                    $events = $wpdb->get_results($wpdb->prepare('SELECT event_uuid,event_key,resource_type,resource_id,actor_type,actor_user_id,from_state,to_state,correlation_id,metadata_json FROM %i WHERE resource_id=%d AND event_key=%s', $schema->audit_table(), $id, 'request_paused'), ARRAY_A);
                    self::assertSame('', $wpdb->last_error); self::assertCount(1, $events);
                    $event = $events[0]; self::assertSame('request', $event['resource_type']); self::assertSame($id, (int) $event['resource_id']);
                    self::assertSame('admin', $event['actor_type']); self::assertSame($this->user('admin'), (int) $event['actor_user_id']);
                    self::assertSame('supplier_review', $event['from_state']); self::assertSame('supplier_review', $event['to_state']);
                    self::assertTrue(wp_is_uuid($event['event_uuid'])); self::assertTrue(wp_is_uuid($event['correlation_id']));
                    self::assertSame($fields['reason'], json_decode($event['metadata_json'], true, 32, JSON_THROW_ON_ERROR)['reason']);
                    $idempotencyAfter = $this->idempotencyRows(); self::assertCount(count($idempotencyBefore) + 1, $idempotencyAfter);
                    foreach ($idempotencyBefore as $key => $row) { self::assertArrayHasKey($key, $idempotencyAfter); self::assertSame($row, $idempotencyAfter[$key], 'Existing idempotency row changed.'); }
                    $appended = array_diff_key($idempotencyAfter, $idempotencyBefore); self::assertCount(1, $appended); $command = reset($appended);
                    self::assertSame('purchase_request_transition', $command['namespace']); self::assertSame('completed', $command['status']);
                    self::assertSame($id, (int) $command['result_resource_id']); self::assertSame($event['event_uuid'], $command['result_event_uuid']);
                    self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $command['key_hash']); self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $command['payload_hash']);
                    $idempotencyProof = ['appended_rows' => 1, 'existing_rows_unchanged' => true, 'namespace' => $command['namespace'],
                        'status' => $command['status'], 'result_resource_id' => (int) $command['result_resource_id'],
                        'result_event_uuid' => $command['result_event_uuid'], 'audit_event_uuid' => $event['event_uuid'],
                        'audit_correlation_id' => $event['correlation_id'], 'audit_actor_user_id' => (int) $event['actor_user_id'], 'audit_reason' => $fields['reason']];
                    $this->unchangedExcept($before, $after, [$schema->purchase_requests_table(), $schema->audit_table(), $schema->notification_outbox_table(), $schema->idempotency_table()]); self::assertNotSame($before, $after);
                } else { self::assertFalse($fresh->isPaused()); self::assertSame(0, $fresh->version()); self::assertSame($before, $after); }
                $this->record($scenario, ['type' => $scenario === 'authorized_positive' ? 'mutation' : 'validation', 'proof_type' => 'mutation', 'http' => $http['status'],
                    'actor' => $actor, 'actor_valid' => $actor === 'admin',
                    'nonce_valid' => $actor !== 'guest' && !in_array($scenario, ['no_nonce', 'invalid_nonce'], true), 'persisted_assertions' => true,
                    'financial_unchanged' => true, 'before_snapshot' => $before, 'after_snapshot' => $after, 'message' => $message, 'notice' => $notice,
                    'observed_response' => ['message' => $message, 'notice' => $notice, 'renderer_denial' => $rendererDenial],
                    'before_version' => 0, 'after_version' => $fresh->version(), 'is_paused' => $fresh->isPaused(),
                    'idempotency_assertions' => $idempotencyProof,
                    'request_id' => $id,
                    'generic_dispatcher_only' => $actor === 'guest',
                    'effect' => $scenario === 'authorized_positive' ? 'actual pause form persisted pause/version/deadline and exact actor/reason audit; all financial/orders unchanged' : ($actor === 'guest' ? 'POST stopped at dispatcher; capability/nonce handler checks unproved for guest' : 'named denial with no business/audit/order write')]);
            }
            return ['type' => 'mutation', 'proof_type' => 'mutation', 'covered_operations' => ['pause'], 'covered_states' => ['supplier_review'],
                'remaining_gaps' => ['resume', 'reopen_supplier', 'reopen_deposit', 'accept_late', 'cancel_review']];
        });
    }

    public function test_frontend_registered_shortcode_transition_form_commits_owned_cancellation_only(): void {
        $this->catalog('http-031', __METHOD__, function (): array {
            foreach (['authorized_positive', 'wrong_role', 'other_buyer', 'other_vendor', 'guest', 'no_nonce', 'invalid_nonce', 'missing_reason', 'changed_order'] as $scenario) {
                global $wpdb; $schema = new Schema($wpdb); $buyer = $this->user('buyer_1');
                $order = wc_create_order(['customer_id' => $buyer]); $id = $order->get_id(); $now = current_time('mysql', true);
                // Historical legacy workflow state only; no payment, provider response, or managed request is simulated.
                self::assertSame(1, $wpdb->insert($schema->workflows_table(), ['order_id' => $id, 'current_state' => 'deposit_pending',
                    'version' => 0, 'is_paused' => 0, 'created_at_gmt' => $now, 'updated_at_gmt' => $now]));
                $store = new WorkflowStore($wpdb, CandidateFixture::repositories()['audit']);
                [$ownerCookie] = $this->auth('buyer_1', 'zbm_core_order_' . $id);
                $render = Task4EndpointHttp::get('task4-remaining-harness.php', ['shortcode' => 'zbm_order_workflow', 'order_id' => $id], $ownerCookie);
                self::assertSame(200, $render['status']); self::assertTrue(str_contains($render['body'], 'class="zbm-order-workflow"'));
                $form = $this->form($render['body'], static fn($node, $fields) => ($fields['action'] ?? '') === 'zbm_core_transition' && ($fields['to_state'] ?? '') === 'cancellation_requested');
                self::assertSame('post', $form['method']); self::assertSame('/wp-admin/admin-post.php', parse_url($form['action_url'], PHP_URL_PATH));
                self::assertSame((string) $id, $form['fields']['order_id']); self::assertTrue(wp_is_uuid($form['fields']['correlation_id']));
                $actor = match ($scenario) { 'wrong_role' => 'vendor_1', 'other_buyer' => 'buyer_2', 'other_vendor' => 'vendor_2', 'guest' => 'guest', default => 'buyer_1' };
                if ($actor === 'buyer_1') { $cookie = $ownerCookie; $nonce = $form['fields']['_wpnonce']; }
                else { [$cookie, $nonce] = $this->auth($actor, 'zbm_core_order_' . $id); }
                if ($actor !== 'guest') { self::assertNotFalse(wp_verify_nonce($nonce, 'zbm_core_order_' . $id)); }
                $fields = array_replace($form['fields'], ['_wpnonce' => $nonce, 'reason' => 'Synthetic frontend owned cancellation']);
                if ($scenario === 'no_nonce') { unset($fields['_wpnonce']); }
                if ($scenario === 'invalid_nonce') { $fields['_wpnonce'] = 'invalid'; }
                if ($scenario === 'missing_reason') { $fields['reason'] = ''; }
                if ($scenario === 'changed_order') {
                    $other = wc_create_order(['customer_id' => $this->user('buyer_2')]); $fields['order_id'] = (string) $other->get_id();
                    [$cookie, $fields['_wpnonce']] = $this->auth('buyer_1', 'zbm_core_order_' . $other->get_id());
                }
                $before = $this->snapshot(); $workflowBefore = $store->snapshot($id); $rendererDenial = null;
                if ($actor !== 'buyer_1') {
                    $deniedRead = Task4EndpointHttp::get('task4-remaining-harness.php', ['shortcode' => 'zbm_order_workflow', 'order_id' => $id], $cookie);
                    self::assertSame(200, $deniedRead['status']); self::assertSame('', trim($deniedRead['body'])); $rendererDenial = 'exact_empty_ownership_gated_shortcode';
                    if ($actor === 'guest') {
                        $guestAfter = $this->snapshot(); self::assertSame($before, $guestAfter);
                        $this->record('guest_renderer', ['type' => 'scoped-read', 'http' => $deniedRead['status'], 'actor' => 'guest',
                            'nonce_contract' => 'N/A: ownership-gated shortcode read', 'message' => $rendererDenial,
                            'observed_response' => ['body' => ''], 'before_snapshot' => $before, 'after_snapshot' => $guestAfter, 'financial_unchanged' => true]);
                    }
                }
                $http = CandidateHttp::post('wp-admin/admin-post.php', $fields, $cookie); $code = '';
                if ($actor === 'guest') { self::assertFalse(has_action('admin_post_nopriv_zbm_core_transition')); self::assertSame(400, $http['status']); self::assertTrue(str_contains($http['body'], 'wp-die-message')); $code = 'dispatcher_no_public_handler'; }
                elseif (in_array($scenario, ['no_nonce', 'invalid_nonce'], true)) { $code = __('The link you followed has expired.'); $this->bodyMessage($http, 403, $code); }
                else { $code = match ($scenario) { 'authorized_positive' => 'success', 'missing_reason' => 'zbm_reason_too_short', default => 'forbidden' }; $this->result($http, $code); }
                $workflowAfter = $store->snapshot($id); $after = $this->snapshot();
                if ($scenario === 'authorized_positive') {
                    self::assertSame('deposit_pending', $workflowBefore['state']); self::assertSame('cancellation_requested', $workflowAfter['state']); self::assertSame(1, $workflowAfter['version']); self::assertFalse($workflowAfter['is_paused']);
                    $events = $wpdb->get_results($wpdb->prepare('SELECT actor_type,actor_user_id,from_state,to_state,correlation_id,metadata_json FROM %i WHERE order_id=%d AND event_key=%s', $schema->audit_table(), $id, 'workflow_transitioned'), ARRAY_A);
                    self::assertSame('', $wpdb->last_error); self::assertCount(1, $events); self::assertSame('buyer', $events[0]['actor_type']); self::assertSame($buyer, (int) $events[0]['actor_user_id']);
                    self::assertSame('deposit_pending', $events[0]['from_state']); self::assertSame('cancellation_requested', $events[0]['to_state']); self::assertSame($fields['correlation_id'], $events[0]['correlation_id']);
                    self::assertSame($fields['reason'], json_decode($events[0]['metadata_json'], true)['reason']);
                    $this->unchangedExcept($before, $after, [$schema->workflows_table(), $schema->audit_table(), $schema->idempotency_table()]); self::assertNotSame($before, $after);
                } else { self::assertSame($workflowBefore, $workflowAfter); self::assertSame($before, $after); }
                $this->record($scenario, ['type' => $scenario === 'authorized_positive' ? 'mutation' : 'validation', 'proof_type' => 'mutation', 'http' => $http['status'],
                    'actor' => $actor, 'actor_valid' => $actor === 'buyer_1' && $scenario !== 'changed_order',
                    'nonce_valid' => $actor !== 'guest' && !in_array($scenario, ['no_nonce', 'invalid_nonce'], true), 'persisted_assertions' => true,
                    'financial_unchanged' => true, 'before_snapshot' => $before, 'after_snapshot' => $after,
                    'result' => $code, 'observed_response' => ['result' => $code, 'renderer_denial' => $rendererDenial],
                    'before_state' => $workflowBefore['state'], 'after_state' => $workflowAfter['state'], 'before_version' => $workflowBefore['version'], 'after_version' => $workflowAfter['version'],
                    'generic_dispatcher_only' => $actor === 'guest',
                    'effect' => $scenario === 'authorized_positive' ? 'real rendered buyer cancellation form persisted exact transition/correlation/reason audit; no financial/order change' : ($actor === 'guest' ? 'POST stopped at dispatcher; capability/nonce handler checks unproved for guest' : 'named authorization/nonce/reason denial; all durable business/order rows unchanged')]);
            }
            return ['type' => 'mutation', 'proof_type' => 'mutation', 'covered_operations' => ['buyer_cancellation_requested'], 'covered_states' => ['deposit_pending'],
                'remaining_gaps' => ['vendor_simple_transition', 'weight_upload_forms', 'evidenced_dispute_forms']];
        });
    }
}

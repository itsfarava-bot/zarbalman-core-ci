<?php
declare(strict_types=1);

/** Read the neutral catalog and inspect the active WordPress callback registry. */
final class HttpSurfaceRegistry {
    public static function catalog(): array {
        $json = file_get_contents(__DIR__ . '/../../ci/http-surfaces.json');
        return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function callbackMatches(mixed $callback, array $target): bool {
        return is_array($callback) && isset($callback[0], $callback[1])
            && is_object($callback[0]) && get_class($callback[0]) === $target['class']
            && $callback[1] === $target['callback'] && is_callable($callback);
    }

    private static function registeredCallback(string $hook, array $target): ?array {
        if (false === has_action($hook)) { return null; }
        $registered = $GLOBALS['wp_filter'][$hook] ?? null;
        if (!$registered instanceof WP_Hook) { return null; }
        foreach ($registered->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                if (self::callbackMatches($callback['function'], $target)) {
                    return $callback['function'];
                }
            }
        }
        return null;
    }

    public static function isRegistered(array $row): bool {
        $target = $row['target'];
        if ($target['kind'] === 'REST') {
            $routes = rest_get_server()->get_routes();
            foreach ($routes[$target['route']] ?? [] as $endpoint) {
                if (is_array($endpoint) && !empty($endpoint['methods'][$target['method']])
                    && self::callbackMatches($endpoint['callback'] ?? null, $target)) { return true; }
            }
            return false;
        }
        if ($target['kind'] === 'shortcode') {
            return shortcode_exists($target['hook'])
                && self::callbackMatches($GLOBALS['shortcode_tags'][$target['hook']] ?? null, $target);
        }
        if ($target['kind'] === 'admin-page') {
            $bootstrap = $target;
            $bootstrap['callback'] = $target['bootstrap_callback'];
            $callback = self::registeredCallback($target['bootstrap_hook'], $bootstrap);
            if ($callback === null) { return false; }
            // Invoke the existing registered menu callback, never a substitute object.
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $oldUser = get_current_user_id();
            $admin = get_user_by('login', 'zbm_test_admin');
            if (!$admin instanceof WP_User) { return false; }
            wp_set_current_user((int) $admin->ID);
            try { $callback(); }
            finally { wp_set_current_user($oldUser); }
            $hook = get_plugin_page_hookname($target['page_slug'], $target['parent_slug']);
            return self::registeredCallback($hook, $target) !== null;
        }
        if ($target['kind'] === 'settings') {
            $callback = self::registeredCallback($target['hook'], $target);
            if ($callback === null) { return false; }
            // Register settings through the actual plugin callback as options.php does.
            $callback();
            $settings = get_registered_settings();
            foreach ($target['options'] as $option => $sanitize) {
                $registered = $settings[$option] ?? [];
                $expected = $target;
                $expected['callback'] = $sanitize;
                if (($registered['group'] ?? null) !== $target['group']
                    || !self::callbackMatches($registered['sanitize_callback'] ?? null, $expected)) { return false; }
            }
            return true;
        }
        return self::registeredCallback($target['hook'], $target) !== null;
    }
}

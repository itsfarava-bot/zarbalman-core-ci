<?php
declare(strict_types=1);

/** Local HTTP only; never follows redirects or contacts a payment provider. */
final class Task4EndpointHttp {
    public static function get(string $path, array $query = [], string $cookie = ''): array {
        $url = 'http://127.0.0.1:8080/' . ltrim($path, '/');
        if ($query) { $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query); }
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Cookie: ' . $cookie]]);
        $raw = curl_exec($curl);
        if (!is_string($raw)) { throw new RuntimeException('Sandbox GET failed: ' . curl_error($curl)); }
        $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    }
    public static function header(array $http, string $name): string {
        return preg_match('/^' . preg_quote($name, '/') . ':\s*(.*?)\r?$/mi', $http['headers'], $match) ? $match[1] : '';
    }
    public static function forms(string $html): array {
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $forms = [];
        foreach ($dom->getElementsByTagName('form') as $form) {
            $fields = [];
            foreach ($form->getElementsByTagName('input') as $input) {
                if ($input->getAttribute('name') !== '') { $fields[$input->getAttribute('name')] = $input->getAttribute('value'); }
            }
            if (isset($fields['action'])) { $forms[$fields['action'] . ':' . ($fields['to_state'] ?? '')] = $fields; }
        }
        return $forms;
    }
    /** Bypass stale in-process WP option/transient caches after a different HTTP process writes. */
    public static function option(string $name): mixed {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, $name));
        if ($wpdb->last_error !== '') { throw new RuntimeException('Option read failed.'); }
        return $raw === null ? null : maybe_unserialize($raw);
    }
}

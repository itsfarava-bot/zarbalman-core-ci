<?php
declare(strict_types=1);
final class Task4RemainingHttp {
    public static function request(string $method, string $path, array $fields = [], string $cookie = '', ?string $nonce = null): array {
        $url = 'http://127.0.0.1:8080/' . ltrim($path, '/');
        if ($method === 'GET' && $fields) { $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($fields); }
        $handle = curl_init($url);
        $headers = ['Cookie: ' . $cookie];
        if ($nonce !== null) { $headers[] = 'X-WP-Nonce: ' . $nonce; }
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => $headers];
        if ($method === 'POST') { $options[CURLOPT_POST] = true; $options[CURLOPT_POSTFIELDS] = http_build_query($fields); }
        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        if (!is_string($raw)) { throw new RuntimeException('Local remaining-endpoint HTTP failed: ' . curl_error($handle)); }
        $size = curl_getinfo($handle, CURLINFO_HEADER_SIZE); $status = curl_getinfo($handle, CURLINFO_HTTP_CODE); curl_close($handle);
        return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    }
}

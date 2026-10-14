<?php
declare(strict_types=1);
require_once __DIR__ . '/Task4EndpointHttp.php';

/** Unauthenticated actual Woo API callback requests, including curl_multi overlap. */
final class Task5FinancialHttp {
    public static function callback(array $query): array { return Task4EndpointHttp::get('', $query); }
    public static function concurrent(array $query): array {
        $multi = curl_multi_init(); $handles = []; $responses = [];
        try {
            for ($index = 0; $index < 2; ++$index) {
                $handle = curl_init('http://127.0.0.1:8080/?' . http_build_query($query));
                curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
                    CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 45]);
                $handles[] = $handle;
                if (curl_multi_add_handle($multi, $handle) !== CURLM_OK) { throw new RuntimeException('Concurrent callback setup failed.'); }
            }
            do {
                $code = curl_multi_exec($multi, $running);
                if ($code !== CURLM_OK) { throw new RuntimeException('Concurrent callback transport failed.'); }
                if ($running > 0 && curl_multi_select($multi, 0.1) === -1) { usleep(1000); }
            } while ($running > 0);
            foreach ($handles as $handle) {
                if (curl_errno($handle) !== 0) { throw new RuntimeException('Concurrent local callback failed: ' . curl_error($handle)); }
                $raw = curl_multi_getcontent($handle); $size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
                $responses[] = ['status' => curl_getinfo($handle, CURLINFO_HTTP_CODE),
                    'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size),
                    'duration_seconds' => curl_getinfo($handle, CURLINFO_TOTAL_TIME)];
            }
        } finally {
            foreach ($handles as $handle) { curl_multi_remove_handle($multi, $handle); curl_close($handle); }
            curl_multi_close($multi);
        }
        return $responses;
    }
}

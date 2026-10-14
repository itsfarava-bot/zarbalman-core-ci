<?php
declare(strict_types=1);
require_once __DIR__ . '/Task4EndpointHttp.php';

/** Unauthenticated actual Woo API callback requests, including curl_multi overlap. */
final class Task5FinancialHttp {
    public static function callback(array $query): array { return Task4EndpointHttp::get('', $query); }
    public static function concurrent(array $query): array {
        [$server, $port, $log] = self::secondServer();
        $multi = curl_multi_init(); $handles = []; $responses = [];
        try {
            foreach ([8080, $port] as $targetPort) {
                $handle = curl_init('http://127.0.0.1:' . $targetPort . '/?' . http_build_query($query));
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
            proc_terminate($server);
            proc_close($server);
            if (is_file($log)) { unlink($log); }
        }
        return $responses;
    }

    /** Distinct native HTTP processes share the disposable WordPress/SQL fixture. */
    private static function secondServer(): array {
        $root = getenv('ZBM_TEST_WP_ROOT');
        if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true || !is_string($root)
            || !is_file($root . '/wp-load.php') || PHP_SAPI !== 'cli') { throw new RuntimeException('Disposable native server required.'); }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if (!is_resource($socket)) { throw new RuntimeException('Unable to allocate an owned loopback server port.'); }
        $address = stream_socket_get_name($socket, false);
        $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
        fclose($socket);
        if ($port < 1024 || $port === 8080) { throw new RuntimeException('Invalid owned loopback server port.'); }
        $log = tempnam(sys_get_temp_dir(), 'zbm-task5-owned-http-');
        if (!is_string($log)) { throw new RuntimeException('Unable to create owned server diagnostics.'); }
        $environment = getenv();
        if (!is_array($environment)) { unlink($log); throw new RuntimeException('Native server environment unavailable.'); }
        unset($environment['PHP_CLI_SERVER_WORKERS']);
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, $environment);
        if (!is_resource($server)) { unlink($log); throw new RuntimeException('Unable to start an owned native server.'); }
        $deadline = microtime(true) + 5;
        do {
            $state = proc_get_status($server);
            if (!$state['running']) { break; }
            $client = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 0.1);
            if (is_resource($client)) { fclose($client); return [$server, $port, $log]; }
            usleep(10000);
        } while (microtime(true) < $deadline);
        proc_terminate($server); proc_close($server); unlink($log);
        throw new RuntimeException('Owned native server did not become ready.');
    }

    /** Finite native response classifiers; never retain raw bodies, URLs or headers. */
    public static function safeResult(array $http): array {
        $body = html_entity_decode(strip_tags((string) ($http['body'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $reason = 'unclassified';
        foreach ([
            'Two distinct callback workers did not enter the barrier.' => 'test_barrier_timeout',
            'Callback barrier read failed closed.' => 'test_barrier_read_failed',
            'نتیجهٔ پرداخت موقتاً قابل استعلام نیست' => 'verification_ambiguous',
            'پرداخت قطعی است اما ثبت داخلی آن کامل نشد' => 'confirmation_journal_failed',
            'پرداخت تأیید شده است اما ادامهٔ امن سفارش' => 'order_reconciliation_pending',
            'زرین‌پال این پرداخت را قطعی اعلام نکرد' => 'definitive_provider_rejection',
            'پرداخت دیگری برای این درخواست در حال بررسی است' => 'callback_lock_busy',
            'هویت callback یا درخواست متناظر معتبر نیست' => 'callback_identity_invalid',
            'این درخواست در وضعیت قابل پرداخت نیست' => 'callback_state_invalid',
            'ثبت امن ورود پرداخت انجام نشد' => 'callback_receipt_failed',
        ] as $message => $classification) {
            if (str_contains($body, $message)) { $reason = $classification; break; }
        }
        return ['http' => (int) $http['status'], 'duration_seconds' => $http['duration_seconds'] ?? null, 'response_reason' => $reason];
    }
}

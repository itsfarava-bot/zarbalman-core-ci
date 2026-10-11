<?php
declare(strict_types=1);
// Test-only transport double. No commercial plugin, credentials or network send.
final class ZbmRecordingSmsTransport {
    public array $sent = [];
    public bool $fail = false;
    public function send(): bool { throw new RuntimeException('Direct gateway sends forbidden in test.'); }
    public function get_sms_gateway(): object { return $this; }
    public function validate_mobile(string $mobile): bool { return $mobile === '09120000000'; }
    public function send_sms(array $payload): bool {
        if ($this->fail) { throw new RuntimeException('Injected SMS transport failure.'); }
        $this->sent[] = $payload;
        return true;
    }
}
if (function_exists('PWSMS') || defined('PWSMS_VERSION')) { throw new RuntimeException('Refusing to replace a real SMS integration.'); }
define('PWSMS_VERSION', '7.2.2'); // MOCK contract version; does not establish compatibility.
if (!function_exists('PWSMS')) {
    function PWSMS(): ZbmRecordingSmsTransport {
        static $transport;
        return $transport ??= new ZbmRecordingSmsTransport();
    }
}

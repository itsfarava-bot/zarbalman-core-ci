<?php
declare(strict_types=1);
final class CandidateFaultDb extends wpdb {
    public bool $failAcquire = false;
    public bool $failRelease = false;
    public function get_var($query = null, $x = 0, $y = 0) {
        if (is_string($query) && str_contains($query, 'GET_LOCK') && $this->failAcquire) { return '0'; }
        if (is_string($query) && str_contains($query, 'RELEASE_LOCK') && $this->failRelease) { throw new RuntimeException('Injected driver lock-release error.'); }
        return parent::get_var($query, $x, $y);
    }
    public static function connect(): self {
        global $wpdb;
        $db = new self(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST); // Synthetic CI constants only.
        $db->set_prefix($wpdb->prefix);
        return $db;
    }
}

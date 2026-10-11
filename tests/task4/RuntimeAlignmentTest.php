<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Infrastructure\Schema;
use Zarbalman\Core\Support\JalaliDate;
final class RuntimeAlignmentTest extends TestCase {
    public function test_host_prefix_tehran_and_gd_only_are_real_runtime_settings(): void {
        global $wpdb;
        self::assertSame('_ci',$wpdb->prefix);
        foreach ((new Schema($wpdb))->tables() as $table) { self::assertStringStartsWith('_cizbm_',$table); }
        self::assertSame('Asia/Tehran',wp_timezone_string());self::assertTrue(extension_loaded('gd'));self::assertFalse(extension_loaded('imagick'));
        self::assertStringStartsWith('10.11.',$wpdb->db_version());
        self::assertSame('utf8mb4_unicode_520_ci',$wpdb->get_var($wpdb->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->options)));
    }
    public function test_internal_jalali_conversion_at_tehran_midnight_and_new_year(): void {
        self::assertSame('۱۴۰۴/۱۲/۲۹ ۲۳:۵۹',JalaliDate::formatGmt('2026-03-20 20:29:59',wp_timezone()));
        self::assertSame('۱۴۰۵/۰۱/۰۱ ۰۰:۰۰',JalaliDate::formatGmt('2026-03-20 20:30:00',wp_timezone()));
        self::assertSame('2026-03-20 20:30:00',JalaliDate::parse('۱۴۰۵/۰۱/۰۱',wp_timezone())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        $this->expectException(InvalidArgumentException::class);JalaliDate::parse('۱۴۰۴/۱۲/۳۰',wp_timezone());
    }
}

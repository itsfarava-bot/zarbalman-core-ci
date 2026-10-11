<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/CandidateFixture.php';
use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Infrastructure\{Migrator, Schema};

final class MigratorUpgradeTest extends TestCase {
    public function test_schema_13_upgrade_restores_unique_reference_and_rejects_duplicate(): void {
        global $wpdb;
        $table = (new Schema($wpdb))->purchase_requests_table();
        // Disposable database only: recreate an additive old shape missing the reference index.
        self::assertNotFalse($wpdb->query($wpdb->prepare('ALTER TABLE %i DROP INDEX request_number', $table)));
        update_option('zbm_core_db_version', '13');
        (new Migrator($wpdb))->maybe_migrate();
        self::assertFalse((bool) get_option(Migrator::FAILURE_OPTION, false));
        self::assertSame((string) ZBM_CORE_DB_VERSION, (string) get_option('zbm_core_db_version'));
        $index = $wpdb->get_row($wpdb->prepare('SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'request_number'), ARRAY_A);
        self::assertIsArray($index);
        self::assertSame('0', (string) $index['Non_unique']);
        self::assertSame('request_number', $index['Column_name']);
        [$first] = CandidateFixture::request(['request_number' => '999990']);
        [$second] = CandidateFixture::request(['request_number' => '999991']);
        $old = $wpdb->suppress_errors(true);
        try {
            self::assertFalse($wpdb->update($table, ['request_number' => '999990'], ['id' => $second->id()]));
        } finally { $wpdb->suppress_errors($old); }
        self::assertSame('999991', $wpdb->get_var($wpdb->prepare('SELECT request_number FROM %i WHERE id = %d', $table, $second->id())));
        self::assertSame('999990', $wpdb->get_var($wpdb->prepare('SELECT request_number FROM %i WHERE id = %d', $table, $first->id())));
    }
}

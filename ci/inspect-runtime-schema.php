<?php
declare(strict_types=1);

// Read-only inspection of the disposable CI database.
global $wpdb;
$schema = new \Zarbalman\Core\Infrastructure\Schema($wpdb);
$engines = [];
foreach ($schema->tables() as $table) {
    $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s', $table), ARRAY_A);
    if ($wpdb->last_error !== '' || !is_array($status)) { throw new RuntimeException('Core table inspection unavailable.'); }
    $engines[substr($table, strlen($wpdb->prefix))] = (string) $status['Engine'];
}
$header = get_file_data(ZBM_CORE_FILE, ['requires_at_least' => 'Requires at least', 'requires_php' => 'Requires PHP']);
$report = ['core_tables' => $engines, 'schema_version' => (string) get_option('zbm_core_db_version'),
    'target_schema_version' => ZBM_CORE_DB_VERSION, 'database_version' => $wpdb->db_version(), 'header' => $header];
$json = wp_json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
if (file_put_contents(getcwd() . '/evidence/runtime-schema.json', $json . "\n") === false) { throw new RuntimeException('Core inspection report unavailable.'); }
$innodb = count(array_filter($engines, static fn(string $engine): bool => strtolower($engine) === 'innodb'));
echo 'Core table engines: inspected=' . count($engines) . ' InnoDB=' . $innodb . "\n";

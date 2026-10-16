<?php
declare(strict_types=1);

use PHPUnit\Framework\Assert;

/** Two actual PHP processes, bounded rendezvous before their real SQL GET_LOCK. */
final class Task7ProcessHarness {
    public static function run(array $specs): array {
        Assert::assertCount(2, $specs);
        Assert::assertSame('cli', PHP_SAPI);
        Assert::assertTrue(defined('ZBM_TEST_SANDBOX') && ZBM_TEST_SANDBOX === true);
        $directory = sys_get_temp_dir() . '/zbm-task7-' . bin2hex(random_bytes(16));
        Assert::assertTrue(mkdir($directory, 0700));
        $processes = []; $exitCodes = []; $arrivals = []; $results = [];
        try {
            foreach (array_values($specs) as $slot => $spec) {
                Assert::assertNotFalse(file_put_contents($directory . '/input-' . $slot . '.json', json_encode($spec, JSON_THROW_ON_ERROR), LOCK_EX));
                $process = proc_open([PHP_BINARY, __DIR__ . '/Task7ProcessWorker.php', $directory, (string) $slot],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/stdout-' . $slot, 'a'],
                        2 => ['file', $directory . '/stderr-' . $slot, 'a']], $pipes);
                Assert::assertIsResource($process, 'A separate OS worker must start.');
                $processes[$slot] = $process;
            }
            $deadline = microtime(true) + 20;
            do {
                foreach ($processes as $slot => $process) {
                    $path = $directory . '/arrival-' . $slot . '.json';
                    if (is_file($path) && !isset($arrivals[$slot])) { $arrivals[$slot] = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR); }
                    $status = proc_get_status($process);
                    if (!$status['running']) { $exitCodes[$slot] ??= $status['exitcode']; }
                }
                if (count($arrivals) === 2 || $exitCodes) { break; }
                usleep(10000);
            } while (microtime(true) < $deadline);
            Assert::assertCount(2, $arrivals, 'Both independent workers must reach the native request lock boundary before either proceeds.');
            $released = microtime(true);
            Assert::assertNotFalse(file_put_contents($directory . '/release', 'release', LOCK_EX));
            $deadline = microtime(true) + 45;
            do {
                foreach ($processes as $slot => $process) {
                    if (array_key_exists($slot, $exitCodes)) { continue; }
                    $status = proc_get_status($process);
                    if (!$status['running']) { $exitCodes[$slot] = $status['exitcode']; }
                }
                if (count($exitCodes) === 2) { break; }
                usleep(10000);
            } while (microtime(true) < $deadline);
            Assert::assertCount(2, $exitCodes, 'Both workers must finish within the native lock timeout budget.');
            foreach ($processes as $slot => $process) {
                Assert::assertSame(0, $exitCodes[$slot], 'Worker must exit normally.');
                $path = $directory . '/result-' . $slot . '.json';
                Assert::assertFileExists($path);
                $results[$slot] = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
                Assert::assertNotSame('task7_worker_exception', $results[$slot]['error']);
                Assert::assertSame($arrivals[$slot]['pid'], $results[$slot]['pid']);
                Assert::assertSame($arrivals[$slot]['connection_id'], $results[$slot]['connection_id']);
                Assert::assertGreaterThan(0, $results[$slot]['connection_id']);
                Assert::assertLessThanOrEqual($released, $results[$slot]['lock_arrival_at']);
                Assert::assertGreaterThanOrEqual($released, $results[$slot]['finished_at']);
            }
            Assert::assertCount(2, array_unique(array_column($results, 'pid')));
            Assert::assertCount(2, array_unique(array_column($results, 'connection_id')));
            Assert::assertLessThan(min(array_column($results, 'finished_at')), max(array_column($results, 'started_at')), 'The actual worker operation lifetimes must overlap.');
            ksort($results);
            return ['transport' => 'separate_php_cli_processes', 'barrier' => 'observe_unchanged_native_GET_LOCK_query',
                'released_at' => $released, 'workers' => array_values($results), 'http_boundary_tested' => false];
        } finally {
            // Release blocked workers on every assertion failure, then terminate
            // only processes owned here. Connection-scoped SQL locks die with them.
            file_put_contents($directory . '/release', 'cleanup', LOCK_EX);
            foreach ($processes as $process) {
                $status = proc_get_status($process);
                if ($status['running']) { proc_terminate($process); }
                proc_close($process);
            }
            foreach (glob($directory . '/*') ?: [] as $path) { unlink($path); }
            rmdir($directory);
        }
    }
}

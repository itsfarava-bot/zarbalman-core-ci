<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class RequestNumberCompatibilityTest extends TestCase {
    public function test_declared_runtime_legacy_entropy_exception_is_wrapped(): void {
        $header = file_get_contents(__DIR__ . '/../../plugin/zarbalman-core.php');
        self::assertMatchesRegularExpression('/Requires PHP:\s+8\.2/', $header);
        $file = var_export(__DIR__ . '/../../plugin/src/Domain/PurchaseRequest/RequestNumber.php', true);
        $script = 'namespace Zarbalman\\Core\\Domain\\PurchaseRequest { function random_int(int $a, int $b): int { throw new \\Exception("Legacy entropy failure"); } } namespace { require ' . $file . '; try { \\Zarbalman\\Core\\Domain\\PurchaseRequest\\RequestNumber::generate(); echo "unexpected-success"; } catch (\\Throwable $e) { echo json_encode([get_class($e),$e->getMessage(),$e->getPrevious() ? get_class($e->getPrevious()) : null]); } }';
        $proc = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        self::assertIsResource($proc);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); self::assertSame(0, proc_close($proc), $error);
        self::assertSame(['RuntimeException','A secure request number could not be generated.','Exception'], json_decode($output, true));
    }
}

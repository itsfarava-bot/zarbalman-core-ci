<?php
declare(strict_types=1);
namespace Zarbalman\Core\Domain\PurchaseRequest {
    // Fault injection at the production unqualified random_int boundary; normal calls delegate to PHP.
    function random_int(int $min, int $max): int {
        if (!empty($GLOBALS['zbm_test_entropy_failure'])) { throw new \Random\RandomException('Injected entropy failure.'); }
        return \random_int($min, $max);
    }
}
namespace {
    use PHPUnit\Framework\TestCase;
    use Zarbalman\Core\Domain\PurchaseRequest\RequestNumber;
    final class RequestNumberEntropyTest extends TestCase {
        public function test_php_8234_entropy_error_is_wrapped_with_original_cause(): void {
            self::assertSame('8.2.34', PHP_VERSION);
            self::assertSame(8, PHP_INT_SIZE);
            $GLOBALS['zbm_test_entropy_failure'] = true;
            try {
                RequestNumber::generate();
                self::fail('Entropy failure must not produce a reference.');
            } catch (Throwable $error) {
                self::assertInstanceOf(RuntimeException::class, $error);
                self::assertSame('A secure request number could not be generated.', $error->getMessage());
                self::assertInstanceOf(Random\RandomException::class, $error->getPrevious());
            } finally { $GLOBALS['zbm_test_entropy_failure'] = false; }
        }
        public function test_normal_secure_generation_still_produces_valid_six_digit_references(): void {
            $GLOBALS['zbm_test_entropy_failure'] = false;
            for ($i = 0; $i < 20; ++$i) { self::assertTrue(RequestNumber::isValid(RequestNumber::generate())); }
        }
    }
}
